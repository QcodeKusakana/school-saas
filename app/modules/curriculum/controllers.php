<?php
/**
 * Module CURRICULUM — contrôleurs.
 *
 * Écrans du référentiel scolaire : sections, options, branches,
 * périodes d'évaluation et programmes.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';

/**
 * Année scolaire de travail.
 *
 * Prend l'année passée en paramètre si elle appartient à l'école, sinon
 * l'année courante. Retourne null si l'école n'en a aucune.
 */
function curriculum_current_year(): ?array
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
//  VUE D'ENSEMBLE
// ---------------------------------------------------------------------

function ctrl_curriculum_index(): void
{
    $year = curriculum_current_year();

    view('curriculum/index', [
        'year'         => $year,
        'years'        => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'sections'     => curriculum_repo_sections(),
        'options'      => curriculum_repo_options(),
        'subjects'     => curriculum_repo_subjects(),
        'periods'      => $year !== null ? curriculum_repo_periods((int) $year['id']) : [],
        'programs'     => $year !== null ? curriculum_repo_programs((int) $year['id']) : [],
        'levels'       => curriculum_repo_available_levels(),
    ], 'app');
}

/** Import du référentiel national dans l'établissement. */
function ctrl_curriculum_import(): void
{
    $counts = curriculum_service_import_national();

    if (array_sum($counts) === 0) {
        flash_info('Le référentiel national était déjà importé : rien n\'a été modifié.');
    } else {
        flash_success(sprintf(
            'Référentiel importé : %d section(s), %d option(s), %d branche(s).',
            $counts['sections'],
            $counts['options'],
            $counts['subjects']
        ));
    }

    redirect('/referentiel');
}

// ---------------------------------------------------------------------
//  PÉRIODES D'ÉVALUATION
// ---------------------------------------------------------------------

function ctrl_curriculum_create_periods(): void
{
    $yearId = input_int('academic_year_id');

    if ($yearId === null) {
        flash_error('Année scolaire manquante.');
        redirect('/referentiel');
    }

    $created = curriculum_service_create_standard_periods($yearId);

    if ($created === 0) {
        flash_info('Les périodes de cette année scolaire existent déjà.');
    } else {
        flash_success($created . ' périodes d\'évaluation créées (4 périodes + 2 examens semestriels).');
    }

    redirect('/referentiel?annee=' . $yearId);
}

// ---------------------------------------------------------------------
//  BRANCHES
// ---------------------------------------------------------------------

function ctrl_curriculum_subjects(): void
{
    view('curriculum/subjects', [
        'subjects' => curriculum_repo_subjects(),
        'domains'  => curriculum_repo_domains(),
    ], 'app');
}

function ctrl_curriculum_store_subject(): void
{
    $result = validate(input_all(), [
        'code'       => 'required|string|max:40|alpha_dash|unique:subjects,code',
        'name'       => 'required|string|max:150',
        'short_name' => 'required|string|max:50',
        'domain_id'  => 'nullable|int|exists:learning_domains,id',
    ], [
        'code'       => 'Code',
        'name'       => 'Nom',
        'short_name' => 'Libellé court',
        'domain_id'  => 'Domaine',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        flash_error('Veuillez corriger les champs signalés.');
        redirect('/referentiel/branches');
    }

    $code = strtoupper((string) input('code'));

    $id = tenant_insert('subjects', [
        'code'       => $code,
        'name'       => (string) input('name'),
        'short_name' => (string) input('short_name'),
        'domain_id'  => input_int('domain_id'),
    ]);

    audit_log('create', 'subject', $id, null, ['code' => $code], 'Création d\'une branche');

    flash_success('Branche « ' . input('name') . ' » ajoutée.');
    redirect('/referentiel/branches');
}

function ctrl_curriculum_update_subject(string $id): void
{
    $subjectId = (int) $id;
    $subject   = curriculum_repo_find_subject($subjectId);

    if ($subject === null) {
        abort(404, 'Branche introuvable.');
    }

    $result = validate(input_all(), [
        'name'       => 'required|string|max:150',
        'short_name' => 'required|string|max:50',
        'domain_id'  => 'nullable|int|exists:learning_domains,id',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        redirect('/referentiel/branches');
    }

    $after = [
        'name'       => (string) input('name'),
        'short_name' => (string) input('short_name'),
        'domain_id'  => input_int('domain_id'),
        'is_active'  => input_bool('is_active') ? 1 : 0,
    ];

    tenant_update('subjects', $after, 'id = :id', ['id' => $subjectId]);
    audit_update('subject', $subjectId, $subject, $after, 'Modification d\'une branche');

    flash_success('Branche mise à jour.');
    redirect('/referentiel/branches');
}

// ---------------------------------------------------------------------
//  SECTIONS ET OPTIONS
// ---------------------------------------------------------------------

function ctrl_curriculum_sections(): void
{
    view('curriculum/sections', [
        'sections' => curriculum_repo_sections(),
        'options'  => curriculum_repo_options(),
    ], 'app');
}

function ctrl_curriculum_toggle_section(string $id): void
{
    $sectionId = (int) $id;
    $section   = curriculum_repo_find_section($sectionId);

    if ($section === null) {
        abort(404, 'Section introuvable.');
    }

    $newState = (int) $section['is_active'] === 1 ? 0 : 1;

    tenant_update('sections', ['is_active' => $newState], 'id = :id', ['id' => $sectionId]);
    audit_log(
        'update',
        'section',
        $sectionId,
        ['is_active' => $section['is_active']],
        ['is_active' => $newState],
        ($newState === 1 ? 'Activation' : 'Désactivation') . ' de la section ' . $section['name']
    );

    flash_success('Section ' . ($newState === 1 ? 'activée' : 'désactivée') . '.');
    redirect('/referentiel/sections');
}

function ctrl_curriculum_toggle_option(string $id): void
{
    $optionId = (int) $id;
    $option   = curriculum_repo_find_option($optionId);

    if ($option === null) {
        abort(404, 'Option introuvable.');
    }

    $newState = (int) $option['is_active'] === 1 ? 0 : 1;

    tenant_update('options', ['is_active' => $newState], 'id = :id', ['id' => $optionId]);
    audit_log(
        'update',
        'option',
        $optionId,
        ['is_active' => $option['is_active']],
        ['is_active' => $newState],
        ($newState === 1 ? 'Activation' : 'Désactivation') . ' de l\'option ' . $option['name']
    );

    flash_success('Option ' . ($newState === 1 ? 'activée' : 'désactivée') . '.');
    redirect('/referentiel/sections');
}

// ---------------------------------------------------------------------
//  PROGRAMMES
// ---------------------------------------------------------------------

function ctrl_curriculum_programs(): void
{
    $year = curriculum_current_year();

    if ($year === null) {
        flash_warning('Créez d\'abord une année scolaire.');
        redirect('/referentiel');
    }

    view('curriculum/programs', [
        'year'     => $year,
        'years'    => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'programs' => curriculum_repo_programs((int) $year['id']),
        'levels'   => curriculum_repo_available_levels(),
        'sections' => curriculum_repo_sections(true),
        'options'  => curriculum_repo_options(null, true),
    ], 'app');
}

function ctrl_curriculum_store_program(): void
{
    $result = validate(input_all(), [
        'academic_year_id'   => 'required|int',
        'education_level_id' => 'required|int|exists:education_levels,id',
        'section_id'         => 'nullable|int',
        'option_id'          => 'nullable|int',
    ], [
        'education_level_id' => 'Niveau',
        'section_id'         => 'Section',
        'option_id'          => 'Option',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        redirect('/referentiel/programmes');
    }

    $outcome = curriculum_service_create_program(
        (int) input_int('academic_year_id'),
        (int) input_int('education_level_id'),
        input_int('section_id'),
        input_int('option_id')
    );

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        redirect('/referentiel/programmes?annee=' . input_int('academic_year_id'));
    }

    flash_success('Programme créé. Ajoutez-y les branches et leurs maxima.');
    redirect('/referentiel/programmes/' . $outcome['id']);
}

function ctrl_curriculum_show_program(string $id): void
{
    $programId = (int) $id;
    $program   = curriculum_repo_find_program($programId);

    if ($program === null) {
        abort(404, 'Programme introuvable.');
    }

    view('curriculum/program-edit', [
        'program'   => $program,
        'subjects'  => curriculum_repo_program_subjects($programId),
        'available' => curriculum_repo_available_subjects($programId),
        'periods'   => curriculum_repo_periods((int) $program['academic_year_id']),
        'issues'    => curriculum_service_validate($programId),
        'years'     => tenant_all('academic_years', 'id <> :id ORDER BY starts_on DESC', ['id' => (int) $program['academic_year_id']]),
    ], 'app');
}

function ctrl_curriculum_fill_program(string $id): void
{
    $programId = (int) $id;
    $added     = curriculum_service_fill_program($programId);

    if ($added === 0) {
        flash_info('Toutes les branches actives figurent déjà dans ce programme.');
    } else {
        flash_success($added . ' branche(s) ajoutée(s) avec leur maximum par défaut. Ajustez les valeurs si nécessaire.');
    }

    redirect('/referentiel/programmes/' . $programId);
}

function ctrl_curriculum_add_subject(string $id): void
{
    $programId = (int) $id;

    $result = validate(input_all(), [
        'subject_id'   => 'required|int|exists:subjects,id',
        'max_points'   => 'required|int|between:1,200',
        'weekly_hours' => 'nullable|numeric',
    ], [
        'subject_id'   => 'Branche',
        'max_points'   => 'Maximum',
        'weekly_hours' => 'Heures hebdomadaires',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        redirect('/referentiel/programmes/' . $programId);
    }

    $outcome = curriculum_service_add_subject(
        $programId,
        (int) input_int('subject_id'),
        (int) input_int('max_points'),
        input_float('weekly_hours')
    );

    $outcome['ok']
        ? flash_success('Branche ajoutée au programme.')
        : flash_error($outcome['message']);

    redirect('/referentiel/programmes/' . $programId);
}

function ctrl_curriculum_update_subjects(string $id): void
{
    $programId = (int) $id;
    $rows      = input_array('subjects');

    if ($rows === []) {
        flash_warning('Aucune modification à enregistrer.');
        redirect('/referentiel/programmes/' . $programId);
    }

    $updated = curriculum_service_update_subjects($programId, $rows);

    $updated > 0
        ? flash_success($updated . ' ligne(s) mise(s) à jour.')
        : flash_info('Aucune modification détectée.');

    redirect('/referentiel/programmes/' . $programId);
}

function ctrl_curriculum_remove_subject(string $id, string $subjectId): void
{
    $programId = (int) $id;
    $rowId     = (int) $subjectId;

    $program = curriculum_repo_find_program($programId);

    if ($program === null) {
        abort(404, 'Programme introuvable.');
    }

    // La suppression est restreinte au programme ET à l'école courante :
    // les deux conditions sont dans la clause WHERE, pas dans le code appelant.
    $deleted = tenant_delete(
        'curriculum_subjects',
        'id = :id AND curriculum_id = :curriculum_id',
        ['id' => $rowId, 'curriculum_id' => $programId]
    );

    if ($deleted > 0) {
        audit_log('delete', 'curriculum_subject', $rowId, null, null, 'Retrait d\'une branche du programme');
        flash_success('Branche retirée du programme.');
    } else {
        flash_error('Branche introuvable dans ce programme.');
    }

    redirect('/referentiel/programmes/' . $programId);
}

function ctrl_curriculum_activate_program(string $id): void
{
    $programId = (int) $id;
    $program   = curriculum_repo_find_program($programId);

    if ($program === null) {
        abort(404, 'Programme introuvable.');
    }

    $outcome = curriculum_service_activate($programId);

    $outcome['ok']
        ? flash_success('Programme activé : il peut désormais être affecté à des classes.')
        : flash_error($outcome['message']);

    redirect('/referentiel/programmes/' . $programId);
}

function ctrl_curriculum_duplicate_program(string $id): void
{
    $programId    = (int) $id;
    $targetYearId = input_int('target_year_id');

    if ($targetYearId === null) {
        flash_error('Année scolaire de destination manquante.');
        redirect('/referentiel/programmes/' . $programId);
    }

    $outcome = curriculum_service_duplicate($programId, $targetYearId);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        redirect('/referentiel/programmes/' . $programId);
    }

    flash_success('Programme dupliqué avec toutes ses branches et leurs maxima.');
    redirect('/referentiel/programmes/' . $outcome['id']);
}

/**
 * Options d'une section, au format JSON.
 * Alimente la liste déroulante « Option » sans recharger la page.
 */
function ctrl_curriculum_section_options(string $id): void
{
    $options = curriculum_repo_options((int) $id, true);

    json_ok(array_map(static fn (array $o): array => [
        'id'   => (int) $o['id'],
        'name' => $o['name'],
    ], $options));
}
