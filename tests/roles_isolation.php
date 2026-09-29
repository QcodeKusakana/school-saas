<?php
/**
 * Phase 11B — les rôles et permissions par école.
 *
 * CE QUE CES TESTS PROTÈGENT
 * --------------------------
 *  · un rôle composé par une école lui appartient, et aucune autre ne
 *    le voit, ne le modifie ni ne le désactive ;
 *  · les cinq règles du module tiennent : niveau strictement inférieur,
 *    on n'accorde que ce qu'on détient, jamais une permission de
 *    plateforme, jamais un rôle système, `school_id` posé par le
 *    service ;
 *  · les COMPTEURS sont ceux de l'école — un défaut constaté par sonde :
 *    une école lisait le nombre de porteurs de TOUTES les écoles, et le
 *    garde multi-école ne le voyait pas parce que la requête liait
 *    `school_id` ailleurs ;
 *  · un rôle composé est réellement ATTRIBUABLE et ouvre ce qu'il
 *    annonce, RIEN de plus ;
 *  · retirer une permission à un rôle porté exige un motif, comme le
 *    désactiver — deux gestes au même dégât ne peuvent pas avoir deux
 *    exigences.
 *
 * NETTOYAGE : ce fichier ne compte PAS sur `finally`. Le garde
 * multi-école et `abort()` terminent le processus par `exit()`, qui le
 * saute (leçon de la phase 9D). Le décor est donc retiré par
 * `tests/_nettoyage_roles.php`, exécuté à la fin ET appelable seul.
 *
 * Usage : php tests/roles_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/platform/services.php';
require APP_PATH . '/modules/roles/services.php';
require_once APP_PATH . '/modules/users/services.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

/** Se glisser dans la peau d'un compte, école comprise. */
function devenir(int $userId, ?int $ecole = null): void
{
    session_unset();
    $_SESSION['user_id'] = $userId;

    if ($ecole !== null) {
        $_SESSION['school_id'] = $ecole;
        tenant_set($ecole);
    }

    auth_user(true);
    perm_all(true);
    perm_roles(true);
}

echo "\n  PHASE 11B — RÔLES ET PERMISSIONS PAR ÉCOLE\n";
echo "  ══════════════════════════════════════════════════════════\n";

// ---------------------------------------------------------------------
//  Le décor : deux écoles nées du chemin réel de la phase 11A.
// ---------------------------------------------------------------------
// La base de démonstration n'a qu'une école, et son seul compte
// d'encadrement est un DIRECTION — qui ne détient pas `role.manage`.
// Éprouver l'isolation sur elle mesurerait un trousseau, pas le produit.
$editeur = (int) platform_scope_cli(static fn (): int => (int) db_value(
    "SELECT u.id FROM users u
       JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id AND r.code = 'SUPER_ADMIN'
      WHERE u.school_id IS NULL AND u.deleted_at IS NULL
      LIMIT 1",
    [],
    true
));

check('Un compte d\'éditeur existe', $editeur > 0);

devenir($editeur);

$fabriquer = static function (string $etiquette): array {
    $suffixe = bin2hex(random_bytes(3));

    $r = platform_service_create_school([
        'name'             => 'Recette 11B ' . $etiquette . ' ' . $suffixe,
        'school_type'      => 'prive',
        'city'             => 'Likasi',
        'cycles'           => ['PRIMAIRE'],
        'admin_last_name'  => 'MUKENDI',
        'admin_first_name' => 'Espoir',
        'admin_username'   => 'recette11b.' . $etiquette . '.' . $suffixe,
    ]);

    if (!$r['ok']) {
        throw new RuntimeException('école ' . $etiquette . ' : ' . $r['message']);
    }

    return [
        'id'    => (int) $r['school_id'],
        'admin' => (int) platform_scope_cli(static fn (): int => (int) db_value(
            'SELECT id FROM users WHERE username = :u',
            ['u' => (string) $r['username']],
            true
        )),
    ];
};

$A = $fabriquer('a');
$B = $fabriquer('b');

check('Deux écoles de recette sont nées', $A['id'] > 0 && $B['id'] > 0 && $A['id'] !== $B['id'],
    'écoles ' . $A['id'] . ' et ' . $B['id']);

// ---------------------------------------------------------------------
//  1. COMPOSER UN RÔLE
// ---------------------------------------------------------------------
echo "\n  Composer un rôle\n";

devenir($A['admin'], $A['id']);

$niveauA = roles_repo_actor_level();
check('L\'administrateur d\'école a un niveau exploitable', $niveauA > 1, 'niveau ' . $niveauA);

$catalogue = roles_repo_grantable_permissions();
$parCode   = [];

foreach ($catalogue as $p) {
    $parCode[(string) $p['code']] = (int) $p['id'];
}

$platformes = (int) db_value('SELECT COUNT(*) FROM permissions WHERE is_platform = 1', [], true);
$ecoles     = (int) db_value('SELECT COUNT(*) FROM permissions WHERE is_platform = 0', [], true);

check('Le catalogue accordable existe', $catalogue !== [], count($catalogue) . ' permission(s)');
check('Il exclut TOUTES les permissions de plateforme',
    $platformes > 0 && count($catalogue) === $ecoles,
    $platformes . ' exclue(s) sur ' . ($platformes + $ecoles));

$r = roles_service_create([
    'name'        => 'Surveillant de recette',
    'description' => 'Relève les présences et consulte les élèves.',
    'level'       => 30,
    'permissions' => [$parCode['student.view'], $parCode['attendance.view']],
]);

check('Un rôle d\'école est créé', $r['ok'], (string) $r['message']);

if (!$r['ok']) {
    require __DIR__ . '/_nettoyage_roles.php';
    printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);
    exit(1);
}

$roleA  = (int) $r['role_id'];
$ligneA = platform_scope_cli(static fn () => db_one('SELECT * FROM roles WHERE id = :i', ['i' => $roleA], true));

check('Il porte le school_id de son école', (int) $ligneA['school_id'] === $A['id']);
check('Il n\'est pas système', (int) $ligneA['is_system'] === 0);
check('Il est actif à la naissance', (int) $ligneA['is_active'] === 1);
check('Son code est dérivé et préfixé par l\'école, jamais saisi',
    str_starts_with((string) $ligneA['code'], 'E' . $A['id'] . '_'), (string) $ligneA['code']);
check('Le code reste dans les 40 caractères de la colonne',
    mb_strlen((string) $ligneA['code']) <= 40);
check('Ses permissions sont enregistrées',
    count(roles_repo_permission_ids($roleA)) === 2);

// Un homonyme ne casse rien : le code se décale.
$r2 = roles_service_create([
    'name' => 'Surveillant de recette', 'level' => 25,
    'permissions' => [$parCode['student.view']],
]);

check('Un rôle homonyme est accepté', $r2['ok'], (string) $r2['message']);

if ($r2['ok']) {
    $code2 = (string) platform_scope_cli(static fn () => db_value(
        'SELECT code FROM roles WHERE id = :i', ['i' => (int) $r2['role_id']], true));

    check('… avec un code distinct', $code2 !== (string) $ligneA['code'], $code2);
}

// ---------------------------------------------------------------------
//  2. LES CINQ RÈGLES
// ---------------------------------------------------------------------
echo "\n  Les cinq règles\n";

$r = roles_service_create(['name' => 'Trop haut', 'level' => $niveauA,
    'permissions' => [$parCode['student.view']]]);
check('RÈGLE 5 — un niveau ÉGAL au sien est refusé', !$r['ok']);

$r = roles_service_create(['name' => 'Bien trop haut', 'level' => $niveauA + 10,
    'permissions' => [$parCode['student.view']]]);
check('RÈGLE 5 — un niveau SUPÉRIEUR au sien est refusé', !$r['ok']);

$r = roles_service_create(['name' => 'Niveau zéro', 'level' => 0,
    'permissions' => [$parCode['student.view']]]);
check('Un niveau nul est refusé', !$r['ok']);

$r = roles_service_create(['name' => 'Sans niveau', 'level' => 'quinze',
    'permissions' => [$parCode['student.view']]]);
check('Un niveau non numérique est refusé', !$r['ok']);

$r = roles_service_create(['name' => 'Ab', 'level' => 20,
    'permissions' => [$parCode['student.view']]]);
check('Un nom de moins de 3 caractères est refusé', !$r['ok']);

$r = roles_service_create(['name' => 'Rôle vide', 'level' => 20, 'permissions' => []]);
check('Un rôle SANS aucune permission est refusé', !$r['ok'], (string) $r['message']);

$idPlateforme = (int) db_value('SELECT id FROM permissions WHERE is_platform = 1 ORDER BY id LIMIT 1', [], true);

$r = roles_service_create(['name' => 'Rôle de plateforme', 'level' => 20,
    'permissions' => [$idPlateforme]]);
check('RÈGLE 4 — une permission de PLATEFORME est refusée', !$r['ok']);

$r = roles_service_create(['name' => 'Permission inexistante', 'level' => 20,
    'permissions' => [999999]]);
check('Une permission inexistante est refusée', !$r['ok']);

$systeme = (int) db_value("SELECT id FROM roles WHERE code = 'SECRETARIAT' AND school_id IS NULL", [], true);

check('Le rôle système SECRETARIAT est visible par l\'école',
    roles_repo_find($systeme) !== null);

$r = roles_service_update($systeme, ['name' => 'Détourné', 'level' => 20,
    'permissions' => [$parCode['student.view']]]);
check('RÈGLE 2 — un rôle SYSTÈME ne se modifie pas', !$r['ok']);

$r = roles_service_toggle($systeme, false, 'motif suffisamment long pour passer');
check('RÈGLE 2 — un rôle SYSTÈME ne se désactive pas', !$r['ok']);

$r = roles_service_update($roleA, ['name' => 'Escalade', 'level' => $niveauA,
    'permissions' => [$parCode['student.view']]]);
check('RÈGLE 5 — on ne fait pas MONTER un rôle à son propre niveau', !$r['ok']);

// RÈGLE 3 — on n'accorde que ce qu'on détient. L'administrateur d'école
// détient tout le catalogue : la règle s'éprouve depuis un compte plus
// modeste, celui qu'on vient de créer.
$modeste = users_service_create([
    'last_name' => 'KABILA', 'first_name' => 'Grâce',
    'username'  => 'recette11b.modeste.' . bin2hex(random_bytes(3)),
    'roles'     => [$roleA],
]);

check('Un compte porteur du nouveau rôle est créé', (bool) ($modeste['ok'] ?? false),
    (string) ($modeste['message'] ?? ''));

// ---------------------------------------------------------------------
//  3. LE RÔLE OUVRE-T-IL CE QU'IL ANNONCE ?
// ---------------------------------------------------------------------
echo "\n  Ce que le rôle ouvre\n";

if ($modeste['ok'] ?? false) {
    $porteur = (int) $modeste['id'];

    devenir($porteur, $A['id']);

    $siennes = perm_all();

    check('Le porteur reçoit ce que le rôle accorde',
        in_array('student.view', $siennes, true) && in_array('attendance.view', $siennes, true));
    check('… et RIEN de plus', count($siennes) === 2, implode(', ', $siennes));
    check('Il ne peut pas composer de rôles', !can('role.manage'));

    $r = roles_service_create(['name' => 'Auto-promotion', 'level' => 10,
        'permissions' => [$parCode['student.view']]]);
    check('Un porteur sans role.manage ne compose RIEN', !$r['ok'], (string) $r['message']);

    devenir($A['admin'], $A['id']);
}

// ---------------------------------------------------------------------
//  4. L'ISOLATION ENTRE ÉCOLES
// ---------------------------------------------------------------------
echo "\n  L'isolation entre écoles\n";

devenir($B['admin'], $B['id']);

$vusParB = array_map('intval', array_column(roles_repo_all(), 'id'));

check('L\'école B ne voit pas le rôle de l\'école A', !in_array($roleA, $vusParB, true));
check('L\'école B voit tout de même les rôles système', in_array($systeme, $vusParB, true));
check('L\'école B ne trouve pas le rôle de A', roles_repo_find($roleA) === null);

$r = roles_service_update($roleA, ['name' => 'Capturé', 'level' => 10,
    'permissions' => [$parCode['student.view']]]);
check('L\'école B ne peut pas le modifier', !$r['ok']);

$r = roles_service_toggle($roleA, false, 'tentative depuis une autre école');
check('L\'école B ne peut pas le désactiver', !$r['ok']);

check('L\'école B ne compte pas ses porteurs', roles_repo_holders($roleA) === 0);

// LE COMPTEUR — le défaut trouvé par sonde.
$compteurB = null;

foreach (roles_repo_all() as $ligne) {
    if ((string) $ligne['code'] === 'SCHOOL_ADMIN') {
        $compteurB = (int) $ligne['comptes'];
    }
}

check('Le compteur de SCHOOL_ADMIN vu par B ne compte QUE B',
    $compteurB === 1, ($compteurB ?? -1) . ' compte(s), 1 attendu');

// ---------------------------------------------------------------------
//  5. L'ÉDITEUR EN VISITE — la règle 4 vaut aussi pour lui
// ---------------------------------------------------------------------
echo "\n  L'éditeur en visite\n";

devenir($editeur, $A['id']);

check('L\'éditeur détient bien une permission de plateforme',
    perm_has('platform.school.view'));

$r = roles_service_create(['name' => 'Laissé par l\'éditeur', 'level' => 50,
    'permissions' => [$idPlateforme, $parCode['student.view']]]);
check('… et ne peut PAS la laisser dans un rôle d\'école', !$r['ok'], (string) $r['message']);

check('Le catalogue reste identique pour lui', count(roles_repo_grantable_permissions()) === $ecoles);

// ---------------------------------------------------------------------
//  6. RETIRER DES ACCÈS
// ---------------------------------------------------------------------
echo "\n  Retirer des accès\n";

devenir($A['admin'], $A['id']);

$porteurs = roles_repo_holders($roleA);
check('Le produit compte les porteurs de SON école', $porteurs === 1, $porteurs . ' compte(s)');

$r = roles_service_update($roleA, ['name' => 'Surveillant de recette', 'level' => 30,
    'permissions' => [$parCode['student.view']]]);
check('Un RETRAIT sans motif sur un rôle porté est refusé', !$r['ok'], (string) $r['message']);

$r = roles_service_update($roleA, ['name' => 'Surveillant de recette', 'level' => 30,
    'permissions' => [$parCode['student.view'], $parCode['attendance.view'],
                      $parCode['classroom.view'] ?? $parCode['student.view']]]);
check('Un AJOUT sans motif passe', $r['ok'], (string) $r['message']);

$r = roles_service_update($roleA, ['name' => 'Surveillant de recette', 'level' => 30,
    'permissions' => [$parCode['student.view']],
    'reason'      => 'Le surveillant ne releve plus les presences']);
check('… et le retrait passe avec un motif', $r['ok'], (string) $r['message']);

check('Le motif est au journal',
    (int) platform_scope_cli(static fn (): int => (int) db_value(
        "SELECT COUNT(*) FROM audit_logs
          WHERE action = 'role.update' AND entity_id = :i
            AND new_values LIKE '%releve plus les presences%'",
        ['i' => $roleA],
        true
    )) === 1);

if ($modeste['ok'] ?? false) {
    devenir((int) $modeste['id'], $A['id']);
    check('Le porteur a bien perdu l\'accès retiré',
        !in_array('attendance.view', perm_all(), true), implode(', ', perm_all()));
    devenir($A['admin'], $A['id']);
}

// ---------------------------------------------------------------------
//  7. DÉSACTIVER
// ---------------------------------------------------------------------
echo "\n  Désactiver\n";

$r = roles_service_toggle($roleA, false, 'court');
check('Désactiver un rôle PORTÉ sans motif est refusé', !$r['ok']);

$r = roles_service_toggle($roleA, false, 'Reorganisation du secretariat pour la rentree');
check('… et passe avec un motif', $r['ok'], (string) $r['message']);

$r = roles_service_toggle($roleA, false, 'Reorganisation du secretariat pour la rentree');
check('Désactiver deux fois est refusé', !$r['ok']);

if ($modeste['ok'] ?? false) {
    devenir((int) $modeste['id'], $A['id']);
    check('Le porteur d\'un rôle désactivé n\'a plus rien', perm_all() === []);
    devenir($A['admin'], $A['id']);
}

// UN RÔLE DÉSACTIVÉ N'EST PLUS ATTRIBUABLE — la phase 7D le refuse déjà.
$refus = users_role_refusal($roleA);
check('Un rôle désactivé n\'est plus attribuable', $refus !== null, (string) $refus);

$r = roles_service_toggle($roleA, true);
check('Réactiver ne demande pas de motif', $r['ok'], (string) $r['message']);

if ($modeste['ok'] ?? false) {
    devenir((int) $modeste['id'], $A['id']);
    check('Le porteur retrouve ses accès', perm_all() !== []);
    devenir($A['admin'], $A['id']);
}

// ---------------------------------------------------------------------
//  8. L'EFFACEMENT D'UNE ÉCOLE EMPORTE SES RÔLES
// ---------------------------------------------------------------------
// La phase 10C a été écrite quand aucun rôle n'avait de `school_id`.
// Rien ne garantissait que ses rôles propres partent avec elle.
echo "\n  Ce qu'emporte l'effacement d'une école\n";

$rolesDeA = (int) platform_scope_cli(static fn (): int => (int) db_value(
    'SELECT COUNT(*) FROM roles WHERE school_id = :s', ['s' => $A['id']], true));

check('L\'école A possède bien des rôles propres', $rolesDeA >= 2, $rolesDeA . ' rôle(s)');

$cascade = (string) db_value(
    "SELECT rc.DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS rc
      WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
        AND rc.TABLE_NAME = 'roles' AND rc.REFERENCED_TABLE_NAME = 'schools'",
    [],
    true
);

check('`roles.school_id` cascade depuis `schools`', $cascade === 'CASCADE', $cascade);

foreach (['role_permissions', 'user_roles'] as $table) {
    $regle = (string) db_value(
        "SELECT rc.DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS rc
          WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
            AND rc.TABLE_NAME = :t AND rc.REFERENCED_TABLE_NAME = 'roles'",
        ['t' => $table],
        true
    );

    check(sprintf('`%s` cascade depuis `roles`', $table), $regle === 'CASCADE', $regle);
}

// ---------------------------------------------------------------------
//  Nettoyage — hors `finally`, volontairement.
// ---------------------------------------------------------------------
require __DIR__ . '/_nettoyage_roles.php';

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
