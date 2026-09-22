<?php
/**
 * Module UTILISATEURS — les règles.
 *
 * CE MODULE EST LE PLUS DANGEREUX DU PRODUIT
 * ===========================================
 * Tous les autres écrans décident de ce qu'on peut FAIRE. Celui-ci
 * décide de QUI PEUT LE FAIRE. Une erreur ici ne fuit pas une donnée :
 * elle fabrique un compte qui en fuira toutes, longtemps, sans bruit.
 *
 * Quatre règles le tiennent, et aucune ne vit dans une vue.
 *
 * 1. LA HIÉRARCHIE DES RÔLES
 * ---------------------------
 * `roles.level` porte depuis la phase 1 le commentaire « un rôle ne
 * peut gérer qu'un rôle de niveau strictement inférieur ». Jusqu'à
 * aujourd'hui, AUCUNE ligne ne l'appliquait : il n'existait pas
 * d'écran qui attribue un rôle. Le commentaire était une intention.
 *
 *   > Un invariant qu'aucune requête ne sait vérifier n'est pas un
 *   > invariant, c'est une intention.
 *
 * Sans cette règle, une DIRECTION (niveau 80) créerait un
 * SCHOOL_ADMIN (90), se ferait promouvoir par lui, et l'école n'aurait
 * plus de hiérarchie. C'est l'élévation de privilège classique, et
 * elle est ici à un clic.
 *
 * 2. LA LIMITE D'ABONNEMENT FERME LA CRÉATION, ET RIEN D'AUTRE
 * -------------------------------------------------------------
 * `subscription_can_add_staff_user()` existe depuis la 7A et n'avait
 * aucune porte : la jauge rougissait en annonçant un blocage que rien
 * ne portait. Ce module EST la porte.
 *
 *   > Une limite commerciale ne ferme jamais la caisse.
 *
 * Elle refuse donc la CRÉATION. Jamais la lecture, jamais la
 * modification, et surtout jamais la désactivation — qui est
 * précisément ce qui libère une place.
 *
 * 3. ON NE SUPPRIME PAS UN COMPTE
 * --------------------------------
 * `audit_logs.user_id` pointe sur des comptes. Effacer une ligne
 * `users` effacerait l'auteur de tout ce qu'elle a fait. Un compte se
 * désactive (réversible) ou s'archive (la personne est partie) ; les
 * deux libèrent une place au décompte d'abonnement.
 *
 * 4. ON NE S'ENFERME PAS DEHORS
 * ------------------------------
 * Un directeur qui se désactive lui-même, ou qui désactive le dernier
 * compte capable de créer des utilisateurs, laisse l'école sans aucun
 * moyen de rouvrir une porte.
 *
 *   > Un produit qui donne un accès doit savoir le rendre.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';
require_once APP_PATH . '/modules/subscriptions/services.php';

/**
 * Mot de passe initial.
 *
 * Même alphabet que le portail : ni O/0, ni I/l/1. Un secrétaire qui
 * recopie un code sur un papier ne doit pas se tromper de caractère.
 * La fonction est réécrite plutôt qu'importée : ce module ne doit pas
 * dépendre du portail, qui est un domaine sans rapport.
 */
const USERS_PASSWORD_LENGTH   = 12;
const USERS_PASSWORD_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

function users_generate_password(): string
{
    $alphabet = USERS_PASSWORD_ALPHABET;
    $max      = strlen($alphabet) - 1;
    $out      = '';

    for ($i = 0; $i < USERS_PASSWORD_LENGTH; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }

    return $out;
}

/**
 * Identifiant de connexion, unique dans TOUTE la plateforme.
 *
 * `uq_users_username` n'est pas par école : deux écoles ne peuvent pas
 * avoir chacune leur « jean.mukendi ». L'unicité se vérifie donc hors
 * périmètre d'établissement — c'est une lecture d'identité, et elle en
 * porte le nom.
 */
function users_build_username(string $firstName, string $lastName): string
{
    $base = str_slug($firstName . ' ' . $lastName, '.');
    $base = $base !== '' ? mb_substr($base, 0, 40) : 'utilisateur';

    $candidate = $base;
    $suffix    = 1;

    while (tenant_scope_identity(static fn (): bool => db_exists(
        'SELECT 1 FROM users WHERE username = :u', ['u' => $candidate], true
    ))) {
        $suffix++;
        $candidate = $base . '.' . $suffix;
    }

    return $candidate;
}

/**
 * Le rôle demandé est-il attribuable par l'utilisateur connecté ?
 *
 * LA DÉCISION EST SÉPARÉE DE SON RENDU : elle rend un motif, ou null.
 * Une règle métier terminée par `abort()` n'est observable qu'au
 * navigateur — la leçon de `platform_require()` en 7B1.
 */
function users_role_refusal(int $roleId): ?string
{
    $role = db_one(
        'SELECT id, code, name, level, school_id, is_active
           FROM roles WHERE id = :id LIMIT 1',
        ['id' => $roleId],
        true
    );

    if ($role === null || (int) $role['is_active'] !== 1) {
        return 'Ce rôle n\'existe pas, ou il a été désactivé.';
    }

    if (in_array((string) $role['code'], USERS_PORTAL_ROLES, true)) {
        return 'Le rôle ' . $role['name'] . ' est celui du portail : il s\'attribue '
            . 'depuis la fiche du tuteur ou de l\'élève, avec le dossier qui va avec.';
    }

    if ($role['school_id'] !== null && (int) $role['school_id'] !== tenant_require()) {
        return 'Ce rôle appartient à un autre établissement.';
    }

    $actorLevel = users_repo_actor_level();

    if ((int) $role['level'] >= $actorLevel) {
        return 'Vous ne pouvez attribuer que des rôles d\'un niveau strictement '
            . 'inférieur au vôtre. Le rôle ' . $role['name'] . ' est de niveau '
            . $role['level'] . ', le vôtre de ' . $actorLevel . '.';
    }

    return null;
}

/**
 * Peut-on agir sur CE compte ?
 *
 * Un compte porté par un rôle supérieur ou égal au sien ne se modifie
 * pas : sans cette règle, la hiérarchie de l'attribution serait
 * contournable en deux temps — on ne peut pas créer un administrateur,
 * mais on pourrait réinitialiser le mot de passe de celui qui existe
 * et prendre sa place.
 */
function users_target_refusal(array $target): ?string
{
    $actorLevel  = users_repo_actor_level();
    $targetLevel = 0;

    // LE NIVEAU RETENU EST LE PLUS HAUT DES RÔLES PORTÉS.
    // Un compte qui cumule secrétariat et administration est un compte
    // d'administration : prendre le plus bas ouvrirait la porte par le
    // rôle le plus modeste. Vérifié par exécution (audit 7D).
    foreach ($target['roles'] ?? [] as $r) {
        $targetLevel = max($targetLevel, (int) $r['level']);
    }

    // UN COMPTE SANS AUCUN RÔLE RESTE ATTEIGNABLE (niveau 0).
    // Le rendre intouchable le rendrait aussi irrécupérable : plus
    // personne ne pourrait lui attribuer un rôle ni l'archiver. Il
    // n'ouvre rien, il ne protège donc rien.
    if ($targetLevel >= $actorLevel) {
        return 'Ce compte est porté par un rôle au moins égal au vôtre : '
            . 'seul un niveau supérieur peut agir dessus. '
            . 'S\'il s\'agit d\'un compte d\'administration dont le titulaire a quitté '
            . 'l\'établissement, l\'éditeur de la plateforme peut intervenir.';
    }

    return null;
}

/**
 * Crée un compte du personnel.
 *
 * @param array{last_name: string, first_name: string, post_name?: string,
 *              email?: string, phone?: string, gender?: string,
 *              roles?: array<int, int|string>} $input
 * @return array{ok: bool, message: string, id?: int, username?: string, password?: string}
 */
function users_service_create(array $input): array
{
    if (!can('user.create')) {
        return ['ok' => false, 'message' => 'Créer un compte relève de l\'administration de l\'école.'];
    }

    $schoolId = tenant_require();

    $lastName  = trim((string) ($input['last_name'] ?? ''));
    $firstName = trim((string) ($input['first_name'] ?? ''));

    if ($lastName === '' || $firstName === '') {
        return ['ok' => false, 'message' => 'Le nom et le prénom sont obligatoires.'];
    }

    $roleIds = array_values(array_unique(array_map(
        'intval',
        (array) ($input['roles'] ?? [])
    )));

    if ($roleIds === []) {
        // UN COMPTE SANS RÔLE N'OUVRE RIEN.
        // Le créer donnerait une connexion qui mène à une page vide, et
        // l'école conclurait que le produit est cassé.
        return ['ok' => false, 'message' => 'Un compte doit porter au moins un rôle : '
            . 'sans rôle, il se connecte et ne peut rien ouvrir.'];
    }

    foreach ($roleIds as $roleId) {
        $refusal = users_role_refusal($roleId);

        if ($refusal !== null) {
            return ['ok' => false, 'message' => $refusal];
        }
    }

    // LA LIMITE COMMERCIALE, ENFIN PORTÉE PAR UNE PORTE.
    $allowed = subscription_can_add_staff_user();

    if (!$allowed['ok']) {
        return ['ok' => false, 'message' => $allowed['message']];
    }

    $email = trim((string) ($input['email'] ?? ''));

    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'L\'adresse e-mail n\'est pas valide.'];
        }

        // `uq_users_email` est GLOBALE, comme `uq_users_username`.
        // Le dire ici évite une erreur SQL brute à l'écran.
        if (tenant_scope_identity(static fn (): bool => db_exists(
            'SELECT 1 FROM users WHERE email = :e', ['e' => $email], true
        ))) {
            return ['ok' => false, 'message' => 'Cette adresse e-mail est déjà utilisée '
                . 'par un compte de la plateforme.'];
        }
    }

    $username = users_build_username($firstName, $lastName);
    $password = users_generate_password();
    $actor    = auth_user();

    $userId = db_transaction(static function () use (
        $schoolId, $username, $email, $input, $lastName, $firstName, $password, $roleIds, $actor
    ): int {
        $id = db_insert('users', [
            'uuid'                 => str_uuid(),
            'school_id'            => $schoolId,
            'username'             => $username,
            'email'                => $email !== '' ? $email : null,
            'phone'                => trim((string) ($input['phone'] ?? '')) ?: null,
            'password_hash'        => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'last_name'            => $lastName,
            'post_name'            => trim((string) ($input['post_name'] ?? '')) ?: null,
            'first_name'           => $firstName,
            'gender'               => in_array($input['gender'] ?? '', ['M', 'F'], true)
                ? (string) $input['gender'] : null,
            'status'               => 'active',
            'must_change_password' => 1,
            'password_changed_at'  => date('Y-m-d H:i:s'),
            'created_by'           => $actor !== null ? (int) $actor['id'] : null,
        ], true);

        foreach ($roleIds as $roleId) {
            db_query(
                'INSERT INTO user_roles (user_id, role_id, assigned_by)
                 VALUES (:u, :r, :by)',
                [
                    'u'  => $id,
                    'r'  => $roleId,
                    'by' => $actor !== null ? (int) $actor['id'] : null,
                ],
                true
            );
        }

        return $id;
    });

    // Le mot de passe N'EST PAS journalisé.
    audit_log('user.create', 'users', $userId, null, [
        'username' => $username,
        'roles'    => $roleIds,
    ]);

    return [
        'ok'       => true,
        'message'  => 'Compte créé pour ' . $firstName . ' ' . $lastName . '.',
        'id'       => $userId,
        'username' => $username,
        'password' => $password,
    ];
}

/**
 * Modifie l'état civil et les coordonnées d'un compte.
 *
 * Ni l'identifiant, ni l'école, ni le mot de passe : chacun a sa
 * propre porte, et les mélanger ferait d'un formulaire d'état civil
 * un outil de prise de contrôle.
 */
function users_service_update(int $userId, array $input): array
{
    if (!can('user.edit')) {
        return ['ok' => false, 'message' => 'Modifier un compte relève de l\'administration de l\'école.'];
    }

    $target = users_repo_find($userId);

    if ($target === null) {
        return ['ok' => false, 'message' => 'Compte introuvable dans cet établissement.'];
    }

    $refusal = users_target_refusal($target);

    if ($refusal !== null) {
        return ['ok' => false, 'message' => $refusal];
    }

    $lastName  = trim((string) ($input['last_name'] ?? ''));
    $firstName = trim((string) ($input['first_name'] ?? ''));

    if ($lastName === '' || $firstName === '') {
        return ['ok' => false, 'message' => 'Le nom et le prénom sont obligatoires.'];
    }

    $email = trim((string) ($input['email'] ?? ''));

    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'L\'adresse e-mail n\'est pas valide.'];
        }

        if (tenant_scope_identity(static fn (): bool => db_exists(
            'SELECT 1 FROM users WHERE email = :e AND id <> :id',
            ['e' => $email, 'id' => $userId],
            true
        ))) {
            return ['ok' => false, 'message' => 'Cette adresse e-mail est déjà utilisée '
                . 'par un autre compte de la plateforme.'];
        }
    }

    // L'ÉCOLE EST DANS L'ÉCRITURE, pas seulement dans le contrôle
    // situé plus haut : un remaniement ne doit pas pouvoir la déplacer.
    db_query(
        'UPDATE users
            SET last_name = :last_name, post_name = :post_name,
                first_name = :first_name, email = :email, phone = :phone,
                gender = :gender
          WHERE id = :id AND school_id = :school_id',
        [
            'last_name'  => $lastName,
            'post_name'  => trim((string) ($input['post_name'] ?? '')) ?: null,
            'first_name' => $firstName,
            'email'      => $email !== '' ? $email : null,
            'phone'      => trim((string) ($input['phone'] ?? '')) ?: null,
            'gender'     => in_array($input['gender'] ?? '', ['M', 'F'], true)
                ? (string) $input['gender'] : null,
            'id'         => $userId,
            'school_id'  => tenant_require(),
        ],
        true
    );

    audit_log('user.update', 'users', $userId, [
        'last_name'  => $target['last_name'],
        'first_name' => $target['first_name'],
        'email'      => $target['email'],
    ], [
        'last_name'  => $lastName,
        'first_name' => $firstName,
        'email'      => $email,
    ]);

    return ['ok' => true, 'message' => 'Compte mis à jour.'];
}

/**
 * Remplace les rôles d'un compte.
 *
 * Remplacement complet plutôt qu'ajout/retrait : « quels rôles a ce
 * compte ? » doit avoir une réponse, pas un historique d'opérations.
 */
function users_service_set_roles(int $userId, array $roleIds): array
{
    if (!can('user.edit')) {
        return ['ok' => false, 'message' => 'Modifier les rôles relève de l\'administration de l\'école.'];
    }

    $target = users_repo_find($userId);

    if ($target === null) {
        return ['ok' => false, 'message' => 'Compte introuvable dans cet établissement.'];
    }

    $refusal = users_target_refusal($target);

    if ($refusal !== null) {
        return ['ok' => false, 'message' => $refusal];
    }

    $actor = auth_user();

    // ON NE SE RETIRE PAS SES PROPRES ROLES.
    // Le compte se retrouverait connecté sans pouvoir rien ouvrir, ni
    // revenir en arrière.
    if ($actor !== null && (int) $actor['id'] === $userId) {
        return ['ok' => false, 'message' => 'Vous ne pouvez pas modifier vos propres rôles. '
            . 'Un autre administrateur doit le faire.'];
    }

    $roleIds = array_values(array_unique(array_map('intval', $roleIds)));

    if ($roleIds === []) {
        return ['ok' => false, 'message' => 'Un compte doit porter au moins un rôle : '
            . 'pour lui retirer tout accès, désactivez-le.'];
    }

    foreach ($roleIds as $roleId) {
        $refusal = users_role_refusal($roleId);

        if ($refusal !== null) {
            return ['ok' => false, 'message' => $refusal];
        }
    }

    // ON NE RETIRE PAS UN RÔLE QU'ON NE POURRAIT PAS REDONNER.
    //
    // Le remplacement est total : tout rôle absent de l'envoi
    // disparaît. Si le compte porte un rôle que l'acteur n'a pas le
    // droit d'attribuer — un rôle désactivé depuis, ou créé par une
    // autre école — le soumettre sans le cocher l'effacerait, et
    // l'acteur serait incapable de revenir en arrière. L'opération
    // serait à sens unique, ce qui est précisément ce que la
    // hiérarchie interdit.
    //
    // Ces rôles-là sont donc CONSERVÉS, quoi que dise le formulaire.
    $assignable = array_map(
        static fn (array $r): int => (int) $r['id'],
        users_repo_assignable_roles()
    );

    $kept = array_values(array_diff(
        array_map(static fn (array $r): int => (int) $r['id'], $target['roles']),
        $assignable
    ));

    $roleIds = array_values(array_unique(array_merge($roleIds, $kept)));

    $before = array_map(static fn (array $r): string => (string) $r['code'], $target['roles']);

    db_transaction(static function () use ($userId, $roleIds, $actor): void {
        db_query('DELETE FROM user_roles WHERE user_id = :u', ['u' => $userId], true);

        foreach ($roleIds as $roleId) {
            db_query(
                'INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (:u, :r, :by)',
                ['u' => $userId, 'r' => $roleId, 'by' => $actor !== null ? (int) $actor['id'] : null],
                true
            );
        }
    });

    audit_log('user.set_roles', 'users', $userId, ['roles' => $before], ['roles' => $roleIds]);

    return [
        'ok'      => true,
        'message' => 'Rôles mis à jour.'
            . ($kept !== []
                ? ' ' . count($kept) . ' rôle(s) que vous ne pouvez pas attribuer '
                  . 'ont été conservés : on ne retire pas ce qu\'on ne saurait redonner.'
                : ''),
    ];
}

/**
 * Change l'état d'un compte — jamais sa suppression.
 *
 * `active` / `suspended` / `inactive` sont réversibles. L'archivage
 * (`deleted_at`) dit « cette personne a quitté l'établissement » ; il
 * se défait aussi, parce qu'une personne revient.
 */
function users_service_set_status(int $userId, string $status, string $reason = ''): array
{
    if (!can('user.delete')) {
        return ['ok' => false, 'message' => 'Désactiver un compte relève de l\'administration de l\'école.'];
    }

    if (!isset(USERS_STATUSES[$status])) {
        return ['ok' => false, 'message' => 'État de compte inconnu.'];
    }

    $target = users_repo_find($userId);

    if ($target === null) {
        return ['ok' => false, 'message' => 'Compte introuvable dans cet établissement.'];
    }

    $refusal = users_target_refusal($target);

    if ($refusal !== null) {
        return ['ok' => false, 'message' => $refusal];
    }

    $actor = auth_user();

    // ON NE SE DÉSACTIVE PAS SOI-MÊME.
    if ($actor !== null && (int) $actor['id'] === $userId && $status !== 'active') {
        return ['ok' => false, 'message' => 'Vous ne pouvez pas désactiver votre propre compte.'];
    }

    if ($status === 'suspended' && trim($reason) === '') {
        return ['ok' => false, 'message' => 'Une suspension demande un motif : '
            . '« suspendu » sans raison ne se relit pas dans six mois.'];
    }

    // ON NE FERME PAS LA DERNIÈRE PORTE — SOUS VERROU.
    //
    // Si ce compte est le dernier à pouvoir en créer d'autres, le
    // désactiver laisse l'école sans aucun moyen de rouvrir un accès.
    //
    // LE CONTRÔLE ET L'ÉCRITURE VIVENT DANS LA MÊME TRANSACTION.
    // Séparés, ils laissaient passer deux désactivations simultanées :
    // chacune lisait « il en reste une » et fermait la sienne. Mesuré
    // à deux processus, école enfermée dehors (audit 7D).
    $outcome = db_transaction(static function () use ($userId, $status, $target, $reason): array {
        if ($status !== 'active'
            && users_repo_lock_and_count_with_permission('user.create', $userId) === 0) {
            return [
                'ok'      => false,
                'message' => 'C\'est le dernier compte actif capable de créer des utilisateurs. '
                    . 'Le désactiver enfermerait l\'établissement dehors : ouvrez d\'abord '
                    . 'un autre compte d\'administration.',
            ];
        }

        db_query(
            'UPDATE users SET status = :status WHERE id = :id AND school_id = :school_id',
            ['status' => $status, 'id' => $userId, 'school_id' => tenant_require()],
            true
        );

        return ['ok' => true, 'message' => 'Compte ' . strtolower(USERS_STATUSES[$status]) . '.'];
    });

    if (!$outcome['ok']) {
        return $outcome;
    }

    audit_log('user.set_status', 'users', $userId,
        ['status' => $target['status']],
        ['status' => $status, 'reason' => trim($reason)]
    );

    return $outcome;
}

/**
 * Archive un compte, ou le rappelle.
 *
 * JAMAIS DE DELETE : `audit_logs.user_id` pointe sur ces lignes.
 * Effacer un compte effacerait l'auteur de tout ce qu'il a fait, et un
 * journal sans auteur ne prouve rien.
 */
function users_service_archive(int $userId, bool $archive, string $reason = ''): array
{
    if (!can('user.delete')) {
        return ['ok' => false, 'message' => 'Archiver un compte relève de l\'administration de l\'école.'];
    }

    $target = users_repo_find($userId);

    if ($target === null) {
        return ['ok' => false, 'message' => 'Compte introuvable dans cet établissement.'];
    }

    $refusal = users_target_refusal($target);

    if ($refusal !== null) {
        return ['ok' => false, 'message' => $refusal];
    }

    $actor = auth_user();

    if ($archive && $actor !== null && (int) $actor['id'] === $userId) {
        return ['ok' => false, 'message' => 'Vous ne pouvez pas archiver votre propre compte.'];
    }

    if ($archive && trim($reason) === '') {
        return ['ok' => false, 'message' => 'Archiver un compte demande un motif : '
            . '« pourquoi ce compte a-t-il été fermé ? » est une vraie question.'];
    }

    // MÊME VERROU QUE LA DÉSACTIVATION, ET POUR LA MÊME RAISON :
    // archiver ferme la porte aussi sûrement, et deux archivages
    // simultanés la fermeraient à deux.
    $outcome = db_transaction(static function () use ($userId, $archive): array {
        if ($archive
            && users_repo_lock_and_count_with_permission('user.create', $userId) === 0) {
            return [
                'ok'      => false,
                'message' => 'C\'est le dernier compte actif capable de créer des utilisateurs. '
                    . 'L\'archiver enfermerait l\'établissement dehors.',
            ];
        }

        db_query(
            'UPDATE users
                SET deleted_at = ' . ($archive ? 'NOW()' : 'NULL') . ',
                    status = :status
              WHERE id = :id AND school_id = :school_id',
            [
                'status'    => $archive ? 'inactive' : 'active',
                'id'        => $userId,
                'school_id' => tenant_require(),
            ],
            true
        );

        return ['ok' => true, 'message' => ''];
    });

    if (!$outcome['ok']) {
        return $outcome;
    }

    audit_log($archive ? 'user.archive' : 'user.restore', 'users', $userId, null, [
        'username' => $target['username'],
        'reason'   => trim($reason),
    ]);

    return [
        'ok'      => true,
        'message' => $archive
            ? 'Compte archivé. Il reste dans le journal, et sa place se libère dans l\'abonnement.'
            : 'Compte rappelé et réactivé.',
    ];
}

/**
 * Régénère le mot de passe d'un compte du personnel.
 *
 * Le pendant de `portal_service_reset_access()`, qui refuse déjà les
 * comptes du personnel. Les deux fonctions se renvoient l'une à
 * l'autre, et aucune n'écrit sur le domaine de l'autre.
 *
 * @return array{ok: bool, message: string, username?: string, password?: string}
 */
function users_service_reset_password(int $userId): array
{
    if (!can('user.reset_password')) {
        return ['ok' => false, 'message' => 'Réinitialiser un mot de passe relève de '
            . 'l\'administration de l\'école.'];
    }

    $target = users_repo_find($userId);

    if ($target === null) {
        return ['ok' => false, 'message' => 'Compte introuvable dans cet établissement, '
            . 'ou compte de famille — ceux-là se réinitialisent depuis la fiche du '
            . 'tuteur ou de l\'élève.'];
    }

    $refusal = users_target_refusal($target);

    if ($refusal !== null) {
        return ['ok' => false, 'message' => $refusal];
    }

    $password = users_generate_password();

    // L'école EST dans l'écriture.
    db_query(
        'UPDATE users
            SET password_hash = :hash,
                must_change_password = 1,
                password_changed_at = NOW(),
                failed_attempts = 0,
                locked_until = NULL
          WHERE id = :id AND school_id = :school_id',
        [
            'hash'      => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'id'        => $userId,
            'school_id' => tenant_require(),
        ],
        true
    );

    // Le mot de passe N'EST PAS journalisé.
    audit_log('user.reset_password', 'users', $userId, null, [
        'username' => $target['username'],
    ]);

    return [
        'ok'       => true,
        'message'  => 'Nouveau mot de passe généré pour ' . $target['username'] . '.',
        'username' => (string) $target['username'],
        'password' => $password,
    ];
}

/**
 * Lève un verrouillage après échecs de connexion répétés.
 *
 * Sans cet écran, un enseignant qui s'est trompé cinq fois attend que
 * `locked_until` expire, sans savoir combien de temps.
 */
function users_service_unlock(int $userId): array
{
    if (!can('user.edit')) {
        return ['ok' => false, 'message' => 'Déverrouiller un compte relève de l\'administration.'];
    }

    $target = users_repo_find($userId);

    if ($target === null) {
        return ['ok' => false, 'message' => 'Compte introuvable dans cet établissement.'];
    }

    $refusal = users_target_refusal($target);

    if ($refusal !== null) {
        return ['ok' => false, 'message' => $refusal];
    }

    db_query(
        'UPDATE users SET failed_attempts = 0, locked_until = NULL
          WHERE id = :id AND school_id = :school_id',
        ['id' => $userId, 'school_id' => tenant_require()],
        true
    );

    audit_log('user.unlock', 'users', $userId, null, ['username' => $target['username']]);

    return ['ok' => true, 'message' => 'Compte déverrouillé.'];
}
