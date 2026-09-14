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
 * Phase 5B — la caisse
 *  · le TAUX est figé sur le paiement, jamais relu ;
 *  · un numéro de reçu est consommé à jamais, même annulé ;
 *  · aucune dette ne se sur-paie, aucun solde ne devient négatif ;
 *  · annuler une dette payée ne fait pas DISPARAÎTRE l'argent.
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
    //  LES PORTÉES « NIVEAU » ET « CLASSE »
    //
    //  Le premier jet de ces tests ne créait qu'un seul niveau : les
    //  deux portées restrictives n'étaient donc jamais exercées, et un
    //  frais de 8e facturé à toute l'école serait passé inaperçu.
    // =================================================================
    $level8  = (int) db_value("SELECT id FROM education_levels WHERE code = 'CTEB_8'", [], true);
    $prog8   = curriculum_service_create_program($yearId, $level8, null, null);
    curriculum_service_fill_program((int) $prog8['id']);
    curriculum_service_activate((int) $prog8['id']);

    $classB = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $prog8['id'],
        'code' => '8A', 'name' => '8ème A', 'capacity' => 40,
    ]);

    $r8 = students_service_enroll_new(
        ['last_name' => 'HUITIEME', 'first_name' => 'Six', 'gender' => 'F'],
        $yearId,
        $classB
    );
    $enroll8 = (int) students_repo_enrollment((int) $r8['id'], $yearId)['id'];

    $labo = finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'LABO8', 'name' => 'Laboratoire 8e',
        'currency' => 'USD', 'amount' => '15', 'scope' => 'level', 'level_id' => $level8,
        'is_mandatory' => 1, 'is_active' => 1,
    ]);

    $sortie = finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'SORTIE7', 'name' => 'Sortie 7ème A',
        'currency' => 'USD', 'amount' => '8', 'scope' => 'classroom', 'classroom_id' => $classA,
        'is_mandatory' => 0, 'is_active' => 1,
    ]);

    finance_service_assign($yearId);

    $countFor = static function (int $feeId, int $classroomId) use ($schoolId): int {
        return (int) db_value(
            'SELECT COUNT(*) FROM student_fees sf
               JOIN enrollments e ON e.id = sf.enrollment_id AND e.school_id = sf.school_id
              WHERE sf.school_id = :s AND sf.fee_id = :f AND e.classroom_id = :c
                AND sf.is_cancelled = 0',
            ['s' => $schoolId, 'f' => $feeId, 'c' => $classroomId]
        );
    };

    check(
        'Un frais de niveau ne touche que son niveau',
        $countFor((int) $labo['id'], $classA) === 0 && $countFor((int) $labo['id'], $classB) === 1,
        $countFor((int) $labo['id'], $classA) . ' en 7ème, ' . $countFor((int) $labo['id'], $classB) . ' en 8ème'
    );

    check(
        'Un frais de classe ne touche que sa classe',
        $countFor((int) $sortie['id'], $classB) === 0 && $countFor((int) $sortie['id'], $classA) > 0,
        $countFor((int) $sortie['id'], $classA) . ' en 7ème, ' . $countFor((int) $sortie['id'], $classB) . ' en 8ème'
    );

    // =================================================================
    //  RÉTRÉCIR UNE PORTÉE LAISSE DES DETTES ORPHELINES
    //
    //  L'affectation ne sait qu'ajouter, et le gel du tarif interdit de
    //  réécrire une dette. Rétrécir la portée d'un frais déjà affecté
    //  laissait donc des classes entières facturées d'un frais qui ne
    //  les concernait plus — sans le moindre signal.
    // =================================================================
    check(
        'Rien n\'est hors portée tant que les portées ne bougent pas',
        finance_repo_out_of_scope($yearId) === []
    );

    finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'SORTIE7', 'name' => 'Sortie 7ème A',
        'currency' => 'USD', 'amount' => '8', 'scope' => 'classroom', 'classroom_id' => $classB,
        'is_mandatory' => 0, 'is_active' => 1,
    ], (int) $sortie['id']);

    $orphans = finance_repo_out_of_scope($yearId);

    check(
        'Rétrécir une portée rend les dettes devenues orphelines VISIBLES',
        count($orphans) > 0,
        count($orphans) . ' dette(s) signalée(s)'
    );

    check(
        'Les annuler en bloc sans motif est refusé',
        !finance_service_cancel_out_of_scope($yearId, 'ab')['ok']
    );

    $bulk = finance_service_cancel_out_of_scope($yearId, 'Portee de la sortie restreinte a la 8e');

    check(
        'Avec motif, elles sont annulées — pas supprimées',
        $bulk['cancelled'] === count($orphans)
            && (int) db_value(
                'SELECT is_cancelled FROM student_fees WHERE id = :i AND school_id = :s',
                ['i' => (int) $orphans[0]['id'], 's' => $schoolId]
            ) === 1,
        $bulk['cancelled'] . ' annulée(s)'
    );

    check(
        'Et il ne reste plus rien hors portée',
        finance_repo_out_of_scope($yearId) === []
    );

    // =================================================================
    //  L'ÉCRAN NE PROPOSE QUE CE QUE L'ACTION TIENT
    //
    //  Le bouton « Réaligner » s'affichait dès qu'une divergence
    //  existait — y compris quand elle portait sur la DEVISE, cas que
    //  le réalignement refuse. L'utilisateur cliquait sur une action
    //  qui échouait à tous les coups.
    // =================================================================
    $cantine = finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'CANTINE', 'name' => 'Cantine',
        'currency' => 'USD', 'amount' => '12', 'scope' => 'school',
        'is_mandatory' => 0, 'is_active' => 1,
    ]);

    finance_service_assign($yearId);

    finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'CANTINE', 'name' => 'Cantine',
        'currency' => 'CDF', 'amount' => '34000', 'scope' => 'school',
        'is_mandatory' => 0, 'is_active' => 1,
    ], (int) $cantine['id']);

    $shown    = finance_service_resync_preview((int) $cantine['id']);
    $mismatch = finance_repo_currency_mismatch((int) $cantine['id']);

    check(
        'Une divergence de devise est détectée à part',
        $shown > 0 && $mismatch === $shown,
        $shown . ' divergente(s), dont ' . $mismatch . ' de devise'
    );

    check(
        'Le bouton n\'est donc pas proposé, et l\'action refuserait bien',
        !finance_service_resync_fee((int) $cantine['id'], 'Verification de la promesse')['ok']
    );

    // =================================================================
    //  LA CAISSE (5B)
    //
    //  Les encaissements se datent du JOUR : postdater fausserait tous
    //  les arrêtés de caisse déjà signés, et la garde le refuse.
    // =================================================================
    $today = date('Y-m-d');

    // Un élève neuf, aux dettes intactes, pour ne pas dépendre de tout
    // ce que les blocs précédents ont modifié.
    $rc = students_service_enroll_new(
        ['last_name' => 'CAISSE', 'first_name' => 'Sept', 'gender' => 'M'],
        $yearId,
        $classA
    );
    $payer = (int) students_repo_enrollment((int) $rc['id'], $yearId)['id'];

    $tarif = finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'TRANCHE1', 'name' => 'Tranche 1',
        'currency' => 'USD', 'amount' => '50', 'scope' => 'school',
        'due_on' => '2095-10-15', 'is_mandatory' => 1, 'is_active' => 1,
    ]);
    finance_service_assign($yearId, $classA);

    // ----- LE TAUX EST FIGÉ SUR LE PAIEMENT --------------------------
    $pay = finance_service_record_payment($payer, [
        'paid_on'           => $today,
        'tendered_currency' => 'CDF',  'tendered_amount' => '140 000',
        'credited_currency' => 'USD',  'exchange_rate'   => '2800',
        'method'            => 'cash',
    ]);

    check('Un paiement en CDF solde une dette en USD', $pay['ok'], $pay['message']);

    $stored = db_one(
        'SELECT tendered_amount, exchange_rate, credited_amount
           FROM payments WHERE id = :i AND school_id = :s',
        ['i' => (int) $pay['id'], 's' => $schoolId]
    );

    check(
        'Les trois montants sont conservés : remis, taux, crédité',
        abs((float) $stored['tendered_amount'] - 140000.0) < 0.01
            && abs((float) $stored['exchange_rate'] - 2800.0) < 0.000001
            && abs((float) $stored['credited_amount'] - 50.0) < 0.01,
        $stored['tendered_amount'] . ' CDF @ ' . $stored['exchange_rate'] . ' → ' . $stored['credited_amount'] . ' USD'
    );

    // Changer le taux de l'école ne doit RIEN réécrire.
    db_query(
        'UPDATE school_settings SET setting_value = :v
          WHERE school_id = :s AND setting_key = :k',
        ['v' => '3500', 's' => $schoolId, 'k' => 'finance.usd_rate']
    );
    school_settings_all(true);

    check(
        'Changer le taux de l\'école ne réécrit aucun reçu',
        abs((float) db_value(
            'SELECT exchange_rate FROM payments WHERE id = :i AND school_id = :s',
            ['i' => (int) $pay['id'], 's' => $schoolId]
        ) - 2800.0) < 0.000001,
        'taux courant : ' . finance_default_rate()
    );

    // ----- LA RÉPARTITION --------------------------------------------
    $balance = finance_repo_balance($payer);

    check(
        'Le versement solde la dette la plus ancienne',
        abs(($balance['USD']['paid'] ?? 0) - 50.0) < 0.01,
        'payé : ' . ($balance['USD']['paid'] ?? 0) . ' USD'
    );

    // ----- ON NE SUR-PAIE PAS ----------------------------------------
    $over = finance_service_record_payment($payer, [
        'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '500',
        'credited_currency' => 'USD', 'method' => 'cash',
    ]);

    $overpaid = false;

    foreach (finance_repo_fees_with_paid($payer) as $l) {
        if ((float) $l['paid'] > (float) $l['amount_net'] + 0.005) {
            $overpaid = true;
        }
    }

    check('Aucune dette ne reçoit plus que son reste dû', !$overpaid);

    check(
        'Le trop-versé reste une AVANCE, et il est annoncé',
        ($over['unallocated'] ?? 0) > 0.005 && str_contains($over['message'], 'avance'),
        $over['message']
    );

    // ----- LE NUMÉRO DE REÇU EST CONSOMMÉ À JAMAIS -------------------
    check(
        'Annuler sans motif suffisant est refusé',
        !finance_service_cancel_payment((int) $over['id'], 'abc')['ok']
    );

    check(
        'Annuler avec motif réussit',
        finance_service_cancel_payment((int) $over['id'], 'Erreur de guichet sur le montant')['ok']
    );

    check(
        'Le reçu annulé ne compte plus dans le solde',
        abs((finance_repo_balance($payer)['USD']['paid'] ?? 0) - 50.0) < 0.01,
        'payé : ' . (finance_repo_balance($payer)['USD']['paid'] ?? 0) . ' USD'
    );

    $seqBefore = array_map('intval', array_column(db_all(
        'SELECT receipt_seq FROM payments WHERE school_id = :s ORDER BY receipt_seq',
        ['s' => $schoolId]
    ), 'receipt_seq'));

    finance_service_record_payment($payer, [
        'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '5',
        'credited_currency' => 'USD', 'method' => 'cash',
    ]);

    $seqAfter = array_map('intval', array_column(db_all(
        'SELECT receipt_seq FROM payments WHERE school_id = :s ORDER BY receipt_seq',
        ['s' => $schoolId]
    ), 'receipt_seq'));

    check(
        'Le numéro d\'un reçu annulé n\'est JAMAIS réattribué',
        count($seqAfter) === count($seqBefore) + 1
            && count(array_unique($seqAfter)) === count($seqAfter)
            && max($seqAfter) === count($seqAfter),
        'séquence : ' . implode(', ', $seqAfter)
    );

    // ----- CONTRÔLES DE SAISIE ---------------------------------------
    check(
        'Un encaissement daté dans le futur est refusé',
        !finance_service_record_payment($payer, [
            'paid_on' => date('Y-m-d', strtotime('+1 day')), 'tendered_currency' => 'USD',
            'tendered_amount' => '10', 'credited_currency' => 'USD', 'method' => 'cash',
        ])['ok']
    );

    check(
        'Une conversion sans taux est refusée',
        !finance_service_record_payment($payer, [
            'paid_on' => $today, 'tendered_currency' => 'CDF', 'tendered_amount' => '50000',
            'credited_currency' => 'USD', 'method' => 'cash',
        ])['ok']
    );

    check(
        'Un montant remis nul est refusé',
        !finance_service_record_payment($payer, [
            'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '0',
            'credited_currency' => 'USD', 'method' => 'cash',
        ])['ok']
    );

    check(
        'Un montant tapé à la française est lu correctement',
        abs(finance_parse_amount('1 250,50') - 1250.50) < 0.001
            && abs(finance_parse_amount('1.250,50') - 1250.50) < 0.001
            && abs(finance_parse_amount('1250.50') - 1250.50) < 0.001
    );

    // =================================================================
    //  LA FRONTIÈRE ENTRE DETTES ET PAIEMENTS
    //
    //  Les deux moitiés du module ont été construites séparément. Les
    //  trois défauts suivants vivaient tous à leur jointure.
    // =================================================================
    $payerLine = (int) db_value(
        'SELECT id FROM student_fees
          WHERE school_id = :s AND enrollment_id = :e AND fee_id = :f',
        ['s' => $schoolId, 'e' => $payer, 'f' => (int) $tarif['id']]
    );

    check(
        'Une remise qui passerait sous le montant encaissé est refusée',
        !finance_service_set_discount($payerLine, 40.0, 'Enfant du personnel')['ok'],
        'la dette a déjà encaissé ' . finance_repo_paid_on_fee($payerLine) . ' USD'
    );

    // Réaligner le tarif SOUS ce qui a été encaissé : le montant dû est
    // ramené à l'encaissé, jamais en dessous — sinon l'école devrait de
    // l'argent à la famille sans avoir décidé de rembourser.
    finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'TRANCHE1', 'name' => 'Tranche 1',
        'currency' => 'USD', 'amount' => '20', 'scope' => 'school',
        'due_on' => '2095-10-15', 'is_mandatory' => 1, 'is_active' => 1,
    ], (int) $tarif['id']);

    $lowered = finance_service_resync_fee((int) $tarif['id'], 'Correction du tarif de la tranche 1');

    check(
        'Un réalignement sous le montant encaissé plancher sur l\'encaissé',
        ($lowered['floored'] ?? 0) >= 1
            && (finance_repo_balance($payer)['USD']['balance'] ?? 0) >= -0.005,
        'solde : ' . (finance_repo_balance($payer)['USD']['balance'] ?? 0) . ' USD'
    );

    check(
        'Et les dossiers concernés sont NOMMÉS, pas traités en silence',
        str_contains($lowered['message'], 'remboursement')
    );

    // Annuler une dette déjà payée : l'argent ne disparaît pas.
    $paidBefore = finance_repo_paid_on_fee($payerLine);
    $cancelPaid = finance_service_cancel_line($payerLine, 'Eleve parti en cours d annee');

    check(
        'Annuler une dette payée le DIT, au lieu de faire disparaître l\'argent',
        $cancelPaid['ok'] && str_contains($cancelPaid['message'], 'AVANCE'),
        $cancelPaid['message']
    );

    check(
        'Et les sommes reçues basculent en avance, sans s\'évaporer',
        abs((finance_repo_balance($payer)['USD']['advance'] ?? 0) - ($paidBefore + 5.0)) < 0.01,
        'avance : ' . (finance_repo_balance($payer)['USD']['advance'] ?? 0) . ' USD'
    );

    finance_service_restore_line($payerLine, 'Retablissement apres verification');

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

    // MAIS IL DOIT AVOIR UN CHEMIN.
    //
    // Sans classe dans son périmètre, /finances lui renvoyait une page
    // vide : une entrée de menu qui ne menait nulle part. Le périmètre
    // bascule alors sur l'ÉLÈVE.
    $mine = finance_repo_scoped_enrollments($yearId);

    check(
        'Il atteint son enfant depuis l\'accueil des finances',
        count($mine) === 1 && (int) $mine[0]['enrollment_id'] === $enroll[0],
        count($mine) . ' inscription(s) dans son périmètre'
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

    check(
        'Ni voir un de ses reçus',
        finance_repo_payment((int) $pay['id']) === null
    );

    check(
        'Ni encaisser sur un de ses élèves',
        !finance_service_record_payment($payer, [
            'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '10',
            'credited_currency' => 'USD', 'method' => 'cash',
        ])['ok']
    );

    check(
        'Ni annuler un de ses reçus',
        !finance_service_cancel_payment((int) $pay['id'], 'Tentative depuis une autre ecole')['ok']
    );
} finally {
    foreach ($createdSchools as $id) {
        db_query('DELETE FROM schools WHERE id = :id', ['id' => $id], true);
    }
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
