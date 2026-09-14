<?php
/**
 * Finances — état de l'année, classe par classe.
 *
 * L'écran répond d'abord à UNE question : QUI N'A PAS ÉTÉ FACTURÉ.
 * Un inscrit sans dette affectée ne doit jamais ressembler à un élève
 * en règle — c'est le piège de l'appel partiel (phase 4D) transposé
 * aux finances, et il se referme de la même manière : en comptant ce
 * qui manque, pas ce qui existe.
 *
 * @var array|null $year
 * @var array      $years
 * @var array      $classrooms
 * @var array      $enrollments  Périmètre par élève, quand aucune classe n'est visible
 * @var int        $feeCount
 */
declare(strict_types=1);

set_title('Finances');

$without = array_filter($classrooms, static fn (array $c): bool => (int) $c['without_fees'] > 0);
$missing = array_sum(array_map(static fn (array $c): int => (int) $c['without_fees'], $classrooms));
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Finances</h1>
        <p class="page-subtitle">
            <?php if ($year !== null): ?>
                Année <?= e($year['code']) ?> · <?= $feeCount ?> frais actif(s)
            <?php else: ?>
                Aucune année scolaire active
            <?php endif; ?>
        </p>
    </div>

    <div class="d-flex gap-2">
        <?php if (count($years) > 1): ?>
            <form method="get" action="<?= e(url('/finances')) ?>" class="d-flex gap-2">
                <select name="annee" class="form-select form-select-sm" data-auto-submit
                        aria-label="Année scolaire">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int) $y['id'] ?>"
                            <?= $year !== null && (int) $y['id'] === (int) $year['id'] ? 'selected' : '' ?>>
                            <?= e($y['code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-outline-secondary"
                        data-auto-submit-fallback>Voir</button>
            </form>
        <?php endif; ?>

        <?php if (can('fee.manage') && $year !== null): ?>
            <a href="<?= e(url('/finances/frais', ['annee' => (int) $year['id']])) ?>"
               class="btn btn-outline-secondary">
                <i class="bi bi-list-columns me-1"></i> Grille tarifaire
            </a>
        <?php endif; ?>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($year === null): ?>
    <div class="alert alert-warning">
        Aucune année scolaire n'est marquée comme courante. Les frais se
        rattachent à une année : commencez par en activer une.
    </div>
<?php elseif ($feeCount === 0): ?>
    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="bi bi-info-circle"></i>
        <div>
            <strong>La grille tarifaire est vide pour <?= e($year['code']) ?>.</strong>
            Tant qu'aucun frais n'est défini, aucune dette ne peut être
            affectée — et l'état des impayés restera muet.
            <?php if (can('fee.manage')): ?>
                <a href="<?= e(url('/finances/frais', ['annee' => (int) $year['id']])) ?>">Définir les frais</a>.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($classrooms === [] && $enrollments !== []): ?>
    <!--
        LA PORTE DU PARENT.

        Un tuteur détient finance.view mais n'a aucune classe : le tableau
        par classe lui renvoyait une page vide, et l'entrée de menu ne
        menait donc nulle part. Son périmètre passe par l'élève.
    -->
    <div class="card">
        <div class="card-header fw-medium">
            <?= count($enrollments) > 1 ? 'Vos enfants' : 'Votre enfant' ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Élève</th>
                        <th scope="col" class="d-none d-md-table-cell">Classe</th>
                        <th scope="col" class="text-center">Frais</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enrollments as $row): ?>
                        <tr>
                            <td>
                                <span class="fw-medium">
                                    <?= e(full_name($row['last_name'], $row['post_name'], $row['first_name'])) ?>
                                </span>
                                <div class="small text-secondary"><?= e((string) $row['matricule']) ?></div>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?= e((string) ($row['classroom_name'] ?? '—')) ?>
                            </td>
                            <td class="text-center">
                                <?php if ((int) $row['fee_count'] === 0): ?>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">aucun</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success-emphasis">
                                        <?= (int) $row['fee_count'] ?>
                                    </span>
                                <?php endif; ?>
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
<?php elseif ($classrooms === []): ?>
    <div class="alert alert-info">Aucune classe ni élève dans votre périmètre pour cette année.</div>
<?php else: ?>
    <?php if ($missing > 0): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-octagon"></i>
            <div>
                <strong><?= (int) $missing ?> inscrit(s)</strong> n'ont aucun frais affecté,
                dans <?= count($without) ?> classe(s).
                Leur dette est nulle parce qu'elle n'a jamais été créée —
                et non parce qu'ils sont à jour.
                <?php if (can('fee.manage')): ?>
                    <form method="post" action="<?= e(url('/finances/affecter')) ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">
                        <input type="hidden" name="retour" value="/finances?annee=<?= (int) $year['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">
                            <i class="bi bi-arrow-repeat me-1"></i>
                            Affecter les frais de l'année à tous les inscrits
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Classe</th>
                        <th scope="col" class="text-center">Inscrits</th>
                        <th scope="col" class="text-center">Facturation</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classrooms as $row): ?>
                        <?php
                        $total   = (int) $row['student_count'];
                        $gap     = (int) $row['without_fees'];
                        $covered = $total - $gap;
                        ?>
                        <tr>
                            <td>
                                <span class="fw-medium"><?= e($row['name']) ?></span>
                                <span class="small text-secondary"><?= e((string) ($row['level_short'] ?? '')) ?></span>
                            </td>
                            <td class="text-center"><?= $total ?></td>
                            <td class="text-center">
                                <?php if ($total === 0): ?>
                                    <span class="text-secondary">—</span>
                                <?php elseif ($gap === 0): ?>
                                    <span class="badge bg-success-subtle text-success-emphasis">complète</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis"
                                          title="<?= $covered ?> facturé(s) sur <?= $total ?>">
                                        <?= $covered ?>/<?= $total ?> facturés
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(url('/finances/classe/' . (int) $row['classroom_id'])) ?>">
                                    Voir
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
