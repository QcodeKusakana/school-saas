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

        <!-- ÉTAT DE LA SYNCHRONISATION (phase 8B).
             Il ne dit pas seulement « en ligne » : il annonce ce qui
             attend d'être envoyé et ce qui est en conflit. Un appel
             saisi hors connexion qui ne partirait jamais sans que
             personne ne le voie serait pire que pas de hors connexion
             du tout. -->
        <span id="etat-connexion" class="badge bg-secondary-subtle text-secondary-emphasis">…</span>

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
                    <!-- `data-deconnexion` : offline.js efface les listes
                         d'élèves, la file et le cache. Un téléphone est
                         souvent partagé ; se déconnecter doit vider. -->
                    <form method="post" action="<?= e(url('/logout')) ?>" class="px-1" data-deconnexion>
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
