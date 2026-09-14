<?php
/**
 * Module CURRICULUM — services (règles métier du référentiel scolaire).
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * Structure standard des périodes d'évaluation en RDC.
 *
 * Quatre périodes et deux examens semestriels. Le multiplicateur
 * s'applique au maximum de base de chaque branche :
 *
 *   branche à 20 points  →  20 par période, 40 à l'examen
 *   total du semestre    →  1 + 1 + 2 = 4 fois le maximum de base
 *   total général        →  8 fois le maximum de base
 *
 * C'est exactement la structure du bulletin officiel EPST.
 */
const CURRICULUM_STANDARD_PERIODS = [
    ['code' => 'P1',  'name' => 'Première période',      'type' => 'period', 'semester' => 1, 'multiplier' => 1.0],
    ['code' => 'P2',  'name' => 'Deuxième période',      'type' => 'period', 'semester' => 1, 'multiplier' => 1.0],
    ['code' => 'EX1', 'name' => 'Examen 1er semestre',   'type' => 'exam',   'semester' => 1, 'multiplier' => 2.0],
    ['code' => 'P3',  'name' => 'Troisième période',     'type' => 'period', 'semester' => 2, 'multiplier' => 1.0],
    ['code' => 'P4',  'name' => 'Quatrième période',     'type' => 'period', 'semester' => 2, 'multiplier' => 1.0],
    ['code' => 'EX2', 'name' => 'Examen 2nd semestre',   'type' => 'exam',   'semester' => 2, 'multiplier' => 2.0],
];

/**
 * Maximum de points proposé par défaut, selon le cycle et la branche.
 *
 * Ce ne sont que des valeurs de départ, modifiables branche par branche.
 * Elles reflètent l'usage courant : les branches de spécialité pèsent
 * davantage dans la filière qui les porte.
 */
const CURRICULUM_DEFAULT_MAX = [
    'PRIMAIRE'  => ['FRANCAIS' => 40, 'MATH' => 40, 'default' => 20],
    'CTEB'      => ['FRANCAIS' => 40, 'MATH' => 40, 'default' => 20],
    'HUMANITES' => ['default' => 20],
];

/** Branches qui, par convention, ne comptent pas dans le classement. */
const CURRICULUM_NON_RANKING = ['CONDUITE', 'RELIGION'];

// =====================================================================
//  IMPORT DU RÉFÉRENTIEL NATIONAL
// =====================================================================

/**
 * Copie le modèle national dans l'établissement.
 *
 * Idempotent : les codes déjà présents sont ignorés, jamais écrasés.
 * Une école qui a renommé « Mathématiques » en « Math » conserve donc
 * son libellé si l'import est rejoué.
 *
 * @return array{sections: int, options: int, subjects: int}
 */
function curriculum_service_import_national(): array
{
    $schoolId = tenant_require();

    return db_transaction(static function () use ($schoolId): array {
        $counts = ['sections' => 0, 'options' => 0, 'subjects' => 0];

        // --- Sections ---------------------------------------------------
        $existingSections = array_column(
            db_all('SELECT code, id FROM sections WHERE school_id = :school_id', ['school_id' => $schoolId]),
            'id',
            'code'
        );

        foreach (curriculum_repo_reference_sections() as $reference) {
            if (isset($existingSections[$reference['code']])) {
                continue;
            }

            $existingSections[$reference['code']] = tenant_insert('sections', [
                'cycle_id'             => (int) $reference['cycle_id'],
                'reference_section_id' => (int) $reference['id'],
                'code'                 => $reference['code'],
                'name'                 => $reference['name'],
                'short_name'           => $reference['short_name'],
                'order_number'         => (int) $reference['order_number'],
            ]);
            $counts['sections']++;
        }

        // --- Options ----------------------------------------------------
        $existingOptions = array_column(
            db_all('SELECT code FROM options WHERE school_id = :school_id', ['school_id' => $schoolId]),
            'code'
        );

        $referenceOptions = db_all(
            'SELECT o.*, s.code AS section_code
               FROM reference_options o
               JOIN reference_sections s ON s.id = o.reference_section_id
              WHERE o.is_active = 1
              ORDER BY s.order_number, o.order_number',
            [],
            true
        );

        foreach ($referenceOptions as $reference) {
            if (in_array($reference['code'], $existingOptions, true)) {
                continue;
            }

            // La section parente doit exister dans l'école : sans elle,
            // l'option serait orpheline.
            $sectionId = $existingSections[$reference['section_code']] ?? null;

            if ($sectionId === null) {
                continue;
            }

            tenant_insert('options', [
                'section_id'          => (int) $sectionId,
                'reference_option_id' => (int) $reference['id'],
                'code'                => $reference['code'],
                'name'                => $reference['name'],
                'short_name'          => $reference['short_name'],
                'order_number'        => (int) $reference['order_number'],
            ]);
            $counts['options']++;
        }

        // --- Branches ---------------------------------------------------
        $existingSubjects = curriculum_repo_existing_subject_codes();

        foreach (curriculum_repo_reference_subjects() as $reference) {
            if (in_array($reference['code'], $existingSubjects, true)) {
                continue;
            }

            tenant_insert('subjects', [
                'domain_id'            => $reference['domain_id'] !== null ? (int) $reference['domain_id'] : null,
                'reference_subject_id' => (int) $reference['id'],
                'code'                 => $reference['code'],
                'name'                 => $reference['name'],
                'short_name'           => $reference['short_name'],
            ]);
            $counts['subjects']++;
        }

        audit_log(
            'import',
            'curriculum',
            null,
            null,
            $counts,
            'Import du référentiel national'
        );

        return $counts;
    });
}

// =====================================================================
//  PÉRIODES D'ÉVALUATION
// =====================================================================

/**
 * Crée les six périodes standard pour une année scolaire.
 * Ne fait rien si des périodes existent déjà.
 *
 * @return int Nombre de périodes créées
 */
function curriculum_service_create_standard_periods(int $academicYearId): int
{
    if (curriculum_repo_count_periods($academicYearId) > 0) {
        return 0;
    }

    // L'année doit appartenir à l'école courante : sans ce contrôle, un
    // identifiant forgé dans le formulaire créerait des périodes chez
    // une autre école.
    $year = tenant_find('academic_years', $academicYearId);

    if ($year === null) {
        abort(404, 'Année scolaire introuvable.');
    }

    return db_transaction(static function () use ($academicYearId, $year): int {
        $created = 0;

        foreach (CURRICULUM_STANDARD_PERIODS as $index => $period) {
            tenant_insert('grade_periods', [
                'academic_year_id' => $academicYearId,
                'code'             => $period['code'],
                'name'             => $period['name'],
                'period_type'      => $period['type'],
                'semester'         => $period['semester'],
                'order_number'     => $index + 1,
                'max_multiplier'   => $period['multiplier'],
            ]);
            $created++;
        }

        audit_log(
            'create',
            'grade_periods',
            $academicYearId,
            null,
            ['count' => $created],
            'Création des périodes pour l\'année ' . $year['code']
        );

        return $created;
    });
}

// =====================================================================
//  PROGRAMMES
// =====================================================================

/**
 * Construit la clé d'unicité d'un programme.
 *
 * Les NULL sont remplacés par un jeton explicite : un index UNIQUE
 * ordinaire laisserait passer deux programmes identiques, MySQL ne
 * comparant jamais deux NULL entre eux.
 */
function curriculum_service_build_key(int $academicYearId, int $levelId, ?int $sectionId, ?int $optionId): string
{
    return sprintf(
        'Y%d|L%d|S%s|O%s',
        $academicYearId,
        $levelId,
        $sectionId !== null ? (string) $sectionId : 'none',
        $optionId !== null ? (string) $optionId : 'none'
    );
}

/**
 * Crée un programme.
 *
 * @return array{ok: bool, id: int|null, message: string}
 */
function curriculum_service_create_program(
    int $academicYearId,
    int $levelId,
    ?int $sectionId,
    ?int $optionId
): array {
    // --- Contrôles d'appartenance ------------------------------------
    // Chaque identifiant reçu du formulaire est revérifié dans le
    // périmètre de l'école : un identifiant forgé ne peut pas désigner
    // la ressource d'un autre établissement.
    $year = tenant_find('academic_years', $academicYearId);

    if ($year === null) {
        return ['ok' => false, 'id' => null, 'message' => 'Année scolaire introuvable.'];
    }

    if ($year['status'] === 'closed' || $year['status'] === 'archived') {
        return [
            'ok'      => false,
            'id'      => null,
            'message' => 'Cette année scolaire est clôturée : son programme ne peut plus être modifié.',
        ];
    }

    $level = db_one(
        'SELECT l.*, c.code AS cycle_code, c.name AS cycle_name
           FROM education_levels l
           JOIN education_cycles c ON c.id = l.cycle_id
          WHERE l.id = :id LIMIT 1',
        ['id' => $levelId],
        true // référentiel national, table globale
    );

    if ($level === null) {
        return ['ok' => false, 'id' => null, 'message' => 'Niveau scolaire introuvable.'];
    }

    // --- Cohérence métier : section et option -------------------------
    if ((int) $level['requires_option'] === 1) {
        if ($sectionId === null || $optionId === null) {
            return [
                'ok'      => false,
                'id'      => null,
                'message' => 'Les humanités exigent une section et une option.',
            ];
        }
    } else {
        // Hors humanités, section et option n'ont pas de sens : on les
        // neutralise plutôt que de refuser, l'utilisateur a pu les
        // laisser remplies en changeant de niveau.
        $sectionId = null;
        $optionId  = null;
    }

    if ($sectionId !== null) {
        $section = tenant_find('sections', $sectionId);

        if ($section === null) {
            return ['ok' => false, 'id' => null, 'message' => 'Section introuvable.'];
        }

        $option = tenant_find('options', (int) $optionId);

        if ($option === null || (int) $option['section_id'] !== $sectionId) {
            return [
                'ok'      => false,
                'id'      => null,
                'message' => 'L\'option sélectionnée n\'appartient pas à cette section.',
            ];
        }
    }

    // --- Unicité ------------------------------------------------------
    $key = curriculum_service_build_key($academicYearId, $levelId, $sectionId, $optionId);

    if (curriculum_repo_program_exists($key)) {
        return [
            'ok'      => false,
            'id'      => null,
            'message' => 'Un programme existe déjà pour cette combinaison niveau / section / option.',
        ];
    }

    // --- Libellé lisible ----------------------------------------------
    $name = $level['name'];

    if ($sectionId !== null) {
        $name .= ' — ' . $option['short_name'];
    }

    $id = db_transaction(static function () use ($academicYearId, $levelId, $sectionId, $optionId, $key, $name): int {
        $newId = tenant_insert('curriculums', [
            'academic_year_id'   => $academicYearId,
            'education_level_id' => $levelId,
            'section_id'         => $sectionId,
            'option_id'          => $optionId,
            'uniq_key'           => $key,
            'name'               => $name,
            'status'             => 'draft',
            'created_by'         => auth_id(),
        ]);

        audit_log('create', 'curriculum', $newId, null, ['name' => $name], 'Création du programme');

        return $newId;
    });

    return ['ok' => true, 'id' => $id, 'message' => ''];
}

/**
 * Ajoute une branche à un programme, avec son maximum de points.
 *
 * @return array{ok: bool, message: string}
 */
function curriculum_service_add_subject(int $curriculumId, int $subjectId, int $maxPoints, ?float $weeklyHours = null): array
{
    $program = curriculum_repo_find_program($curriculumId);

    if ($program === null) {
        return ['ok' => false, 'message' => 'Programme introuvable.'];
    }

    if ($program['status'] === 'archived') {
        return ['ok' => false, 'message' => 'Ce programme est archivé et ne peut plus être modifié.'];
    }

    $subject = tenant_find('subjects', $subjectId);

    if ($subject === null) {
        return ['ok' => false, 'message' => 'Branche introuvable.'];
    }

    if ($maxPoints < 1 || $maxPoints > 200) {
        return ['ok' => false, 'message' => 'Le maximum doit être compris entre 1 et 200 points.'];
    }

    $alreadyPresent = db_exists(
        'SELECT 1 FROM curriculum_subjects
          WHERE school_id = :school_id AND curriculum_id = :curriculum_id AND subject_id = :subject_id
          LIMIT 1',
        [
            'school_id'     => tenant_require(),
            'curriculum_id' => $curriculumId,
            'subject_id'    => $subjectId,
        ]
    );

    if ($alreadyPresent) {
        return ['ok' => false, 'message' => 'Cette branche figure déjà dans le programme.'];
    }

    $nextOrder = (int) db_value(
        'SELECT COALESCE(MAX(order_number), 0) + 10 FROM curriculum_subjects
          WHERE school_id = :school_id AND curriculum_id = :curriculum_id',
        ['school_id' => tenant_require(), 'curriculum_id' => $curriculumId]
    );

    db_transaction(static function () use ($curriculumId, $subjectId, $maxPoints, $weeklyHours, $nextOrder, $subject): void {
        tenant_insert('curriculum_subjects', [
            'curriculum_id'      => $curriculumId,
            'subject_id'         => $subjectId,
            'max_points'         => $maxPoints,
            'weekly_hours'       => $weeklyHours,
            'counts_for_ranking' => in_array($subject['code'], CURRICULUM_NON_RANKING, true) ? 0 : 1,
            'order_number'       => $nextOrder,
        ]);

        audit_log(
            'create',
            'curriculum_subject',
            $curriculumId,
            null,
            ['subject' => $subject['code'], 'max_points' => $maxPoints],
            'Ajout de la branche ' . $subject['name'] . ' au programme'
        );
    });

    return ['ok' => true, 'message' => ''];
}

/**
 * Remplit un programme vide avec toutes les branches actives et leurs
 * maxima par défaut. Fait gagner un temps considérable : un programme
 * de primaire compte une douzaine de branches.
 *
 * @return int Nombre de branches ajoutées
 */
function curriculum_service_fill_program(int $curriculumId): int
{
    $program = curriculum_repo_find_program($curriculumId);

    if ($program === null) {
        abort(404, 'Programme introuvable.');
    }

    $available = curriculum_repo_available_subjects($curriculumId);

    if ($available === []) {
        return 0;
    }

    $cycleCode = (string) $program['cycle_code'];
    $defaults  = CURRICULUM_DEFAULT_MAX[$cycleCode] ?? ['default' => 20];

    return db_transaction(static function () use ($available, $curriculumId, $defaults, $program): int {
        $order = (int) db_value(
            'SELECT COALESCE(MAX(order_number), 0) FROM curriculum_subjects
              WHERE school_id = :school_id AND curriculum_id = :curriculum_id',
            ['school_id' => tenant_require(), 'curriculum_id' => $curriculumId]
        );

        $added = 0;

        foreach ($available as $subject) {
            $order += 10;

            tenant_insert('curriculum_subjects', [
                'curriculum_id'      => $curriculumId,
                'subject_id'         => (int) $subject['id'],
                'max_points'         => $defaults[$subject['code']] ?? $defaults['default'],
                'counts_for_ranking' => in_array($subject['code'], CURRICULUM_NON_RANKING, true) ? 0 : 1,
                'order_number'       => $order,
            ]);
            $added++;
        }

        audit_log(
            'create',
            'curriculum',
            $curriculumId,
            null,
            ['added' => $added],
            'Remplissage automatique du programme ' . $program['name']
        );

        return $added;
    });
}

/**
 * Met à jour les maxima de plusieurs branches en une seule soumission.
 *
 * @param array $rows [curriculum_subject_id => ['max_points' => int, …]]
 * @return int Nombre de lignes modifiées
 */
function curriculum_service_update_subjects(int $curriculumId, array $rows): int
{
    $program = curriculum_repo_find_program($curriculumId);

    if ($program === null) {
        abort(404, 'Programme introuvable.');
    }

    if ($program['status'] === 'archived') {
        flash_error('Ce programme est archivé et ne peut plus être modifié.');

        return 0;
    }

    // Lignes réellement rattachées à CE programme et à CETTE école :
    // toute autre clé envoyée par le formulaire est ignorée.
    $allowed = array_column(curriculum_repo_program_subjects($curriculumId), 'id');

    return db_transaction(static function () use ($rows, $allowed, $curriculumId, $program): int {
        $updated = 0;
        $blocked = [];

        foreach ($rows as $id => $values) {
            $id = (int) $id;

            if (!in_array((string) $id, array_map('strval', $allowed), true)) {
                continue;
            }

            $maxPoints = (int) ($values['max_points'] ?? 0);

            if ($maxPoints < 1 || $maxPoints > 200) {
                continue;
            }

            // Le maximum d'une branche ne change plus dès qu'une cote
            // existe.
            //
            // grades.max_points est figé à la saisie et n'est jamais
            // recalculé — c'est ce qui rend un bulletin reproductible.
            // Mais si le programme, lui, continue de bouger, la borne de
            // saisie et le barème stocké cessent de parler de la même
            // chose : une branche portée de 40 à 100 laissait enregistrer
            // 95 sur une ligne figée à 40, soit 237 % du maximum.
            //
            // Interdire la modification est la seule correction qui ferme
            // le problème à sa source. Une école qui veut vraiment
            // changer un barème en cours d'année doit d'abord assumer la
            // suppression des cotes concernées : c'est une décision, pas
            // un effet de bord.
            $current = tenant_one(
                'curriculum_subjects',
                'id = :id AND curriculum_id = :curriculum_id',
                ['id' => $id, 'curriculum_id' => $curriculumId]
            );

            if ($current !== null && (int) $current['max_points'] !== $maxPoints) {
                $existingGrades = (int) db_value(
                    'SELECT COUNT(*) FROM grades
                      WHERE school_id = :school_id AND curriculum_subject_id = :id',
                    ['school_id' => tenant_require(), 'id' => $id]
                );

                if ($existingGrades > 0) {
                    $blocked[] = $id;
                    continue;
                }
            }

            $hours = isset($values['weekly_hours']) && $values['weekly_hours'] !== ''
                ? (float) str_replace(',', '.', (string) $values['weekly_hours'])
                : null;

            $updated += tenant_update('curriculum_subjects', [
                'max_points'         => $maxPoints,
                'weekly_hours'       => $hours,
                'is_optional'        => !empty($values['is_optional']) ? 1 : 0,
                'counts_for_ranking' => !empty($values['counts_for_ranking']) ? 1 : 0,
                'order_number'       => (int) ($values['order_number'] ?? 0),
            ], 'id = :id AND curriculum_id = :curriculum_id', [
                'id'            => $id,
                'curriculum_id' => $curriculumId,
            ]);
        }

        if ($blocked !== []) {
            flash_warning(
                count($blocked) . ' branche(s) n\'ont pas été modifiées : des cotes y sont '
                . 'déjà saisies, et changer leur maximum fausserait les bulletins déjà établis.'
            );
        }

        if ($updated > 0) {
            audit_log(
                'update',
                'curriculum',
                $curriculumId,
                null,
                ['rows' => $updated, 'blocked' => count($blocked)],
                'Modification des maxima du programme ' . $program['name']
            );
        }

        return $updated;
    });
}

/**
 * Duplique un programme vers une autre année scolaire.
 *
 * C'est l'opération la plus utilisée en pratique : à chaque rentrée, les
 * programmes changent peu. La copie évite de tout ressaisir tout en
 * préservant l'historique — les bulletins de l'année précédente
 * continuent de pointer vers l'ancien programme et ses anciens maxima.
 *
 * @return array{ok: bool, id: int|null, message: string}
 */
function curriculum_service_duplicate(int $curriculumId, int $targetYearId): array
{
    $source = curriculum_repo_find_program($curriculumId);

    if ($source === null) {
        return ['ok' => false, 'id' => null, 'message' => 'Programme source introuvable.'];
    }

    $creation = curriculum_service_create_program(
        $targetYearId,
        (int) $source['education_level_id'],
        $source['section_id'] !== null ? (int) $source['section_id'] : null,
        $source['option_id'] !== null ? (int) $source['option_id'] : null
    );

    if (!$creation['ok']) {
        return $creation;
    }

    $newId   = (int) $creation['id'];
    $subjects = curriculum_repo_program_subjects($curriculumId);

    db_transaction(static function () use ($subjects, $newId, $source): void {
        foreach ($subjects as $row) {
            tenant_insert('curriculum_subjects', [
                'curriculum_id'      => $newId,
                'subject_id'         => (int) $row['subject_id'],
                'max_points'         => (int) $row['max_points'],
                'weekly_hours'       => $row['weekly_hours'],
                'is_optional'        => (int) $row['is_optional'],
                'counts_for_ranking' => (int) $row['counts_for_ranking'],
                'order_number'       => (int) $row['order_number'],
            ]);
        }

        audit_log(
            'create',
            'curriculum',
            $newId,
            null,
            ['from' => $source['id'], 'subjects' => count($subjects)],
            'Duplication du programme ' . $source['name']
        );
    });

    return ['ok' => true, 'id' => $newId, 'message' => ''];
}

/**
 * Vérifie qu'un programme est exploitable avant de l'activer.
 *
 * @return string[] Liste des anomalies, vide si le programme est valide
 */
function curriculum_service_validate(int $curriculumId): array
{
    $subjects = curriculum_repo_program_subjects($curriculumId);
    $issues   = [];

    if ($subjects === []) {
        $issues[] = 'Le programme ne contient aucune branche.';

        return $issues;
    }

    $counted = array_filter($subjects, static fn (array $s): bool => (int) $s['is_optional'] === 0);

    if ($counted === []) {
        $issues[] = 'Toutes les branches sont facultatives : le total serait nul.';
    }

    $ranked = array_filter($subjects, static fn (array $s): bool => (int) $s['counts_for_ranking'] === 1);

    if ($ranked === []) {
        $issues[] = 'Aucune branche ne compte pour le classement : aucun rang ne pourra être calculé.';
    }

    foreach ($subjects as $subject) {
        if ((int) $subject['max_points'] < 1) {
            $issues[] = 'La branche « ' . $subject['subject_name'] . ' » a un maximum nul.';
        }
    }

    return $issues;
}

/** Active un programme après validation. */
function curriculum_service_activate(int $curriculumId): array
{
    $issues = curriculum_service_validate($curriculumId);

    if ($issues !== []) {
        return ['ok' => false, 'message' => implode(' ', $issues)];
    }

    tenant_update('curriculums', ['status' => 'active'], 'id = :id', ['id' => $curriculumId]);

    audit_log('update', 'curriculum', $curriculumId, ['status' => 'draft'], ['status' => 'active'], 'Activation du programme');

    return ['ok' => true, 'message' => ''];
}
