<?php
/**
 * Gabarit « maxima » — bulletin sans domaines.
 *
 * Mise en page des HUMANITÉS TECHNIQUES : construction, mécanique
 * générale, secrétariat et administration. Les branches n'y sont pas
 * rangées par domaine mais regroupées par MAXIMUM, chaque bloc ouvert par
 * une ligne MAXIMA qui donne le maximum d'une période, de l'examen, du
 * regroupement et de l'année.
 *
 * Le nombre de regroupements — deux semestres ou trois trimestres — est
 * déduit des périodes de la classe, pas écrit ici.
 *
 * @var array $report   subjects, periods, totals, blocks, domains
 * @var array $subjects
 * @var array $periods
 * @var array $totals
 * @var array $groups
 * @var array $closes   dernière période de chaque regroupement
 * @var callable $cell
 */
declare(strict_types=1);
?>

    <!-- --------------------------------------------------------------
         Tableau des branches
    --------------------------------------------------------------- -->
    <div class="table-responsive avoid-break">
        <table class="table table-sm table-bordered align-middle mb-3" style="font-size:.8rem">
            <thead class="table-light">
                <tr>
                    <th scope="col" rowspan="2" style="min-width:9rem">Branche</th>
                    <?php foreach ($periods as $p): ?>
                        <th scope="col" class="text-center" title="<?= e($p['name']) ?>">
                            <?= e($p['code']) ?>
                        </th>
                        <?php if (isset($closes[$p['code']])): ?>
                            <th scope="col" class="text-center table-secondary"
                                title="<?= e($groups[$closes[$p['code']]]['label']) ?>">
                                <?= e($closes[$p['code']]) ?>
                            </th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <th scope="col" class="text-center table-secondary">TOTAL</th>
                </tr>
                <tr>
                    <?php foreach ($periods as $p): ?>
                        <th scope="col" class="text-center fw-normal text-secondary small">
                            ×<?= e(grades_format($p['multiplier'])) ?>
                        </th>
                        <?php if (isset($closes[$p['code']])): ?>
                            <th scope="col" class="table-secondary"></th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <th scope="col" class="table-secondary"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['blocks'] as $block): ?>
                    <!-- Ligne MAXIMA ouvrant le bloc, comme sur le document
                         officiel : maximum d'une période, de l'examen, du
                         semestre et de l'année pour toutes les branches qui
                         suivent. -->
                    <tr class="fw-bold table-light">
                        <td>MAXIMA</td>
                        <?php foreach ($periods as $p): ?>
                            <td class="text-center">
                                <?= $block['periods'][$p['code']] > 0
                                    ? e(grades_format($block['periods'][$p['code']]))
                                    : '' ?>
                            </td>
                            <?php if (isset($closes[$p['code']])): ?>
                                <td class="text-center table-secondary">
                                    <?= e(grades_format($block['groups'][$closes[$p['code']]])) ?>
                                </td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <td class="text-center table-secondary">
                            <?= e(grades_format($block['groups']['ANNUAL'])) ?>
                        </td>
                    </tr>

                    <?php foreach ($block['subjects'] as $subjectId): ?>
                        <?php $subject = $subjects[$subjectId]; ?>
                        <tr>
                            <td>
                                <?= e($subject['name']) ?>
                                <?php if (!$subject['ranking']): ?>
                                    <span class="text-secondary" title="Ne compte pas dans le classement">*</span>
                                <?php endif; ?>
                            </td>

                            <?php foreach ($periods as $p): ?>
                                <td class="text-center"><?= $cell($subject['cells'][$p['code']] ?? null) ?></td>

                                <?php if (isset($closes[$p['code']])): ?>
                                    <?php $g = $subject['groups'][$closes[$p['code']]]; ?>
                                    <td class="text-center table-secondary fw-medium">
                                        <?= $g['max'] > 0
                                            ? e(grades_format($g['points']))
                                            : '<span class="text-secondary">—</span>' ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <?php $ga = $subject['groups']['ANNUAL']; ?>
                            <td class="text-center table-secondary fw-semibold">
                                <?= $ga['max'] > 0
                                    ? e(grades_format($ga['points']))
                                    : '<span class="text-secondary">—</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <?php $generalMaxima = bulletins_general_maxima($report['blocks']); ?>

                <tr class="fw-bold">
                    <td>MAXIMA GÉNÉRAUX</td>
                    <?php foreach ($periods as $p): ?>
                        <?php
                        $columnMax = 0.0;

                        foreach ($report['blocks'] as $b) {
                            $columnMax += ($b['periods'][$p['code']] ?? 0.0) * count($b['subjects']);
                        }
                        ?>
                        <td class="text-center"><?= e(grades_format($columnMax)) ?></td>
                        <?php if (isset($closes[$p['code']])): ?>
                            <td class="text-center table-secondary">
                                <?= e(grades_format($generalMaxima[$closes[$p['code']]])) ?>
                            </td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <td class="text-center table-secondary">
                        <?= e(grades_format($generalMaxima['ANNUAL'])) ?>
                    </td>
                </tr>

                <tr class="fw-semibold">
                    <td>TOTAUX</td>
                    <?php foreach ($periods as $p): ?>
                        <?php
                        $columnPoints = 0.0;

                        foreach ($subjects as $s) {
                            $c = $s['cells'][$p['code']] ?? null;

                            if ($c !== null && $c['counted']) {
                                $columnPoints += $c['points'] ?? 0.0;
                            }
                        }
                        ?>
                        <td class="text-center"><?= e(grades_format($columnPoints)) ?></td>
                        <?php if (isset($closes[$p['code']])): ?>
                            <?php $t = $totals[$closes[$p['code']]]; ?>
                            <td class="text-center table-secondary">
                                <?= e(grades_format($t['points'])) ?>
                            </td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <td class="text-center table-secondary">
                        <?= e(grades_format($totals['ANNUAL']['points'])) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
