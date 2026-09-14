<?php
/**
 * Avancement de la saisie pour une classe.
 *
 * Tableau de bord du préfet : une ligne par branche, une colonne par
 * période, et le nombre de cotes déjà posées. Ce qui manque avant de
 * pouvoir éditer les bulletins se voit d'un coup d'œil.
 *
 * @var array      $classroom
 * @var array|null $year
 * @var array      $rows
 * @var int        $students
 */
declare(strict_types=1);

set_title('Notes — ' . $classroom['name']);

// Réorganisation en tableau croisé : branches en lignes, périodes en
// colonnes. Le dépôt renvoie une ligne par couple, une seule requête.
$subjects = [];
$periods  = [];

foreach ($rows as $row) {
    $subjectId = (int) $row['curriculum_subject_id'];
    $periodId  = (int) $row['period_id'];

    if (!isset($subjects[$subjectId])) {
        $subjects[$subjectId] = [
            'name'       => $row['subject_name'],
            'max'        => (int) $row['max_points'],
            'teacher'    => $row['teacher_id'] !== null
                ? full_name($row['teacher_last_name'], $row['teacher_post_name'], $row['teacher_first_name'])
                : null,
            'cells'      => [],
        ];
    }

    $periods[$periodId] = [
        'code'   => $row['period_code'],
        'name'   => $row['period_name'],
        'locked' => (int) $row['is_locked'] === 1,
        'order'  => (int) $row['period_order'],
    ];

    $subjects[$subjectId]['cells'][$periodId] = (int) $row['entered'];
}

uasort($periods, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

$missing = 0;

foreach ($subjects as $subject) {
    foreach ($periods as $periodId => $period) {
        $missing += max(0, $students - ($subject['cells'][$periodId] ?? 0));
    }
}
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($classroom['name']) ?></h1>
        <p class="page-subtitle">
            <?= e($classroom['code']) ?>
            <?php if ($year !== null): ?> · année <?= e($year['code']) ?><?php endif; ?>
            · <?= $students ?> élève(s)
            · <?= count($subjects) ?> branche(s)
        </p>
    </div>

    <a href="<?= e(url('/notes')) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Notes
    </a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($subjects === []): ?>
    <div class="alert alert-info">
        Le programme de cette classe ne comporte aucune branche.
        Complétez-le depuis <a href="<?= e(url('/referentiel/programmes')) ?>">le référentiel</a>.
    </div>
<?php else: ?>
    <?php if ($missing > 0): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle"></i>
            <div>
                <strong><?= $missing ?></strong> cote(s) manquante(s) sur l'ensemble de l'année.
                Un bulletin ne peut être établi tant qu'une période reste incomplète.
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Branche</th>
                        <th scope="col" class="d-none d-lg-table-cell">Enseignant</th>
                        <?php foreach ($periods as $period): ?>
                            <th scope="col" class="text-center" title="<?= e($period['name']) ?>">
                                <?= e($period['code']) ?>
                                <?php if ($period['locked']): ?>
                                    <i class="bi bi-lock-fill text-secondary"></i>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subjects as $subjectId => $subject): ?>
                        <tr>
                            <td>
                                <span class="fw-medium"><?= e($subject['name']) ?></span>
                                <span class="small text-secondary">/ <?= $subject['max'] ?></span>
                            </td>
                            <td class="d-none d-lg-table-cell">
                                <?php if ($subject['teacher'] !== null): ?>
                                    <span class="small"><?= e($subject['teacher']) ?></span>
                                <?php else: ?>
                                    <span class="small text-warning-emphasis">
                                        <i class="bi bi-exclamation-circle me-1"></i>non pourvue
                                    </span>
                                <?php endif; ?>
                            </td>

                            <?php foreach ($periods as $periodId => $period): ?>
                                <?php
                                $done     = $subject['cells'][$periodId] ?? 0;
                                $complete = $students > 0 && $done >= $students;
                                ?>
                                <td class="text-center">
                                    <a class="btn btn-sm <?= $complete ? 'btn-success' : ($done > 0 ? 'btn-warning' : 'btn-outline-secondary') ?>"
                                       href="<?= e(url('/notes/classe/' . (int) $classroom['id']
                                           . '/branche/' . $subjectId
                                           . '/periode/' . $periodId)) ?>"
                                       title="<?= e($period['name']) ?> — <?= $done ?>/<?= $students ?>">
                                        <?= $done ?>/<?= $students ?>
                                    </a>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="small text-secondary mt-2 mb-0">
        Chaque case mène à la grille de saisie. Vert : colonne complète.
        Orange : saisie commencée. Gris : rien de saisi.
    </p>
<?php endif; ?>
