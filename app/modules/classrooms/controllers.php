<?php
/**
 * Module CLASSROOMS — classes de l'établissement.
 *
 * Une classe est rattachée à un PROGRAMME (curriculum), lui-même porteur
 * du niveau, de la section et de l'option. Cette contrainte garantit
 * qu'une classe ne peut pas être incohérente avec le programme qu'elle
 * suit, et évite de dupliquer trois colonnes.
 */

declare(strict_types=1);

/** Année de travail : paramètre d'URL validé, sinon année courante. */
function classrooms_current_year(): ?array
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

/** Classes d'une année, avec effectif et taux de remplissage. */
function classrooms_repo_list(int $yearId): array
{
    return db_all(
        'SELECT c.*,
                cu.name AS curriculum_name, cu.status AS curriculum_status,
                l.name AS level_name, l.short_name AS level_short, l.order_number AS level_order,
                cy.short_name AS cycle_short,
                sec.short_name AS section_short, opt.short_name AS option_short,
                r.name AS room_name,
                COUNT(e.id) AS student_count,
                SUM(CASE WHEN s.gender = \'M\' THEN 1 ELSE 0 END) AS boys,
                SUM(CASE WHEN s.gender = \'F\' THEN 1 ELSE 0 END) AS girls
           FROM classrooms c
           JOIN curriculums cu     ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           JOIN education_levels l ON l.id = cu.education_level_id
           JOIN education_cycles cy ON cy.id = l.cycle_id
           LEFT JOIN sections sec  ON sec.id = cu.section_id AND sec.school_id = c.school_id
           LEFT JOIN options opt   ON opt.id = cu.option_id AND opt.school_id = c.school_id
           LEFT JOIN rooms r       ON r.id = c.room_id AND r.school_id = c.school_id
           LEFT JOIN enrollments e ON e.classroom_id = c.id AND e.school_id = c.school_id
                                  AND e.status <> \'cancelled\'
           LEFT JOIN students s    ON s.id = e.student_id AND s.school_id = c.school_id
          WHERE c.school_id = :school_id AND c.academic_year_id = :year_id
          GROUP BY c.id
          ORDER BY l.order_number, c.order_number, c.code',
        ['school_id' => tenant_require(), 'year_id' => $yearId]
    );
}

/** Programmes actifs d'une année — seuls ceux-ci peuvent porter une classe. */
function classrooms_repo_curriculums(int $yearId): array
{
    return db_all(
        'SELECT cu.id, cu.name, cu.status,
                l.short_name AS level_short, l.order_number AS level_order,
                opt.short_name AS option_short
           FROM curriculums cu
           JOIN education_levels l ON l.id = cu.education_level_id
           LEFT JOIN options opt   ON opt.id = cu.option_id AND opt.school_id = cu.school_id
          WHERE cu.school_id = :school_id AND cu.academic_year_id = :year_id
            AND cu.status = \'active\'
          ORDER BY l.order_number, opt.order_number',
        ['school_id' => tenant_require(), 'year_id' => $yearId]
    );
}

function ctrl_classrooms_index(): void
{
    $year = classrooms_current_year();

    if ($year === null) {
        flash_warning('Créez d\'abord une année scolaire.');
        redirect('/referentiel');
    }

    view('classrooms/index', [
        'year'        => $year,
        'years'       => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'classrooms'  => classrooms_repo_list((int) $year['id']),
        'curriculums' => classrooms_repo_curriculums((int) $year['id']),
        'rooms'       => tenant_all('rooms', 'is_active = 1 ORDER BY name'),
    ], 'app');
}

function ctrl_classrooms_store(): void
{
    $result = validate(input_all(), [
        'academic_year_id' => 'required|int',
        'curriculum_id'    => 'required|int',
        'code'             => 'required|string|max:30|alpha_dash',
        'name'             => 'required|string|max:120',
        'capacity'         => 'required|int|between:1,200',
        'room_id'          => 'nullable|int',
    ], [
        'curriculum_id' => 'Programme',
        'code'          => 'Code',
        'name'          => 'Nom',
        'capacity'      => 'Capacité',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        flash_error('Veuillez corriger les champs signalés.');
        redirect('/classes');
    }

    $yearId = (int) input_int('academic_year_id');
    $year   = tenant_find('academic_years', $yearId);

    if ($year === null) {
        flash_error('Année scolaire introuvable.');
        redirect('/classes');
    }

    // Le programme doit appartenir à l'école ET à l'année : sans ce
    // contrôle, un identifiant forgé rattacherait la classe au
    // programme d'un autre établissement.
    $curriculumId = (int) input_int('curriculum_id');
    $curriculum   = tenant_one(
        'curriculums',
        'id = :id AND academic_year_id = :y',
        ['id' => $curriculumId, 'y' => $yearId]
    );

    if ($curriculum === null) {
        flash_error('Le programme sélectionné n\'existe pas pour cette année scolaire.');
        redirect('/classes?annee=' . $yearId);
    }

    $code = strtoupper((string) input('code'));

    if (tenant_one('classrooms', 'academic_year_id = :y AND code = :c', ['y' => $yearId, 'c' => $code]) !== null) {
        flash_error('Une classe portant le code « ' . $code . ' » existe déjà pour cette année.');
        redirect('/classes?annee=' . $yearId);
    }

    $roomId = input_int('room_id');

    if ($roomId !== null && tenant_find('rooms', $roomId) === null) {
        flash_error('Salle introuvable.');
        redirect('/classes?annee=' . $yearId);
    }

    $id = tenant_insert('classrooms', [
        'academic_year_id' => $yearId,
        'curriculum_id'    => $curriculumId,
        'room_id'          => $roomId,
        'code'             => $code,
        'name'             => sanitize_string((string) input('name'), 120),
        'capacity'         => (int) input_int('capacity'),
    ]);

    audit_log('create', 'classroom', $id, null, ['code' => $code], 'Création de la classe ' . $code);

    flash_success('Classe « ' . input('name') . ' » créée.');
    redirect('/classes?annee=' . $yearId);
}

function ctrl_classrooms_update(string $id): void
{
    $classroomId = (int) $id;
    $classroom   = tenant_find('classrooms', $classroomId);

    if ($classroom === null) {
        abort(404, 'Classe introuvable.');
    }

    $result = validate(input_all(), [
        'name'     => 'required|string|max:120',
        'capacity' => 'required|int|between:1,200',
        'room_id'  => 'nullable|int',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        redirect('/classes?annee=' . (int) $classroom['academic_year_id']);
    }

    // La capacité ne peut pas descendre sous l'effectif déjà inscrit.
    $current = (int) db_value(
        'SELECT COUNT(*) FROM enrollments
          WHERE school_id = :school_id AND classroom_id = :id AND status <> \'cancelled\'',
        ['school_id' => tenant_require(), 'id' => $classroomId]
    );

    $capacity = (int) input_int('capacity');

    if ($capacity < $current) {
        flash_error(sprintf(
            'Impossible : la classe compte déjà %d élèves, la capacité ne peut pas être réduite à %d.',
            $current,
            $capacity
        ));
        redirect('/classes?annee=' . (int) $classroom['academic_year_id']);
    }

    $roomId = input_int('room_id');

    if ($roomId !== null && tenant_find('rooms', $roomId) === null) {
        flash_error('Salle introuvable.');
        redirect('/classes?annee=' . (int) $classroom['academic_year_id']);
    }

    $after = [
        'name'      => sanitize_string((string) input('name'), 120),
        'capacity'  => $capacity,
        'room_id'   => $roomId,
        'is_active' => input_bool('is_active') ? 1 : 0,
    ];

    tenant_update('classrooms', $after, 'id = :id', ['id' => $classroomId]);
    audit_update('classroom', $classroomId, $classroom, $after, 'Modification de la classe');

    flash_success('Classe mise à jour.');
    redirect('/classes?annee=' . (int) $classroom['academic_year_id']);
}

function ctrl_classrooms_show(string $id): void
{
    $classroomId = (int) $id;
    $classroom   = tenant_find('classrooms', $classroomId);

    require_once APP_PATH . '/modules/students/repositories.php';

    // Le périmètre vaut pour la classe entière, pas seulement pour la
    // liste nominative : l'effectif, le taux de remplissage et le nom du
    // titulaire sont eux aussi des informations de gestion.
    //
    // 404 et non 403 : confirmer l'existence d'une classe qu'on n'a pas
    // le droit de consulter renseigne déjà sur l'organisation de
    // l'établissement.
    if ($classroom === null || !students_can_view_classroom($classroomId)) {
        abort(404, 'Classe introuvable.');
    }

    $details = db_one(
        'SELECT c.*, cu.name AS curriculum_name, l.name AS level_name,
                sec.name AS section_name, opt.name AS option_name,
                y.code AS year_code, r.name AS room_name
           FROM classrooms c
           JOIN curriculums cu     ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           JOIN education_levels l ON l.id = cu.education_level_id
           JOIN academic_years y   ON y.id = c.academic_year_id AND y.school_id = c.school_id
           LEFT JOIN sections sec  ON sec.id = cu.section_id AND sec.school_id = c.school_id
           LEFT JOIN options opt   ON opt.id = cu.option_id AND opt.school_id = c.school_id
           LEFT JOIN rooms r       ON r.id = c.room_id AND r.school_id = c.school_id
          WHERE c.id = :id AND c.school_id = :school_id
          LIMIT 1',
        ['id' => $classroomId, 'school_id' => tenant_require()]
    );

    view('classrooms/show', [
        'classroom' => $details,
        'students'  => students_repo_classroom_students($classroomId),
    ], 'app');
}

/** Création rapide d'une salle. */
function ctrl_classrooms_store_room(): void
{
    $result = validate(input_all(), [
        'code'     => 'required|string|max:30|alpha_dash|unique:rooms,code',
        'name'     => 'required|string|max:100',
        'capacity' => 'nullable|int|between:1,500',
        'building' => 'nullable|string|max:60',
    ], ['code' => 'Code', 'name' => 'Nom', 'capacity' => 'Capacité']);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        flash_error('Veuillez corriger les champs de la salle.');
        redirect('/classes');
    }

    $id = tenant_insert('rooms', [
        'code'     => strtoupper((string) input('code')),
        'name'     => sanitize_string((string) input('name'), 100),
        'capacity' => input_int('capacity'),
        'building' => sanitize_string(input('building'), 60),
    ]);

    audit_log('create', 'room', $id, null, ['code' => input('code')], 'Création d\'une salle');

    flash_success('Salle ajoutée.');
    redirect('/classes');
}
