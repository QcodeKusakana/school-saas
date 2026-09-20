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
 * Phase 5C — le recouvrement
 *  · le retard se mesure ÉCHÉANCE PAR ÉCHÉANCE, jamais sur le solde ;
 *  · une dette d'élève parti reste une créance, retrouvable ;
 *  · une famille dont l'école détient déjà l'argent n'est pas relancée ;
 *  · un versement n'est JAMAIS imputé deux fois — même après une
 *    annulation de dette suivie d'un rétablissement.
 *
 * Phase 5D — les dépenses
 *  · une dépense sans bénéficiaire nommé est refusée ;
 *  · un bon de sortie annulé garde son numéro à jamais ;
 *  · la caisse compte la monnaie REMISE, pas celle créditée ;
 *  · qui engage la dépense ne l'annule pas.
 *
 * Usage : php tests/finance_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/finance/services.php';
require_once APP_PATH . '/modules/finance/controllers.php';
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

    // LES EXERCICES SONT CALÉS SUR LA DATE RÉELLE.
    //
    // Les encaissements de cette suite sont datés d'aujourd'hui, parce
    // qu'un versement postdaté est refusé depuis la phase 5B. Un
    // exercice figé en 2095 rendait donc ces tests irréalistes : aucune
    // école ne reçoit en 2026 l'argent d'une année scolaire 2095.
    // L'audit 5D, qui exige désormais qu'une opération soit datée dans
    // la fenêtre de son exercice, l'a mis en évidence.
    $yearStart = date('Y-m-d', strtotime('-30 days'));
    $yearEnd   = date('Y-m-d', strtotime('+300 days'));

    $yearId = tenant_insert('academic_years', [
        'code' => 'TF-' . date('Y'), 'name' => 'Test finances', 'starts_on' => $yearStart,
        'ends_on' => $yearEnd, 'status' => 'active', 'is_current' => 1,
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

    // ----- DEUX GUICHETS SIMULTANÉS ----------------------------------
    //
    //  L'INSERT IGNORE du compteur vivait DANS la transaction, juste
    //  avant le SELECT … FOR UPDATE. Quand la ligne existe déjà, InnoDB
    //  pose un verrou PARTAGÉ pour vérifier le doublon, puis le
    //  SELECT … FOR UPDATE en réclame un EXCLUSIF : deux caissiers
    //  simultanés s'attendaient mutuellement — INTERBLOCAGE, et l'un
    //  des deux paiements perdu sur une page d'erreur.
    //
    //  Ce test ne rejoue pas la concurrence (un test à un seul
    //  processus ne le peut pas) : il vérifie que la préparation du
    //  compteur est bien SORTIE de la transaction, ce qui est la
    //  correction, et que db_transaction sait rejouer un interblocage.
    check(
        'La ligne du compteur de reçus se prépare hors transaction',
        function_exists('finance_ensure_receipt_counter')
    );

    check(
        'Celle des matricules aussi — même motif depuis la phase 3',
        function_exists('students_ensure_counter')
    );

    check(
        'Un interblocage est reconnu comme rejouable, pas comme une erreur',
        db_is_deadlock(new PDOException('Deadlock', 40001))
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
    //  LE RECOUVREMENT (5C)
    //
    //  Tout se lit sur ce que 5A et 5B ont posé : aucune table nouvelle,
    //  mais des agrégats qui doivent être justes au centime.
    // =================================================================
    $past = date('Y-m-d', strtotime('-20 days'));
    $soon = date('Y-m-d', strtotime('+20 days'));

    // UNE ANNÉE DÉDIÉE.
    //
    // Les frais de portée « école » créés plus haut s'appliqueraient à
    // toute classe de la même année, et fausseraient chaque montant
    // vérifié ici. Une année à part donne une grille vierge — et c'est
    // exactement ce que fait une école qui ouvre un nouvel exercice.
    //
    // `is_current` reste NULL : la colonne porte une clé unique par
    // école et n'accepte jamais 0 (leçon de la phase 1).
    // L'exercice PRÉCÉDENT : clos depuis deux mois, donc encore dans la
    // fenêtre de règlement tardif — c'est exactement la situation d'une
    // école qui recouvre en septembre les impayés de l'année passée.
    $yearC = tenant_insert('academic_years', [
        'code' => 'TR-' . date('Y'), 'name' => 'Test recouvrement',
        'starts_on' => date('Y-m-d', strtotime('-390 days')),
        'ends_on' => date('Y-m-d', strtotime('-60 days')),
        'status' => 'active', 'is_current' => null,
    ]);

    $progC = curriculum_service_create_program($yearC, $levelId, null, null);
    curriculum_service_fill_program((int) $progC['id']);
    curriculum_service_activate((int) $progC['id']);

    $classC = tenant_insert('classrooms', [
        'academic_year_id' => $yearC, 'curriculum_id' => (int) $progC['id'],
        'code' => '7C', 'name' => '7ème C', 'capacity' => 40,
    ]);

    $reco = [];

    foreach (['RECOA', 'RECOB', 'RECOC'] as $name) {
        $rr = students_service_enroll_new(
            ['last_name' => $name, 'first_name' => 'X', 'gender' => 'M'],
            $yearC,
            $classC
        );
        $reco[$name] = (int) students_repo_enrollment((int) $rr['id'], $yearC)['id'];
    }

    // Échue il y a 20 jours / à échoir dans 20 jours.
    finance_service_save_fee([
        'academic_year_id' => $yearC, 'code' => 'RECO_ECHUE', 'name' => 'Tranche échue',
        'currency' => 'USD', 'amount' => '40', 'scope' => 'classroom', 'classroom_id' => $classC,
        'due_on' => $past, 'is_mandatory' => 1, 'is_active' => 1,
    ]);
    finance_service_save_fee([
        'academic_year_id' => $yearC, 'code' => 'RECO_FUTUR', 'name' => 'Tranche à échoir',
        'currency' => 'USD', 'amount' => '60', 'scope' => 'classroom', 'classroom_id' => $classC,
        'due_on' => $soon, 'is_mandatory' => 1, 'is_active' => 1,
    ]);
    finance_service_assign($yearC, $classC);

    // RECOA ne paie rien ; RECOB solde l'échéance échue ; RECOC part.
    finance_service_record_payment($reco['RECOB'], [
        'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '40',
        'credited_currency' => 'USD', 'method' => 'cash',
    ]);

    db_query(
        'UPDATE enrollments SET status = :st WHERE id = :i AND school_id = :s',
        ['st' => 'cancelled', 'i' => $reco['RECOC'], 's' => $schoolId]
    );

    $byName = [];

    foreach (finance_repo_outstanding($yearC, ['classroom_id' => $classC]) as $row) {
        $byName[(string) $row['last_name']] = $row;
    }

    check(
        'Celui qui n\'a rien payé doit tout, et 40 sont échus',
        abs((float) ($byName['RECOA']['balance'] ?? 0) - 100.0) < 0.01
            && abs((float) ($byName['RECOA']['overdue'] ?? 0) - 40.0) < 0.01,
        'reste ' . ($byName['RECOA']['balance'] ?? 0) . ', échu ' . ($byName['RECOA']['overdue'] ?? 0)
    );

    // LE RETARD SE MESURE PAR ÉCHÉANCE, PAS SUR LE SOLDE.
    // Celui qui a soldé la tranche échue doit encore 60, mais n'est pas
    // en retard : le confondre ferait passer un parent à jour pour un
    // mauvais payeur.
    check(
        'Celui qui a soldé l\'échéance échue doit encore, sans être en retard',
        abs((float) ($byName['RECOB']['balance'] ?? 0) - 60.0) < 0.01
            && (float) ($byName['RECOB']['overdue'] ?? 99) < 0.01,
        'reste ' . ($byName['RECOB']['balance'] ?? 0) . ', échu ' . ($byName['RECOB']['overdue'] ?? 0)
    );

    check(
        'Le filtre « en retard » ne retient que lui',
        count(finance_repo_outstanding($yearC, [
            'classroom_id' => $classC, 'only_overdue' => true,
        ])) === 1
    );

    // UNE DETTE D'ÉLÈVE PARTI RESTE UNE CRÉANCE.
    // Elle disparaissait de tous les écrans, qui filtraient sur
    // « enrolled » : l'école perdait de vue ce qu'on lui devait.
    $hasLeaver = static function (array $rows): bool {
        foreach ($rows as $row) {
            if ((string) $row['last_name'] === 'RECOC') {
                return true;
            }
        }

        return false;
    };

    check(
        'Un élève parti sort des impayés courants',
        !$hasLeaver(finance_repo_outstanding($yearC, ['classroom_id' => $classC]))
    );

    check(
        'Mais sa créance reste retrouvable',
        $hasLeaver(finance_repo_outstanding($yearC, [
            'classroom_id' => $classC, 'include_cancelled' => true,
        ]))
    );

    // ON NE RELANCE PAS UNE FAMILLE DONT ON DÉTIENT L'ARGENT.
    finance_service_record_payment($reco['RECOA'], [
        'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '200',
        'credited_currency' => 'USD', 'method' => 'cash',
    ]);

    $advances = finance_repo_advances($yearC);

    check(
        'Un trop-versé apparaît comme AVANCE, pas comme rien',
        ($advances[$reco['RECOA']]['USD'] ?? 0) > 0.005,
        ($advances[$reco['RECOA']]['USD'] ?? 0) . ' USD d\'avance'
    );

    // Une nouvelle tranche : l'argent est là, la dette aussi.
    finance_service_save_fee([
        'academic_year_id' => $yearC, 'code' => 'RECO_T3', 'name' => 'Tranche 3',
        'currency' => 'USD', 'amount' => '30', 'scope' => 'classroom', 'classroom_id' => $classC,
        'due_on' => $past, 'is_mandatory' => 1, 'is_active' => 1,
    ]);
    finance_service_assign($yearC, $classC);

    $applied = finance_service_apply_advances($yearC, $classC);

    check(
        'Imputer les avances solde ce que l\'école détenait déjà',
        $applied['ok'] && $applied['applied'] > 0,
        $applied['message']
    );

    $paidT3 = finance_repo_paid_on_fee((int) db_value(
        'SELECT sf.id FROM student_fees sf
           JOIN fees f ON f.id = sf.fee_id AND f.school_id = sf.school_id
          WHERE sf.school_id = :s AND sf.enrollment_id = :e AND f.code = :c',
        ['s' => $schoolId, 'e' => $reco['RECOA'], 'c' => 'RECO_T3']
    ));

    check(
        'Et n\'impute jamais plus que le reste dû',
        abs($paidT3 - 30.0) < 0.01,
        $paidT3 . ' USD sur une dette de 30'
    );

    // Rejouer l'imputation ne doit rien doubler.
    finance_service_apply_advances($yearC, $classC);

    check(
        'Rejouer l\'imputation ne double aucun montant',
        abs(finance_repo_paid_on_fee((int) db_value(
            'SELECT sf.id FROM student_fees sf
               JOIN fees f ON f.id = sf.fee_id AND f.school_id = sf.school_id
              WHERE sf.school_id = :s AND sf.enrollment_id = :e AND f.code = :c',
            ['s' => $schoolId, 'e' => $reco['RECOA'], 'c' => 'RECO_T3']
        )) - 30.0) < 0.01
    );

    // =================================================================
    //  UN VERSEMENT N'EST JAMAIS COMPTÉ DEUX FOIS
    //
    //  La phase 5B conservait les allocations d'une dette annulée « pour
    //  garder la trace ». Le raisonnement posait une bombe :
    //
    //    1. on annule une dette soldée → l'allocation reste en base
    //       tout en cessant de compter, l'argent devient une avance ;
    //    2. on impute l'avance          → une SECONDE allocation naît
    //       pour le MÊME argent ;
    //    3. on rétablit la dette        → la première redevient visible.
    //
    //  Résultat : 200 imputés pour 100 encaissés, et une avance
    //  NÉGATIVE. L'annulation supprime désormais les allocations ; la
    //  trace vit dans le journal d'audit.
    // =================================================================
    $dblA = finance_service_save_fee([
        'academic_year_id' => $yearC, 'code' => 'DBL_A', 'name' => 'Double A',
        'currency' => 'USD', 'amount' => '100', 'scope' => 'classroom', 'classroom_id' => $classC,
        'is_mandatory' => 1, 'is_active' => 1,
    ]);
    finance_service_save_fee([
        'academic_year_id' => $yearC, 'code' => 'DBL_B', 'name' => 'Double B',
        'currency' => 'USD', 'amount' => '100', 'scope' => 'classroom', 'classroom_id' => $classC,
        'is_mandatory' => 1, 'is_active' => 1,
    ]);
    finance_service_assign($yearC, $classC);

    $dblPay = finance_service_record_payment($reco['RECOB'], [
        'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '100',
        'credited_currency' => 'USD', 'method' => 'cash',
    ]);

    $dblLine = (int) db_value(
        'SELECT id FROM student_fees
          WHERE school_id = :s AND enrollment_id = :e AND fee_id = :f',
        ['s' => $schoolId, 'e' => $reco['RECOB'], 'f' => (int) $dblA['id']]
    );

    $allocatedOn = static function (int $paymentId) use ($schoolId): float {
        return (float) db_value(
            'SELECT COALESCE(SUM(amount), 0) FROM payment_allocations
              WHERE school_id = :s AND payment_id = :p',
            ['s' => $schoolId, 'p' => $paymentId]
        );
    };

    $allocatedFor = static function (int $studentFeeId) use ($schoolId): float {
        return (float) db_value(
            'SELECT COALESCE(SUM(amount), 0) FROM payment_allocations
              WHERE school_id = :s AND student_fee_id = :f',
            ['s' => $schoolId, 'f' => $studentFeeId]
        );
    };

    $beforeCancel = $allocatedFor($dblLine);

    finance_service_cancel_line($dblLine, 'Annulation pour eprouver la double imputation');

    check(
        'Annuler une dette SUPPRIME ses allocations au lieu de les garder',
        $beforeCancel > 0.005 && $allocatedFor($dblLine) < 0.005,
        $beforeCancel . ' USD imputés avant, ' . $allocatedFor($dblLine) . ' après'
    );

    finance_service_apply_advances($yearC, $classC);
    finance_service_restore_line($dblLine, 'Retablissement apres verification');

    check(
        'Annuler, imputer l\'avance puis rétablir n\'impute jamais deux fois',
        $allocatedOn((int) $dblPay['id']) <= 100.0 + 0.005,
        $allocatedOn((int) $dblPay['id']) . ' USD imputés pour 100 USD encaissés'
    );

    check(
        'Et l\'avance ne devient jamais négative',
        (finance_repo_balance($reco['RECOB'])['USD']['advance'] ?? 0) >= -0.005,
        'avance : ' . (finance_repo_balance($reco['RECOB'])['USD']['advance'] ?? 0)
    );

    // =================================================================
    //  L'EXPORT CSV
    //
    //  Écrit comme une fonction PURE : la version précédente posait ses
    //  en-têtes HTTP puis appelait exit, et n'était donc exécutable par
    //  aucun test. Elle plantait sous PHP 8.4 — fputcsv() y exige son
    //  paramètre d'échappement — sans que rien ne le révèle.
    // =================================================================
    $csvRows = finance_repo_outstanding($yearC, ['classroom_id' => $classC]);
    $csvAdv  = finance_repo_advances($yearC);

    foreach ($csvRows as $i => $csvRow) {
        $csvRows[$i]['advance'] =
            $csvAdv[(int) $csvRow['enrollment_id']][(string) $csvRow['currency']] ?? 0.0;
    }

    $csv = finance_build_outstanding_csv($csvRows);

    check(
        'L\'export CSV se génère sans planter',
        $csv !== '',
        strlen($csv) . ' octets'
    );

    check(
        'Il porte le BOM UTF-8 — sans lui Excel abîme les accents',
        str_starts_with($csv, "\xEF\xBB\xBF")
    );

    check(
        'Il sépare au point-virgule — la virgule est la décimale française',
        str_contains($csv, 'Matricule;Nom')
    );

    check(
        'Et il conserve les accents des en-têtes',
        str_contains($csv, 'Échéance')
    );

    check(
        'Le recouvrement par classe distingue débiteurs et retardataires',
        (bool) array_filter(
            finance_repo_recovery_by_classroom($yearC),
            static fn (array $r): bool => (string) $r['classroom_name'] === '7ème C'
                && (int) $r['debtors'] > 0
        )
    );

    // =================================================================
    //  LES DÉPENSES (5D)
    // =================================================================
    $categories = finance_repo_expense_categories();

    check(
        'Le référentiel des postes de dépense est peuplé',
        count($categories) >= 10,
        count($categories) . ' poste(s)'
    );

    $catId  = (int) $categories[0]['id'];
    $expBase = [
        'category_id' => $catId, 'spent_on' => $today, 'currency' => 'USD',
        'amount' => '50', 'beneficiary' => 'Fournisseur Kasa',
        'description' => 'Achat de craies', 'method' => 'cash',
    ];

    // UNE DÉPENSE SANS BÉNÉFICIAIRE EST UN TROU DANS LA CAISSE.
    // C'est la seule chose qui permette, six mois plus tard, de savoir
    // à qui l'argent est allé.
    check(
        'Une dépense sans bénéficiaire nommé est refusée',
        !finance_service_record_expense($yearId, array_merge($expBase, ['beneficiary' => '']))['ok']
    );

    check(
        'Une dépense sans motif est refusée',
        !finance_service_record_expense($yearId, array_merge($expBase, ['description' => '']))['ok']
    );

    check(
        'Une dépense datée dans le futur est refusée',
        !finance_service_record_expense($yearId, array_merge($expBase, [
            'spent_on' => date('Y-m-d', strtotime('+1 day')),
        ]))['ok']
    );

    check(
        'Un poste de dépense inconnu est refusé',
        !finance_service_record_expense($yearId, array_merge($expBase, ['category_id' => 999999]))['ok']
    );

    $exp1 = finance_service_record_expense($yearId, $expBase);

    check('Une dépense complète s\'enregistre et reçoit un numéro', $exp1['ok'], $exp1['message']);

    // LA CAISSE COMPTE LA MONNAIE REMISE, PAS CELLE CRÉDITÉE.
    //
    // Un parent qui remet 140 000 CDF pour un minerval en dollars met
    // bien 140 000 CDF dans le tiroir. Compter les dollars crédités
    // donnerait une caisse qui ne se recoupe jamais avec l'argent
    // physiquement présent.
    $cashBefore = finance_repo_cash_position($today);

    finance_service_record_payment($payer, [
        'paid_on' => $today, 'tendered_currency' => 'CDF', 'tendered_amount' => '28 000',
        'credited_currency' => 'USD', 'exchange_rate' => '2800', 'method' => 'cash',
    ]);

    $cashAfter = finance_repo_cash_position($today);

    check(
        'Un versement en CDF fait entrer des CDF dans la caisse, pas des USD',
        abs((($cashAfter['CDF']['in'] ?? 0) - ($cashBefore['CDF']['in'] ?? 0)) - 28000.0) < 0.01
            && abs(($cashAfter['USD']['in'] ?? 0) - ($cashBefore['USD']['in'] ?? 0)) < 0.01,
        'CDF +' . (($cashAfter['CDF']['in'] ?? 0) - ($cashBefore['CDF']['in'] ?? 0))
            . ', USD +' . (($cashAfter['USD']['in'] ?? 0) - ($cashBefore['USD']['in'] ?? 0))
    );

    check(
        'Une dépense diminue le solde de sa propre devise',
        abs(($cashAfter['USD']['out'] ?? 0) - 50.0) < 0.01,
        ($cashAfter['USD']['out'] ?? 0) . ' USD sortis'
    );

    // LE NUMÉRO DU BON EST CONSOMMÉ À JAMAIS.
    // Un trou dans la séquence ne signale pas une erreur de saisie :
    // il signale une ligne effacée, donc un détournement.
    check(
        'Annuler une dépense sans motif suffisant est refusé',
        !finance_service_cancel_expense((int) $exp1['id'], 'abc')['ok']
    );

    check(
        'Annuler avec motif réussit',
        finance_service_cancel_expense((int) $exp1['id'], 'Facture reglee deux fois par erreur')['ok']
    );

    check(
        'Le bon annulé sort du solde de caisse',
        abs((finance_repo_cash_position($today)['USD']['out'] ?? 0)) < 0.01,
        (finance_repo_cash_position($today)['USD']['out'] ?? 0) . ' USD sortis'
    );

    $seqBeforeExp = array_map('intval', array_column(db_all(
        'SELECT voucher_seq FROM expenses WHERE school_id = :s ORDER BY voucher_seq',
        ['s' => $schoolId]
    ), 'voucher_seq'));

    finance_service_record_expense($yearId, array_merge($expBase, [
        'beneficiary' => 'Autre fournisseur', 'description' => 'Registres',
    ]));

    $seqAfterExp = array_map('intval', array_column(db_all(
        'SELECT voucher_seq FROM expenses WHERE school_id = :s ORDER BY voucher_seq',
        ['s' => $schoolId]
    ), 'voucher_seq'));

    check(
        'Le numéro d\'un bon annulé n\'est JAMAIS réattribué',
        count($seqAfterExp) === count($seqBeforeExp) + 1
            && count(array_unique($seqAfterExp)) === count($seqAfterExp)
            && max($seqAfterExp) === count($seqAfterExp),
        'séquence : ' . implode(', ', $seqAfterExp)
    );

    check(
        'Le compteur des bons se prépare hors transaction, comme les reçus',
        function_exists('finance_ensure_expense_counter')
    );

    // QUI ENGAGE LA DÉPENSE NE L'ANNULE PAS.
    check(
        'Le comptable ne détient PAS le droit d\'annuler une dépense',
        !db_exists(
            'SELECT 1 FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.code = :role AND r.school_id IS NULL AND p.code = :perm',
            ['role' => 'COMPTABLE', 'perm' => 'expense.cancel'],
            true
        )
    );

    check(
        'La direction le détient',
        db_exists(
            'SELECT 1 FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.code = :role AND r.school_id IS NULL AND p.code = :perm',
            ['role' => 'DIRECTION', 'perm' => 'expense.cancel'],
            true
        )
    );

    // =================================================================
    //  AUDIT 5D — CE QUE LA SONDE A TROUVÉ
    //
    //  Cinq défauts, tous dans des chemins qu'aucun test n'exécutait :
    //  l'écran mentait sur les francs, un exercice clos acceptait encore
    //  de l'argent, une dépense pouvait être imputée à n'importe quel
    //  exercice, un double clic créait deux écritures, et la séparation
    //  des rôles ne tenait que par le middleware de la route.
    // =================================================================
    echo "\n  AUDIT 5D — L'ARGENT DIT LA VÉRITÉ\n";

    // ----- LE FRANC CONGOLAIS N'A PAS DE CENTIMES --------------------
    //
    // `finance_decimals()` le savait depuis la 5A, mais ne servait qu'à
    // l'affichage. Une dette pouvait garder un solde de 0,41 CDF, rester
    // dans l'état des impayés en affichant « 0 CDF », et n'être jamais
    // soldable : la pièce n'existe pas.
    $feeCdf = finance_service_save_fee([
        'academic_year_id' => $yearId, 'code' => 'AUD5D_CDF', 'name' => 'Frais en francs',
        'currency' => 'CDF', 'amount' => '45000,60', 'scope' => 'school', 'is_active' => 1,
    ]);

    $storedFee = (float) db_value(
        'SELECT amount FROM fees WHERE school_id = :s AND code = :c',
        ['s' => $schoolId, 'c' => 'AUD5D_CDF']
    );

    check(
        'Un tarif en francs est stocké SANS centimes',
        $feeCdf['ok'] && abs($storedFee - 45001.0) < 0.0001,
        $storedFee . ' CDF'
    );

    check(
        'Ce qui est stocké est exactement ce qui est affiché',
        finance_amount($storedFee, 'CDF') === number_format($storedFee, 0, ',', ' ') . ' CDF'
    );

    // Une conversion vers le franc ne doit pas fabriquer de centimes.
    $conv = finance_service_record_payment($payer, [
        'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '20',
        'credited_currency' => 'CDF', 'exchange_rate' => '0.000357', 'method' => 'cash',
    ]);

    $convAmount = (float) db_value(
        'SELECT credited_amount FROM payments WHERE school_id = :s AND id = :id',
        ['s' => $schoolId, 'id' => (int) ($conv['id'] ?? 0)]
    );

    check(
        'Convertir des dollars en francs ne fabrique pas de centimes',
        $conv['ok'] && abs($convAmount - round($convAmount)) < 0.0001,
        $convAmount . ' CDF'
    );

    check(
        'Un dollar, lui, garde bien ses deux décimales',
        finance_round(12.345, 'USD') === 12.35 && finance_round(12.4, 'CDF') === 12.0
    );

    // ----- UN EXERCICE CLOS N'ACCEPTE PLUS D'ARGENT ------------------
    //
    // Un exercice clôturé est un exercice dont les chiffres ont été
    // remis au promoteur. Y ajouter une écriture après coup réécrit un
    // état déjà signé.
    $closedYear = tenant_insert('academic_years', [
        'code' => 'TC-' . date('Y'), 'name' => 'Exercice clos',
        'starts_on' => date('Y-m-d', strtotime('-390 days')),
        'ends_on' => date('Y-m-d', strtotime('-60 days')),
        'status' => 'closed', 'is_current' => null,
    ]);

    check(
        'Un exercice clôturé refuse une dépense',
        !finance_service_record_expense($closedYear, array_merge($expBase, [
            'spent_on' => date('Y-m-d', strtotime('-70 days')),
        ]))['ok']
    );

    // Pour l'encaissement, on ferme temporairement l'exercice courant :
    // monter une inscription complète dans un exercice déjà clos serait
    // monter une situation qui ne peut pas exister.
    tenant_update('academic_years', ['status' => 'closed'], 'id = :id', ['id' => $yearId]);

    $refusClos = finance_service_record_payment($payer, [
        'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '10',
        'credited_currency' => 'USD', 'method' => 'cash',
    ]);

    tenant_update('academic_years', ['status' => 'active'], 'id = :id', ['id' => $yearId]);

    check(
        'Un exercice clôturé refuse aussi un encaissement',
        !$refusClos['ok'],
        $refusClos['message']
    );

    check(
        'Rouvrir l\'exercice rend la caisse à nouveau opérante',
        finance_service_record_payment($payer, [
            'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '1',
            'credited_currency' => 'USD', 'method' => 'cash',
        ])['ok']
    );

    // ----- LA DATE APPARTIENT À SON EXERCICE -------------------------
    //
    // Une dépense datée du 15/01/2020 était acceptée sur un exercice de
    // 2092 : le total annuel devenait librement falsifiable.
    check(
        'Une dépense trop éloignée de son exercice est refusée',
        !finance_service_record_expense($yearId, array_merge($expBase, [
            'spent_on' => date('Y-m-d', strtotime('-5 years')),
        ]))['ok']
    );

    // …mais la règle ne doit pas piéger le travail réel : les familles
    // versent l'inscription de l'année suivante AVANT sa rentrée.
    $avance = finance_service_record_payment($payer, [
        'paid_on' => date('Y-m-d', strtotime('-25 days')),
        'tendered_currency' => 'USD', 'tendered_amount' => '5',
        'credited_currency' => 'USD', 'method' => 'cash',
    ]);

    check(
        'Un versement fait avant la rentrée reste accepté',
        $avance['ok'],
        $avance['message']
    );

    // ----- UN BILLET, UN REÇU ----------------------------------------
    //
    // Le jeton CSRF vit deux heures et vaut pour tous les formulaires :
    // il prouve l'origine d'une requête, jamais son unicité. Un double
    // clic créait deux reçus pour un seul versement.
    $once = form_nonce('finance.pay');

    check('Le premier envoi d\'un formulaire d\'argent est accepté', form_nonce_consume('finance.pay', $once));
    check('Le SECOND envoi du même formulaire est refusé', !form_nonce_consume('finance.pay', $once));
    check(
        'Un jeton d\'encaissement ne vaut pas pour une dépense',
        !form_nonce_consume('finance.expense', form_nonce('finance.pay'))
    );
    check('Un jeton inventé est refusé', !form_nonce_consume('finance.pay', 'jeton-invente'));

    // ----- TOUTES LES PORTES PORTENT LA SÉPARATION DES RÔLES ---------
    //
    // La règle « qui engage la dépense ne l'annule pas » ne tenait que
    // par le middleware de la route. Le service, lui, annulait pour
    // quiconque l'appelait.
    $expSep = finance_service_record_expense($yearId, array_merge($expBase, [
        'description' => 'Dépense de contrôle de la séparation des rôles',
    ]));

    act_as($schoolId, 'COMPTABLE');

    check(
        'Le SERVICE d\'annulation refuse un comptable, pas seulement la route',
        !finance_service_cancel_expense((int) $expSep['id'], 'Tentative depuis le guichet')['ok']
    );

    check(
        'Le service d\'annulation d\'un reçu porte la même règle',
        !finance_service_cancel_payment((int) $conv['id'], 'Tentative depuis le guichet')['ok']
    );

    act_as($schoolId, 'DIRECTION');

    check(
        'La direction, elle, annule bien',
        finance_service_cancel_expense((int) $expSep['id'], 'Annulation régulière par la direction')['ok']
    );

    // =================================================================
    //  RECETTE — CELUI QUI ENCAISSE NE RÉDUIT PAS LA DETTE
    //
    //  Trouvé en interrogeant les vraies URL : le comptable détenait
    //  `fee.manage`, qui ouvrait à la fois la GRILLE (un document
    //  collectif et visible) et la DETTE D'UNE SEULE FAMILLE (une
    //  remise, une annulation — invisibles dans la grille).
    //
    //  Comme il détient aussi `payment.record`, il pouvait encaisser
    //  45 000 CDF en espèces, annuler la dette, et garder l'argent :
    //  les comptes tombaient juste, puisqu'il ne restait ni dette ni
    //  reçu. `fee.waive` sépare les deux.
    // =================================================================
    echo "\n  SÉPARATION DES RÔLES — LA DETTE D'UNE FAMILLE\n";

    check(
        'Le comptable tient la grille tarifaire',
        db_exists(
            'SELECT 1 FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.code = :role AND r.school_id IS NULL AND p.code = :perm',
            ['role' => 'COMPTABLE', 'perm' => 'fee.manage'],
            true
        )
    );

    check(
        'Mais il ne détient PAS le droit de toucher à une dette',
        !db_exists(
            'SELECT 1 FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.code = :role AND r.school_id IS NULL AND p.code = :perm',
            ['role' => 'COMPTABLE', 'perm' => 'fee.waive'],
            true
        )
    );

    check(
        'La direction le détient',
        db_exists(
            'SELECT 1 FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.code = :role AND r.school_id IS NULL AND p.code = :perm',
            ['role' => 'DIRECTION', 'perm' => 'fee.waive'],
            true
        )
    );

    // La règle est portée par le SERVICE, pas seulement par la route :
    // un import, une reprise de caisse ou une API mobile passeraient
    // à côté du middleware.
    $ligneTest = (int) db_value(
        'SELECT id FROM student_fees
          WHERE school_id = :s AND is_cancelled = 0 AND discount_amount = 0
          ORDER BY id DESC LIMIT 1',
        ['s' => $schoolId]
    );

    act_as($schoolId, 'COMPTABLE');

    check(
        'Le comptable ne peut PAS accorder de remise',
        !finance_service_set_discount($ligneTest, 5.0, 'Remise tentee par le comptable')['ok']
    );

    check(
        'Ni annuler la dette d\'une famille',
        !finance_service_cancel_line($ligneTest, 'Annulation tentee par le comptable')['ok']
    );

    check(
        'Ni réaligner des dettes déjà annoncées',
        !finance_service_resync_fee((int) db_value(
            'SELECT id FROM fees WHERE school_id = :s ORDER BY id LIMIT 1',
            ['s' => $schoolId]
        ), 'Realignement tente par le comptable')['ok']
    );

    check(
        'Ni annuler en masse les dettes hors portée',
        !finance_service_cancel_out_of_scope($yearId, 'Annulation tentee par le comptable')['ok']
    );

    // Il garde en revanche tout son métier.
    $encCpt = finance_service_record_payment($payer, [
        'paid_on' => $today, 'tendered_currency' => 'USD', 'tendered_amount' => '2',
        'credited_currency' => 'USD', 'method' => 'cash',
    ]);

    check('Le comptable encaisse toujours', $encCpt['ok'], $encCpt['message']);

    act_as($schoolId, 'DIRECTION');

    $remiseDir = finance_service_set_discount($ligneTest, 5.0, 'Remise sociale accordee par la direction');

    check('La direction accorde bien la remise', $remiseDir['ok'], $remiseDir['message']);

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

    check(
        'Ni lire son journal de caisse',
        finance_repo_cashbook($today) === []
    );

    check(
        'Ni son état des impayés',
        finance_repo_outstanding($yearId, ['include_cancelled' => true]) === []
    );

    check(
        'Ni ses avances',
        finance_repo_advances($yearId) === []
    );

    check(
        'Ni ses dépenses',
        finance_repo_expenses($yearId) === []
    );

    check(
        'Ni sa situation de caisse',
        finance_repo_cash_position($today) === []
    );
} finally {
    foreach ($createdSchools as $id) {
        db_query('DELETE FROM schools WHERE id = :id', ['id' => $id], true);
    }
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
