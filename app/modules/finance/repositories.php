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

// ---------------------------------------------------------------------
//  DETTES DEVENUES HORS PORTÉE
//
//  Une école crée « Minerval » pour toute l'école, l'affecte, puis
//  s'aperçoit qu'il ne concerne que la 8e et rétrécit sa portée. Les
//  dettes déjà créées sur la 7e RESTENT : l'affectation ne sait
//  qu'ajouter, et le gel du tarif interdit de les réécrire.
//
//  Sans ce signal, deux classes entières restaient facturées d'un frais
//  qui ne les concernait plus, et rien ne le disait — l'audit de la
//  phase 5A les a trouvées par exécution.
//
//  La même requête attrape un cas tout aussi réel : l'élève transféré
//  de 7A vers 8A en cours d'année, qui traîne la sortie scolaire de son
//  ancienne classe. Dans les deux cas c'est au comptable de trancher,
//  pas au programme — d'où un signalement, jamais une suppression
//  automatique.
// ---------------------------------------------------------------------

/**
 * Dettes actives dont le frais ne vise plus l'inscription.
 *
 * Les frais de portée « école » ne peuvent jamais être hors portée.
 */
function finance_repo_out_of_scope(int $yearId): array
{
    return db_all(
        'SELECT sf.id, sf.label, sf.currency, sf.amount_due, sf.enrollment_id,
                f.name AS fee_name, f.scope,
                c.name AS classroom_name,
                st.matricule, st.last_name, st.post_name, st.first_name
           FROM student_fees sf
           JOIN fees f ON f.id = sf.fee_id AND f.school_id = sf.school_id
           JOIN enrollments e ON e.id = sf.enrollment_id AND e.school_id = sf.school_id
           JOIN students st ON st.id = e.student_id AND st.school_id = e.school_id
      LEFT JOIN classrooms c ON c.id = e.classroom_id AND c.school_id = e.school_id
      LEFT JOIN curriculums cu ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
          WHERE sf.school_id = :school_id
            AND f.academic_year_id = :year_id
            AND sf.is_cancelled = 0
            AND (
                 (f.scope = :scope_level
                  AND (cu.education_level_id IS NULL OR cu.education_level_id <> f.level_id))
              OR (f.scope = :scope_class
                  AND (e.classroom_id IS NULL OR e.classroom_id <> f.classroom_id))
            )
          ORDER BY f.name, st.last_name, st.first_name',
        [
            'school_id'   => tenant_require(),
            'year_id'     => $yearId,
            'scope_level' => 'level',
            'scope_class' => 'classroom',
        ]
    );
}

/**
 * Nombre de dettes actives libellées dans une autre devise que le tarif.
 *
 * Le réalignement REFUSE de convertir une monnaie : proposer le bouton
 * dans ce cas serait promettre une action qui échoue toujours. L'écran
 * a besoin de distinguer les deux situations.
 */
function finance_repo_currency_mismatch(int $feeId): int
{
    $fee = finance_repo_fee($feeId);

    if ($fee === null) {
        return 0;
    }

    return (int) db_value(
        'SELECT COUNT(*) FROM student_fees
          WHERE school_id = :school_id AND fee_id = :fee_id
            AND is_cancelled = 0 AND currency <> :currency',
        [
            'school_id' => tenant_require(),
            'fee_id'    => $feeId,
            'currency'  => (string) $fee['currency'],
        ]
    );
}

/**
 * Inscriptions de l'année visibles par l'utilisateur courant.
 *
 * Sert la porte du PARENT. Il détient finance.view depuis la phase 1,
 * mais aucune classe : le tableau par classe lui renvoyait donc une
 * page vide, et l'entrée de menu ne menait nulle part. Le périmètre
 * passe ici par l'ÉLÈVE, via students_scope_clause — source unique.
 *
 * À n'appeler que lorsque le périmètre par classe est vide : sur un
 * compte de direction, la requête ramènerait l'établissement entier.
 */
function finance_repo_scoped_enrollments(int $yearId): array
{
    [$scope, $scopeParams] = students_scope_clause($yearId);

    return db_all(
        'SELECT e.id AS enrollment_id,
                st.matricule, st.last_name, st.post_name, st.first_name,
                c.name AS classroom_name,
                COUNT(sf.id) AS fee_count
           FROM enrollments e
           JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
           JOIN students st ON st.id = s.id
      LEFT JOIN classrooms c ON c.id = e.classroom_id AND c.school_id = e.school_id
      LEFT JOIN student_fees sf ON sf.enrollment_id = e.id
                               AND sf.school_id = e.school_id
                               AND sf.is_cancelled = 0
          WHERE e.school_id = :school_id
            AND e.academic_year_id = :year_id
            AND e.status = :status
            AND s.deleted_at IS NULL
            AND ' . $scope . '
          GROUP BY e.id, st.matricule, st.last_name, st.post_name, st.first_name, c.name
          ORDER BY st.last_name, st.first_name',
        $scopeParams + [
            'school_id' => tenant_require(),
            'year_id'   => $yearId,
            'status'    => 'enrolled',
        ]
    );
}

// =====================================================================
//  ENCAISSEMENTS (phase 5B)
//
//  RÈGLE DE LECTURE, VALABLE PARTOUT DANS CETTE SECTION
//  ----------------------------------------------------
//  Un paiement annulé garde ses allocations : les effacer ferait perdre
//  la trace de ce qui avait été soldé. Toute somme encaissée se lit donc
//  en joignant `payments` et en filtrant `p.is_cancelled = 0`.
//
//  Oublier ce filtre ferait apparaître comme payée une dette dont le
//  reçu a été annulé — l'erreur la plus coûteuse possible dans une
//  caisse d'école.
// =====================================================================

/** Paiements d'une inscription, du plus récent au plus ancien. */
function finance_repo_payments(int $enrollmentId, bool $includeCancelled = true): array
{
    $sql = 'SELECT p.*,
                   u.last_name  AS cashier_last_name,
                   u.first_name AS cashier_first_name,
                   (SELECT COALESCE(SUM(pa.amount), 0)
                      FROM payment_allocations pa
                      JOIN student_fees sf ON sf.id = pa.student_fee_id
                                          AND sf.school_id = pa.school_id
                     WHERE pa.school_id = p.school_id
                       AND pa.payment_id = p.id
                       AND sf.is_cancelled = 0) AS allocated
              FROM payments p
         LEFT JOIN users u ON u.id = p.received_by
             WHERE p.school_id = :school_id
               AND p.enrollment_id = :enrollment_id';

    if (!$includeCancelled) {
        $sql .= ' AND p.is_cancelled = 0';
    }

    $sql .= ' ORDER BY p.paid_on DESC, p.receipt_seq DESC';

    return db_all($sql, [
        'school_id'     => tenant_require(),
        'enrollment_id' => $enrollmentId,
    ]);
}

function finance_repo_payment(int $paymentId): ?array
{
    return db_one(
        'SELECT p.*,
                e.student_id, e.academic_year_id, e.classroom_id,
                u.last_name  AS cashier_last_name,
                u.first_name AS cashier_first_name
           FROM payments p
           JOIN enrollments e ON e.id = p.enrollment_id AND e.school_id = p.school_id
      LEFT JOIN users u ON u.id = p.received_by
          WHERE p.id = :id AND p.school_id = :school_id',
        ['id' => $paymentId, 'school_id' => tenant_require()]
    );
}

/** Répartition d'un paiement, dette par dette. */
function finance_repo_allocations(int $paymentId): array
{
    return db_all(
        'SELECT pa.*, sf.label, sf.due_on, sf.amount_due, sf.discount_amount
           FROM payment_allocations pa
           JOIN student_fees sf ON sf.id = pa.student_fee_id AND sf.school_id = pa.school_id
          WHERE pa.school_id = :school_id AND pa.payment_id = :payment_id
          ORDER BY sf.due_on IS NULL, sf.due_on, sf.id',
        ['school_id' => tenant_require(), 'payment_id' => $paymentId]
    );
}

/**
 * Dettes d'une inscription, avec ce qui a déjà été encaissé dessus.
 *
 * C'est la requête centrale de la caisse : `paid` ne compte que les
 * allocations rattachées à un paiement NON ANNULÉ.
 */
function finance_repo_fees_with_paid(int $enrollmentId, bool $includeCancelled = false): array
{
    $sql = 'SELECT sf.*,
                   (sf.amount_due - sf.discount_amount) AS amount_net,
                   COALESCE((
                       SELECT SUM(pa.amount)
                         FROM payment_allocations pa
                         JOIN payments p ON p.id = pa.payment_id AND p.school_id = pa.school_id
                        WHERE pa.school_id = sf.school_id
                          AND pa.student_fee_id = sf.id
                          AND p.is_cancelled = 0
                   ), 0) AS paid,
                   f.group_label, f.is_mandatory
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

/**
 * Solde d'une inscription, PAR DEVISE.
 *
 * @return array<string, array{due: float, paid: float, balance: float, advance: float}>
 */
function finance_repo_balance(int $enrollmentId): array
{
    $totals = [];

    foreach (finance_repo_fees_with_paid($enrollmentId) as $line) {
        $currency = (string) $line['currency'];

        $totals[$currency] ??= ['due' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'advance' => 0.0];
        $totals[$currency]['due']  += (float) $line['amount_net'];
        $totals[$currency]['paid'] += (float) $line['paid'];
    }

    // L'AVANCE : encaissé mais pas encore rattaché à une dette.
    //
    // En RDC un parent paie souvent sur la tranche suivante, qui n'est
    // pas encore affectée. Refuser le reliquat obligerait le caissier à
    // mentir sur le montant reçu ; le perdre reviendrait à voler la
    // famille. Il est donc conservé et affiché comme une avance.
    // UNE ALLOCATION SUR UNE DETTE ANNULÉE NE COMPTE PLUS.
    //
    // Sans cette jointure, annuler une dette déjà payée faisait
    // DISPARAÎTRE l'argent : la dette sortait des totaux, et son
    // allocation continuait pourtant à absorber le crédit du paiement.
    // Cinquante dollars encaissés s'évaporaient des livres.
    //
    // Les allocations ne sont pas effacées pour autant — elles gardent
    // la trace de ce qui avait été soldé, et reviennent d'elles-mêmes
    // si la dette est rétablie.
    $rows = db_all(
        'SELECT p.credited_currency AS currency,
                SUM(p.credited_amount) AS credited,
                COALESCE(SUM((SELECT SUM(pa.amount)
                                FROM payment_allocations pa
                                JOIN student_fees sf ON sf.id = pa.student_fee_id
                                                    AND sf.school_id = pa.school_id
                               WHERE pa.school_id = p.school_id
                                 AND pa.payment_id = p.id
                                 AND sf.is_cancelled = 0)), 0) AS allocated
           FROM payments p
          WHERE p.school_id = :school_id
            AND p.enrollment_id = :enrollment_id
            AND p.is_cancelled = 0
          GROUP BY p.credited_currency',
        ['school_id' => tenant_require(), 'enrollment_id' => $enrollmentId]
    );

    foreach ($rows as $row) {
        $currency = (string) $row['currency'];
        $totals[$currency] ??= ['due' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'advance' => 0.0];
        $totals[$currency]['advance'] = (float) $row['credited'] - (float) $row['allocated'];
    }

    foreach ($totals as $currency => $t) {
        $totals[$currency]['balance'] = round($t['due'] - $t['paid'], 2);
        $totals[$currency]['advance'] = round($t['advance'], 2);
    }

    return $totals;
}

/** Somme déjà allouée à une dette par des paiements non annulés. */
function finance_repo_paid_on_fee(int $studentFeeId): float
{
    return (float) db_value(
        'SELECT COALESCE(SUM(pa.amount), 0)
           FROM payment_allocations pa
           JOIN payments p ON p.id = pa.payment_id AND p.school_id = pa.school_id
          WHERE pa.school_id = :school_id
            AND pa.student_fee_id = :fee_id
            AND p.is_cancelled = 0',
        ['school_id' => tenant_require(), 'fee_id' => $studentFeeId]
    );
}

/**
 * Journal de caisse d'une journée — ce que le caissier remet le soir.
 *
 * LE PÉRIMÈTRE EST DANS LA REQUÊTE, PAS DANS UNE BOUCLE.
 * Le contrôleur filtrait ligne à ligne en appelant
 * finance_can_view_enrollment() : une journée de 200 reçus déclenchait
 * 200 requêtes supplémentaires. La condition de périmètre est la même
 * (students_scope_clause, source unique du projet), mais appliquée une
 * seule fois, par la base.
 */
function finance_repo_cashbook(string $date): array
{
    [$scope, $scopeParams] = students_scope_clause();

    return db_all(
        'SELECT p.*,
                s.matricule, s.last_name, s.post_name, s.first_name,
                c.name AS classroom_name,
                u.last_name AS cashier_last_name, u.first_name AS cashier_first_name
           FROM payments p
           JOIN enrollments e ON e.id = p.enrollment_id AND e.school_id = p.school_id
           JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
      LEFT JOIN classrooms c ON c.id = e.classroom_id AND c.school_id = e.school_id
      LEFT JOIN users u ON u.id = p.received_by
          WHERE p.school_id = :school_id
            AND p.paid_on = :date
            AND s.deleted_at IS NULL
            AND ' . $scope . '
          ORDER BY p.receipt_seq',
        $scopeParams + ['school_id' => tenant_require(), 'date' => $date]
    );
}

// =====================================================================
//  ÉTAT DES IMPAYÉS (phase 5C)
//
//  Une seule requête agrégée, jamais une boucle. Sur 1 200 inscrits et
//  6 frais, une lecture ligne à ligne ferait 7 200 allers-retours —
//  l'écran deviendrait inutilisable précisément dans l'école qui en a
//  le plus besoin.
//
//  LE RETARD SE CALCULE PAR DETTE, PAS PAR SOLDE
//  ---------------------------------------------
//  Un élève qui doit 100 USD dont 50 échus et 50 à échoir n'est pas
//  « en retard de 100 ». Le retard se mesure échéance par échéance,
//  puis se somme. Une dette sans échéance n'est jamais en retard.
// =====================================================================

/**
 * Impayés d'une année, une ligne par inscription ET par devise.
 *
 * @param array{classroom_id?: int|null, currency?: string|null,
 *              only_overdue?: bool, include_cancelled?: bool} $filters
 */
function finance_repo_outstanding(int $yearId, array $filters = []): array
{
    [$scope, $scopeParams] = students_scope_clause($yearId);

    // `school_alloc` double `school_id` a dessein : avec
    // ATTR_EMULATE_PREPARES = false, un parametre nomme ne peut pas
    // apparaitre deux fois dans la meme requete (HY093). C'est la
    // cinquieme fois que ce piege se presente dans ce projet.
    $params = $scopeParams + [
        'school_id'    => tenant_require(),
        'school_alloc' => tenant_require(),
        'year_id'      => $yearId,
        'today'        => date('Y-m-d'),
    ];

    $where = [
        'sf.school_id = :school_id',
        'e.academic_year_id = :year_id',
        'sf.is_cancelled = 0',
        's.deleted_at IS NULL',
        $scope,
    ];

    // UNE INSCRIPTION ANNULÉE GARDE SES DETTES.
    //
    // Elles disparaissaient de tous les écrans, qui filtraient sur
    // « enrolled » : l'école perdait de vue une créance bien réelle —
    // un élève parti en janvier doit toujours le premier trimestre.
    // Elles sont donc consultables, mais jamais mélangées par défaut
    // aux impayés des élèves présents.
    if (!empty($filters['include_cancelled'])) {
        $where[]            = 'e.status IN (:status_enrolled, :status_cancelled)';
        $params['status_enrolled']  = 'enrolled';
        $params['status_cancelled'] = 'cancelled';
    } else {
        $where[]           = 'e.status = :status_enrolled';
        $params['status_enrolled'] = 'enrolled';
    }

    if (!empty($filters['classroom_id'])) {
        $where[]                 = 'e.classroom_id = :classroom_id';
        $params['classroom_id']  = (int) $filters['classroom_id'];
    }

    if (!empty($filters['currency'])) {
        $where[]             = 'sf.currency = :currency';
        $params['currency']  = (string) $filters['currency'];
    }

    $sql = 'SELECT e.id            AS enrollment_id,
                   e.status        AS enrollment_status,
                   s.id            AS student_id,
                   s.matricule, s.last_name, s.post_name, s.first_name,
                   c.name          AS classroom_name,
                   sf.currency,
                   SUM(sf.amount_due - sf.discount_amount)                       AS due,
                   SUM(COALESCE(a.paid, 0))                                      AS paid,
                   SUM(GREATEST(sf.amount_due - sf.discount_amount
                                - COALESCE(a.paid, 0), 0))                       AS balance,
                   SUM(CASE
                         WHEN sf.due_on IS NOT NULL AND sf.due_on < :today
                         THEN GREATEST(sf.amount_due - sf.discount_amount
                                       - COALESCE(a.paid, 0), 0)
                         ELSE 0
                       END)                                                      AS overdue,
                   MIN(CASE
                         WHEN sf.due_on IS NOT NULL
                              AND (sf.amount_due - sf.discount_amount
                                   - COALESCE(a.paid, 0)) > 0.005
                         THEN sf.due_on
                       END)                                                      AS oldest_due
              FROM student_fees sf
              JOIN enrollments e ON e.id = sf.enrollment_id AND e.school_id = sf.school_id
              JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
         LEFT JOIN classrooms c ON c.id = e.classroom_id AND c.school_id = e.school_id
         LEFT JOIN (
                   SELECT pa.student_fee_id, SUM(pa.amount) AS paid
                     FROM payment_allocations pa
                     JOIN payments pm ON pm.id = pa.payment_id AND pm.school_id = pa.school_id
                    WHERE pa.school_id = :school_alloc
                      AND pm.is_cancelled = 0
                    GROUP BY pa.student_fee_id
              ) a ON a.student_fee_id = sf.id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY e.id, e.status, s.id, s.matricule, s.last_name, s.post_name,
                      s.first_name, c.name, sf.currency';

    // Le filtre « en retard » porte sur l'agrégat : il appartient donc
    // au HAVING, pas au WHERE.
    if (!empty($filters['only_overdue'])) {
        $sql .= ' HAVING overdue > 0.005';
    } else {
        $sql .= ' HAVING balance > 0.005';
    }

    $sql .= ' ORDER BY overdue DESC, balance DESC, s.last_name, s.first_name';

    return db_all($sql, $params);
}

/**
 * Totaux d'une année, par devise : réclamé, encaissé, restant, en retard.
 *
 * Sert l'en-tête du tableau de bord financier. Les devises ne sont
 * jamais additionnées entre elles.
 *
 * @return array<string, array{due: float, paid: float, balance: float, overdue: float, students: int}>
 */
function finance_repo_year_totals(int $yearId, bool $includeCancelled = false): array
{
    $totals = [];

    foreach (finance_repo_outstanding($yearId, ['include_cancelled' => $includeCancelled]) as $row) {
        $currency = (string) $row['currency'];

        $totals[$currency] ??= [
            'due' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'overdue' => 0.0, 'students' => 0,
        ];

        $totals[$currency]['due']     += (float) $row['due'];
        $totals[$currency]['paid']    += (float) $row['paid'];
        $totals[$currency]['balance'] += (float) $row['balance'];
        $totals[$currency]['overdue'] += (float) $row['overdue'];
        $totals[$currency]['students']++;
    }

    return $totals;
}

/**
 * Recouvrement par classe : une ligne par classe et par devise.
 *
 * `debtors` compte les élèves qui doivent encore quelque chose ;
 * `late` ceux dont une échéance est dépassée.
 */
function finance_repo_recovery_by_classroom(int $yearId): array
{
    $rows = [];

    foreach (finance_repo_outstanding($yearId) as $row) {
        $key = ($row['classroom_name'] ?? '—') . '|' . $row['currency'];

        $rows[$key] ??= [
            'classroom_name' => $row['classroom_name'],
            'currency'       => $row['currency'],
            'balance'        => 0.0,
            'overdue'        => 0.0,
            'debtors'        => 0,
            'late'           => 0,
        ];

        $rows[$key]['balance'] += (float) $row['balance'];
        $rows[$key]['overdue'] += (float) $row['overdue'];
        $rows[$key]['debtors']++;

        if ((float) $row['overdue'] > 0.005) {
            $rows[$key]['late']++;
        }
    }

    uasort($rows, static function (array $a, array $b): int {
        return [$a['classroom_name'], $a['currency']] <=> [$b['classroom_name'], $b['currency']];
    });

    return array_values($rows);
}

/**
 * Avances d'une année : encaissé non encore imputé, par inscription et
 * par devise.
 *
 * POURQUOI UNE REQUÊTE À PART
 * ---------------------------
 * L'état des impayés se lit sur les DETTES. Une avance vit sur les
 * PAIEMENTS. Les joindre dans la même requête agrégée donnerait une
 * sous-requête imbriquée à trois niveaux, illisible et fragile ; deux
 * requêtes simples, fusionnées en PHP, coûtent le même temps et se
 * relisent.
 *
 * Sans cette colonne, la liste des impayés ferait relancer une famille
 * dont l'école détient déjà l'argent — le pire reproche qu'on puisse
 * faire à un état de recouvrement.
 *
 * @return array<int, array<string, float>> [enrollment_id][devise] => avance
 */
function finance_repo_advances(int $yearId): array
{
    $rows = db_all(
        'SELECT pm.enrollment_id,
                pm.credited_currency AS currency,
                SUM(pm.credited_amount) - COALESCE(SUM(al.allocated), 0) AS advance
           FROM payments pm
           JOIN enrollments e ON e.id = pm.enrollment_id AND e.school_id = pm.school_id
      LEFT JOIN (
                SELECT pa.payment_id, SUM(pa.amount) AS allocated
                  FROM payment_allocations pa
                  JOIN student_fees sf ON sf.id = pa.student_fee_id AND sf.school_id = pa.school_id
                 WHERE pa.school_id = :school_alloc
                   AND sf.is_cancelled = 0
                 GROUP BY pa.payment_id
           ) al ON al.payment_id = pm.id
          WHERE pm.school_id = :school_id
            AND pm.is_cancelled = 0
            AND e.academic_year_id = :year_id
          GROUP BY pm.enrollment_id, pm.credited_currency',
        [
            'school_id'    => tenant_require(),
            'school_alloc' => tenant_require(),
            'year_id'      => $yearId,
        ]
    );

    $advances = [];

    foreach ($rows as $row) {
        $advance = round((float) $row['advance'], 2);

        if ($advance > 0.005) {
            $advances[(int) $row['enrollment_id']][(string) $row['currency']] = $advance;
        }
    }

    return $advances;
}

/** Paiements d'une inscription portant encore un reliquat non imputé. */
function finance_repo_payments_with_remainder(int $enrollmentId, string $currency): array
{
    return db_all(
        'SELECT pm.id,
                pm.credited_amount
                - COALESCE((SELECT SUM(pa.amount)
                              FROM payment_allocations pa
                              JOIN student_fees sf ON sf.id = pa.student_fee_id
                                                  AND sf.school_id = pa.school_id
                             WHERE pa.school_id = pm.school_id
                               AND pa.payment_id = pm.id
                               AND sf.is_cancelled = 0), 0) AS remainder
           FROM payments pm
          WHERE pm.school_id = :school_id
            AND pm.enrollment_id = :enrollment_id
            AND pm.credited_currency = :currency
            AND pm.is_cancelled = 0
          HAVING remainder > 0.005
          ORDER BY pm.receipt_seq',
        [
            'school_id'     => tenant_require(),
            'enrollment_id' => $enrollmentId,
            'currency'      => $currency,
        ]
    );
}

// =====================================================================
//  DÉPENSES (phase 5D)
//
//  Aucune fonction de cette section n'additionne deux devises. Une
//  dépense est engagée dans sa monnaie, comme une dette est due dans
//  la sienne.
// =====================================================================

/** Postes de dépense actifs, dans l'ordre d'affichage. */
function finance_repo_expense_categories(): array
{
    return db_all(
        'SELECT id, code, name FROM expense_categories
          WHERE is_active = 1 ORDER BY position, name',
        [],
        true // table globale, partagée par toutes les écoles
    );
}

function finance_repo_expense(int $expenseId): ?array
{
    return db_one(
        'SELECT x.*, c.name AS category_name, c.code AS category_code,
                u.last_name AS author_last_name, u.first_name AS author_first_name
           FROM expenses x
      LEFT JOIN expense_categories c ON c.id = x.category_id
      LEFT JOIN users u ON u.id = x.recorded_by
          WHERE x.id = :id AND x.school_id = :school_id',
        ['id' => $expenseId, 'school_id' => tenant_require()]
    );
}

/**
 * Dépenses d'une année, filtrables.
 *
 * @param array{category_id?: int|null, currency?: string|null,
 *              from?: string|null, to?: string|null,
 *              include_cancelled?: bool} $filters
 */
function finance_repo_expenses(int $yearId, array $filters = []): array
{
    $params = ['school_id' => tenant_require(), 'year_id' => $yearId];
    $where  = ['x.school_id = :school_id', 'x.academic_year_id = :year_id'];

    // Les dépenses annulées restent visibles par défaut, barrées : les
    // faire disparaître rendrait la séquence des bons incompréhensible
    // et masquerait les erreurs de caisse. Même règle que les reçus.
    if (isset($filters['include_cancelled']) && !$filters['include_cancelled']) {
        $where[] = 'x.is_cancelled = 0';
    }

    if (!empty($filters['category_id'])) {
        $where[]                = 'x.category_id = :category_id';
        $params['category_id']  = (int) $filters['category_id'];
    }

    if (!empty($filters['currency'])) {
        $where[]            = 'x.currency = :currency';
        $params['currency'] = (string) $filters['currency'];
    }

    if (!empty($filters['from'])) {
        $where[]        = 'x.spent_on >= :from';
        $params['from'] = (string) $filters['from'];
    }

    if (!empty($filters['to'])) {
        $where[]      = 'x.spent_on <= :to';
        $params['to'] = (string) $filters['to'];
    }

    return db_all(
        'SELECT x.*, c.name AS category_name,
                u.last_name AS author_last_name, u.first_name AS author_first_name
           FROM expenses x
      LEFT JOIN expense_categories c ON c.id = x.category_id
      LEFT JOIN users u ON u.id = x.recorded_by
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY x.spent_on DESC, x.voucher_seq DESC',
        $params
    );
}

/**
 * Totaux des dépenses d'une année, par devise puis par poste.
 *
 * @return array<string, array{total: float, categories: array<string, float>}>
 */
function finance_repo_expense_totals(int $yearId): array
{
    $rows = db_all(
        'SELECT x.currency,
                COALESCE(c.name, :unknown) AS category_name,
                SUM(x.amount) AS total
           FROM expenses x
      LEFT JOIN expense_categories c ON c.id = x.category_id
          WHERE x.school_id = :school_id
            AND x.academic_year_id = :year_id
            AND x.is_cancelled = 0
          GROUP BY x.currency, c.name
          ORDER BY x.currency, total DESC',
        [
            'school_id' => tenant_require(),
            'year_id'   => $yearId,
            'unknown'   => 'Poste supprimé',
        ]
    );

    $totals = [];

    foreach ($rows as $row) {
        $currency = (string) $row['currency'];

        $totals[$currency] ??= ['total' => 0.0, 'categories' => []];
        $totals[$currency]['total'] += (float) $row['total'];
        $totals[$currency]['categories'][(string) $row['category_name']] = (float) $row['total'];
    }

    return $totals;
}

/** Dépenses d'une journée — pour le journal de caisse. */
function finance_repo_expenses_of_day(string $date): array
{
    return db_all(
        'SELECT x.*, c.name AS category_name,
                u.last_name AS author_last_name, u.first_name AS author_first_name
           FROM expenses x
      LEFT JOIN expense_categories c ON c.id = x.category_id
      LEFT JOIN users u ON u.id = x.recorded_by
          WHERE x.school_id = :school_id AND x.spent_on = :date
          ORDER BY x.voucher_seq',
        ['school_id' => tenant_require(), 'date' => $date]
    );
}

/**
 * Situation de caisse à une date : ce qui est entré, ce qui est sorti,
 * et ce qui devrait rester.
 *
 * LA MONNAIE COMPTÉE EST CELLE QUI A ÉTÉ REMISE
 * ---------------------------------------------
 * Un parent remet 140 000 CDF pour une dette en USD : ce sont bien
 * 140 000 CDF qui entrent dans le tiroir, pas 50 USD. Le solde de
 * caisse se calcule donc sur `tendered_currency`, jamais sur la devise
 * portée au crédit des dettes. Confondre les deux donnerait une caisse
 * qui ne se recoupe jamais.
 *
 * @return array<string, array{in: float, out: float, balance: float}>
 */
function finance_repo_cash_position(string $upToDate): array
{
    $schoolId = tenant_require();
    $position = [];

    $in = db_all(
        'SELECT tendered_currency AS currency, SUM(tendered_amount) AS total
           FROM payments
          WHERE school_id = :school_id AND is_cancelled = 0 AND paid_on <= :date
          GROUP BY tendered_currency',
        ['school_id' => $schoolId, 'date' => $upToDate]
    );

    foreach ($in as $row) {
        $currency = (string) $row['currency'];
        $position[$currency] ??= ['in' => 0.0, 'out' => 0.0, 'balance' => 0.0];
        $position[$currency]['in'] = (float) $row['total'];
    }

    $out = db_all(
        'SELECT currency, SUM(amount) AS total
           FROM expenses
          WHERE school_id = :school_id AND is_cancelled = 0 AND spent_on <= :date
          GROUP BY currency',
        ['school_id' => $schoolId, 'date' => $upToDate]
    );

    foreach ($out as $row) {
        $currency = (string) $row['currency'];
        $position[$currency] ??= ['in' => 0.0, 'out' => 0.0, 'balance' => 0.0];
        $position[$currency]['out'] = (float) $row['total'];
    }

    foreach ($position as $currency => $line) {
        $position[$currency]['balance'] = round($line['in'] - $line['out'], 2);
    }

    return $position;
}
