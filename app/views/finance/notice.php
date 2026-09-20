<?php
/**
 * Avis de situation financière, remis à la famille.
 *
 * Ce document quitte l'école : il doit se suffire à lui-même et ne rien
 * affirmer qu'on ne puisse vérifier. Il dit donc, ligne par ligne, ce
 * qui a été réclamé, ce qui a été reçu, ce qui reste — et il distingue
 * ce qui est ÉCHU de ce qui ne l'est pas encore. Confondre les deux
 * ferait passer pour un mauvais payeur un parent parfaitement à jour.
 *
 * @var array      $enrollment
 * @var array      $student
 * @var array|null $classroom
 * @var array|null $year
 * @var array      $lines
 * @var array      $balance
 * @var array      $school
 * @var string     $today
 */
declare(strict_types=1);

set_title('Avis de situation');

$open = array_values(array_filter(
    $lines,
    static fn (array $l): bool => (float) $l['amount_net'] - (float) $l['paid'] > 0.005
));

$overdue = [];

foreach ($open as $line) {
    if ($line['due_on'] !== null && (string) $line['due_on'] < $today) {
        $currency = (string) $line['currency'];
        $overdue[$currency] = ($overdue[$currency] ?? 0.0)
            + ((float) $line['amount_net'] - (float) $line['paid']);
    }
}
?>

<div class="page-head d-print-none">
    <div>
        <h1 class="page-title">Avis de situation</h1>
        <p class="page-subtitle">
            <?= e(full_name($student['last_name'], $student['post_name'], $student['first_name'])) ?>
            · <?= e(date('d/m/Y', strtotime($today))) ?>
        </p>
    </div>

    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary" data-print>
            <i class="bi bi-printer me-1"></i> Imprimer
        </button>
        <a href="<?= e(url('/finances/eleve/' . (int) $enrollment['id'])) ?>"
           class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> La situation
        </a>
    </div>
</div>

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
                <div class="fw-semibold">AVIS DE SITUATION</div>
                <div class="small">Établi le <?= e(date('d/m/Y', strtotime($today))) ?></div>
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
        </dl>

        <?php if ($open === []): ?>
            <div class="alert alert-success">
                <strong>Situation soldée.</strong>
                Tous les frais affectés à cet élève ont été réglés à ce jour.
            </div>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th scope="col">Frais</th>
                        <th scope="col">Échéance</th>
                        <th scope="col" class="text-end">Réclamé</th>
                        <th scope="col" class="text-end">Reçu</th>
                        <th scope="col" class="text-end">Reste</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($open as $line): ?>
                        <?php
                        $currency = (string) $line['currency'];
                        $rest     = round((float) $line['amount_net'] - (float) $line['paid'], 2);
                        $late     = $line['due_on'] !== null && (string) $line['due_on'] < $today;
                        ?>
                        <tr>
                            <td>
                                <?= e($line['label']) ?>
                                <?php if ((float) $line['discount_amount'] > 0): ?>
                                    <div class="small text-secondary">
                                        après remise de
                                        <?= e(finance_amount((float) $line['discount_amount'], $currency)) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($line['due_on'] === null): ?>
                                    <span class="text-secondary">—</span>
                                <?php elseif ($late): ?>
                                    <span class="text-danger-emphasis fw-medium">
                                        <?= e(date('d/m/Y', strtotime((string) $line['due_on']))) ?> — échue
                                    </span>
                                <?php else: ?>
                                    <?= e(date('d/m/Y', strtotime((string) $line['due_on']))) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?= e(finance_amount((float) $line['amount_net'], $currency)) ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?= e(finance_amount((float) $line['paid'], $currency)) ?>
                            </td>
                            <td class="text-end text-nowrap fw-medium">
                                <?= e(finance_amount($rest, $currency)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="border-top pt-3 mt-3">
            <?php foreach ($balance as $currency => $b): ?>
                <?php if ((float) $b['balance'] <= 0.005 && (float) $b['advance'] <= 0.005): ?>
                    <?php continue; ?>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-baseline">
                    <span>Reste dû</span>
                    <span class="fs-5 fw-semibold">
                        <?= e(finance_amount((float) $b['balance'], (string) $currency)) ?>
                    </span>
                </div>
                <?php if (isset($overdue[$currency])): ?>
                    <div class="d-flex justify-content-between small text-danger-emphasis">
                        <span>dont échu à ce jour</span>
                        <span><?= e(finance_amount((float) $overdue[$currency], (string) $currency)) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ((float) $b['advance'] > 0.005): ?>
                    <div class="d-flex justify-content-between small text-info-emphasis">
                        <span>versement déjà reçu, non encore imputé</span>
                        <span><?= e(finance_amount((float) $b['advance'], (string) $currency)) ?></span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <p class="small text-secondary mt-2 mb-0">
                Les montants sont présentés dans leur monnaie d'origine et ne
                s'additionnent pas entre eux. Un règlement peut être remis dans une
                autre monnaie : le taux appliqué figurera sur le reçu.
            </p>
        </div>

        <div class="d-flex justify-content-between mt-5 pt-4">
            <div class="small text-secondary">
                Le service financier<br><br>
                ______________________
            </div>
            <div class="small text-secondary text-end">
                Reçu par le parent / tuteur<br><br>
                ______________________
            </div>
        </div>
    </div>
</div>
