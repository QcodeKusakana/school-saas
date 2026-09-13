<?php
/**
 * Liste des élèves — écran le plus utilisé du secrétariat.
 *
 * @var array $year
 * @var array $years
 * @var array $students
 * @var int   $total
 * @var int   $pages
 * @var int   $page
 * @var array $filters
 * @var array $classrooms
 * @var array $stats
 */
declare(strict_types=1);

set_title('Élèves');

/** Conserve les filtres courants lors de la pagination. */
$queryBase = array_filter([
    'annee'       => (int) $year['id'],
    'q'           => $filters['q'] ?: null,
    'statut'      => $filters['status'] ?: null,
    'sexe'        => $filters['gender'] ?: null,
    'classe'      => $filters['classroom_id'] ?: null,
    'sans_classe' => $filters['unassigned'] ? 1 : null,
]);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Élèves</h1>
        <p class="page-subtitle">
            Année <strong><?= e($year['code']) ?></strong> ·
            <?= number_format($total, 0, ',', ' ') ?> dossier<?= $total > 1 ? 's' : '' ?>
            <?= $filters['q'] !== '' ? ' correspondant à « ' . e($filters['q']) . ' »' : '' ?>
        </p>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <?php if (count($years) > 1): ?>
            <form method="get" action="<?= e(url('/eleves')) ?>">
                <select name="annee" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($years as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === (int) $year['id'] ? 'selected' : '' ?>>
                            <?= e($option['code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <?php if (can('student.create')): ?>
            <a href="<?= e(url('/eleves/nouveau', ['annee' => (int) $year['id']])) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-person-plus"></i> Inscrire un élève
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Indicateurs de l'année -->
<div class="stat-grid mb-3">
    <div class="stat-card">
        <div class="stat-icon stat-icon-teal"><i class="bi bi-people"></i></div>
        <div class="stat-body">
            <span class="stat-value"><?= number_format((int) ($stats['enrolled'] ?? 0), 0, ',', ' ') ?></span>
            <span class="stat-label">Inscrits</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-indigo"><i class="bi bi-gender-ambiguous"></i></div>
        <div class="stat-body">
            <span class="stat-value"><?= (int) ($stats['girls'] ?? 0) ?> F / <?= (int) ($stats['boys'] ?? 0) ?> G</span>
            <span class="stat-label">Répartition</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-amber"><i class="bi bi-hourglass-split"></i></div>
        <div class="stat-body">
            <span class="stat-value"><?= (int) ($stats['pre_registered'] ?? 0) + (int) ($stats['admitted'] ?? 0) ?></span>
            <span class="stat-label">Dossiers en attente</span>
        </div>
    </div>
    <?php $unassigned = (int) ($stats['unassigned'] ?? 0); ?>
    <a class="stat-card stat-link" href="<?= e(url('/eleves', ['annee' => (int) $year['id'], 'sans_classe' => 1])) ?>">
        <div class="stat-icon <?= $unassigned > 0 ? 'stat-icon-red' : 'stat-icon-green' ?>">
            <i class="bi bi-door-open"></i>
        </div>
        <div class="stat-body">
            <span class="stat-value"><?= $unassigned ?></span>
            <span class="stat-label">Sans classe</span>
        </div>
    </a>
</div>

<!-- Filtres -->
<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?= e(url('/eleves')) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="annee" value="<?= (int) $year['id'] ?>">

            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="q">Rechercher</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="search" class="form-control" id="q" name="q"
                           value="<?= e($filters['q']) ?>"
                           placeholder="Nom, postnom, prénom ou matricule">
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="classe">Classe</label>
                <select class="form-select form-select-sm" id="classe" name="classe">
                    <option value="">Toutes</option>
                    <?php foreach ($classrooms as $classroom): ?>
                        <option value="<?= (int) $classroom['id'] ?>"
                            <?= (int) $classroom['id'] === (int) $filters['classroom_id'] ? 'selected' : '' ?>>
                            <?= e($classroom['name']) ?> (<?= (int) $classroom['student_count'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="sexe">Sexe</label>
                <select class="form-select form-select-sm" id="sexe" name="sexe">
                    <option value="">Tous</option>
                    <option value="F" <?= $filters['gender'] === 'F' ? 'selected' : '' ?>>Filles</option>
                    <option value="M" <?= $filters['gender'] === 'M' ? 'selected' : '' ?>>Garçons</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="statut">Statut</label>
                <select class="form-select form-select-sm" id="statut" name="statut">
                    <option value="">Tous</option>
                    <?php foreach (['active' => 'Actif', 'graduated' => 'Diplômé', 'transferred' => 'Transféré',
                                    'dropped' => 'Abandon', 'archived' => 'Archivé'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-1 col-md-4">
                <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filtrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Tableau -->
<div class="card">
    <?php if ($students === []): ?>
        <div class="card-body text-center py-5">
            <div class="empty-icon"><i class="bi bi-people"></i></div>
            <h2 class="h6 mt-3 mb-2">
                <?= $filters['q'] !== '' ? 'Aucun élève ne correspond à cette recherche' : 'Aucun élève inscrit' ?>
            </h2>
            <?php if ($filters['q'] === '' && can('student.create')): ?>
                <a href="<?= e(url('/eleves/nouveau', ['annee' => (int) $year['id']])) ?>" class="btn btn-primary mt-2">
                    <i class="bi bi-person-plus"></i> Inscrire le premier élève
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:110px">Matricule</th>
                        <th>Nom complet</th>
                        <th style="width:60px" class="text-center">Sexe</th>
                        <th style="width:110px">Naissance</th>
                        <th style="width:150px">Classe</th>
                        <th style="width:120px" class="text-center">Inscription</th>
                        <th style="width:70px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr class="<?= $student['status'] !== 'active' ? 'row-muted' : '' ?>">
                            <td><code><?= e($student['matricule']) ?></code></td>
                            <td>
                                <a href="<?= e(url('/eleves/' . (int) $student['id'])) ?>" class="fw-semibold">
                                    <?= e(full_name($student['last_name'], $student['post_name'], $student['first_name'])) ?>
                                </a>
                                <?php if ($student['status'] !== 'active'): ?>
                                    <span class="badge text-bg-secondary ms-1"><?= e($student['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge <?= $student['gender'] === 'F' ? 'text-bg-light' : 'text-bg-light' ?>">
                                    <?= e($student['gender']) ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= e(format_date($student['birth_date'])) ?: '—' ?></td>
                            <td>
                                <?php if ($student['classroom_name'] !== null): ?>
                                    <?= e($student['classroom_name']) ?>
                                <?php elseif ($student['enrollment_id'] !== null): ?>
                                    <span class="badge text-bg-warning">à affecter</span>
                                <?php else: ?>
                                    <span class="text-muted small">non inscrit</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php
                                $statuses = [
                                    'enrolled'      => ['text-bg-success', 'inscrit'],
                                    'admitted'      => ['text-bg-info', 'admis'],
                                    'pre_registered'=> ['text-bg-warning', 'préinscrit'],
                                    'cancelled'     => ['text-bg-secondary', 'annulé'],
                                ];
                                ?>
                                <?php if ($student['enrollment_status'] !== null): ?>
                                    <?php [$class, $label] = $statuses[$student['enrollment_status']] ?? ['text-bg-light', $student['enrollment_status']]; ?>
                                    <span class="badge <?= e($class) ?>"><?= e($label) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= e(url('/eleves/' . (int) $student['id'])) ?>"
                                   class="btn btn-sm btn-outline-secondary">Ouvrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="small text-muted">Page <?= $page ?> sur <?= $pages ?></span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= e(url('/eleves', $queryBase + ['page' => $page - 1])) ?>">‹</a>
                            </li>
                        <?php endif; ?>

                        <?php
                        // Fenêtre de 5 pages autour de la page courante :
                        // avec des milliers d'élèves, lister toutes les pages
                        // rendrait la pagination inutilisable.
                        $from = max(1, $page - 2);
                        $to   = min($pages, $from + 4);
                        ?>
                        <?php for ($i = $from; $i <= $to; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e(url('/eleves', $queryBase + ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= e(url('/eleves', $queryBase + ['page' => $page + 1])) ?>">›</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
