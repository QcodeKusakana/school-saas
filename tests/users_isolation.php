<?php
/**
 * Phase 7D — les comptes du personnel.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · LA HIÉRARCHIE : on n'attribue jamais un rôle de son niveau ou
 *    au-dessus, et on n'agit pas sur un compte porté par un tel rôle ;
 *  · un compte de FAMILLE et un compte de PLATEFORME n'existent pas
 *    pour ce module ;
 *  · la limite d'abonnement ferme la CRÉATION, et rien d'autre ;
 *  · on ne se désactive pas soi-même, et on ne désactive pas le
 *    dernier compte capable d'en créer ;
 *  · un compte ne se supprime jamais ;
 *  · l'isolation multi-école tient sur chaque écriture.
 *
 * Usage : php tests/users_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/users/services.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

/**
 * Ouvre une session pour ce compte.
 *
 * ON NE POSE PAS LE CONTEXTE D'ÉCOLE À LA MAIN : `auth_user()` le
 * relit en base — depuis `school_id`, ou depuis `visiting_school_id`
 * pour un compte de la plateforme qui a ouvert une école (7B1). Un
 * `tenant_set()` après coup écraserait précisément ce que le produit
 * vient de décider, et le test mesurerait autre chose que la réalité.
 */
function act_as(int $userId, ?int $schoolId): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);
}

/** Crée un compte directement en base, avec ses rôles. */
function seed_account(int $schoolId, string $username, string $roleCode): int
{
    return platform_scope_cli(static function () use ($schoolId, $username, $roleCode): int {
        $id = db_insert('users', [
            'uuid' => str_uuid(), 'school_id' => $schoolId, 'username' => $username,
            'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
            'last_name' => strtoupper($roleCode), 'first_name' => 'Test', 'status' => 'active',
        ], true);

        db_query(
            'INSERT INTO user_roles (user_id, role_id)
             SELECT :u, id FROM roles WHERE code = :c AND school_id IS NULL',
            ['u' => $id, 'c' => $roleCode],
            true
        );

        return $id;
    });
}

$schools = [];
$users   = [];
$made    = [];

try {
    foreach (['USR-A' => 'École Un', 'USR-B' => 'École Deux'] as $code => $name) {
        $schools[$code] = platform_scope_cli(static fn (): int => db_insert('schools', [
            'uuid' => str_uuid(), 'code' => $code, 'slug' => strtolower($code),
            'name' => $name, 'status' => 'active',
        ], true));
    }

    $A = $schools['USR-A'];
    $B = $schools['USR-B'];

    // Une offre, sinon la limite d'abonnement refuse tout.
    platform_scope_cli(static function () use ($A, $B): void {
        foreach ([$A, $B] as $s) {
            db_insert('subscriptions', [
                'school_id' => $s,
                'plan_id'   => (int) db_value("SELECT id FROM plans WHERE code='PRO'", [], true),
                'status'    => 'active', 'billing_cycle' => 'yearly',
                'starts_on' => date('Y-m-d'), 'ends_on' => date('Y-m-d', strtotime('+1 year')),
                'price_amount' => 2000, 'price_currency' => 'USD',
            ], true);
        }
    });

    $admin     = seed_account($A, 'usr.admin',     'SCHOOL_ADMIN'); // niveau 90
    $direction = seed_account($A, 'usr.direction', 'DIRECTION');    // niveau 80
    $secret    = seed_account($A, 'usr.secret',    'SECRETARIAT');  // niveau 60
    $foreign   = seed_account($B, 'usr.foreign',   'SCHOOL_ADMIN');
    $users     = [$admin, $direction, $secret, $foreign];

    // =================================================================
    echo "\n  LA HIÉRARCHIE DES RÔLES — CE QUE `roles.level` PROMETTAIT\n";

    act_as($direction, $A);

    check('Le niveau de l\'acteur se lit depuis ses rôles',
        users_repo_actor_level() === 80, (string) users_repo_actor_level());

    $assignable = array_map(
        static fn (array $r): string => (string) $r['code'],
        users_repo_assignable_roles()
    );

    check('Une direction NE PEUT PAS attribuer SCHOOL_ADMIN',
        !in_array('SCHOOL_ADMIN', $assignable, true));

    check('Ni son PROPRE rôle', !in_array('DIRECTION', $assignable, true));

    check('Mais bien un rôle inférieur', in_array('SECRETARIAT', $assignable, true));

    $adminRole = (int) platform_scope_cli(static fn (): int => (int) db_value(
        "SELECT id FROM roles WHERE code='SCHOOL_ADMIN' AND school_id IS NULL", [], true));

    check('Le service refuse l\'attribution, pas seulement la vue',
        users_role_refusal($adminRole) !== null);

    check('Et le refus nomme les deux niveaux',
        str_contains((string) users_role_refusal($adminRole), '80')
        && str_contains((string) users_role_refusal($adminRole), '90'));

    // LE CONTOURNEMENT EN DEUX TEMPS.
    check('Une direction ne peut pas AGIR sur un administrateur d\'école',
        users_target_refusal(users_repo_find($admin) ?? []) !== null);

    check('Régénérer SON mot de passe est donc refusé',
        !users_service_reset_password($admin)['ok']);

    check('Le désactiver aussi',
        !users_service_set_status($admin, 'inactive')['ok']);

    check('Mais agir sur un compte inférieur passe',
        users_target_refusal(users_repo_find($secret) ?? []) === null);

    // AUDIT 7D — LE REFUS DOIT DIRE OÙ ALLER.
    // Deux administrateurs d'école ne peuvent rien l'un sur l'autre.
    // Si l'un part sans passer la main, l'école est sans recours si
    // le message ne nomme pas celui qui en a un.
    check('Le refus nomme le recours : l\'éditeur',
        str_contains((string) users_target_refusal(users_repo_find($admin) ?? []), 'éditeur'));

    // AUDIT 7D — LE NIVEAU RETENU EST LE PLUS HAUT DES RÔLES PORTÉS.
    $mixte = seed_account($A, 'usr.mixte', 'SECRETARIAT');
    $users[] = $mixte;

    platform_scope_cli(static fn () => db_query(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :u, id FROM roles WHERE code = \'SCHOOL_ADMIN\' AND school_id IS NULL',
        ['u' => $mixte], true
    ));

    check('Un compte qui CUMULE un rôle bas et un rôle haut reste hors de portée',
        users_target_refusal(users_repo_find($mixte) ?? []) !== null);

    // AUDIT 7D — UN COMPTE SANS AUCUN RÔLE RESTE ATTEIGNABLE.
    // Le rendre intouchable le rendrait irrecuperable : plus personne
    // ne pourrait lui attribuer un role ni l'archiver.
    $nu = platform_scope_cli(static fn (): int => db_insert('users', [
        'uuid' => str_uuid(), 'school_id' => $A, 'username' => 'usr.nu',
        'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name' => 'NU', 'first_name' => 'Sans role', 'status' => 'active',
    ], true));
    $users[] = $nu;

    check('Un compte SANS rôle reste atteignable — sinon il serait irrécupérable',
        users_target_refusal(users_repo_find($nu) ?? []) === null);

    // CES DEUX COMPTES SORTENT DU PEUPLEMENT TOUT DE SUITE.
    // `usr.mixte` porte SCHOOL_ADMIN, donc `user.create` : le laisser
    // vivre fausserait le décompte des « dernières portes » plus bas.
    // Une sonde qui modifie le terrain qu'elle mesure ne mesure plus rien.
    platform_scope_cli(static function () use ($mixte, $nu): void {
        foreach ([$mixte, $nu] as $id) {
            db_query('DELETE FROM user_roles WHERE user_id = :u', ['u' => $id], true);
            db_query('DELETE FROM users WHERE id = :id', ['id' => $id], true);
        }
    });

    // =================================================================
    echo "\n  LES RÔLES DU PORTAIL NE S'ATTRIBUENT PAS ICI\n";

    act_as($admin, $A);

    foreach (['PARENT', 'ELEVE'] as $code) {
        $roleId = (int) platform_scope_cli(static fn (): int => (int) db_value(
            'SELECT id FROM roles WHERE code = :c AND school_id IS NULL', ['c' => $code], true));

        check("Le rôle {$code} est refusé", users_role_refusal($roleId) !== null);
    }

    check('Et il n\'apparaît pas dans la liste attribuable',
        !in_array('PARENT', array_map(
            static fn (array $r): string => (string) $r['code'],
            users_repo_assignable_roles()
        ), true));

    // =================================================================
    echo "\n  CRÉATION D'UN COMPTE\n";

    $secretRole = (int) platform_scope_cli(static fn (): int => (int) db_value(
        "SELECT id FROM roles WHERE code='SECRETARIAT' AND school_id IS NULL", [], true));

    $created = users_service_create([
        'last_name' => 'MUKENDI', 'first_name' => 'Jeanne',
        'roles' => [$secretRole],
    ]);

    check('Un compte se crée', $created['ok'], $created['message']);

    if ($created['ok']) {
        $made[] = (int) $created['id'];
    }

    check('L\'identifiant est construit sur le nom',
        ($created['username'] ?? '') === 'jeanne.mukendi', (string) ($created['username'] ?? ''));

    check('Un mot de passe est rendu, une seule fois',
        strlen((string) ($created['password'] ?? '')) === 12);

    check('Il n\'est PAS journalisé',
        !platform_scope_cli(static fn (): bool => db_exists(
            'SELECT 1 FROM audit_logs WHERE action = :a AND new_values LIKE :p',
            ['a' => 'user.create', 'p' => '%' . ($created['password'] ?? 'zzz') . '%'],
            true
        )));

    check('Le compte doit changer son mot de passe',
        (int) users_repo_find((int) $created['id'])['must_change_password'] === 1);

    // L'HOMONYME.
    $twin = users_service_create([
        'last_name' => 'MUKENDI', 'first_name' => 'Jeanne', 'roles' => [$secretRole],
    ]);

    if ($twin['ok']) {
        $made[] = (int) $twin['id'];
    }

    check('Un homonyme reçoit un identifiant suffixé',
        ($twin['username'] ?? '') === 'jeanne.mukendi.2', (string) ($twin['username'] ?? ''));

    check('Un compte SANS rôle est refusé',
        !users_service_create([
            'last_name' => 'SANS', 'first_name' => 'Role', 'roles' => [],
        ])['ok']);

    check('Un compte sans nom est refusé',
        !users_service_create([
            'last_name' => '', 'first_name' => 'Rien', 'roles' => [$secretRole],
        ])['ok']);

    // =================================================================
    echo "\n  LES COMPTES QUI N'EXISTENT PAS POUR CE MODULE\n";

    check('Un compte d\'une AUTRE école est introuvable',
        users_repo_find($foreign) === null);

    check('Et toutes les écritures dessus sont refusées',
        !users_service_reset_password($foreign)['ok']
        && !users_service_set_status($foreign, 'inactive')['ok']
        && !users_service_update($foreign, ['last_name' => 'X', 'first_name' => 'Y'])['ok']
        && !users_service_archive($foreign, true, 'tentative')['ok']);

    // Un compte de PLATEFORME.
    $platformUser = platform_scope_cli(static fn (): int => db_insert('users', [
        'uuid' => str_uuid(), 'school_id' => null, 'username' => 'usr.editeur',
        'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name' => 'EDITEUR', 'first_name' => 'Test', 'status' => 'active',
    ], true));
    $users[] = $platformUser;

    check('Un compte de PLATEFORME est invisible depuis une école',
        users_repo_find($platformUser) === null);

    check('Et intouchable',
        !users_service_reset_password($platformUser)['ok']);

    // Un compte de FAMILLE.
    $student = platform_scope_cli(static function () use ($A): array {
        $u = db_insert('users', [
            'uuid' => str_uuid(), 'school_id' => $A, 'username' => 'usr.eleve',
            'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
            'last_name' => 'ELEVE', 'first_name' => 'Test', 'status' => 'active',
        ], true);

        $s = db_insert('students', [
            'uuid' => str_uuid(), 'school_id' => $A, 'user_id' => $u,
            'matricule' => 'USR-TEST-1', 'last_name' => 'ELEVE', 'first_name' => 'Test',
            'birth_date' => '2012-01-01', 'gender' => 'M', 'status' => 'active',
        ], true);

        return ['user' => $u, 'student' => $s];
    });
    $users[] = $student['user'];

    check('Un compte de FAMILLE est invisible dans la liste du personnel',
        users_repo_find($student['user']) === null);

    check('Sa réinitialisation renvoie vers le portail',
        str_contains(
            users_service_reset_password($student['user'])['message'],
            'tuteur'
        ));

    // =================================================================
    echo "\n  LA LIMITE D'ABONNEMENT FERME LA CRÉATION, ET RIEN D'AUTRE\n";

    // On rabat l'offre sur un plafond déjà dépassé.
    platform_scope_cli(static fn () => db_query(
        'UPDATE subscriptions SET plan_id = (SELECT id FROM plans WHERE code = \'DECOUVERTE\')
          WHERE school_id = :s', ['s' => $A], true
    ));
    platform_scope_cli(static fn () => db_query(
        'UPDATE plans SET max_users = 1 WHERE code = \'DECOUVERTE\'', [], true
    ));

    perm_all(true);

    $blocked = users_service_create([
        'last_name' => 'TROP', 'first_name' => 'Tard', 'roles' => [$secretRole],
    ]);

    check('La création est REFUSÉE au plafond', !$blocked['ok'], $blocked['message']);

    check('Et le message nomme le plafond',
        str_contains($blocked['message'], '1'));

    check('Mais la LECTURE reste ouverte',
        users_repo_search([], 1)['total'] > 0);

    check('Et la modification aussi',
        users_service_update((int) $created['id'], [
            'last_name' => 'MUKENDI', 'first_name' => 'Jeanne', 'phone' => '0999000111',
        ])['ok']);

    check('La DÉSACTIVATION reste possible — c\'est elle qui libère une place',
        users_service_set_status((int) $twin['id'], 'inactive')['ok']);

    platform_scope_cli(static fn () => db_query(
        'UPDATE plans SET max_users = 50 WHERE code = \'DECOUVERTE\'', [], true
    ));

    // =================================================================
    echo "\n  ON NE S'ENFERME PAS DEHORS\n";

    act_as($admin, $A);

    check('On ne se désactive pas soi-même',
        !users_service_set_status($admin, 'inactive')['ok']);

    check('On ne s\'archive pas soi-même',
        !users_service_archive($admin, true, 'tentative')['ok']);

    check('On ne modifie pas ses propres rôles',
        !users_service_set_roles($admin, [$secretRole])['ok']);

    // Le dernier administrateur : on retire d'abord la direction.
    check('Le compte direction se désactive tant qu\'un autre administre',
        users_service_set_status($direction, 'inactive')['ok']);

    check('Il ne reste plus qu\'un compte capable de créer',
        users_repo_count_with_permission('user.create') === 1,
        (string) users_repo_count_with_permission('user.create'));

    // Ce dernier ne peut plus être désactivé, MÊME par l'éditeur.
    //
    // Un compte de plateforme n'a pas d'école : il en OUVRE une, et
    // `auth_user()` relit ce contexte en base à chaque requête (7B1).
    // Le poser avec `tenant_set()` ne tiendrait pas une requête.
    platform_scope_cli(static function () use ($platformUser, $A): void {
        db_query(
            'INSERT INTO user_roles (user_id, role_id)
             SELECT :u, id FROM roles WHERE code = \'SUPER_ADMIN\' AND school_id IS NULL',
            ['u' => $platformUser], true
        );
        db_query('UPDATE users SET visiting_school_id = :s WHERE id = :u',
            ['s' => $A, 'u' => $platformUser], true);
    });

    act_as($platformUser, null);

    check('L\'éditeur qui ouvre une école en prend le contexte',
        tenant_id() === $A, var_export(tenant_id(), true));

    $lastDoor = users_service_set_status($admin, 'inactive');

    check('Le DERNIER compte capable de créer ne se désactive pas', !$lastDoor['ok'],
        $lastDoor['message']);

    check('Et le message explique pourquoi',
        str_contains($lastDoor['message'], 'dehors'));

    check('L\'archiver est refusé pour la même raison',
        !users_service_archive($admin, true, 'tentative')['ok']);

    check('Rouvrir un autre accès lève le verrou',
        users_service_set_status($direction, 'active')['ok']
        && users_service_set_status($admin, 'inactive')['ok']);

    users_service_set_status($admin, 'active');

    // AUDIT 7D — LE CONTRÔLE DE LA DERNIÈRE PORTE EST SOUS VERROU.
    //
    // C'était une lecture suivie d'une écriture : deux désactivations
    // simultanées passaient toutes les deux et fermaient l'école.
    // Mesuré à deux processus (voir la recette). Ici on vérifie que la
    // fonction verrouillante rend le même décompte que la lecture
    // simple — l'égalité des deux est ce qui rend le remplacement sûr.
    $libre   = users_repo_count_with_permission('user.create', 0);
    $verrou  = db_transaction(static fn (): int =>
        users_repo_lock_and_count_with_permission('user.create', 0));

    check('La version verrouillée compte comme la version libre',
        $libre === $verrou, "{$libre} vs {$verrou}");

    check('Et elle sait exclure une cible',
        db_transaction(static fn (): int =>
            users_repo_lock_and_count_with_permission('user.create', $admin)) === $libre - 1);


    // =================================================================
    echo "\n  UN COMPTE NE SE SUPPRIME JAMAIS\n";

    act_as($admin, $A);

    $before = (int) platform_scope_cli(static fn (): int => (int) db_value(
        'SELECT COUNT(*) FROM users WHERE school_id = :s', ['s' => $A], true));

    check('L\'archivage exige un motif',
        !users_service_archive((int) $created['id'], true, '')['ok']);

    check('Avec motif, il passe',
        users_service_archive((int) $created['id'], true, 'a quitté en juin')['ok']);

    check('La ligne EXISTE toujours',
        (int) platform_scope_cli(static fn (): int => (int) db_value(
            'SELECT COUNT(*) FROM users WHERE school_id = :s', ['s' => $A], true)) === $before);

    check('Le compte sort de la liste par défaut',
        !in_array((int) $created['id'], array_map(
            static fn (array $r): int => (int) $r['id'],
            users_repo_search([], 1)['rows']
        ), true));

    check('Mais il reparaît sur demande',
        in_array((int) $created['id'], array_map(
            static fn (array $r): int => (int) $r['id'],
            users_repo_search(['archived' => '1'], 1)['rows']
        ), true));

    check('Il se rappelle', users_service_archive((int) $created['id'], false)['ok']);

    check('Et il est réactivé',
        users_repo_find((int) $created['id'])['status'] === 'active');

    // =================================================================
    echo "\n  SUSPENSION, VERROU, MOT DE PASSE\n";

    check('Une suspension sans motif est refusée',
        !users_service_set_status((int) $created['id'], 'suspended', '')['ok']);

    check('Avec motif, elle passe',
        users_service_set_status((int) $created['id'], 'suspended', 'enquête interne')['ok']);

    $oldHash = users_repo_find((int) $created['id'])['password_hash'];

    $reset = users_service_reset_password((int) $created['id']);

    check('Le mot de passe se régénère', $reset['ok']);

    check('Et le condensat change',
        users_repo_find((int) $created['id'])['password_hash'] !== $oldHash);

    check('Le compte devra le changer',
        (int) users_repo_find((int) $created['id'])['must_change_password'] === 1);

    platform_scope_cli(static fn () => db_query(
        'UPDATE users SET failed_attempts = 5, locked_until = DATE_ADD(NOW(), INTERVAL 1 HOUR)
          WHERE id = :id', ['id' => $created['id']], true
    ));

    check('Un compte verrouillé se déverrouille',
        users_service_unlock((int) $created['id'])['ok']);

    check('Et le verrou est bien levé',
        users_repo_find((int) $created['id'])['locked_until'] === null);

    users_service_set_status((int) $created['id'], 'active');

    // =================================================================
    echo "\n  LES RÔLES SE REMPLACENT, PAS NE S'EMPILENT\n";

    $prefetRole = (int) platform_scope_cli(static fn (): int => (int) db_value(
        "SELECT id FROM roles WHERE code='PREFET' AND school_id IS NULL", [], true));

    check('Les rôles se remplacent',
        users_service_set_roles((int) $created['id'], [$prefetRole])['ok']);

    check('Et l\'ancien a disparu',
        array_map(
            static fn (array $r): string => (string) $r['code'],
            users_repo_find((int) $created['id'])['roles']
        ) === ['PREFET']);

    check('Un compte ne peut pas se retrouver sans rôle',
        !users_service_set_roles((int) $created['id'], [])['ok']);

    check('Le changement est tracé',
        platform_scope_cli(static fn (): bool => db_exists(
            'SELECT 1 FROM audit_logs WHERE action = :a AND entity_id = :id',
            ['a' => 'user.set_roles', 'id' => $created['id']], true
        )));

    // ON NE RETIRE PAS UN RÔLE QU'ON NE POURRAIT PAS REDONNER.
    //
    // Un rôle désactivé après coup sort de la liste attribuable. Le
    // formulaire ne le montre plus ; le soumettre ne doit pas
    // l'effacer, sinon l'opération est à sens unique.
    $customRole = platform_scope_cli(static fn (): int => db_insert('roles', [
        'school_id' => $A, 'code' => 'USR_CUSTOM', 'name' => 'Rôle maison',
        'level' => 30, 'is_system' => 0, 'is_active' => 1,
    ], true));

    users_service_set_roles((int) $created['id'], [$prefetRole, $customRole]);

    platform_scope_cli(static fn () => db_query(
        'UPDATE roles SET is_active = 0 WHERE id = :id', ['id' => $customRole], true
    ));

    check('Un rôle désactivé n\'est plus attribuable',
        users_role_refusal($customRole) !== null);

    $stripped = users_service_set_roles((int) $created['id'], [$prefetRole]);

    check('Le soumettre sans lui NE L\'EFFACE PAS', $stripped['ok'], $stripped['message']);

    check('Il est conservé',
        in_array('USR_CUSTOM', array_map(
            static fn (array $r): string => (string) $r['code'],
            users_repo_find((int) $created['id'])['roles']
        ), true));

    check('Et le message le dit',
        str_contains($stripped['message'], 'conservés'));

    platform_scope_cli(static fn () => db_query(
        'DELETE FROM user_roles WHERE role_id = :id', ['id' => $customRole], true));
    platform_scope_cli(static fn () => db_query(
        'DELETE FROM roles WHERE id = :id', ['id' => $customRole], true));

    // =================================================================
    echo "\n  L'ISOLATION TIENT SUR CHAQUE ÉCRITURE\n";

    act_as($foreign, $B);

    check('L\'école B ne voit aucun compte de l\'école A',
        array_filter(
            users_repo_search([], 1)['rows'],
            static fn (array $r): bool => (int) $r['id'] === (int) $created['id']
        ) === []);

    check('Et ne peut pas le trouver', users_repo_find((int) $created['id']) === null);

    check('Ni écrire dessus',
        !users_service_update((int) $created['id'], [
            'last_name' => 'PIRATE', 'first_name' => 'X',
        ])['ok']);

    act_as($admin, $A);

    check('Vérification côté A : le nom est intact',
        users_repo_find((int) $created['id'])['last_name'] === 'MUKENDI');

    // =================================================================
    echo "\n  TOUT EST TRACÉ\n";

    foreach (['user.create', 'user.set_status', 'user.archive', 'user.reset_password'] as $action) {
        check("Une trace pour {$action}",
            platform_scope_cli(static fn (): bool => db_exists(
                'SELECT 1 FROM audit_logs WHERE action = :a', ['a' => $action], true)));
    }

} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    platform_scope_cli(static function () use ($schools, $users, $made): void {
        foreach ($schools as $id) {
            db_query('DELETE FROM students WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM subscriptions WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM audit_logs WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM user_roles WHERE user_id IN
                      (SELECT id FROM users WHERE school_id = :s)', ['s' => $id], true);
            db_query('UPDATE users SET created_by = NULL WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM users WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM schools WHERE id = :id', ['id' => $id], true);
        }

        foreach (array_merge($users, $made) as $u) {
            db_query('DELETE FROM user_roles WHERE user_id = :u', ['u' => $u], true);
            db_query('UPDATE users SET created_by = NULL WHERE created_by = :u', ['u' => $u], true);
            db_query('DELETE FROM users WHERE id = :id', ['id' => $u], true);
        }

        db_query('DELETE FROM audit_logs WHERE action LIKE :a AND school_id IS NULL',
            ['a' => 'user.%'], true);

        db_query('UPDATE plans SET max_users = 50 WHERE code = \'DECOUVERTE\'', [], true);
    });
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
