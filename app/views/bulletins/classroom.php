<?php
/**
 * Bulletins d'une classe : classement et publication.
 *
 * Écran du conseil de classe. Tant que rien n'est publié, il montre
 * l'état de travail, recalculé à chaque affichage. Une fois publié, il
 * montre ce qui a été figé — c'est ce document que les familles ont reçu.
 *
 * @var array      $classroom
 * @var array|null $year
 * @var string     $periodKey
 * @var array      $groups
 * @var array      $published
 * @var array      $live
 * @var array      $status
 * @var float      $threshold
 * @var string     $mode
 * @var array      $decisions
 */
declare(strict_types=1);

require_once APP_PATH . '/modules/grades/services.php';

set_title('Bulletins — ' . $classroom['name']);

$isPublished = $published !== [];
$editable    = can('bulletin.publish')
    && $year !== null
    && !in_array($year['status'], ['closed', 'archived'], true);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Bulletins</h1>
        <p class="page-subtitle">
            <strong><?= e($classroom['name']) ?></strong>
            <span class="text-secondary">(<?= e($classroom['code']) ?>)</span>
            <?php if ($year !== null): ?> · année <?= e($year['code']) ?><?php endif; ?>
            · seuil <?= e(number_format($threshold, 0, ',', ' ')) ?> %
        </p>
    </div>

    <a href="<?= e(url('/notes/classe/' . (int) $classroom['id'])) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Les notes
    </a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<!-- -------------------------------------------------------------------
     Choix du regroupement
-------------------------------------------------------------------- -->
<div class="card mb-3">
    <div class="card-body py-3 d-flex flex-wrap gap-2 align-items-center">
        <?php foreach ($groups as $key => $group): ?>
            <a class="btn btn-sm <?= $key === $periodKey ? 'btn-primary' : 'btn-outline-secondary' ?>"
               href="<?= e(url('/bulletins/classe/' . (int) $classroom['id'], ['periode' => $key])) ?>">
                <?= e($group['label']) ?>
                <?php if (isset($status[$key])): ?>
                    <i class="bi bi-check-circle-fill ms-1" title="Publié"></i>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>

        <span class="ms-auto small text-secondary">
            Absences :
            <?= $mode === 'zero' ? 'comptées zéro' : 'exclues du total' ?>
            <?php if (can('school.edit')): ?>
                · <a href="<?= e(url('/bulletins/parametres')) ?>">modifier</a>
            <?php endif; ?>
        </span>
    </div>
</div>

<?php if (isset($status[$periodKey])): ?>
    <div class="alert alert-success d-flex align-items-start gap-2">
        <i class="bi bi-check-circle"></i>
        <div>
            <strong><?= (int) $status[$periodKey]['count'] ?></strong> bulletin(s) publié(s)
            le <?= e(substr($status[$periodKey]['published_at'], 0, 16)) ?>.
            Les rangs affichés sont ceux qui figurent sur les documents remis.
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-secondary d-flex align-items-start gap-2">
        <i class="bi bi-pencil"></i>
        <div>
            <strong>Document de travail.</strong>
            Rien n'est publié pour ce regroupement : les totaux et les rangs
            sont recalculés à chaque affichage et suivront toute nouvelle cote.
        </div>
    </div>
<?php endif; ?>

<?php
$rows = $isPublished ? $published : $live;
?>

<?php if ($rows === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-file-earmark-text display-6 text-secondary d-block mb-2"></i>
            <p class="mb-1 fw-medium">Aucun élève inscrit dans cette classe.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col" class="text-center" style="width:4rem">Rang</th>
                        <th scope="col">Élève</th>
                        <th scope="col" class="d-none d-md-table-cell">Matricule</th>
                        <th scope="col" class="text-end">Total</th>
                        <th scope="col" class="text-end">%</th>
                        <th scope="col" class="text-center">Résultat</th>
                        <?php if ($isPublished && $periodKey === 'ANNUAL'): ?>
                            <th scope="col">Décision</th>
                        <?php endif; ?>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $key => $row): ?>
                        <?php
                        // Les deux sources n'ont pas la même forme : le
                        // figé vient de la table, le vivant du calcul.
                        if ($isPublished) {
                            $enrollmentId = (int) $row['enrollment_id'];
                            $name    = full_name($row['last_name'], $row['post_name'], $row['first_name']);
                            $mat     = $row['matricule'];
                            $rank    = $row['class_rank'] !== null ? (int) $row['class_rank'] : null;
                            $size    = (int) $row['class_size'];
                            $pct     = $row['percentage'] !== null ? (float) $row['percentage'] : null;
                            $points  = (float) $row['total_points'];
                            $max     = (float) $row['max_points'];
                            $missing = (int) $row['missing_grades'];
                        } else {
                            $enrollmentId = (int) $key;
                            $student = $row['student'];
                            $name    = full_name($student['last_name'], $student['post_name'], $student['first_name']);
                            $mat     = $student['matricule'];
                            $rank    = $row['rank'];
                            $size    = count(array_filter($live, static fn (array $r): bool => $r['percentage'] !== null));
                            $pct     = $row['percentage'];
                            $points  = (float) $row['points'];
                            $max     = (float) $row['max'];
                            $missing = (int) $row['missing'];
                        }

                        $pass = $pct !== null && $pct >= $threshold;
                        ?>
                        <tr>
                            <td class="text-center">
                                <?php if ($rank !== null): ?>
                                    <span class="fw-semibold"><?= $rank ?></span>
                                    <span class="small text-secondary">/<?= $size ?></span>
                                <?php else: ?>
                                    <span class="text-secondary">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-medium"><?= e($name) ?></span>
                                <?php if ($missing > 0): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis ms-1"
                                          title="Cotes manquantes"><?= $missing ?> manquante(s)</span>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <span class="small text-secondary"><?= e($mat) ?></span>
                            </td>
                            <td class="text-end">
                                <?= e(grades_format($points)) ?>
                                <span class="small text-secondary">/ <?= e(grades_format($max)) ?></span>
                            </td>
                            <td class="text-end fw-medium">
                                <?= $pct !== null ? e(number_format($pct, 1, ',', ' ')) : '—' ?>
                            </td>
                            <td class="text-center">
                                <?php if ($pct === null): ?>
                                    <span class="text-secondary">non classé</span>
                                <?php else: ?>
                                    <span class="badge <?= $pass ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' ?>">
                                        <?= $pass ? 'Réussite' : 'Échec' ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <?php if ($isPublished && $periodKey === 'ANNUAL'): ?>
                                <td>
                                    <span class="small">
                                        <?= $row['decision'] !== null
                                            ? e($decisions[$row['decision']] ?? $row['decision'])
                                            : '<span class="text-secondary">—</span>' ?>
                                    </span>
                                </td>
                            <?php endif; ?>

                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="<?= e(url('/bulletins/' . $enrollmentId)) ?>">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <span class="d-none d-md-inline ms-1">Bulletin</span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($editable): ?>
        <form method="post"
              action="<?= e(url('/bulletins/classe/' . (int) $classroom['id'] . '/publier', ['periode' => $periodKey])) ?>"
              class="mt-3 d-flex flex-wrap gap-2 align-items-center"
              data-confirm="Publier les bulletins ? Les rangs et les totaux seront figés : ajouter une cote ensuite ne modifiera plus les documents publiés.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2-square me-1"></i>
                <?= isset($status[$periodKey]) ? 'Republier' : 'Publier' ?> — <?= e($groups[$periodKey]['label']) ?>
            </button>
            <span class="small text-secondary">
                La publication fige le rang et l'effectif. Un rang recalculé après coup
                contredirait un bulletin déjà remis.
            </span>
        </form>
    <?php endif; ?>
<?php endif; ?>
