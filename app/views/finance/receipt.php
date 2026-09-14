<?php
/**
 * Reçu imprimable.
 *
 * C'est le seul document que la famille emporte. Il doit donc porter,
 * sans que personne ait à interroger le système :
 *   · ce qui a été REMIS, dans la monnaie remise ;
 *   · le TAUX appliqué, s'il y en a eu un ;
 *   · ce qui a été CRÉDITÉ, et sur quelles dettes ;
 *   · ce qui reste dû après ce versement.
 *
 * Le taux figuré ici est celui qui a été figé : le reçu reste
 * vérifiable dans dix ans sans connaître le cours du jour.
 *
 * @var array      $payment
 * @var array      $student
 * @var array|null $classroom
 * @var array|null $year
 * @var array      $allocations
 * @var array      $school
 * @var array      $balance
 */
declare(strict_types=1);

set_title('Reçu ' . $payment['receipt_no']);

$cancelled = (int) $payment['is_cancelled'] === 1;
$allocated = 0.0;

foreach ($allocations as $a) {
    $allocated += (float) $a['amount'];
}

$unallocated = round((float) $payment['credited_amount'] - $allocated, 2);
?>

<div class="page-head d-print-none">
    <div>
        <h1 class="page-title">Reçu <?= e($payment['receipt_no']) ?></h1>
        <p class="page-subtitle">
            <?= e(date('d/m/Y', strtotime((string) $payment['paid_on']))) ?>
            <?php if ($payment['cashier_last_name'] !== null): ?>
                · encaissé par <?= e($payment['cashier_last_name'] . ' ' . $payment['cashier_first_name']) ?>
            <?php endif; ?>
        </p>
    </div>

    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary" data-print="1">
            <i class="bi bi-printer me-1"></i> Imprimer
        </button>
        <a href="<?= e(url('/finances/eleve/' . (int) $payment['enrollment_id'])) ?>"
           class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> La situation
        </a>
    </div>
</div>

<div class="d-print-none"><?php require APP_PATH . '/views/partials/flash.php'; ?></div>

<?php if ($cancelled): ?>
    <div class="alert alert-danger">
        <strong>Reçu annulé.</strong>
        <?= e((string) $payment['cancelled_reason']) ?>
        <div class="small mt-1">
            Le numéro reste consommé : un trou dans la séquence signalerait un incident,
            jamais une annulation régulière.
        </div>
    </div>
<?php endif; ?>

<!-- ============================== LE DOCUMENT ============================== -->
<div class="card">
    <div class="card-body" style="max-width:52rem">
        <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
            <div>
                <div class="fs-5 fw-semibold"><?= e($school['name']) ?></div>
                <div class="small text-secondary">
                    <?= e((string) ($school['code'] ?? '')) ?>
                    <?php if ($year !== null): ?> · année scolaire <?= e($year['code']) ?><?php endif; ?>
                </div>
            </div>
            <div class="text-end">
                <div class="fw-semibold">REÇU N° <?= e($payment['receipt_no']) ?></div>
                <div class="small"><?= e(date('d/m/Y', strtotime((string) $payment['paid_on']))) ?></div>
                <?php if ($cancelled): ?>
                    <div class="fw-bold text-danger">ANNULÉ</div>
                <?php endif; ?>
            </div>
        </div>

        <dl class="row mb-3">
            <dt class="col-4 col-sm-3 fw-normal text-secondary">Élève</dt>
            <dd class="col-8 col-sm-9 fw-medium">
                <?= e(full_name($student['last_name'], $student['post_name'], $student['first_name'])) ?>
                <span class="text-secondary">(<?= e((string) $student['matricule']) ?>)</span>
            </dd>

            <?php if ($classroom !== null): ?>
                <dt class="col-4 col-sm-3 fw-normal text-secondary">Classe</dt>
                <dd class="col-8 col-sm-9"><?= e($classroom['name']) ?></dd>
            <?php endif; ?>

            <?php if ($payment['payer_name'] !== null): ?>
                <dt class="col-4 col-sm-3 fw-normal text-secondary">Payeur</dt>
                <dd class="col-8 col-sm-9"><?= e((string) $payment['payer_name']) ?></dd>
            <?php endif; ?>

            <dt class="col-4 col-sm-3 fw-normal text-secondary">Mode</dt>
            <dd class="col-8 col-sm-9">
                <?= e(FINANCE_METHODS[$payment['method']] ?? (string) $payment['method']) ?>
                <?php if ($payment['reference'] !== null): ?>
                    · réf. <?= e((string) $payment['reference']) ?>
                <?php endif; ?>
            </dd>
        </dl>

        <!-- LES TROIS MONTANTS -->
        <table class="table table-sm mb-3">
            <tbody>
                <tr>
                    <th scope="row" class="fw-normal">Montant remis</th>
                    <td class="text-end fw-medium">
                        <?= e(finance_amount((float) $payment['tendered_amount'], (string) $payment['tendered_currency'])) ?>
                    </td>
                </tr>
                <?php if ($payment['exchange_rate'] !== null): ?>
                    <tr>
                        <th scope="row" class="fw-normal">Taux appliqué</th>
                        <td class="text-end">
                            <?= e(rtrim(rtrim(number_format((float) $payment['exchange_rate'], 6, ',', ' '), '0'), ',')) ?>
                            <?= e((string) $payment['tendered_currency']) ?>
                            pour 1 <?= e((string) $payment['credited_currency']) ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr class="border-top">
                    <th scope="row">Porté au crédit</th>
                    <td class="text-end fs-5 fw-semibold">
                        <?= e(finance_amount((float) $payment['credited_amount'], (string) $payment['credited_currency'])) ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php if ($allocations !== []): ?>
            <div class="fw-medium mb-1">Imputation</div>
            <table class="table table-sm">
                <tbody>
                    <?php foreach ($allocations as $a): ?>
                        <tr>
                            <td><?= e((string) $a['label']) ?></td>
                            <td class="text-end">
                                <?= e(finance_amount((float) $a['amount'], (string) $a['currency'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($unallocated > 0.005): ?>
                        <tr>
                            <td class="text-info-emphasis">Avance — aucune dette ouverte ne l'absorbe</td>
                            <td class="text-end text-info-emphasis">
                                <?= e(finance_amount($unallocated, (string) $payment['credited_currency'])) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($unallocated > 0.005): ?>
            <p class="text-info-emphasis">
                La totalité de ce versement reste en avance :
                <?= e(finance_amount($unallocated, (string) $payment['credited_currency'])) ?>.
            </p>
        <?php endif; ?>

        <!-- CE QUI RESTE DÛ APRÈS CE VERSEMENT -->
        <div class="border-top pt-3 mt-3">
            <div class="fw-medium mb-1">Situation après ce versement</div>
            <?php if ($balance === []): ?>
                <div class="text-secondary">Aucun frais affecté.</div>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-4">
                    <?php foreach ($balance as $currency => $b): ?>
                        <div>
                            <span class="text-secondary">Reste dû</span>
                            <span class="fw-semibold">
                                <?= e(finance_amount((float) $b['balance'], (string) $currency)) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="d-flex justify-content-between mt-5 pt-4">
            <div class="small text-secondary">
                Signature du caissier<br><br>
                ______________________
            </div>
            <div class="small text-secondary text-end">
                Signature du payeur<br><br>
                ______________________
            </div>
        </div>
    </div>
</div>

<!-- ========================= ACTIONS, HORS IMPRESSION ===================== -->
<?php if (!$cancelled && (can('payment.cancel') || can('payment.record'))): ?>
    <div class="row g-3 mt-1 d-print-none">
        <?php if (can('payment.record') && $allocations !== []): ?>
            <div class="col-12 col-lg-7">
                <div class="card">
                    <div class="card-header fw-medium">Corriger l'imputation</div>
                    <div class="card-body">
                        <form method="post"
                              action="<?= e(url('/finances/recu/' . (int) $payment['id'] . '/repartir')) ?>">
                            <?= csrf_field() ?>
                            <?php foreach ($allocations as $a): ?>
                                <div class="row g-2 align-items-center mb-2">
                                    <div class="col-7 small"><?= e((string) $a['label']) ?></div>
                                    <div class="col-5">
                                        <input type="text" inputmode="decimal"
                                               class="form-control form-control-sm text-end"
                                               name="allocation[<?= (int) $a['student_fee_id'] ?>]"
                                               value="<?= e((string) $a['amount']) ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-sm btn-outline-primary mt-2">
                                Réimputer
                            </button>
                            <p class="small text-secondary mb-0 mt-2">
                                Le montant encaissé ne change pas : seule sa répartition sur les
                                dettes est refaite. Aucune ligne ne peut recevoir plus que son reste dû.
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (can('payment.cancel')): ?>
            <div class="col-12 col-lg-5">
                <div class="card border-danger-subtle">
                    <div class="card-header fw-medium">Annuler ce reçu</div>
                    <div class="card-body">
                        <form method="post"
                              action="<?= e(url('/finances/recu/' . (int) $payment['id'] . '/annuler')) ?>">
                            <?= csrf_field() ?>
                            <label class="form-label small" for="cancel-motif">Motif</label>
                            <input type="text" class="form-control form-control-sm mb-2" id="cancel-motif"
                                   name="motif" required minlength="5" maxlength="160"
                                   placeholder="Erreur de guichet, chèque impayé…">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Annuler le reçu</button>
                            <p class="small text-secondary mb-0 mt-2">
                                Le reçu n'est pas supprimé et son numéro reste consommé. Les sommes
                                cessent de compter dans les soldes.
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
