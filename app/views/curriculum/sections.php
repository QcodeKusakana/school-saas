<?php
/**
 * Sections et options de l'établissement.
 *
 * Désactiver une section la retire des choix proposés à la création
 * d'un programme, sans supprimer les programmes existants.
 *
 * @var array $sections
 * @var array $options
 */
declare(strict_types=1);

set_title('Sections et options');

$optionsBySection = [];
foreach ($options as $option) {
    $optionsBySection[(int) $option['section_id']][] = $option;
}
?>

<div class="page-head">
    <div>
        <nav class="breadcrumb-mini">
            <a href="<?= e(url('/referentiel')) ?>">Référentiel</a><span>/</span>
        </nav>
        <h1 class="page-title">Sections et options</h1>
        <p class="page-subtitle">
            N'activez que les filières réellement ouvertes dans votre établissement.
        </p>
    </div>
</div>

<?php if ($sections === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-3">Aucune section enregistrée.</p>
            <a href="<?= e(url('/referentiel')) ?>" class="btn btn-primary">
                Importer le référentiel national
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($sections as $section): ?>
            <?php $sectionOptions = $optionsBySection[(int) $section['id']] ?? []; ?>
            <div class="col-lg-6">
                <div class="card h-100 <?= (int) $section['is_active'] === 0 ? 'card-muted' : '' ?>">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title mb-0"><?= e($section['name']) ?></h2>
                            <span class="text-muted small"><?= e($section['cycle_name']) ?></span>
                        </div>
                        <?php if (can('curriculum.manage')): ?>
                            <form method="post"
                                  action="<?= e(url('/referentiel/sections/' . (int) $section['id'] . '/etat')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit"
                                        class="btn btn-sm <?= (int) $section['is_active'] === 1 ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                                    <?= (int) $section['is_active'] === 1 ? 'Désactiver' : 'Activer' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <?= (int) $section['is_active'] === 1
                                ? '<span class="badge text-bg-success">active</span>'
                                : '<span class="badge text-bg-secondary">inactive</span>' ?>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($sectionOptions === []): ?>
                            <p class="text-muted small mb-0">Aucune option rattachée.</p>
                        <?php else: ?>
                            <ul class="plain-list">
                                <?php foreach ($sectionOptions as $option): ?>
                                    <li class="<?= (int) $option['is_active'] === 0 ? 'row-muted' : '' ?>">
                                        <span>
                                            <?= e($option['name']) ?>
                                            <code class="small ms-1"><?= e($option['code']) ?></code>
                                        </span>
                                        <?php if (can('curriculum.manage')): ?>
                                            <form method="post" class="d-inline"
                                                  action="<?= e(url('/referentiel/options/' . (int) $option['id'] . '/etat')) ?>">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm btn-link p-0 small">
                                                    <?= (int) $option['is_active'] === 1 ? 'désactiver' : 'activer' ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
