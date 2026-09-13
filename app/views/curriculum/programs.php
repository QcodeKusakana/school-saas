<?php
/**
 * Liste des programmes d'une année scolaire.
 *
 * @var array $year
 * @var array $years
 * @var array $programs
 * @var array $levels
 * @var array $sections
 * @var array $options
 */
declare(strict_types=1);

set_title('Programmes scolaires');

// Options groupées par section, pour alimenter la liste déroulante
// dépendante sans appel réseau supplémentaire.
$optionsBySection = [];
foreach ($options as $option) {
    $optionsBySection[(int) $option['section_id']][] = [
        'id'   => (int) $option['id'],
        'name' => $option['name'],
    ];
}
?>

<div class="page-head">
    <div>
        <nav class="breadcrumb-mini">
            <a href="<?= e(url('/referentiel')) ?>">Référentiel</a><span>/</span>
        </nav>
        <h1 class="page-title">Programmes scolaires</h1>
        <p class="page-subtitle">
            Année <strong><?= e($year['code']) ?></strong> ·
            un programme par niveau, et un par option dans les humanités
        </p>
    </div>

    <?php if (count($years) > 1): ?>
        <form method="get" action="<?= e(url('/referentiel/programmes')) ?>">
            <select name="annee" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($years as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === (int) $year['id'] ? 'selected' : '' ?>>
                        <?= e($option['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if ($year['status'] === 'closed' || $year['status'] === 'archived'): ?>
    <div class="alert alert-secondary">
        <i class="bi bi-archive"></i>
        Cette année scolaire est <?= e($year['status'] === 'closed' ? 'clôturée' : 'archivée') ?> :
        ses programmes sont consultables mais ne peuvent plus être modifiés.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Programmes définis</h2>
        <span class="text-muted small"><?= count($programs) ?> programme(s)</span>
    </div>

    <?php if ($programs === []): ?>
        <div class="card-body text-center py-4">
            <p class="text-muted mb-0">Aucun programme pour cette année scolaire.</p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Niveau</th>
                        <th>Section / Option</th>
                        <th class="text-center">Branches</th>
                        <th class="text-center">Max / période</th>
                        <th class="text-center">État</th>
                        <th style="width:100px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($programs as $program): ?>
                        <tr>
                            <td>
                                <strong><?= e($program['level_short']) ?></strong>
                                <span class="d-block text-muted small"><?= e($program['cycle_short']) ?></span>
                            </td>
                            <td>
                                <?php if ($program['option_short'] !== null): ?>
                                    <?= e($program['section_short']) ?>
                                    <span class="d-block text-muted small"><?= e($program['option_short']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= (int) $program['subjects_count'] ?></td>
                            <td class="text-center fw-semibold"><?= (int) $program['total_max'] ?> pts</td>
                            <td class="text-center">
                                <?php if ($program['status'] === 'active'): ?>
                                    <span class="badge text-bg-success">actif</span>
                                <?php elseif ($program['status'] === 'archived'): ?>
                                    <span class="badge text-bg-secondary">archivé</span>
                                <?php else: ?>
                                    <span class="badge text-bg-warning">brouillon</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= e(url('/referentiel/programmes/' . (int) $program['id'])) ?>"
                                   class="btn btn-sm btn-outline-secondary">Ouvrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (can('curriculum.manage') && $year['status'] === 'active' || $year['status'] === 'draft'): ?>
    <?php if (can('curriculum.manage')): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h2 class="card-title">Nouveau programme</h2>
            </div>
            <div class="card-body">
                <?php if ($levels === []): ?>
                    <p class="text-muted mb-0">
                        Aucun cycle activé pour votre établissement.
                        Activez d'abord les cycles enseignés dans les paramètres de l'école.
                    </p>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/referentiel/programmes')) ?>" class="row g-3 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">

                        <div class="col-md-4">
                            <label class="form-label" for="education_level_id">Niveau scolaire</label>
                            <select class="form-select" id="education_level_id" name="education_level_id" required
                                    data-level-select>
                                <option value="">— Choisir —</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= (int) $level['id'] ?>"
                                            data-requires-option="<?= (int) $level['requires_option'] ?>">
                                        <?= e($level['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4" data-option-block hidden>
                            <label class="form-label" for="section_id">Section</label>
                            <select class="form-select" id="section_id" name="section_id" data-section-select>
                                <option value="">— Choisir —</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?= (int) $section['id'] ?>"><?= e($section['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4" data-option-block hidden>
                            <label class="form-label" for="option_id">Option</label>
                            <select class="form-select" id="option_id" name="option_id" data-option-select>
                                <option value="">— Choisir une section —</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Créer le programme
                            </button>
                            <span class="form-text d-block mt-1">
                                Les humanités exigent une section et une option ; le primaire et le CTEB n'en ont pas.
                            </span>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?= script_tag('', 'window.__optionsBySection = ' . e_js($optionsBySection) . ';' . <<<'JS'

    (function () {
        var levelSelect   = document.querySelector('[data-level-select]');
        var sectionSelect = document.querySelector('[data-section-select]');
        var optionSelect  = document.querySelector('[data-option-select]');
        var blocks        = document.querySelectorAll('[data-option-block]');

        if (!levelSelect) {
            return;
        }

        // Section et option n'apparaissent que pour les niveaux qui les
        // exigent — les humanités. Inutile de les montrer en primaire.
        function refreshVisibility() {
            var selected = levelSelect.options[levelSelect.selectedIndex];
            var required = selected && selected.getAttribute('data-requires-option') === '1';

            blocks.forEach(function (block) {
                block.hidden = !required;
            });

            if (sectionSelect) { sectionSelect.required = required; }
            if (optionSelect)  { optionSelect.required  = required; }
        }

        function refreshOptions() {
            if (!optionSelect || !sectionSelect) {
                return;
            }

            var list = window.__optionsBySection[sectionSelect.value] || [];
            optionSelect.innerHTML = '<option value="">— Choisir —</option>';

            list.forEach(function (option) {
                var element = document.createElement('option');
                element.value = option.id;
                element.textContent = option.name;
                optionSelect.appendChild(element);
            });
        }

        levelSelect.addEventListener('change', refreshVisibility);
        if (sectionSelect) { sectionSelect.addEventListener('change', refreshOptions); }

        refreshVisibility();
    })();
JS) ?>
