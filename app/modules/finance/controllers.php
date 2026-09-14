<?php
/**
 * Module FINANCE — contrôleurs (phase 5A).
 *
 * Trois écrans :
 *   · la GRILLE TARIFAIRE de l'année — ce que l'école réclame ;
 *   · l'ÉTAT PAR CLASSE — qui a été facturé, et qui ne l'a pas été ;
 *   · la SITUATION D'UN ÉLÈVE — le détail de sa dette.
 *
 * LE PÉRIMÈTRE EST POSÉ DANS CHAQUE CONTRÔLEUR.
 * finance.view appartient aussi à PARENT. Une restriction d'accès ne
 * vaut que si TOUTES les portes la portent — la leçon revient à chaque
 * phase, et chaque phase l'a d'abord oubliée quelque part.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';
require_once APP_PATH . '/modules/students/repositories.php';

/** Année consultée : celle demandée si elle existe, l'année en cours sinon. */
function finance_requested_year(): ?array
{
    $requested = (int) input('annee', 0);

    if ($requested > 0) {
        $year = tenant_find('academic_years', $requested);

        if ($year !== null) {
            return $year;
        }
    }

    return tenant_one('academic_years', 'is_current = 1');
}

// ---------------------------------------------------------------------
//  ÉTAT DE L'ANNÉE — LA PORTE D'ENTRÉE
// ---------------------------------------------------------------------

function ctrl_finance_index(): void
{
    $year       = finance_requested_year();
    $classrooms = [];

    if ($year !== null) {
        foreach (finance_repo_year_overview((int) $year['id']) as $row) {
            if (students_can_view_classroom((int) $row['classroom_id'])) {
                $classrooms[] = $row;
            }
        }
    }

    // LA PORTE DU PARENT.
    //
    // PARENT détient finance.view depuis la phase 1 mais n'a aucune
    // classe : le tableau par classe lui renvoyait une page vide, et
    // l'entrée de menu ne menait donc nulle part. Quand le périmètre
    // par classe est vide, on bascule sur le périmètre par ÉLÈVE.
    //
    // La requête n'est lancée QUE dans ce cas : sur un compte de
    // direction, elle ramènerait l'établissement entier.
    $enrollments = ($year !== null && $classrooms === [])
        ? finance_repo_scoped_enrollments((int) $year['id'])
        : [];

    view('finance/index', [
        'title'       => 'Finances',
        'year'        => $year,
        'years'       => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'classrooms'  => $classrooms,
        'enrollments' => $enrollments,
        'feeCount'    => $year !== null ? count(finance_repo_fees((int) $year['id'], true)) : 0,
    ]);
}

// ---------------------------------------------------------------------
//  LA GRILLE TARIFAIRE
// ---------------------------------------------------------------------

function ctrl_finance_fees(): void
{
    $year = finance_requested_year();

    if ($year === null) {
        flash_error('Aucune année scolaire active.');
        redirect('/finances');
    }

    $fees = finance_repo_fees((int) $year['id']);

    // Combien de dettes une resynchronisation changerait, frais par
    // frais. Affiché AVANT l'action, jamais découvert après.
    //
    // Et surtout : le réalignement REFUSE de convertir une monnaie. Un
    // écran qui proposerait le bouton dans ce cas promettrait une
    // action qui échoue toujours — une impasse. Les deux situations
    // sont donc séparées.
    $drift   = [];
    $blocked = [];

    foreach ($fees as $fee) {
        $count = finance_service_resync_preview((int) $fee['id']);

        if ($count === 0) {
            continue;
        }

        if (finance_repo_currency_mismatch((int) $fee['id']) > 0) {
            $blocked[(int) $fee['id']] = $count;
        } else {
            $drift[(int) $fee['id']] = $count;
        }
    }

    view('finance/fees', [
        'title'      => 'Grille tarifaire',
        'year'       => $year,
        'years'      => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'fees'       => $fees,
        'drift'      => $drift,
        'blocked'    => $blocked,
        'outOfScope' => finance_repo_out_of_scope((int) $year['id']),
        'currencies' => FINANCE_CURRENCIES,
        'scopes'     => FINANCE_SCOPES,
        'levels'     => db_all(
            'SELECT el.id, el.name, el.short_name
               FROM education_levels el
               JOIN school_cycles sc ON sc.cycle_id = el.cycle_id
              WHERE sc.school_id = :school_id AND sc.is_active = 1
              ORDER BY el.order_number',
            ['school_id' => tenant_require()]
        ),
        'classrooms' => tenant_all(
            'classrooms',
            'academic_year_id = :y ORDER BY name',
            ['y' => (int) $year['id']]
        ),
        'defaultCurrency' => finance_default_currency(),
    ]);
}

function ctrl_finance_fee_save(): void
{
    $feeId   = (int) input('fee_id', 0);
    $outcome = finance_service_save_fee($_POST, $feeId > 0 ? $feeId : null);

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/finances/frais?annee=' . (int) input('academic_year_id', 0));
}

function ctrl_finance_fee_resync(string $id): void
{
    $outcome = finance_service_resync_fee((int) $id, (string) input('motif', ''));

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/finances/frais?annee=' . (int) input('annee', 0));
}

// ---------------------------------------------------------------------
//  AFFECTATION
// ---------------------------------------------------------------------

function ctrl_finance_assign(): void
{
    $yearId      = (int) input('academic_year_id', 0);
    $classroomId = (int) input('classroom_id', 0);

    if (tenant_find('academic_years', $yearId) === null) {
        flash_error('Année scolaire introuvable.');
        redirect('/finances');
    }

    // Le périmètre vaut aussi pour une écriture ciblée sur une classe.
    if ($classroomId > 0 && !students_can_view_classroom($classroomId)) {
        abort(404, 'Classe introuvable.');
    }

    $outcome = finance_service_assign(
        $yearId,
        $classroomId > 0 ? $classroomId : null,
        ((int) input('fee_id', 0)) > 0 ? (int) input('fee_id') : null
    );

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect((string) input('retour', '/finances?annee=' . $yearId));
}

// ---------------------------------------------------------------------
//  ÉTAT D'UNE CLASSE
// ---------------------------------------------------------------------

function ctrl_finance_classroom(string $id): void
{
    $classroomId = (int) $id;
    $classroom   = tenant_find('classrooms', $classroomId);

    if ($classroom === null || !students_can_view_classroom($classroomId)) {
        abort(404, 'Classe introuvable.');
    }

    view('finance/classroom', [
        'title'     => 'Finances — ' . $classroom['name'],
        'classroom' => $classroom,
        'year'      => tenant_find('academic_years', (int) $classroom['academic_year_id']),
        'students'  => finance_repo_classroom_situation($classroomId),
        'due'       => finance_repo_classroom_due($classroomId),
    ]);
}

// ---------------------------------------------------------------------
//  SITUATION D'UN ÉLÈVE
// ---------------------------------------------------------------------

function ctrl_finance_student(string $id): void
{
    $enrollmentId = (int) $id;

    // Ici le périmètre passe par l'ÉLÈVE et non par la classe : c'est la
    // seule porte qu'un parent peut franchir, et il n'a aucune classe.
    if (!finance_can_view_enrollment($enrollmentId)) {
        abort(404, 'Dossier introuvable.');
    }

    $enrollment = tenant_find('enrollments', $enrollmentId);
    $student    = tenant_find('students', (int) $enrollment['student_id']);

    view('finance/student', [
        'title'      => 'Situation financière',
        'enrollment' => $enrollment,
        'student'    => $student,
        'classroom'  => $enrollment['classroom_id'] !== null
            ? tenant_find('classrooms', (int) $enrollment['classroom_id'])
            : null,
        'year'       => tenant_find('academic_years', (int) $enrollment['academic_year_id']),
        'lines'      => finance_repo_student_fees($enrollmentId, true),
        'due'        => finance_repo_due_by_currency($enrollmentId),
    ]);
}

function ctrl_finance_discount(string $id): void
{
    $line = finance_repo_student_fee((int) $id);

    if ($line === null) {
        abort(404, 'Ligne introuvable.');
    }

    $amount  = (float) str_replace([' ', ','], ['', '.'], (string) input('remise', '0'));
    $outcome = finance_service_set_discount((int) $id, $amount, (string) input('motif', ''));

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/finances/eleve/' . (int) $line['enrollment_id']);
}

function ctrl_finance_cancel_line(string $id): void
{
    $line = finance_repo_student_fee((int) $id);

    if ($line === null) {
        abort(404, 'Ligne introuvable.');
    }

    $outcome = finance_service_cancel_line((int) $id, (string) input('motif', ''));

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/finances/eleve/' . (int) $line['enrollment_id']);
}

function ctrl_finance_restore_line(string $id): void
{
    $line = finance_repo_student_fee((int) $id);

    if ($line === null) {
        abort(404, 'Ligne introuvable.');
    }

    $outcome = finance_service_restore_line((int) $id, (string) input('motif', ''));

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/finances/eleve/' . (int) $line['enrollment_id']);
}

function ctrl_finance_cancel_out_of_scope(): void
{
    $yearId = (int) input('academic_year_id', 0);

    if (tenant_find('academic_years', $yearId) === null) {
        flash_error('Année scolaire introuvable.');
        redirect('/finances');
    }

    $outcome = finance_service_cancel_out_of_scope($yearId, (string) input('motif', ''));

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/finances/frais?annee=' . $yearId);
}

// =====================================================================
//  CAISSE (phase 5B)
//
//  payment.record appartient au COMPTABLE ; payment.cancel n'est
//  accordée qu'à la DIRECTION. Celui qui encaisse n'annule pas son
//  propre reçu — c'est la séparation la plus élémentaire d'une caisse.
// =====================================================================

/** Écran d'encaissement d'un élève : dettes ouvertes + formulaire. */
function ctrl_finance_pay_form(string $id): void
{
    $enrollmentId = (int) $id;

    if (!finance_can_view_enrollment($enrollmentId)) {
        abort(404, 'Dossier introuvable.');
    }

    $enrollment = tenant_find('enrollments', $enrollmentId);
    $student    = tenant_find('students', (int) $enrollment['student_id']);

    view('finance/pay', [
        'title'      => 'Encaisser — ' . $student['last_name'],
        'enrollment' => $enrollment,
        'student'    => $student,
        'classroom'  => $enrollment['classroom_id'] !== null
            ? tenant_find('classrooms', (int) $enrollment['classroom_id'])
            : null,
        'year'       => tenant_find('academic_years', (int) $enrollment['academic_year_id']),
        'lines'      => finance_repo_fees_with_paid($enrollmentId),
        'balance'    => finance_repo_balance($enrollmentId),
        'payments'   => finance_repo_payments($enrollmentId),
        'currencies' => FINANCE_CURRENCIES,
        'methods'    => FINANCE_METHODS,
        'rate'       => finance_default_rate(),
        'today'      => date('Y-m-d'),
    ]);
}

function ctrl_finance_pay(string $id): void
{
    $enrollmentId = (int) $id;

    // La répartition manuelle arrive sous forme allocation[<id>] = montant.
    // Vide : le service applique le FIFO.
    $allocations = [];

    foreach ((array) input('allocation', []) as $lineId => $amount) {
        if (trim((string) $amount) !== '') {
            $allocations[(int) $lineId] = $amount;
        }
    }

    $outcome = finance_service_record_payment($enrollmentId, $_POST, $allocations);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        redirect('/finances/eleve/' . $enrollmentId . '/encaisser');
    }

    flash_success($outcome['message']);

    // Le reliquat non affecté est une information de caisse : le taire
    // présenterait une avance comme un solde ordinaire.
    if (($outcome['unallocated'] ?? 0.0) > 0.005) {
        flash_warning(
            'Vérifiez le montant saisi : une partie du paiement ne solde aucune dette ouverte '
            . 'et reste en avance.'
        );
    }

    redirect('/finances/recu/' . (int) $outcome['id']);
}

/** Reçu imprimable. */
function ctrl_finance_receipt(string $id): void
{
    $payment = finance_repo_payment((int) $id);

    if ($payment === null || !finance_can_view_enrollment((int) $payment['enrollment_id'])) {
        abort(404, 'Reçu introuvable.');
    }

    $student = tenant_find('students', (int) $payment['student_id']);

    view('finance/receipt', [
        'title'       => 'Reçu ' . $payment['receipt_no'],
        'payment'     => $payment,
        'student'     => $student,
        'classroom'   => $payment['classroom_id'] !== null
            ? tenant_find('classrooms', (int) $payment['classroom_id'])
            : null,
        'year'        => tenant_find('academic_years', (int) $payment['academic_year_id']),
        'allocations' => finance_repo_allocations((int) $payment['id']),
        'school'      => db_one('SELECT * FROM schools WHERE id = :id', ['id' => tenant_require()], true),
        'balance'     => finance_repo_balance((int) $payment['enrollment_id']),
    ]);
}

function ctrl_finance_cancel_payment(string $id): void
{
    $payment = finance_repo_payment((int) $id);

    if ($payment === null || !finance_can_view_enrollment((int) $payment['enrollment_id'])) {
        abort(404, 'Reçu introuvable.');
    }

    $outcome = finance_service_cancel_payment((int) $id, (string) input('motif', ''));

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/finances/recu/' . (int) $id);
}

function ctrl_finance_reallocate(string $id): void
{
    $payment = finance_repo_payment((int) $id);

    if ($payment === null || !finance_can_view_enrollment((int) $payment['enrollment_id'])) {
        abort(404, 'Reçu introuvable.');
    }

    $allocations = [];

    foreach ((array) input('allocation', []) as $lineId => $amount) {
        if (trim((string) $amount) !== '') {
            $allocations[(int) $lineId] = $amount;
        }
    }

    $outcome = finance_service_reallocate((int) $id, $allocations);

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/finances/recu/' . (int) $id);
}

/** Journal de caisse d'une journée — ce que le caissier remet le soir. */
function ctrl_finance_cashbook(): void
{
    $date = (string) input('date', '');

    if (!finance_valid_date($date)) {
        $date = date('Y-m-d');
    }

    // Le périmètre est appliqué DANS la requête : filtrer ligne à ligne
    // déclenchait une requête par reçu, soit deux cents sur une journée
    // chargée. La route exige par ailleurs report.financial, que le
    // rôle PARENT ne détient pas : ce journal est un document interne,
    // et un tuteur n'a rien à y lire.
    $payments = finance_repo_cashbook($date);

    $totals = [];

    foreach ($payments as $row) {
        if ((int) $row['is_cancelled'] === 1) {
            continue;
        }

        $currency = (string) $row['credited_currency'];
        $totals[$currency] = ($totals[$currency] ?? 0.0) + (float) $row['credited_amount'];
    }

    view('finance/cashbook', [
        'title'    => 'Journal de caisse',
        'date'     => $date,
        'payments' => $payments,
        'totals'   => $totals,
        'methods'  => FINANCE_METHODS,
    ]);
}
