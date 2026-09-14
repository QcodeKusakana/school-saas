<?php
/**
 * Répartition des branches d'une classe.
 *
 * Écran de travail du préfet des études : une ligne par branche du
 * programme, l'enseignant qui l'assure, et ce qui reste à pourvoir.
 *
 * @var array      $classroom
 * @var array|null $year
 * @var array      $rows
 * @var array      $teachers
 * @var array|null $mainTeacher
 */
declare(strict_types=1);

set_title('Répartition — ' . $classroom['name']);

$assigned = 0;
$hours    = 0.0;

foreach ($rows as $row) {
    if ($row['assignment_id'] !== null) {
        $assigned++;
        $hours += (float) ($row['assigned_hours'] ?? $row['weekly_hours'] ?? 0);
    }
}

$pending  = count($rows) - $assigned;
$editable = can('teacher.assign')
    && $year !== null
    && !in_array($year['status'], ['closed', 'archived'], true);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Répartition des branches</h1>
        <p class="page-subtitle">
            <strong><?= e($classroom['name']) ?></strong>
            <span class="text-secondary">(<?= e($classroom['code']) ?>)</span>
            <?php if ($year !== null): ?> · année <?= e($year['code']) ?><?php endif; ?>
            · <?= $assigned ?> / <?= count($rows) ?> branche(s) pourvue(s)
            · <?= e(number_format($hours, 1, ',', ' ')) ?> h/semaine
        </p>
    </div>

    <a href="<?= e(url('/classes/' . (int) $classroom['id'])) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> La classe
    </a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($year !== null && in_array($year['status'], ['closed', 'archived'], true)): ?>
    <div class="alert alert-secondary d-flex align-items-start gap-2">
        <i class="bi bi-lock"></i>
        <div>
            Cette année scolaire est clôturée. La répartition reste consultable
            mais ne peut plus être modifiée : elle documente ce qui a réellement
            été enseigné.
        </div>
    </div>
<?php endif; ?>

<?php if ($pending > 0 && $editable): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle"></i>
        <div>
            <strong><?= $pending ?></strong> branche(s) sans enseignant.
            Tant qu'une branche n'est pas pourvue, aucune note ne pourra y être saisie.
        </div>
    </div>
<?php endif; ?>

<!-- -------------------------------------------------------------------
     Titulaire
-------------------------------------------------------------------- -->
<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label class="form-label form-label-sm mb-1">Titulaire de la classe</label>
                <?php if ($editable): ?>
                    <form method="post" action="<?= e(url('/classes/' . (int) $classroom['id'] . '/titulaire')) ?>"
                          class="d-flex gap-2">
                        <?= csrf_field() ?>
                        <select name="main_teacher_id" class="form-select form-select-sm">
                            <option value="">Aucun</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int) $teacher['id'] ?>"
                                    <?= (int) $classroom['main_teacher_id'] === (int) $teacher['id'] ? 'selected' : '' ?>>
                                    <?= e(full_name($teacher['last_name'], $teacher['post_name'], $teacher['first_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Enregistrer</button>
                    </form>
                <?php else: ?>
                    <p class="mb-0">
                        <?= $mainTeacher !== null
                            ? e(full_name($mainTeacher['last_name'], $mainTeacher['post_name'], $mainTeacher['first_name']))
                            : '<span class="text-secondary">Aucun</span>' ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-6">
                <p class="small text-secondary mb-0">
                    Le titulaire encadre la classe et voit tous ses élèves.
                    Il ne note que les branches qui lui sont confiées ci-dessous.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- -------------------------------------------------------------------
     Branches
-------------------------------------------------------------------- -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Branche</th>
                    <th scope="col" class="text-center">Maximum</th>
                    <th scope="col" class="text-center d-none d-md-table-cell">Heures</th>
                    <th scope="col">Enseignant</th>
                    <?php if ($editable): ?><th scope="col" class="text-end">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr class="<?= $row['assignment_id'] === null ? 'table-warning' : '' ?>">
                        <td>
                            <span class="fw-medium"><?= e($row['subject_name']) ?></span>
                            <?php if ((int) $row['is_optional'] === 1): ?>
                                <span class="badge bg-light text-secondary ms-1">facultative</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= (int) $row['max_points'] ?></td>
                        <td class="text-center d-none d-md-table-cell">
                            <?= $row['weekly_hours'] !== null
                                ? e(number_format((float) $row['weekly_hours'], 1, ',', ' '))
                                : '<span class="text-secondary">—</span>' ?>
                        </td>
                        <td>
                            <?php if ($row['teacher_id'] !== null): ?>
                                <a href="<?= e(url('/enseignants/' . (int) $row['teacher_id'])) ?>"
                                   class="text-decoration-none">
                                    <?= e(full_name($row['last_name'], $row['post_name'], $row['first_name'])) ?>
                                </a>
                            <?php elseif ($editable): ?>
                                <form method="post"
                                      action="<?= e(url('/classes/' . (int) $classroom['id'] . '/repartition')) ?>"
                                      class="d-flex gap-1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="curriculum_subject_id"
                                           value="<?= (int) $row['curriculum_subject_id'] ?>">
                                    <select name="teacher_id" class="form-select form-select-sm"
                                            aria-label="Enseignant pour <?= e($row['subject_name']) ?>" required>
                                        <option value="">Choisir…</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?= (int) $teacher['id'] ?>">
                                                <?= e(full_name($teacher['last_name'], $teacher['post_name'], $teacher['first_name'])) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" name="weekly_hours" class="form-control form-control-sm"
                                           style="max-width:5.5rem" step="0.5" min="0" max="40"
                                           placeholder="h" aria-label="Heures hebdomadaires">
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-secondary">Non pourvue</span>
                            <?php endif; ?>
                        </td>

                        <?php if ($editable): ?>
                            <td class="text-end">
                                <?php if ($row['assignment_id'] !== null): ?>
                                    <form method="post"
                                          action="<?= e(url('/classes/' . (int) $classroom['id'] . '/repartition/' . (int) $row['assignment_id'] . '/retirer')) ?>"
                                          data-confirm="Retirer cette branche à l'enseignant ? Si des cotes ont été saisies, plus rien ne le reliera à ce qu'il a corrigé.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="confirm" value="oui">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x-lg"></i>
                                            <span class="d-none d-md-inline ms-1">Retirer</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="alert alert-info mt-3">
        Le programme de cette classe ne comporte aucune branche.
        Complétez-le depuis <a href="<?= e(url('/referentiel/programmes')) ?>">le référentiel</a>
        avant de répartir les enseignants.
    </div>
<?php endif; ?>
