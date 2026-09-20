<?php
/**
 * Phase 7A — abonnements, limites, et ce qu'une limite ne casse pas.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · toute école a un abonnement au moment où la règle s'applique ;
 *  · la limite d'élèves MORD, à l'inscription comme à la réinscription ;
 *  · elle se mesure sur l'année VISÉE, pas sur l'année courante ;
 *  · une limite atteinte ne ferme jamais la lecture NI LA CAISSE ;
 *  · les comptes de familles ne comptent pas dans `max_users` ;
 *  · NULL veut dire illimité, jamais zéro ;
 *  · aucune école ne lit l'abonnement d'une autre.
 *
 * Usage : php tests/subscriptions_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/finance/services.php';
require APP_PATH . '/modules/portal/services.php';
require APP_PATH . '/modules/subscriptions/services.php';
require_once APP_PATH . '/modules/students/repositories.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

function act_as(int $schoolId, string $roleCode): int
{
    static $counter = 0;
    $counter++;

    $userId = db_insert('users', [
        'uuid'          => str_uuid(),
        'school_id'     => $schoolId,
        'username'      => 'sub.' . strtolower($roleCode) . '.' . $counter,
        'password_hash' => password_hash('MotDePasse2026', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name'     => 'TEST',
        'first_name'    => ucfirst(strtolower($roleCode)),
        'status'        => 'active',
    ], true);

    db_query(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :user_id, id FROM roles WHERE code = :code AND school_id IS NULL',
        ['user_id' => $userId, 'code' => $roleCode],
        true
    );

    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;

    auth_user(true);
    perm_all(true);
    perm_roles(true);

    return $userId;
}

function purge_school(int $schoolId): void
{
    foreach ([
        'subscription_payments', 'subscriptions',
        'bulletin_lines', 'bulletins', 'attendance_records', 'attendance_sessions',
        'grades', 'payment_allocations', 'payments', 'student_fees', 'fees',
        'receipt_counters', 'expenses', 'expense_counters',
        'student_history', 'orientations', 'student_guardians', 'enrollments',
        'guardians', 'students', 'student_counters',
        'teacher_subjects', 'teachers', 'classrooms',
        'curriculum_subjects', 'curriculums', 'grade_periods',
    ] as $table) {
        db_query("DELETE FROM {$table} WHERE school_id = :s", ['s' => $schoolId], true);
    }

    db_query('DELETE FROM schools WHERE id = :id', ['id' => $schoolId], true);
    db_query('DELETE FROM users WHERE school_id = :id', ['id' => $schoolId], true);
}

$createdSchools = [];

try {
    $schoolId = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'SUB-A', 'slug' => 'sub-a',
        'name' => 'École abonnée', 'status' => 'active',
    ], true);
    $createdSchools[] = $schoolId;

    db_query(
        'INSERT INTO school_cycles (school_id, cycle_id, is_active)
         SELECT :s, id, 1 FROM education_cycles',
        ['s' => $schoolId]
    );

    act_as($schoolId, 'SCHOOL_ADMIN');
    tenant_set($schoolId);
    school_settings_all(true);

    echo "\n  TOUTE ÉCOLE A UN ABONNEMENT\n";

    check('Une école neuve n\'en a pas encore', subscription_any() === null);

    $yearId = tenant_insert('academic_years', [
        'code' => 'SB-' . date('Y'), 'name' => 'Test abonnement',
        'starts_on' => date('Y-m-d', strtotime('-30 days')),
        'ends_on' => date('Y-m-d', strtotime('+300 days')),
        'status' => 'active', 'is_current' => 1,
    ]);

    curriculum_service_import_national();
    curriculum_service_create_standard_periods($yearId);

    $levelId = (int) db_value("SELECT id FROM education_levels WHERE code = 'CTEB_7'", [], true);
    $program = curriculum_service_create_program($yearId, $levelId, null, null);
    curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    $classId = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '7A', 'name' => '7ème A', 'capacity' => 500,
    ]);

    $first = students_service_enroll_new(
        ['last_name' => 'PREMIER', 'first_name' => 'Eleve', 'gender' => 'M'],
        $yearId,
        $classId
    );

    check('La première inscription réussit', $first['ok'], $first['message'] ?? '');

    $sub = subscription_any();

    check('Et l\'abonnement est créé au passage', $sub !== null);
    check('C\'est un ESSAI, pas un abonnement actif',
        $sub !== null && (string) $sub['status'] === 'trial');
    check('Sur l\'offre Découverte',
        $sub !== null && (string) $sub['plan_code'] === 'DECOUVERTE');
    check('Avec une échéance dans le futur',
        subscription_days_left() !== null && subscription_days_left() > 0);

    echo "\n  LA LIMITE MORD\n";

    // Plafond ramené à 3 : éprouver la règle sans créer 150 élèves.
    tenant_update('subscriptions', ['max_students_override' => 3],
        'school_id = :s', ['s' => $schoolId]);

    check('Le plafond négocié l\'emporte sur l\'offre',
        subscription_limits()['students'] === 3);

    for ($i = 2; $i <= 3; $i++) {
        students_service_enroll_new(
            ['last_name' => 'ELEVE' . $i, 'first_name' => 'Test', 'gender' => 'M'],
            $yearId,
            $classId
        );
    }

    check('Trois élèves sont inscrits', subscription_student_count($yearId) === 3);

    $refused = students_service_enroll_new(
        ['last_name' => 'QUATRIEME', 'first_name' => 'Test', 'gender' => 'M'],
        $yearId,
        $classId
    );

    check('Le quatrième est REFUSÉ', !$refused['ok']);

    check(
        'Et le message nomme l\'offre et le plafond',
        str_contains((string) $refused['message'], 'Découverte')
            && str_contains((string) $refused['message'], '3'),
        $refused['message']
    );

    check('Aucun dossier orphelin n\'a été créé',
        subscription_student_count($yearId) === 3);

    // LA RÉINSCRIPTION AUSSI.
    //
    // Sans ce contrôle, une école de 700 élèves passée sur une offre à
    // 600 les réinscrirait tous : la limite ne vaudrait que pour les
    // nouveaux, donc quasiment jamais.
    $nextYear = tenant_insert('academic_years', [
        'code' => 'SB2-' . date('Y'), 'name' => 'Année suivante',
        'starts_on' => date('Y-m-d', strtotime('+301 days')),
        'ends_on' => date('Y-m-d', strtotime('+600 days')),
        'status' => 'active', 'is_current' => null,
    ]);

    $progNext = curriculum_service_create_program($nextYear, $levelId, null, null);
    curriculum_service_fill_program((int) $progNext['id']);
    curriculum_service_activate((int) $progNext['id']);

    $classNext = tenant_insert('classrooms', [
        'academic_year_id' => $nextYear, 'curriculum_id' => (int) $progNext['id'],
        'code' => '8A', 'name' => '8ème A', 'capacity' => 500,
    ]);

    tenant_update('subscriptions', ['max_students_override' => 2],
        'school_id = :s', ['s' => $schoolId]);

    $students = db_all(
        'SELECT id FROM students WHERE school_id = :s AND deleted_at IS NULL ORDER BY id',
        ['s' => $schoolId]
    );

    $reOk = 0;
    $reKo = 0;

    foreach ($students as $st) {
        $r = students_service_re_enroll((int) $st['id'], $nextYear, $classNext);
        $r['ok'] ? $reOk++ : $reKo++;
    }

    check('La réinscription s\'arrête au plafond', $reOk === 2 && $reKo >= 1,
        $reOk . ' réinscrit(s), ' . $reKo . ' refusé(s)');

    // LA MESURE PORTE SUR L'ANNÉE VISÉE.
    //
    // L'année suivante n'est pas `is_current`. Si le décompte portait
    // sur l'année courante, elle aurait accepté tout le monde.
    check('Le décompte porte sur l\'année VISÉE, pas la courante',
        subscription_student_count($nextYear) === 2
            && subscription_student_count($yearId) === 3);

    echo "\n  CE QU'UNE LIMITE NE DOIT JAMAIS CASSER\n";

    check('La lecture des dossiers reste entière',
        count(students_repo_search([], $yearId)) > 0);

    $fee = finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'MIN', 'name' => 'Minerval',
        'currency' => 'USD', 'amount' => '50', 'scope' => 'school', 'is_active' => 1,
    ]);

    check('On peut encore créer un frais', $fee['ok'], $fee['message']);

    finance_service_assign($yearId);

    $enr = (int) db_value(
        'SELECT id FROM enrollments WHERE school_id = :s AND academic_year_id = :y LIMIT 1',
        ['s' => $schoolId, 'y' => $yearId]
    );

    $pay = finance_service_record_payment($enr, [
        'paid_on' => date('Y-m-d'), 'tendered_currency' => 'USD', 'tendered_amount' => '20',
        'credited_currency' => 'USD', 'method' => 'cash',
    ]);

    // RETENIR LA CAISSE D'UNE ÉCOLE POUR LA FAIRE PAYER SERAIT UNE
    // PRISE D'OTAGE — et l'empêcherait précisément de nous payer.
    check('LA CAISSE RESTE OUVERTE malgré le plafond atteint', $pay['ok'], $pay['message']);

    echo "\n  LES COMPTES DE FAMILLES NE COMPTENT PAS\n";

    $before = subscription_usage();

    $studentId = (int) db_value(
        'SELECT student_id FROM enrollments WHERE id = :e AND school_id = :s',
        ['e' => $enr, 's' => $schoolId]
    );

    $access = portal_service_create_student_access($studentId);

    check('Un compte élève se crée', $access['ok'], $access['message']);

    $after = subscription_usage();

    check('Le décompte du PERSONNEL n\'a pas bougé',
        $after['staff_users']['used'] === $before['staff_users']['used'],
        $before['staff_users']['used'] . ' → ' . $after['staff_users']['used']);

    check('Celui des FAMILLES a augmenté',
        $after['family_users']['used'] === $before['family_users']['used'] + 1);

    check('Et les familles n\'ont aucun plafond',
        $after['family_users']['limit'] === null);

    echo "\n  NULL VEUT DIRE ILLIMITÉ, JAMAIS ZÉRO\n";

    $reseau = (int) db_value("SELECT id FROM plans WHERE code = 'RESEAU'", [], true);

    tenant_update('subscriptions',
        ['plan_id' => $reseau, 'max_students_override' => null],
        'school_id = :s', ['s' => $schoolId]);

    check('L\'offre Réseau n\'a pas de plafond d\'élèves',
        subscription_limits()['students'] === null);

    $unlimited = students_service_enroll_new(
        ['last_name' => 'ILLIMITE', 'first_name' => 'Test', 'gender' => 'F'],
        $yearId,
        $classId
    );

    check('Et l\'inscription y passe', $unlimited['ok'], $unlimited['message'] ?? '');

    check('Le restant d\'une limite illimitée est NULL, pas 0',
        subscription_usage()['years'][0]['remaining'] === null);

    echo "\n  UN ÉCRAN, UNE VÉRITÉ (audit 7A)\n";

    // SCÉNARIO RÉEL : l'école résilie son offre ANNUELLE en cours de
    // terme — la résiliation garde la date payée, loin devant — puis
    // souscrit une MENSUELLE. Deux lignes coexistent, et la plus
    // lointaine est la RÉSILIÉE.
    $annual = subscription_any();

    tenant_update('subscriptions',
        ['status' => 'cancelled', 'ends_on' => date('Y-m-d', strtotime('+300 days'))],
        'school_id = :s AND id = :sub_id', ['s' => $schoolId, 'sub_id' => (int) $annual['id']]);

    $monthlyId = tenant_insert('subscriptions', [
        'plan_id'       => (int) db_value("SELECT id FROM plans WHERE code = 'ESSENTIEL'", [], true),
        'status'        => 'active',
        'billing_cycle' => 'monthly',
        'starts_on'     => date('Y-m-d'),
        'ends_on'       => date('Y-m-d', strtotime('+30 days')),
        'auto_renew'    => 1,
    ]);

    check('`subscription_any()` rend bien la RÉSILIÉE (la plus lointaine)',
        (int) subscription_any()['id'] === (int) $annual['id']);

    check('Mais l\'écran affiche celle qui GOUVERNE',
        (int) subscription_to_show()['id'] === $monthlyId,
        'affiché : ' . subscription_to_show()['status']);

    check('Le plafond affiché est celui de l\'offre qui gouverne',
        subscription_usage()['years'][0]['limit'] === 600);

    // L'échéance annoncée ne doit pas être celle d'un abonnement éteint.
    check('L\'échéance annoncée est celle de l\'offre active',
        subscription_days_left() !== null && subscription_days_left() <= 31,
        subscription_days_left() . ' jour(s)');

    db_query('DELETE FROM subscriptions WHERE school_id = :s AND id = :sub_id',
        ['s' => $schoolId, 'sub_id' => (int) $annual['id']]);

    echo "\n  LA JAUGE COMPTE LES MÊMES ANNÉES QUE LA LIMITE (audit 7A)\n";

    // L'année suivante a été créée plus haut : elle n'est pas `is_current`
    // et porte 2 réinscrits. La jauge doit la MONTRER.
    tenant_update('subscriptions', ['max_students_override' => 2],
        'school_id = :s', ['s' => $schoolId]);

    $usage = subscription_usage();
    $byId  = [];

    foreach ($usage['years'] as $line) {
        $byId[$line['id']] = $line;
    }

    check('L\'année VISÉE, non courante, figure à l\'écran',
        isset($byId[$nextYear]),
        'années affichées : ' . implode(', ', array_column($usage['years'], 'name')));

    $refusal = subscription_can_add_student($nextYear);

    check('Elle y figure PLEINE, comme l\'inscription la refuse',
        !$refusal['ok']
            && isset($byId[$nextYear])
            && (int) $byId[$nextYear]['remaining'] === 0,
        isset($byId[$nextYear])
            ? $byId[$nextYear]['used'] . '/' . $byId[$nextYear]['limit']
            : 'absente');

    echo "\n  ZÉRO N'EST PAS UN PLAFOND D'OFFRE (audit 7A)\n";

    tenant_update('subscriptions', ['status' => 'suspended'],
        'school_id = :s', ['s' => $schoolId]);

    $usage = subscription_usage();

    check('Sans abonnement actif, aucun plafond ne gouverne',
        $usage['governed'] === false);

    $zero = false;

    foreach ($usage['years'] as $line) {
        $zero = $zero || $line['limit'] === 0;
    }

    check('Aucune année n\'affiche un plafond de zéro',
        !$zero && $usage['staff_users']['limit'] !== 0);

    check('Mais les décomptes restent exacts',
        $usage['years'] !== [] && $usage['years'][0]['used'] > 0);

    // La DÉCISION, elle, reste un refus net — c'est son rôle.
    check('La décision interne refuse toujours',
        subscription_limits()['students'] === 0
            && !subscription_can_add_student($yearId)['ok']);

    tenant_update('subscriptions',
        ['status' => 'trial', 'max_students_override' => null],
        'school_id = :s', ['s' => $schoolId]);

    echo "\n  SANS ABONNEMENT ACTIF, RIEN NE SE CRÉE — MAIS TOUT SE LIT\n";

    tenant_update('subscriptions', ['status' => 'suspended'],
        'school_id = :s', ['s' => $schoolId]);

    check('Un abonnement suspendu n\'est plus « en cours »',
        subscription_current() === null);

    check('Mais il reste lisible pour être affiché',
        subscription_any() !== null);

    $blocked = subscription_can_add_student($yearId);

    check('Aucune inscription n\'est possible', !$blocked['ok'], $blocked['message']);

    check('La lecture des dossiers reste entière',
        count(students_repo_search([], $yearId)) > 0);

    $stillPay = finance_service_record_payment($enr, [
        'paid_on' => date('Y-m-d'), 'tendered_currency' => 'USD', 'tendered_amount' => '5',
        'credited_currency' => 'USD', 'method' => 'cash',
    ]);

    check('ET LA CAISSE RESTE OUVERTE', $stillPay['ok'], $stillPay['message']);

    tenant_update('subscriptions', ['status' => 'trial'],
        'school_id = :s', ['s' => $schoolId]);

    echo "\n  LE PLAFOND DE COMPTES DU PERSONNEL — CONTRAT ÉCRIT D'AVANCE\n";

    // AUCUN ÉCRAN NE CRÉE ENCORE DE COMPTE DU PERSONNEL.
    //
    // Il n'y a pas de module Utilisateurs : `subscription_can_add_staff_user()`
    // n'est appelé par aucune porte. On verrouille quand même sa règle ici,
    // pour que le jour où cette porte existera, elle n'ait qu'à l'appeler —
    // et pour qu'une régression sur le décompte se voie tout de suite.
    $decouverte = (int) db_value("SELECT id FROM plans WHERE code = 'DECOUVERTE'", [], true);

    tenant_update('subscriptions',
        ['plan_id' => $decouverte, 'max_students_override' => null],
        'school_id = :s', ['s' => $schoolId]);

    $staffLimit = subscription_limits()['staff_users'];

    check('L\'offre Découverte plafonne les comptes du personnel',
        $staffLimit !== null && $staffLimit > 0, var_export($staffLimit, true));

    check('Sous le plafond, la règle laisse passer',
        subscription_can_add_staff_user()['ok']);

    // On amène le décompte au plafond sans toucher au reste.
    for ($i = subscription_staff_user_count(); $i < $staffLimit; $i++) {
        db_insert('users', [
            'uuid'          => str_uuid(),
            'school_id'     => $schoolId,
            'username'      => 'bourrage.' . $i,
            'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
            'last_name'     => 'BOURRAGE',
            'first_name'    => (string) $i,
            'status'        => 'active',
        ], true);
    }

    check('Le décompte atteint le plafond',
        subscription_staff_user_count() === $staffLimit);

    $staffKo = subscription_can_add_staff_user();

    check('Au plafond, la règle REFUSE', !$staffKo['ok']);

    check('Et son message dit que les familles n\'y comptent pas',
        str_contains($staffKo['message'], 'familles'), $staffKo['message']);

    echo "\n  AUCUNE ÉCOLE NE LIT L'ABONNEMENT D'UNE AUTRE\n";

    $otherSchool = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'SUB-B', 'slug' => 'sub-b',
        'name' => 'Autre école', 'status' => 'active',
    ], true);
    $createdSchools[] = $otherSchool;

    act_as($otherSchool, 'SCHOOL_ADMIN');
    tenant_set($otherSchool);

    check('Elle ne voit aucun abonnement', subscription_any() === null);
    check('Ni aucun élève au décompte', subscription_student_count() === 0);
    check('Ni aucun compte de famille', subscription_family_user_count() === 0);
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($createdSchools as $id) {
        tenant_set($id);
        purge_school($id);
    }
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
