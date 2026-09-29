<?php
/**
 * Module RÔLES — décisions (phase 11B).
 *
 * ════════════════════════════════════════════════════════════════════
 *  CE MODULE DISTRIBUE DU POUVOIR. C'EST DONC LUI QUI DOIT LE PLUS SE
 *  MÉFIER.
 * ════════════════════════════════════════════════════════════════════
 *
 * Laisser une école composer ses rôles est indispensable — chaque
 * établissement organise son secrétariat, sa préfecture et sa comptabilité
 * à sa façon, et les neuf rôles livrés ne peuvent pas tous les prévoir.
 * Mais c'est aussi le geste qui, mal gardé, permet à quelqu'un de
 * s'accorder ce qu'il n'a pas.
 *
 * CINQ RÈGLES, ET AUCUNE N'EST DÉCORATIVE
 * ---------------------------------------
 *
 *  1. UN RÔLE D'ÉCOLE APPARTIENT À SON ÉCOLE. `school_id` est posé par
 *     le service, jamais par le formulaire. Aucun rôle global ne naît
 *     ici : les rôles globaux sont la colonne vertébrale du produit.
 *
 *  2. UN RÔLE SYSTÈME NE SE MODIFIE PAS. Les neuf livrés sont ce sur
 *     quoi reposent l'installation, la création d'école et les portails.
 *     Les rendre modifiables, c'est permettre à une école de casser
 *     `SCHOOL_ADMIN` pour toutes les autres.
 *
 *  3. ON N'ACCORDE QUE CE QU'ON DÉTIENT. Un rôle ne peut porter que des
 *     permissions que son créateur possède lui-même. Sans cette règle,
 *     un secrétariat autorisé à gérer les rôles s'accorderait la
 *     comptabilité en trois clics.
 *
 *  4. JAMAIS UNE PERMISSION DE PLATEFORME. Même si le créateur la
 *     détient — et l'éditeur qui entre dans une école cliente la détient.
 *     La règle 3 seule laisserait passer un rôle d'école portant
 *     `platform.school.erase`, oublié là par quelqu'un de passage.
 *
 *  5. UN NIVEAU STRICTEMENT INFÉRIEUR AU SIEN. C'est la règle que la
 *     phase 7D applique déjà à l'ATTRIBUTION. Sans elle ici, on
 *     créerait un rôle de niveau supérieur au sien, qu'on ne pourrait
 *     plus attribuer — ou pire, qu'un collègue attribuerait, produisant
 *     quelqu'un qui vous gère.
 *
 * Les règles 3, 4 et 5 se recouvrent partiellement. C'est voulu : une
 * défense qui se répète survit à la disparition de l'une de ses couches.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/** Bornes du niveau d'un rôle d'école. */
const ROLES_LEVEL_MIN = 1;

/**
 * Crée un rôle propre à l'établissement.
 *
 * @param array{name: string, description?: string, level: int|string,
 *              permissions?: array<int, int|string>} $input
 * @return array{ok: bool, message: string, role_id: ?int}
 */
function roles_service_create(array $input): array
{
    if (!can('role.manage')) {
        return ['ok' => false, 'role_id' => null,
                'message' => 'Composer les rôles relève de l\'administration de l\'établissement.'];
    }

    $nom = trim((string) ($input['name'] ?? ''));

    if (mb_strlen($nom) < 3) {
        return ['ok' => false, 'role_id' => null,
                'message' => 'Le nom du rôle est requis (3 caractères au moins).'];
    }

    $niveau = roles_valider_niveau($input['level'] ?? null);

    if (!$niveau['ok']) {
        return ['ok' => false, 'role_id' => null, 'message' => $niveau['message']];
    }

    $permissions = roles_valider_permissions((array) ($input['permissions'] ?? []));

    if (!$permissions['ok']) {
        return ['ok' => false, 'role_id' => null, 'message' => $permissions['message']];
    }

    // LE CODE EST DÉRIVÉ, PAS SAISI.
    // Il sert de clé technique dans tout le produit (`r.code = 'SCHOOL_ADMIN'`).
    // Le laisser saisir, c'est laisser une école déclarer un rôle nommé
    // `SUPER_ADMIN`. Il est donc calculé, préfixé par l'école, et unique.
    $code = roles_code_libre($nom);

    $roleId = db_transaction(static function () use ($nom, $input, $niveau, $permissions, $code): int {
        $id = db_insert('roles', [
            'school_id'   => tenant_require(),
            'code'        => $code,
            'name'        => $nom,
            'description' => roles_texte($input['description'] ?? null, 255),
            'level'       => $niveau['valeur'],
            'is_system'   => 0,
            'is_active'   => 1,
        ], true);

        foreach ($permissions['ids'] as $permissionId) {
            db_query(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (:r, :p)',
                ['r' => $id, 'p' => $permissionId],
                true
            );
        }

        return $id;
    });

    audit_log('role.create', 'roles', $roleId, null, [
        'code'        => $code,
        'name'        => $nom,
        'level'       => $niveau['valeur'],
        'permissions' => count($permissions['ids']),
    ], 'Création du rôle ' . $nom);

    return ['ok' => true, 'role_id' => $roleId,
            'message' => 'Rôle « ' . $nom . ' » créé avec '
                . count($permissions['ids']) . ' permission(s).'];
}

/**
 * Modifie un rôle de l'établissement — nom, description, niveau,
 * permissions.
 *
 * @param array<string, mixed> $input
 * @return array{ok: bool, message: string}
 */
function roles_service_update(int $roleId, array $input): array
{
    if (!can('role.manage')) {
        return ['ok' => false, 'message' => 'Composer les rôles relève de l\'administration de l\'établissement.'];
    }

    $role = roles_repo_find($roleId);

    $refus = roles_refus_modification($role);

    if ($refus !== null) {
        return ['ok' => false, 'message' => $refus];
    }

    $nom = trim((string) ($input['name'] ?? ''));

    if (mb_strlen($nom) < 3) {
        return ['ok' => false, 'message' => 'Le nom du rôle est requis (3 caractères au moins).'];
    }

    $niveau = roles_valider_niveau($input['level'] ?? null);

    if (!$niveau['ok']) {
        return ['ok' => false, 'message' => $niveau['message']];
    }

    $permissions = roles_valider_permissions((array) ($input['permissions'] ?? []));

    if (!$permissions['ok']) {
        return ['ok' => false, 'message' => $permissions['message']];
    }

    // RETIRER UNE PERMISSION À UN RÔLE PORTÉ, C'EST RETIRER DES ACCÈS.
    //
    // Le produit exige déjà un motif pour DÉSACTIVER un rôle porté. Or
    // décocher quatre cases sur cinq produit exactement le même effet —
    // les comptes concernés perdent ces accès à leur requête suivante —
    // et passait, lui, sans un mot.
    //
    //   > Deux gestes qui font le même dégât ne peuvent pas avoir deux
    //   > exigences différentes : la plus faible devient le chemin
    //   > qu'on prend.
    //
    // L'AJOUT, lui, n'exige rien : ouvrir un accès se rattrape en le
    // refermant, alors qu'une perte d'accès en pleine journée d'école
    // arrête quelqu'un dans son travail sans qu'il sache pourquoi.
    $ancienNes = roles_repo_permission_ids($roleId);
    $retirees  = array_values(array_diff($ancienNes, $permissions['ids']));
    $porteurs  = roles_repo_holders($roleId);

    if ($retirees !== [] && $porteurs > 0 && mb_strlen(trim((string) ($input['reason'] ?? ''))) < 10) {
        return ['ok' => false, 'message' => sprintf(
            'Cette modification retire %d permission(s) à un rôle porté par '
            . '%d compte(s), qui perdront ces accès immédiatement. '
            . 'Un motif d\'au moins 10 caractères est exigé.',
            count($retirees),
            $porteurs
        )];
    }

    $avant = [
        'name'        => (string) $role['name'],
        'level'       => (int) $role['level'],
        'permissions' => count($ancienNes),
    ];

    db_transaction(static function () use ($roleId, $nom, $input, $niveau, $permissions): void {
        db_update('roles', [
            'name'        => $nom,
            'description' => roles_texte($input['description'] ?? null, 255),
            'level'       => $niveau['valeur'],
        ], 'id = :id', ['id' => $roleId], true);

        // REMPLACEMENT TOTAL, et c'est le bon geste : un écran de cases
        // à cocher décrit l'état voulu, pas une liste d'ajouts. Ce qui
        // n'est pas coché doit disparaître, sinon décocher ne ferait
        // rien et personne ne saurait retirer une permission.
        db_query('DELETE FROM role_permissions WHERE role_id = :r', ['r' => $roleId], true);

        foreach ($permissions['ids'] as $permissionId) {
            db_query(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (:r, :p)',
                ['r' => $roleId, 'p' => $permissionId],
                true
            );
        }
    });

    audit_log('role.update', 'roles', $roleId, $avant, [
        'name'        => $nom,
        'level'       => $niveau['valeur'],
        'permissions' => count($permissions['ids']),
        'retirees'    => count($retirees),
        'porteurs'    => $porteurs,
        'reason'      => trim((string) ($input['reason'] ?? '')),
    ], 'Modification du rôle ' . $nom);

    return ['ok' => true, 'message' => 'Rôle « ' . $nom . ' » mis à jour.'
        . ($retirees !== [] && $porteurs > 0
            ? sprintf(' %d permission(s) retirée(s) à %d compte(s).', count($retirees), $porteurs)
            : '')];
}

/**
 * Active ou désactive un rôle de l'établissement.
 *
 * ON NE SUPPRIME PAS UN RÔLE, ON LE DÉSACTIVE.
 * `user_roles` le référence ; le supprimer effacerait en cascade des
 * attributions dont personne ne garderait trace, et la question « qui
 * avait quoi » n'aurait plus de réponse. Un rôle désactivé n'est plus
 * attribuable — `users_role_refusal()` le refuse depuis la phase 7D —
 * et reste lisible dans l'historique.
 *
 * @return array{ok: bool, message: string}
 */
function roles_service_toggle(int $roleId, bool $actif, string $reason = ''): array
{
    if (!can('role.manage')) {
        return ['ok' => false, 'message' => 'Composer les rôles relève de l\'administration de l\'établissement.'];
    }

    $role = roles_repo_find($roleId);

    $refus = roles_refus_modification($role);

    if ($refus !== null) {
        return ['ok' => false, 'message' => $refus];
    }

    if ((int) $role['is_active'] === ($actif ? 1 : 0)) {
        return ['ok' => false, 'message' => 'Ce rôle est déjà ' . ($actif ? 'actif' : 'désactivé') . '.'];
    }

    $porteurs = roles_repo_holders($roleId);

    // DÉSACTIVER UN RÔLE PORTÉ RETIRE DES ACCÈS, SÉANCE TENANTE.
    // `perm_all()` ignore les rôles inactifs : les comptes concernés
    // perdront leurs permissions à leur requête suivante. Un motif est
    // donc exigé, comme pour la suspension d'un établissement.
    if (!$actif && $porteurs > 0 && mb_strlen(trim($reason)) < 10) {
        return ['ok' => false, 'message' => sprintf(
            '%d compte(s) portent ce rôle et perdront ces accès immédiatement. '
            . 'Un motif d\'au moins 10 caractères est exigé.',
            $porteurs
        )];
    }

    db_update('roles', ['is_active' => $actif ? 1 : 0], 'id = :id', ['id' => $roleId], true);

    audit_log(
        $actif ? 'role.activate' : 'role.deactivate',
        'roles',
        $roleId,
        ['is_active' => (int) $role['is_active']],
        ['is_active' => $actif ? 1 : 0, 'reason' => trim($reason), 'porteurs' => $porteurs],
        ($actif ? 'Réactivation' : 'Désactivation') . ' du rôle ' . (string) $role['name']
    );

    return ['ok' => true, 'message' => 'Rôle « ' . (string) $role['name'] . ' » '
        . ($actif ? 'réactivé' : 'désactivé')
        . ($porteurs > 0 ? ' — ' . $porteurs . ' compte(s) concerné(s).' : '.')];
}

// ---------------------------------------------------------------------
//  Les gardes
// ---------------------------------------------------------------------

/** Pourquoi ce rôle ne peut pas être modifié, ou null. */
function roles_refus_modification(?array $role): ?string
{
    if ($role === null) {
        return 'Ce rôle n\'existe pas, ou il appartient à un autre établissement.';
    }

    // RÈGLE 2 — un rôle système est la colonne vertébrale du produit.
    if ((int) $role['is_system'] === 1) {
        return 'Le rôle « ' . (string) $role['name'] . ' » est livré avec le produit : '
            . 'il est partagé par tous les établissements et ne se modifie pas. '
            . 'Créez-en un qui vous ressemble.';
    }

    // RÈGLE 1 — un rôle global n'appartient à personne en particulier.
    if ($role['school_id'] === null) {
        return 'Ce rôle est commun à toute la plateforme : il ne se modifie pas depuis un établissement.';
    }

    // RÈGLE 5 — on ne touche pas à un rôle d'un niveau qu'on n'atteint pas.
    $niveauActeur = roles_repo_actor_level();

    if ((int) $role['level'] >= $niveauActeur) {
        return sprintf(
            'Le rôle « %s » est de niveau %d, le vôtre de %d. On ne modifie '
            . 'qu\'un rôle d\'un niveau strictement inférieur au sien.',
            (string) $role['name'],
            (int) $role['level'],
            $niveauActeur
        );
    }

    return null;
}

/**
 * Le niveau demandé est-il recevable ?
 *
 * @return array{ok: bool, message: string, valeur: int}
 */
function roles_valider_niveau(mixed $valeur): array
{
    if (!is_numeric($valeur)) {
        return ['ok' => false, 'valeur' => 0, 'message' => 'Le niveau du rôle est requis.'];
    }

    $niveau = (int) $valeur;
    $acteur = roles_repo_actor_level();

    if ($niveau < ROLES_LEVEL_MIN) {
        return ['ok' => false, 'valeur' => 0,
                'message' => 'Le niveau doit valoir au moins ' . ROLES_LEVEL_MIN . '.'];
    }

    // RÈGLE 5 — strictement inférieur au sien.
    if ($niveau >= $acteur) {
        return ['ok' => false, 'valeur' => 0, 'message' => sprintf(
            'Le niveau doit rester strictement inférieur au vôtre (%d). '
            . 'Un rôle de niveau supérieur ne pourrait pas vous être retiré '
            . 'par vous-même.',
            $acteur
        )];
    }

    return ['ok' => true, 'valeur' => $niveau, 'message' => ''];
}

/**
 * Les permissions demandées sont-elles accordables ?
 *
 * @param array<int, int|string> $demandees
 * @return array{ok: bool, message: string, ids: array<int, int>}
 */
function roles_valider_permissions(array $demandees): array
{
    $ids = array_values(array_unique(array_map('intval', $demandees)));

    if ($ids === []) {
        return ['ok' => false, 'ids' => [],
                'message' => 'Un rôle sans aucune permission n\'ouvre rien : '
                    . 'cochez au moins une capacité.'];
    }

    // RÈGLE 4 — jamais une permission de plateforme, quoi que détienne
    // celui qui compose le rôle.
    $accordables = [];

    foreach (roles_repo_grantable_permissions() as $permission) {
        $accordables[(int) $permission['id']] = (string) $permission['code'];
    }

    $horsCatalogue = array_values(array_diff($ids, array_keys($accordables)));

    if ($horsCatalogue !== []) {
        return ['ok' => false, 'ids' => [],
                'message' => 'Certaines permissions demandées n\'existent pas, ou relèvent '
                    . 'de la plateforme et ne s\'accordent jamais depuis un établissement.'];
    }

    // RÈGLE 3 — on n'accorde que ce qu'on détient.
    $miennes = perm_all();
    $refusees = [];

    foreach ($ids as $id) {
        if (!in_array($accordables[$id], $miennes, true)) {
            $refusees[] = $accordables[$id];
        }
    }

    if ($refusees !== []) {
        return ['ok' => false, 'ids' => [], 'message' => sprintf(
            'Vous ne détenez pas %s : on n\'accorde pas ce qu\'on n\'a pas. (%s)',
            count($refusees) > 1 ? 'ces permissions' : 'cette permission',
            implode(', ', array_slice($refusees, 0, 4)) . (count($refusees) > 4 ? '…' : '')
        )];
    }

    return ['ok' => true, 'ids' => $ids, 'message' => ''];
}

// ---------------------------------------------------------------------
//  Appui
// ---------------------------------------------------------------------

/**
 * Un code technique libre, dérivé du nom et préfixé par l'école.
 *
 * `roles.code` sert de clé dans tout le produit — `r.code =
 * 'SCHOOL_ADMIN'` apparaît dans l'installateur, la création d'école et
 * les portails. Le laisser saisir, c'est laisser une école déclarer un
 * rôle nommé `SUPER_ADMIN` et brouiller ces requêtes.
 *
 *   > Une clé technique que l'utilisateur choisit n'est plus une clé :
 *   > c'est une collision qui attend son heure.
 */
function roles_code_libre(string $nom): string
{
    $ecole = tenant_require();
    $base  = strtoupper(str_replace('-', '_', str_slug($nom)));
    $base  = $base !== '' ? mb_substr($base, 0, 22) : 'ROLE';
    $base  = 'E' . $ecole . '_' . $base;

    $essai = mb_substr($base, 0, 40);

    for ($n = 2; $n < 200; $n++) {
        $pris = db_value('SELECT 1 FROM roles WHERE code = :c LIMIT 1', ['c' => $essai], true);

        if ($pris === null) {
            return $essai;
        }

        $suffixe = '_' . $n;
        $essai = mb_substr($base, 0, 40 - mb_strlen($suffixe)) . $suffixe;
    }

    return mb_substr($base, 0, 33) . '_' . bin2hex(random_bytes(3));
}

/** Un texte nettoyé, tronqué, ou null s'il est vide. */
function roles_texte(mixed $valeur, int $max): ?string
{
    $texte = trim((string) ($valeur ?? ''));

    return $texte === '' ? null : mb_substr($texte, 0, $max);
}
