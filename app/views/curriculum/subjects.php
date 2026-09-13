<?php
/**
 * Gestion des branches de l'établissement.
 *
 * @var array $subjects
 * @var array $domains
 */
declare(strict_types=1);

set_title('Branches');

$byDomain = [];
foreach ($subjects as $subject) {
    $byDomain[$subject['domain_name'] ?? 'Sans domaine'][] = $subject;
}
?>

<div class="page-head">
    <div>
        <nav class="breadcrumb-mini">
            <a href="<?= e(url('/referentiel')) ?>">Référentiel</a><span>/</span>
        </nav>
        <h1 class="page-title">Branches</h1>
        <p class="page-subtitle">
            <?= count($subjects) ?> branche(s). Le maximum de points n'est pas défini ici :
            il dépend du niveau et de l'option, et se règle dans chaque programme.
        </p>
    </div>
</div>

<?php if ($subjects === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-3">Aucune branche enregistrée.</p>
            <a href="<?= e(url('/referentiel')) ?>" class="btn btn-primary">
                Importer le référentiel national
            </a>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($byDomain as $domainName => $list): ?>
        <div class="card mb-3">
            <div class="card-header">
                <h2 class="card-title"><?= e($domainName) ?></h2>
                <span class="text-muted small"><?= count($list) ?></span>
            </div>
            <div class="table-scroll">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:120px">Code</th>
                            <th>Nom</th>
                            <th style="width:160px">Libellé bulletin</th>
                            <th style="width:110px" class="text-center">Usages</th>
                            <th style="width:110px" class="text-center">État</th>
                            <?php if (can('curriculum.manage')): ?>
                                <th style="width:110px"></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $subject): ?>
                            <tr class="<?= (int) $subject['is_active'] === 0 ? 'row-muted' : '' ?>">
                                <td><code><?= e($subject['code']) ?></code></td>
                                <td><?= e($subject['name']) ?></td>
                                <td class="text-muted"><?= e($subject['short_name']) ?></td>
                                <td class="text-center">
                                    <?php if ((int) $subject['usage_count'] > 0): ?>
                                        <span class="badge text-bg-light"><?= (int) $subject['usage_count'] ?> programme(s)</span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?= (int) $subject['is_active'] === 1
                                        ? '<span class="badge text-bg-success">active</span>'
                                        : '<span class="badge text-bg-secondary">inactive</span>' ?>
                                </td>
                                <?php if (can('curriculum.manage')): ?>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#subject-<?= (int) $subject['id'] ?>">
                                            Modifier
                                        </button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                            <?php if (can('curriculum.manage')): ?>
                                <tr class="collapse" id="subject-<?= (int) $subject['id'] ?>">
                                    <td colspan="6" class="bg-light">
                                        <form method="post"
                                              action="<?= e(url('/referentiel/branches/' . (int) $subject['id'])) ?>"
                                              class="row g-2 align-items-end">
                                            <?= csrf_field() ?>
                                            <div class="col-md-4">
                                                <label class="form-label">Nom</label>
                                                <input type="text" class="form-control form-control-sm"
                                                       name="name" value="<?= e($subject['name']) ?>" required maxlength="150">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Libellé bulletin</label>
                                                <input type="text" class="form-control form-control-sm"
                                                       name="short_name" value="<?= e($subject['short_name']) ?>" required maxlength="50">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Domaine</label>
                                                <select class="form-select form-select-sm" name="domain_id">
                                                    <option value="">—</option>
                                                    <?php foreach ($domains as $domain): ?>
                                                        <option value="<?= (int) $domain['id'] ?>"
                                                            <?= (int) $domain['id'] === (int) $subject['domain_id'] ? 'selected' : '' ?>>
                                                            <?= e($domain['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                                           id="active-<?= (int) $subject['id'] ?>"
                                                           <?= (int) $subject['is_active'] === 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label small" for="active-<?= (int) $subject['id'] ?>">Active</label>
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-primary w-100">Enregistrer</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (can('curriculum.manage')): ?>
    <div class="card mt-4">
        <div class="card-header"><h2 class="card-title">Ajouter une branche</h2></div>
        <div class="card-body">
            <form method="post" action="<?= e(url('/referentiel/branches')) ?>" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-2">
                    <label class="form-label" for="code">Code</label>
                    <input type="text" class="form-control <?= has_error('code') ? 'is-invalid' : '' ?>"
                           id="code" name="code" value="<?= old('code') ?>"
                           placeholder="INFORMATIQUE" required maxlength="40">
                    <?php if (has_error('code')): ?>
                        <div class="invalid-feedback"><?= e(error_for('code')) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="name">Nom</label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= old('name') ?>" required maxlength="150">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="short_name">Libellé bulletin</label>
                    <input type="text" class="form-control" id="short_name" name="short_name"
                           value="<?= old('short_name') ?>" required maxlength="50">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="new_domain">Domaine</label>
                    <select class="form-select" id="new_domain" name="domain_id">
                        <option value="">—</option>
                        <?php foreach ($domains as $domain): ?>
                            <option value="<?= (int) $domain['id'] ?>"><?= e($domain['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
