<?php
/**
 * Barre de navigation latérale.
 *
 * Chaque entrée est conditionnée par une permission : un enseignant ne
 * voit pas le menu Finances, un comptable ne voit pas les Notes. Masquer
 * l'entrée est un confort — la vraie protection reste le middleware de
 * la route, jamais l'absence de lien.
 *
 * @var array $user
 */
declare(strict_types=1);

// Le portail des familles est proposé sur chaque page : son dépôt doit
// donc être chargé ici, et pas seulement quand une route /espace est
// appelée. Le routeur ne charge un module que lorsqu'il le dispatche.
require_once APP_PATH . '/modules/portal/repositories.php';

$isPlatform = $user['school_id'] === null;
?>
<aside class="app-sidebar" data-sidebar>

    <div class="sidebar-head">
        <a class="sidebar-brand" href="<?= e(url('/tableau-de-bord')) ?>">
            <span class="sidebar-mark" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
            <span class="sidebar-brand-text">
                <strong><?= e($isPlatform ? config('app.name') : ($user['school_name'] ?? 'Établissement')) ?></strong>
                <small><?= e($isPlatform ? 'Administration plateforme' : 'Espace établissement') ?></small>
            </span>
        </a>
        <button type="button" class="sidebar-close btn-icon d-lg-none"
                data-sidebar-close aria-label="Fermer le menu">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <nav class="sidebar-nav" aria-label="Navigation principale">

        <a class="nav-item <?= nav_active('/tableau-de-bord') ?>" href="<?= e(url('/tableau-de-bord')) ?>">
            <i class="bi bi-grid-1x2"></i><span>Tableau de bord</span>
        </a>

        <?php
        // MON ESPACE — le portail des familles (phase 6A).
        //
        // L'entrée n'est PAS conditionnée par une permission mais par le
        // lien de tutelle : c'est la même clé que les routes. Un directeur
        // qui a ses enfants dans l'école la voit ; un enseignant qui n'a
        // pas d'enfant inscrit ne la voit pas.
        //
        // La requête ne tourne que pour un compte rattaché à une école.
        ?>
        <?php if (!$isPlatform && route_exists('/espace') && portal_has_children()): ?>
            <a class="nav-item <?= nav_active('/espace') ?>" href="<?= e(url('/espace')) ?>">
                <i class="bi bi-house-heart"></i><span>Mon espace</span>
            </a>
        <?php endif; ?>

        <?php if ($isPlatform): ?>

            <p class="nav-heading">Plateforme</p>

            <?php if (can('platform.school.view')): ?>
                <a class="nav-item <?= nav_active('/plateforme/ecoles') ?>" href="<?= e(url('/plateforme/ecoles')) ?>">
                    <i class="bi bi-buildings"></i><span>Établissements</span>
                </a>
            <?php endif; ?>

            <?php if (can('platform.subscription.manage')): ?>
                <a class="nav-item <?= nav_active('/plateforme/abonnements') ?>" href="<?= e(url('/plateforme/abonnements')) ?>">
                    <i class="bi bi-credit-card"></i><span>Abonnements</span>
                </a>
            <?php endif; ?>

            <?php if (can('platform.audit.view')): ?>
                <a class="nav-item <?= nav_active('/plateforme/journal') ?>" href="<?= e(url('/plateforme/journal')) ?>">
                    <i class="bi bi-journal-text"></i><span>Journal global</span>
                </a>
            <?php endif; ?>

        <?php else: ?>

            <?php if (perm_any(['student.view', 'enrollment.manage'])): ?>
                <p class="nav-heading">Scolarité</p>
                <a class="nav-item <?= nav_active('/eleves') ?>" href="<?= e(url('/eleves')) ?>">
                    <i class="bi bi-people"></i><span>Élèves</span>
                </a>
                <a class="nav-item <?= nav_active('/classes') ?>" href="<?= e(url('/classes')) ?>">
                    <i class="bi bi-door-open"></i><span>Classes</span>
                </a>
            <?php endif; ?>

            <?php if (perm_any(['grade.view', 'attendance.view', 'evaluation.view', 'teacher.view'])): ?>
                <p class="nav-heading">Pédagogie</p>
                <?php if (can('grade.view') && route_exists('/notes')): ?>
                    <a class="nav-item <?= nav_active('/notes') ?>" href="<?= e(url('/notes')) ?>">
                        <i class="bi bi-journal-check"></i><span>Notes et bulletins</span>
                    </a>
                <?php endif; ?>
                <?php if (can('attendance.view') && route_exists('/presences')): ?>
                    <a class="nav-item <?= nav_active('/presences') ?>" href="<?= e(url('/presences')) ?>">
                        <i class="bi bi-calendar-check"></i><span>Présences</span>
                    </a>
                <?php endif; ?>
                <?php if (can('teacher.view') && route_exists('/enseignants')): ?>
                    <a class="nav-item <?= nav_active('/enseignants') ?>" href="<?= e(url('/enseignants')) ?>">
                        <i class="bi bi-person-video3"></i><span>Enseignants</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (can('finance.view') && route_exists('/finances')): ?>
                <p class="nav-heading">Finances</p>
                <a class="nav-item <?= nav_active('/finances') ?>" href="<?= e(url('/finances')) ?>">
                    <i class="bi bi-cash-coin"></i><span>Frais et paiements</span>
                </a>
                <?php if (can('fee.manage') && route_exists('/finances/frais')): ?>
                    <a class="nav-item <?= nav_active('/finances/frais') ?>" href="<?= e(url('/finances/frais')) ?>">
                        <i class="bi bi-list-columns"></i><span>Grille tarifaire</span>
                    </a>
                <?php endif; ?>
                <?php if (can('report.financial') && route_exists('/finances/impayes')): ?>
                    <a class="nav-item <?= nav_active('/finances/impayes') ?>" href="<?= e(url('/finances/impayes')) ?>">
                        <i class="bi bi-exclamation-diamond"></i><span>Impayés</span>
                    </a>
                <?php endif; ?>
                <?php if (can('expense.manage') && route_exists('/finances/depenses')): ?>
                    <a class="nav-item <?= nav_active('/finances/depenses') ?>" href="<?= e(url('/finances/depenses')) ?>">
                        <i class="bi bi-arrow-down-circle"></i><span>Dépenses</span>
                    </a>
                <?php endif; ?>
                <?php if (can('report.financial') && route_exists('/finances/journal')): ?>
                    <a class="nav-item <?= nav_active('/finances/journal') ?>" href="<?= e(url('/finances/journal')) ?>">
                        <i class="bi bi-journal-text"></i><span>Journal de caisse</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (perm_any(['school.edit', 'user.view', 'academic_year.view', 'curriculum.view', 'subscription.view'])): ?>
                <p class="nav-heading">Administration</p>

                <?php if (can('academic_year.view') && route_exists('/annees-scolaires')): ?>
                    <a class="nav-item <?= nav_active('/annees-scolaires') ?>" href="<?= e(url('/annees-scolaires')) ?>">
                        <i class="bi bi-calendar3"></i><span>Années scolaires</span>
                    </a>
                <?php endif; ?>

                <?php if (can('curriculum.view') && route_exists('/referentiel')): ?>
                    <a class="nav-item <?= nav_active('/referentiel') ?>" href="<?= e(url('/referentiel')) ?>">
                        <i class="bi bi-diagram-3"></i><span>Référentiel scolaire</span>
                    </a>
                <?php endif; ?>

                <?php if (can('user.view') && route_exists('/utilisateurs')): ?>
                    <a class="nav-item <?= nav_active('/utilisateurs') ?>" href="<?= e(url('/utilisateurs')) ?>">
                        <i class="bi bi-person-badge"></i><span>Utilisateurs</span>
                    </a>
                <?php endif; ?>

                <?php if (can('school.edit') && route_exists('/ecole/parametres')): ?>
                    <a class="nav-item <?= nav_active('/ecole') ?>" href="<?= e(url('/ecole/parametres')) ?>">
                        <i class="bi bi-gear"></i><span>Mon établissement</span>
                    </a>
                <?php endif; ?>

                <?php if (can('subscription.view') && route_exists('/abonnement')): ?>
                    <a class="nav-item <?= nav_active('/abonnement') ?>" href="<?= e(url('/abonnement')) ?>">
                        <i class="bi bi-patch-check"></i><span>Mon abonnement</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

        <?php endif; ?>

    </nav>
</aside>
