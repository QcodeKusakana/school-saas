<?php
/**
 * Gabarit « domaines » — bulletin rangé par domaine d'apprentissage.
 *
 * Mise en page du PRIMAIRE (degrés élémentaire, moyen et terminal), du
 * CTEB et des HUMANITÉS GÉNÉRALES. Les branches y sont rangées sous les
 * cinq en-têtes officiels, et chaque domaine porte une ligne SOUS-TOTAL.
 *
 * Deux différences avec le gabarit « maxima » :
 *
 *  · le maximum est une COLONNE, répétée à côté des points, et non une
 *    ligne ouvrant un bloc ;
 *  · les domaines et leurs sous-totaux remplacent les blocs de maxima.
 *
 * Le nombre de regroupements — deux semestres au CTEB et aux humanités,
 * trois trimestres au primaire — est déduit des périodes de la classe.
 * Ce fichier ne suppose ni l'un ni l'autre.
 *
 * @var array    $report   subjects, periods, totals, blocks, domains
 * @var array    $subjects
 * @var array    $periods
 * @var array    $totals
 * @var array    $groups
 * @var array    $closes   dernière période de chaque regroupement
 * @var callable $cell
 */
declare(strict_types=1);

/** Une case de maximum, vide quand la branche n'est pas concernée. */
$maxCell = static function (float $value): string {
    return $value > 0 ? e(grades_format($value)) : '';
};

/**
 * Une ligne de totaux — sous-total de sous-domaine, de domaine, ou ligne
 * générale. Colonne Max : le barème. Colonne Pts : les cotes retenues.
 */
$totalRow = static function (string $label, array $byPeriod, array $byGroup, string $class, string $indent)
    use ($periods, $closes, $maxCell): void {
    ?>
    <tr class="<?= e($class) ?>">
        <td class="<?= e($indent) ?>"><?= e($label) ?></td>
        <?php foreach ($periods as $p): ?>
            <?php $cellTotals = $byPeriod[$p['code']]; ?>
            <td class="text-center text-secondary"><?= $maxCell((float) $cellTotals['scale']) ?></td>
            <td class="text-center"><?= $cellTotals['max'] > 0 ? e(grades_format($cellTotals['points'])) : '' ?></td>

            <?php if (isset($closes[$p['code']])): ?>
                <?php $groupTotals = $byGroup[$closes[$p['code']]]; ?>
                <td class="text-center table-secondary text-secondary"><?= $maxCell((float) $groupTotals['scale']) ?></td>
                <td class="text-center table-secondary"><?= $groupTotals['max'] > 0 ? e(grades_format($groupTotals['points'])) : '' ?></td>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php $annualTotals = $byGroup['ANNUAL']; ?>
        <td class="text-center table-secondary text-secondary"><?= $maxCell((float) $annualTotals['scale']) ?></td>
        <td class="text-center table-secondary"><?= $annualTotals['max'] > 0 ? e(grades_format($annualTotals['points'])) : '' ?></td>
    </tr>
    <?php
};

/** Une ligne de branche. */
$subjectRow = static function (array $subject) use ($periods, $closes, $maxCell, $cell): void {
    ?>
    <tr>
        <td class="ps-3">
            <?= e($subject['name']) ?>
            <?php if (!$subject['ranking']): ?>
                <span class="text-secondary" title="Ne compte pas dans le classement">*</span>
            <?php endif; ?>
        </td>

        <?php foreach ($periods as $p): ?>
            <?php $c = $subject['cells'][$p['code']] ?? null; ?>
            <td class="text-center text-secondary"><?= $c !== null ? $maxCell((float) $c['max']) : '' ?></td>
            <td class="text-center"><?= $cell($c) ?></td>

            <?php if (isset($closes[$p['code']])): ?>
                <?php $g = $subject['groups'][$closes[$p['code']]]; ?>
                <td class="text-center table-secondary text-secondary"><?= $maxCell((float) $g['scale']) ?></td>
                <td class="text-center table-secondary fw-medium">
                    <?= $g['max'] > 0 ? e(grades_format($g['points'])) : '' ?>
                </td>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php $ga = $subject['groups']['ANNUAL']; ?>
        <td class="text-center table-secondary text-secondary"><?= $maxCell((float) $ga['scale']) ?></td>
        <td class="text-center table-secondary fw-semibold">
            <?= $ga['max'] > 0 ? e(grades_format($ga['points'])) : '' ?>
        </td>
    </tr>
    <?php
};

/**
 * Nombre de colonnes du tableau, pour le colspan des en-têtes de domaine.
 *
 * Une colonne « Branches », deux par période (max et points), deux par
 * regroupement, deux pour le total général. Le compte était surévalué
 * d'une unité et l'en-tête de domaine débordait du tableau.
 */
$columnCount = 1 + count($periods) * 2 + count($closes) * 2 + 2;
?>

<div class="table-responsive avoid-break">
    <table class="table table-sm table-bordered align-middle mb-3" style="font-size:.75rem">
        <thead class="table-light">
            <tr>
                <th scope="col" rowspan="2" style="min-width:8rem">Branches</th>
                <?php foreach ($periods as $p): ?>
                    <th scope="col" colspan="2" class="text-center" title="<?= e($p['name']) ?>">
                        <?= e($p['code']) ?>
                    </th>
                    <?php if (isset($closes[$p['code']])): ?>
                        <th scope="col" colspan="2" class="text-center table-secondary"
                            title="<?= e($groups[$closes[$p['code']]]['label']) ?>">
                            <?= e($closes[$p['code']]) ?>
                        </th>
                    <?php endif; ?>
                <?php endforeach; ?>
                <th scope="col" colspan="2" class="text-center table-secondary">TOTAL</th>
            </tr>
            <tr class="fw-normal text-secondary">
                <?php foreach ($periods as $p): ?>
                    <th scope="col" class="text-center small">Max</th>
                    <th scope="col" class="text-center small">Pts</th>
                    <?php if (isset($closes[$p['code']])): ?>
                        <th scope="col" class="text-center small table-secondary">Max</th>
                        <th scope="col" class="text-center small table-secondary">Pts</th>
                    <?php endif; ?>
                <?php endforeach; ?>
                <th scope="col" class="text-center small table-secondary">Max</th>
                <th scope="col" class="text-center small table-secondary">Pts</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($report['domains'] as $domain): ?>
                <!-- En-tête de domaine, en toutes lettres comme sur le
                     document officiel. -->
                <tr class="table-secondary">
                    <th scope="rowgroup" colspan="<?= $columnCount ?>" class="text-uppercase small fw-bold">
                        <?= e($domain['name']) ?>
                    </th>
                </tr>

                <?php
                // SOUS-DOMAINES D'ABORD, PUIS LES BRANCHES DIRECTES.
                //
                // Les bulletins du CTEB et des humanités scientifiques
                // rangent les sciences sous trois sous-domaines, chacun
                // portant SON sous-total. Toutes les branches n'en
                // relèvent pas : celles qui n'ont aucun sous-domaine
                // restent directement sous le domaine, et disparaîtraient
                // si le gabarit ne savait afficher que les strates.
                $grouped = [];

                foreach ($domain['subdomains'] as $sub) {
                    foreach ($sub['subjects'] as $sid) {
                        $grouped[$sid] = true;
                    }
                }
                ?>

                <?php foreach ($domain['subdomains'] as $sub): ?>
                    <tr>
                        <th scope="rowgroup" colspan="<?= $columnCount ?>"
                            class="ps-3 small fw-semibold fst-italic">
                            <?= e($sub['name']) ?>
                        </th>
                    </tr>

                    <?php foreach ($sub['subjects'] as $subjectId): ?>
                        <?php $subjectRow($subjects[$subjectId]); ?>
                    <?php endforeach; ?>

                    <?php $totalRow('Sous-total', $sub['periods'], $sub['groups'], 'fw-medium', 'ps-4 fst-italic'); ?>
                <?php endforeach; ?>

                <?php foreach ($domain['subjects'] as $subjectId): ?>
                    <?php if (isset($grouped[$subjectId])) { continue; } ?>
                    <?php $subjectRow($subjects[$subjectId]); ?>
                <?php endforeach; ?>

                <!-- SOUS-TOTAL du domaine.
                     Colonne Max : le BARÈME, toutes branches confondues,
                     comme sur le formulaire pré-imprimé.
                     Colonne Pts : les seules cotes disponibles, de sorte
                     qu'une branche non encore corrigée ne pèse pas comme
                     un échec. Les deux colonnes ne répondent donc pas à
                     la même question, et c'est voulu. -->
                <?php $totalRow('Sous-total du domaine', $domain['periods'], $domain['groups'], 'fw-semibold', 'ps-2'); ?>
            <?php endforeach; ?>
        </tbody>

        <tfoot class="table-light">
            <tr class="fw-bold">
                <td>MAXIMA GÉNÉRAUX / POINTS</td>
                <?php foreach ($periods as $p): ?>
                    <?php
                    // Les colonnes de maximum portent le BARÈME du
                    // programme, comme sur le formulaire pré-imprimé du
                    // ministère : une branche non encore corrigée doit
                    // montrer ce qu'elle vaut, pas une case vide.
                    $columnScale  = 0.0;
                    $columnPoints = 0.0;

                    foreach ($report['domains'] as $d) {
                        $columnScale  += $d['periods'][$p['code']]['scale'];
                        $columnPoints += $d['periods'][$p['code']]['points'];
                    }

                    $groupScale = 0.0;

                    if (isset($closes[$p['code']])) {
                        foreach ($report['domains'] as $d) {
                            $groupScale += $d['groups'][$closes[$p['code']]]['scale'];
                        }
                    }
                    ?>
                    <td class="text-center text-secondary"><?= $maxCell($columnScale) ?></td>
                    <td class="text-center"><?= $columnScale > 0 ? e(grades_format($columnPoints)) : '' ?></td>

                    <?php if (isset($closes[$p['code']])): ?>
                        <?php $t = $totals[$closes[$p['code']]]; ?>
                        <td class="text-center table-secondary text-secondary"><?= $maxCell($groupScale) ?></td>
                        <td class="text-center table-secondary"><?= $t['max'] > 0 ? e(grades_format($t['points'])) : '' ?></td>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php
                $annualScale = 0.0;

                foreach ($report['domains'] as $d) {
                    $annualScale += $d['groups']['ANNUAL']['scale'];
                }
                ?>
                <td class="text-center table-secondary text-secondary">
                    <?= $maxCell($annualScale) ?>
                </td>
                <td class="text-center table-secondary">
                    <?= $totals['ANNUAL']['max'] > 0 ? e(grades_format($totals['ANNUAL']['points'])) : '' ?>
                </td>
            </tr>
        </tfoot>
    </table>
</div>
