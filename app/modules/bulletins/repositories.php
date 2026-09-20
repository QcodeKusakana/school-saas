<?php
/**
 * Module BULLETINS — dépôts.
 */

declare(strict_types=1);

/** Bulletin figé d'une inscription pour un regroupement. */
function bulletins_repo_find(int $enrollmentId, string $periodKey): ?array
{
    return tenant_one(
        'bulletins',
        'enrollment_id = :e AND period_key = :k',
        ['e' => $enrollmentId, 'k' => $periodKey]
    );
}

/**
 * Tous les bulletins publiés d'une inscription, par regroupement.
 *
 * @return array<string, array>
 */
function bulletins_repo_for_enrollment(int $enrollmentId): array
{
    $rows = tenant_all('bulletins', 'enrollment_id = :e', ['e' => $enrollmentId]);
    $byKey = [];

    foreach ($rows as $row) {
        $byKey[(string) $row['period_key']] = $row;
    }

    return $byKey;
}

/**
 * Bulletins publiés d'une classe pour un regroupement, classés.
 *
 * Une seule requête : c'est la liste que le conseil de classe parcourt.
 */
function bulletins_repo_classroom(int $classroomId, string $periodKey): array
{
    return db_all(
        'SELECT b.*, s.matricule, s.last_name, s.post_name, s.first_name, s.gender,
                e.id AS enrollment_id, e.student_id
           FROM bulletins b
           JOIN enrollments e ON e.id = b.enrollment_id AND e.school_id = b.school_id
           JOIN students s    ON s.id = e.student_id AND s.school_id = e.school_id
          WHERE b.school_id = :school_id
            AND e.classroom_id = :classroom_id
            AND b.period_key = :period_key
            AND e.status <> \'cancelled\'
            AND s.deleted_at IS NULL
          ORDER BY b.class_rank IS NULL, b.class_rank, s.last_name, s.first_name',
        [
            'school_id'    => tenant_require(),
            'classroom_id' => $classroomId,
            'period_key'   => $periodKey,
        ]
    );
}

/**
 * Dates de publication par regroupement pour une classe.
 *
 * Permet à l'écran de dire ce qui est publié et depuis quand, sans
 * charger tous les bulletins.
 *
 * @return array<string, array{count: int, published_at: string}>
 */
function bulletins_repo_classroom_status(int $classroomId): array
{
    $rows = db_all(
        'SELECT b.period_key, COUNT(*) AS total, MAX(b.published_at) AS published_at
           FROM bulletins b
           JOIN enrollments e ON e.id = b.enrollment_id AND e.school_id = b.school_id
          WHERE b.school_id = :school_id
            AND e.classroom_id = :classroom_id
            AND e.status <> \'cancelled\'
          GROUP BY b.period_key',
        ['school_id' => tenant_require(), 'classroom_id' => $classroomId]
    );

    $status = [];

    foreach ($rows as $row) {
        $status[(string) $row['period_key']] = [
            'count'        => (int) $row['total'],
            'published_at' => (string) $row['published_at'],
        ];
    }

    return $status;
}

/** En-tête d'un bulletin : élève, classe, école, année. */
function bulletins_repo_header(int $enrollmentId): ?array
{
    return db_one(
        'SELECT e.id AS enrollment_id, e.student_id, e.repeated_year,
                s.matricule, s.last_name, s.post_name, s.first_name,
                s.gender, s.birth_date, s.birth_place,
                c.id AS classroom_id, c.code AS classroom_code, c.name AS classroom_name,
                y.id AS year_id, y.code AS year_code, y.name AS year_name, y.status AS year_status,
                l.name AS level_name, sec.name AS section_name, opt.name AS option_name,
                sch.name AS school_name, sch.code AS school_code,
                sch.province, sch.city, sch.commune, sch.director_name,
                t.last_name AS teacher_last_name, t.post_name AS teacher_post_name,
                t.first_name AS teacher_first_name
           FROM enrollments e
           JOIN students s        ON s.id = e.student_id AND s.school_id = e.school_id
           JOIN classrooms c      ON c.id = e.classroom_id AND c.school_id = e.school_id
           JOIN academic_years y  ON y.id = e.academic_year_id AND y.school_id = e.school_id
           JOIN curriculums cu    ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           JOIN education_levels l ON l.id = cu.education_level_id
           JOIN schools sch       ON sch.id = e.school_id
           LEFT JOIN sections sec ON sec.id = cu.section_id AND sec.school_id = cu.school_id
           LEFT JOIN options opt  ON opt.id = cu.option_id AND opt.school_id = cu.school_id
           LEFT JOIN teachers t   ON t.id = c.main_teacher_id AND t.school_id = c.school_id
                                 AND t.deleted_at IS NULL
          WHERE e.school_id = :school_id AND e.id = :enrollment_id
          LIMIT 1',
        ['school_id' => tenant_require(), 'enrollment_id' => $enrollmentId]
    );
}

// =====================================================================
//  LE DOCUMENT PUBLIÉ (phase 6A)
//
//  Ces fonctions ne lisent QUE du figé. Aucune ne touche aux cotes
//  vivantes : c'est ce qui permet de remettre à une famille, des mois
//  plus tard, exactement le document qu'elle a reçu.
// =====================================================================

/** Un bulletin publié, avec l'élève et la classe — null si inconnu. */
function bulletins_repo_published(int $bulletinId): ?array
{
    return db_one(
        'SELECT b.*,
                e.student_id, e.academic_year_id, e.classroom_id,
                s.matricule, s.last_name, s.post_name, s.first_name,
                s.gender, s.birth_date, s.birth_place,
                c.name AS classroom_name,
                y.code AS year_code, y.name AS year_name,
                u.last_name AS publisher_last_name, u.first_name AS publisher_first_name
           FROM bulletins b
           JOIN enrollments e ON e.id = b.enrollment_id AND e.school_id = b.school_id
           JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
      LEFT JOIN classrooms c ON c.id = e.classroom_id AND c.school_id = e.school_id
      LEFT JOIN academic_years y ON y.id = e.academic_year_id AND y.school_id = e.school_id
      LEFT JOIN users u ON u.id = b.published_by
          WHERE b.id = :id AND b.school_id = :school_id',
        ['id' => $bulletinId, 'school_id' => tenant_require()]
    );
}

/**
 * Le détail figé d'un bulletin, remis dans la forme d'un relevé.
 *
 * MÊME FORME QU'UN RELEVÉ CALCULÉ
 * -------------------------------
 * Le retour reprend la structure de bulletins_service_compute() —
 * `periods` et `subjects[…]['cells']` — pour qu'un même gabarit puisse
 * afficher l'un ou l'autre. Deux structures pour un seul document
 * finiraient par diverger, et c'est précisément sur cette divergence
 * que le défaut du détail non figé était né.
 *
 * @return array{periods: array<string,array>, subjects: array<int,array>}
 */
function bulletins_repo_lines(int $bulletinId): array
{
    $rows = db_all(
        'SELECT * FROM bulletin_lines
          WHERE school_id = :school_id AND bulletin_id = :bulletin_id
          ORDER BY domain_order, subdomain_order, subject_order, subject_name, period_order',
        ['school_id' => tenant_require(), 'bulletin_id' => $bulletinId]
    );

    $periods  = [];
    $subjects = [];

    foreach ($rows as $row) {
        $code = (string) $row['period_code'];

        $periods[$code] ??= [
            'code'  => $code,
            'name'  => (string) $row['period_name'],
            'order' => (int) $row['period_order'],
        ];

        // Une branche retirée du programme garde ses lignes : la clé
        // retombe donc sur le libellé figé plutôt que sur un identifiant
        // qui peut être NULL.
        $key = $row['curriculum_subject_id'] !== null
            ? 'cs' . (int) $row['curriculum_subject_id']
            : 'nom' . md5((string) $row['subject_name']);

        $subjects[$key] ??= [
            'id'              => $row['curriculum_subject_id'] !== null
                ? (int) $row['curriculum_subject_id'] : null,
            'name'            => (string) $row['subject_name'],
            'short'           => $row['subject_short'],
            'order'           => (int) $row['subject_order'],
            'ranking'         => (int) $row['counts_for_ranking'] === 1,
            'domain'          => $row['domain_code'],
            'domain_name'     => $row['domain_name'],
            'domain_order'    => (int) $row['domain_order'],
            'subdomain_name'  => $row['subdomain_name'],
            'subdomain_order' => (int) $row['subdomain_order'],
            'max_unit'        => (float) $row['max_unit'],
            'cells'           => [],
        ];

        $subjects[$key]['cells'][$code] = [
            'points'    => $row['points'] !== null ? (float) $row['points'] : null,
            'max'       => (float) $row['max_points'],
            'is_absent' => (int) $row['is_absent'] === 1,
        ];
    }

    uasort($periods, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

    return ['periods' => $periods, 'subjects' => $subjects];
}

/**
 * Les bulletins publiés d'un élève, du plus récent au plus ancien.
 *
 * L'ordre d'affichage suit celui des regroupements, pas la date de
 * publication : republier le premier semestre en mars ne doit pas le
 * faire passer devant le second.
 */
function bulletins_repo_published_for_student(int $studentId): array
{
    return db_all(
        'SELECT b.id, b.period_key, b.percentage, b.total_points, b.max_points,
                b.class_rank, b.class_size, b.decision, b.published_at,
                b.absent_count, b.missing_grades,
                e.academic_year_id,
                c.name AS classroom_name,
                y.code AS year_code
           FROM bulletins b
           JOIN enrollments e ON e.id = b.enrollment_id AND e.school_id = b.school_id
      LEFT JOIN classrooms c ON c.id = e.classroom_id AND c.school_id = e.school_id
      LEFT JOIN academic_years y ON y.id = e.academic_year_id AND y.school_id = e.school_id
          WHERE b.school_id = :school_id AND e.student_id = :student_id
          ORDER BY y.starts_on DESC, b.period_key',
        ['school_id' => tenant_require(), 'student_id' => $studentId]
    );
}
