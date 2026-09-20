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

    // UN BILLET, UN REÇU.
    //
    // Le jeton CSRF vit deux heures : un double clic le présente deux
    // fois et deux reçus naissaient pour un seul versement (audit 5D).
    // Le jeton à usage unique refuse le second envoi.
    if (!form_nonce_consume('finance.pay', (string) input(FORM_NONCE_FIELD, ''))) {
        flash_error(
            'Ce formulaire a déjà été envoyé. Vérifiez la fiche de l\'élève avant de '
            . 'ressaisir : un encaissement y figure peut-être déjà.'
        );
        redirect('/finances/eleve/' . $enrollmentId);
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
        // Le journal d'une caisse montre les deux sens. N'y mettre que
        // les entrées donnerait un document qui ne se recoupe jamais
        // avec l'argent physiquement compté le soir.
        'expenses' => finance_repo_expenses_of_day($date),
        'position' => finance_repo_cash_position($date),
    ]);
}

// =====================================================================
//  RECOUVREMENT (phase 5C)
// =====================================================================

/**
 * État des impayés.
 *
 * Les avances y figurent : sans elles, l'école relancerait une famille
 * dont elle détient déjà le versement.
 */
function ctrl_finance_outstanding(): void
{
    $year = finance_requested_year();

    if ($year === null) {
        flash_error('Aucune année scolaire active.');
        redirect('/finances');
    }

    $yearId  = (int) $year['id'];
    $filters = [
        'classroom_id'      => ((int) input('classe', 0)) ?: null,
        'currency'          => isset(FINANCE_CURRENCIES[(string) input('devise', '')])
            ? (string) input('devise')
            : null,
        'only_overdue'      => (string) input('retard', '') === '1',
        'include_cancelled' => (string) input('partis', '') === '1',
    ];

    $rows     = finance_repo_outstanding($yearId, $filters);
    $advances = finance_repo_advances($yearId);

    // Fusion en PHP : une avance vit sur les paiements, une dette sur
    // les frais. Deux requêtes simples valent mieux qu'une imbriquée.
    foreach ($rows as $i => $row) {
        $rows[$i]['advance'] = $advances[(int) $row['enrollment_id']][(string) $row['currency']] ?? 0.0;
    }

    if ((string) input('export', '') === 'csv') {
        finance_send_outstanding_csv($rows, (string) $year['code']);
    }

    view('finance/outstanding', [
        'title'      => 'Impayés',
        'year'       => $year,
        'years'      => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'rows'       => $rows,
        'filters'    => $filters,
        'currencies' => FINANCE_CURRENCIES,
        'classrooms' => tenant_all(
            'classrooms',
            'academic_year_id = :y ORDER BY name',
            ['y' => $yearId]
        ),
        'totals'     => finance_repo_year_totals($yearId, $filters['include_cancelled']),
        'byClass'    => finance_repo_recovery_by_classroom($yearId),
    ]);
}

/**
 * Construit le CSV de l'état des impayés.
 *
 * SÉPARÉE DE L'ENVOI, ET C'EST LE POINT
 * -------------------------------------
 * La version précédente écrivait ses en-têtes HTTP puis appelait exit :
 * elle était INTESTABLE par construction — aucun test, aucune sonde ne
 * pouvait l'exécuter sans tuer le processus. « Un dépôt non exécuté est
 * un dépôt non testé » : ici, le code était non exécutable.
 *
 * CSV et non XLSX : l'hébergement cible est un cPanel mutualisé, sans
 * Composer ni bibliothèque de tableur. Un CSV s'ouvre dans Excel comme
 * dans LibreOffice, et ne dépend de rien.
 *
 * Le BOM UTF-8 est indispensable : sans lui, Excel sous Windows affiche
 * « MBILA Émile » en « MBILA Ã‰mile », et l'école conclut que le
 * logiciel abîme les noms. Le point-virgule l'est tout autant : la
 * virgule est le séparateur DÉCIMAL en français.
 */
function finance_build_outstanding_csv(array $rows): string
{
    // Le quatrième et le cinquième argument de fputcsv() sont écrits
    // EXPLICITEMENT. PHP 8.4 émet sinon une dépréciation — que le
    // gestionnaire d'erreurs du projet transforme en exception : sans
    // eux, l'export ne produisait rien du tout. Trouvé en exécutant
    // l'export, jamais à la relecture.
    //
    // L'échappement vide est le comportement de RFC 4180, celui vers
    // lequel PHP se dirige, et celui qu'Excel attend.
    $handle = fopen('php://temp', 'r+b');

    fwrite($handle, "\xEF\xBB\xBF");

    fputcsv($handle, [
        'Matricule', 'Nom', 'Post-nom', 'Prénom', 'Classe', 'Statut',
        'Devise', 'Dû', 'Encaissé', 'Reste', 'En retard', 'Avance', 'Échéance la plus ancienne',
    ], ';', '"', '');

    foreach ($rows as $row) {
        fputcsv($handle, [
            $row['matricule'],
            $row['last_name'],
            $row['post_name'] ?? '',
            $row['first_name'],
            $row['classroom_name'] ?? '',
            $row['enrollment_status'] === 'cancelled' ? 'parti' : 'inscrit',
            $row['currency'],
            number_format((float) $row['due'], 2, ',', ''),
            number_format((float) $row['paid'], 2, ',', ''),
            number_format((float) $row['balance'], 2, ',', ''),
            number_format((float) $row['overdue'], 2, ',', ''),
            number_format((float) ($row['advance'] ?? 0), 2, ',', ''),
            $row['oldest_due'] ?? '',
        ], ';', '"', '');
    }

    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    return $csv;
}

/** Envoie le CSV en téléchargement. */
function finance_send_outstanding_csv(array $rows, string $yearCode): never
{
    $filename = 'impayes-' . $yearCode . '-' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo finance_build_outstanding_csv($rows);

    exit;
}

function ctrl_finance_apply_advances(): void
{
    $yearId      = (int) input('academic_year_id', 0);
    $classroomId = ((int) input('classroom_id', 0)) ?: null;

    if ($classroomId !== null && !students_can_view_classroom($classroomId)) {
        abort(404, 'Classe introuvable.');
    }

    $outcome = finance_service_apply_advances($yearId, $classroomId);

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect((string) input('retour', '/finances/impayes?annee=' . $yearId));
}

/** Avis d'impayé imprimable, remis à la famille. */
function ctrl_finance_notice(string $id): void
{
    $enrollmentId = (int) $id;

    if (!finance_can_view_enrollment($enrollmentId)) {
        abort(404, 'Dossier introuvable.');
    }

    $enrollment = tenant_find('enrollments', $enrollmentId);
    $student    = tenant_find('students', (int) $enrollment['student_id']);

    view('finance/notice', [
        'title'      => 'Avis de situation',
        'enrollment' => $enrollment,
        'student'    => $student,
        'classroom'  => $enrollment['classroom_id'] !== null
            ? tenant_find('classrooms', (int) $enrollment['classroom_id'])
            : null,
        'year'       => tenant_find('academic_years', (int) $enrollment['academic_year_id']),
        'lines'      => finance_repo_fees_with_paid($enrollmentId),
        'balance'    => finance_repo_balance($enrollmentId),
        'school'     => db_one('SELECT * FROM schools WHERE id = :id', ['id' => tenant_require()], true),
        'today'      => date('Y-m-d'),
    ]);
}

// =====================================================================
//  DÉPENSES (phase 5D)
// =====================================================================

function ctrl_finance_expenses(): void
{
    $year = finance_requested_year();

    if ($year === null) {
        flash_error('Aucune année scolaire active.');
        redirect('/finances');
    }

    $yearId  = (int) $year['id'];
    $filters = [
        'category_id'       => ((int) input('poste', 0)) ?: null,
        'currency'          => isset(FINANCE_CURRENCIES[(string) input('devise', '')])
            ? (string) input('devise')
            : null,
        'from'              => finance_valid_date((string) input('du', '')) ? (string) input('du') : null,
        'to'                => finance_valid_date((string) input('au', '')) ? (string) input('au') : null,
        'include_cancelled' => (string) input('annulees', '1') === '1',
    ];

    view('finance/expenses', [
        'title'      => 'Dépenses',
        'year'       => $year,
        'years'      => tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC'),
        'expenses'   => finance_repo_expenses($yearId, $filters),
        'filters'    => $filters,
        'categories' => finance_repo_expense_categories(),
        'currencies' => FINANCE_CURRENCIES,
        'methods'    => FINANCE_METHODS,
        'totals'     => finance_repo_expense_totals($yearId),
        'position'   => finance_repo_cash_position(date('Y-m-d')),
        'today'      => date('Y-m-d'),
        'defaultCurrency' => finance_default_currency(),
    ]);
}

function ctrl_finance_expense_save(): void
{
    $yearId = (int) input('academic_year_id', 0);

    // Même garde-fou qu'à l'encaissement : un double clic ne doit pas
    // produire deux bons de sortie pour une seule enveloppe remise.
    if (!form_nonce_consume('finance.expense', (string) input(FORM_NONCE_FIELD, ''))) {
        flash_error(
            'Ce formulaire a déjà été envoyé. Vérifiez la liste des bons avant de '
            . 'ressaisir : la dépense y figure peut-être déjà.'
        );
        redirect('/finances/depenses?annee=' . $yearId);
    }

    $outcome = finance_service_record_expense($yearId, $_POST);

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/finances/depenses?annee=' . $yearId);
}

function ctrl_finance_expense_cancel(string $id): void
{
    $expense = finance_repo_expense((int) $id);

    if ($expense === null) {
        abort(404, 'Dépense introuvable.');
    }

    $outcome = finance_service_cancel_expense((int) $id, (string) input('motif', ''));

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/finances/depenses?annee=' . (int) $expense['academic_year_id']);
}
