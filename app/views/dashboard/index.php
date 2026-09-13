<?php
/**
 * Tableau de bord de l'établissement.
 *
 * Phase 1 : n'affiche que des données réelles. Les compteurs d'élèves,
 * de présences et de recettes apparaîtront avec leurs modules respectifs.
 *
 * @var array|null $school
 * @var array|null $currentYear
 * @var int        $usersCount
 * @var array      $activeCycles
 * @var array|null $subscription
 * @var array      $setupSteps
 */
declare(strict_types=1);

set_title('Tableau de bord');

$remainingSteps = array_filter($setupSteps, static fn (array $s): bool => !$s['done']);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Tableau de bord</h1>
        <p class="page-subtitle">
            <?= e($school['name'] ?? 'Établissement') ?>
            <?php if ($currentYear !== null): ?>
                · Année <strong><?= e($currentYear['code']) ?></strong>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if ($currentYear === null): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong>Aucune année scolaire active.</strong>
            Les inscriptions, les notes et les paiements sont tous rattachés à une année
            scolaire : elle doit être créée avant toute autre opération.
            <?php if (can('academic_year.manage')): ?>
                <a href="<?= e(url('/annees-scolaires')) ?>" class="alert-link d-block mt-1">
                    Créer l'année scolaire →
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- ------------------------------------------------------------------
     Indicateurs disponibles en phase 1
------------------------------------------------------------------- -->
<div class="stat-grid">

    <div class="stat-card">
        <div class="stat-icon stat-icon-teal"><i class="bi bi-person-badge"></i></div>
        <div class="stat-body">
            <span class="stat-value"><?= number_format($usersCount, 0, ',', ' ') ?></span>
            <span class="stat-label">Comptes utilisateurs</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-amber"><i class="bi bi-calendar3"></i></div>
        <div class="stat-body">
            <span class="stat-value"><?= e($currentYear['code'] ?? '—') ?></span>
            <span class="stat-label">Année scolaire en cours</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-indigo"><i class="bi bi-diagram-3"></i></div>
        <div class="stat-body">
            <span class="stat-value"><?= count($activeCycles) ?></span>
            <span class="stat-label">
                <?= $activeCycles === []
                    ? 'Aucun cycle activé'
                    : e(implode(' · ', array_column($activeCycles, 'short_name'))) ?>
            </span>
        </div>
    </div>

    <?php if ($subscription !== null): ?>
        <?php
        $daysLeft = (int) floor((strtotime((string) $subscription['ends_on']) - time()) / 86400);
        $tone     = $daysLeft <= 15 ? 'stat-icon-red' : 'stat-icon-green';
        ?>
        <div class="stat-card">
            <div class="stat-icon <?= e($tone) ?>"><i class="bi bi-patch-check"></i></div>
            <div class="stat-body">
                <span class="stat-value"><?= e($subscription['plan_name']) ?></span>
                <span class="stat-label">
                    <?= $daysLeft >= 0
                        ? 'Abonnement · ' . $daysLeft . ' jour' . ($daysLeft > 1 ? 's' : '') . ' restant' . ($daysLeft > 1 ? 's' : '')
                        : 'Abonnement expiré' ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

</div>

<div class="row g-4 mt-1">

    <!-- ------------------------------------------------------------------
         Guide de configuration
    ------------------------------------------------------------------- -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <h2 class="card-title">Configuration de l'établissement</h2>
                <?php if ($remainingSteps === []): ?>
                    <span class="badge text-bg-success">Terminée</span>
                <?php else: ?>
                    <span class="badge text-bg-warning">
                        <?= count($remainingSteps) ?> étape<?= count($remainingSteps) > 1 ? 's' : '' ?> restante<?= count($remainingSteps) > 1 ? 's' : '' ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <ul class="setup-list">
                    <?php foreach ($setupSteps as $step): ?>
                        <li class="setup-item <?= $step['done'] ? 'is-done' : '' ?>">
                            <span class="setup-check" aria-hidden="true">
                                <i class="bi <?= $step['done'] ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                            </span>
                            <div class="setup-text">
                                <strong><?= e($step['label']) ?></strong>
                                <small><?= e($step['hint']) ?></small>
                            </div>
                            <?php if (!$step['done']): ?>
                                <a href="<?= e(url($step['url'])) ?>" class="btn btn-sm btn-outline-primary">
                                    Configurer
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------------------------
         Fiche établissement
    ------------------------------------------------------------------- -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <h2 class="card-title">Établissement</h2>
            </div>
            <div class="card-body">
                <dl class="info-list">
                    <dt>Dénomination</dt>
                    <dd><?= e($school['name'] ?? '—') ?></dd>

                    <dt>Code</dt>
                    <dd><code><?= e($school['code'] ?? '—') ?></code></dd>

                    <dt>Numéro SERNIE</dt>
                    <dd><?= e($school['sernie_number'] ?: 'Non renseigné') ?></dd>

                    <dt>Régime</dt>
                    <dd><?= e(ucfirst((string) ($school['school_type'] ?? '—'))) ?></dd>

                    <dt>Localisation</dt>
                    <dd>
                        <?= e(implode(', ', array_filter([
                            $school['commune'] ?? null,
                            $school['city'] ?? null,
                            $school['province'] ?? null,
                        ])) ?: 'Non renseignée') ?>
                    </dd>

                    <dt>Chef d'établissement</dt>
                    <dd><?= e($school['director_name'] ?: 'Non renseigné') ?></dd>
                </dl>

                <?php if (can('school.edit')): ?>
                    <a href="<?= e(url('/ecole/parametres')) ?>" class="btn btn-sm btn-outline-secondary w-100 mt-2">
                        <i class="bi bi-pencil"></i> Modifier la fiche
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ------------------------------------------------------------------
     Repère de progression du produit
------------------------------------------------------------------- -->
<div class="card mt-4">
    <div class="card-header">
        <h2 class="card-title">Modules du logiciel</h2>
        <span class="text-muted small">Phase 1 sur 10</span>
    </div>
    <div class="card-body">
        <div class="phase-track">
            <?php
            $phases = [
                ['Fondation',            true],
                ['Référentiel scolaire', false],
                ['Élèves',               false],
                ['Pédagogie',            false],
                ['Finances',             false],
                ['Portails',             false],
                ['Abonnements',          false],
                ['Hors connexion',       false],
                ['Modules avancés',      false],
                ['Recette',              false],
            ];
            ?>
            <?php foreach ($phases as $index => [$label, $done]): ?>
                <div class="phase-step <?= $done ? 'is-done' : '' ?>">
                    <span class="phase-dot"><?= $done ? '<i class="bi bi-check"></i>' : ($index + 1) ?></span>
                    <span class="phase-label"><?= e($label) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
