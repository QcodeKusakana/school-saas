<?php
/**
 * Module STUDENTS — contrôleurs.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';

/** Année de travail : paramètre d'URL validé, sinon année courante. */
function students_current_year(): ?array
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

/** Classes d'une année, avec leur effectif. */
function students_classrooms_for_year(int $yearId): array
{
    return db_all(
        'SELECT c.*, l.short_name AS level_short, opt.short_name AS option_short,
                COUNT(e.id) AS student_count
           FROM classrooms c
           JOIN curriculums cu ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           JOIN education_levels l ON l.id = cu.education_level_id
           LEFT JOIN options opt ON opt.id = cu.option_id AND opt.school_id = c.school_id
           LEFT JOIN enrollments e ON e.classroom_id = c.id AND e.school_id = c.school_id
                                  AND e.status <> \'cancelled\'
          WHERE c.school_id = :school_id AND c.academic_year_id = :year_id AND c.is_active = 1
          GROUP BY c.id
          ORDER BY l.order_number, c.order_number, c.code',
        ['school_id' => tenant_require(), 'year_id' => $yearId]
    );
}

// ---------------------------------------------------------------------
//  LISTE
// ---------------------------------------------------------------------

function ctrl_students_index(): void
{
    $year = students_current_year();

    if ($year === null) {
        flash_warning('Créez d\'abord une année scolaire.');
        redirect('/referentiel');
    }

    $yearId  = (int) $year['id'];
    $filters = [
        'q'            => input('q', ''),
        'status'       => input('statut', ''),
        'gender'       => input('sexe', ''),
        'classroom_id' => input_int('classe'),
        'unassigned'   => input_bool('sans_classe'),
    ];

    $result = students_repo_search($filters, $yearId, request_page(), request_per_page());

    view('students/index', [
        'year'       => $year,
        'years'      => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'students'   => $result['rows'],
        'total'      => $result['total'],
        'pages'      => $result['pages'],
        'page'       => $result['page'],
        'filters'    => $filters,
        'classrooms' => students_classrooms_for_year($yearId),
        'stats'      => students_repo_year_stats($yearId),
    ], 'app');
}

// ---------------------------------------------------------------------
//  FICHE ÉLÈVE
// ---------------------------------------------------------------------

function ctrl_students_show(string $id): void
{
    $studentId = (int) $id;
    $student   = students_repo_find($studentId);

    if ($student === null) {
        abort(404, 'Élève introuvable.');
    }

    $year = students_current_year();

    view('students/show', [
        'student'      => $student,
        'guardians'    => students_repo_guardians($studentId),
        'enrollments'  => students_repo_enrollments($studentId),
        'history'      => students_repo_history($studentId),
        'orientations' => students_repo_orientations($studentId),
        'year'         => $year,
        'years'        => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'classrooms'   => $year !== null ? students_classrooms_for_year((int) $year['id']) : [],
        'sections'     => tenant_all('sections', 'is_active = 1 ORDER BY order_number'),
        'options'      => db_all(
            'SELECT o.*, s.name AS section_name FROM options o
               JOIN sections s ON s.id = o.section_id AND s.school_id = o.school_id
              WHERE o.school_id = :school_id AND o.is_active = 1
              ORDER BY s.order_number, o.order_number',
            ['school_id' => tenant_require()]
        ),
    ], 'app');
}

// ---------------------------------------------------------------------
//  INSCRIPTION
// ---------------------------------------------------------------------

function ctrl_students_create_form(): void
{
    $year = students_current_year();

    if ($year === null) {
        flash_warning('Créez d\'abord une année scolaire.');
        redirect('/referentiel');
    }

    view('students/form', [
        'student'    => null,
        'year'       => $year,
        'classrooms' => students_classrooms_for_year((int) $year['id']),
    ], 'app');
}

function ctrl_students_store(): void
{
    $result = validate(input_all(), [
        'last_name'       => 'required|string|max:80',
        'post_name'       => 'nullable|string|max:80',
        'first_name'      => 'required|string|max:80',
        'gender'          => 'required|in:M,F',
        'birth_date'      => 'nullable|date|before:today',
        'birth_place'     => 'nullable|string|max:120',
        'nationality'     => 'nullable|string|max:60',
        'address'         => 'nullable|string|max:255',
        'phone'           => 'nullable|phone',
        'email'           => 'nullable|email|max:190',
        'previous_school' => 'nullable|string|max:190',
        'academic_year_id'=> 'required|int',
        'classroom_id'    => 'nullable|int',
    ], [
        'last_name'  => 'Nom',
        'first_name' => 'Prénom',
        'gender'     => 'Sexe',
        'birth_date' => 'Date de naissance',
        'phone'      => 'Téléphone',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        flash_error('Veuillez corriger les champs signalés.');
        redirect('/eleves/nouveau');
    }

    $data = validator_data($result);

    $outcome = students_service_enroll_new(
        [
            'last_name'       => sanitize_string($data['last_name'], 80),
            'post_name'       => sanitize_string($data['post_name'] ?? null, 80),
            'first_name'      => sanitize_string($data['first_name'], 80),
            'gender'          => $data['gender'],
            'birth_date'      => $data['birth_date'] ?? null,
            'birth_place'     => sanitize_string($data['birth_place'] ?? null, 120),
            'nationality'     => sanitize_string($data['nationality'] ?? null, 60) ?? 'Congolaise',
            'address'         => sanitize_string($data['address'] ?? null, 255),
            'phone'           => sanitize_phone($data['phone'] ?? null),
            'email'           => $data['email'] ?? null,
            'previous_school' => sanitize_string($data['previous_school'] ?? null, 190),
        ],
        (int) $data['academic_year_id'],
        isset($data['classroom_id']) ? (int) $data['classroom_id'] : null
    );

    if (!$outcome['ok']) {
        flash_old(input_all());
        flash_error($outcome['message']);
        redirect('/eleves/nouveau');
    }

    flash_success('Élève inscrit. Matricule attribué : ' . $outcome['matricule']);
    redirect('/eleves/' . $outcome['id']);
}

function ctrl_students_update(string $id): void
{
    $studentId = (int) $id;
    $student   = students_repo_find($studentId);

    if ($student === null) {
        abort(404, 'Élève introuvable.');
    }

    $result = validate(input_all(), [
        'last_name'   => 'required|string|max:80',
        'post_name'   => 'nullable|string|max:80',
        'first_name'  => 'required|string|max:80',
        'gender'      => 'required|in:M,F',
        'birth_date'  => 'nullable|date|before:today',
        'birth_place' => 'nullable|string|max:120',
        'nationality' => 'nullable|string|max:60',
        'address'     => 'nullable|string|max:255',
        'phone'       => 'nullable|phone',
        'email'       => 'nullable|email|max:190',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        redirect('/eleves/' . $studentId);
    }

    $after = [
        'last_name'   => sanitize_string((string) input('last_name'), 80),
        'post_name'   => sanitize_string(input('post_name'), 80),
        'first_name'  => sanitize_string((string) input('first_name'), 80),
        'gender'      => (string) input('gender'),
        'birth_date'  => input('birth_date'),
        'birth_place' => sanitize_string(input('birth_place'), 120),
        'nationality' => sanitize_string(input('nationality'), 60),
        'address'     => sanitize_string(input('address'), 255),
        'phone'       => sanitize_phone(input('phone')),
        'email'       => input('email'),
    ];

    tenant_update('students', $after, 'id = :id', ['id' => $studentId]);
    audit_update('student', $studentId, $student, $after, 'Modification du dossier élève');

    flash_success('Dossier mis à jour.');
    redirect('/eleves/' . $studentId);
}

// ---------------------------------------------------------------------
//  RÉINSCRIPTION ET AFFECTATION
// ---------------------------------------------------------------------

function ctrl_students_re_enroll(string $id): void
{
    $studentId = (int) $id;

    $result = validate(input_all(), [
        'academic_year_id' => 'required|int',
        'classroom_id'     => 'nullable|int',
    ]);

    if (!validator_passes($result)) {
        flash_error('Année scolaire manquante.');
        redirect('/eleves/' . $studentId);
    }

    $outcome = students_service_re_enroll(
        $studentId,
        (int) input_int('academic_year_id'),
        input_int('classroom_id'),
        input_bool('repeated')
    );

    $outcome['ok']
        ? flash_success('Élève réinscrit.')
        : flash_error($outcome['message']);

    redirect('/eleves/' . $studentId);
}

function ctrl_students_assign(string $id): void
{
    $studentId    = (int) $id;
    $enrollmentId = input_int('enrollment_id');
    $classroomId  = input_int('classroom_id');

    if ($enrollmentId === null || $classroomId === null) {
        flash_error('Inscription ou classe manquante.');
        redirect('/eleves/' . $studentId);
    }

    $outcome = students_service_assign_classroom($enrollmentId, $classroomId);

    $outcome['ok']
        ? flash_success('Élève affecté à la classe.')
        : flash_error($outcome['message']);

    redirect('/eleves/' . $studentId);
}

// ---------------------------------------------------------------------
//  TUTEURS
// ---------------------------------------------------------------------

function ctrl_students_add_guardian(string $id): void
{
    $studentId = (int) $id;

    $result = validate(input_all(), [
        'last_name'    => 'required|string|max:80',
        'post_name'    => 'nullable|string|max:80',
        'first_name'   => 'required|string|max:80',
        'phone'        => 'required|phone',
        'phone_alt'    => 'nullable|phone',
        'email'        => 'nullable|email|max:190',
        'address'      => 'nullable|string|max:255',
        'profession'   => 'nullable|string|max:120',
        'relationship' => 'required|in:pere,mere,tuteur,oncle,tante,frere,soeur,grand_parent,autre',
    ], [
        'last_name'    => 'Nom du tuteur',
        'first_name'   => 'Prénom du tuteur',
        'phone'        => 'Téléphone',
        'relationship' => 'Lien de parenté',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        flash_error('Veuillez corriger les champs du tuteur.');
        redirect('/eleves/' . $studentId);
    }

    $outcome = students_service_attach_guardian($studentId, [
        'last_name'    => sanitize_string((string) input('last_name'), 80),
        'post_name'    => sanitize_string(input('post_name'), 80),
        'first_name'   => sanitize_string((string) input('first_name'), 80),
        'gender'       => input('gender'),
        'phone'        => (string) input('phone'),
        'phone_alt'    => input('phone_alt'),
        'email'        => input('email'),
        'address'      => sanitize_string(input('address'), 255),
        'profession'   => sanitize_string(input('profession'), 120),
        'relationship' => (string) input('relationship'),
        'is_primary'   => input_bool('is_primary'),
        'is_emergency' => input_bool('is_emergency'),
        'can_pickup'   => input_bool('can_pickup'),
        'is_payer'     => input_bool('is_payer'),
    ]);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
    } elseif ($outcome['created']) {
        flash_success('Tuteur créé et rattaché à l\'élève.');
    } else {
        flash_success('Tuteur existant retrouvé par son téléphone et rattaché à l\'élève.');
    }

    redirect('/eleves/' . $studentId);
}

function ctrl_students_detach_guardian(string $id, string $linkId): void
{
    $studentId = (int) $id;

    $deleted = tenant_delete(
        'student_guardians',
        'id = :id AND student_id = :student_id',
        ['id' => (int) $linkId, 'student_id' => $studentId]
    );

    if ($deleted > 0) {
        audit_log('delete', 'student_guardian', (int) $linkId, null, null, 'Détachement d\'un tuteur');
        flash_success('Tuteur détaché de l\'élève. Sa fiche est conservée.');
    } else {
        flash_error('Liaison introuvable.');
    }

    redirect('/eleves/' . $studentId);
}

// ---------------------------------------------------------------------
//  ORIENTATION ET STATUT
// ---------------------------------------------------------------------

function ctrl_students_orient(string $id): void
{
    $studentId = (int) $id;

    $result = validate(input_all(), [
        'academic_year_id' => 'required|int',
        'section_id'       => 'required|int',
        'option_id'        => 'required|int',
        'final_percentage' => 'nullable|numeric|between:0,100',
        'notes'            => 'nullable|string|max:255',
    ], [
        'section_id'       => 'Section',
        'option_id'        => 'Option',
        'final_percentage' => 'Pourcentage',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        redirect('/eleves/' . $studentId);
    }

    $outcome = students_service_orient(
        $studentId,
        (int) input_int('academic_year_id'),
        (int) input_int('section_id'),
        (int) input_int('option_id'),
        input_float('final_percentage'),
        (string) input('notes', '')
    );

    $outcome['ok']
        ? flash_success('Orientation enregistrée dans le parcours de l\'élève.')
        : flash_error($outcome['message']);

    redirect('/eleves/' . $studentId);
}

function ctrl_students_change_status(string $id): void
{
    $studentId = (int) $id;
    $status    = (string) input('status', '');

    $outcome = students_service_change_status($studentId, $status, (string) input('reason', ''));

    $outcome['ok']
        ? flash_success($outcome['message'])
        : flash_error($outcome['message']);

    redirect('/eleves/' . $studentId);
}

// ---------------------------------------------------------------------
//  RECHERCHE RAPIDE (barre supérieure)
// ---------------------------------------------------------------------

function ctrl_students_quick_search(): void
{
    $term = trim((string) input('q', ''));

    if (mb_strlen($term) < 2) {
        json_ok([]);
    }

    $year = students_current_year();

    $result = students_repo_search(
        ['q' => $term],
        $year !== null ? (int) $year['id'] : 0,
        1,
        10
    );

    json_ok(array_map(static fn (array $s): array => [
        'id'        => (int) $s['id'],
        'matricule' => $s['matricule'],
        'name'      => full_name($s['last_name'], $s['post_name'], $s['first_name']),
        'classroom' => $s['classroom_name'],
        'url'       => url('/eleves/' . (int) $s['id']),
    ], $result['rows']));
}
