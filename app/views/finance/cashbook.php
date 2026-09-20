<?php
/**
 * Journal de caisse d'une journée.
 *
 * C'est le document que le caissier remet le soir. Il doit se recouper
 * avec l'argent physiquement compté, donc :
 *   · les reçus ANNULÉS y figurent, barrés — les faire disparaître
 *     rendrait la séquence incompréhensible et masquerait les erreurs ;
 *   · les totaux les excluent ;
 *   · les totaux sont donnés PAR DEVISE, et aussi par monnaie REMISE :
 *     c'est cette dernière qui doit correspondre au tiroir-caisse.
 *
 * @var string $date
 * @var array  $payments
 * @var array  $totals   Crédité, par devise
 * @var array  $methods
 * @var array  $expenses Sorties du jour (phase 5D)
 * @var array  $position Entrées, sorties et solde cumulés à cette date
 */
declare(strict_types=1);

set_title('Journal de caisse');

// Ce qu'il doit y avoir dans le tiroir : la monnaie REÇUE, pas celle
// portée au crédit des dettes.
$tendered = [];

foreach ($payments as $row) {
    if ((int) $row['is_cancelled'] === 1) {
        continue;
    }

    $currency = (string) $row['tendered_currency'];
    $tendered[$currency] = ($tendered[$currency] ?? 0.0) + (float) $row['tendered_amount'];
}

$cancelled = array_filter($payments, static fn (array $p): bool => (int) $p['is_cancelled'] === 1);

// Les sorties du jour, par monnaie réellement décaissée.
$paidOut = [];

foreach ($expenses as $row) {
    if ((int) $row['is_cancelled'] === 1) {
        continue;
    }

    $currency = (string) $row['currency'];
    $paidOut[$currency] = ($paidOut[$currency] ?? 0.0) + (float) $row['amount'];
}
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Journal de caisse</h1>
        <p class="page-subtitle">
            <?= e(date('d/m/Y', strtotime($date))) ?>
            · <?= count($payments) ?> reçu(s)
            <?php if ($cancelled !== []): ?>
                · <?= count($cancelled) ?> annulé(s)
            <?php endif; ?>
        </p>
    </div>

    <div class="d-flex gap-2 d-print-none">
        <form method="get" action="<?= e(url('/finances/journal')) ?>" class="d-flex gap-2">
            <input type="date" name="date" value="<?= e($date) ?>"
                   class="form-control form-control-sm" data-auto-submit aria-label="Date">
            <button type="submit" class="btn btn-sm btn-outline-secondary"
                    data-auto-submit-fallback>Voir</button>
        </form>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-print>
            <i class="bi bi-printer me-1"></i> Imprimer
        </button>
    </div>
</div>

<div class="d-print-none"><?php require APP_PATH . '/views/partials/flash.php'; ?></div>

<!-- ============================== LES TOTAUX ============================== -->
<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-medium">À compter dans la caisse</div>
            <div class="card-body">
                <?php if ($tendered === []): ?>
                    <span class="text-secondary">Aucun encaissement.</span>
                <?php else: ?>
                    <?php foreach ($tendered as $currency => $total): ?>
                        <div class="d-flex justify-content-between">
                            <span class="text-secondary">Monnaie reçue</span>
                            <span class="fs-5 fw-semibold">
                                <?= e(finance_amount((float) $total, (string) $currency)) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php foreach ($paidOut as $currency => $total): ?>
                    <div class="d-flex justify-content-between text-danger-emphasis">
                        <span>Sorti ce jour</span>
                        <span class="fw-semibold">
                            − <?= e(finance_amount((float) $total, (string) $currency)) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
                <p class="small text-secondary mb-0 mt-2">
                    La monnaie telle qu'elle a été remise, pas celle portée au crédit
                    des dettes — et diminuée des sorties de la journée.
                </p>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-medium">Porté au crédit des dettes</div>
            <div class="card-body">
                <?php if ($totals === []): ?>
                    <span class="text-secondary">Aucun encaissement.</span>
                <?php else: ?>
                    <?php foreach ($totals as $currency => $total): ?>
                        <div class="d-flex justify-content-between">
                            <span class="text-secondary">Crédité</span>
                            <span class="fs-5 fw-semibold">
                                <?= e(finance_amount((float) $total, (string) $currency)) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <p class="small text-secondary mb-0 mt-2">
                    Les deux colonnes diffèrent dès qu'un parent a payé dans une autre
                    monnaie que celle de ses dettes. C'est normal, et le taux de chaque
                    reçu explique l'écart.
                </p>
            </div>
        </div>
    </div>
</div>

<?php if ($payments === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-journal-text display-6 text-secondary d-block mb-2"></i>
            <p class="mb-0 fw-medium">Aucun encaissement ce jour-là.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Reçu</th>
                        <th scope="col">Élève</th>
                        <th scope="col" class="d-none d-md-table-cell">Classe</th>
                        <th scope="col" class="d-none d-lg-table-cell">Mode</th>
                        <th scope="col" class="text-end">Remis</th>
                        <th scope="col" class="text-end">Crédité</th>
                        <th scope="col" class="d-none d-lg-table-cell">Caissier</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $row): ?>
                        <?php $void = (int) $row['is_cancelled'] === 1; ?>
                        <tr<?= $void ? ' class="opacity-50"' : '' ?>>
                            <td>
                                <a href="<?= e(url('/finances/recu/' . (int) $row['id'])) ?>">
                                    <?= e($row['receipt_no']) ?>
                                </a>
                                <?php if ($void): ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis">annulé</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e(full_name($row['last_name'], $row['post_name'], $row['first_name'])) ?>
                                <div class="small text-secondary d-md-none">
                                    <?= e((string) ($row['classroom_name'] ?? '')) ?>
                                </div>
                            </td>
                            <td class="d-none d-md-table-cell small">
                                <?= e((string) ($row['classroom_name'] ?? '—')) ?>
                            </td>
                            <td class="d-none d-lg-table-cell small">
                                <?= e($methods[$row['method']] ?? (string) $row['method']) ?>
                                <?php if ($row['reference'] !== null): ?>
                                    <div class="text-secondary"><?= e((string) $row['reference']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap<?= $void ? ' text-decoration-line-through' : '' ?>">
                                <?= e(finance_amount((float) $row['tendered_amount'], (string) $row['tendered_currency'])) ?>
                            </td>
                            <td class="text-end text-nowrap fw-medium<?= $void ? ' text-decoration-line-through' : '' ?>">
                                <?= e(finance_amount((float) $row['credited_amount'], (string) $row['credited_currency'])) ?>
                            </td>
                            <td class="d-none d-lg-table-cell small text-secondary">
                                <?= e(trim((string) ($row['cashier_last_name'] ?? '') . ' ' . (string) ($row['cashier_first_name'] ?? ''))) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="small text-secondary mt-2">
        Les reçus annulés restent affichés, barrés : les faire disparaître rendrait la
        séquence des numéros incompréhensible et masquerait les erreurs de guichet.
        Ils sont exclus des totaux.
    </p>
<?php endif; ?>

<!-- =========================== LES SORTIES DU JOUR ======================== -->
<?php if ($expenses !== []): ?>
    <div class="card mt-3">
        <div class="card-header fw-medium">Sorties de caisse du jour</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Bon</th>
                        <th scope="col">Bénéficiaire</th>
                        <th scope="col" class="d-none d-md-table-cell">Poste</th>
                        <th scope="col" class="text-end">Montant</th>
                        <th scope="col" class="d-none d-lg-table-cell">Par</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $row): ?>
                        <?php $void = (int) $row['is_cancelled'] === 1; ?>
                        <tr<?= $void ? ' class="opacity-50"' : '' ?>>
                            <td class="small">
                                <?= e($row['voucher_no']) ?>
                                <?php if ($void): ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis">annulé</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($row['beneficiary']) ?>
                                <div class="small text-secondary"><?= e($row['description']) ?></div>
                            </td>
                            <td class="d-none d-md-table-cell small">
                                <?= e((string) ($row['category_name'] ?? '—')) ?>
                            </td>
                            <td class="text-end text-nowrap fw-medium<?= $void ? ' text-decoration-line-through' : '' ?>">
                                <?= e(finance_amount((float) $row['amount'], (string) $row['currency'])) ?>
                            </td>
                            <td class="d-none d-lg-table-cell small text-secondary">
                                <?= e(trim((string) ($row['author_last_name'] ?? '') . ' ' . (string) ($row['author_first_name'] ?? ''))) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ======================= LE SOLDE CUMULÉ À CETTE DATE =================== -->
<?php if ($position !== []): ?>
    <div class="card mt-3">
        <div class="card-header fw-medium d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>Solde de caisse cumulé au <?= e(date('d/m/Y', strtotime($date))) ?></span>
            <span class="badge bg-secondary-subtle text-secondary-emphasis fw-normal">
                toutes années confondues
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="max-width:40rem">
                <thead>
                    <tr>
                        <th scope="col">Devise</th>
                        <th scope="col" class="text-end">Entré</th>
                        <th scope="col" class="text-end">Sorti</th>
                        <th scope="col" class="text-end">Devrait rester</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($position as $currency => $line): ?>
                        <tr>
                            <td class="fw-medium"><?= e((string) $currency) ?></td>
                            <td class="text-end text-nowrap">
                                <?= e(finance_amount((float) $line['in'], (string) $currency)) ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?= e(finance_amount((float) $line['out'], (string) $currency)) ?>
                            </td>
                            <td class="text-end text-nowrap fw-semibold
                                       <?= (float) $line['balance'] < 0 ? 'text-danger-emphasis' : '' ?>">
                                <?= e(finance_amount((float) $line['balance'], (string) $currency)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
