<?php
/**
 * Grille de saisie : une classe, une branche, une période.
 *
 * L'écran le plus utilisé du module. Un enseignant y saisit quarante
 * cotes à la suite : le clavier doit suffire, la souris ne doit jamais
 * être nécessaire.
 *
 * @var array      $classroom
 * @var array      $subject
 * @var array      $period
 * @var array|null $year
 * @var float      $maxPoints
 * @var bool       $canEnter
 * @var bool       $forced
 * @var string     $reason
 * @var array      $rows
 */
declare(strict_types=1);

set_title('Saisie — ' . $subject['subject_name'] . ' — ' . $classroom['code']);

$entered = 0;

foreach ($rows as $row) {
    if ($row['points'] !== null || (int) $row['is_absent'] === 1) {
        $entered++;
    }
}

$action = url('/notes/classe/' . (int) $classroom['id']
    . '/branche/' . (int) $subject['id']
    . '/periode/' . (int) $period['id']);
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($subject['subject_name']) ?></h1>
        <p class="page-subtitle">
            <strong><?= e($classroom['name']) ?></strong>
            · <?= e($period['name']) ?>
            · noté sur <strong><?= e(grades_format($maxPoints)) ?></strong>
            <?php if ((float) $period['max_multiplier'] !== 1.0): ?>
                <span class="text-secondary">
                    (<?= (int) $subject['max_points'] ?> × <?= e(grades_format((float) $period['max_multiplier'])) ?>)
                </span>
            <?php endif; ?>
            · <?= $entered ?> / <?= count($rows) ?> saisie(s)
        </p>
    </div>

    <a href="<?= e(url('/notes/classe/' . (int) $classroom['id'])) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> La classe
    </a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if (!$canEnter): ?>
    <div class="alert alert-secondary d-flex align-items-start gap-2">
        <i class="bi bi-lock"></i>
        <div>
            <strong>Lecture seule.</strong>
            <?= e($reason !== '' ? $reason : 'La saisie ne vous est pas ouverte pour cette branche.') ?>
        </div>
    </div>
<?php elseif ($forced): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle"></i>
        <div>
            <strong>Période verrouillée.</strong>
            Vous y accédez au titre d'une permission d'exception.
            Chaque enregistrement sera journalisé à votre nom.
        </div>
    </div>
<?php endif; ?>

<?php if ($rows === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-people display-6 text-secondary d-block mb-2"></i>
            <p class="mb-1 fw-medium">Aucun élève inscrit dans cette classe.</p>
            <p class="text-secondary small mb-0">Affectez d'abord les élèves à la classe.</p>
        </div>
    </div>
<?php else: ?>
    <form method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 grade-sheet">
                    <thead>
                        <tr>
                            <th scope="col" style="width:3rem" class="text-center">#</th>
                            <th scope="col">Élève</th>
                            <th scope="col" class="d-none d-md-table-cell">Matricule</th>
                            <th scope="col" style="width:9rem" class="text-center">
                                Cote / <?= e(grades_format($maxPoints)) ?>
                            </th>
                            <th scope="col" style="width:6rem" class="text-center">Absent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php $id = (int) $row['enrollment_id']; ?>
                            <tr>
                                <td class="text-center text-secondary"><?= $index + 1 ?></td>
                                <td>
                                    <span class="fw-medium">
                                        <?= e(full_name($row['last_name'], $row['post_name'], $row['first_name'])) ?>
                                    </span>
                                    <?php if ($row['gender'] !== null): ?>
                                        <span class="badge bg-light text-secondary ms-1"><?= e($row['gender']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <span class="small text-secondary"><?= e($row['matricule']) ?></span>
                                </td>
                                <td class="text-center">
                                    <input type="number"
                                           class="form-control form-control-sm text-center"
                                           name="points[<?= $id ?>]"
                                           value="<?= $row['points'] !== null ? e(rtrim(rtrim((string) $row['points'], '0'), '.')) : '' ?>"
                                           step="0.25" min="0" max="<?= e((string) $maxPoints) ?>"
                                           inputmode="decimal"
                                           aria-label="Cote de <?= e(full_name($row['last_name'], $row['post_name'], $row['first_name'])) ?>"
                                           <?= $canEnter ? '' : 'disabled' ?>
                                           <?= (int) $row['is_absent'] === 1 ? 'disabled' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox"
                                           class="form-check-input"
                                           name="absent[<?= $id ?>]" value="1"
                                           data-grade-absent
                                           aria-label="Absent"
                                           <?= (int) $row['is_absent'] === 1 ? 'checked' : '' ?>
                                           <?= $canEnter ? '' : 'disabled' ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($canEnter): ?>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Enregistrer la colonne
                </button>
                <span class="small text-secondary">
                    Une case laissée vide efface la cote. Cocher « Absent » n'équivaut pas à zéro.
                </span>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>
