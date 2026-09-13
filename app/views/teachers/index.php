<?php
/**
 * Liste du personnel enseignant.
 *
 * @var array      $teachers
 * @var int        $total
 * @var int        $pages
 * @var int        $page
 * @var array      $filters
 * @var array      $statuses
 * @var array|null $year
 * @var array      $years
 */
declare(strict_types=1);

set_title('Enseignants');

$badge = [
    'active'    => 'bg-success-subtle text-success-emphasis',
    'suspended' => 'bg-warning-subtle text-warning-emphasis',
    'left'      => 'bg-secondary-subtle text-secondary-emphasis',
];
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Enseignants</h1>
        <p class="page-subtitle">
            <?= (int) $total ?> fiche(s)
            <?php if ($year !== null): ?>
                · année <strong><?= e($year['code']) ?></strong>
            <?php endif; ?>
        </p>
    </div>

    <?php if (can('teacher.manage')): ?>
        <a href="<?= e(url('/enseignants/nouveau')) ?>" class="btn btn-primary">
            <i class="bi bi-person-plus me-1"></i> Nouvel enseignant
        </a>
    <?php endif; ?>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?= e(url('/enseignants')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label for="q" class="form-label form-label-sm">Nom ou matricule</label>
                <input type="search" id="q" name="q" class="form-control form-control-sm"
                       value="<?= e($filters['q']) ?>" placeholder="Début du nom…">
            </div>

            <div class="col-6 col-md-3">
                <label for="statut" class="form-label form-label-sm">Statut</label>
                <select id="statut" name="statut" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    <?php foreach ($statuses as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $filters['status'] === $code ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-search me-1"></i> Filtrer
                </button>
                <?php if ($filters['q'] !== '' || $filters['status'] !== ''): ?>
                    <a href="<?= e(url('/enseignants')) ?>" class="btn btn-sm btn-outline-secondary">Réinitialiser</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($teachers === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-people display-6 text-secondary d-block mb-2"></i>
            <p class="mb-1 fw-medium">Aucun enseignant ne correspond.</p>
            <p class="text-secondary small mb-0">
                La répartition des branches et la saisie des notes s'appuient sur ces fiches.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Nom</th>
                        <th scope="col" class="d-none d-md-table-cell">Matricule</th>
                        <th scope="col" class="d-none d-lg-table-cell">Spécialité</th>
                        <th scope="col" class="text-center">Branches</th>
                        <th scope="col" class="d-none d-md-table-cell">Compte</th>
                        <th scope="col">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teachers as $teacher): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('/enseignants/' . (int) $teacher['id'])) ?>"
                                   class="fw-medium text-decoration-none">
                                    <?= e(full_name($teacher['last_name'], $teacher['post_name'], $teacher['first_name'])) ?>
                                </a>
                                <?php if (!empty($teacher['phone'])): ?>
                                    <div class="small text-secondary d-md-none"><?= e($teacher['phone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?= $teacher['matricule'] !== null ? e($teacher['matricule']) : '<span class="text-secondary">—</span>' ?>
                            </td>
                            <td class="d-none d-lg-table-cell">
                                <?= $teacher['specialty'] !== null ? e($teacher['specialty']) : '<span class="text-secondary">—</span>' ?>
                            </td>
                            <td class="text-center">
                                <?php if ((int) ($teacher['assignment_count'] ?? 0) > 0): ?>
                                    <span class="badge bg-primary-subtle text-primary-emphasis">
                                        <?= (int) $teacher['assignment_count'] ?>
                                    </span>
                                    <span class="small text-secondary">
                                        dans <?= (int) $teacher['classroom_count'] ?> classe(s)
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?php if ($teacher['username'] !== null): ?>
                                    <span class="small"><i class="bi bi-person-check text-success me-1"></i><?= e($teacher['username']) ?></span>
                                <?php else: ?>
                                    <span class="small text-secondary"><i class="bi bi-dash-circle me-1"></i>aucun</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= e($badge[$teacher['status']] ?? 'bg-secondary-subtle') ?>">
                                    <?= e($statuses[$teacher['status']] ?? $teacher['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="mt-3" aria-label="Pagination">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link"
                           href="<?= e(url('/enseignants', array_filter([
                               'q'      => $filters['q'],
                               'statut' => $filters['status'],
                               'page'   => $p,
                           ]))) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
