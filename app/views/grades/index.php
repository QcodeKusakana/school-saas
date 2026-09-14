<?php
/**
 * Accueil du module Notes.
 *
 * Deux publics, deux lectures du même écran :
 *  · l'enseignant voit SON service — ce qu'il a à saisir ;
 *  · la direction voit les classes et l'état des périodes.
 *
 * @var array|null $year
 * @var array      $years
 * @var array      $periods
 * @var array      $workload
 * @var array      $classrooms
 * @var bool       $isTeacher
 */
declare(strict_types=1);

set_title('Notes');
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Notes</h1>
        <p class="page-subtitle">
            <?php if ($year !== null): ?>
                Année <strong><?= e($year['code']) ?></strong>
                · <?= count($periods) ?> période(s)
            <?php else: ?>
                Aucune année scolaire définie.
            <?php endif; ?>
        </p>
    </div>

    <?php if (count($years) > 1 && $year !== null): ?>
        <form method="get" action="<?= e(url('/notes')) ?>">
            <select name="annee" class="form-select form-select-sm" data-auto-submit>
                <?php foreach ($years as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === (int) $year['id'] ? 'selected' : '' ?>>
                        <?= e($option['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary ms-1" data-auto-submit-fallback>OK</button>
        </form>
    <?php endif; ?>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($year === null): ?>
    <div class="alert alert-info">
        Créez d'abord une année scolaire et ses périodes depuis
        <a href="<?= e(url('/referentiel')) ?>">le référentiel</a>.
    </div>
<?php else: ?>

    <!-- -----------------------------------------------------------------
         Mon service — visible dès que l'utilisateur est enseignant
    ------------------------------------------------------------------ -->
    <?php if ($isTeacher): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Mon service</strong>
                <span class="small text-secondary"><?= count($workload) ?> branche(s)</span>
            </div>

            <?php if ($workload === []): ?>
                <div class="card-body text-center py-4">
                    <p class="mb-1">Aucune branche ne vous est confiée pour cette année.</p>
                    <p class="small text-secondary mb-0">
                        La répartition est faite par le préfet des études, classe par classe.
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Classe</th>
                                <th scope="col">Branche</th>
                                <th scope="col" class="text-center d-none d-sm-table-cell">Élèves</th>
                                <th scope="col">Périodes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workload as $item): ?>
                                <tr>
                                    <td>
                                        <span class="fw-medium"><?= e($item['classroom_code']) ?></span>
                                        <div class="small text-secondary d-none d-md-block">
                                            <?= e($item['classroom_name']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?= e($item['subject_name']) ?>
                                        <span class="small text-secondary">/ <?= (int) $item['max_points'] ?></span>
                                    </td>
                                    <td class="text-center d-none d-sm-table-cell">
                                        <?= (int) $item['student_count'] ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php foreach ($periods as $p): ?>
                                                <a class="btn btn-sm <?= (int) $p['is_locked'] === 1 ? 'btn-outline-secondary' : 'btn-outline-primary' ?>"
                                                   href="<?= e(url('/notes/classe/' . (int) $item['classroom_id']
                                                       . '/branche/' . (int) $item['curriculum_subject_id']
                                                       . '/periode/' . (int) $p['id'])) ?>"
                                                   title="<?= e($p['name']) ?><?= (int) $p['is_locked'] === 1 ? ' (verrouillée)' : '' ?>">
                                                    <?= e($p['code']) ?>
                                                    <?php if ((int) $p['is_locked'] === 1): ?>
                                                        <i class="bi bi-lock-fill ms-1"></i>
                                                    <?php endif; ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- -----------------------------------------------------------------
         Périodes et verrous — direction uniquement
    ------------------------------------------------------------------ -->
    <?php if (can('grade.validate')): ?>
        <div class="card mb-3">
            <div class="card-header"><strong>Périodes de l'année</strong></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Période</th>
                            <th scope="col" class="text-center">Poids</th>
                            <th scope="col" class="text-center">État</th>
                            <th scope="col" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periods as $p): ?>
                            <tr>
                                <td>
                                    <span class="fw-medium"><?= e($p['name']) ?></span>
                                    <span class="small text-secondary ms-1"><?= e($p['code']) ?></span>
                                    <?php if ($p['period_type'] === 'exam'): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis ms-1">examen</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">× <?= e(grades_format((float) $p['max_multiplier'])) ?></td>
                                <td class="text-center">
                                    <?php if ((int) $p['is_locked'] === 1): ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">
                                            <i class="bi bi-lock-fill me-1"></i>verrouillée
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success-emphasis">ouverte</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="post"
                                          action="<?= e(url('/notes/periodes/' . (int) $p['id'] . '/verrou')) ?>"
                                          data-confirm="<?= (int) $p['is_locked'] === 1
                                              ? 'Rouvrir cette période à la saisie ?'
                                              : 'Verrouiller cette période ? Les enseignants ne pourront plus modifier leurs cotes.' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="annee" value="<?= (int) $year['id'] ?>">
                                        <input type="hidden" name="action"
                                               value="<?= (int) $p['is_locked'] === 1 ? 'unlock' : 'lock' ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <?= (int) $p['is_locked'] === 1 ? 'Déverrouiller' : 'Verrouiller' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><strong>Classes</strong></div>
            <?php if ($classrooms === []): ?>
                <div class="card-body text-secondary">Aucune classe pour cette année.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($classrooms as $classroom): ?>
                        <a href="<?= e(url('/notes/classe/' . (int) $classroom['id'])) ?>"
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <span>
                                <span class="fw-medium"><?= e($classroom['name']) ?></span>
                                <span class="small text-secondary ms-1"><?= e($classroom['code']) ?></span>
                            </span>
                            <span class="small text-secondary">
                                <?= (int) $classroom['student_count'] ?> élève(s)
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$isTeacher && !can('grade.validate')): ?>
        <div class="alert alert-info">
            Votre compte n'est rattaché à aucune fiche enseignant.
            La saisie des notes s'appuie sur cette fiche : demandez au
            secrétariat de faire le rattachement.
        </div>
    <?php endif; ?>
<?php endif; ?>
