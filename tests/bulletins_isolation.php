<?php
/**
 * Phase 4C — bulletins : calcul, classement, publication.
 *
 * Ce que ces tests protègent
 * --------------------------
 * Le bulletin décide du passage de classe. Quatre choses doivent tenir :
 *
 *  · les totaux s'appuient sur les maxima FIGÉS des cotes, jamais sur le
 *    programme courant ;
 *  · le traitement des absences suit le paramètre de l'établissement, et
 *    change effectivement le résultat ;
 *  · les branches hors classement ne pèsent pas sur le rang ;
 *  · le rang se FIGE à la publication — ajouter une cote ensuite ne doit
 *    plus modifier un document déjà remis aux parents.
 *
 * Usage : php tests/bulletins_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/teachers/services.php';
require APP_PATH . '/modules/grades/services.php';
require APP_PATH . '/modules/bulletins/services.php';
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
        'username'      => 'bul.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'bul' . $counter . '@example.test',
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

function switch_to(int $userId, int $schoolId): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($schoolId);
}

echo "\n  BULLETINS — CALCUL, CLASSEMENT ET PUBLICATION\n";
echo "  ──────────────────────────────────────────────────────\n\n";

$createdSchools = [];

try {
    // -----------------------------------------------------------------
    //  Mise en place
    // -----------------------------------------------------------------
    $schoolId = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'BUL-A', 'slug' => 'bul-a',
        'name' => 'École des Bulletins', 'status' => 'active',
        'director_name' => 'KAYEMBE Sylvie',
    ], true);
    $createdSchools[] = $schoolId;

    db_query(
        'INSERT INTO school_cycles (school_id, cycle_id, is_active)
         SELECT :s, id, 1 FROM education_cycles',
        ['s' => $schoolId]
    );

    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    $yearId = tenant_insert('academic_years', [
        'code' => '2085-2086', 'name' => 'Test bulletins', 'starts_on' => '2085-09-01',
        'ends_on' => '2086-07-31', 'status' => 'active', 'is_current' => 1,
    ]);

    curriculum_service_import_national();
    curriculum_service_create_standard_periods($yearId);

    $levelId = (int) db_value("SELECT id FROM education_levels WHERE code = 'PRI_5'", [], true);
    $program = curriculum_service_create_program($yearId, $levelId, null, null);
    curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    // On réduit le programme à trois branches pour rendre les totaux
    // vérifiables à la main. Les branches retirées n'ont aucune cote.
    $all = tenant_all('curriculum_subjects', 'curriculum_id = :c ORDER BY id', ['c' => (int) $program['id']]);
    $keep = array_slice($all, 0, 3);
    $keepIds = array_map(static fn (array $r): int => (int) $r['id'], $keep);

    foreach ($all as $row) {
        if (!in_array((int) $row['id'], $keepIds, true)) {
            tenant_delete('curriculum_subjects', 'id = :id', ['id' => (int) $row['id']]);
        }
    }

    // Maxima maîtrisés : 20, 20, et une branche hors classement à 20.
    foreach ($keepIds as $index => $id) {
        tenant_update('curriculum_subjects', [
            'max_points'         => 20,
            'counts_for_ranking' => $index === 2 ? 0 : 1,
        ], 'id = :id', ['id' => $id]);
    }

    [$subjA, $subjB, $subjNoRank] = $keepIds;

    $classroom = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '5A', 'name' => '5ème primaire A', 'capacity' => 40,
    ]);

    $periods = [];

    foreach (['P1', 'P2', 'EX1', 'P3', 'P4', 'EX2'] as $code) {
        $periods[$code] = (int) tenant_one(
            'grade_periods',
            'academic_year_id = :y AND code = :c',
            ['y' => $yearId, 'c' => $code]
        )['id'];
    }

    check('Programme réduit à 3 branches notées sur 20', count($keepIds) === 3);

    // Trois élèves.
    $enroll = [];

    foreach ([['ALPHA', 'Un', 'M'], ['BETA', 'Deux', 'F'], ['GAMMA', 'Trois', 'M']] as $s) {
        $r = students_service_enroll_new(
            ['last_name' => $s[0], 'first_name' => $s[1], 'gender' => $s[2]],
            $yearId,
            $classroom
        );
        $enroll[] = (int) students_repo_enrollment((int) $r['id'], $yearId)['id'];
    }

    check('Trois élèves inscrits', count($enroll) === 3);

    // =================================================================
    //  CALCUL DES TOTAUX
    //
    //  Branche sur 20 : P1 ×1 = 20, P2 ×1 = 20, EX1 ×2 = 40.
    //  Premier semestre = 80 points de maximum par branche.
    // =================================================================
    grades_service_save_sheet($classroom, $subjA, $periods['P1'], [$enroll[0] => ['points' => 15, 'is_absent' => false]]);
    grades_service_save_sheet($classroom, $subjA, $periods['P2'], [$enroll[0] => ['points' => 10, 'is_absent' => false]]);
    grades_service_save_sheet($classroom, $subjA, $periods['EX1'], [$enroll[0] => ['points' => 30, 'is_absent' => false]]);

    $report = bulletins_service_compute($enroll[0]);
    $s1     = $report['totals']['S1'];

    check(
        'Maximum du premier semestre : 20 + 20 + 40 = 80',
        abs($s1['max'] - 80.0) < 0.001,
        (string) $s1['max']
    );

    check(
        'Total obtenu : 15 + 10 + 30 = 55',
        abs($s1['points'] - 55.0) < 0.001,
        (string) $s1['points']
    );

    check(
        'Pourcentage : 55 / 80 = 68,75 %',
        abs((float) $s1['percentage'] - 68.75) < 0.01,
        (string) $s1['percentage']
    );

    // =================================================================
    //  UNE COTE NON SAISIE NE PÈSE PAS COMME UN ÉCHEC
    // =================================================================
    check(
        'Les branches non corrigées sont signalées, pas comptées zéro',
        $report['missing'] > 0 && abs($s1['max'] - 80.0) < 0.001,
        $report['missing'] . ' manquante(s), maximum ' . $s1['max']
    );

    // =================================================================
    //  TRAITEMENT DES ABSENCES
    // =================================================================
    grades_service_save_sheet($classroom, $subjB, $periods['P1'], [$enroll[0] => ['points' => 18, 'is_absent' => false]]);
    grades_service_save_sheet($classroom, $subjB, $periods['P2'], [$enroll[0] => ['points' => null, 'is_absent' => true]]);

    school_setting_set('grading.absence_mode', 'excluded');
    school_settings_all(true);

    $excluded = bulletins_service_compute($enroll[0]);
    $bExcl    = $excluded['subjects'][$subjB]['groups']['S1'];

    check(
        'Mode « exclusion » : l\'absence sort du total ET du maximum',
        abs($bExcl['points'] - 18.0) < 0.001 && abs($bExcl['max'] - 20.0) < 0.001,
        $bExcl['points'] . ' / ' . $bExcl['max']
    );

    school_setting_set('grading.absence_mode', 'zero');
    school_settings_all(true);

    $zero  = bulletins_service_compute($enroll[0]);
    $bZero = $zero['subjects'][$subjB]['groups']['S1'];

    check(
        'Mode « zéro » : l\'absence compte 0 sur le maximum plein',
        abs($bZero['points'] - 18.0) < 0.001 && abs($bZero['max'] - 40.0) < 0.001,
        $bZero['points'] . ' / ' . $bZero['max']
    );

    check(
        'Le mode change bien le résultat',
        abs($bExcl['max'] - $bZero['max']) > 0.001
    );

    school_setting_set('grading.absence_mode', 'excluded');
    school_settings_all(true);

    // =================================================================
    //  MAXIMUM FIGÉ
    // =================================================================
    db_query(
        'UPDATE curriculum_subjects SET max_points = 99 WHERE school_id = :s AND id = :id',
        ['s' => $schoolId, 'id' => $subjA]
    );

    $afterChange = bulletins_service_compute($enroll[0]);

    check(
        'Le bulletin conserve le maximum FIGÉ, pas celui du programme',
        abs($afterChange['totals']['S1']['max'] - $excluded['totals']['S1']['max']) < 0.001,
        $afterChange['totals']['S1']['max'] . ' contre ' . $excluded['totals']['S1']['max']
    );

    db_query(
        'UPDATE curriculum_subjects SET max_points = 20 WHERE school_id = :s AND id = :id',
        ['s' => $schoolId, 'id' => $subjA]
    );

    // =================================================================
    //  BRANCHE HORS CLASSEMENT
    // =================================================================
    grades_service_save_sheet($classroom, $subjNoRank, $periods['P1'], [$enroll[0] => ['points' => 20, 'is_absent' => false]]);

    $withNoRank = bulletins_service_compute($enroll[0]);
    $t          = $withNoRank['totals']['S1'];

    check(
        'La branche hors classement figure au total général',
        $t['points'] > $t['ranking_points'],
        $t['points'] . ' contre ' . $t['ranking_points'] . ' au classement'
    );

    check(
        'Mais elle ne pèse pas sur le pourcentage de classement',
        abs((float) $t['percentage'] - (float) $t['ranking_percentage']) > 0.01,
        $t['percentage'] . ' % contre ' . $t['ranking_percentage'] . ' %'
    );

    // =================================================================
    //  CLASSEMENT
    // =================================================================
    // Élève 2 : meilleur que le 1er. Élève 3 : aucune cote.
    grades_service_save_sheet($classroom, $subjA, $periods['P1'], [$enroll[1] => ['points' => 20, 'is_absent' => false]]);
    grades_service_save_sheet($classroom, $subjA, $periods['P2'], [$enroll[1] => ['points' => 20, 'is_absent' => false]]);
    grades_service_save_sheet($classroom, $subjA, $periods['EX1'], [$enroll[1] => ['points' => 40, 'is_absent' => false]]);

    $ranking = bulletins_service_rank_classroom($classroom, 'S1');

    check(
        'L\'élève le mieux noté est premier',
        $ranking[$enroll[1]]['rank'] === 1,
        'rang ' . var_export($ranking[$enroll[1]]['rank'], true)
    );

    check(
        'Le second est deuxième',
        $ranking[$enroll[0]]['rank'] === 2,
        'rang ' . var_export($ranking[$enroll[0]]['rank'], true)
    );

    check(
        'Un élève SANS aucune cote n\'est PAS classé dernier : il n\'est pas classé',
        $ranking[$enroll[2]]['rank'] === null && $ranking[$enroll[2]]['percentage'] === null
    );

    // Égalité : le troisième élève reçoit les mêmes cotes que le premier.
    grades_service_save_sheet($classroom, $subjA, $periods['P1'], [$enroll[2] => ['points' => 20, 'is_absent' => false]]);
    grades_service_save_sheet($classroom, $subjA, $periods['P2'], [$enroll[2] => ['points' => 20, 'is_absent' => false]]);
    grades_service_save_sheet($classroom, $subjA, $periods['EX1'], [$enroll[2] => ['points' => 40, 'is_absent' => false]]);

    $tied = bulletins_service_rank_classroom($classroom, 'S1');

    check(
        'Deux élèves à égalité reçoivent le MÊME rang',
        $tied[$enroll[1]]['rank'] === $tied[$enroll[2]]['rank'] && $tied[$enroll[1]]['rank'] === 1,
        $tied[$enroll[1]]['rank'] . ' et ' . $tied[$enroll[2]]['rank']
    );

    check(
        'Et le rang suivant est décalé d\'autant (1, 1, 3)',
        $tied[$enroll[0]]['rank'] === 3,
        'rang ' . var_export($tied[$enroll[0]]['rank'], true)
    );

    // =================================================================
    //  PUBLICATION : LE RANG SE FIGE
    // =================================================================
    $publish = bulletins_service_publish($classroom, 'S1');
    check('Publication des bulletins du premier semestre', $publish['ok'], $publish['message']);

    $frozen = bulletins_repo_find($enroll[0], 'S1');

    check(
        'Le bulletin publié porte le rang et l\'effectif',
        $frozen !== null && (int) $frozen['class_rank'] === 3 && (int) $frozen['class_size'] === 3,
        'rang ' . ($frozen['class_rank'] ?? '?') . ' / ' . ($frozen['class_size'] ?? '?')
    );

    $frozenPct = (float) $frozen['percentage'];

    // On améliore l'élève APRÈS publication : le document ne doit pas
    // bouger.
    grades_service_save_sheet($classroom, $subjB, $periods['EX1'], [$enroll[0] => ['points' => 40, 'is_absent' => false]]);

    $stillFrozen = bulletins_repo_find($enroll[0], 'S1');

    check(
        'Une cote ajoutée APRÈS publication ne modifie pas le bulletin publié',
        abs((float) $stillFrozen['percentage'] - $frozenPct) < 0.001,
        $stillFrozen['percentage'] . ' contre ' . $frozenPct
    );

    $liveAfter = bulletins_service_compute($enroll[0]);

    check(
        'Mais l\'état de travail, lui, a bien évolué',
        abs((float) $liveAfter['totals']['S1']['percentage'] - $frozenPct) > 0.01,
        $liveAfter['totals']['S1']['percentage'] . ' contre ' . $frozenPct
    );

    // Le document réimprimé doit afficher les chiffres publiés, et non
    // les chiffres à jour : c'est la règle que la vue applique en
    // choisissant $published[$key] plutôt que $totals[$key].
    $reprint = bulletins_repo_for_enrollment($enroll[0]);

    check(
        'Le total réimprimé est celui du document remis, pas le total courant',
        isset($reprint['S1'])
            && abs((float) $reprint['S1']['percentage'] - $frozenPct) < 0.001
            && abs((float) $reprint['S1']['total_points'] - 93.0) < 0.001,
        ($reprint['S1']['total_points'] ?? '?') . ' pts, ' . ($reprint['S1']['percentage'] ?? '?') . ' %'
    );

    check(
        'Le pourcentage publié est cohérent avec le total publié',
        abs(
            (float) $reprint['S1']['percentage']
            - round((float) $reprint['S1']['total_points'] / (float) $reprint['S1']['max_points'] * 100, 2)
        ) < 0.01,
        $reprint['S1']['total_points'] . ' / ' . $reprint['S1']['max_points']
            . ' = ' . $reprint['S1']['percentage'] . ' %'
    );

    check(
        'Le pourcentage de CLASSEMENT est conservé à part, pour justifier le rang',
        $reprint['S1']['ranking_percentage'] !== null
            && abs((float) $reprint['S1']['ranking_percentage'] - 73.0) < 0.01,
        (string) ($reprint['S1']['ranking_percentage'] ?? 'null')
    );

    // =================================================================
    //  DÉCISION DE FIN D'ANNÉE
    // =================================================================
    $tooEarly = bulletins_service_set_decision($enroll[0], 'passed');
    check(
        'Aucune décision sans bulletin annuel publié',
        !$tooEarly['ok'] && str_contains($tooEarly['message'], 'doit être publié'),
        $tooEarly['message']
    );

    bulletins_service_publish($classroom, 'ANNUAL');

    $annual = bulletins_repo_find($enroll[0], 'ANNUAL');
    check(
        'Le bulletin annuel est publié « en délibération »',
        $annual !== null && $annual['decision'] === 'pending',
        (string) ($annual['decision'] ?? 'null')
    );

    $decided = bulletins_service_set_decision($enroll[0], 'passed');
    check('Décision enregistrée', $decided['ok'], $decided['message']);

    $enrollmentRow = tenant_find('enrollments', $enroll[0]);

    check(
        'La décision est reportée sur l\'inscription',
        $enrollmentRow['decision'] === 'passed' && $enrollmentRow['decision_date'] !== null
    );

    check(
        'Le pourcentage de l\'inscription est COPIÉ du bulletin, pas recalculé',
        abs((float) $enrollmentRow['final_percentage'] - (float) $annual['percentage']) < 0.001,
        $enrollmentRow['final_percentage'] . ' contre ' . $annual['percentage']
    );

    // =================================================================
    //  AUTORISATION
    // =================================================================
    $teacherUserId = act_as($schoolId, 'ENSEIGNANT');
    tenant_set($schoolId);

    check('L\'enseignant ne détient pas bulletin.publish', !perm_has('bulletin.publish'));

    $refused = bulletins_service_publish($classroom, 'S2');
    check(
        'Un enseignant ne peut pas publier les bulletins',
        !$refused['ok'] && str_contains($refused['message'], 'droit'),
        $refused['message']
    );

    $refusedDecision = bulletins_service_set_decision($enroll[0], 'failed');
    check(
        'Un enseignant ne peut pas prononcer une décision',
        !$refusedDecision['ok'] && str_contains($refusedDecision['message'], 'droit'),
        $refusedDecision['message']
    );

    // =================================================================
    //  PÉRIMÈTRE : LA PERMISSION NE DÉSIGNE PAS LA CLASSE
    //
    //  Une école peut parfaitement accorder bulletin.publish à ses
    //  titulaires. Le droit dit ce qu'on peut FAIRE, jamais SUR QUI :
    //  chaque porte doit porter le périmètre, pas seulement l'écran de
    //  liste.
    // =================================================================
    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    // Une seconde classe, dont l'enseignant n'a la charge d'aucun élève.
    $otherClassroom = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '5B', 'name' => '5ème primaire B', 'capacity' => 40,
    ]);

    $otherStudent = students_service_enroll_new(
        ['last_name' => 'DELTA', 'first_name' => 'Quatre', 'gender' => 'F'],
        $yearId,
        $otherClassroom
    );
    $otherEnrollment = (int) students_repo_enrollment((int) $otherStudent['id'], $yearId)['id'];

    // Un enseignant titulaire de 5A seulement, à qui l'école accorde
    // exceptionnellement le droit de publier.
    $limitedUserId = act_as($schoolId, 'ENSEIGNANT');
    tenant_set($schoolId);

    $limitedTeacherId = tenant_insert('teachers', [
        'uuid'       => str_uuid(),
        'user_id'    => $limitedUserId,
        'matricule'  => 'ENS-BUL-1',
        'last_name'  => 'MUKENDI',
        'first_name' => 'Joseph',
        'gender'     => 'M',
        'status'     => 'active',
        'hire_date'  => '2085-09-01',
    ]);

    tenant_update('classrooms', ['main_teacher_id' => $limitedTeacherId], 'id = :id', ['id' => $classroom]);

    db_query(
        'INSERT INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id FROM roles r, permissions p
          WHERE r.code = \'ENSEIGNANT\' AND r.school_id IS NULL
            AND p.code IN (\'bulletin.generate\', \'bulletin.publish\')',
        [],
        true
    );

    perm_all(true);
    perm_roles(true);

    check(
        'L\'enseignant détient maintenant bulletin.publish',
        perm_has('bulletin.publish')
    );

    $ownClass = bulletins_service_publish($classroom, 'S2');
    check(
        'Il publie les bulletins de SA classe',
        $ownClass['ok'],
        $ownClass['message']
    );

    $foreignClass = bulletins_service_publish($otherClassroom, 'S2');
    check(
        'Mais pas ceux d\'une classe hors de son périmètre',
        !$foreignClass['ok'] && str_contains($foreignClass['message'], 'périmètre'),
        $foreignClass['message']
    );

    $foreignDecision = bulletins_service_set_decision($otherEnrollment, 'passed');
    check(
        'Et il ne décide pas du sort d\'un élève hors de son périmètre',
        !$foreignDecision['ok'] && str_contains($foreignDecision['message'], 'périmètre'),
        $foreignDecision['message']
    );

    perm_all(true);
    perm_roles(true);

    // =================================================================
    //  ANNÉE CLÔTURÉE
    // =================================================================
    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    tenant_update('academic_years', ['status' => 'closed'], 'id = :id', ['id' => $yearId]);

    $closed = bulletins_service_publish($classroom, 'S2');
    check(
        'Année clôturée : plus aucune publication',
        !$closed['ok'] && str_contains($closed['message'], 'clôturée'),
        $closed['message']
    );

    $closedDecision = bulletins_service_set_decision($enroll[1], 'passed');
    check(
        'Année clôturée : plus aucune décision',
        !$closedDecision['ok'] && str_contains($closedDecision['message'], 'clôturée'),
        $closedDecision['message']
    );

    tenant_update('academic_years', ['status' => 'active'], 'id = :id', ['id' => $yearId]);

    // =================================================================
    //  SEUIL DE RÉUSSITE
    // =================================================================
    require_once APP_PATH . '/modules/teachers/services.php';

    school_setting_set('grading.passing_threshold', 50);
    school_settings_all(true);

    check(
        'Une proposition de décision suit le seuil de l\'établissement',
        bulletins_service_suggest_decision(60.0) === 'passed'
            && bulletins_service_suggest_decision(40.0) === 'failed'
    );

    school_setting_set('grading.passing_threshold', 70);
    school_settings_all(true);

    check(
        'Seuil relevé à 70 % : 60 % devient un échec',
        bulletins_service_suggest_decision(60.0) === 'failed'
    );

    check(
        'Sans pourcentage, aucune proposition n\'est faite',
        bulletins_service_suggest_decision(null) === 'pending'
    );

    school_setting_set('grading.passing_threshold', 50);
    school_settings_all(true);

    // =================================================================
    //  ISOLATION ENTRE ÉCOLES
    //
    //  Le point non négociable du produit : un utilisateur d'une école ne
    //  doit JAMAIS atteindre les données d'une autre. Ici, le bulletin
    //  porte les résultats et la décision de passage — la fuite la plus
    //  grave possible après le dossier de l'élève.
    // =================================================================
    $blocked = false;

    try {
        db_query('SELECT * FROM bulletins LIMIT 1');
    } catch (Throwable) {
        $blocked = true;
    }

    check('Une requête sans school_id sur « bulletins » est refusée', $blocked);

    $otherSchoolId = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'BUL-B', 'slug' => 'bul-b',
        'name' => 'École voisine', 'status' => 'active',
    ], true);
    $createdSchools[] = $otherSchoolId;

    act_as($otherSchoolId, 'DIRECTION');
    tenant_set($otherSchoolId);

    check(
        'L\'école voisine ne voit AUCUN bulletin de la première',
        bulletins_repo_find($enroll[0], 'ANNUAL') === null
            && bulletins_repo_classroom($classroom, 'ANNUAL') === []
            && bulletins_repo_header($enroll[0]) === null
    );

    $crossPublish = bulletins_service_publish($classroom, 'S1');
    check(
        'Et elle ne peut pas republier — donc reclasser — une classe étrangère',
        !$crossPublish['ok'] && str_contains($crossPublish['message'], 'introuvable'),
        $crossPublish['message']
    );

    $crossDecision = bulletins_service_set_decision($enroll[0], 'failed');
    check(
        'Ni prononcer une décision sur un élève étranger',
        !$crossDecision['ok'] && str_contains($crossDecision['message'], 'introuvable'),
        $crossDecision['message']
    );

    // Le bulletin de la première école est resté intact.
    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    $untouched = bulletins_repo_find($enroll[0], 'ANNUAL');
    check(
        'Le bulletin de la première école est intact',
        $untouched !== null && $untouched['decision'] === 'passed',
        (string) ($untouched['decision'] ?? 'null')
    );
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    // Le catalogue des rôles est GLOBAL : il est partagé par toutes les
    // écoles. Le droit exceptionnel accordé plus haut doit disparaître
    // même si un test a échoué en cours de route, sans quoi la suite
    // suivante s'exécuterait sur un catalogue faussé.
    db_query(
        'DELETE rp FROM role_permissions rp
           JOIN roles r ON r.id = rp.role_id
           JOIN permissions p ON p.id = rp.permission_id
          WHERE r.code = \'ENSEIGNANT\' AND r.school_id IS NULL
            AND p.code IN (\'bulletin.generate\', \'bulletin.publish\')',
        [],
        true
    );

    foreach ($createdSchools as $schoolId) {
        tenant_set($schoolId);

        db_query('UPDATE classrooms SET main_teacher_id = NULL WHERE school_id = :s', ['s' => $schoolId]);

        foreach ([
            'bulletins', 'grades', 'student_history', 'orientations',
            'student_guardians', 'enrollments', 'guardians', 'students',
            'student_counters', 'teacher_subjects', 'teachers', 'classrooms',
            'curriculum_subjects', 'curriculums', 'grade_periods',
            'subjects', 'options', 'sections', 'academic_years', 'audit_logs',
        ] as $table) {
            db_query("DELETE FROM {$table} WHERE school_id = :school_id", ['school_id' => $schoolId]);
        }

        db_query('DELETE FROM school_settings WHERE school_id = :s', ['s' => $schoolId]);
        db_query('DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE school_id = :s)',
            ['s' => $schoolId], true);
        db_query('DELETE FROM users WHERE school_id = :s', ['s' => $schoolId], true);
        db_query('DELETE FROM school_cycles WHERE school_id = :s', ['s' => $schoolId], true);
        db_query('DELETE FROM schools WHERE id = :s', ['s' => $schoolId], true);
    }
}

echo "\n  ──────────────────────────────────────────────────\n";
printf("  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
