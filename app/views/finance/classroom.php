<?php
/**
 * Situation financière d'une classe.
 *
 * La colonne qui compte est « Facturation ». Un élève sans dette
 * affectée porte un avertissement, jamais un blanc : un blanc se lit
 * comme « rien à devoir ».
 *
 * @var array $classroom
 * @var array|null $year
 * @var array $students
 * @var array $due
 */
declare(strict_types=1);

set_title('Finances — ' . $classroom['name']);

$missing = array_filter($students, static fn (array $s): bool => (int) $s['fee_count'] === 0);
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($classroom['name']) ?></h1>
        <p class="page-subtitle">
            <?= count($students) ?> inscrit(s)
            <?php if ($year !== null): ?> · année <?= e($year['code']) ?><?php endif; ?>
        </p>
    </div>

    <a href="<?= e(url('/finances', ['annee' => (int) $classroom['academic_year_id']])) ?>"
       class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Toutes les classes
    </a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($due !== []): ?>
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-4">
            <?php foreach ($due as $currency => $total): ?>
                <div>
                    <div class="small text-secondary">Total réclamé</div>
                    <div class="fs-5 fw-semibold">
                        <?= e(finance_amount((float) $total, (string) $currency)) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($missing !== []): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-octagon"></i>
        <div>
            <strong><?= count($missing) ?> élève(s)</strong> n'ont aucun frais affecté.
            Ils ne doivent rien parce que rien ne leur a été facturé.
            <?php if (can('fee.manage')): ?>
                <form method="post" action="<?= e(url('/finances/affecter')) ?>" class="mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="academic_year_id" value="<?= (int) $classroom['academic_year_id'] ?>">
                    <input type="hidden" name="classroom_id" value="<?= (int) $classroom['id'] ?>">
                    <input type="hidden" name="retour" value="/finances/classe/<?= (int) $classroom['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">
                        Affecter les frais à cette classe
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($students === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-people display-6 text-secondary d-block mb-2"></i>
            <p class="mb-0 fw-medium">Aucun élève inscrit dans cette classe.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Élève</th>
                        <th scope="col" class="d-none d-md-table-cell">Matricule</th>
                        <th scope="col" class="text-center">Facturation</th>
                        <th scope="col" class="text-center">Remises</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $row): ?>
                        <tr>
                            <td class="fw-medium">
                                <?= e(full_name($row['last_name'], $row['post_name'], $row['first_name'])) ?>
                            </td>
                            <td class="d-none d-md-table-cell small text-secondary">
                                <?= e((string) $row['matricule']) ?>
                            </td>
                            <td class="text-center">
                                <?php if ((int) $row['fee_count'] === 0): ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis">non facturé</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success-emphasis">
                                        <?= (int) $row['fee_count'] ?> frais
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?= (int) $row['discount_count'] > 0
                                    ? '<span class="badge bg-info-subtle text-info-emphasis">'
                                        . (int) $row['discount_count'] . '</span>'
                                    : '<span class="text-secondary">—</span>' ?>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(url('/finances/eleve/' . (int) $row['enrollment_id'])) ?>">
                                    Situation
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
