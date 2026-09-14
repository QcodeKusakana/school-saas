<?php
/**
 * Phase 5A — frais et dettes : gel, devises, périmètre, isolation.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · un tarif modifié ne réécrit AUCUNE dette déjà annoncée ;
 *  · l'échappatoire de correction ne produit jamais de solde négatif
 *    et ne change jamais la monnaie d'une dette ;
 *  · une annulation reste réversible — sinon la somme due est perdue ;
 *  · deux devises ne sont jamais additionnées ;
 *  · un inscrit sans dette affectée est SIGNALÉ, jamais confondu avec
 *    un élève en règle ;
 *  · un parent ne voit que la situation de ses enfants ;
 *  · aucune école ne voit la caisse d'une autre.
 *
 * Usage : php tests/finance_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/finance/services.php';
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
        'username'      => 'fin.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'fin' . $counter . '@example.test',
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

echo "\n  FRAIS ET DETTES — GEL, DEVISES, PÉRIMÈTRE\n";
echo "  ──────────────────────────────────────────────────────\n\n";

$createdSchools = [];

try {
    $schoolId = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'FIN-A', 'slug' => 'fin-a',
        'name' => 'École de la Caisse', 'status' => 'active',
    ], true);
    $createdSchools[] = $schoolId;

    db_query(
        'INSERT INTO school_cycles (school_id, cycle_id, is_active)
         SELECT :s, id, 1 FROM education_cycles',
        ['s' => $schoolId]
    );

    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);
    school_settings_all(true);

    $yearId = tenant_insert('academic_years', [
        'code' => '2095-2096', 'name' => 'Test finances', 'starts_on' => '2095-09-01',
        'ends_on' => '2096-07-31', 'status' => 'active', 'is_current' => 1,
    ]);

    curriculum_service_import_national();
    curriculum_service_create_standard_periods($yearId);

    $levelId = (int) db_value("SELECT id FROM education_levels WHERE code = 'CTEB_7'", [], true);
    $program = curriculum_service_create_program($yearId, $levelId, null, null);
    curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    $classA = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '7A', 'name' => '7ème A', 'capacity' => 40,
    ]);

    $enroll = [];

    foreach ([['MOSI', 'Un'], ['MBILA', 'Deux'], ['NKOSI', 'Trois']] as $s) {
        $r = students_service_enroll_new(
            ['last_name' => $s[0], 'first_name' => $s[1], 'gender' => 'M'],
            $yearId,
            $classA
        );
        $enroll[] = (int) students_repo_enrollment((int) $r['id'], $yearId)['id'];
    }

    // =================================================================
    //  LA GRILLE
    // =================================================================
    $minerval = finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'MIN_T1', 'name' => 'Minerval 1re tranche',
        'currency' => 'USD', 'amount' => '50', 'scope' => 'school',
        'due_on' => '2095-10-15', 'is_mandatory' => 1, 'is_active' => 1,
    ]);

    check('Un frais en USD se crée', $minerval['ok'], $minerval['message']);

    $fonct = finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'FONCT', 'name' => 'Frais de fonctionnement',
        'currency' => 'CDF', 'amount' => '45000', 'scope' => 'school',
        'is_mandatory' => 1, 'is_active' => 1,
    ]);

    check('Un frais en CDF se crée à côté', $fonct['ok'], $fonct['message']);

    $dupe = finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'MIN_T1', 'name' => 'Doublon',
        'currency' => 'USD', 'amount' => '10', 'scope' => 'school', 'is_active' => 1,
    ]);

    check('Un code dupliqué est refusé par un message, pas par une exception', !$dupe['ok']);

    $zero = finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'ZERO', 'name' => 'Frais nul',
        'currency' => 'USD', 'amount' => '0', 'scope' => 'school', 'is_active' => 1,
    ]);

    check('Un montant nul ou négatif est refusé', !$zero['ok']);

    // =================================================================
    //  AFFECTATION : IDEMPOTENTE
    // =================================================================
    $first  = finance_service_assign($yearId);
    $second = finance_service_assign($yearId);

    check(
        'La première affectation crée une dette par frais et par élève',
        $first['created'] === 6,
        $first['created'] . ' dette(s) pour 3 élèves × 2 frais'
    );

    check(
        'La rejouer ne crée aucun doublon',
        $second['created'] === 0 && $second['skipped'] === 6,
        $second['created'] . ' créée(s), ' . $second['skipped'] . ' ignorée(s)'
    );

    // =================================================================
    //  DEUX DEVISES NE S'ADDITIONNENT PAS
    //
    //  50 USD et 45 000 CDF ne font pas 45 050. Un total unique serait
    //  faux dans toutes les monnaies à la fois.
    // =================================================================
    $due = finance_repo_due_by_currency($enroll[0]);

    check(
        'Le dû est rendu par devise, jamais fusionné',
        count($due) === 2 && abs(($due['USD'] ?? 0) - 50.0) < 0.01
            && abs(($due['CDF'] ?? 0) - 45000.0) < 0.01,
        implode(' + ', array_map(
            static fn (string $c, float $t): string => finance_amount($t, $c),
            array_keys($due),
            array_values($due)
        ))
    );

    // =================================================================
    //  LE GEL DU TARIF
    //
    //  Le montant annoncé à une famille en septembre ne doit pas
    //  pouvoir être réécrit en janvier par une modification du
    //  paramétrage. Cinquième application du principe dans ce projet.
    // =================================================================
    finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'MIN_T1', 'name' => 'Minerval 1re tranche',
        'currency' => 'USD', 'amount' => '80', 'scope' => 'school',
        'due_on' => '2095-10-15', 'is_mandatory' => 1, 'is_active' => 1,
    ], (int) $minerval['id']);

    $frozen = (float) db_value(
        'SELECT amount_due FROM student_fees
          WHERE school_id = :s AND enrollment_id = :e AND fee_id = :f',
        ['s' => $schoolId, 'e' => $enroll[0], 'f' => (int) $minerval['id']]
    );

    check(
        'Relever le tarif ne réécrit AUCUNE dette déjà annoncée',
        abs($frozen - 50.0) < 0.01,
        'dette gelée à ' . $frozen . ' USD alors que le tarif est à 80'
    );

    // Un élève inscrit APRÈS la hausse paie bien le nouveau tarif.
    $late = students_service_enroll_new(
        ['last_name' => 'TARDIF', 'first_name' => 'Quatre', 'gender' => 'F'],
        $yearId,
        $classA
    );
    $lateEnroll = (int) students_repo_enrollment((int) $late['id'], $yearId)['id'];
    finance_service_assign($yearId);

    $lateAmount = (float) db_value(
        'SELECT amount_due FROM student_fees
          WHERE school_id = :s AND enrollment_id = :e AND fee_id = :f',
        ['s' => $schoolId, 'e' => $lateEnroll, 'f' => (int) $minerval['id']]
    );

    check(
        'Un élève inscrit après la hausse reçoit le tarif du jour',
        abs($lateAmount - 80.0) < 0.01,
        $lateAmount . ' USD'
    );

    // =================================================================
    //  L'ÉCHAPPATOIRE DE CORRECTION
    //
    //  Le gel est juste, mais une règle sans issue devient un piège :
    //  un minerval saisi à 500 au lieu de 50 serait irréparable. La
    //  correction existe donc — motivée, tracée, et annoncée d'avance.
    // =================================================================
    check(
        'La divergence est annonçable AVANT de corriger',
        finance_service_resync_preview((int) $minerval['id']) === 3,
        finance_service_resync_preview((int) $minerval['id']) . ' dette(s) divergentes'
    );

    check(
        'Corriger sans motif est refusé',
        !finance_service_resync_fee((int) $minerval['id'], 'abc')['ok']
    );

    $resync = finance_service_resync_fee((int) $minerval['id'], 'Erreur de saisie constatee au guichet');

    check(
        'Corriger avec motif réaligne les dettes divergentes',
        $resync['ok'] && $resync['updated'] === 3,
        $resync['updated'] . ' dette(s)'
    );

    // =================================================================
    //  LA CORRECTION NE PRODUIT JAMAIS DE SOLDE NÉGATIF
    //
    //  Une remise de 30 sur une dette ramenée à 20 laissait un « reste
    //  à payer » de −10 : l'école devait de l'argent à la famille sans
    //  qu'aucun paiement n'ait eu lieu.
    // =================================================================
    $lineB = (int) db_value(
        'SELECT id FROM student_fees
          WHERE school_id = :s AND enrollment_id = :e AND fee_id = :f',
        ['s' => $schoolId, 'e' => $enroll[1], 'f' => (int) $minerval['id']]
    );

    finance_service_set_discount($lineB, 30.0, 'Enfant du personnel');

    finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'MIN_T1', 'name' => 'Minerval 1re tranche',
        'currency' => 'USD', 'amount' => '20', 'scope' => 'school',
        'due_on' => '2095-10-15', 'is_mandatory' => 1, 'is_active' => 1,
    ], (int) $minerval['id']);

    $lowered = finance_service_resync_fee((int) $minerval['id'], 'Correction du tarif apres erreur');

    $net = (float) db_value(
        'SELECT amount_due - discount_amount FROM student_fees
          WHERE id = :i AND school_id = :s',
        ['i' => $lineB, 's' => $schoolId]
    );

    check(
        'Une remise plus grande que le nouveau tarif ne crée pas de solde négatif',
        $net >= 0.0,
        'reste à payer : ' . $net . ' USD'
    );

    check(
        'Les remises rabotées sont annoncées, pas subies en silence',
        ($lowered['capped'] ?? 0) === 1,
        ($lowered['capped'] ?? 0) . ' remise(s) ramenée(s) à une exonération totale'
    );

    // =================================================================
    //  LA CORRECTION NE CHANGE PAS DE MONNAIE
    //
    //  Réécrire la devise sans convertir transformait 10 USD en 10 CDF :
    //  un rapport de 1 à 3 500, silencieux.
    // =================================================================
    finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'MIN_T1', 'name' => 'Minerval 1re tranche',
        'currency' => 'CDF', 'amount' => '60000', 'scope' => 'school', 'is_active' => 1,
    ], (int) $minerval['id']);

    $swap = finance_service_resync_fee((int) $minerval['id'], 'Passage du tarif en francs congolais');

    check('Un réalignement qui changerait la devise est refusé', !$swap['ok'], $swap['message']);

    check(
        'Et la dette garde sa monnaie d\'origine',
        (string) db_value(
            'SELECT currency FROM student_fees WHERE id = :i AND school_id = :s',
            ['i' => $lineB, 's' => $schoolId]
        ) === 'USD'
    );

    // =================================================================
    //  REMISES
    // =================================================================
    check(
        'Une remise sans motif est refusée',
        !finance_service_set_discount($lineB, 5.0, '')['ok']
    );

    check(
        'Une remise supérieure à la dette est refusée',
        !finance_service_set_discount($lineB, 5000.0, 'Bourse')['ok']
    );

    // =================================================================
    //  ANNULATION ET RÉTABLISSEMENT
    //
    //  Annuler ne supprime pas. Mais l'annulation était sans retour :
    //  un clic de trop faisait perdre une somme réellement due, car la
    //  réaffectation ne recrée jamais une dette annulée — à raison,
    //  sinon elle ressusciterait les annulations volontaires.
    // =================================================================
    check(
        'Annuler sans motif suffisant est refusé',
        !finance_service_cancel_line($lineB, 'test')['ok']
    );

    check(
        'Annuler avec motif réussit',
        finance_service_cancel_line($lineB, 'Eleve parti en cours d annee')['ok']
    );

    check(
        'La dette annulée EXISTE encore, avec son motif',
        db_one(
            'SELECT is_cancelled FROM student_fees WHERE id = :i AND school_id = :s',
            ['i' => $lineB, 's' => $schoolId]
        ) !== null
    );

    check(
        'Elle sort du total dû',
        !array_key_exists('USD', finance_repo_due_by_currency($enroll[1]))
            || abs(finance_repo_due_by_currency($enroll[1])['USD']) < 0.01
    );

    check(
        'La réaffectation ne la ressuscite pas',
        finance_service_assign($yearId)['created'] === 0
            && (int) db_value(
                'SELECT is_cancelled FROM student_fees WHERE id = :i AND school_id = :s',
                ['i' => $lineB, 's' => $schoolId]
            ) === 1
    );

    $restored = finance_service_restore_line($lineB, 'Annulation faite par erreur au guichet');

    check('Mais elle peut être rétablie, avec motif', $restored['ok'], $restored['message']);

    check(
        'Le rétablissement rend le montant GELÉ, pas le tarif du jour',
        abs((float) db_value(
            'SELECT amount_due FROM student_fees WHERE id = :i AND school_id = :s',
            ['i' => $lineB, 's' => $schoolId]
        ) - 20.0) < 0.01
    );

    // =================================================================
    //  UN INSCRIT SANS DETTE N'EST PAS UN ÉLÈVE EN RÈGLE
    //
    //  Le même piège qu'en phase 4D : l'absence de donnée se lit comme
    //  « rien à devoir ». Elle doit se compter, donc se voir.
    // =================================================================
    $unbilled = students_service_enroll_new(
        ['last_name' => 'OUBLIE', 'first_name' => 'Cinq', 'gender' => 'M'],
        $yearId,
        $classA
    );

    $situation = finance_repo_classroom_situation($classA);
    $missing   = array_filter($situation, static fn (array $r): bool => (int) $r['fee_count'] === 0);

    check(
        'Un inscrit jamais facturé est compté à part',
        count($missing) === 1,
        count($missing) . ' non facturé(s) sur ' . count($situation) . ' inscrits'
    );

    $overview = finance_repo_year_overview($yearId);

    foreach ($overview as $row) {
        if ((int) $row['classroom_id'] === $classA) {
            check(
                'Et le tableau de l\'année le remonte à la direction',
                (int) $row['without_fees'] === 1,
                $row['without_fees'] . ' sur ' . $row['student_count']
            );
        }
    }

    // =================================================================
    //  PÉRIMÈTRE : UN PARENT NE VOIT QUE SES ENFANTS
    //
    //  finance.view est accordée à PARENT depuis la phase 1. Sans cette
    //  couche, un parent lirait la situation financière de toutes les
    //  familles de l'établissement.
    // =================================================================
    $guardianId = tenant_insert('guardians', [
        'uuid' => str_uuid(), 'last_name' => 'TUTEUR', 'first_name' => 'Alpha',
        'phone' => '+243900000077',
    ]);

    db_query(
        'INSERT INTO student_guardians (school_id, student_id, guardian_id, relationship, is_primary)
         VALUES (:s, :st, :g, :r, 1)',
        [
            's'  => $schoolId,
            'st' => (int) db_value(
                'SELECT student_id FROM enrollments WHERE id = :e AND school_id = :sc',
                ['e' => $enroll[0], 'sc' => $schoolId]
            ),
            'g'  => $guardianId,
            'r'  => 'pere',
        ]
    );

    $parentId = act_as($schoolId, 'PARENT');

    db_query(
        'UPDATE guardians SET user_id = :u WHERE id = :g AND school_id = :s',
        ['u' => $parentId, 'g' => $guardianId, 's' => $schoolId]
    );

    auth_user(true);
    perm_all(true);

    check('Le parent détient bien finance.view', can('finance.view'));

    check(
        'Il consulte la situation de SON enfant',
        finance_can_view_enrollment($enroll[0])
    );

    check(
        'Il ne consulte PAS celle d\'un autre élève',
        !finance_can_view_enrollment($enroll[2])
    );

    check(
        'Ni l\'état financier de la classe entière',
        !students_can_view_classroom($classA)
    );

    // =================================================================
    //  ISOLATION MULTI-ÉCOLE
    // =================================================================
    $otherSchool = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'FIN-B', 'slug' => 'fin-b',
        'name' => 'Autre école', 'status' => 'active',
    ], true);
    $createdSchools[] = $otherSchool;

    act_as($otherSchool, 'DIRECTION');
    tenant_set($otherSchool);

    check(
        'Une autre école ne voit pas la grille tarifaire de la première',
        finance_repo_fees($yearId) === []
    );

    check(
        'Ni une de ses dettes',
        finance_repo_student_fee($lineB) === null
    );

    check(
        'Et ne peut pas y accorder de remise',
        !finance_service_set_discount($lineB, 5.0, 'Tentative depuis une autre ecole')['ok']
    );

    check(
        'Ni l\'annuler',
        !finance_service_cancel_line($lineB, 'Tentative depuis une autre ecole')['ok']
    );
} finally {
    foreach ($createdSchools as $id) {
        db_query('DELETE FROM schools WHERE id = :id', ['id' => $id], true);
    }
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
