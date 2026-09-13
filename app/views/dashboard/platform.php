<?php
/**
 * Tableau de bord du super administrateur de la plateforme.
 *
 * @var int $schools_total
 * @var int $schools_active
 * @var int $users_total
 */
declare(strict_types=1);

set_title('Administration de la plateforme');
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Administration de la plateforme</h1>
        <p class="page-subtitle">Vue d'ensemble des établissements abonnés.</p>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-teal"><i class="bi bi-buildings"></i></div>
        <div class="stat-body">
            <span class="stat-value"><?= number_format($schools_total, 0, ',', ' ') ?></span>
            <span class="stat-label">Établissements enregistrés</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-green"><i class="bi bi-patch-check"></i></div>
        <div class="stat-body">
            <span class="stat-value"><?= number_format($schools_active, 0, ',', ' ') ?></span>
            <span class="stat-label">Établissements actifs</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-indigo"><i class="bi bi-people"></i></div>
        <div class="stat-body">
            <span class="stat-value"><?= number_format($users_total, 0, ',', ' ') ?></span>
            <span class="stat-label">Comptes, toutes écoles</span>
        </div>
    </div>
</div>

<div class="alert alert-info mt-4">
    <i class="bi bi-info-circle"></i>
    La gestion des établissements et des abonnements sera développée en phase 7.
    L'ossature de sécurité (rôles plateforme, isolation des données) est en place.
</div>
