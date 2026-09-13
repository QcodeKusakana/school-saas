<?php
/**
 * Édition d'un programme : branches et maxima.
 *
 * C'est l'écran central de la phase 2. Le maximum saisi ici détermine
 * la pondération de la branche dans le bulletin : une branche sur 40
 * pèse deux fois plus qu'une branche sur 20.
 *
 * @var array $program
 * @var array $subjects
 * @var array $available
 * @var array $periods
 * @var array $issues
 * @var array $years
 */
declare(strict_types=1);

set_title($program['name']);

$editable   = $program['status'] !== 'archived';
$totalMax   = 0;
$rankedMax  = 0;

foreach ($subjects as $row) {
    if ((int) $row['is_optional'] === 0) {
        $totalMax += (int) $row['max_points'];

        if ((int) $row['counts_for_ranking'] === 1) {
            $rankedMax += (int) $row['max_points'];
        }
    }
}

// Multiplicateur cumulé de l'année : somme des poids de toutes les
// périodes. Avec la structure standard (1+1+2+1+1+2), il vaut 8.
$yearMultiplier = 0.0;

foreach ($periods as $period) {
    $yearMultiplier += (float) $period['max_multiplier'];
}
?>

<div class="page-head">
    <div>
        <nav class="breadcrumb-mini">
            <a href="<?= e(url('/referentiel')) ?>">Référentiel</a>
            <span>/</span>
            <a href="<?= e(url('/referentiel/programmes', ['annee' => (int) $program['academic_year_id']])) ?>">Programmes</a>
        </nav>
        <h1 class="page-title"><?= e($program['name']) ?></h1>
        <p class="page-subtitle">
            <?= e($program['cycle_name']) ?> · Année <?= e($program['year_code']) ?>
            <?php if ($program['section_name'] !== null): ?>
                · <?= e($program['section_name']) ?>
            <?php endif; ?>
            <?php if ($program['status'] === 'active'): ?>
                <span class="badge text-bg-success ms-1">actif</span>
            <?php elseif ($program['status'] === 'archived'): ?>
                <span class="badge text-bg-secondary ms-1">archivé</span>
            <?php else: ?>
                <span class="badge text-bg-warning ms-1">brouillon</span>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($editable && can('curriculum.manage')): ?>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($program['status'] === 'draft'): ?>
                <form method="post" action="<?= e(url('/referentiel/programmes/' . (int) $program['id'] . '/activer')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary" <?= $issues !== [] ? 'disabled' : '' ?>>
                        <i class="bi bi-check2-circle"></i> Activer
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($years !== []): ?>
                <button class="btn btn-outline-secondary" type="button"
                        data-bs-toggle="collapse" data-bs-target="#duplicate-panel">
                    <i class="bi bi-files"></i> Dupliquer
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($years !== [] && $editable && can('curriculum.manage')): ?>
    <div class="collapse mb-3" id="duplicate-panel">
        <div class="card">
            <div class="card-body">
                <form method="post" action="<?= e(url('/referentiel/programmes/' . (int) $program['id'] . '/dupliquer')) ?>"
                      class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-md-6">
                        <label class="form-label" for="target_year_id">Copier vers l'année scolaire</label>
                        <select class="form-select" id="target_year_id" name="target_year_id" required>
                            <?php foreach ($years as $targetYear): ?>
                                <option value="<?= (int) $targetYear['id'] ?>"><?= e($targetYear['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-primary">Dupliquer le programme</button>
                        <span class="form-text d-block">
                            Les branches et leurs maxima sont recopiés. Le programme d'origine reste intact.
                        </span>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($issues !== []): ?>
    <div class="alert alert-warning">
        <strong><i class="bi bi-exclamation-triangle-fill"></i> À corriger avant activation</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($issues as $issue): ?>
                <li><?= e($issue) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($subjects === []): ?>

    <div class="card">
        <div class="card-body text-center py-5">
            <div class="empty-icon"><i class="bi bi-journal-plus"></i></div>
            <h2 class="h5 mt-3 mb-2">Aucune branche dans ce programme</h2>
            <p class="text-muted mx-auto" style="max-width:520px">
                Le plus rapide est d'ajouter toutes les branches actives d'un coup,
                avec des maxima par défaut que vous ajusterez ensuite ligne par ligne.
            </p>
            <?php if ($editable && can('curriculum.manage')): ?>
                <form method="post" action="<?= e(url('/referentiel/programmes/' . (int) $program['id'] . '/remplir')) ?>" class="mt-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-magic"></i> Ajouter toutes les branches
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>

    <!-- --------------------------------------------------------------
         Récapitulatif des maxima
    --------------------------------------------------------------- -->
    <div class="max-summary">
        <div>
            <span class="max-summary-label">Maximum par période</span>
            <span class="max-summary-value"><?= number_format($totalMax, 0, ',', ' ') ?> pts</span>
        </div>
        <div>
            <span class="max-summary-label">Comptant pour le rang</span>
            <span class="max-summary-value"><?= number_format($rankedMax, 0, ',', ' ') ?> pts</span>
        </div>
        <div>
            <span class="max-summary-label">Total général de l'année</span>
            <span class="max-summary-value">
                <?= $yearMultiplier > 0
                    ? number_format($totalMax * $yearMultiplier, 0, ',', ' ') . ' pts'
                    : '—' ?>
            </span>
            <?php if ($yearMultiplier > 0): ?>
                <span class="max-summary-hint">
                    <?= number_format($totalMax, 0, ',', ' ') ?> × <?= rtrim(rtrim(number_format($yearMultiplier, 1, ',', ''), '0'), ',') ?>
                    (<?= count($periods) ?> périodes)
                </span>
            <?php else: ?>
                <span class="max-summary-hint text-danger">Périodes non définies</span>
            <?php endif; ?>
        </div>
        <div>
            <span class="max-summary-label">Branches</span>
            <span class="max-summary-value"><?= count($subjects) ?></span>
        </div>
    </div>

    <!-- --------------------------------------------------------------
         Tableau des branches
    --------------------------------------------------------------- -->
    <form method="post" action="<?= e(url('/referentiel/programmes/' . (int) $program['id'] . '/maxima')) ?>">
        <?= csrf_field() ?>

        <div class="card mt-3">
            <div class="card-header">
                <h2 class="card-title">Branches et maxima</h2>
                <?php if ($editable && can('curriculum.manage')): ?>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-save"></i> Enregistrer
                    </button>
                <?php endif; ?>
            </div>

            <div class="table-scroll">
                <table class="table table-hover align-middle mb-0 subjects-table">
                    <thead>
                        <tr>
                            <th style="width:60px">Ordre</th>
                            <th>Branche</th>
                            <th style="width:120px">Domaine</th>
                            <th style="width:110px" class="text-center">
                                Maximum
                                <i class="bi bi-info-circle text-muted" title="Points par période. L'examen vaut le double."></i>
                            </th>
                            <th style="width:100px" class="text-center">H/sem.</th>
                            <th style="width:90px" class="text-center">Facult.</th>
                            <th style="width:90px" class="text-center">Rang</th>
                            <?php if ($editable && can('curriculum.manage')): ?>
                                <th style="width:50px"></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $row): ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td>
                                    <input type="number" class="form-control form-control-sm text-center"
                                           name="subjects[<?= $rowId ?>][order_number]"
                                           value="<?= (int) $row['order_number'] ?>"
                                           min="0" max="9999"
                                           <?= $editable ? '' : 'disabled' ?>>
                                </td>
                                <td>
                                    <strong><?= e($row['subject_name']) ?></strong>
                                    <span class="d-block text-muted small">
                                        <?= e($row['subject_short']) ?> · <code><?= e($row['subject_code']) ?></code>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge text-bg-light"><?= e($row['domain_short'] ?: '—') ?></span>
                                </td>
                                <td>
                                    <input type="number" class="form-control form-control-sm text-center fw-semibold"
                                           name="subjects[<?= $rowId ?>][max_points]"
                                           value="<?= (int) $row['max_points'] ?>"
                                           min="1" max="200" required
                                           <?= $editable ? '' : 'disabled' ?>>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm text-center"
                                           name="subjects[<?= $rowId ?>][weekly_hours]"
                                           value="<?= $row['weekly_hours'] !== null ? e(rtrim(rtrim((string) $row['weekly_hours'], '0'), '.')) : '' ?>"
                                           inputmode="decimal" placeholder="—"
                                           <?= $editable ? '' : 'disabled' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input"
                                           name="subjects[<?= $rowId ?>][is_optional]" value="1"
                                           <?= (int) $row['is_optional'] === 1 ? 'checked' : '' ?>
                                           <?= $editable ? '' : 'disabled' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input"
                                           name="subjects[<?= $rowId ?>][counts_for_ranking]" value="1"
                                           <?= (int) $row['counts_for_ranking'] === 1 ? 'checked' : '' ?>
                                           <?= $editable ? '' : 'disabled' ?>>
                                </td>
                                <?php if ($editable && can('curriculum.manage')): ?>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0"
                                                data-remove-subject="<?= $rowId ?>"
                                                data-subject-name="<?= e($row['subject_name']) ?>"
                                                title="Retirer du programme">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($editable && can('curriculum.manage')): ?>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="small text-muted">
                        « Facult. » exclut la branche du total. « Rang » l'inclut dans le calcul du classement.
                    </span>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Enregistrer les modifications
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Formulaires de retrait, hors du formulaire principal pour ne pas l'imbriquer -->
    <?php if ($editable && can('curriculum.manage')): ?>
        <?php foreach ($subjects as $row): ?>
            <form method="post" class="d-none"
                  id="remove-form-<?= (int) $row['id'] ?>"
                  action="<?= e(url('/referentiel/programmes/' . (int) $program['id'] . '/branches/' . (int) $row['id'] . '/retirer')) ?>">
                <?= csrf_field() ?>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>

<?php endif; ?>

<!-- ------------------------------------------------------------------
     Ajout d'une branche
------------------------------------------------------------------- -->
<?php if ($editable && can('curriculum.manage') && $available !== []): ?>
    <div class="card mt-4">
        <div class="card-header">
            <h2 class="card-title">Ajouter une branche</h2>
            <span class="text-muted small"><?= count($available) ?> disponible(s)</span>
        </div>
        <div class="card-body">
            <form method="post" action="<?= e(url('/referentiel/programmes/' . (int) $program['id'] . '/branches')) ?>"
                  class="row g-2 align-items-end">
                <?= csrf_field() ?>

                <div class="col-md-5">
                    <label class="form-label" for="subject_id">Branche</label>
                    <select class="form-select" id="subject_id" name="subject_id" required>
                        <option value="">— Choisir —</option>
                        <?php foreach ($available as $subject): ?>
                            <option value="<?= (int) $subject['id'] ?>">
                                <?= e($subject['name']) ?><?= $subject['domain_short'] ? ' (' . e($subject['domain_short']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="max_points">Maximum par période</label>
                    <input type="number" class="form-control" id="max_points" name="max_points"
                           value="20" min="1" max="200" required>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="weekly_hours">Heures / semaine</label>
                    <input type="text" class="form-control" id="weekly_hours" name="weekly_hours"
                           inputmode="decimal" placeholder="—">
                </div>

                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-plus-lg"></i> Ajouter
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?= script_tag('', <<<'JS'
    // Retrait d'une branche : confirmation puis soumission du formulaire
    // caché correspondant. Les formulaires sont hors du tableau, car un
    // formulaire imbriqué dans un autre est invalide en HTML.
    document.querySelectorAll('[data-remove-subject]').forEach(function (button) {
        button.addEventListener('click', function () {
            var id = button.getAttribute('data-remove-subject');
            var name = button.getAttribute('data-subject-name');

            if (window.confirm('Retirer « ' + name + ' » de ce programme ?')) {
                document.getElementById('remove-form-' + id).submit();
            }
        });
    });
JS) ?>
