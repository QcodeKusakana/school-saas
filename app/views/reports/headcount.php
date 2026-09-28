<?php
/**
 * EFFECTIFS — l'état que la tutelle réclame chaque rentrée.
 *
 * L'INVARIANT EST AFFICHÉ, PAS SEULEMENT CALCULÉ : garçons + filles =
 * effectif, sur chaque ligne comme sur le total. C'est la première chose
 * qu'un inspecteur vérifie, et un tableau qui ne s'additionne pas est un
 * tableau qu'on ne signe pas.
 *
 * @var array $annees
 * @var array $annee
 * @var array $lignes
 * @var array $total
 */
declare(strict_types=1);

set_title('Effectifs');

$actif = 'effectifs';
require APP_PATH . '/views/reports/_nav.php';

$cycleCourant = null;
?>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($lignes === []): ?>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            Aucune classe n'est ouverte pour l'année <?= e((string) $annee['code']) ?>.
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Classe</th>
                        <th class="d-none d-md-table-cell">Niveau</th>
                        <th class="text-end">Garçons</th>
                        <th class="text-end">Filles</th>
                        <th class="text-end">Effectif</th>
                        <th class="text-end">Redoublants</th>
                        <th class="text-end">Partis</th>
                        <th class="text-end">Capacité</th>
                        <th class="text-end">Remplissage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lignes as $l): ?>
                        <?php if ($l['cycle_name'] !== null && $l['cycle_name'] !== $cycleCourant): ?>
                            <?php $cycleCourant = $l['cycle_name']; ?>
                            <tr class="table-light">
                                <td colspan="9" class="fw-semibold small text-uppercase"
                                    style="letter-spacing:.05em;"><?= e((string) $cycleCourant) ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php $taux = reports_rate((int) $l['effectif'], (int) ($l['capacity'] ?? 0)); ?>
                        <tr>
                            <td><strong><?= e((string) $l['name']) ?></strong></td>
                            <td class="small text-muted d-none d-md-table-cell">
                                <?= e((string) ($l['level_name'] ?? '—')) ?>
                            </td>
                            <td class="text-end"><?= (int) $l['garcons'] ?></td>
                            <td class="text-end"><?= (int) $l['filles'] ?></td>
                            <td class="text-end fw-semibold"><?= (int) $l['effectif'] ?></td>
                            <td class="text-end"><?= (int) $l['redoublants'] ?: '—' ?></td>
                            <td class="text-end text-muted"><?= (int) $l['partis'] ?: '—' ?></td>
                            <td class="text-end text-muted"><?= (int) ($l['capacity'] ?? 0) ?: '—' ?></td>
                            <td class="text-end">
                                <?php if ($taux === null): ?>
                                    <span class="text-body-tertiary">—</span>
                                <?php else: ?>
                                    <span class="<?= $taux > 100 ? 'text-danger fw-semibold' : '' ?>">
                                        <?= e(reports_rate_display($taux)) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-report-total">
                        <td colspan="2">TOTAL — <?= count($lignes) ?> classe(s)</td>
                        <td class="text-end"><?= (int) $total['garcons'] ?></td>
                        <td class="text-end"><?= (int) $total['filles'] ?></td>
                        <td class="text-end"><?= (int) $total['effectif'] ?></td>
                        <td class="text-end"><?= (int) $total['redoublants'] ?></td>
                        <td class="text-end"><?= (int) $total['partis'] ?></td>
                        <td class="text-end"><?= (int) $total['capacity'] ?></td>
                        <td class="text-end">
                            <?= e(reports_rate_display(reports_rate(
                                (int) $total['effectif'],
                                (int) $total['capacity']
                            ))) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="small text-muted mt-2">
        L'effectif exclut les inscriptions annulées, comptées à part sous
        « Partis ». Un remplissage supérieur à 100 % signale une classe
        au-delà de sa capacité déclarée.
    </p>
<?php endif; ?>
