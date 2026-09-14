<?php
/**
 * Module ATTENDANCE — dépôts.
 *
 * Trois requêtes portent le module :
 *
 *  · le REGISTRE d'une classe pour une date — un élève par ligne, son
 *    statut si l'appel a été fait ;
 *  · les CLASSES SANS APPEL pour une date — la question que se pose la
 *    direction chaque matin ;
 *  · le CUMUL par élève sur un intervalle — base des statistiques et du
 *    dossier de l'élève.
 */

declare(strict_types=1);

/**
 * Session d'appel d'une classe, pour une date et un moment donnés.
 *
 * Renvoie null quand l'appel n'a pas été fait — ce qui n'est pas la même
 * chose qu'un appel sans absent.
 */
function attendance_repo_session(int $classroomId, string $date, string $slot = 'day'): ?array
{
    return tenant_one(
        'attendance_sessions',
        'classroom_id = :c AND session_date = :d AND slot = :s',
        ['c' => $classroomId, 'd' => $date, 's' => $slot]
    );
}

/**
 * Registre de la classe : tous les élèves inscrits, avec leur statut si
 * l'appel a été fait.
 *
 * Jointure externe sur attendance_records, comme la grille de saisie des
 * notes : une ligne sans statut est un élève non encore appelé, pas un
 * élève à gérer côté PHP.
 */
function attendance_repo_register(int $classroomId, ?int $sessionId): array
{
    return db_all(
        'SELECT e.id AS enrollment_id, e.student_id,
                s.matricule, s.last_name, s.post_name, s.first_name, s.gender,
                r.id AS record_id, r.status, r.minutes_late,
                r.is_justified, r.justification
           FROM enrollments e
           JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
           LEFT JOIN attendance_records r ON r.enrollment_id = e.id
                                         AND r.school_id = e.school_id
                                         AND r.session_id = :session_id
          WHERE e.school_id = :school_id
            AND e.classroom_id = :classroom_id
            AND e.status <> \'cancelled\'
            AND s.deleted_at IS NULL
          ORDER BY s.last_name, s.post_name, s.first_name',
        [
            'school_id'    => tenant_require(),
            'classroom_id' => $classroomId,
            // 0 ne correspond à aucune session : toutes les cotes
            // reviennent alors nulles, ce qui est exactement l'état
            // « appel pas encore fait ».
            'session_id'   => $sessionId ?? 0,
        ]
    );
}

/**
 * Classes d'une année, avec l'état de leur appel pour une date.
 *
 * C'EST LA REQUÊTE QUI JUSTIFIE LA TABLE DES SESSIONS. Sans elle, une
 * classe dont personne n'a fait l'appel serait indiscernable d'une classe
 * sans absent.
 */
function attendance_repo_day_overview(int $yearId, string $date, string $slot = 'day'): array
{
    return db_all(
        'SELECT c.id AS classroom_id, c.code, c.name,
                l.short_name AS level_short,
                (SELECT COUNT(*) FROM enrollments e
                  WHERE e.school_id = c.school_id
                    AND e.classroom_id = c.id
                    AND e.status <> \'cancelled\') AS student_count,
                a.id AS session_id, a.taken_at, a.is_locked,
                u.username AS taken_by_name,
                -- COMBIEN D ÉLÈVES L APPEL A-T-IL RÉELLEMENT COUVERTS ?
                --
                -- Une session existe dès le premier élève enregistré. Sans
                -- ce décompte, un appel portant sur deux élèves sur
                -- quarante s affichait « fait », et les trente-huit autres
                -- n étaient ni présents ni absents — exactement le piège
                -- que la table des sessions devait fermer, reproduit un
                -- niveau plus bas.
                COUNT(DISTINCT r.enrollment_id) AS recorded,
                COUNT(CASE WHEN r.status = \'absent\' THEN 1 END) AS absents,
                COUNT(CASE WHEN r.status = \'late\'   THEN 1 END) AS lates
           FROM classrooms c
           JOIN curriculums cu ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           JOIN education_levels l ON l.id = cu.education_level_id
           LEFT JOIN attendance_sessions a ON a.classroom_id = c.id
                                          AND a.school_id = c.school_id
                                          AND a.session_date = :date
                                          AND a.slot = :slot
           LEFT JOIN users u ON u.id = a.taken_by
           LEFT JOIN attendance_records r ON r.session_id = a.id AND r.school_id = a.school_id
          WHERE c.school_id = :school_id AND c.academic_year_id = :year_id
          GROUP BY c.id, a.id
          ORDER BY l.order_number, c.code',
        [
            'school_id' => tenant_require(),
            'year_id'   => $yearId,
            'date'      => $date,
            'slot'      => $slot,
        ]
    );
}

/**
 * Cumul des présences d'une inscription sur un intervalle de dates.
 *
 * L'intervalle est explicite plutôt que déduit d'une période : les dates
 * de grade_periods ne sont pas renseignées, et les déduire du calendrier
 * scolaire reviendrait à inventer un découpage.
 *
 * @return array{sessions: int, present: int, absent: int, late: int, justified: int}
 */
function attendance_repo_summary(int $enrollmentId, string $from, string $to): array
{
    $row = db_one(
        'SELECT COUNT(*) AS sessions,
                COUNT(CASE WHEN r.status = \'present\' THEN 1 END) AS present,
                COUNT(CASE WHEN r.status = \'absent\'  THEN 1 END) AS absent,
                COUNT(CASE WHEN r.status = \'late\'    THEN 1 END) AS late,
                COUNT(CASE WHEN r.status = \'absent\' AND r.is_justified = 1 THEN 1 END) AS justified,
                COALESCE(SUM(r.minutes_late), 0) AS minutes_late
           FROM attendance_records r
           JOIN attendance_sessions a ON a.id = r.session_id AND a.school_id = r.school_id
          WHERE r.school_id = :school_id
            AND r.enrollment_id = :enrollment_id
            AND a.session_date BETWEEN :from AND :to',
        [
            'school_id'     => tenant_require(),
            'enrollment_id' => $enrollmentId,
            'from'          => $from,
            'to'            => $to,
        ]
    );

    return [
        'sessions'     => (int) ($row['sessions'] ?? 0),
        'present'      => (int) ($row['present'] ?? 0),
        'absent'       => (int) ($row['absent'] ?? 0),
        'late'         => (int) ($row['late'] ?? 0),
        'justified'    => (int) ($row['justified'] ?? 0),
        'minutes_late' => (int) ($row['minutes_late'] ?? 0),
    ];
}

/**
 * Cumul par élève pour toute une classe, sur un intervalle.
 *
 * Une seule requête pour la classe entière : une classe de 45 élèves
 * produirait sinon 45 appels.
 */
function attendance_repo_classroom_summary(int $classroomId, string $from, string $to): array
{
    return db_all(
        'SELECT e.id AS enrollment_id,
                s.matricule, s.last_name, s.post_name, s.first_name,
                COUNT(r.id) AS sessions,
                COUNT(CASE WHEN r.status = \'absent\' THEN 1 END) AS absent,
                COUNT(CASE WHEN r.status = \'late\'   THEN 1 END) AS late,
                COUNT(CASE WHEN r.status = \'absent\' AND r.is_justified = 1 THEN 1 END) AS justified,
                COALESCE(SUM(r.minutes_late), 0) AS minutes_late
           FROM enrollments e
           JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
           LEFT JOIN attendance_records r ON r.enrollment_id = e.id
                                         AND r.school_id = e.school_id
           LEFT JOIN attendance_sessions a ON a.id = r.session_id
                                          AND a.school_id = r.school_id
                                          AND a.session_date BETWEEN :from AND :to
          WHERE e.school_id = :school_id
            AND e.classroom_id = :classroom_id
            AND e.status <> \'cancelled\'
            AND s.deleted_at IS NULL
            AND (r.id IS NULL OR a.id IS NOT NULL)
          GROUP BY e.id
          ORDER BY absent DESC, s.last_name, s.first_name',
        [
            'school_id'    => tenant_require(),
            'classroom_id' => $classroomId,
            'from'         => $from,
            'to'           => $to,
        ]
    );
}
