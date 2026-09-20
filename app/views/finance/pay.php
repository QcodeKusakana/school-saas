<?php
/**
 * Guichet : encaisser un paiement pour un élève.
 *
 * L'écran est fait pour un caissier debout, avec une file d'attente.
 * Il montre d'abord CE QUI RESTE DÛ, puis un formulaire court. La
 * répartition est automatique — la plus ancienne échéance d'abord — et
 * ne se touche que si le parent demande autre chose.
 *
 * @var array      $enrollment
 * @var array      $student
 * @var array|null $classroom
 * @var array|null $year
 * @var array      $lines     Dettes avec ce qui a déjà été encaissé
 * @var array      $balance   Solde PAR DEVISE
 * @var array      $payments
 * @var array      $currencies
 * @var array      $methods
 * @var float      $rate
 * @var string     $today
 */
declare(strict_types=1);

set_title('Encaisser — ' . $student['last_name']);

$open = array_values(array_filter(
    $lines,
    static fn (array $l): bool => (float) $l['amount_net'] - (float) $l['paid'] > 0.005
));
?>

<div class="page-head">
    <div>
        <h1 class="page-title">
            <?= e(full_name($student['last_name'], $student['post_name'], $student['first_name'])) ?>
        </h1>
        <p class="page-subtitle">
            <?= e((string) $student['matricule']) ?>
            <?php if ($classroom !== null): ?> · <?= e($classroom['name']) ?><?php endif; ?>
            <?php if ($year !== null): ?> · <?= e($year['code']) ?><?php endif; ?>
        </p>
    </div>

    <a href="<?= e(url('/finances/eleve/' . (int) $enrollment['id'])) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> La situation
    </a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<!-- ============================== LE SOLDE ============================== -->
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-4">
        <?php if ($balance === []): ?>
            <div class="text-secondary">Aucun frais affecté à cet élève.</div>
        <?php else: ?>
            <?php foreach ($balance as $currency => $b): ?>
                <div>
                    <div class="small text-secondary">Reste à payer</div>
                    <div class="fs-4 fw-semibold <?= $b['balance'] > 0.005 ? 'text-danger-emphasis' : 'text-success-emphasis' ?>">
                        <?= e(finance_amount((float) $b['balance'], (string) $currency)) ?>
                    </div>
                    <div class="small text-secondary">
                        dû <?= e(finance_amount((float) $b['due'], (string) $currency)) ?>
                        · reçu <?= e(finance_amount((float) $b['paid'], (string) $currency)) ?>
                    </div>
                    <?php if ((float) $b['advance'] > 0.005): ?>
                        <div class="small text-info-emphasis">
                            avance disponible : <?= e(finance_amount((float) $b['advance'], (string) $currency)) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <!-- ========================= LES DETTES OUVERTES ===================== -->
    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header fw-medium">Ce qui reste dû</div>

            <?php if ($open === []): ?>
                <div class="card-body text-center py-4">
                    <i class="bi bi-check2-circle display-6 text-success d-block mb-2"></i>
                    <p class="mb-0">Toutes les dettes affectées sont soldées.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Frais</th>
                                <th scope="col" class="d-none d-md-table-cell">Échéance</th>
                                <th scope="col" class="text-end">À payer</th>
                                <th scope="col" class="text-end">Déjà reçu</th>
                                <th scope="col" class="text-end">Reste</th>
                                <th scope="col" class="text-end" style="width:9rem">Imputer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($open as $line): ?>
                                <?php $rest = round((float) $line['amount_net'] - (float) $line['paid'], 2); ?>
                                <tr>
                                    <td>
                                        <span class="fw-medium"><?= e($line['label']) ?></span>
                                        <?php if ((float) $line['discount_amount'] > 0): ?>
                                            <div class="small text-info-emphasis">
                                                remise <?= e(finance_amount((float) $line['discount_amount'], (string) $line['currency'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="d-none d-md-table-cell small">
                                        <?= $line['due_on'] !== null
                                            ? e(date('d/m/Y', strtotime((string) $line['due_on'])))
                                            : '<span class="text-secondary">—</span>' ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?= e(finance_amount((float) $line['amount_net'], (string) $line['currency'])) ?>
                                    </td>
                                    <td class="text-end text-nowrap text-secondary">
                                        <?= e(finance_amount((float) $line['paid'], (string) $line['currency'])) ?>
                                    </td>
                                    <td class="text-end text-nowrap fw-medium">
                                        <?= e(finance_amount($rest, (string) $line['currency'])) ?>
                                    </td>
                                    <td class="text-end">
                                        <input type="text" form="encaissement" inputmode="decimal"
                                               class="form-control form-control-sm text-end"
                                               name="allocation[<?= (int) $line['id'] ?>]"
                                               placeholder="auto">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-secondary">
                    Laissez la colonne « Imputer » vide : le paiement solde la plus ancienne
                    échéance d'abord. Ne la remplissez que si le parent demande une autre
                    imputation — la somme des montants saisis ne dépassera jamais le reste dû
                    de chaque ligne.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========================== LE FORMULAIRE ========================== -->
    <div class="col-12 col-xl-5">
        <div class="card">
            <div class="card-header fw-medium">Encaissement</div>
            <div class="card-body">
                <form method="post" id="encaissement"
                      action="<?= e(url('/finances/eleve/' . (int) $enrollment['id'] . '/encaisser')) ?>">
                    <?= csrf_field() ?>
                    <?php /* Un billet, un reçu : le double clic est refusé. */ ?>
                    <?= form_nonce_field('finance.pay') ?>

                    <div class="mb-3">
                        <label class="form-label" for="p-date">Date</label>
                        <input type="date" class="form-control" id="p-date" name="paid_on"
                               value="<?= e($today) ?>" max="<?= e($today) ?>" required>
                        <div class="form-text">
                            Un encaissement ne se date pas dans le futur : les arrêtés de caisse
                            déjà signés en seraient faussés.
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label" for="p-tendered">Montant remis</label>
                            <input type="text" class="form-control" id="p-tendered" name="tendered_amount"
                                   inputmode="decimal" required placeholder="140 000">
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="p-tcur">Monnaie remise</label>
                            <select class="form-select" id="p-tcur" name="tendered_currency">
                                <?php foreach ($currencies as $code => $label): ?>
                                    <option value="<?= e($code) ?>"><?= e($code) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label" for="p-rate">Taux appliqué</label>
                            <input type="text" class="form-control" id="p-rate" name="exchange_rate"
                                   inputmode="decimal" value="<?= e((string) $rate) ?>">
                            <div class="form-text">
                                Unités remises pour 1 unité créditée. Figé sur le reçu.
                            </div>
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="p-ccur">Dettes à solder</label>
                            <select class="form-select" id="p-ccur" name="credited_currency">
                                <?php foreach ($currencies as $code => $label): ?>
                                    <option value="<?= e($code) ?>"
                                        <?= $code === finance_default_currency() ? 'selected' : '' ?>>
                                        <?= e($code) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="p-method">Mode</label>
                            <select class="form-select" id="p-method" name="method">
                                <?php foreach ($methods as $key => $label): ?>
                                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="p-ref">Référence</label>
                            <input type="text" class="form-control" id="p-ref" name="reference"
                                   maxlength="80" placeholder="N° transaction">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="p-payer">Payeur</label>
                        <input type="text" class="form-control" id="p-payer" name="payer_name"
                               maxlength="120" placeholder="Si ce n'est pas le tuteur principal">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-cash-coin me-1"></i> Encaisser et imprimer le reçu
                    </button>
                </form>
            </div>
        </div>

        <?php if ($payments !== []): ?>
            <div class="card mt-3">
                <div class="card-header fw-medium">Derniers reçus</div>
                <ul class="list-group list-group-flush">
                    <?php foreach (array_slice($payments, 0, 6) as $p): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center
                                   <?= (int) $p['is_cancelled'] === 1 ? 'opacity-50' : '' ?>">
                            <span>
                                <a href="<?= e(url('/finances/recu/' . (int) $p['id'])) ?>">
                                    <?= e($p['receipt_no']) ?>
                                </a>
                                <span class="small text-secondary">
                                    <?= e(date('d/m/Y', strtotime((string) $p['paid_on']))) ?>
                                </span>
                                <?php if ((int) $p['is_cancelled'] === 1): ?>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">annulé</span>
                                <?php endif; ?>
                            </span>
                            <span class="fw-medium">
                                <?= e(finance_amount((float) $p['credited_amount'], (string) $p['credited_currency'])) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
