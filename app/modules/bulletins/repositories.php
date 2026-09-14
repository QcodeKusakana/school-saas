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
