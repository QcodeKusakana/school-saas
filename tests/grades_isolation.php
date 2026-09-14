<?php
/**
 * Phase 4B — notes : autorisation, bornes, verrouillage, maxima figés.
 *
 * Ce que ces tests protègent
 * --------------------------
 * Une cote décide du passage de classe. Trois choses doivent tenir :
 *
 *  · seul l'enseignant de LA branche de LA classe peut saisir ;
 *  · une cote ne peut pas dépasser son maximum, ni être négative ;
 *  · le maximum figé à la saisie ne bouge JAMAIS, même si l'école
 *    modifie ensuite le programme.
 *
 * Le troisième point est le plus important et le moins visible : sans
 * lui, corriger un maximum en mars réécrirait tous les bulletins déjà
 * remis aux parents.
 *
 * Usage : php tests/grades_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/teachers/services.php';
require APP_PATH . '/modules/grades/services.php';
require_once APP_PATH . '/modules/students/repositories.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

/** Ouvre une session de test au nom d'un utilisateur portant un rôle système. */
function act_as(int $schoolId, string $roleCode): int
{
    static $counter = 0;
    $counter++;

    $userId = db_insert('users', [
        'uuid'          => str_uuid(),
        'school_id'     => $schoolId,
        'username'      => 'note.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'note' . $counter . '@example.test',
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

/** Bascule vers un utilisateur déjà créé. */
function switch_to(int $userId, int $schoolId): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($schoolId);
}

echo "\n  NOTES — AUTORISATION, BORNES ET MAXIMA FIGÉS\n";
echo "  ──────────────────────────────────────────────────────\n\n";

$createdSchools = [];

try {
    // -----------------------------------------------------------------
    //  Mise en place
    // -----------------------------------------------------------------
    $schoolId = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'NOTE-A', 'slug' => 'note-a',
        'name' => 'École des Notes', 'status' => 'active',
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
        'code' => '2080-2081', 'name' => 'Test notes', 'starts_on' => '2080-09-01',
        'ends_on' => '2081-07-31', 'status' => 'active', 'is_current' => 1,
    ]);

    curriculum_service_import_national();
    curriculum_service_create_standard_periods($yearId);

    $levelId = (int) db_value("SELECT id FROM education_levels WHERE code = 'PRI_5'", [], true);
    $program = curriculum_service_create_program($yearId, $levelId, null, null);
    curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    $classA = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '5A', 'name' => '5ème primaire A', 'capacity' => 40,
    ]);
    $classB = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '5B', 'name' => '5ème primaire B', 'capacity' => 40,
    ]);

    // Deux branches du programme, dont une notée sur 40.
    $subjects = tenant_all(
        'curriculum_subjects',
        'curriculum_id = :c ORDER BY max_points DESC, order_number LIMIT 2',
        ['c' => (int) $program['id']]
    );
    $subjectBig   = (int) $subjects[0]['id'];
    $subjectOther = (int) $subjects[1]['id'];
    $bigMax       = (int) $subjects[0]['max_points'];

    // Le code d'une période n'est plus unique dans l'année : depuis la
    // migration 011, chaque cycle porte son propre jeu et le primaire a
    // son « P1 » comme les humanités ont le leur. La recherche se
    // qualifie donc par le cycle de la classe.
    $cycleId  = curriculum_classroom_cycle_id($classA);
    $periodP1 = tenant_one(
        'grade_periods',
        'academic_year_id = :y AND code = :c AND cycle_id = :cy',
        ['y' => $yearId, 'c' => 'P1', 'cy' => $cycleId]
    );
    $periodEx = tenant_one(
        'grade_periods',
        'academic_year_id = :y AND code = :c AND cycle_id = :cy',
        ['y' => $yearId, 'c' => 'EX1', 'cy' => $cycleId]
    );

    check('Périodes standard créées', $periodP1 !== null && $periodEx !== null);

    // Trois élèves en 5A, un en 5B.
    $eleves = [];

    foreach ([['KABILA', 'Grace', 'F'], ['MUKENDI', 'Joseph', 'M'], ['ILUNGA', 'Esther', 'F']] as $s) {
        $r = students_service_enroll_new(
            ['last_name' => $s[0], 'first_name' => $s[1], 'gender' => $s[2]],
            $yearId,
            $classA
        );
        $eleves[] = (int) $r['id'];
    }

    $eleveB = students_service_enroll_new(
        ['last_name' => 'TSHALA', 'first_name' => 'Bijou', 'gender' => 'F'],
        $yearId,
        $classB
    );

    check('Quatre élèves inscrits', count($eleves) === 3 && $eleveB['ok']);

    $enrollA = [];

    foreach ($eleves as $studentId) {
        $enrollA[] = (int) students_repo_enrollment($studentId, $yearId)['id'];
    }

    $enrollB = (int) students_repo_enrollment((int) $eleveB['id'], $yearId)['id'];

    // Un enseignant, affecté à UNE branche de 5A seulement.
    $teacher = teachers_service_create([
        'last_name' => 'MULUMBA', 'first_name' => 'Pierre', 'specialty' => 'Mathématiques',
    ]);
    $teacherId = (int) $teacher['id'];

    $assign = teachers_service_assign($teacherId, $classA, $subjectBig, 6.0);
    check('Branche confiée à l\'enseignant', $assign['ok'], $assign['message']);

    $teacherUserId = act_as($schoolId, 'ENSEIGNANT');
    tenant_set($schoolId);
    tenant_update('teachers', ['user_id' => $teacherUserId], 'id = :id', ['id' => $teacherId]);

    // =================================================================
    //  AUTORISATION EN DEUX TEMPS
    // =================================================================
    switch_to($teacherUserId, $schoolId);

    check('L\'enseignant détient grade.enter', perm_has('grade.enter'));
    check('Il ne détient PAS grade.validate', !perm_has('grade.validate'));

    $can = grades_service_can_enter($classA, $subjectBig);
    check('Saisie autorisée dans SA branche de SA classe', $can['ok'], $can['message']);

    $cannotSubject = grades_service_can_enter($classA, $subjectOther);
    check(
        'Saisie REFUSÉE dans une branche qu\'on ne lui a pas confiée',
        !$cannotSubject['ok'] && str_contains($cannotSubject['message'], 'pas confiée'),
        $cannotSubject['message']
    );

    $cannotClass = grades_service_can_enter($classB, $subjectBig);
    check(
        'Saisie REFUSÉE dans une classe qui n\'est pas la sienne',
        !$cannotClass['ok'],
        $cannotClass['message']
    );

    // La permission seule ne suffit pas : le service refuse même en
    // passant directement par l'enregistrement.
    $forged = grades_service_save_sheet($classB, $subjectBig, (int) $periodP1['id'], [
        $enrollB => ['points' => 15, 'is_absent' => false],
    ]);
    check(
        'Enregistrement direct dans une autre classe : refusé',
        !$forged['ok'],
        $forged['message']
    );

    // =================================================================
    //  LA GRILLE EST UNE PORTE, ELLE AUSSI
    //
    //  Le contrôle de lecture de la grille était écrit
    //  « !$auth['ok'] && !perm_has('grade.view') ». La route exigeant
    //  déjà grade.view, la seconde condition était toujours fausse : le
    //  refus n'était jamais atteint. Et grade.view était accordée aux
    //  rôles PARENT et ELEVE.
    //
    //  Résultat : un parent lisait noms, matricules et cotes de toute
    //  classe de l'école.
    // =================================================================
    $parentUserId = act_as($schoolId, 'PARENT');
    tenant_set($schoolId);

    check(
        'Le rôle PARENT ne détient plus grade.view',
        !perm_has('grade.view')
    );

    check(
        'Un parent n\'a aucune classe dans son périmètre',
        !students_can_view_classroom($classA) && !students_can_view_classroom($classB)
    );

    $parentEnter = grades_service_can_enter($classA, $subjectBig);
    check(
        'Un parent ne peut évidemment pas saisir',
        !$parentEnter['ok'],
        $parentEnter['message']
    );

    switch_to($teacherUserId, $schoolId);

    check(
        'L\'enseignant ne voit pas non plus la classe qui n\'est pas la sienne',
        !students_can_view_classroom($classB)
    );

    check(
        'Mais il voit bien la sienne',
        students_can_view_classroom($classA)
    );

    // =================================================================
    //  SAISIE ET BORNES
    // =================================================================
    $save = grades_service_save_sheet($classA, $subjectBig, (int) $periodP1['id'], [
        $enrollA[0] => ['points' => 32, 'is_absent' => false],
        $enrollA[1] => ['points' => 0,  'is_absent' => false],
        $enrollA[2] => ['points' => null, 'is_absent' => true],
    ]);
    check('Trois cotes enregistrées', $save['ok'] && $save['saved'] === 3, $save['message']);

    $sheet = grades_repo_sheet($classA, $subjectBig, (int) $periodP1['id']);
    $byEnrollment = [];

    foreach ($sheet as $row) {
        $byEnrollment[(int) $row['enrollment_id']] = $row;
    }

    check(
        'Le maximum figé vaut le maximum de la branche × le poids de la période',
        abs((float) $byEnrollment[$enrollA[0]]['max_points'] - (float) $bigMax) < 0.001,
        $byEnrollment[$enrollA[0]]['max_points'] . ' attendu ' . $bigMax
    );

    check(
        'Un zéro est bien enregistré comme un zéro',
        (float) $byEnrollment[$enrollA[1]]['points'] === 0.0
            && (int) $byEnrollment[$enrollA[1]]['is_absent'] === 0
    );

    check(
        'Une absence n\'est PAS un zéro',
        $byEnrollment[$enrollA[2]]['points'] === null
            && (int) $byEnrollment[$enrollA[2]]['is_absent'] === 1
    );

    // Bornes.
    $tooHigh = grades_service_save_sheet($classA, $subjectBig, (int) $periodP1['id'], [
        $enrollA[0] => ['points' => $bigMax + 1, 'is_absent' => false],
    ]);
    check(
        'Cote supérieure au maximum : rejetée',
        !$tooHigh['ok'] && isset($tooHigh['errors'][$enrollA[0]]),
        $tooHigh['errors'][$enrollA[0]] ?? ''
    );

    $negative = grades_service_save_sheet($classA, $subjectBig, (int) $periodP1['id'], [
        $enrollA[0] => ['points' => -1, 'is_absent' => false],
    ]);
    check(
        'Cote négative : rejetée',
        !$negative['ok'] && isset($negative['errors'][$enrollA[0]]),
        $negative['errors'][$enrollA[0]] ?? ''
    );

    $notNumeric = grades_service_save_sheet($classA, $subjectBig, (int) $periodP1['id'], [
        $enrollA[0] => ['points' => 'douze', 'is_absent' => false],
    ]);
    check(
        'Cote non numérique : rejetée',
        !$notNumeric['ok'] && isset($notNumeric['errors'][$enrollA[0]]),
        $notNumeric['errors'][$enrollA[0]] ?? ''
    );

    // La cote d'origine n'a pas bougé.
    $after = grades_repo_sheet($classA, $subjectBig, (int) $periodP1['id']);
    $stillThere = null;

    foreach ($after as $row) {
        if ((int) $row['enrollment_id'] === $enrollA[0]) {
            $stillThere = $row;
        }
    }

    check(
        'Une saisie rejetée ne détruit pas la cote déjà posée',
        $stillThere !== null && (float) $stillThere['points'] === 32.0,
        (string) ($stillThere['points'] ?? 'null')
    );

    // Un élève d'une AUTRE classe glissé dans l'envoi.
    $intruder = grades_service_save_sheet($classA, $subjectBig, (int) $periodP1['id'], [
        $enrollA[0] => ['points' => 30, 'is_absent' => false],
        $enrollB    => ['points' => 18, 'is_absent' => false],
    ]);
    check(
        'Un élève d\'une AUTRE classe glissé dans l\'envoi est rejeté',
        $intruder['ok'] && isset($intruder['errors'][$enrollB]) && $intruder['saved'] === 1,
        $intruder['errors'][$enrollB] ?? ''
    );

    check(
        'Aucune cote n\'a été posée sur l\'élève de l\'autre classe',
        grades_repo_sheet($classB, $subjectBig, (int) $periodP1['id'])[0]['points'] === null
    );

    // Décimales conservées.
    $decimal = grades_service_save_sheet($classA, $subjectBig, (int) $periodP1['id'], [
        $enrollA[1] => ['points' => 12.5, 'is_absent' => false],
    ]);
    check('Cote décimale acceptée', $decimal['ok'], $decimal['message']);

    $sheet2 = grades_repo_sheet($classA, $subjectBig, (int) $periodP1['id']);
    $decimalRow = null;

    foreach ($sheet2 as $row) {
        if ((int) $row['enrollment_id'] === $enrollA[1]) {
            $decimalRow = $row;
        }
    }

    check(
        'La décimale est conservée en base, pas arrondie',
        $decimalRow !== null && abs((float) $decimalRow['points'] - 12.5) < 0.001,
        (string) ($decimalRow['points'] ?? '')
    );

    // =================================================================
    //  UNE CASE VIDE N'EST PAS UNE COTE
    //
    //  Ces contrôles existent parce qu'un défaut réel leur avait échappé :
    //  enregistrer une colonne encore vierge créait une ligne par élève,
    //  et le tableau de bord du préfet — qui comptait les LIGNES —
    //  affichait la colonne en vert. Il annonçait « complet » sur une
    //  classe sans une seule note.
    // =================================================================
    // La direction saisit partout : ce bloc porte sur une branche qui
    // n'est pas confiée à l'enseignant de test.
    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    // =================================================================
    //  UNE PÉRIODE D'UN AUTRE CYCLE EST REFUSÉE
    //
    //  Depuis que le primaire est en trimestres et les humanités en
    //  semestres, une même année porte plusieurs jeux de périodes. Ne
    //  contrôler que l'année laissait poser une cote de primaire sur le
    //  « P1 » des humanités : ACCEPTÉE, enregistrée, et invisible sur tous
    //  les bulletins. Le professeur croyait avoir saisi, le document n'en
    //  portait rien.
    // =================================================================
    $otherCycleId = (int) db_value(
        'SELECT id FROM education_cycles WHERE id <> :own ORDER BY id LIMIT 1',
        ['own' => $cycleId],
        true
    );

    $foreignPeriod = tenant_one(
        'grade_periods',
        'academic_year_id = :y AND cycle_id = :cy ORDER BY order_number',
        ['y' => $yearId, 'cy' => $otherCycleId]
    );

    check('Une période existe bien pour un autre cycle', $foreignPeriod !== null);

    $before = (int) db_value('SELECT COUNT(*) FROM grades WHERE school_id = :s', ['s' => $schoolId], true);

    $foreign = grades_service_save_sheet(
        $classA,
        $subjectBig,
        (int) $foreignPeriod['id'],
        [$enrollA[0] => ['points' => 5, 'is_absent' => false]]
    );

    check(
        'Une cote posée sur la période d\'un AUTRE cycle est refusée',
        !$foreign['ok'] && str_contains($foreign['message'], 'cycle'),
        $foreign['message']
    );

    check(
        'Et rien n\'est écrit en base',
        (int) db_value('SELECT COUNT(*) FROM grades WHERE school_id = :s', ['s' => $schoolId], true) === $before
    );

    $emptyPeriod = tenant_one(
        'grade_periods',
        'academic_year_id = :y AND code = :c AND cycle_id = :cy',
        ['y' => $yearId, 'c' => 'P2', 'cy' => $cycleId]
    );

    $empty = grades_service_save_sheet($classA, $subjectOther, (int) $emptyPeriod['id'], [
        $enrollA[0] => ['points' => '', 'is_absent' => false],
        $enrollA[1] => ['points' => '', 'is_absent' => false],
        $enrollA[2] => ['points' => '', 'is_absent' => false],
    ]);

    $emptyRows = (int) db_value(
        'SELECT COUNT(*) FROM grades
          WHERE school_id = :s AND curriculum_subject_id = :c AND grade_period_id = :p',
        ['s' => $schoolId, 'c' => $subjectOther, 'p' => (int) $emptyPeriod['id']]
    );

    check(
        'Une colonne entièrement vide ne crée AUCUNE ligne',
        $emptyRows === 0,
        $emptyRows . ' ligne(s)'
    );

    $progress = grades_repo_classroom_progress($classA, $yearId);
    $emptyCell = null;

    foreach ($progress as $row) {
        if ((int) $row['curriculum_subject_id'] === $subjectOther
            && (int) $row['period_id'] === (int) $emptyPeriod['id']) {
            $emptyCell = (int) $row['entered'];
        }
    }

    check(
        'Le tableau de bord annonce 0 cote saisie, pas « complet »',
        $emptyCell === 0,
        $emptyCell . ' comptée(s)'
    );

    // Effacer une cote existante la fait disparaître du décompte.
    grades_service_save_sheet($classA, $subjectOther, (int) $emptyPeriod['id'], [
        $enrollA[0] => ['points' => 15, 'is_absent' => false],
    ]);

    $before = (int) db_value(
        'SELECT COUNT(*) FROM grades WHERE school_id = :s AND curriculum_subject_id = :c AND grade_period_id = :p',
        ['s' => $schoolId, 'c' => $subjectOther, 'p' => (int) $emptyPeriod['id']]
    );

    grades_service_save_sheet($classA, $subjectOther, (int) $emptyPeriod['id'], [
        $enrollA[0] => ['points' => '', 'is_absent' => false],
    ]);

    $afterErase = (int) db_value(
        'SELECT COUNT(*) FROM grades WHERE school_id = :s AND curriculum_subject_id = :c AND grade_period_id = :p',
        ['s' => $schoolId, 'c' => $subjectOther, 'p' => (int) $emptyPeriod['id']]
    );

    check(
        'Vider une case efface la cote au lieu de la mettre à NULL',
        $before === 1 && $afterErase === 0,
        "avant={$before} après={$afterErase}"
    );

    switch_to($teacherUserId, $schoolId);

    // =================================================================
    //  LE POIDS DE LA PÉRIODE
    // =================================================================
    $examMax = grades_max_for($subjects[0], $periodEx);
    check(
        'La période d\'examen double le maximum',
        abs($examMax - $bigMax * 2.0) < 0.001,
        $examMax . ' pour un maximum de branche de ' . $bigMax
    );

    $examSave = grades_service_save_sheet($classA, $subjectBig, (int) $periodEx['id'], [
        $enrollA[0] => ['points' => $bigMax + 10, 'is_absent' => false],
    ]);
    check(
        'Une cote refusée en période simple passe à l\'examen',
        $examSave['ok'] && $examSave['saved'] === 1,
        $examSave['message']
    );

    // =================================================================
    //  LE MAXIMUM FIGÉ NE BOUGE PAS
    //
    //  C'est la garantie centrale de cette phase : l'école corrige le
    //  programme en cours d'année, les cotes déjà posées gardent leur
    //  barème d'origine.
    // =================================================================
    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    // --- Le barème ne peut PLUS être modifié une fois des cotes posées.
    //
    //  C'est la correction à la racine du défaut le plus grave de cette
    //  phase : la borne de saisie venait du programme COURANT, le
    //  stockage du barème FIGÉ. Porter une branche de 40 à 100 laissait
    //  enregistrer 95 sur une ligne figée à 40, soit 237 % du maximum.
    $blocked = curriculum_service_update_subjects((int) $program['id'], [
        $subjectBig => ['max_points' => 100, 'order_number' => 1],
    ]);

    check(
        'Barème d\'une branche notée : la modification est refusée',
        (int) tenant_find('curriculum_subjects', $subjectBig)['max_points'] === $bigMax,
        'max = ' . tenant_find('curriculum_subjects', $subjectBig)['max_points']
    );

    // Une branche SANS cote reste modifiable.
    curriculum_service_update_subjects((int) $program['id'], [
        $subjectOther => ['max_points' => 30, 'order_number' => 2],
    ]);

    // subjectOther porte une cote depuis le test précédent : il doit
    // donc être refusé lui aussi. On vérifie sur une troisième branche.
    $freeSubject = tenant_one(
        'curriculum_subjects',
        'curriculum_id = :c AND id NOT IN (:a, :b) ORDER BY order_number',
        ['c' => (int) $program['id'], 'a' => $subjectBig, 'b' => $subjectOther]
    );

    $freeMaxBefore = (int) $freeSubject['max_points'];
    curriculum_service_update_subjects((int) $program['id'], [
        (int) $freeSubject['id'] => ['max_points' => $freeMaxBefore + 5, 'order_number' => 3],
    ]);

    check(
        'Une branche SANS cote reste modifiable',
        (int) tenant_find('curriculum_subjects', (int) $freeSubject['id'])['max_points'] === $freeMaxBefore + 5
    );

    // --- Vérification de la ceinture : même si un barème divergeait,
    //     chaque cote est validée contre SON propre maximum.
    db_query(
        'UPDATE curriculum_subjects SET max_points = 100 WHERE school_id = :s AND id = :id',
        ['s' => $schoolId, 'id' => $subjectBig]
    );

    $overFrozen = grades_service_save_sheet($classA, $subjectBig, (int) $periodP1['id'], [
        $enrollA[0] => ['points' => 95, 'is_absent' => false],
    ]);

    check(
        'Barème divergent : la cote est bornée par le maximum FIGÉ, pas par le courant',
        !$overFrozen['ok'] && isset($overFrozen['errors'][$enrollA[0]]),
        $overFrozen['errors'][$enrollA[0]] ?? ''
    );

    db_query(
        'UPDATE curriculum_subjects SET max_points = :m WHERE school_id = :s AND id = :id',
        ['m' => $bigMax, 's' => $schoolId, 'id' => $subjectBig]
    );

    tenant_update('curriculum_subjects', ['max_points' => 10], 'id = :id', ['id' => $subjectBig]);

    $afterChange = grades_repo_sheet($classA, $subjectBig, (int) $periodP1['id']);
    $frozen = null;

    foreach ($afterChange as $row) {
        if ((int) $row['enrollment_id'] === $enrollA[0]) {
            $frozen = $row;
        }
    }

    check(
        'Le maximum de la branche a bien été modifié dans le programme',
        (int) tenant_find('curriculum_subjects', $subjectBig)['max_points'] === 10
    );

    check(
        'La cote déjà posée conserve son maximum d\'origine',
        $frozen !== null && abs((float) $frozen['max_points'] - (float) $bigMax) < 0.001,
        $frozen['max_points'] . ' au lieu de ' . $bigMax
    );

    check(
        'Et sa valeur n\'a pas changé non plus',
        $frozen !== null && (float) $frozen['points'] === 30.0,
        (string) ($frozen['points'] ?? '')
    );

    // Le relevé retient le maximum figé, pas le courant.
    $report = grades_repo_report($enrollA[0]);
    $reportRow = null;

    foreach ($report as $row) {
        if ((int) $row['curriculum_subject_id'] === $subjectBig
            && (int) $row['period_id'] === (int) $periodP1['id']) {
            $reportRow = $row;
        }
    }

    check(
        'Le relevé de l\'élève utilise le maximum figé',
        $reportRow !== null && abs((float) $reportRow['max_points'] - (float) $bigMax) < 0.001,
        (string) ($reportRow['max_points'] ?? '')
    );

    tenant_update('curriculum_subjects', ['max_points' => $bigMax], 'id = :id', ['id' => $subjectBig]);

    // =================================================================
    //  VERROUILLAGE
    // =================================================================
    switch_to($teacherUserId, $schoolId);

    $cannotLock = grades_service_set_period_lock((int) $periodP1['id'], true);
    check(
        'Un enseignant ne peut pas verrouiller une période',
        !$cannotLock['ok'] && str_contains($cannotLock['message'], 'droit'),
        $cannotLock['message']
    );

    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    $lock = grades_service_set_period_lock((int) $periodP1['id'], true);
    check('La direction verrouille la période', $lock['ok'], $lock['message']);

    $lockedPeriod = tenant_find('grade_periods', (int) $periodP1['id']);
    check(
        'Le verrou est attribuable : qui et quand',
        (int) $lockedPeriod['is_locked'] === 1
            && $lockedPeriod['locked_by'] !== null
            && $lockedPeriod['locked_at'] !== null
    );

    switch_to($teacherUserId, $schoolId);

    $blocked = grades_service_save_sheet($classA, $subjectBig, (int) $periodP1['id'], [
        $enrollA[0] => ['points' => 5, 'is_absent' => false],
    ]);
    check(
        'Période verrouillée : l\'enseignant ne peut plus saisir',
        !$blocked['ok'] && str_contains($blocked['message'], 'verrouillée'),
        $blocked['message']
    );

    $untouched = grades_repo_sheet($classA, $subjectBig, (int) $periodP1['id']);
    $value = null;

    foreach ($untouched as $row) {
        if ((int) $row['enrollment_id'] === $enrollA[0]) {
            $value = (float) $row['points'];
        }
    }

    check('La cote n\'a pas été modifiée', $value === 30.0, (string) $value);

    // La direction détient grade.edit_locked.
    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    check('La direction détient grade.edit_locked', perm_has('grade.edit_locked'));

    $forcedSave = grades_service_save_sheet($classA, $subjectBig, (int) $periodP1['id'], [
        $enrollA[0] => ['points' => 28, 'is_absent' => false],
    ]);
    check(
        'La direction peut corriger une période verrouillée',
        $forcedSave['ok'] && $forcedSave['saved'] === 1,
        $forcedSave['message']
    );

    $forcedLog = db_one(
        'SELECT description FROM audit_logs
          WHERE school_id = :s AND entity_type = :t
          ORDER BY id DESC LIMIT 1',
        ['s' => $schoolId, 't' => 'grade_sheet']
    );
    check(
        'La saisie forcée est journalisée comme telle',
        $forcedLog !== null && str_contains((string) $forcedLog['description'], 'VERROUILLÉE'),
        (string) ($forcedLog['description'] ?? '')
    );

    grades_service_set_period_lock((int) $periodP1['id'], false);

    // =================================================================
    //  ANNÉE CLÔTURÉE
    // =================================================================
    tenant_update('academic_years', ['status' => 'closed'], 'id = :id', ['id' => $yearId]);

    $closed = grades_service_save_sheet($classA, $subjectBig, (int) $periodP1['id'], [
        $enrollA[0] => ['points' => 1, 'is_absent' => false],
    ]);
    check(
        'Année clôturée : plus aucune saisie, même pour la direction',
        !$closed['ok'] && str_contains($closed['message'], 'clôturée'),
        $closed['message']
    );

    tenant_update('academic_years', ['status' => 'active'], 'id = :id', ['id' => $yearId]);

    // =================================================================
    //  RETRAIT D'AFFECTATION AVEC DES COTES
    // =================================================================
    $assignment = tenant_one(
        'teacher_subjects',
        'classroom_id = :c AND curriculum_subject_id = :s',
        ['c' => $classA, 's' => $subjectBig]
    );

    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    // La garde reposait sur « !perm_has('grade.validate') ». Or les
    // quatre seuls rôles détenant teacher.assign détiennent tous
    // grade.validate : elle ne pouvait jamais se déclencher. Elle repose
    // désormais sur une confirmation explicite, que la DIRECTION doit
    // fournir comme tout le monde.
    $refusedRemoval = teachers_service_unassign($classA, (int) $assignment['id']);
    check(
        'Des cotes existent : le retrait exige une confirmation, même pour la direction',
        !$refusedRemoval['ok'] && ($refusedRemoval['needs_confirmation'] ?? false) === true,
        $refusedRemoval['message']
    );

    check(
        'Le refus annonce le nombre de cotes concernées',
        ($refusedRemoval['grade_count'] ?? 0) > 0,
        (string) ($refusedRemoval['grade_count'] ?? 0)
    );

    $allowedRemoval = teachers_service_unassign($classA, (int) $assignment['id'], true);
    check(
        'Confirmé : le retrait est accepté',
        $allowedRemoval['ok'],
        $allowedRemoval['message']
    );

    check(
        'Les cotes survivent au retrait de l\'affectation',
        grades_repo_count_for_assignment($classA, $subjectBig) > 0
    );

    // =================================================================
    //  BRANCHE HORS PROGRAMME
    // =================================================================
    $otherProgram = curriculum_service_create_program(
        $yearId,
        (int) db_value("SELECT id FROM education_levels WHERE code = 'PRI_3'", [], true),
        null,
        null
    );
    curriculum_service_fill_program((int) $otherProgram['id']);

    $foreignSubject = tenant_one(
        'curriculum_subjects',
        'curriculum_id = :c ORDER BY order_number',
        ['c' => (int) $otherProgram['id']]
    );

    $wrongSubject = grades_service_save_sheet(
        $classA,
        (int) $foreignSubject['id'],
        (int) $periodP1['id'],
        [$enrollA[0] => ['points' => 10, 'is_absent' => false]]
    );
    check(
        'Branche absente du programme de la classe : refusée',
        !$wrongSubject['ok'] && str_contains($wrongSubject['message'], 'ne figure pas au programme'),
        $wrongSubject['message']
    );

    // =================================================================
    //  GARDE-FOU MULTI-ÉCOLE
    // =================================================================
    $blockedGuard = false;

    try {
        db_query('SELECT * FROM grades LIMIT 1');
    } catch (Throwable) {
        $blockedGuard = true;
    }

    check('Garde-fou actif sur « grades »', $blockedGuard);
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($createdSchools as $schoolId) {
        tenant_set($schoolId);

        db_query('UPDATE classrooms SET main_teacher_id = NULL WHERE school_id = :s', ['s' => $schoolId]);

        foreach ([
            'grades', 'student_history', 'orientations', 'student_guardians',
            'enrollments', 'guardians', 'students', 'student_counters',
            'teacher_subjects', 'teachers', 'classrooms',
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
