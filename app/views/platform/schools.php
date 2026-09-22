<?php
/**
 * CONSOLE — les établissements.
 *
 * L'éditeur voit ici son parc : qui est actif, qui est sur quelle
 * offre, qui approche de son plafond. Et il peut entrer dans une école
 * pour la dépanner.
 *
 * @var array  $schools
 * @var array  $filters
 * @var ?array $visiting
 */
declare(strict_types=1);

set_title('Établissements');

$statusLabels = [
    'pending'   => 'En attente',
    'active'    => 'Actif',
    'suspended' => 'Suspendu',
    'cancelled' => 'Résilié',
];

$statusClass = [
    'pending'   => 'bg-warning-subtle text-warning-emphasis',
    'active'    => 'bg-success-subtle text-success-emphasis',
    'suspended' => 'bg-danger-subtle text-danger-emphasis',
    'cancelled' => 'bg-secondary-subtle text-secondary-emphasis',
];
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Établissements</h1>
        <p class="page-subtitle">
            <?= count($schools) ?> établissement<?= count($schools) > 1 ? 's' : '' ?>
            <?= $filters['q'] !== '' || $filters['status'] !== '' ? 'correspondant au filtre' : 'au total' ?>
        </p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(url('/plateforme/ecoles')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label class="form-label small" for="q">Rechercher</label>
                <input type="search" class="form-control" id="q" name="q"
                       value="<?= e($filters['q']) ?>"
                       placeholder="Nom, code ou ville">
            </div>
            <div class="col-8 col-md-4">
                <label class="form-label small" for="status">Statut</label>
                <select class="form-select" id="status" name="status" data-auto-submit>
                    <option value="">Tous</option>
                    <?php foreach ($statusLabels as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $filters['status'] === $code ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-md-2">
                <button class="btn btn-primary w-100" type="submit">Filtrer</button>
            </div>
        </form>
    </div>
</div>

<?php if ($schools === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="fw-medium mb-1">Aucun établissement ne correspond.</p>
            <p class="text-secondary mb-0">Élargissez la recherche ou changez le filtre de statut.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Établissement</th>
                        <th scope="col" class="d-none d-md-table-cell">Ville</th>
                        <th scope="col">Statut</th>
                        <th scope="col">Offre</th>
                        <th scope="col" class="text-end">Élèves</th>
                        <th scope="col" class="d-none d-lg-table-cell">Échéance</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schools as $s): ?>
                        <?php
                        $limit = $s['max_students_override'] !== null
                            ? (int) $s['max_students_override']
                            : ($s['plan_max_students'] !== null ? (int) $s['plan_max_students'] : null);

                        $students = (int) $s['students'];
                        $full     = $limit !== null && $students >= $limit;
                        $isHere   = $visiting !== null && (int) $visiting['id'] === (int) $s['id'];
                        ?>
                        <tr<?= $isHere ? ' class="table-active"' : '' ?>>
                            <td>
                                <a href="<?= e(url('/plateforme/ecoles/' . (int) $s['id'])) ?>"
                                   class="fw-medium text-decoration-none">
                                    <?= e((string) $s['name']) ?>
                                </a>
                                <div class="small text-secondary"><?= e((string) $s['code']) ?></div>
                            </td>
                            <td class="d-none d-md-table-cell small">
                                <?= e((string) ($s['city'] ?? '—')) ?>
                            </td>
                            <td>
                                <span class="badge <?= e($statusClass[$s['status']] ?? 'bg-secondary-subtle') ?>">
                                    <?= e($statusLabels[$s['status']] ?? (string) $s['status']) ?>
                                </span>
                            </td>
                            <td class="small">
                                <?php if ($s['plan_name'] === null): ?>
                                    <!-- PAS D'ABONNEMENT EN COURS : c'est une décision à prendre,
                                         pas une case vide. -->
                                    <span class="text-danger-emphasis fw-medium">aucun</span>
                                <?php else: ?>
                                    <?= e((string) $s['plan_name']) ?>
                                    <?php if ($s['subscription_status'] !== 'active'): ?>
                                        <span class="text-secondary">(<?= e((string) $s['subscription_status']) ?>)</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end <?= $full ? 'text-danger-emphasis fw-medium' : '' ?>">
                                <?= $students ?><?= $limit !== null ? ' / ' . $limit : '' ?>
                            </td>
                            <td class="d-none d-lg-table-cell small">
                                <?= $s['subscription_ends_on'] !== null
                                    ? e(date('d/m/Y', strtotime((string) $s['subscription_ends_on'])))
                                    : '—' ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ($isHere): ?>
                                    <span class="badge bg-primary-subtle text-primary-emphasis">vous y êtes</span>
                                <?php else: ?>
                                    <form method="post"
                                          action="<?= e(url('/plateforme/ecoles/' . (int) $s['id'] . '/ouvrir')) ?>"
                                          class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-primary" type="submit">
                                            Ouvrir
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    /*
     * CE QUE « OUVRIR » VEUT DIRE, ÉCRIT NOIR SUR BLANC.
     * Un éditeur qui entre chez un client doit savoir que la visite est
     * tracée — et l'école doit pouvoir le lui demander.
     */
    ?>
    <p class="small text-secondary mt-3 mb-0">
        « Ouvrir » vous place dans l'établissement pour y travailler.
        <strong>Chaque entrée et chaque sortie sont journalisées</strong>, et un
        bandeau reste affiché tant que vous y êtes.
    </p>
<?php endif; ?>
