<?php
/**
 * Grille tarifaire d'une année.
 *
 * L'écran assume une règle qui surprend : MODIFIER UN TARIF NE CHANGE
 * AUCUNE DETTE DÉJÀ AFFECTÉE. Plutôt que de la cacher, il l'affiche, et
 * propose l'action explicite qui la lève — en annonçant d'abord combien
 * de lignes elle toucherait.
 *
 * @var array  $year
 * @var array  $years
 * @var array  $fees
 * @var array  $drift       [fee_id => nombre de dettes divergentes]
 * @var array  $currencies
 * @var array  $scopes
 * @var array  $levels
 * @var array  $classrooms
 * @var string $defaultCurrency
 */
declare(strict_types=1);

set_title('Grille tarifaire');

$edit = null;

foreach ($fees as $f) {
    if ((int) $f['id'] === (int) input('modifier', 0)) {
        $edit = $f;
    }
}
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Grille tarifaire</h1>
        <p class="page-subtitle">
            Année <?= e($year['code']) ?> · <?= count($fees) ?> ligne(s)
        </p>
    </div>

    <div class="d-flex gap-2">
        <?php if (count($years) > 1): ?>
            <form method="get" action="<?= e(url('/finances/frais')) ?>">
                <select name="annee" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int) $y['id'] ?>"
                            <?= (int) $y['id'] === (int) $year['id'] ? 'selected' : '' ?>>
                            <?= e($y['code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <a href="<?= e(url('/finances', ['annee' => (int) $year['id']])) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> État de l'année
        </a>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="row g-3">
    <!-- ============================ LA GRILLE ============================ -->
    <div class="col-12 col-xl-8">
        <?php if ($fees === []): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-receipt display-6 text-secondary d-block mb-2"></i>
                    <p class="mb-1 fw-medium">Aucun frais défini pour <?= e($year['code']) ?>.</p>
                    <p class="mb-0 small text-secondary">
                        Une ligne par montant réclamé — les tranches comprises.
                        « Minerval 1<sup>re</sup> tranche » est une ligne, avec son échéance.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Frais</th>
                                <th scope="col">Portée</th>
                                <th scope="col" class="text-end">Montant</th>
                                <th scope="col" class="d-none d-md-table-cell">Échéance</th>
                                <th scope="col" class="text-center">Affectés</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fees as $fee): ?>
                                <?php $diverging = $drift[(int) $fee['id']] ?? 0; ?>
                                <tr<?= (int) $fee['is_active'] === 0 ? ' class="opacity-50"' : '' ?>>
                                    <td>
                                        <span class="fw-medium"><?= e($fee['name']) ?></span>
                                        <?php if ($fee['group_label'] !== null): ?>
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis">
                                                <?= e((string) $fee['group_label']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ((int) $fee['is_mandatory'] === 0): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis">facultatif</span>
                                        <?php endif; ?>
                                        <?php if ((int) $fee['is_active'] === 0): ?>
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis">inactif</span>
                                        <?php endif; ?>
                                        <div class="small text-secondary"><?= e($fee['code']) ?></div>
                                    </td>
                                    <td class="small">
                                        <?php if ((string) $fee['scope'] === 'level'): ?>
                                            <?= e((string) ($fee['level_short'] ?? 'niveau')) ?>
                                        <?php elseif ((string) $fee['scope'] === 'classroom'): ?>
                                            <?= e((string) ($fee['classroom_name'] ?? 'classe')) ?>
                                        <?php else: ?>
                                            <span class="text-secondary">toute l'école</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-medium text-nowrap">
                                        <?= e(finance_amount((float) $fee['amount'], (string) $fee['currency'])) ?>
                                    </td>
                                    <td class="d-none d-md-table-cell small">
                                        <?= $fee['due_on'] !== null
                                            ? e(date('d/m/Y', strtotime((string) $fee['due_on'])))
                                            : '<span class="text-secondary">—</span>' ?>
                                    </td>
                                    <td class="text-center">
                                        <?= (int) $fee['assigned_count'] ?>
                                        <?php if ($diverging > 0): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis d-block mt-1"
                                                  title="Ces dettes portent encore l'ancien montant">
                                                <?= (int) $diverging ?> figée(s)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="<?= e(url('/finances/frais', [
                                               'annee' => (int) $year['id'], 'modifier' => (int) $fee['id'],
                                           ])) ?>#formulaire">Modifier</a>

                                        <form method="post" action="<?= e(url('/finances/affecter')) ?>"
                                              class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">
                                            <input type="hidden" name="fee_id" value="<?= (int) $fee['id'] ?>">
                                            <input type="hidden" name="retour"
                                                   value="/finances/frais?annee=<?= (int) $year['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                                <?= (int) $fee['is_active'] === 0 ? 'disabled' : '' ?>>Affecter</button>
                                        </form>
                                    </td>
                                </tr>

                                <?php if ($diverging > 0): ?>
                                    <tr class="table-warning">
                                        <td colspan="6" class="small">
                                            <form method="post"
                                                  action="<?= e(url('/finances/frais/' . (int) $fee['id'] . '/realigner')) ?>"
                                                  class="d-flex flex-wrap gap-2 align-items-center">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="annee" value="<?= (int) $year['id'] ?>">
                                                <span>
                                                    <strong><?= (int) $diverging ?> dette(s)</strong> portent encore
                                                    l'ancien montant. C'est voulu : le tarif annoncé à une famille ne
                                                    se réécrit pas tout seul. Si c'est une erreur de saisie à corriger :
                                                </span>
                                                <input type="text" name="motif" class="form-control form-control-sm"
                                                       style="max-width:20rem" required minlength="5"
                                                       placeholder="Motif de la correction (obligatoire)">
                                                <button type="submit" class="btn btn-sm btn-warning">
                                                    Réaligner ces <?= (int) $diverging ?> dette(s)
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <form method="post" action="<?= e(url('/finances/affecter')) ?>" class="mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">
                <input type="hidden" name="retour" value="/finances/frais?annee=<?= (int) $year['id'] ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-repeat me-1"></i> Affecter toute la grille aux inscrits
                </button>
                <span class="small text-secondary ms-2">
                    Sans effet sur les dettes déjà créées — l'opération peut être relancée sans risque.
                </span>
            </form>
        <?php endif; ?>
    </div>

    <!-- ========================== LE FORMULAIRE ========================== -->
    <div class="col-12 col-xl-4" id="formulaire">
        <div class="card">
            <div class="card-header fw-medium">
                <?= $edit !== null ? 'Modifier « ' . e($edit['name']) . ' »' : 'Nouveau frais' ?>
            </div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/finances/frais')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">
                    <input type="hidden" name="fee_id" value="<?= $edit !== null ? (int) $edit['id'] : 0 ?>">

                    <div class="mb-3">
                        <label class="form-label" for="f-name">Libellé</label>
                        <input type="text" class="form-control" id="f-name" name="name" required maxlength="120"
                               value="<?= e((string) ($edit['name'] ?? '')) ?>"
                               placeholder="Minerval 1re tranche">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label" for="f-code">Code</label>
                            <input type="text" class="form-control" id="f-code" name="code" required
                                   pattern="[A-Za-z0-9_-]{2,30}" maxlength="30"
                                   value="<?= e((string) ($edit['code'] ?? '')) ?>"
                                   placeholder="MIN_T1">
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="f-pos">Ordre</label>
                            <input type="number" class="form-control" id="f-pos" name="position" min="0" max="999"
                                   value="<?= (int) ($edit['position'] ?? 0) ?>">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label" for="f-amount">Montant</label>
                            <input type="text" class="form-control" id="f-amount" name="amount" required
                                   inputmode="decimal"
                                   value="<?= $edit !== null ? e((string) $edit['amount']) : '' ?>">
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="f-currency">Devise</label>
                            <select class="form-select" id="f-currency" name="currency">
                                <?php foreach ($currencies as $code => $label): ?>
                                    <option value="<?= e($code) ?>"
                                        <?= ($edit['currency'] ?? $defaultCurrency) === $code ? 'selected' : '' ?>>
                                        <?= e($code) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="f-scope">Portée</label>
                        <select class="form-select" id="f-scope" name="scope"
                                onchange="document.getElementById('f-level').closest('div').hidden = this.value !== 'level';
                                          document.getElementById('f-class').closest('div').hidden = this.value !== 'classroom';">
                            <?php foreach ($scopes as $key => $label): ?>
                                <option value="<?= e($key) ?>"
                                    <?= ($edit['scope'] ?? 'school') === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3" <?= ($edit['scope'] ?? 'school') !== 'level' ? 'hidden' : '' ?>>
                        <label class="form-label" for="f-level">Niveau</label>
                        <select class="form-select" id="f-level" name="level_id">
                            <?php foreach ($levels as $level): ?>
                                <option value="<?= (int) $level['id'] ?>"
                                    <?= (int) ($edit['level_id'] ?? 0) === (int) $level['id'] ? 'selected' : '' ?>>
                                    <?= e($level['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3" <?= ($edit['scope'] ?? 'school') !== 'classroom' ? 'hidden' : '' ?>>
                        <label class="form-label" for="f-class">Classe</label>
                        <select class="form-select" id="f-class" name="classroom_id">
                            <?php foreach ($classrooms as $classroom): ?>
                                <option value="<?= (int) $classroom['id'] ?>"
                                    <?= (int) ($edit['classroom_id'] ?? 0) === (int) $classroom['id'] ? 'selected' : '' ?>>
                                    <?= e($classroom['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="f-due">Échéance</label>
                            <input type="date" class="form-control" id="f-due" name="due_on"
                                   value="<?= e((string) ($edit['due_on'] ?? '')) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="f-group">Groupe</label>
                            <input type="text" class="form-control" id="f-group" name="group_label" maxlength="80"
                                   value="<?= e((string) ($edit['group_label'] ?? '')) ?>"
                                   placeholder="Minerval">
                        </div>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="f-mandatory" name="is_mandatory" value="1"
                            <?= (int) ($edit['is_mandatory'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="f-mandatory">Obligatoire</label>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="f-active" name="is_active" value="1"
                            <?= (int) ($edit['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="f-active">Actif</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <?= $edit !== null ? 'Enregistrer' : 'Créer le frais' ?>
                        </button>
                        <?php if ($edit !== null): ?>
                            <a href="<?= e(url('/finances/frais', ['annee' => (int) $year['id']])) ?>"
                               class="btn btn-outline-secondary">Annuler</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
