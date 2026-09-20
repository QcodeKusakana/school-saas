<?php
/**
 * LE BULLETIN PUBLIÉ — le document remis à la famille.
 *
 * Tout ce qui s'affiche ici vient du FIGÉ : les totaux de `bulletins`,
 * le détail de `bulletin_lines`. Aucune cote vivante n'est lue. C'est
 * ce qui permet de rouvrir dans six mois exactement le papier reçu,
 * même si une cote a été corrigée depuis.
 *
 * Le même gabarit sert au portail des parents et au personnel : deux
 * rendus du même document finiraient par diverger, et c'est de cette
 * divergence qu'est né le défaut que la phase 6A corrige.
 *
 * @var array  $bulletin  Ligne figée + élève, classe, année
 * @var array  $report    ['periods' => …, 'subjects' => …] figé
 * @var float  $threshold Seuil de réussite de l'établissement
 * @var array  $decisions Libellés des décisions de fin d'année
 * @var string $backUrl   Retour selon l'écran d'où l'on vient
 * @var string $backLabel
 */
declare(strict_types=1);

set_title('Bulletin');

$periods  = $report['periods'];
$subjects = $report['subjects'];

$percentage = $bulletin['percentage'] !== null ? (float) $bulletin['percentage'] : null;
$passed     = $percentage !== null && $percentage >= $threshold;

// Regroupement par domaine quand le programme en porte : c'est la mise
// en page du bulletin officiel EPST. Sans domaine, une seule table.
$byDomain = [];

foreach ($subjects as $key => $subject) {
    $domain = $subject['domain_name'] ?? '';
    $byDomain[$domain][$key] = $subject;
}

$hasDomains = count($byDomain) > 1 || !isset($byDomain['']);
?>

<div class="page-head d-print-none">
    <div>
        <h1 class="page-title">Bulletin — <?= e((string) $bulletin['period_key']) ?></h1>
        <p class="page-subtitle">
            <?= e(full_name($bulletin['last_name'], $bulletin['post_name'], $bulletin['first_name'])) ?>
            · <?= e((string) ($bulletin['classroom_name'] ?? '—')) ?>
            · <?= e((string) ($bulletin['year_code'] ?? '')) ?>
        </p>
    </div>

    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url($backUrl)) ?>">
            <i class="bi bi-arrow-left me-1"></i> <?= e($backLabel) ?>
        </a>
        <button type="button" class="btn btn-primary" data-print>
            <i class="bi bi-printer me-1"></i> Imprimer
        </button>
    </div>
</div>

<!-- ===================== L'EN-TÊTE DU DOCUMENT ======================= -->
<div class="card mb-3">
    <div class="card-body">
        <div class="text-center mb-3">
            <div class="fw-semibold text-uppercase"><?= e(school_setting('school.name', '')) ?></div>
            <div class="small text-secondary">
                République Démocratique du Congo · Ministère de l'Enseignement
                Primaire, Secondaire et Technique
            </div>
            <h2 class="h5 mt-2 mb-0">Bulletin scolaire</h2>
            <div class="small text-secondary">
                Année scolaire <?= e((string) ($bulletin['year_name'] ?? $bulletin['year_code'] ?? '')) ?>
            </div>
        </div>

        <div class="row g-2 small">
            <div class="col-12 col-md-6">
                <div><span class="text-secondary">Élève :</span>
                    <strong><?= e(full_name($bulletin['last_name'], $bulletin['post_name'], $bulletin['first_name'])) ?></strong>
                </div>
                <div><span class="text-secondary">Matricule :</span> <?= e((string) $bulletin['matricule']) ?></div>
                <?php if ($bulletin['birth_date'] !== null): ?>
                    <div>
                        <span class="text-secondary">Né(e) le :</span>
                        <?= e(date('d/m/Y', strtotime((string) $bulletin['birth_date']))) ?>
                        <?php if ($bulletin['birth_place'] !== null): ?>
                            à <?= e((string) $bulletin['birth_place']) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-6">
                <div><span class="text-secondary">Classe :</span>
                    <strong><?= e((string) ($bulletin['classroom_name'] ?? '—')) ?></strong>
                </div>
                <div><span class="text-secondary">Période :</span> <?= e((string) $bulletin['period_key']) ?></div>
                <div>
                    <span class="text-secondary">Publié le :</span>
                    <?= e(date('d/m/Y', strtotime((string) $bulletin['published_at']))) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ======================== LES RÉSULTATS ============================ -->
<?php if ($subjects === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="mb-0 fw-medium">Ce bulletin ne porte aucune branche.</p>
            <p class="small text-secondary mb-0">
                Il a été publié avant que le détail ne soit archivé. Republier la
                classe reconstruira le document complet.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="min-width:12rem">Branche</th>
                        <?php foreach ($periods as $p): ?>
                            <th scope="col" class="text-center text-nowrap"><?= e($p['name']) ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="text-center">Total</th>
                        <th scope="col" class="text-center">Max</th>
                    </tr>
                </thead>

                <?php foreach ($byDomain as $domainName => $group): ?>
                    <tbody>
                        <?php if ($hasDomains && $domainName !== ''): ?>
                            <tr class="table-light">
                                <th colspan="<?= count($periods) + 3 ?>" class="small text-uppercase">
                                    <?= e((string) $domainName) ?>
                                </th>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($group as $subject): ?>
                            <?php
                            $sum = 0.0;
                            $max = 0.0;
                            $hasPoints = false;

                            foreach ($periods as $code => $p) {
                                $cell = $subject['cells'][$code] ?? null;

                                if ($cell === null) {
                                    continue;
                                }

                                $max += (float) $cell['max'];

                                if ($cell['points'] !== null) {
                                    $sum += (float) $cell['points'];
                                    $hasPoints = true;
                                }
                            }
                            ?>
                            <tr>
                                <td><?= e($subject['name']) ?></td>

                                <?php foreach ($periods as $code => $p): ?>
                                    <?php $cell = $subject['cells'][$code] ?? null; ?>
                                    <td class="text-center text-nowrap">
                                        <?php if ($cell === null): ?>
                                            <span class="text-secondary">—</span>
                                        <?php elseif ($cell['is_absent']): ?>
                                            <span class="text-secondary" title="Absent">abs</span>
                                        <?php elseif ($cell['points'] === null): ?>
                                            <span class="text-secondary">—</span>
                                        <?php else: ?>
                                            <?= e(grades_format((float) $cell['points'])) ?>
                                            <span class="text-secondary small">/<?= e(grades_format((float) $cell['max'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>

                                <td class="text-center fw-medium">
                                    <?= $hasPoints ? e(grades_format($sum)) : '—' ?>
                                </td>
                                <td class="text-center text-secondary"><?= e(grades_format($max)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ========================= LA SYNTHÈSE ============================= -->
<div class="row g-3">
    <div class="col-12 col-md-7">
        <div class="card h-100">
            <div class="card-header fw-medium">Résultat</div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-2">
                    <span class="text-secondary">Pourcentage</span>
                    <span class="fs-3 fw-semibold">
                        <?= $percentage !== null ? e(number_format($percentage, 1, ',', ' ')) . ' %' : '—' ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-secondary">Points obtenus</span>
                    <span><?= e(grades_format((float) $bulletin['total_points'])) ?>
                        / <?= e(grades_format((float) $bulletin['max_points'])) ?></span>
                </div>
                <?php if ($bulletin['class_rank'] !== null): ?>
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary">Rang</span>
                        <span><strong><?= (int) $bulletin['class_rank'] ?></strong>
                            sur <?= (int) $bulletin['class_size'] ?></span>
                    </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between">
                    <span class="text-secondary">Absences retenues</span>
                    <span><?= (int) $bulletin['absent_count'] ?></span>
                </div>

                <?php if ($percentage !== null): ?>
                    <div class="mt-2">
                        <span class="badge <?= $passed
                            ? 'bg-success-subtle text-success-emphasis'
                            : 'bg-danger-subtle text-danger-emphasis' ?>">
                            <?= $passed ? 'Seuil atteint' : 'Sous le seuil' ?>
                            (<?= e(number_format($threshold, 0, ',', ' ')) ?> %)
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ((int) $bulletin['missing_grades'] > 0): ?>
                    <p class="small text-warning-emphasis mb-0 mt-2">
                        <?= (int) $bulletin['missing_grades'] ?> cote(s) manquaient au moment de
                        la publication.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-5">
        <div class="card h-100">
            <div class="card-header fw-medium">Décision</div>
            <div class="card-body">
                <?php if ($bulletin['decision'] !== null): ?>
                    <p class="fs-5 mb-1">
                        <?= e($decisions[(string) $bulletin['decision']] ?? (string) $bulletin['decision']) ?>
                    </p>
                <?php else: ?>
                    <p class="text-secondary mb-1">Sans objet pour cette période.</p>
                <?php endif; ?>

                <?php if ($bulletin['comment'] !== null && $bulletin['comment'] !== ''): ?>
                    <p class="small mb-0"><?= e((string) $bulletin['comment']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<p class="small text-secondary mt-3">
    Document publié le <?= e(date('d/m/Y', strtotime((string) $bulletin['published_at']))) ?>.
    Il est figé : une cote corrigée depuis ne le modifie pas. Seule une nouvelle
    publication par l'établissement le remplace.
</p>
