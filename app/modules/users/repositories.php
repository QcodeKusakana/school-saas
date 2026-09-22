<?php
/**
 * Module UTILISATEURS — lectures.
 *
 * CE QU'EST UN « COMPTE DU PERSONNEL »
 * =====================================
 * Ce module ne gère PAS tous les comptes de la table `users`. Il en
 * gère exactement un sous-ensemble, et la frontière est une règle de
 * sécurité, pas une commodité d'affichage :
 *
 *   · un compte de FAMILLE (tuteur, élève) est rattaché à une fiche
 *     `guardians` ou `students`. Il naît du portail, s'y réinitialise
 *     et n'apparaît jamais ici. Deux écrans qui écriraient sur le même
 *     compte avec des règles différentes finiraient par se contredire ;
 *   · un compte de PLATEFORME (`school_id IS NULL`) n'appartient à
 *     aucune école. Le laisser apparaître dans la liste d'une école
 *     ouvrirait à sa direction la modification d'un compte de
 *     l'éditeur — une élévation de privilège maximale.
 *
 * Le prédicat est exactement celui de `subscription_staff_user_count()`
 * (phase 7A), et ce n'est pas un hasard : l'écran qui montre les
 * comptes et la jauge qui les compte doivent désigner les MÊMES objets.
 *
 * > Une jauge et une règle qui ne mesurent pas la même chose finissent
 * > toujours par se contredire devant l'utilisateur.
 */

declare(strict_types=1);

/**
 * Le prédicat « ce compte est un compte du personnel de CETTE école ».
 *
 * Écrit une fois, partagé par toutes les lectures et par le service :
 * deux copies finiraient par diverger, et c'est un contrôle d'accès.
 *
 * `:school_id` n'y figure pas — l'appelant filtre `u.school_id`
 * lui-même, dans son propre WHERE, pour que le filtre d'école soit
 * visible à l'endroit où la requête se lit.
 */
const USERS_STAFF_PREDICATE = "NOT EXISTS (
        SELECT 1 FROM guardians g
         WHERE g.user_id = u.id AND g.school_id = u.school_id AND g.deleted_at IS NULL
    )
    AND NOT EXISTS (
        SELECT 1 FROM students st
         WHERE st.user_id = u.id AND st.school_id = u.school_id AND st.deleted_at IS NULL
    )";

/** Les états d'un compte, dans l'ordre où ils se lisent. */
const USERS_STATUSES = [
    'active'    => 'Actif',
    'pending'   => 'En attente',
    'inactive'  => 'Désactivé',
    'suspended' => 'Suspendu',
];

/**
 * Les rôles que ce module n'attribue JAMAIS.
 *
 * Ils appartiennent au portail : un compte PARENT naît d'une fiche
 * tuteur, un compte ELEVE d'une fiche élève. Les attribuer ici
 * produirait un compte de famille sans famille — visible dans la
 * liste du personnel, compté dans la limite d'abonnement, et sans
 * aucun dossier à ouvrir.
 */
const USERS_PORTAL_ROLES = ['PARENT', 'ELEVE'];

/**
 * Recherche paginée des comptes du personnel de l'école courante.
 *
 * Les rôles sont ramenés en UNE requête pour la page affichée : 25
 * comptes ne doivent pas produire 26 requêtes (leçon du portail 6A).
 *
 * @param array{q?: string, status?: string, role?: string, archived?: string} $filters
 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int}
 */
function users_repo_search(array $filters, int $page = 1, int $perPage = 25): array
{
    $schoolId = tenant_require();

    $where  = ['u.school_id = :school_id', USERS_STAFF_PREDICATE];
    $params = ['school_id' => $schoolId];

    // Les comptes archivés sont HORS liste par défaut, mais jamais
    // effacés : une case les rappelle.
    if (($filters['archived'] ?? '') !== '1') {
        $where[] = 'u.deleted_at IS NULL';
    }

    if (!empty($filters['q'])) {
        // Recherche par PRÉFIXE, pour rester sur idx_users_name.
        $term = users_escape_like(trim((string) $filters['q']));
        $where[] = '(u.last_name LIKE :q1 OR u.post_name LIKE :q2
                     OR u.first_name LIKE :q3 OR u.username LIKE :q4)';
        $params['q1'] = $term . '%';
        $params['q2'] = $term . '%';
        $params['q3'] = $term . '%';
        $params['q4'] = $term . '%';
    }

    if (!empty($filters['status']) && isset(USERS_STATUSES[$filters['status']])) {
        $where[] = 'u.status = :status';
        $params['status'] = (string) $filters['status'];
    }

    if (!empty($filters['role'])) {
        $where[] = 'EXISTS (
            SELECT 1 FROM user_roles ur
              JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = u.id AND r.code = :role
        )';
        $params['role'] = (string) $filters['role'];
    }

    $from = 'FROM users u WHERE ' . implode(' AND ', $where);

    $total  = (int) db_value('SELECT COUNT(*) ' . $from, $params, true);
    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = min(max(1, $page), $pages);
    $offset = ($page - 1) * $perPage;

    $rows = db_all(
        'SELECT u.id, u.username, u.email, u.phone, u.last_name, u.post_name,
                u.first_name, u.status, u.must_change_password, u.last_login_at,
                u.locked_until, u.deleted_at, u.created_at
         ' . $from . '
         ORDER BY u.deleted_at IS NOT NULL, u.last_name, u.post_name, u.first_name
         LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params,
        true
    );

    if ($rows !== []) {
        $ids = implode(',', array_map(
            static fn (array $r): int => (int) $r['id'],
            $rows
        ));

        $roles = db_all(
            'SELECT ur.user_id, r.code, r.name, r.level
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id IN (' . $ids . ')
              ORDER BY r.level DESC',
            [],
            true
        );

        $byUser = [];

        foreach ($roles as $r) {
            $byUser[(int) $r['user_id']][] = $r;
        }

        foreach ($rows as &$row) {
            $row['roles'] = $byUser[(int) $row['id']] ?? [];
        }

        unset($row);
    }

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/** Échappe les jokers LIKE, pour qu'un « % » saisi cherche un « % ». */
function users_escape_like(string $term): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
}

/**
 * Un compte du personnel de l'école courante, ou null.
 *
 * C'est la porte d'entrée de TOUTES les écritures du module : si elle
 * rend null, l'objet n'existe pas pour cette école, quel que soit son
 * identifiant réel.
 */
function users_repo_find(int $userId): ?array
{
    $row = db_one(
        'SELECT u.*
           FROM users u
          WHERE u.id = :id
            AND u.school_id = :school_id
            AND ' . USERS_STAFF_PREDICATE . '
          LIMIT 1',
        ['id' => $userId, 'school_id' => tenant_require()],
        true
    );

    if ($row === null) {
        return null;
    }

    $row['roles'] = users_repo_roles($userId);

    return $row;
}

/** Les rôles d'un compte, du plus élevé au plus bas. */
function users_repo_roles(int $userId): array
{
    return db_all(
        'SELECT r.id, r.code, r.name, r.level, r.is_system
           FROM user_roles ur
           JOIN roles r ON r.id = ur.role_id
          WHERE ur.user_id = :id
          ORDER BY r.level DESC, r.name',
        ['id' => $userId],
        true
    );
}

/**
 * Le niveau hiérarchique de l'utilisateur connecté : le plus haut de
 * ses rôles, ou 0 s'il n'en a aucun.
 *
 * `roles.level` porte depuis l'origine le commentaire « un rôle ne
 * peut gérer qu'un rôle de niveau strictement inférieur ». Jusqu'ici,
 * AUCUNE ligne de code ne l'appliquait — faute d'écran qui attribue
 * des rôles. Ce module est cet écran, et il doit donc porter la règle.
 */
function users_repo_actor_level(): int
{
    $user = auth_user();

    if ($user === null) {
        return 0;
    }

    return (int) db_value(
        'SELECT COALESCE(MAX(r.level), 0)
           FROM user_roles ur
           JOIN roles r ON r.id = ur.role_id
          WHERE ur.user_id = :id',
        ['id' => (int) $user['id']],
        true
    );
}

/**
 * Les rôles que l'utilisateur connecté a le droit d'attribuer.
 *
 * Trois filtres, et chacun ferme une porte différente :
 *   · `level < :actor` — on n'attribue pas son propre niveau, et
 *     encore moins au-dessus. Sinon une direction se fabriquerait un
 *     administrateur d'école, puis se ferait promouvoir par lui ;
 *   · `school_id IS NULL OR school_id = :school` — un rôle
 *     personnalisé appartient à l'école qui l'a créé ;
 *   · les rôles de portail sont exclus (voir USERS_PORTAL_ROLES).
 *
 * @return array<int, array<string, mixed>>
 */
function users_repo_assignable_roles(): array
{
    $codes = "'" . implode("','", USERS_PORTAL_ROLES) . "'";

    return db_all(
        'SELECT r.id, r.code, r.name, r.description, r.level
           FROM roles r
          WHERE r.is_active = 1
            AND r.level < :actor
            AND (r.school_id IS NULL OR r.school_id = :school_id)
            AND r.code NOT IN (' . $codes . ')
          ORDER BY r.level DESC, r.name',
        ['actor' => users_repo_actor_level(), 'school_id' => tenant_require()],
        true
    );
}

/**
 * Combien de comptes VIVANTS de cette école portent une permission
 * donnée, en excluant éventuellement l'un d'eux ?
 *
 * Sert à refuser la manœuvre qui enfermerait l'école dehors : si le
 * dernier compte capable de créer des utilisateurs est désactivé,
 * plus personne ne peut en ouvrir un — et le produit a donné un accès
 * qu'il ne sait pas rendre (leçon de l'audit 6B).
 *
 * LECTURE SEULE — pour l'affichage. La DÉCISION passe par
 * `users_repo_lock_and_count_with_permission()`, qui verrouille.
 */
function users_repo_count_with_permission(string $permission, int $exceptUserId = 0): int
{
    return (int) db_value(
        'SELECT COUNT(DISTINCT u.id)
           FROM users u
           JOIN user_roles ur       ON ur.user_id = u.id
           JOIN role_permissions rp ON rp.role_id = ur.role_id
           JOIN permissions p       ON p.id = rp.permission_id
          WHERE u.school_id = :school_id
            AND u.deleted_at IS NULL
            AND u.status = \'active\'
            AND u.id <> :except
            AND p.code = :permission',
        [
            'school_id'  => tenant_require(),
            'except'     => $exceptUserId,
            'permission' => $permission,
        ],
        true
    );
}

/**
 * Le même décompte, mais SOUS VERROU — la version qui décide.
 *
 * LE DÉFAUT QUE CETTE FONCTION FERME (audit 7D, mesuré).
 * -------------------------------------------------------
 * Le contrôle « il reste quelqu'un » était une LECTURE suivie d'une
 * ÉCRITURE. Deux requêtes simultanées de l'éditeur, visant chacune une
 * direction différente, lisaient toutes deux « il en reste une » et
 * passaient toutes deux. Vérifié à deux processus : les deux comptes
 * désactivés, zéro porte ouverte, l'école enfermée dehors.
 *
 *   > Un compteur qui protège d'un état final ne vaut que verrouillé.
 *   > La leçon de la phase 5B, transposée : ce n'est pas l'argent
 *   > qu'on protège ici, c'est la capacité de rouvrir la porte.
 *
 * LE VERROU PORTE SUR TOUS LES CANDIDATS, LA CIBLE COMPRISE. C'est ce
 * qui fait que deux transactions concurrentes se disputent les MÊMES
 * lignes : exclure la cible du verrou leur donnerait deux ensembles
 * disjoints, et elles ne se croiseraient jamais.
 *
 * Deux temps, et seul le second verrouille : on ne pose pas de verrou
 * sur `permissions` ni `role_permissions`, qui sont partagées par tout
 * le parc.
 *
 * À n'appeler QUE dans une transaction — sinon le verrou tombe avec
 * l'instruction.
 */
function users_repo_lock_and_count_with_permission(string $permission, int $exceptUserId = 0): int
{
    $schoolId = tenant_require();

    // 1. Qui sont les candidats ? Lecture simple.
    $candidates = db_all(
        'SELECT DISTINCT u.id
           FROM users u
           JOIN user_roles ur       ON ur.user_id = u.id
           JOIN role_permissions rp ON rp.role_id = ur.role_id
           JOIN permissions p       ON p.id = rp.permission_id
          WHERE u.school_id = :school_id
            AND u.deleted_at IS NULL
            AND u.status = \'active\'
            AND p.code = :permission
          ORDER BY u.id',
        ['school_id' => $schoolId, 'permission' => $permission],
        true
    );

    if ($candidates === []) {
        return 0;
    }

    $ids = implode(',', array_map(
        static fn (array $r): int => (int) $r['id'],
        $candidates
    ));

    // 2. On verrouille ces lignes-là, dans l'ordre des identifiants —
    //    un ordre stable évite l'interblocage entre deux transactions
    //    qui verrouillent le même ensemble.
    $locked = db_all(
        'SELECT id, status, deleted_at
           FROM users
          WHERE school_id = :school_id AND id IN (' . $ids . ')
          ORDER BY id
          FOR UPDATE',
        ['school_id' => $schoolId],
        true
    );

    // 3. Relecture APRÈS verrou : l'état peut avoir changé pendant
    //    l'attente, et c'est précisément le cas qui nous intéresse.
    $remaining = 0;

    foreach ($locked as $row) {
        if ((int) $row['id'] !== $exceptUserId
            && $row['deleted_at'] === null
            && (string) $row['status'] === 'active') {
            $remaining++;
        }
    }

    return $remaining;
}

/** Le décompte affiché en tête de liste, par état. */
function users_repo_counts(): array
{
    $rows = db_all(
        'SELECT u.status, COUNT(*) AS n
           FROM users u
          WHERE u.school_id = :school_id
            AND u.deleted_at IS NULL
            AND ' . USERS_STAFF_PREDICATE . '
          GROUP BY u.status',
        ['school_id' => tenant_require()],
        true
    );

    $out = ['active' => 0, 'pending' => 0, 'inactive' => 0, 'suspended' => 0, 'archived' => 0];

    foreach ($rows as $r) {
        $out[(string) $r['status']] = (int) $r['n'];
    }

    $out['archived'] = (int) db_value(
        'SELECT COUNT(*) FROM users u
          WHERE u.school_id = :school_id
            AND u.deleted_at IS NOT NULL
            AND ' . USERS_STAFF_PREDICATE,
        ['school_id' => tenant_require()],
        true
    );

    return $out;
}
