<?php
/**
 * Module TEACHERS — contrôleurs.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';

/** Année de travail : paramètre d'URL validé, sinon année courante. */
function teachers_current_year(): ?array
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
//  LISTE
// ---------------------------------------------------------------------

function ctrl_teachers_index(): void
{
    $year = teachers_current_year();

    $filters = [
        'q'         => trim((string) input('q', '')),
        'status'    => (string) input('statut', ''),
        'specialty' => (string) input('specialite', ''),
    ];

    $result = teachers_repo_search($filters, max(1, (int) input_int('page', 1)));

    view('teachers/index', [
        'title'       => 'Enseignants',
        'teachers'    => $result['rows'],
        'total'       => $result['total'],
        'pages'       => $result['pages'],
        'page'        => $result['page'],
        'filters'     => $filters,
        'statuses'    => TEACHER_STATUSES,
        'year'        => $year,
        'years'       => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
    ]);
}

// ---------------------------------------------------------------------
//  FICHE
// ---------------------------------------------------------------------

function ctrl_teachers_show(string $id): void
{
    $teacherId = (int) $id;
    $teacher   = teachers_repo_find($teacherId);

    if ($teacher === null) {
        abort(404, 'Enseignant introuvable.');
    }

    $year = teachers_current_year();
    $yearId = $year !== null ? (int) $year['id'] : 0;

    view('teachers/show', [
        'title'          => full_name($teacher['last_name'], $teacher['post_name'], $teacher['first_name']),
        'teacher'        => $teacher,
        'year'           => $year,
        'years'          => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'assignments'    => $yearId > 0 ? teachers_repo_assignments($teacherId, $yearId) : [],
        'mainClassrooms' => $yearId > 0 ? teachers_repo_main_classrooms($teacherId, $yearId) : [],
        'weeklyLoad'     => $yearId > 0 ? teachers_repo_weekly_load($teacherId, $yearId) : 0.0,
        'statuses'       => TEACHER_STATUSES,
        'employmentTypes' => TEACHER_EMPLOYMENT_TYPES,
        // La liste des comptes n'est rendue que sous teacher.manage :
        // inutile de l'interroger pour un consultant en lecture seule.
        'availableUsers' => can('teacher.manage')
            ? teachers_repo_available_users($teacherId)
            : [],
    ]);
}

// ---------------------------------------------------------------------
//  CRÉATION
// ---------------------------------------------------------------------

function ctrl_teachers_create_form(): void
{
    view('teachers/form', [
        'title'           => 'Nouvel enseignant',
        'teacher'         => null,
        'statuses'        => TEACHER_STATUSES,
        'employmentTypes' => TEACHER_EMPLOYMENT_TYPES,
        'availableUsers'  => teachers_repo_available_users(),
    ]);
}

function ctrl_teachers_store(): void
{
    $result = validate(input_all(), teachers_validation_rules());

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        flash_old(input_all());
        redirect('/enseignants/nouveau');
    }

    $outcome = teachers_service_create(teachers_payload_from_input(input_all()));

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        flash_old(input_all());
        redirect('/enseignants/nouveau');
    }

    flash_success('Fiche enseignant créée.');
    redirect('/enseignants/' . (int) $outcome['id']);
}

// ---------------------------------------------------------------------
//  MODIFICATION
// ---------------------------------------------------------------------

function ctrl_teachers_update(string $id): void
{
    $teacherId = (int) $id;

    if (teachers_repo_find($teacherId) === null) {
        abort(404, 'Enseignant introuvable.');
    }

    $result = validate(input_all(), teachers_validation_rules());

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        redirect('/enseignants/' . $teacherId);
    }

    // Seules les clés réellement transmises sont écrites : un envoi
    // partiel ne doit pas vider les colonnes qu'il ne mentionne pas.
    $outcome = teachers_service_update($teacherId, teachers_payload_from_input(input_all(), true));

    $outcome['ok']
        ? flash_success('Fiche mise à jour.')
        : flash_error($outcome['message']);

    redirect('/enseignants/' . $teacherId);
}

function ctrl_teachers_change_status(string $id): void
{
    $teacherId = (int) $id;
    $status    = (string) input('status', '');
    $reason    = sanitize_string(input('reason'), 255) ?? '';

    $outcome = teachers_service_change_status($teacherId, $status, $reason);

    $outcome['ok']
        ? flash_success('Statut mis à jour.')
        : flash_error($outcome['message']);

    redirect('/enseignants/' . $teacherId);
}

// ---------------------------------------------------------------------
//  RÉPARTITION DES BRANCHES
// ---------------------------------------------------------------------

/** Écran de répartition d'une classe : chaque branche et son enseignant. */
function ctrl_teachers_classroom_grid(string $id): void
{
    $classroomId = (int) $id;
    $classroom   = tenant_find('classrooms', $classroomId);

    if ($classroom === null) {
        abort(404, 'Classe introuvable.');
    }

    $year = tenant_find('academic_years', (int) $classroom['academic_year_id']);

    view('teachers/repartition', [
        'title'     => 'Répartition — ' . $classroom['name'],
        'classroom' => $classroom,
        'year'      => $year,
        'rows'      => teachers_repo_classroom_grid($classroomId),
        'teachers'  => tenant_all(
            'teachers',
            'status = :s AND deleted_at IS NULL ORDER BY last_name, post_name, first_name',
            ['s' => 'active']
        ),
        'mainTeacher' => $classroom['main_teacher_id'] !== null
            ? teachers_repo_find((int) $classroom['main_teacher_id'])
            : null,
    ]);
}

function ctrl_teachers_assign(string $id): void
{
    $classroomId = (int) $id;
    $teacherId   = input_int('teacher_id');
    $subjectId   = input_int('curriculum_subject_id');
    $hours       = input('weekly_hours');

    if ($teacherId === null || $subjectId === null) {
        flash_error('Enseignant ou branche manquant.');
        redirect('/classes/' . $classroomId . '/repartition');
    }

    $outcome = teachers_service_assign(
        $teacherId,
        $classroomId,
        $subjectId,
        ($hours !== null && $hours !== '') ? (float) $hours : null
    );

    $outcome['ok']
        ? flash_success('Branche attribuée.')
        : flash_error($outcome['message']);

    redirect('/classes/' . $classroomId . '/repartition');
}

function ctrl_teachers_unassign(string $id, string $assignmentId): void
{
    $classroomId = (int) $id;

    $outcome = teachers_service_unassign(
        $classroomId,
        (int) $assignmentId,
        input('confirm') === 'oui'
    );

    $outcome['ok']
        ? flash_success('Affectation retirée.')
        : flash_error($outcome['message']);

    redirect('/classes/' . $classroomId . '/repartition');
}

function ctrl_teachers_set_main(string $id): void
{
    $classroomId = (int) $id;
    $teacherId   = input_int('main_teacher_id');

    $outcome = teachers_service_set_main_teacher($classroomId, $teacherId);

    $outcome['ok']
        ? flash_success('Titulaire enregistré.')
        : flash_error($outcome['message']);

    redirect('/classes/' . $classroomId . '/repartition');
}

// ---------------------------------------------------------------------
//  OUTILS INTERNES
// ---------------------------------------------------------------------

/** Règles de validation communes à la création et à la modification. */
function teachers_validation_rules(): array
{
    return [
        'last_name'       => 'required|string|max:80',
        'post_name'       => 'nullable|string|max:80',
        'first_name'      => 'required|string|max:80',
        'gender'          => 'nullable|in:M,F',
        'birth_date'      => 'nullable|date|before:today',
        'matricule'       => 'nullable|string|max:30',
        'phone'           => 'nullable|phone',
        'phone_alt'       => 'nullable|phone',
        'email'           => 'nullable|email|max:190',
        'address'         => 'nullable|string|max:255',
        'hire_date'       => 'nullable|date',
        'employment_type' => 'nullable|in:permanent,contract,volunteer',
        'qualification'   => 'nullable|string|max:120',
        'specialty'       => 'nullable|string|max:120',
        'notes'           => 'nullable|string|max:255',
    ];
}

/**
 * Construit le tableau d'écriture à partir des entrées.
 *
 * @param bool $partial Ne retenir que les clés présentes dans l'envoi.
 */
function teachers_payload_from_input(array $source, bool $partial = false): array
{
    $transforms = [
        'last_name'       => static fn ($v) => sanitize_string((string) $v, 80),
        'post_name'       => static fn ($v) => sanitize_string($v, 80),
        'first_name'      => static fn ($v) => sanitize_string((string) $v, 80),
        'gender'          => static fn ($v) => $v !== '' ? (string) $v : null,
        'birth_date'      => static fn ($v) => $v !== '' ? $v : null,
        'matricule'       => static fn ($v) => sanitize_string($v, 30),
        'phone'           => static fn ($v) => sanitize_phone($v),
        'phone_alt'       => static fn ($v) => sanitize_phone($v),
        'email'           => static fn ($v) => $v !== '' ? $v : null,
        'address'         => static fn ($v) => sanitize_string($v, 255),
        'hire_date'       => static fn ($v) => $v !== '' ? $v : null,
        'employment_type' => static fn ($v) => $v !== '' ? (string) $v : 'permanent',
        'qualification'   => static fn ($v) => sanitize_string($v, 120),
        'specialty'       => static fn ($v) => sanitize_string($v, 120),
        'notes'           => static fn ($v) => sanitize_string($v, 255),
    ];

    $payload = [];

    foreach ($transforms as $field => $transform) {
        if ($partial && !array_key_exists($field, $source)) {
            continue;
        }

        $value = $source[$field] ?? '';

        // Un champ posté sous forme de tableau (name="post_name[]")
        // provoquerait une erreur de type dans sanitize_string(). On le
        // ramène à une chaîne vide : la validation a déjà rejeté la
        // requête si le champ était obligatoire.
        $payload[$field] = $transform(is_scalar($value) ? (string) $value : '');
    }

    // Le compte de connexion n'est écrit que s'il figure explicitement
    // dans l'envoi : il ouvre un périmètre d'accès, il ne doit jamais
    // être modifié par omission.
    if (array_key_exists('user_id', $source)) {
        $payload['user_id'] = ($source['user_id'] !== '' && $source['user_id'] !== null)
            ? (int) $source['user_id']
            : null;
    }

    return $payload;
}
