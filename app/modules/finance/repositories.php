<?php
/**
 * Module FINANCE — lectures.
 *
 * Toute requête porte `school_id`. Le garde-fou multi-école le vérifie,
 * mais c'est la discipline qui protège, pas le garde-fou.
 *
 * ATTENTION AUX SOMMES
 * --------------------
 * Aucune fonction de ce fichier n'additionne deux devises. Un total
 * est toujours rendu PAR DEVISE, sous forme de tableau indexé par le
 * code monétaire. Une école qui affiche « dû : 320 » sans dire en quoi
 * n'a rien affiché du tout.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
//  LA GRILLE TARIFAIRE
// ---------------------------------------------------------------------

/** Grille d'une année, ordonnée comme elle sera imprimée. */
function finance_repo_fees(int $yearId, bool $activeOnly = false): array
{
    $schoolId = tenant_require();

    $sql = 'SELECT f.*,
                   el.name       AS level_name,
                   el.short_name AS level_short,
                   c.name        AS classroom_name,
                   (SELECT COUNT(*) FROM student_fees sf
                     WHERE sf.school_id = f.school_id
                       AND sf.fee_id = f.id
                       AND sf.is_cancelled = 0) AS assigned_count
              FROM fees f
         LEFT JOIN education_levels el ON el.id = f.level_id
         LEFT JOIN classrooms c ON c.id = f.classroom_id AND c.school_id = f.school_id
             WHERE f.school_id = :school_id
               AND f.academic_year_id = :year_id';

    if ($activeOnly) {
        $sql .= ' AND f.is_active = 1';
    }

    $sql .= ' ORDER BY f.position, f.due_on IS NULL, f.due_on, f.name';

    return db_all($sql, ['school_id' => $schoolId, 'year_id' => $yearId]);
}

function finance_repo_fee(int $feeId): ?array
{
    return db_one(
        'SELECT * FROM fees WHERE id = :id AND school_id = :school_id',
        ['id' => $feeId, 'school_id' => tenant_require()]
    );
}

// ---------------------------------------------------------------------
//  LES DETTES
// ---------------------------------------------------------------------

/**
 * Dettes d'une inscription.
 *
 * `amount_net` est la somme réellement exigible : le tarif figé moins
 * la remise accordée. C'est elle, et non `amount_due`, qui sert de
 * référence au solde.
 */
function finance_repo_student_fees(int $enrollmentId, bool $includeCancelled = false): array
{
    $sql = 'SELECT sf.*,
                   (sf.amount_due - sf.discount_amount) AS amount_net,
                   f.group_label,
                   f.is_mandatory
              FROM student_fees sf
         LEFT JOIN fees f ON f.id = sf.fee_id AND f.school_id = sf.school_id
             WHERE sf.school_id = :school_id
               AND sf.enrollment_id = :enrollment_id';

    if (!$includeCancelled) {
        $sql .= ' AND sf.is_cancelled = 0';
    }

    $sql .= ' ORDER BY sf.is_cancelled, sf.due_on IS NULL, sf.due_on, sf.id';

    return db_all($sql, [
        'school_id'     => tenant_require(),
        'enrollment_id' => $enrollmentId,
    ]);
}

function finance_repo_student_fee(int $id): ?array
{
    return db_one(
        'SELECT sf.*, (sf.amount_due - sf.discount_amount) AS amount_net,
                e.academic_year_id, e.classroom_id, e.student_id
           FROM student_fees sf
           JOIN enrollments e ON e.id = sf.enrollment_id AND e.school_id = sf.school_id
          WHERE sf.id = :id AND sf.school_id = :school_id',
        ['id' => $id, 'school_id' => tenant_require()]
    );
}

/**
 * Total dû par une inscription, PAR DEVISE.
 *
 * @return array<string,float> ['USD' => 150.00, 'CDF' => 45000.00]
 */
function finance_repo_due_by_currency(int $enrollmentId): array
{
    $rows = db_all(
        'SELECT currency, SUM(amount_due - discount_amount) AS total
           FROM student_fees
          WHERE school_id = :school_id
            AND enrollment_id = :enrollment_id
            AND is_cancelled = 0
          GROUP BY currency',
        ['school_id' => tenant_require(), 'enrollment_id' => $enrollmentId]
    );

    $totals = [];

    foreach ($rows as $row) {
        $totals[(string) $row['currency']] = (float) $row['total'];
    }

    return $totals;
}

// ---------------------------------------------------------------------
//  L'ÉTAT D'UNE CLASSE
// ---------------------------------------------------------------------

/**
 * Situation d'une classe : un élève par ligne, avec ce qu'il doit.
 *
 * `fee_count` vaut 0 pour un inscrit SANS aucune dette affectée. Cette
 * colonne est la raison d'être de cette requête : sans elle, un élève
 * jamais facturé serait indiscernable d'un élève à jour — le piège de
 * l'appel partiel corrigé en phase 4D, transposé aux finances.
 */
function finance_repo_classroom_situation(int $classroomId): array
{
    return db_all(
        'SELECT e.id              AS enrollment_id,
                st.id             AS student_id,
                st.matricule,
                st.last_name, st.post_name, st.first_name,
                COUNT(sf.id)      AS fee_count,
                COUNT(CASE WHEN sf.discount_amount > 0 THEN 1 END) AS discount_count
           FROM enrollments e
           JOIN students st ON st.id = e.student_id AND st.school_id = e.school_id
      LEFT JOIN student_fees sf ON sf.enrollment_id = e.id
                               AND sf.school_id = e.school_id
                               AND sf.is_cancelled = 0
          WHERE e.school_id = :school_id
            AND e.classroom_id = :classroom_id
            AND e.status = :status
          GROUP BY e.id, st.id, st.matricule, st.last_name, st.post_name, st.first_name
          ORDER BY st.last_name, st.post_name, st.first_name',
        [
            'school_id'    => tenant_require(),
            'classroom_id' => $classroomId,
            'status'       => 'enrolled',
        ]
    );
}

/** Totaux dus d'une classe, par devise. */
function finance_repo_classroom_due(int $classroomId): array
{
    $rows = db_all(
        'SELECT sf.currency, SUM(sf.amount_due - sf.discount_amount) AS total
           FROM student_fees sf
           JOIN enrollments e ON e.id = sf.enrollment_id AND e.school_id = sf.school_id
          WHERE sf.school_id = :school_id
            AND e.classroom_id = :classroom_id
            AND e.status = :status
            AND sf.is_cancelled = 0
          GROUP BY sf.currency',
        [
            'school_id'    => tenant_require(),
            'classroom_id' => $classroomId,
            'status'       => 'enrolled',
        ]
    );

    $totals = [];

    foreach ($rows as $row) {
        $totals[(string) $row['currency']] = (float) $row['total'];
    }

    return $totals;
}

/**
 * Vue d'ensemble d'une année : une ligne par classe.
 *
 * `without_fees` compte les inscrits sans la moindre dette. C'est le
 * chiffre que la direction doit voir en premier.
 */
function finance_repo_year_overview(int $yearId): array
{
    return db_all(
        'SELECT c.id   AS classroom_id,
                c.name,
                el.short_name AS level_short,
                COUNT(DISTINCT e.id) AS student_count,
                COUNT(DISTINCT CASE WHEN sf.id IS NULL THEN e.id END) AS without_fees
           FROM classrooms c
           JOIN curriculums cu ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           JOIN education_levels el ON el.id = cu.education_level_id
      LEFT JOIN enrollments e ON e.classroom_id = c.id
                             AND e.school_id = c.school_id
                             AND e.status = :status
      LEFT JOIN student_fees sf ON sf.enrollment_id = e.id
                               AND sf.school_id = e.school_id
                               AND sf.is_cancelled = 0
          WHERE c.school_id = :school_id
            AND c.academic_year_id = :year_id
          GROUP BY c.id, c.name, el.short_name, el.order_number
          ORDER BY el.order_number, c.name',
        [
            'school_id' => tenant_require(),
            'year_id'   => $yearId,
            'status'    => 'enrolled',
        ]
    );
}

/**
 * Inscriptions d'une année éligibles à un frais donné.
 *
 * La portée du frais décide : toute l'école, un niveau, ou une classe.
 * Le niveau est lu sur le PROGRAMME de la classe (`education_level_id`),
 * jamais deviné à partir du nom de la classe.
 *
 * Seules les inscriptions au statut « enrolled » sont retenues : un
 * dossier pré-inscrit ou annulé n'a pas de dette.
 */
function finance_repo_targets(array $fee, ?int $classroomId = null): array
{
    $params = [
        'school_id' => tenant_require(),
        'year_id'   => (int) $fee['academic_year_id'],
        'status'    => 'enrolled',
    ];

    $sql = 'SELECT e.id
              FROM enrollments e
              JOIN classrooms c ON c.id = e.classroom_id AND c.school_id = e.school_id
              JOIN curriculums cu ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
             WHERE e.school_id = :school_id
               AND e.academic_year_id = :year_id
               AND e.status = :status';

    if ((string) $fee['scope'] === 'level') {
        $sql .= ' AND cu.education_level_id = :level_id';
        $params['level_id'] = (int) $fee['level_id'];
    } elseif ((string) $fee['scope'] === 'classroom') {
        $sql .= ' AND c.id = :fee_classroom';
        $params['fee_classroom'] = (int) $fee['classroom_id'];
    }

    if ($classroomId !== null) {
        $sql .= ' AND c.id = :only_classroom';
        $params['only_classroom'] = $classroomId;
    }

    return array_map('intval', array_column(db_all($sql, $params), 'id'));
}
