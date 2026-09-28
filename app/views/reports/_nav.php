<?php
/**
 * Bandeau commun aux trois rapports : onglets, année, export, impression.
 *
 * LE BOUTON D'EXPORT SUIT LA PERMISSION, PAS L'ÉCRAN.
 * `report.export` est distincte de `report.academic` : un compte peut
 * consulter sans pouvoir sortir de fichier. Masquer le bouton est un
 * confort — la vraie protection est `reports_require_export()`, dans le
 * contrôleur.
 *
 * @var string $actif      Onglet courant : effectifs | resultats | assiduite
 * @var array  $annees
 * @var array  $annee
 * @var array  $parametres Paramètres à conserver dans les liens
 */
declare(strict_types=1);

$onglets = [
    'effectifs' => ['Effectifs', '/rapports/effectifs', 'bi-people'],
    'resultats' => ['Résultats', '/rapports/resultats', 'bi-journal-check'],
    'assiduite' => ['Assiduité', '/rapports/assiduite', 'bi-calendar-check'],
];

$parametres = $parametres ?? [];
$courant    = $onglets[$actif][1];
?>

<div class="page-head no-print">
    <div>
        <h1 class="page-title">Rapports</h1>
        <p class="page-subtitle">Année <?= e((string) $annee['code']) ?></p>
    </div>
</div>

<ul class="nav nav-pills mb-3 no-print">
    <?php foreach ($onglets as $cle => [$libelle, $chemin, $icone]): ?>
        <li class="nav-item">
            <a class="nav-link <?= $cle === $actif ? 'active' : '' ?>"
               href="<?= e(url($chemin, ['annee' => (int) $annee['id']])) ?>">
                <i class="bi <?= e($icone) ?> me-1"></i><?= e($libelle) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<form method="get" action="<?= e(url($courant)) ?>" class="card mb-3 no-print">
    <div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1" for="f-annee">Année scolaire</label>
            <select class="form-select form-select-sm" id="f-annee" name="annee"
                    onchange="this.form.submit()">
                <?php foreach ($annees as $a): ?>
                    <option value="<?= (int) $a['id'] ?>"
                        <?= (int) $a['id'] === (int) $annee['id'] ? 'selected' : '' ?>>
                        <?= e((string) $a['code']) ?>
                        <?= (int) $a['is_current'] === 1 ? '(en cours)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (isset($filtresSupplementaires)) { echo $filtresSupplementaires; } ?>

        <div class="col-12 col-md-auto d-flex gap-2 pt-1 ms-md-auto">
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-funnel me-1"></i>Afficher
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimer
            </button>
            <?php if (can('report.export')): ?>
                <a class="btn btn-sm btn-outline-success"
                   href="<?= e(url($courant, $parametres + ['annee' => (int) $annee['id'], 'export' => 'csv'])) ?>">
                    <i class="bi bi-filetype-csv me-1"></i>Excel (CSV)
                </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="print-only mb-3" style="display:none;">
    <strong><?= e((string) (auth_user()['school_name'] ?? '')) ?></strong> —
    <?= e($onglets[$actif][0]) ?>, année <?= e((string) $annee['code']) ?>
    <div class="text-muted" style="font-size:.8rem;">
        Édité le <?= e(date('d/m/Y à H:i')) ?>
    </div>
</div>
