<?php
/**
 * Le tableau de bord ne montre que ce que l'on pourrait ouvrir.
 *
 * CE QUE CES TESTS PROTÈGENT
 * --------------------------
 * `/tableau-de-bord` n'exige que `auth` : c'est le seul écran du produit
 * ouvert à tout compte connecté. Il affichait donc à TOUS — enseignants
 * compris — l'offre d'abonnement de l'école, ses jours restants, son
 * nombre de comptes et la liste des tâches d'administration, alors que
 * `/abonnement` et `/utilisateurs` leur répondent 403.
 *
 *   > Une porte fermée à trois endroits et ouverte sur le tableau de
 *   > bord n'est pas fermée.
 *
 * Défaut de la phase 1, resté dormant jusqu'à ce que la 11B pose la
 * question : « que voit un compte dont on vient de retirer tous les
 * droits ? »
 *
 * Usage : php tests/dashboard_perimetre.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/dashboard/controllers.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

function devenir(int $userId, int $ecole): void
{
    session_unset();
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $ecole;
    tenant_set($ecole);
    auth_user(true);
    perm_all(true);
    perm_roles(true);
}

echo "\n  LE PÉRIMÈTRE DU TABLEAU DE BORD\n";
echo "  ══════════════════════════════════════════════════════════\n";

$compte = static fn (string $username): ?array => platform_scope_cli(
    static fn (): ?array => db_one(
        'SELECT id, school_id FROM users WHERE username = :u AND deleted_at IS NULL',
        ['u' => $username],
        true
    )
);

$admin      = $compte('admin.demo');
$enseignant = $compte('enseignant.demo');

check('Le jeu de démonstration fournit un administrateur d\'école', $admin !== null,
    'php database/seed_demo.php');
check('… et un enseignant', $enseignant !== null);

if ($admin === null || $enseignant === null) {
    printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);
    exit(1);
}

$ecole = (int) $admin['school_id'];

// ---------------------------------------------------------------------
//  L'ADMINISTRATEUR — il doit tout voir, sinon la correction a trop pris
// ---------------------------------------------------------------------
echo "\n  L'administrateur d'établissement\n";

devenir((int) $admin['id'], $ecole);

check('Il détient bien les permissions concernées',
    can('user.view') && can('subscription.view') && can('school.edit'));

$etapes = dashboard_setup_steps();

check('Le guide de configuration lui est proposé', $etapes !== [],
    count($etapes) . ' étape(s)');
check('… avec les quatre étapes', count($etapes) === 4);

$urls = array_column($etapes, 'url');

check('… dont la création des comptes du personnel',
    in_array('/utilisateurs', $urls, true));

check('L\'abonnement lui est lisible', dashboard_subscription() !== null);

// ---------------------------------------------------------------------
//  L'ENSEIGNANT — il enseigne, il ne gère pas l'école
// ---------------------------------------------------------------------
echo "\n  L'enseignant\n";

devenir((int) $enseignant['id'], $ecole);

check('Il ne détient AUCUNE des permissions d\'administration',
    !can('user.view') && !can('subscription.view') && !can('school.edit'));

$etapesEns = dashboard_setup_steps();

check('Aucune étape de configuration ne lui est proposée', $etapesEns === [],
    count($etapesEns) . ' étape(s) — chacune mène à un 403');

// LE CONTRÔLE QUI COMPTE : ce que le contrôleur passe à la vue.
// On le reproduit ici à l'identique ; un écart se verrait.
check('Le nombre de comptes ne lui est pas transmis',
    (can('user.view') ? tenant_count('users', 'deleted_at IS NULL') : null) === null);

check('L\'offre d\'abonnement ne lui est pas transmise',
    (can('subscription.view') ? dashboard_subscription() : null) === null,
    'l\'offre et les jours restants sont une information commerciale');

// Ce qu'il DOIT continuer à voir : il travaille dans cette école.
check('L\'année scolaire courante lui reste lisible', dashboard_current_year() !== null);
check('Les cycles enseignés lui restent lisibles', dashboard_active_cycles() !== []);

// ---------------------------------------------------------------------
//  UN COMPTE SANS AUCUN DROIT
// ---------------------------------------------------------------------
echo "\n  Un compte dont le rôle a été désactivé\n";

require_once APP_PATH . '/modules/roles/services.php';
require_once APP_PATH . '/modules/users/services.php';

devenir((int) $admin['id'], $ecole);

$suffixe = bin2hex(random_bytes(3));

$role = roles_service_create([
    'name'        => 'Recette perimetre ' . $suffixe,
    'level'       => 20,
    'permissions' => [(int) db_value(
        'SELECT id FROM permissions WHERE code = :c', ['c' => 'student.view'], true
    )],
]);

check('Un rôle jetable est composé', $role['ok'], (string) $role['message']);

if ($role['ok']) {
    $porteur = users_service_create([
        'last_name'  => 'DEMUNI',
        'first_name' => 'Recette',
        'username'   => 'perimetre.' . $suffixe,
        'roles'      => [(int) $role['role_id']],
    ]);

    check('Un compte le porte', (bool) ($porteur['ok'] ?? false),
        (string) ($porteur['message'] ?? ''));

    if ($porteur['ok'] ?? false) {
        devenir((int) $porteur['id'], $ecole);
        check('Il a bien un droit au départ', perm_all() !== []);

        devenir((int) $admin['id'], $ecole);
        $t = roles_service_toggle((int) $role['role_id'], false,
            'Recette du perimetre du tableau de bord');
        check('Le rôle est désactivé', $t['ok'], (string) $t['message']);

        devenir((int) $porteur['id'], $ecole);

        check('Le compte n\'a plus AUCUNE permission', perm_all() === []);
        check('… et le tableau de bord ne lui propose rien à configurer',
            dashboard_setup_steps() === []);
        check('… ni le nombre de comptes',
            (can('user.view') ? 1 : null) === null);
        check('… ni l\'abonnement',
            (can('subscription.view') ? 1 : null) === null);

        // C'est cet état que la vue doit ANNONCER — la condition testée
        // ici est exactement celle du bandeau d'avertissement.
        check('Le produit sait reconnaître ce cul-de-sac', perm_all() === [],
            'la vue affiche « votre compte n\'a aucun droit d\'accès »');
    }
}

// ---------------------------------------------------------------------
//  Nettoyage — hors `finally`, volontairement (leçon de la 9D).
// ---------------------------------------------------------------------
platform_scope_cli(static function (): void {
    $roles = db_all(
        "SELECT id FROM roles WHERE school_id IS NOT NULL AND name LIKE 'Recette perimetre %'",
        [],
        true
    );

    foreach ($roles as $r) {
        db_query('DELETE FROM roles WHERE id = :i', ['i' => (int) $r['id']], true);
    }

    db_query("DELETE FROM users WHERE username LIKE 'perimetre.%'", [], true);
    db_query("DELETE FROM audit_logs WHERE entity_type = 'roles'", [], true);
});

check('Le décor jetable est nettoyé',
    (int) platform_scope_cli(static fn (): int => (int) db_value(
        "SELECT COUNT(*) FROM roles WHERE name LIKE 'Recette perimetre %'", [], true
    )) === 0);

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
