<?php
/**
 * Référentiel scolaire — vue d'ensemble.
 *
 * @var array|null $year
 * @var array $years
 * @var array $sections
 * @var array $options
 * @var array $subjects
 * @var array $periods
 * @var array $programs
 * @var array $levels
 */
declare(strict_types=1);

set_title('Référentiel scolaire');

$activeSections = array_filter($sections, static fn (array $s): bool => (int) $s['is_active'] === 1);
$activeSubjects = array_filter($subjects, static fn (array $s): bool => (int) $s['is_active'] === 1);
$activePrograms = array_filter($programs, static fn (array $p): bool => $p['status'] === 'active');
$isEmpty        = $sections === [] && $subjects === [];
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Référentiel scolaire</h1>
        <p class="page-subtitle">
            Sections, options, branches et programmes de votre établissement
            <?php if ($year !== null): ?>
                · Année <strong><?= e($year['code']) ?></strong>
            <?php endif; ?>
        </p>
    </div>

    <?php if (count($years) > 1): ?>
        <form method="get" action="<?= e(url('/referentiel')) ?>" class="d-flex gap-2">
            <select name="annee" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($years as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"
                        <?= $year !== null && (int) $option['id'] === (int) $year['id'] ? 'selected' : '' ?>>
                        <?= e($option['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if ($isEmpty): ?>
    <!-- --------------------------------------------------------------
         Premier passage : proposer l'import du référentiel national
    --------------------------------------------------------------- -->
    <div class="card">
        <div class="card-body text-center py-5">
            <div class="empty-icon"><i class="bi bi-diagram-3"></i></div>
            <h2 class="h5 mt-3 mb-2">Votre référentiel est vide</h2>
            <p class="text-muted mx-auto" style="max-width:560px">
                Importez le référentiel national congolais : 7 sections d'humanités,
                18 options et 33 branches, prêtes à l'emploi. Vous pourrez ensuite
                renommer, désactiver ou compléter librement — votre copie
                n'affecte aucun autre établissement.
            </p>

            <?php if (can('curriculum.manage')): ?>
                <form method="post" action="<?= e(url('/referentiel/importer')) ?>" class="mt-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-download"></i> Importer le référentiel national
                    </button>
                </form>
            <?php else: ?>
                <p class="text-muted small mt-3">
                    Vous n'avez pas l'autorisation d'importer le référentiel.
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

    <!-- --------------------------------------------------------------
         Indicateurs
    --------------------------------------------------------------- -->
    <div class="stat-grid">
        <a class="stat-card stat-link" href="<?= e(url('/referentiel/sections')) ?>">
            <div class="stat-icon stat-icon-indigo"><i class="bi bi-diagram-2"></i></div>
            <div class="stat-body">
                <span class="stat-value"><?= count($activeSections) ?><span class="stat-total">/<?= count($sections) ?></span></span>
                <span class="stat-label">Sections actives</span>
            </div>
        </a>

        <a class="stat-card stat-link" href="<?= e(url('/referentiel/sections')) ?>">
            <div class="stat-icon stat-icon-teal"><i class="bi bi-signpost-split"></i></div>
            <div class="stat-body">
                <span class="stat-value"><?= count($options) ?></span>
                <span class="stat-label">Options</span>
            </div>
        </a>

        <a class="stat-card stat-link" href="<?= e(url('/referentiel/branches')) ?>">
            <div class="stat-icon stat-icon-amber"><i class="bi bi-book"></i></div>
            <div class="stat-body">
                <span class="stat-value"><?= count($activeSubjects) ?><span class="stat-total">/<?= count($subjects) ?></span></span>
                <span class="stat-label">Branches actives</span>
            </div>
        </a>

        <a class="stat-card stat-link" href="<?= e(url('/referentiel/programmes', $year ? ['annee' => (int) $year['id']] : [])) ?>">
            <div class="stat-icon <?= $activePrograms !== [] ? 'stat-icon-green' : 'stat-icon-red' ?>">
                <i class="bi bi-journal-bookmark"></i>
            </div>
            <div class="stat-body">
                <span class="stat-value"><?= count($activePrograms) ?><span class="stat-total">/<?= count($programs) ?></span></span>
                <span class="stat-label">Programmes activés</span>
            </div>
        </a>
    </div>

    <div class="row g-4 mt-1">

        <!-- ----------------------------------------------------------
             Périodes d'évaluation
        ----------------------------------------------------------- -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h2 class="card-title">Périodes d'évaluation</h2>
                    <?php if ($periods !== []): ?>
                        <span class="badge text-bg-success"><?= count($periods) ?> périodes</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($year === null): ?>
                        <p class="text-muted mb-0">Créez d'abord une année scolaire.</p>

                    <?php elseif ($periods === []): ?>
                        <p class="text-muted">
                            Les périodes déterminent les colonnes du bulletin et la façon
                            dont les points sont totalisés.
                        </p>
                        <div class="alert alert-light border mb-3">
                            <strong class="d-block mb-1">Structure officielle EPST</strong>
                            <span class="small text-muted">
                                4 périodes + 2 examens semestriels. Une branche sur 20 points
                                est notée sur 20 par période et sur 40 à l'examen ; le total
                                général atteint 160 points.
                            </span>
                        </div>
                        <?php if (can('curriculum.manage')): ?>
                            <form method="post" action="<?= e(url('/referentiel/periodes')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-calendar-plus"></i> Créer les périodes standard
                                </button>
                            </form>
                        <?php endif; ?>

                    <?php else: ?>
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Période</th>
                                    <th class="text-center">Semestre</th>
                                    <th class="text-end">Poids</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($periods as $period): ?>
                                    <tr>
                                        <td>
                                            <?php if ($period['period_type'] === 'exam'): ?>
                                                <i class="bi bi-star-fill text-warning me-1" title="Examen"></i>
                                            <?php endif; ?>
                                            <?= e($period['name']) ?>
                                            <code class="ms-1 small"><?= e($period['code']) ?></code>
                                        </td>
                                        <td class="text-center"><?= (int) $period['semester'] ?></td>
                                        <td class="text-end">
                                            × <?= rtrim(rtrim(number_format((float) $period['max_multiplier'], 2, ',', ''), '0'), ',') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ----------------------------------------------------------
             Programmes de l'année
        ----------------------------------------------------------- -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h2 class="card-title">Programmes <?= $year !== null ? e($year['code']) : '' ?></h2>
                    <a href="<?= e(url('/referentiel/programmes', $year ? ['annee' => (int) $year['id']] : [])) ?>"
                       class="btn btn-sm btn-outline-secondary">Gérer</a>
                </div>
                <div class="card-body">
                    <?php if ($programs === []): ?>
                        <p class="text-muted mb-2">Aucun programme défini pour cette année.</p>
                        <p class="small text-muted mb-0">
                            Un programme rassemble les branches d'un niveau et leur maximum
                            de points. Il faut un programme par niveau, et un par option
                            dans les humanités.
                        </p>
                    <?php else: ?>
                        <ul class="plain-list">
                            <?php foreach (array_slice($programs, 0, 8) as $program): ?>
                                <li>
                                    <a href="<?= e(url('/referentiel/programmes/' . (int) $program['id'])) ?>">
                                        <?= e($program['name']) ?>
                                    </a>
                                    <span class="plain-list-meta">
                                        <?= (int) $program['subjects_count'] ?> branches ·
                                        <?= (int) $program['total_max'] ?> pts
                                        <?php if ($program['status'] === 'active'): ?>
                                            <span class="badge text-bg-success ms-1">actif</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary ms-1"><?= e($program['status']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (count($programs) > 8): ?>
                            <p class="small text-muted mt-2 mb-0">
                                et <?= count($programs) - 8 ?> autre(s)…
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (can('curriculum.manage')): ?>
        <div class="card mt-4">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <strong>Mettre à jour depuis le référentiel national</strong>
                    <p class="small text-muted mb-0">
                        Ajoute uniquement les éléments absents. Vos libellés modifiés
                        et vos désactivations sont conservés.
                    </p>
                </div>
                <form method="post" action="<?= e(url('/referentiel/importer')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-repeat"></i> Réimporter
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>
