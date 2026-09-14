<?php
/**
 * Situation financière d'un élève.
 *
 * Les totaux sont affichés PAR DEVISE et ne sont jamais additionnés :
 * 50 USD et 45 000 CDF ne font pas 45 050. C'est la règle centrale de
 * tout le module.
 *
 * @var array      $enrollment
 * @var array      $student
 * @var array|null $classroom
 * @var array|null $year
 * @var array      $lines
 * @var array      $due
 */
declare(strict_types=1);

set_title('Situation financière');

$manage = can('fee.manage');
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

    <div class="d-flex gap-2">
        <?php if (can('payment.record') && route_exists('/finances/eleve/{id}/encaisser')): ?>
            <a href="<?= e(url('/finances/eleve/' . (int) $enrollment['id'] . '/encaisser')) ?>"
               class="btn btn-primary">
                <i class="bi bi-cash-coin me-1"></i> Encaisser
            </a>
        <?php endif; ?>
        <?php if ($classroom !== null): ?>
            <a href="<?= e(url('/finances/classe/' . (int) $classroom['id'])) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> La classe
            </a>
        <?php endif; ?>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($due === []): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle"></i>
        <div>
            <strong>Aucun frais affecté.</strong>
            Cet élève ne doit rien parce que rien ne lui a été facturé —
            ce n'est pas la même chose qu'être en règle.
        </div>
    </div>
<?php else: ?>
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-4">
            <?php foreach ($due as $currency => $total): ?>
                <div>
                    <div class="small text-secondary">Total dû</div>
                    <div class="fs-4 fw-semibold"><?= e(finance_amount((float) $total, (string) $currency)) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="ms-auto small text-secondary align-self-end" style="max-width:22rem">
                Les devises ne sont jamais additionnées : une dette est due,
                et se solde, dans sa propre monnaie.
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($lines !== []): ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Frais</th>
                        <th scope="col" class="d-none d-md-table-cell">Échéance</th>
                        <th scope="col" class="text-end">Tarif</th>
                        <th scope="col" class="text-end">Remise</th>
                        <th scope="col" class="text-end">À payer</th>
                        <?php if ($manage): ?><th scope="col"></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line): ?>
                        <?php $cancelled = (int) $line['is_cancelled'] === 1; ?>
                        <tr<?= $cancelled ? ' class="opacity-50"' : '' ?>>
                            <td>
                                <span class="fw-medium"><?= e($line['label']) ?></span>
                                <?php if ($cancelled): ?>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">annulée</span>
                                <?php endif; ?>
                                <?php if ($line['discount_reason'] !== null): ?>
                                    <div class="small text-info-emphasis">
                                        Remise : <?= e((string) $line['discount_reason']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($cancelled && $line['cancelled_reason'] !== null): ?>
                                    <div class="small text-secondary">
                                        Motif : <?= e((string) $line['cancelled_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell small">
                                <?= $line['due_on'] !== null
                                    ? e(date('d/m/Y', strtotime((string) $line['due_on'])))
                                    : '<span class="text-secondary">—</span>' ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?= e(finance_amount((float) $line['amount_due'], (string) $line['currency'])) ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?= (float) $line['discount_amount'] > 0
                                    ? '− ' . e(finance_amount((float) $line['discount_amount'], (string) $line['currency']))
                                    : '<span class="text-secondary">—</span>' ?>
                            </td>
                            <td class="text-end fw-medium text-nowrap">
                                <?= $cancelled
                                    ? '<span class="text-secondary">—</span>'
                                    : e(finance_amount((float) $line['amount_net'], (string) $line['currency'])) ?>
                            </td>

                            <?php if ($manage): ?>
                                <td class="text-end text-nowrap">
                                    <?php if (!$cancelled): ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#action-<?= (int) $line['id'] ?>">
                                            Remise / annuler
                                        </button>
                                    <?php else: ?>
                                        <!-- Une annulation faite d'un clic de trop doit pouvoir être
                                             reprise : sans cela, la somme due est perdue sans recours,
                                             car la réaffectation ne recrée jamais une dette annulée. -->
                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#restore-<?= (int) $line['id'] ?>">
                                            Rétablir
                                        </button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>

                        <?php if ($manage && $cancelled): ?>
                            <tr class="collapse" id="restore-<?= (int) $line['id'] ?>">
                                <td colspan="6" class="bg-body-tertiary">
                                    <form method="post"
                                          action="<?= e(url('/finances/ligne/' . (int) $line['id'] . '/retablir')) ?>"
                                          class="d-flex flex-wrap gap-2 align-items-end">
                                        <?= csrf_field() ?>
                                        <div class="flex-grow-1">
                                            <label class="form-label small mb-1">
                                                Rétablir cette dette — motif
                                            </label>
                                            <input type="text" name="motif" maxlength="160" required minlength="5"
                                                   class="form-control form-control-sm"
                                                   placeholder="Annulation faite par erreur, élève finalement présent…">
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary">Rétablir</button>
                                    </form>
                                    <p class="small text-secondary mb-0 mt-1">
                                        Le montant rétabli est celui qui avait été gelé, pas le tarif du jour :
                                        rétablir n'est pas refacturer.
                                    </p>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php if ($manage && !$cancelled): ?>
                            <tr class="collapse" id="action-<?= (int) $line['id'] ?>">
                                <td colspan="6" class="bg-body-tertiary">
                                    <div class="row g-3">
                                        <div class="col-12 col-lg-7">
                                            <form method="post"
                                                  action="<?= e(url('/finances/ligne/' . (int) $line['id'] . '/remise')) ?>"
                                                  class="d-flex flex-wrap gap-2 align-items-end">
                                                <?= csrf_field() ?>
                                                <div>
                                                    <label class="form-label small mb-1">Remise</label>
                                                    <input type="text" name="remise" inputmode="decimal"
                                                           class="form-control form-control-sm" style="width:8rem"
                                                           value="<?= e((string) $line['discount_amount']) ?>">
                                                </div>
                                                <div class="flex-grow-1">
                                                    <label class="form-label small mb-1">
                                                        Motif <span class="text-secondary">(obligatoire si remise)</span>
                                                    </label>
                                                    <input type="text" name="motif" maxlength="160"
                                                           class="form-control form-control-sm"
                                                           value="<?= e((string) ($line['discount_reason'] ?? '')) ?>"
                                                           placeholder="Enfant du personnel, fratrie, bourse…">
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-primary">Appliquer</button>
                                            </form>
                                        </div>

                                        <div class="col-12 col-lg-5">
                                            <form method="post"
                                                  action="<?= e(url('/finances/ligne/' . (int) $line['id'] . '/annuler')) ?>"
                                                  class="d-flex flex-wrap gap-2 align-items-end">
                                                <?= csrf_field() ?>
                                                <div class="flex-grow-1">
                                                    <label class="form-label small mb-1">Annuler cette dette — motif</label>
                                                    <input type="text" name="motif" maxlength="160" required minlength="5"
                                                           class="form-control form-control-sm"
                                                           placeholder="Départ de l'élève, double facturation…">
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Annuler</button>
                                            </form>
                                            <p class="small text-secondary mb-0 mt-1">
                                                La dette n'est pas supprimée : elle reste visible, barrée, avec son motif.
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
