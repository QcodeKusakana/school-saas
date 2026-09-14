<?php
/**
 * Module GRADES — dépôts.
 *
 * Deux requêtes portent tout le module :
 *
 *  · la GRILLE DE SAISIE — les élèves d'une classe et leur cote dans une
 *    branche pour une période ;
 *  · le RELEVÉ D'UN ÉLÈVE — toutes ses cotes de l'année, par branche et
 *    par période, base du futur bulletin.
 *
 * Toutes deux doivent tenir en une requête : une classe de 45 élèves et
 * un programme de 12 branches produiraient sinon des centaines d'appels.
 */

declare(strict_types=1);

/**
 * Inscriptions actives d'une classe, dans l'ordre du registre.
 *
 * Sert à la fois d'affichage et de LISTE BLANCHE à la saisie : une cote
 * ne peut être posée que pour une inscription qui figure ici.
 */
function grades_repo_classroom_enrollments(int $classroomId): array
{
    return db_all(
        'SELECT e.id, e.student_id,
                s.matricule, s.last_name, s.post_name, s.first_name, s.gender
           FROM enrollments e
           JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
          WHERE e.school_id = :school_id
            AND e.classroom_id = :classroom_id
            AND e.status <> \'cancelled\'
            AND s.deleted_at IS NULL
          ORDER BY s.last_name, s.post_name, s.first_name',
        ['school_id' => tenant_require(), 'classroom_id' => $classroomId]
    );
}

/**
 * Maxima DÉJÀ FIGÉS sur une colonne, indexés par inscription.
 *
 * Sert à valider chaque cote contre son propre barème, et non contre
 * celui du programme courant — les deux peuvent différer sur une base
 * antérieure au blocage des modifications de maxima.
 *
 * @return array<int, float>
 */
function grades_repo_frozen_maxima(int $classroomId, int $curriculumSubjectId, int $periodId): array
{
    $rows = db_all(
        'SELECT g.enrollment_id, g.max_points
           FROM grades g
           JOIN enrollments e ON e.id = g.enrollment_id AND e.school_id = g.school_id
          WHERE g.school_id = :school_id
            AND e.classroom_id = :classroom_id
            AND g.curriculum_subject_id = :subject_id
            AND g.grade_period_id = :period_id',
        [
            'school_id'    => tenant_require(),
            'classroom_id' => $classroomId,
            'subject_id'   => $curriculumSubjectId,
            'period_id'    => $periodId,
        ]
    );

    $maxima = [];

    foreach ($rows as $row) {
        $maxima[(int) $row['enrollment_id']] = (float) $row['max_points'];
    }

    return $maxima;
}

/**
 * Grille de saisie : un élève par ligne, sa cote si elle existe.
 *
 * Jointure externe sur grades : une case vide est une cote non saisie,
 * pas une absence de ligne à gérer côté PHP.
 */
function grades_repo_sheet(int $classroomId, int $curriculumSubjectId, int $periodId): array
{
    return db_all(
        'SELECT e.id AS enrollment_id, e.student_id,
                s.matricule, s.last_name, s.post_name, s.first_name, s.gender,
                g.id AS grade_id, g.points, g.max_points, g.is_absent, g.comment,
                g.entered_at, g.updated_at
           FROM enrollments e
           JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
           LEFT JOIN grades g ON g.enrollment_id = e.id
                             AND g.school_id = e.school_id
                             AND g.curriculum_subject_id = :subject_id
                             AND g.grade_period_id = :period_id
          WHERE e.school_id = :school_id
            AND e.classroom_id = :classroom_id
            AND e.status <> \'cancelled\'
            AND s.deleted_at IS NULL
          ORDER BY s.last_name, s.post_name, s.first_name',
        [
            'school_id'    => tenant_require(),
            'classroom_id' => $classroomId,
            'subject_id'   => $curriculumSubjectId,
            'period_id'    => $periodId,
        ]
    );
}

/**
 * Avancement de la saisie pour une classe : chaque branche, chaque
 * période, et le nombre de cotes déjà posées.
 *
 * C'est le tableau de bord du préfet : il montre d'un coup d'œil ce qui
 * manque avant de pouvoir éditer les bulletins.
 */
function grades_repo_classroom_progress(int $classroomId, int $yearId): array
{
    return db_all(
        'SELECT cs.id AS curriculum_subject_id, s.name AS subject_name,
                cs.max_points, cs.order_number,
                gp.id AS period_id, gp.code AS period_code, gp.name AS period_name,
                gp.period_type, gp.max_multiplier, gp.is_locked, gp.order_number AS period_order,
                -- Une ligne dont la cote est NULL et qui n a pas été
                -- marquée « absent » n est PAS une saisie. Compter les
                -- lignes affichait une colonne vierge en vert et
                -- annonçait « complet » sur une classe sans une seule
                -- note.
                COUNT(CASE WHEN g.points IS NOT NULL OR g.is_absent = 1 THEN 1 END) AS entered,
                t.id AS teacher_id, t.last_name AS teacher_last_name,
                t.post_name AS teacher_post_name, t.first_name AS teacher_first_name
           FROM classrooms c
           JOIN curriculum_subjects cs ON cs.curriculum_id = c.curriculum_id
                                      AND cs.school_id = c.school_id
           JOIN subjects s ON s.id = cs.subject_id AND s.school_id = cs.school_id
           JOIN grade_periods gp ON gp.academic_year_id = :year_id
                                AND gp.school_id = c.school_id
           LEFT JOIN teacher_subjects ts ON ts.curriculum_subject_id = cs.id
                                        AND ts.classroom_id = c.id
                                        AND ts.school_id = c.school_id
           LEFT JOIN teachers t ON t.id = ts.teacher_id AND t.school_id = ts.school_id
                               AND t.deleted_at IS NULL
           LEFT JOIN grades g ON g.curriculum_subject_id = cs.id
                             AND g.grade_period_id = gp.id
                             AND g.school_id = c.school_id
                             AND g.enrollment_id IN (
                                   SELECT e.id FROM enrollments e
                                    WHERE e.school_id = c.school_id
                                      AND e.classroom_id = c.id
                                      AND e.status <> \'cancelled\')
          WHERE c.school_id = :school_id AND c.id = :classroom_id
          GROUP BY cs.id, gp.id
          ORDER BY cs.order_number, s.name, gp.order_number',
        [
            'school_id'    => tenant_require(),
            'classroom_id' => $classroomId,
            'year_id'      => $yearId,
        ]
    );
}

/**
 * Relevé complet d'une inscription : toutes ses cotes de l'année.
 *
 * Une ligne par branche et par période. Le maximum retourné est celui
 * FIGÉ sur la cote lorsqu'elle existe, et seulement à défaut le maximum
 * courant du programme — de sorte qu'un relevé déjà constitué reste
 * reproductible même si le programme a changé depuis.
 */
function grades_repo_report(int $enrollmentId): array
{
    return db_all(
        'SELECT cs.id AS curriculum_subject_id, s.name AS subject_name,
                s.short_name AS subject_short, cs.order_number,
                cs.max_points AS program_max, cs.counts_for_ranking,
                gp.id AS period_id, gp.code AS period_code, gp.name AS period_name,
                gp.period_type, gp.semester, gp.max_multiplier,
                gp.order_number AS period_order,
                g.points, g.is_absent,
                COALESCE(g.max_points, cs.max_points * gp.max_multiplier) AS max_points
           FROM enrollments e
           JOIN classrooms c ON c.id = e.classroom_id AND c.school_id = e.school_id
           JOIN curriculum_subjects cs ON cs.curriculum_id = c.curriculum_id
                                      AND cs.school_id = c.school_id
           JOIN subjects s ON s.id = cs.subject_id AND s.school_id = cs.school_id
           JOIN grade_periods gp ON gp.academic_year_id = e.academic_year_id
                                AND gp.school_id = e.school_id
           LEFT JOIN grades g ON g.enrollment_id = e.id
                            AND g.curriculum_subject_id = cs.id
                            AND g.grade_period_id = gp.id
                            AND g.school_id = e.school_id
          WHERE e.school_id = :school_id AND e.id = :enrollment_id
          ORDER BY cs.order_number, s.name, gp.order_number',
        ['school_id' => tenant_require(), 'enrollment_id' => $enrollmentId]
    );
}

/**
 * Branches qu'un enseignant peut saisir pour une année, avec l'état de
 * chaque période.
 *
 * C'est son écran d'accueil : « qu'ai-je à saisir, et où en suis-je ? »
 */
function grades_repo_teacher_workload(int $teacherId, int $yearId): array
{
    return db_all(
        'SELECT ts.classroom_id, ts.curriculum_subject_id,
                c.code AS classroom_code, c.name AS classroom_name,
                s.name AS subject_name, cs.max_points,
                l.short_name AS level_short,
                (SELECT COUNT(*) FROM enrollments e
                  WHERE e.school_id = ts.school_id
                    AND e.classroom_id = ts.classroom_id
                    AND e.status <> \'cancelled\') AS student_count
           FROM teacher_subjects ts
           JOIN classrooms c           ON c.id = ts.classroom_id AND c.school_id = ts.school_id
           JOIN curriculum_subjects cs ON cs.id = ts.curriculum_subject_id AND cs.school_id = ts.school_id
           JOIN subjects s             ON s.id = cs.subject_id AND s.school_id = cs.school_id
           JOIN curriculums cu         ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           JOIN education_levels l     ON l.id = cu.education_level_id
          WHERE ts.school_id = :school_id
            AND ts.teacher_id = :teacher_id
            AND ts.academic_year_id = :year_id
          ORDER BY l.order_number, c.code, cs.order_number, s.name',
        ['school_id' => tenant_require(), 'teacher_id' => $teacherId, 'year_id' => $yearId]
    );
}

/**
 * Nombre de cotes déjà saisies pour une branche d'une classe, toutes
 * périodes confondues.
 *
 * Sert à refuser le retrait d'une affectation : l'auteur d'une note doit
 * rester identifiable.
 */
function grades_repo_count_for_assignment(int $classroomId, int $curriculumSubjectId): int
{
    return (int) db_value(
        'SELECT COUNT(*)
           FROM grades g
           JOIN enrollments e ON e.id = g.enrollment_id AND e.school_id = g.school_id
          WHERE g.school_id = :school_id
            AND g.curriculum_subject_id = :subject_id
            AND e.classroom_id = :classroom_id',
        [
            'school_id'    => tenant_require(),
            'subject_id'   => $curriculumSubjectId,
            'classroom_id' => $classroomId,
        ]
    );
}
