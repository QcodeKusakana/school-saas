<?php
/**
 * Barre supérieure : ouverture du menu sur mobile, recherche globale,
 * menu utilisateur.
 *
 * @var array $user
 */
declare(strict_types=1);
?>
<header class="app-topbar">

    <button type="button" class="btn-icon d-lg-none" data-sidebar-toggle aria-label="Ouvrir le menu">
        <i class="bi bi-list"></i>
    </button>

    <?php if ($user['school_id'] !== null && can('student.view')): ?>
        <form class="topbar-search" action="<?= e(url('/recherche')) ?>" method="get" role="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="q" class="form-control"
                   placeholder="Rechercher un élève, un matricule, un parent…"
                   aria-label="Recherche globale" autocomplete="off">
        </form>
    <?php else: ?>
        <div class="topbar-search"></div>
    <?php endif; ?>

    <div class="topbar-actions">

        <!-- Indicateur de connexion réseau, piloté par app.js (utile en phase 8) -->
        <span class="net-status" data-net-status hidden>
            <i class="bi bi-wifi-off"></i><span class="net-status-text">Hors connexion</span>
        </span>

        <div class="dropdown">
            <button class="user-button" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="user-avatar" aria-hidden="true">
                    <?= e(mb_strtoupper(mb_substr((string) $user['first_name'], 0, 1) . mb_substr((string) $user['last_name'], 0, 1))) ?>
                </span>
                <span class="user-meta d-none d-sm-flex">
                    <strong><?= e(auth_display_name()) ?></strong>
                    <small><?= e(implode(', ', array_column(perm_roles(), 'code'))) ?></small>
                </span>
                <i class="bi bi-chevron-down d-none d-sm-block" aria-hidden="true"></i>
            </button>

            <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-header">
                    <?= e(full_name($user['last_name'], $user['post_name'], $user['first_name'])) ?><br>
                    <span class="text-muted small"><?= e($user['username']) ?></span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="<?= e(url('/mon-compte')) ?>">
                        <i class="bi bi-person"></i> Mon compte
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="<?= e(url('/mot-de-passe/changer')) ?>">
                        <i class="bi bi-key"></i> Changer mon mot de passe
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <!-- Déconnexion en POST : un lien GET serait déclenchable
                         par une image distante (CSRF de déconnexion). -->
                    <form method="post" action="<?= e(url('/logout')) ?>" class="px-1">
                        <?= csrf_field() ?>
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-box-arrow-right"></i> Se déconnecter
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
