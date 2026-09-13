<?php
/**
 * Module STUDENTS — dépôts.
 *
 * La recherche d'élève est l'action la plus fréquente du secrétariat :
 * elle est paginée et s'appuie sur les index de la table students.
 * Aucune requête ne charge la totalité des dossiers.
 */

declare(strict_types=1);

/**
 * Liste paginée des élèves, avec leur inscription de l'année demandée.
 *
 * @param array $filters q, classroom_id, status, gender, level_id
 * @return array{rows: array, total: int, pages: int, page: int}
 */
function students_repo_search(array $filters, int $academicYearId, int $page = 1, int $perPage = 25): array
{
    $where  = ['s.school_id = :school_id', 's.deleted_at IS NULL'];
    $params = ['school_id' => tenant_require(), 'year_id' => $academicYearId];

    // Recherche plein texte simple : nom, postnom, prénom, matricule.
    // LIKE 'terme%' sur les noms utilise l'index idx_student_names ;
    // un '%terme%' en tête l'empêcherait et forcerait un balayage complet.
    if (!empty($filters['q'])) {
        $term = trim((string) $filters['q']);
        $where[] = '(s.matricule LIKE :q_exact
                     OR s.last_name LIKE :q_start
                     OR s.post_name LIKE :q_start2
                     OR s.first_name LIKE :q_start3)';
        $params['q_exact']  = $term . '%';
        $params['q_start']  = $term . '%';
        $params['q_start2'] = $term . '%';
        $params['q_start3'] = $term . '%';
    }

    if (!empty($filters['status'])) {
        $where[] = 's.status = :status';
        $params['status'] = (string) $filters['status'];
    }

    if (!empty($filters['gender'])) {
        $where[] = 's.gender = :gender';
        $params['gender'] = (string) $filters['gender'];
    }

    if (!empty($filters['classroom_id'])) {
        $where[] = 'e.classroom_id = :classroom_id';
        $params['classroom_id'] = (int) $filters['classroom_id'];
    }

    if (!empty($filters['unassigned'])) {
        $where[] = 'e.id IS NOT NULL AND e.classroom_id IS NULL';
    }

    $whereSql = implode(' AND ', $where);

    $from = 'FROM students s
             LEFT JOIN enrollments e ON e.student_id = s.id
                                    AND e.school_id = s.school_id
                                    AND e.academic_year_id = :year_id
                                    AND e.status <> \'cancelled\'
             LEFT JOIN classrooms c  ON c.id = e.classroom_id AND c.school_id = s.school_id
             WHERE ' . $whereSql;

    $total   = (int) db_value('SELECT COUNT(DISTINCT s.id) ' . $from, $params);
    $pages   = max(1, (int) ceil($total / $perPage));
    $page    = min(max(1, $page), $pages);
    $offset  = ($page - 1) * $perPage;

    // LIMIT et OFFSET sont castés en entier puis interpolés : MySQL
    // n'accepte pas de paramètre lié à ces emplacements en requête
    // réellement préparée. Le cast (int) rend l'interpolation sûre.
    $rows = db_all(
        'SELECT s.*, e.id AS enrollment_id, e.status AS enrollment_status,
                e.enrollment_type, e.decision, e.classroom_id,
                c.name AS classroom_name, c.code AS classroom_code
         ' . $from . '
         ORDER BY s.last_name, s.post_name, s.first_name
         LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/** Fiche complète d'un élève. */
function students_repo_find(int $id): ?array
{
    return tenant_find('students', $id);
}

/** Élève retrouvé par son matricule (recherche du secrétariat). */
function students_repo_find_by_matricule(string $matricule): ?array
{
    return tenant_one('students', 'matricule = :m AND deleted_at IS NULL', ['m' => $matricule]);
}

/** Tuteurs d'un élève, contact principal en premier. */
function students_repo_guardians(int $studentId): array
{
    return db_all(
        'SELECT g.*, sg.relationship, sg.is_primary, sg.is_emergency, sg.can_pickup, sg.is_payer,
                sg.id AS link_id
           FROM student_guardians sg
           JOIN guardians g ON g.id = sg.guardian_id AND g.school_id = sg.school_id
          WHERE sg.school_id = :school_id AND sg.student_id = :student_id
            AND g.deleted_at IS NULL
          ORDER BY sg.is_primary DESC, g.last_name',
        ['school_id' => tenant_require(), 'student_id' => $studentId]
    );
}

/**
 * Parcours scolaire : toutes les inscriptions, de la plus ancienne à la
 * plus récente. C'est la source principale de la frise affichée sur la
 * fiche de l'élève.
 */
function students_repo_enrollments(int $studentId): array
{
    return db_all(
        'SELECT e.*, y.code AS year_code, y.starts_on, y.status AS year_status,
                c.name AS classroom_name, c.code AS classroom_code,
                l.name AS level_name, l.short_name AS level_short, l.order_number AS level_order,
                cy.short_name AS cycle_short,
                sec.short_name AS section_short, opt.short_name AS option_short
           FROM enrollments e
           JOIN academic_years y    ON y.id = e.academic_year_id AND y.school_id = e.school_id
           LEFT JOIN classrooms c   ON c.id = e.classroom_id AND c.school_id = e.school_id
           LEFT JOIN curriculums cu ON cu.id = c.curriculum_id AND cu.school_id = e.school_id
           LEFT JOIN education_levels l ON l.id = cu.education_level_id
           LEFT JOIN education_cycles cy ON cy.id = l.cycle_id
           LEFT JOIN sections sec   ON sec.id = cu.section_id AND sec.school_id = e.school_id
           LEFT JOIN options  opt   ON opt.id = cu.option_id  AND opt.school_id = e.school_id
          WHERE e.school_id = :school_id AND e.student_id = :student_id
          ORDER BY y.starts_on',
        ['school_id' => tenant_require(), 'student_id' => $studentId]
    );
}

/** Événements du parcours autres que les inscriptions. */
function students_repo_history(int $studentId): array
{
    return db_all(
        'SELECT h.*, y.code AS year_code
           FROM student_history h
           LEFT JOIN academic_years y ON y.id = h.academic_year_id AND y.school_id = h.school_id
          WHERE h.school_id = :school_id AND h.student_id = :student_id
          ORDER BY h.event_date DESC, h.id DESC',
        ['school_id' => tenant_require(), 'student_id' => $studentId]
    );
}

/** Orientations enregistrées pour un élève. */
function students_repo_orientations(int $studentId): array
{
    // Les libellés proviennent des colonnes figées, pas des jointures :
    // c'est ce qui garantit qu'une orientation reste lisible même après
    // une réorganisation du référentiel. Les jointures ne servent plus
    // qu'aux statistiques, d'où les LEFT JOIN.
    return db_all(
        'SELECT o.*, y.code AS year_code,
                COALESCE(o.level_label, l.name)     AS level_name,
                COALESCE(o.section_label, sec.name) AS section_name,
                COALESCE(o.option_label, opt.name)  AS option_name
           FROM orientations o
           JOIN academic_years y   ON y.id = o.academic_year_id AND y.school_id = o.school_id
           LEFT JOIN education_levels l ON l.id = o.from_level_id
           LEFT JOIN sections sec  ON sec.id = o.section_id AND sec.school_id = o.school_id
           LEFT JOIN options  opt  ON opt.id = o.option_id  AND opt.school_id = o.school_id
          WHERE o.school_id = :school_id AND o.student_id = :student_id
          ORDER BY o.decided_on DESC',
        ['school_id' => tenant_require(), 'student_id' => $studentId]
    );
}

/** Inscription d'un élève pour une année donnée. */
function students_repo_enrollment(int $studentId, int $academicYearId): ?array
{
    return tenant_one(
        'enrollments',
        'student_id = :s AND academic_year_id = :y',
        ['s' => $studentId, 'y' => $academicYearId]
    );
}

/** Statistiques de l'année, pour le tableau de bord des élèves. */
function students_repo_year_stats(int $academicYearId): array
{
    $row = db_one(
        'SELECT
             COUNT(*) AS total,
             SUM(CASE WHEN e.status = \'enrolled\' THEN 1 ELSE 0 END)        AS enrolled,
             SUM(CASE WHEN e.status = \'pre_registered\' THEN 1 ELSE 0 END)  AS pre_registered,
             SUM(CASE WHEN e.status = \'admitted\' THEN 1 ELSE 0 END)        AS admitted,
             SUM(CASE WHEN e.classroom_id IS NULL AND e.status <> \'cancelled\' THEN 1 ELSE 0 END) AS unassigned,
             SUM(CASE WHEN s.gender = \'M\' AND e.status = \'enrolled\' THEN 1 ELSE 0 END) AS boys,
             SUM(CASE WHEN s.gender = \'F\' AND e.status = \'enrolled\' THEN 1 ELSE 0 END) AS girls
           FROM enrollments e
           JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
          WHERE e.school_id = :school_id AND e.academic_year_id = :year_id
            AND s.deleted_at IS NULL',
        ['school_id' => tenant_require(), 'year_id' => $academicYearId]
    );

    return array_map(static fn ($v): int => (int) $v, $row ?? []);
}

/** Élèves d'une classe. */
function students_repo_classroom_students(int $classroomId): array
{
    return db_all(
        'SELECT s.*, e.id AS enrollment_id, e.status AS enrollment_status, e.decision
           FROM enrollments e
           JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
          WHERE e.school_id = :school_id AND e.classroom_id = :classroom_id
            AND e.status <> \'cancelled\' AND s.deleted_at IS NULL
          ORDER BY s.last_name, s.post_name, s.first_name',
        ['school_id' => tenant_require(), 'classroom_id' => $classroomId]
    );
}

// =====================================================================
//  TUTEURS
// =====================================================================

function guardians_repo_find(int $id): ?array
{
    return tenant_find('guardians', $id);
}

/** Recherche un tuteur par téléphone — évite les doublons à la saisie. */
function guardians_repo_find_by_phone(string $phone): ?array
{
    return tenant_one('guardians', 'phone = :p AND deleted_at IS NULL', ['p' => $phone]);
}

/** Liste paginée des tuteurs, avec le nombre d'enfants. */
function guardians_repo_search(string $term = '', int $page = 1, int $perPage = 25): array
{
    $where  = ['g.school_id = :school_id', 'g.deleted_at IS NULL'];
    $params = ['school_id' => tenant_require()];

    if ($term !== '') {
        $where[]           = '(g.last_name LIKE :q1 OR g.first_name LIKE :q2 OR g.phone LIKE :q3)';
        $params['q1']      = $term . '%';
        $params['q2']      = $term . '%';
        $params['q3']      = '%' . $term . '%';
    }

    $from  = 'FROM guardians g WHERE ' . implode(' AND ', $where);
    $total = (int) db_value('SELECT COUNT(*) ' . $from, $params);
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);

    $rows = db_all(
        'SELECT g.*,
                (SELECT COUNT(*) FROM student_guardians sg
                  WHERE sg.guardian_id = g.id AND sg.school_id = g.school_id) AS children_count
         ' . $from . '
         ORDER BY g.last_name, g.first_name
         LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
        $params
    );

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}
