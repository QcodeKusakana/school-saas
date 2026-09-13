<?php
/**
 * Module CURRICULUM — dépôts (accès aux données).
 *
 * Toutes les requêtes du référentiel scolaire vivent ici.
 * Les tables sections, options, subjects, grade_periods, curriculums et
 * curriculum_subjects portent school_id : les lectures passent par les
 * helpers tenant_* ou mentionnent explicitement school_id.
 */

declare(strict_types=1);

// =====================================================================
//  MODÈLE NATIONAL (tables globales, hors périmètre multi-école)
// =====================================================================

/** Domaines d'apprentissage du programme national. */
function curriculum_repo_domains(): array
{
    return db_all(
        'SELECT * FROM learning_domains WHERE is_active = 1 ORDER BY order_number',
        [],
        true // table globale
    );
}

/** Sections nationales, avec le nombre d'options de chacune. */
function curriculum_repo_reference_sections(): array
{
    return db_all(
        'SELECT s.*, COUNT(o.id) AS options_count
           FROM reference_sections s
           LEFT JOIN reference_options o ON o.reference_section_id = s.id AND o.is_active = 1
          WHERE s.is_active = 1
          GROUP BY s.id
          ORDER BY s.order_number',
        [],
        true
    );
}

/** Branches nationales, avec le libellé de leur domaine. */
function curriculum_repo_reference_subjects(): array
{
    return db_all(
        'SELECT s.*, d.name AS domain_name, d.short_name AS domain_short
           FROM reference_subjects s
           LEFT JOIN learning_domains d ON d.id = s.domain_id
          WHERE s.is_active = 1
          ORDER BY s.order_number',
        [],
        true
    );
}

// =====================================================================
//  SECTIONS ET OPTIONS DE L'ÉTABLISSEMENT
// =====================================================================

/** Sections de l'école, avec leurs options agrégées. */
function curriculum_repo_sections(bool $activeOnly = false): array
{
    $sql = 'SELECT s.*, c.name AS cycle_name, c.short_name AS cycle_short,
                   COUNT(o.id) AS options_count
              FROM sections s
              JOIN education_cycles c ON c.id = s.cycle_id
              LEFT JOIN options o ON o.section_id = s.id AND o.school_id = s.school_id
                                 AND (:active_opt = 0 OR o.is_active = 1)
             WHERE s.school_id = :school_id';

    if ($activeOnly) {
        $sql .= ' AND s.is_active = 1';
    }

    $sql .= ' GROUP BY s.id ORDER BY s.order_number, s.name';

    return db_all($sql, [
        'school_id'  => tenant_require(),
        'active_opt' => $activeOnly ? 1 : 0,
    ]);
}

/** Options de l'école, éventuellement filtrées sur une section. */
function curriculum_repo_options(?int $sectionId = null, bool $activeOnly = false): array
{
    $sql = 'SELECT o.*, s.name AS section_name, s.short_name AS section_short
              FROM options o
              JOIN sections s ON s.id = o.section_id AND s.school_id = o.school_id
             WHERE o.school_id = :school_id';

    $params = ['school_id' => tenant_require()];

    if ($sectionId !== null) {
        $sql .= ' AND o.section_id = :section_id';
        $params['section_id'] = $sectionId;
    }

    if ($activeOnly) {
        $sql .= ' AND o.is_active = 1 AND s.is_active = 1';
    }

    $sql .= ' ORDER BY s.order_number, o.order_number, o.name';

    return db_all($sql, $params);
}

function curriculum_repo_find_section(int $id): ?array
{
    return tenant_find('sections', $id);
}

function curriculum_repo_find_option(int $id): ?array
{
    return tenant_find('options', $id);
}

// =====================================================================
//  BRANCHES
// =====================================================================

/** Branches de l'école, avec leur domaine et leur nombre d'usages. */
function curriculum_repo_subjects(bool $activeOnly = false): array
{
    $sql = 'SELECT s.*, d.name AS domain_name, d.short_name AS domain_short,
                   COUNT(cs.id) AS usage_count
              FROM subjects s
              LEFT JOIN learning_domains d ON d.id = s.domain_id
              LEFT JOIN curriculum_subjects cs ON cs.subject_id = s.id AND cs.school_id = s.school_id
             WHERE s.school_id = :school_id';

    if ($activeOnly) {
        $sql .= ' AND s.is_active = 1';
    }

    $sql .= ' GROUP BY s.id ORDER BY d.order_number, s.name';

    return db_all($sql, ['school_id' => tenant_require()]);
}

function curriculum_repo_find_subject(int $id): ?array
{
    return tenant_find('subjects', $id);
}

/** Codes de branches déjà présents dans l'école (pour éviter les doublons à l'import). */
function curriculum_repo_existing_subject_codes(): array
{
    $rows = db_all(
        'SELECT code FROM subjects WHERE school_id = :school_id',
        ['school_id' => tenant_require()]
    );

    return array_column($rows, 'code');
}

// =====================================================================
//  PÉRIODES D'ÉVALUATION
// =====================================================================

/** Périodes d'une année scolaire, dans l'ordre du bulletin. */
function curriculum_repo_periods(int $academicYearId): array
{
    return db_all(
        'SELECT * FROM grade_periods
          WHERE school_id = :school_id AND academic_year_id = :year_id
          ORDER BY order_number',
        ['school_id' => tenant_require(), 'year_id' => $academicYearId]
    );
}

function curriculum_repo_count_periods(int $academicYearId): int
{
    return (int) db_value(
        'SELECT COUNT(*) FROM grade_periods
          WHERE school_id = :school_id AND academic_year_id = :year_id',
        ['school_id' => tenant_require(), 'year_id' => $academicYearId]
    );
}

// =====================================================================
//  PROGRAMMES
// =====================================================================

/**
 * Programmes d'une année scolaire, enrichis du niveau, de la section et
 * de l'option, avec le nombre de branches et le total des maxima.
 */
function curriculum_repo_programs(int $academicYearId): array
{
    return db_all(
        'SELECT c.*,
                l.name       AS level_name,
                l.short_name AS level_short,
                l.order_number AS level_order,
                cy.short_name AS cycle_short,
                sec.short_name AS section_short,
                opt.short_name AS option_short,
                COUNT(cs.id)   AS subjects_count,
                COALESCE(SUM(CASE WHEN cs.is_optional = 0 THEN cs.max_points ELSE 0 END), 0) AS total_max
           FROM curriculums c
           JOIN education_levels l  ON l.id = c.education_level_id
           JOIN education_cycles cy ON cy.id = l.cycle_id
           LEFT JOIN sections sec   ON sec.id = c.section_id AND sec.school_id = c.school_id
           LEFT JOIN options  opt   ON opt.id = c.option_id  AND opt.school_id = c.school_id
           LEFT JOIN curriculum_subjects cs ON cs.curriculum_id = c.id AND cs.school_id = c.school_id
          WHERE c.school_id = :school_id AND c.academic_year_id = :year_id
          GROUP BY c.id
          ORDER BY l.order_number, sec.order_number, opt.order_number',
        ['school_id' => tenant_require(), 'year_id' => $academicYearId]
    );
}

/** Un programme, avec tous ses libellés résolus. */
function curriculum_repo_find_program(int $id): ?array
{
    return db_one(
        'SELECT c.*,
                l.name AS level_name, l.short_name AS level_short, l.requires_option,
                cy.name AS cycle_name, cy.code AS cycle_code,
                sec.name AS section_name, opt.name AS option_name,
                y.code AS year_code, y.status AS year_status
           FROM curriculums c
           JOIN education_levels l  ON l.id = c.education_level_id
           JOIN education_cycles cy ON cy.id = l.cycle_id
           JOIN academic_years y    ON y.id = c.academic_year_id AND y.school_id = c.school_id
           LEFT JOIN sections sec   ON sec.id = c.section_id AND sec.school_id = c.school_id
           LEFT JOIN options  opt   ON opt.id = c.option_id  AND opt.school_id = c.school_id
          WHERE c.id = :id AND c.school_id = :school_id
          LIMIT 1',
        ['id' => $id, 'school_id' => tenant_require()]
    );
}

/** Branches d'un programme, dans l'ordre d'impression du bulletin. */
function curriculum_repo_program_subjects(int $curriculumId): array
{
    return db_all(
        'SELECT cs.*, s.name AS subject_name, s.short_name AS subject_short, s.code AS subject_code,
                d.name AS domain_name, d.short_name AS domain_short, d.order_number AS domain_order
           FROM curriculum_subjects cs
           JOIN subjects s ON s.id = cs.subject_id AND s.school_id = cs.school_id
           LEFT JOIN learning_domains d ON d.id = s.domain_id
          WHERE cs.curriculum_id = :curriculum_id AND cs.school_id = :school_id
          ORDER BY cs.order_number, d.order_number, s.name',
        ['curriculum_id' => $curriculumId, 'school_id' => tenant_require()]
    );
}

/** Branches actives non encore rattachées à ce programme. */
function curriculum_repo_available_subjects(int $curriculumId): array
{
    return db_all(
        'SELECT s.*, d.short_name AS domain_short, d.order_number AS domain_order
           FROM subjects s
           LEFT JOIN learning_domains d ON d.id = s.domain_id
          WHERE s.school_id = :school_id
            AND s.is_active = 1
            AND s.id NOT IN (
                SELECT cs.subject_id FROM curriculum_subjects cs
                 WHERE cs.curriculum_id = :curriculum_id AND cs.school_id = :school_id2
            )
          ORDER BY d.order_number, s.name',
        [
            'school_id'     => tenant_require(),
            'school_id2'    => tenant_require(),
            'curriculum_id' => $curriculumId,
        ]
    );
}

/** Niveaux du référentiel national, limités aux cycles activés par l'école. */
function curriculum_repo_available_levels(): array
{
    return db_all(
        'SELECT l.*, c.name AS cycle_name, c.short_name AS cycle_short, c.code AS cycle_code
           FROM education_levels l
           JOIN education_cycles c ON c.id = l.cycle_id
           JOIN school_cycles sc   ON sc.cycle_id = c.id
                                  AND sc.school_id = :school_id
                                  AND sc.is_active = 1
          WHERE l.is_active = 1
          ORDER BY l.order_number',
        ['school_id' => tenant_require()]
    );
}

/** Vrai si un programme existe déjà pour cette combinaison. */
function curriculum_repo_program_exists(string $uniqueKey, ?int $excludeId = null): bool
{
    $sql    = 'SELECT 1 FROM curriculums WHERE school_id = :school_id AND uniq_key = :key';
    $params = ['school_id' => tenant_require(), 'key' => $uniqueKey];

    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude';
        $params['exclude'] = $excludeId;
    }

    return db_exists($sql . ' LIMIT 1', $params);
}
