<?php
/**
 * Module TEACHERS — dépôts.
 *
 * Deux préoccupations distinctes vivent ici :
 *
 *  · la consultation du personnel enseignant (liste, fiche, service) ;
 *  · le calcul du PÉRIMÈTRE d'un enseignant, c'est-à-dire l'ensemble des
 *    classes et des branches qu'il est autorisé à toucher.
 *
 * Le second point est une brique de sécurité, pas une commodité
 * d'affichage : la saisie des notes s'y adossera en phase 4B.
 */

declare(strict_types=1);

// =====================================================================
//  CONSULTATION
// =====================================================================

/**
 * Liste paginée des enseignants.
 *
 * @param array $filters q, status, classroom_id
 * @return array{rows: array, total: int, pages: int, page: int}
 */
function teachers_repo_search(array $filters, int $page = 1, int $perPage = 25): array
{
    $where  = ['t.school_id = :school_id', 't.deleted_at IS NULL'];
    $params = ['school_id' => tenant_require()];

    if (!empty($filters['q'])) {
        // Même règle que pour les élèves : recherche par préfixe, pour
        // rester sur l'index idx_teacher_names, et jokers échappés.
        $term = teachers_escape_like(trim((string) $filters['q']));
        $where[] = '(t.last_name LIKE :q1 OR t.post_name LIKE :q2
                     OR t.first_name LIKE :q3 OR t.matricule LIKE :q4)';
        $params['q1'] = $term . '%';
        $params['q2'] = $term . '%';
        $params['q3'] = $term . '%';
        $params['q4'] = $term . '%';
    }

    if (!empty($filters['status'])) {
        $where[] = 't.status = :status';
        $params['status'] = (string) $filters['status'];
    }

    if (!empty($filters['specialty'])) {
        $where[] = 't.specialty = :specialty';
        $params['specialty'] = (string) $filters['specialty'];
    }

    // La jointure sur users porte AUSSI sur school_id : users est une
    // table globale, le garde-fou multi-école ne la couvre pas. Sans ce
    // filtre, un identifiant de compte d'une autre école pourrait
    // s'afficher si les données venaient à diverger.
    $from = 'FROM teachers t
             LEFT JOIN users u ON u.id = t.user_id AND u.school_id = t.school_id
             WHERE ' . implode(' AND ', $where);

    $total  = (int) db_value('SELECT COUNT(DISTINCT t.id) ' . $from, $params);
    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = min(max(1, $page), $pages);
    $offset = ($page - 1) * $perPage;

    $rows = db_all(
        'SELECT t.*, u.username
         ' . $from . '
         ORDER BY t.last_name, t.post_name, t.first_name
         LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );

    // Le nombre d'affectations est ajouté en UNE requête pour la page
    // affichée, et non par enseignant : une liste de 25 enseignants ne
    // doit pas produire 26 requêtes.
    if ($rows !== []) {
        $ids     = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $inList  = implode(',', array_map('intval', $ids));
        $counts  = db_all(
            'SELECT teacher_id, COUNT(*) AS total, COUNT(DISTINCT classroom_id) AS classrooms
               FROM teacher_subjects
              WHERE school_id = :school_id AND teacher_id IN (' . $inList . ')
              GROUP BY teacher_id',
            ['school_id' => tenant_require()]
        );

        $byTeacher = [];

        foreach ($counts as $row) {
            $byTeacher[(int) $row['teacher_id']] = $row;
        }

        foreach ($rows as $index => $row) {
            $id = (int) $row['id'];
            $rows[$index]['assignment_count'] = (int) ($byTeacher[$id]['total'] ?? 0);
            $rows[$index]['classroom_count']  = (int) ($byTeacher[$id]['classrooms'] ?? 0);
        }
    }

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/** Échappe les jokers d'un terme destiné à un LIKE. */
function teachers_escape_like(string $term): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
}

/** Fiche d'un enseignant. */
function teachers_repo_find(int $id): ?array
{
    return tenant_one('teachers', 'id = :id AND deleted_at IS NULL', ['id' => $id]);
}

/** Enseignant rattaché à un compte utilisateur. */
function teachers_repo_find_by_user(int $userId): ?array
{
    return tenant_one(
        'teachers',
        'user_id = :user_id AND deleted_at IS NULL',
        ['user_id' => $userId]
    );
}

/**
 * Comptes utilisateurs de l'école qui ne sont encore liés à aucune fiche.
 *
 * Sert à alimenter le sélecteur « compte de connexion » du formulaire.
 * L'enseignant en cours d'édition garde son propre compte dans la liste,
 * sans quoi une simple modification de son téléphone le détacherait.
 */
function teachers_repo_available_users(?int $currentTeacherId = null): array
{
    return db_all(
        'SELECT u.id, u.username, u.last_name, u.post_name, u.first_name
           FROM users u
           LEFT JOIN teachers t ON t.user_id = u.id
                               AND t.school_id = u.school_id
                               AND t.deleted_at IS NULL
                               AND (:current IS NULL OR t.id <> :current2)
          WHERE u.school_id = :school_id
            AND u.status = \'active\'
            AND t.id IS NULL
          ORDER BY u.last_name, u.first_name',
        [
            'school_id' => tenant_require(),
            'current'   => $currentTeacherId,
            'current2'  => $currentTeacherId ?? 0,
        ]
    );
}

/**
 * Service d'un enseignant pour une année : ses branches, classe par classe.
 */
function teachers_repo_assignments(int $teacherId, int $yearId): array
{
    return db_all(
        'SELECT ts.id, ts.weekly_hours, ts.classroom_id, ts.curriculum_subject_id,
                c.code AS classroom_code, c.name AS classroom_name,
                s.name AS subject_name, s.short_name AS subject_short,
                cs.max_points, cs.weekly_hours AS program_hours,
                l.short_name AS level_short
           FROM teacher_subjects ts
           JOIN classrooms c          ON c.id = ts.classroom_id AND c.school_id = ts.school_id
           JOIN curriculum_subjects cs ON cs.id = ts.curriculum_subject_id AND cs.school_id = ts.school_id
           JOIN subjects s            ON s.id = cs.subject_id AND s.school_id = cs.school_id
           JOIN curriculums cu        ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           JOIN education_levels l    ON l.id = cu.education_level_id
          WHERE ts.school_id = :school_id
            AND ts.teacher_id = :teacher_id
            AND ts.academic_year_id = :year_id
          ORDER BY l.order_number, c.code, cs.order_number, s.name',
        ['school_id' => tenant_require(), 'teacher_id' => $teacherId, 'year_id' => $yearId]
    );
}

/**
 * Répartition d'une classe : chaque branche du programme et, si elle est
 * pourvue, l'enseignant qui l'assure.
 *
 * Une seule requête : c'est l'écran de travail du préfet des études, il
 * doit rester utilisable avec quarante branches.
 */
function teachers_repo_classroom_grid(int $classroomId): array
{
    return db_all(
        'SELECT cs.id AS curriculum_subject_id, cs.max_points, cs.weekly_hours,
                cs.is_optional, cs.order_number,
                s.name AS subject_name, s.short_name AS subject_short,
                ts.id AS assignment_id, ts.weekly_hours AS assigned_hours,
                t.id AS teacher_id, t.last_name, t.post_name, t.first_name
           FROM classrooms c
           JOIN curriculum_subjects cs ON cs.curriculum_id = c.curriculum_id
                                      AND cs.school_id = c.school_id
           JOIN subjects s ON s.id = cs.subject_id AND s.school_id = cs.school_id
           LEFT JOIN teacher_subjects ts ON ts.curriculum_subject_id = cs.id
                                        AND ts.classroom_id = c.id
                                        AND ts.school_id = c.school_id
           LEFT JOIN teachers t ON t.id = ts.teacher_id AND t.school_id = ts.school_id
          WHERE c.school_id = :school_id AND c.id = :classroom_id
          ORDER BY cs.order_number, s.name',
        ['school_id' => tenant_require(), 'classroom_id' => $classroomId]
    );
}

/** Charge horaire totale d'un enseignant sur une année. */
function teachers_repo_weekly_load(int $teacherId, int $yearId): float
{
    return (float) db_value(
        'SELECT COALESCE(SUM(COALESCE(ts.weekly_hours, cs.weekly_hours, 0)), 0)
           FROM teacher_subjects ts
           JOIN curriculum_subjects cs ON cs.id = ts.curriculum_subject_id
                                      AND cs.school_id = ts.school_id
          WHERE ts.school_id = :school_id
            AND ts.teacher_id = :teacher_id
            AND ts.academic_year_id = :year_id',
        ['school_id' => tenant_require(), 'teacher_id' => $teacherId, 'year_id' => $yearId]
    );
}

/** Classes dont l'enseignant est titulaire. */
function teachers_repo_main_classrooms(int $teacherId, int $yearId): array
{
    return tenant_all(
        'classrooms',
        'main_teacher_id = :teacher_id AND academic_year_id = :year_id ORDER BY code',
        ['teacher_id' => $teacherId, 'year_id' => $yearId]
    );
}

// =====================================================================
//  PÉRIMÈTRE DE L'ENSEIGNANT
//
//  Un enseignant est responsable de ce qu'on lui a confié, et de rien
//  d'autre. Deux titres ouvrent ce périmètre :
//
//    · être TITULAIRE d'une classe (classrooms.main_teacher_id) ;
//    · ASSURER une branche dans une classe (teacher_subjects).
//
//  Ces fonctions sont la source unique de vérité. Toute autorisation
//  pédagogique — consultation d'élèves, saisie de notes, appel des
//  présences — doit les interroger plutôt que refaire la requête.
// =====================================================================

/**
 * Identifiants des classes confiées à l'utilisateur courant.
 *
 * @return int[] Vide s'il n'est pas enseignant, ou n'a rien reçu.
 */
function teachers_scope_classroom_ids(?int $yearId = null): array
{
    $userId = auth_id();

    if ($userId === null) {
        return [];
    }

    $teacher = teachers_repo_find_by_user($userId);

    if ($teacher === null || $teacher['status'] !== 'active') {
        return [];
    }

    // Chaque branche de l'UNION reçoit SES PROPRES paramètres, avec des
    // noms distincts. En requêtes réellement préparées
    // (ATTR_EMULATE_PREPARES = false), MySQL refuse qu'un même paramètre
    // nommé apparaisse deux fois : il répond « Invalid parameter number ».
    $params = [
        'school_main'  => tenant_require(),
        'teacher_main' => (int) $teacher['id'],
        'school_sub'   => tenant_require(),
        'teacher_sub'  => (int) $teacher['id'],
    ];

    $yearMain = '';
    $yearSub  = '';

    if ($yearId !== null) {
        $yearMain = ' AND c.academic_year_id = :year_main';
        $yearSub  = ' AND c.academic_year_id = :year_sub';
        $params['year_main'] = $yearId;
        $params['year_sub']  = $yearId;
    }

    $rows = db_all(
        'SELECT c.id
           FROM classrooms c
          WHERE c.school_id = :school_main AND c.main_teacher_id = :teacher_main' . $yearMain . '
          UNION
         SELECT ts.classroom_id AS id
           FROM teacher_subjects ts
           JOIN classrooms c ON c.id = ts.classroom_id AND c.school_id = ts.school_id
          WHERE ts.school_id = :school_sub AND ts.teacher_id = :teacher_sub' . $yearSub,
        $params
    );

    return array_map(static fn (array $row): int => (int) $row['id'], $rows);
}

/**
 * L'utilisateur courant assure-t-il CETTE branche dans CETTE classe ?
 *
 * C'est le contrôle qui autorisera la saisie d'une note en phase 4B.
 * Être titulaire de la classe ne suffit pas : le titulaire encadre, il
 * ne note pas les branches qu'il n'enseigne pas.
 */
function teachers_can_teach(int $classroomId, int $curriculumSubjectId): bool
{
    $userId = auth_id();

    if ($userId === null) {
        return false;
    }

    $teacher = teachers_repo_find_by_user($userId);

    if ($teacher === null || $teacher['status'] !== 'active') {
        return false;
    }

    return db_exists(
        'SELECT 1 FROM teacher_subjects
          WHERE school_id = :school_id
            AND teacher_id = :teacher_id
            AND classroom_id = :classroom_id
            AND curriculum_subject_id = :subject_id
          LIMIT 1',
        [
            'school_id'    => tenant_require(),
            'teacher_id'   => (int) $teacher['id'],
            'classroom_id' => $classroomId,
            'subject_id'   => $curriculumSubjectId,
        ]
    );
}

/** L'utilisateur courant est-il un enseignant en activité ? */
function teachers_current_is_teacher(): bool
{
    $userId = auth_id();

    if ($userId === null) {
        return false;
    }

    $teacher = teachers_repo_find_by_user($userId);

    return $teacher !== null && $teacher['status'] === 'active';
}
