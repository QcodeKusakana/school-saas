<?php
/**
 * Tableau de bord de l'établissement.
 *
 * Phase 1 : n'affiche que des données réelles. Les compteurs d'élèves,
 * de présences et de recettes apparaîtront avec leurs modules respectifs.
 *
 * @var array|null $school
 * @var array|null $currentYear
 * @var ?int       $usersCount  null si le visiteur n'a pas `user.view`
 * @var array      $activeCycles
 * @var array|null $subscription null si pas d'abonnement, OU pas `subscription.view`
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

<?php
/*
 * UN COMPTE SANS AUCUN DROIT DOIT L'APPRENDRE DU PRODUIT.
 *
 * La phase 7D refuse de créer un compte sans rôle, et le dit :
 * « sans rôle, il se connecte et ne peut rien ouvrir. » La phase 11B
 * permet pourtant d'y arriver après coup — il suffit de désactiver le
 * rôle qu'il portait, ou de lui retirer ses permissions.
 *
 * Sans ce message, l'utilisateur voit un écran presque vide, sans menu,
 * et conclut que le logiciel est cassé. Il appellera l'éditeur au lieu
 * de son administration, qui est la seule à pouvoir le débloquer.
 *
 *   > Un cul-de-sac que le produit sait reconnaître doit être annoncé,
 *   > pas laissé deviner.
 */
?>
<?php if (perm_all() === []): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-shield-exclamation mt-1"></i>
        <div>
            <strong>Votre compte n'a aucun droit d'accès pour le moment.</strong>
            Vous êtes bien connecté, mais aucun écran ne vous est ouvert : le rôle
            qui vous était attribué a été retiré ou désactivé.
            Adressez-vous à l'administration de votre établissement — elle seule
            peut vous réattribuer un rôle.
        </div>
    </div>
<?php endif; ?>

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

    <?php if ($usersCount !== null): ?>
        <div class="stat-card">
            <div class="stat-icon stat-icon-teal"><i class="bi bi-person-badge"></i></div>
            <div class="stat-body">
                <span class="stat-value"><?= number_format($usersCount, 0, ',', ' ') ?></span>
                <span class="stat-label">Comptes utilisateurs</span>
            </div>
        </div>
    <?php endif; ?>

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
         Guide de configuration — seulement pour qui peut configurer.
         Le contrôleur n'y met que les étapes dont le visiteur détient la
         permission ; sans aucune, la carte entière disparaît plutôt que
         d'annoncer « Terminée » à quelqu'un qui n'a rien pu faire.
    ------------------------------------------------------------------- -->
    <?php if ($setupSteps !== []): ?>
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
    <?php endif; ?>

    <!-- ------------------------------------------------------------------
         Fiche établissement
    ------------------------------------------------------------------- -->
    <div class="<?= $setupSteps === [] ? 'col-12' : 'col-lg-5' ?>">
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

<?php
/*
 * LE REPÈRE DE PROGRESSION DU PRODUIT A ÉTÉ RETIRÉ.
 *
 * Un bandeau « Modules du logiciel — Phase 1 sur 10 » listait les dix
 * phases du développement, dont neuf cochées « à faire ». Il datait de
 * la phase 1 et n'avait jamais été remis à jour : en septembre 2026 il
 * annonçait encore à une école qui PAIE que les élèves, la pédagogie et
 * les finances n'étaient pas faits.
 *
 *   > Un repère de chantier laissé dans un produit vendu ne raconte pas
 *   > le produit, il raconte le chantier — et il ment dès la phase
 *   > suivante.
 *
 * Il était de surcroît visible de tous, sans aucune permission. Ce que
 * l'éditeur veut suivre se lit dans `docs-projet/etat-du-projet.md`, pas
 * sur l'écran d'accueil de ses clients.
 */
?>

