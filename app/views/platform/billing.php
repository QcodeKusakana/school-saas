<?php
/**
 * CONSOLE — la facturation d'une école.
 *
 * Ce que l'écran doit rendre évident : ce qui est dû, ce qui a été
 * versé, et la différence. Dans la bonne devise, et sans jamais mêler
 * des dollars à des francs.
 *
 * @var array  $school
 * @var array  $ledger
 * @var array  $payments
 * @var array  $balance
 * @var int    $unpriced
 * @var ?array $visiting
 */
declare(strict_types=1);

set_title('Facturation — ' . $school['name']);

$statusLabels = [
    'pending'   => 'En attente',
    'confirmed' => 'Confirmé',
    'failed'    => 'Échoué',
    'refunded'  => 'Remboursé',
];

$methodLabels = [
    'mobile_money'  => 'Mobile Money',
    'bank_transfer' => 'Virement',
    'cash'          => 'Espèces',
    'card'          => 'Carte',
    'other'         => 'Autre',
];

// Les abonnements sur lesquels on peut encaisser : ceux qui ont un
// tarif figé. Les autres sont listés mais non encaissables.
$payable = array_values(array_filter(
    $ledger,
    static fn (array $r): bool => $r['price_amount'] !== null
));
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Facturation</h1>
        <p class="page-subtitle"><?= e((string) $school['name']) ?> · <?= e((string) $school['code']) ?></p>
    </div>
    <div>
        <a class="btn btn-outline-secondary"
           href="<?= e(url('/plateforme/ecoles/' . (int) $school['id'])) ?>">
            Fiche de l'établissement
        </a>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($unpriced > 0): ?>
    <?php
    /*
     * ON NE DEVINE PAS UNE DETTE.
     * Un abonnement antérieur à la migration 028 n'a pas de tarif figé.
     * L'écran le DIT plutôt que de compter zéro — un zéro silencieux
     * ferait croire que l'école est à jour.
     */
    ?>
    <div class="alert alert-warning">
        <strong><?= (int) $unpriced ?> abonnement<?= $unpriced > 1 ? 's' : '' ?>
        sans tarif figé</strong> — antérieur<?= $unpriced > 1 ? 's' : '' ?> à la mise en
        place de la facturation. <?= $unpriced > 1 ? 'Ils ne sont' : 'Il n\'est' ?> pas
        compté<?= $unpriced > 1 ? 's' : '' ?> dans le solde, et on ne peut pas encaisser
        dessus. Appliquez une offre depuis la fiche pour repartir sur une base sûre.
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <!-- ==================== LE SOLDE, PAR DEVISE ==================== -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-medium">Solde</div>
            <div class="card-body">
                <?php if ($balance === []): ?>
                    <p class="text-secondary mb-0">Rien de facturé pour l'instant.</p>
                <?php else: ?>
                    <?php foreach ($balance as $currency => $line): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small">
                                <span class="text-secondary">Dû</span>
                                <span><?= e(billing_format($line['due'], $currency)) ?></span>
                            </div>
                            <div class="d-flex justify-content-between small">
                                <span class="text-secondary">Versé</span>
                                <span><?= e(billing_format($line['paid'], $currency)) ?></span>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between">
                                <span class="fw-medium">
                                    <?= $line['balance'] > 0 ? 'Reste dû' : ($line['balance'] < 0 ? 'Trop-perçu' : 'Soldé') ?>
                                </span>
                                <span class="fw-medium <?= $line['balance'] > 0
                                    ? 'text-danger-emphasis'
                                    : ($line['balance'] < 0 ? 'text-warning-emphasis' : 'text-success-emphasis') ?>">
                                    <?= e(billing_format(abs($line['balance']), $currency)) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (count($balance) > 1): ?>
                        <p class="small text-secondary mb-0">
                            Aucun total toutes devises : additionner des dollars et des
                            francs produit un nombre qui ne veut rien dire.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==================== ENREGISTRER UN VERSEMENT ==================== -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header fw-medium">Enregistrer un versement</div>
            <div class="card-body">
                <?php if ($payable === []): ?>
                    <p class="text-secondary mb-0">
                        Aucun abonnement à tarif figé : appliquez d'abord une offre
                        depuis la fiche de l'établissement.
                    </p>
                <?php else: ?>
                    <form method="post"
                          action="<?= e(url('/plateforme/ecoles/' . (int) $school['id'] . '/versement')) ?>"
                          class="row g-2">
                        <?= csrf_field() ?>

                        <div class="col-12">
                            <label class="form-label small" for="subscription_id">Période facturée</label>
                            <select class="form-select" id="subscription_id" name="subscription_id" required>
                                <?php foreach ($payable as $row): ?>
                                    <option value="<?= (int) $row['id'] ?>">
                                        <?= e((string) $row['plan_name']) ?>
                                        — <?= e(date('d/m/Y', strtotime((string) $row['starts_on']))) ?>
                                        au <?= e(date('d/m/Y', strtotime((string) $row['ends_on']))) ?>
                                        · dû <?= e(billing_format((float) $row['due'], (string) $row['price_currency'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="tendered_currency">Monnaie remise</label>
                            <select class="form-select" id="tendered_currency" name="tendered_currency">
                                <option value="USD">USD</option>
                                <option value="CDF">CDF</option>
                            </select>
                        </div>

                        <div class="col-6 col-md-4">
                            <label class="form-label small" for="tendered_amount">Somme remise</label>
                            <input type="number" step="0.01" min="0.01" class="form-control"
                                   id="tendered_amount" name="tendered_amount" required>
                        </div>

                        <div class="col-12 col-md-5">
                            <label class="form-label small" for="exchange_rate">Taux de change</label>
                            <input type="number" step="0.000001" min="0" class="form-control"
                                   id="exchange_rate" name="exchange_rate" placeholder="—">
                            <div class="form-text small">
                                Obligatoire si la monnaie remise diffère de celle de la dette.
                                Il est <strong>figé</strong> sur le versement.
                            </div>
                        </div>

                        <div class="col-6 col-md-4">
                            <label class="form-label small" for="method">Moyen</label>
                            <select class="form-select" id="method" name="method">
                                <?php foreach ($methodLabels as $code => $label): ?>
                                    <option value="<?= e($code) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-4">
                            <label class="form-label small" for="provider">Prestataire</label>
                            <input type="text" class="form-control" id="provider" name="provider"
                                   placeholder="M-Pesa, Airtel…">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label small" for="reference">Référence</label>
                            <input type="text" class="form-control" id="reference" name="reference"
                                   placeholder="Numéro de transaction">
                            <div class="form-text small">Unique par prestataire.</div>
                        </div>

                        <div class="col-6 col-md-5">
                            <label class="form-label small" for="paid_at">Date du versement</label>
                            <input type="datetime-local" class="form-control" id="paid_at" name="paid_at"
                                   value="<?= e(date('Y-m-d\TH:i')) ?>">
                        </div>

                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="status">État</label>
                            <select class="form-select" id="status" name="status">
                                <option value="confirmed">Confirmé</option>
                                <option value="pending">En attente</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small" for="notes">Note</label>
                            <input type="text" class="form-control" id="notes" name="notes">
                        </div>

                        <div class="col-12 mt-3">
                            <button class="btn btn-primary" type="submit">Enregistrer le versement</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ==================== LE RELEVÉ, PÉRIODE PAR PÉRIODE ==================== -->
<div class="card mb-3">
    <div class="card-header fw-medium">Périodes facturées</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Offre</th>
                    <th scope="col">Période</th>
                    <th scope="col" class="text-end">Tarif</th>
                    <th scope="col" class="text-end">Dû</th>
                    <th scope="col" class="text-end">Versé</th>
                    <th scope="col" class="text-end">Reste</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ledger as $row): ?>
                    <?php
                    $cur  = (string) ($row['price_currency'] ?? '');
                    $due  = $row['price_amount'] === null ? null : (float) $row['due'];
                    $paid = (float) $row['paid'];
                    ?>
                    <tr>
                        <td>
                            <?= e((string) $row['plan_name']) ?>
                            <?php if ($row['cancelled_at'] !== null): ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">clôturée</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?= e(date('d/m/Y', strtotime((string) $row['starts_on']))) ?>
                            → <?= e(date('d/m/Y', strtotime((string) $row['ends_on']))) ?>
                        </td>
                        <td class="text-end small">
                            <?= $row['price_amount'] === null
                                ? '<span class="text-warning-emphasis">non figé</span>'
                                : e(billing_format((float) $row['price_amount'], $cur)) ?>
                        </td>
                        <td class="text-end small">
                            <?php if ($due === null): ?>
                                —
                            <?php else: ?>
                                <?= e(billing_format($due, $cur)) ?>
                                <?php if ($row['cancelled_at'] !== null && $due < (float) $row['price_amount']): ?>
                                    <!-- Prorata : la période n'a pas été servie en entier. -->
                                    <span class="text-secondary" title="au prorata des jours servis">⌁</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small"><?= $cur !== '' ? e(billing_format($paid, $cur)) : '—' ?></td>
                        <td class="text-end small fw-medium">
                            <?= $due === null ? '—' : e(billing_format($due - $paid, $cur)) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($ledger === []): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">
                        Aucun abonnement enregistré.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==================== LES VERSEMENTS ==================== -->
<div class="card">
    <div class="card-header fw-medium">Versements</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col" class="text-end">Remis</th>
                    <th scope="col" class="text-end d-none d-md-table-cell">Taux</th>
                    <th scope="col" class="text-end">Crédité</th>
                    <th scope="col" class="d-none d-md-table-cell">Moyen</th>
                    <th scope="col">État</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $pay): ?>
                    <?php $cancelled = $pay['cancelled_at'] !== null; ?>
                    <tr class="<?= $cancelled ? 'text-secondary' : '' ?>">
                        <td class="small">
                            <?= e(date('d/m/Y H:i', strtotime((string) $pay['paid_at']))) ?>
                            <?php if ($pay['reference'] !== null): ?>
                                <div class="text-secondary"><?= e((string) $pay['reference']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small <?= $cancelled ? 'text-decoration-line-through' : '' ?>">
                            <?= $pay['tendered_amount'] !== null
                                ? e(billing_format((float) $pay['tendered_amount'], (string) $pay['tendered_currency']))
                                : '—' ?>
                        </td>
                        <td class="text-end small d-none d-md-table-cell">
                            <?= $pay['exchange_rate'] !== null
                                ? e(rtrim(rtrim(number_format((float) $pay['exchange_rate'], 6, ',', ' '), '0'), ','))
                                : '—' ?>
                        </td>
                        <td class="text-end small <?= $cancelled ? 'text-decoration-line-through' : '' ?>">
                            <?= e(billing_format((float) $pay['amount'], (string) $pay['currency'])) ?>
                        </td>
                        <td class="small d-none d-md-table-cell">
                            <?= e($methodLabels[$pay['method']] ?? (string) $pay['method']) ?>
                            <?php if ($pay['provider'] !== null): ?>
                                <div class="text-secondary"><?= e((string) $pay['provider']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($cancelled): ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Annulé</span>
                                <div class="small text-secondary"><?= e((string) $pay['cancelled_reason']) ?></div>
                            <?php else: ?>
                                <span class="badge <?= $pay['status'] === 'confirmed'
                                    ? 'bg-success-subtle text-success-emphasis'
                                    : 'bg-warning-subtle text-warning-emphasis' ?>">
                                    <?= e($statusLabels[$pay['status']] ?? (string) $pay['status']) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (!$cancelled): ?>
                                <?php if ($pay['status'] === 'pending'): ?>
                                    <form method="post" class="d-inline"
                                          action="<?= e(url('/plateforme/ecoles/' . (int) $school['id']
                                              . '/versement/' . (int) $pay['id'] . '/confirmer')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-success" type="submit">Confirmer</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" class="d-inline"
                                      action="<?= e(url('/plateforme/ecoles/' . (int) $school['id']
                                          . '/versement/' . (int) $pay['id'] . '/annuler')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="text" name="reason" class="form-control form-control-sm d-inline-block"
                                           style="width:11rem" placeholder="Motif" required>
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Annuler</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($payments === []): ?>
                    <tr><td colspan="7" class="text-center text-secondary py-4">
                        Aucun versement enregistré.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body">
        <p class="small text-secondary mb-0">
            Un versement annulé <strong>reste au journal</strong>, avec son motif et son
            auteur. Un trou dans une comptabilité signale un détournement ; une
            annulation motivée raconte ce qui s'est passé.
        </p>
    </div>
</div>
