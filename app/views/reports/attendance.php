<?php
/**
 * ASSIDUITÉ — par classe, sur une plage de dates.
 *
 * POURQUOI DES DATES ET NON DES PÉRIODES
 * =======================================
 * `grade_periods.starts_on` et `ends_on` ne sont jamais remplis — dette
 * connue, inscrite dans l'état du projet. Proposer un découpage par
 * période donnerait un écran qui ne peut rien calculer.
 * `attendance_sessions.session_date` est, elle, une donnée réelle.
 *
 * @var array       $annees
 * @var array       $annee
 * @var string|null $du
 * @var string|null $au
 * @var array       $plage
 * @var array       $lignes
 * @var array       $total
 */
declare(strict_types=1);

set_title('Assiduité');

ob_start();
?>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="f-du">Du</label>
        <input type="date" class="form-control form-control-sm" id="f-du"
               name="du" value="<?= e((string) $du) ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="f-au">Au</label>
        <input type="date" class="form-control form-control-sm" id="f-au"
               name="au" value="<?= e((string) $au) ?>">
    </div>
<?php
$filtresSupplementaires = ob_get_clean();

$actif      = 'assiduite';
$parametres = array_filter(['du' => (string) $du, 'au' => (string) $au]);
require APP_PATH . '/views/reports/_nav.php';
?>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($plage['first'] === null): ?>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-calendar-x d-block mb-2" style="font-size:1.6rem;"></i>
            Aucune séance de pointage n'a été enregistrée pour l'année
            <?= e((string) $annee['code']) ?>.
            <div class="small mt-2">
                L'assiduité se calcule à partir des présences saisies dans
                <a href="<?= e(url('/presences')) ?>">Présences</a>.
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Classe</th>
                        <th class="text-end">Séances</th>
                        <th class="text-end">Pointages</th>
                        <th class="text-end">Présences</th>
                        <th class="text-end">Retards</th>
                        <th class="text-end">Absences</th>
                        <th class="text-end d-none d-md-table-cell">dont justifiées</th>
                        <th class="text-end">Taux de présence</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lignes as $l): ?>
                        <?php
                        // Un retard reste une présence : l'élève est là.
                        // Le compter en absence gonflerait l'absentéisme
                        // d'un établissement ponctuel mais mal desservi.
                        $taux = reports_rate(
                            (int) $l['presences'] + (int) $l['retards'],
                            (int) $l['pointages']
                        );
                        ?>
                        <tr>
                            <td>
                                <strong><?= e((string) $l['name']) ?></strong>
                                <div class="small text-muted"><?= e((string) ($l['level_name'] ?? '')) ?></div>
                            </td>
                            <td class="text-end"><?= (int) $l['seances'] ?></td>
                            <td class="text-end text-muted"><?= (int) $l['pointages'] ?></td>
                            <td class="text-end"><?= (int) $l['presences'] ?></td>
                            <td class="text-end"><?= (int) $l['retards'] ?: '—' ?></td>
                            <td class="text-end"><?= (int) $l['absences'] ?: '—' ?></td>
                            <td class="text-end text-muted d-none d-md-table-cell">
                                <?= (int) $l['justifiees'] ?: '—' ?>
                            </td>
                            <td class="text-end fw-semibold">
                                <?php if ($taux === null): ?>
                                    <span class="text-body-tertiary">—</span>
                                <?php else: ?>
                                    <span class="<?= $taux < 80 ? 'text-danger' : '' ?>">
                                        <?= e(reports_rate_display($taux)) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-report-total">
                        <td>TOTAL</td>
                        <td class="text-end"><?= (int) $total['seances'] ?></td>
                        <td class="text-end"><?= (int) $total['pointages'] ?></td>
                        <td class="text-end"><?= (int) $total['presences'] ?></td>
                        <td class="text-end"><?= (int) $total['retards'] ?></td>
                        <td class="text-end"><?= (int) $total['absences'] ?></td>
                        <td class="text-end d-none d-md-table-cell"><?= (int) $total['justifiees'] ?></td>
                        <td class="text-end">
                            <?= e(reports_rate_display(reports_rate(
                                (int) $total['presences'] + (int) $total['retards'],
                                (int) $total['pointages']
                            ))) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="small text-muted mt-2">
        Un retard compte comme une présence : l'élève est là. Le taux
        rapporte les présences et retards au nombre de pointages
        réellement effectués, non au nombre d'élèves — une séance non
        pointée n'invente pas d'absents.
    </p>
<?php endif; ?>
