<?php
/**
 * Phase 7B — la console de l'éditeur.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · la liste des écoles est transversale, et refusée à un compte
 *    d'établissement ;
 *  · changer d'offre CLÔTURE la précédente et garde l'historique ;
 *  · une école ne porte JAMAIS deux abonnements en cours, y compris
 *    sous deux changements simultanés ;
 *  · la visite d'une école SURVIT d'une requête à l'autre — elle est
 *    un état de la base, pas de la session ;
 *  · entrer et sortir laissent une trace, avec le motif ;
 *  · le service refuse ce qui n'a pas de sens : offre inconnue, cycle
 *    invalide, échéance inversée, plafond à zéro, suspension sans motif.
 *
 * Usage : php tests/platform_console.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/platform/services.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

/** Compte les traces d'une action, tous établissements confondus. */
function trace_count(string $action): int
{
    return (int) platform_scope_cli(static fn (): int => (int) db_value(
        'SELECT COUNT(*) FROM audit_logs WHERE action = :a',
        ['a' => $action],
        true
    ));
}

/** Les abonnements « en cours » d'une école. */
function live_subscriptions(int $schoolId): array
{
    return array_values(array_filter(
        platform_repo_school_subscriptions($schoolId),
        static fn (array $r): bool => in_array((string) $r['status'], ['trial', 'active', 'past_due'], true)
    ));
}

$schools = [];
$users   = [];

function act_as(int $userId, ?int $schoolId): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;

    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($schoolId);
}

try {
    foreach (['CONS-A' => 'École Alpha', 'CONS-B' => 'École Beta'] as $code => $name) {
        $schools[$code] = db_insert('schools', [
            'uuid' => str_uuid(), 'code' => $code, 'slug' => strtolower($code),
            'name' => $name, 'city' => 'Kinshasa', 'status' => 'active',
        ], true);
    }

    $editor = db_insert('users', [
        'uuid' => str_uuid(), 'school_id' => null, 'username' => 'console.editeur',
        'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name' => 'CONSOLE', 'first_name' => 'Editeur', 'status' => 'active',
    ], true);
    $users[] = $editor;

    db_query(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :u, id FROM roles WHERE code = \'SUPER_ADMIN\' AND school_id IS NULL',
        ['u' => $editor],
        true
    );

    $schoolAdmin = db_insert('users', [
        'uuid' => str_uuid(), 'school_id' => $schools['CONS-A'], 'username' => 'console.ecole',
        'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name' => 'CONSOLE', 'first_name' => 'Ecole', 'status' => 'active',
    ], true);
    $users[] = $schoolAdmin;

    db_query(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :u, id FROM roles WHERE code = \'SCHOOL_ADMIN\' AND school_id IS NULL',
        ['u' => $schoolAdmin],
        true
    );

    act_as($editor, null);

    // =================================================================
    echo "\n  LA CONSOLE EST TRANSVERSALE, ET RÉSERVÉE\n";

    $list  = platform_repo_schools([]);
    $codes = array_column($list, 'code');

    check('L\'éditeur voit les écoles de plusieurs établissements',
        in_array('CONS-A', $codes, true) && in_array('CONS-B', $codes, true));

    check('La recherche filtre', array_column(platform_repo_schools(['q' => 'Beta']), 'code') === ['CONS-B']);

    act_as($schoolAdmin, $schools['CONS-A']);

    check('Un compte d\'école se voit refuser la console',
        platform_refusal('platform.school.view') !== null);

    check('Et le changement d\'offre aussi',
        platform_refusal('platform.subscription.manage') !== null);

    act_as($editor, null);

    // =================================================================
    echo "\n  CHANGER D'OFFRE CLÔTURE LA PRÉCÉDENTE\n";

    $r1 = platform_service_change_plan($schools['CONS-A'], [
        'plan_code' => 'DECOUVERTE', 'reason' => 'ouverture du compte',
    ]);

    check('La première offre s\'applique', $r1['ok'], $r1['message']);

    $r2 = platform_service_change_plan($schools['CONS-A'], [
        'plan_code' => 'ESSENTIEL', 'billing_cycle' => 'monthly', 'reason' => 'montée de gamme',
    ]);

    check('La seconde aussi', $r2['ok'], $r2['message']);

    $all  = platform_repo_school_subscriptions($schools['CONS-A']);
    $live = live_subscriptions($schools['CONS-A']);

    check('L\'historique est GARDÉ', count($all) === 2, count($all) . ' ligne(s)');
    check('Une seule offre est en cours', count($live) === 1, count($live) . ' en cours');
    check('Et c\'est la dernière appliquée', $live[0]['plan_code'] === 'ESSENTIEL');
    check('La précédente est clôturée, pas supprimée',
        in_array('cancelled', array_column($all, 'status'), true));

    // Un plafond négocié l'emporte et se conserve.
    $r3 = platform_service_change_plan($schools['CONS-A'], [
        'plan_code' => 'ESSENTIEL', 'max_students_override' => 900,
        'reason' => 'accord commercial',
    ]);

    check('Un plafond négocié s\'enregistre', $r3['ok']);
    check('Et il est relu', (int) live_subscriptions($schools['CONS-A'])[0]['max_students_override'] === 900);

    // =================================================================
    echo "\n  L'INVARIANT TIENT SOUS CONCURRENCE\n";

    // DEUX CHANGEMENTS SIMULTANÉS SUR LA MÊME ÉCOLE.
    //
    // Sans le `SELECT … FOR UPDATE`, les deux liraient « un abonnement
    // en cours », les deux le clôtureraient, et les deux en ouvriraient
    // un : l'école finirait à deux.
    $script = <<<'PHP'
<?php
declare(strict_types=1);
require '/home/claude/school-saas/app/bootstrap.php';
require APP_PATH . '/modules/platform/services.php';
[$user, $school, $plan, $tag] = array_slice($argv, 1);
$_SESSION['user_id'] = (int) $user;
$_SESSION['school_id'] = null;
auth_user(true); perm_all(true); perm_roles(true);
tenant_set(null);
usleep(200000);
$r = platform_service_change_plan((int) $school, ['plan_code' => $plan, 'reason' => 'concurrence ' . $tag]);
echo $tag . ':' . ($r['ok'] ? 'ok' : 'refus') . "\n";
PHP;

    $tmp = sys_get_temp_dir() . '/console_conc_' . getmypid() . '.php';
    file_put_contents($tmp, $script);

    $out = [];
    exec(sprintf(
        'php %s %d %d PRO A & php %s %d %d RESEAU B & wait',
        escapeshellarg($tmp), $editor, $schools['CONS-A'],
        escapeshellarg($tmp), $editor, $schools['CONS-A']
    ) . ' 2>&1', $out);

    @unlink($tmp);

    check('Les deux changements aboutissent', count($out) === 2, implode(' ', $out));

    check('Mais l\'école ne porte QU\'UN abonnement en cours',
        count(live_subscriptions($schools['CONS-A'])) === 1);

    check('Et le parc entier est cohérent',
        platform_repo_subscription_conflicts() === []);

    // =================================================================
    echo "\n  LA VISITE SURVIT D'UNE REQUÊTE À L'AUTRE\n";

    check('Avant l\'entrée, aucun contexte', tenant_id() === null);
    check('Et aucun bandeau', platform_visiting_school() === null);

    $enter = platform_service_enter_school($schools['CONS-B']);

    check('L\'entrée réussit', $enter['ok'], $enter['message']);
    check('Le contexte est celui de l\'école visitée', tenant_id() === $schools['CONS-B']);
    check('Le bandeau la nomme',
        (platform_visiting_school()['name'] ?? '') === 'École Beta');

    // LE TEST QUI COMPTE.
    //
    // La première version plaçait la visite en session ; `auth_user()`
    // réétablit le contexte depuis la BASE à chaque requête, et
    // l'effaçait donc avant la page suivante. Simuler une requête
    // neuve, c'est vider les caches et relire.
    auth_user(true);
    perm_all(true);
    tenant_set(null);       // comme au début d'une requête HTTP
    auth_user(true);        // …qui repose le contexte depuis la base

    check('LA VISITE TIENT à la requête suivante', tenant_id() === $schools['CONS-B'],
        'tenant = ' . var_export(tenant_id(), true));

    check('L\'éditeur garde sa console depuis l\'école',
        platform_refusal('platform.school.view') === null);

    $leave = platform_service_leave_school();

    check('La sortie réussit', $leave['ok']);
    check('Le contexte est rendu', tenant_id() === null);
    check('Le bandeau disparaît', platform_visiting_school() === null);

    auth_user(true);
    check('Et il ne revient pas à la requête suivante', tenant_id() === null);

    // LA COLONNE N'AGIT QUE POUR UN COMPTE DE PLATEFORME.
    tenant_scope_identity(static function () use ($schoolAdmin, $schools): void {
        db_query(
            'UPDATE users SET visiting_school_id = :v WHERE id = :id',
            ['v' => $schools['CONS-B'], 'id' => $schoolAdmin],
            true
        );
    });

    act_as($schoolAdmin, $schools['CONS-A']);

    check('Renseignée par erreur sur un compte d\'école, elle est IGNORÉE',
        tenant_id() === $schools['CONS-A'],
        'tenant = ' . var_export(tenant_id(), true));

    check('Et le bandeau ne s\'affiche pas chez lui',
        platform_visiting_school() === null);

    act_as($editor, null);

    // =================================================================
    echo "\n  CHANGER D'ÉCOLE FERME LA PRÉCÉDENTE (audit 7B1)\n";

    // Sans cela, deux entrées et aucune sortie : rien ne disait quand
    // l'éditeur avait quitté la première école. Or c'est la question
    // qu'une école poserait.
    $entersBefore = trace_count('platform.school.enter');
    $leavesBefore = trace_count('platform.school.leave');

    platform_service_enter_school($schools['CONS-A']);
    platform_service_enter_school($schools['CONS-B']);   // sans sortir de A

    check('Deux entrées sont tracées',
        trace_count('platform.school.enter') - $entersBefore === 2);

    check('ET une sortie, celle de la première école',
        trace_count('platform.school.leave') - $leavesBefore === 1);

    check('Le contexte est bien la SECONDE école',
        tenant_id() === $schools['CONS-B']);

    // Réentrer dans la MÊME école ne doit pas produire une sortie inutile.
    $leavesNow = trace_count('platform.school.leave');
    platform_service_enter_school($schools['CONS-B']);

    check('Réentrer dans la même école ne fabrique pas de sortie',
        trace_count('platform.school.leave') === $leavesNow);

    platform_service_leave_school();

    // =================================================================
    echo "\n  LE BANDEAU NE DISPARAÎT PAS SOUS L'ÉDITEUR (audit 7B1)\n";

    platform_service_enter_school($schools['CONS-A']);

    // L'école est ARCHIVÉE pendant la visite.
    platform_scope_cli(static fn () => db_query(
        'UPDATE schools SET deleted_at = NOW() WHERE id = :id',
        ['id' => $schools['CONS-A']],
        true
    ));

    auth_user(true);   // requête suivante

    $banner = platform_visiting_school();

    check('Le contexte est toujours celui de l\'école', tenant_id() === $schools['CONS-A']);

    check('ET le bandeau la nomme toujours', $banner !== null,
        $banner === null ? 'ABSENT — l\'éditeur est dedans sans le savoir' : $banner['name']);

    check('Il la signale comme ARCHIVÉE', !empty($banner['archived']));

    platform_scope_cli(static fn () => db_query(
        'UPDATE schools SET deleted_at = NULL WHERE id = :id',
        ['id' => $schools['CONS-A']],
        true
    ));

    platform_service_leave_school();

    // =================================================================
    echo "\n  CONSULTER NE DÉMARRE PAS D'HORLOGE COMMERCIALE (audit 7B1)\n";

    require_once APP_PATH . '/modules/subscriptions/services.php';

    // Une école sans aucun abonnement — comme le sera toute école créée
    // avant qu'on lui applique une offre.
    $bare = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'CONS-C', 'slug' => 'cons-c',
        'name' => 'École sans offre', 'status' => 'active',
    ], true);
    $schools['CONS-C'] = $bare;

    platform_service_enter_school($bare);

    $countBefore = (int) platform_scope_cli(static fn (): int => (int) db_value(
        'SELECT COUNT(*) FROM subscriptions WHERE school_id = :s', ['s' => $bare], true
    ));

    // Ce que fait ctrl_subscription_show() : il LIT.
    subscription_to_show();
    subscription_usage();
    subscription_days_left();

    $countAfter = (int) platform_scope_cli(static fn (): int => (int) db_value(
        'SELECT COUNT(*) FROM subscriptions WHERE school_id = :s', ['s' => $bare], true
    ));

    check('Aucun abonnement au départ', $countBefore === 0);
    check('Ouvrir l\'écran n\'en crée AUCUN', $countAfter === 0,
        $countAfter . ' créé(s)');

    // Mais le chemin d'ÉCRITURE, lui, tient toujours l'invariant.
    subscription_can_add_student();

    check('En revanche, le chemin d\'écriture crée bien l\'essai',
        (int) platform_scope_cli(static fn (): int => (int) db_value(
            'SELECT COUNT(*) FROM subscriptions WHERE school_id = :s', ['s' => $bare], true
        )) === 1);

    platform_service_leave_school();

    // =================================================================
    echo "\n  TOUT EST TRACÉ, LE MOTIF COMPRIS\n";

    foreach ([
        'platform.plan.change'  => 'le changement d\'offre',
        'platform.school.enter' => 'l\'entrée dans une école',
        'platform.school.leave' => 'la sortie',
    ] as $action => $label) {
        $n = (int) platform_scope_cli(static fn (): int => (int) db_value(
            'SELECT COUNT(*) FROM audit_logs WHERE action = :a',
            ['a' => $action],
            true
        ));

        check('Une trace pour ' . $label, $n > 0, $n . ' entrée(s)');
    }

    check(
        'Le MOTIF du changement est conservé',
        platform_scope_cli(static fn (): bool => db_exists(
            'SELECT 1 FROM audit_logs
              WHERE action = :a AND new_values LIKE :r',
            ['a' => 'platform.plan.change', 'r' => '%accord commercial%'],
            true
        ))
    );

    // =================================================================
    echo "\n  CE QUE LE SERVICE REFUSE\n";

    $refusals = [
        'une offre inconnue'        => ['plan_code' => 'INEXISTANTE'],
        'un cycle invalide'         => ['plan_code' => 'PRO', 'billing_cycle' => 'weekly'],
        'une échéance inversée'     => ['plan_code' => 'PRO', 'starts_on' => '2027-01-01', 'ends_on' => '2026-01-01'],
        'un plafond négocié à zéro' => ['plan_code' => 'PRO', 'max_students_override' => 0],
    ];

    foreach ($refusals as $label => $input) {
        $r = platform_service_change_plan($schools['CONS-B'], $input);
        check('Refusé : ' . $label, !$r['ok'], $r['message']);
    }

    check('Refusé : une suspension sans motif',
        !platform_service_set_subscription_status($schools['CONS-A'], 'suspended', '')['ok']);

    check('Accepté : une suspension motivée',
        platform_service_set_subscription_status($schools['CONS-A'], 'suspended', 'impayé de 3 mois')['ok']);

    // LA RÉSILIATION NE PASSE PAS PAR LE STATUT.
    //
    // Sinon l'école se retrouverait sans aucun abonnement en cours, et
    // `subscription_ensure()` lui rouvrirait un essai gratuit au
    // premier écran. Résilier, c'est appliquer une offre.
    check('Refusé : une résiliation par changement de statut',
        !platform_service_set_subscription_status($schools['CONS-A'], 'cancelled', 'fin')['ok']);

    // =================================================================
    echo "\n  UNE SUSPENSION NE FERME PAS LA CAISSE\n";

    // La règle de la phase 7A vaut depuis la console aussi : c'est la
    // même table, le même service de limite.
    require_once APP_PATH . '/modules/subscriptions/services.php';

    act_as($editor, null);
    platform_service_enter_school($schools['CONS-A']);

    check('Depuis l\'école suspendue, aucune inscription n\'est possible',
        !subscription_can_add_student()['ok']);

    check('Mais l\'abonnement reste LISIBLE pour être expliqué',
        subscription_any() !== null);

    platform_service_leave_school();
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    platform_scope_cli(static function () use ($schools, $users): void {
        foreach ($users as $u) {
            db_query('UPDATE users SET visiting_school_id = NULL WHERE id = :id', ['id' => $u], true);
        }

        foreach ($schools as $id) {
            db_query('UPDATE users SET visiting_school_id = NULL WHERE visiting_school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM subscription_payments WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM subscriptions WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM audit_logs WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM school_settings WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM users WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM schools WHERE id = :id', ['id' => $id], true);
        }

        foreach ($users as $u) {
            db_query('DELETE FROM user_roles WHERE user_id = :u', ['u' => $u], true);
            db_query('DELETE FROM users WHERE id = :id', ['id' => $u], true);
        }

        db_query(
            'DELETE FROM audit_logs WHERE action LIKE :a AND school_id IS NULL',
            ['a' => 'platform.%'],
            true
        );
    });
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
