<?php
/**
 * RÉSULTATS — par classe, pour un regroupement de périodes.
 *
 * CE TABLEAU DIT CE QU'IL NE SAIT PAS
 * ====================================
 * Un bulletin dont le conseil n'a pas encore statué porte
 * `decision = NULL`. Il n'est ni une réussite, ni un échec. La colonne
 * « Sans décision » existe pour cela, et le taux de réussite se calcule
 * sur les seuls bulletins décidés — il affiche un tiret quand il n'y en
 * a aucun.
 *
 *   > Un indicateur qui confond « pas encore décidé » et « refusé » ne
 *   > mesure pas la réussite, il mesure l'avancement du conseil.
 *
 * @var array  $annees
 * @var array  $annee
 * @var array  $periodes
 * @var string $periode
 * @var array  $lignes
 * @var array  $total
 */
declare(strict_types=1);

set_title('Résultats');

$libelle = static function (string $cle): string {
    if ($cle === 'ANNUAL') {
        return 'Total général';
    }

    $rang = (int) substr($cle, 1);

    return ($cle[0] === 'T' ? 'Trimestre ' : 'Semestre ') . $rang;
};

ob_start();
?>
    <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="f-periode">Période</label>
        <select class="form-select form-select-sm" id="f-periode" name="periode">
            <?php foreach ($periodes as $p): ?>
                <option value="<?= e($p) ?>" <?= $p === $periode ? 'selected' : '' ?>>
                    <?= e($libelle($p)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
<?php
$filtresSupplementaires = ob_get_clean();

$actif      = 'resultats';
$parametres = ['periode' => $periode];
require APP_PATH . '/views/reports/_nav.php';
?>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($periodes === []): ?>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            Aucun bulletin n'a encore été calculé pour l'année
            <?= e((string) $annee['code']) ?>.
        </div>
    </div>
<?php else: ?>
    <?php if ((int) $total['sans_decision'] > 0): ?>
        <div class="alert alert-info small no-print">
            <i class="bi bi-hourglass-split me-1"></i>
            <strong><?= (int) $total['sans_decision'] ?> bulletin(s)</strong> n'ont pas
            encore de décision du conseil. Ils ne sont comptés ni en réussite
            ni en échec, et le taux de réussite porte uniquement sur les
            <strong><?= (int) $total['decides'] ?></strong> bulletins décidés.
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Classe</th>
                        <th class="text-end">Bulletins</th>
                        <th class="text-end">Moyenne</th>
                        <th class="text-end d-none d-lg-table-cell">Min / Max</th>
                        <th class="text-end">Décidés</th>
                        <th class="text-end">Réussites</th>
                        <th class="text-end">Échecs</th>
                        <th class="text-end">Taux</th>
                        <th class="text-end d-none d-md-table-cell">Cotes manq.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lignes as $l): ?>
                        <?php $taux = reports_rate((int) $l['reussites'], (int) $l['decides']); ?>
                        <tr>
                            <td>
                                <strong><?= e((string) $l['name']) ?></strong>
                                <div class="small text-muted"><?= e((string) ($l['level_name'] ?? '')) ?></div>
                            </td>
                            <td class="text-end"><?= (int) $l['bulletins'] ?></td>
                            <td class="text-end fw-semibold">
                                <?= $l['moyenne'] === null ? '—'
                                    : e(number_format((float) $l['moyenne'], 1, ',', ' ') . ' %') ?>
                            </td>
                            <td class="text-end small text-muted d-none d-lg-table-cell">
                                <?= $l['mini'] === null ? '—'
                                    : e(number_format((float) $l['mini'], 0, ',', ' ') . ' / '
                                      . number_format((float) $l['maxi'], 0, ',', ' ')) ?>
                            </td>
                            <td class="text-end">
                                <?= (int) $l['decides'] ?>
                                <?php if ((int) $l['sans_decision'] > 0): ?>
                                    <span class="text-body-tertiary small">(+<?= (int) $l['sans_decision'] ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= (int) $l['reussites'] ?></td>
                            <td class="text-end"><?= (int) $l['echecs'] ?></td>
                            <td class="text-end"><?= e(reports_rate_display($taux)) ?></td>
                            <td class="text-end text-muted d-none d-md-table-cell">
                                <?= (int) $l['cotes_manquantes'] ?: '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-report-total">
                        <td>TOTAL</td>
                        <td class="text-end"><?= (int) $total['bulletins'] ?></td>
                        <td class="text-end">—</td>
                        <td class="text-end d-none d-lg-table-cell">—</td>
                        <td class="text-end"><?= (int) $total['decides'] ?></td>
                        <td class="text-end"><?= (int) $total['reussites'] ?></td>
                        <td class="text-end"><?= (int) $total['echecs'] ?></td>
                        <td class="text-end">
                            <?= e(reports_rate_display(reports_rate(
                                (int) $total['reussites'],
                                (int) $total['decides']
                            ))) ?>
                        </td>
                        <td class="text-end d-none d-md-table-cell"><?= (int) $total['cotes_manquantes'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="small text-muted mt-2">
        La moyenne générale n'est pas totalisée : la moyenne des moyennes de
        classe n'est pas la moyenne de l'école tant que les classes n'ont pas
        le même effectif. Elle se lit classe par classe.
    </p>
<?php endif; ?>
