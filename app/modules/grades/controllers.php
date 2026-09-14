<?php
/**
 * Module GRADES — contrôleurs.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/../teachers/repositories.php';

/** Année de travail : paramètre d'URL validé, sinon année courante. */
function grades_current_year(): ?array
{
    $requested = input_int('annee');

    if ($requested !== null) {
        $year = tenant_find('academic_years', $requested);

        if ($year !== null) {
            return $year;
        }
    }

    return tenant_one('academic_years', 'is_current = 1')
        ?? tenant_one('academic_years', '1 = 1 ORDER BY starts_on DESC');
}

// ---------------------------------------------------------------------
//  ACCUEIL DU MODULE
// ---------------------------------------------------------------------

/**
 * Point d'entrée.
 *
 * Deux publics, deux écrans :
 *  · l'enseignant voit SON service — ce qu'il a à saisir ;
 *  · la direction voit les classes de l'établissement.
 *
 * Le contenu découle du périmètre, pas d'un choix d'interface.
 */
function ctrl_grades_index(): void
{
    $year   = grades_current_year();
    $yearId = $year !== null ? (int) $year['id'] : 0;

    $teacher   = auth_id() !== null ? teachers_repo_find_by_user((int) auth_id()) : null;
    $isTeacher = $teacher !== null && $teacher['status'] === 'active';

    $workload = ($isTeacher && $yearId > 0)
        ? grades_repo_teacher_workload((int) $teacher['id'], $yearId)
        : [];

    // La direction et le préfet voient toutes les classes ; un enseignant
    // qui est aussi titulaire ne voit que les siennes.
    $classrooms = [];

    if ($yearId > 0 && perm_has('grade.validate')) {
        $classrooms = db_all(
            'SELECT c.id, c.code, c.name, l.short_name AS level_short,
                    COUNT(e.id) AS student_count
               FROM classrooms c
               JOIN curriculums cu     ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
               JOIN education_levels l ON l.id = cu.education_level_id
               LEFT JOIN enrollments e ON e.classroom_id = c.id AND e.school_id = c.school_id
                                      AND e.status <> \'cancelled\'
              WHERE c.school_id = :school_id AND c.academic_year_id = :year_id
                AND c.is_active = 1
              GROUP BY c.id
              ORDER BY l.order_number, c.order_number, c.code',
            ['school_id' => tenant_require(), 'year_id' => $yearId]
        );
    }

    view('grades/index', [
        'title'      => 'Notes',
        'year'       => $year,
        'years'      => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'periods'    => $yearId > 0
            ? tenant_all('grade_periods', 'academic_year_id = :y ORDER BY order_number', ['y' => $yearId])
            : [],
        'workload'   => $workload,
        'classrooms' => $classrooms,
        'isTeacher'  => $isTeacher,
    ]);
}

// ---------------------------------------------------------------------
//  AVANCEMENT D'UNE CLASSE
// ---------------------------------------------------------------------

function ctrl_grades_classroom(string $id): void
{
    $classroomId = (int) $id;
    $classroom   = tenant_find('classrooms', $classroomId);

    require_once APP_PATH . '/modules/students/repositories.php';

    if ($classroom === null || !students_can_view_classroom($classroomId)) {
        abort(404, 'Classe introuvable.');
    }

    $year = tenant_find('academic_years', (int) $classroom['academic_year_id']);

    view('grades/classroom', [
        'title'     => 'Notes — ' . $classroom['name'],
        'classroom' => $classroom,
        'year'      => $year,
        'rows'      => grades_repo_classroom_progress($classroomId, (int) $classroom['academic_year_id']),
        'students'  => count(grades_repo_classroom_enrollments($classroomId)),
    ]);
}

// ---------------------------------------------------------------------
//  GRILLE DE SAISIE
// ---------------------------------------------------------------------

function ctrl_grades_sheet(string $id, string $subjectId, string $periodId): void
{
    $classroomId = (int) $id;
    $subject     = (int) $subjectId;
    $period      = (int) $periodId;

    $context = grades_sheet_context($classroomId, $subject, $period);

    view('grades/sheet', $context + [
        'title' => 'Saisie — ' . $context['classroom']['name'],
        'rows'  => grades_repo_sheet($classroomId, $subject, $period),
    ]);
}

function ctrl_grades_store(string $id, string $subjectId, string $periodId): void
{
    $classroomId = (int) $id;
    $subject     = (int) $subjectId;
    $period      = (int) $periodId;

    $target = '/notes/classe/' . $classroomId . '/branche/' . $subject . '/periode/' . $period;

    $points  = is_array(input('points')) ? input('points') : [];
    $absents = is_array(input('absent')) ? input('absent') : [];

    if ($points === [] && $absents === []) {
        flash_error('Aucune cote transmise.');
        redirect($target);
    }

    // Les deux tableaux sont fusionnés, et ce n'est pas une précaution
    // théorique : un champ « disabled » n'est PAS transmis par le
    // navigateur. Cocher « Absent » désactive la case de la cote, donc
    // points[42] disparaît de l'envoi. N'itérer que sur $points
    // perdrait précisément les élèves absents — ceux que l'enseignant
    // vient de signaler.
    $entries = [];

    foreach (array_keys($points + $absents) as $key) {
        $enrollmentId = (int) $key;

        if ($enrollmentId <= 0) {
            continue;
        }

        $value = $points[$key] ?? null;

        $entries[$enrollmentId] = [
            'points'    => is_scalar($value) ? trim((string) $value) : null,
            'is_absent' => !empty($absents[$key]),
        ];
    }

    $outcome = grades_service_save_sheet($classroomId, $subject, $period, $entries);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        redirect($target);
    }

    if ($outcome['errors'] !== []) {
        // Les cotes valides sont enregistrées, les autres signalées
        // nommément : un rejet global ferait perdre une saisie de
        // quarante lignes pour une seule faute de frappe.
        flash_warning(
            $outcome['saved'] . ' cote(s) enregistrée(s). '
            . count($outcome['errors']) . ' rejetée(s) : '
            . implode(' ', array_unique($outcome['errors']))
        );
    } else {
        flash_success($outcome['saved'] . ' cote(s) enregistrée(s).');
    }

    redirect($target);
}

// ---------------------------------------------------------------------
//  VERROUILLAGE
// ---------------------------------------------------------------------

function ctrl_grades_lock_period(string $id): void
{
    $periodId = (int) $id;
    $locked   = input('action') === 'lock';

    $outcome = grades_service_set_period_lock($periodId, $locked);

    $outcome['ok']
        ? flash_success($locked ? 'Période verrouillée.' : 'Période déverrouillée.')
        : flash_error($outcome['message']);

    redirect('/notes' . (input_int('annee') !== null ? '?annee=' . input_int('annee') : ''));
}

// ---------------------------------------------------------------------
//  OUTILS INTERNES
// ---------------------------------------------------------------------

/**
 * Résout et VÉRIFIE le contexte d'une grille de saisie.
 *
 * Les trois identifiants viennent de l'URL. Chacun est contrôlé :
 * appartenance à l'école, appartenance de la branche au programme de la
 * classe, appartenance de la période à l'année de la classe. Un seul
 * manquement ouvrirait la saisie sur une combinaison qui n'existe pas.
 *
 * @return array<string, mixed>
 */
function grades_sheet_context(int $classroomId, int $curriculumSubjectId, int $periodId): array
{
    $classroom = tenant_find('classrooms', $classroomId);

    if ($classroom === null) {
        abort(404, 'Classe introuvable.');
    }

    $subject = db_one(
        'SELECT cs.*, s.name AS subject_name, s.short_name AS subject_short
           FROM curriculum_subjects cs
           JOIN subjects s ON s.id = cs.subject_id AND s.school_id = cs.school_id
          WHERE cs.school_id = :school_id AND cs.id = :id AND cs.curriculum_id = :curriculum_id
          LIMIT 1',
        [
            'school_id'     => tenant_require(),
            'id'            => $curriculumSubjectId,
            'curriculum_id' => (int) $classroom['curriculum_id'],
        ]
    );

    if ($subject === null) {
        abort(404, 'Cette branche ne figure pas au programme de la classe.');
    }

    $period = tenant_one(
        'grade_periods',
        'id = :id AND academic_year_id = :y',
        ['id' => $periodId, 'y' => (int) $classroom['academic_year_id']]
    );

    if ($period === null) {
        abort(404, 'Cette période n\'appartient pas à l\'année de la classe.');
    }

    // Autorisation de saisie : la permission ne suffit pas, il faut la
    // branche. L'écran reste consultable en lecture si l'utilisateur a le
    // droit de voir les notes.
    $auth  = grades_service_can_enter($classroomId, $curriculumSubjectId);
    $year  = tenant_find('academic_years', (int) $classroom['academic_year_id']);
    $state = grades_service_period_state($period, $year ?? []);

    if (!$auth['ok'] && !perm_has('grade.view')) {
        abort(403, $auth['message']);
    }

    return [
        'classroom' => $classroom,
        'subject'   => $subject,
        'period'    => $period,
        'year'      => $year,
        'maxPoints' => grades_max_for($subject, $period),
        'canEnter'  => $auth['ok'] && $state['ok'],
        'forced'    => $state['forced'],
        'reason'    => $auth['ok'] ? $state['message'] : $auth['message'],
    ];
}
