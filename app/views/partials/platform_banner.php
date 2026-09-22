<?php
/**
 * BANDEAU DE VISITE — l'éditeur travaille dans une école cliente.
 *
 * POURQUOI CE BANDEAU EXISTE
 * ==========================
 * Quand un compte de la plateforme ouvre une école, il en obtient le
 * contexte complet : les mêmes écrans, les mêmes boutons, les mêmes
 * pouvoirs que la direction. Rien, à l'écran, ne le distinguerait de sa
 * propre console.
 *
 * C'est exactement la situation où l'on modifie les données d'un client
 * en croyant être chez soi. Le bandeau est donc permanent, coloré, et
 * porte la sortie : il n'est pas décoratif, il est la seule chose qui
 * dise où l'on se trouve.
 *
 * Il ne s'affiche QUE pour un utilisateur de la plateforme en visite —
 * un compte d'école a bien un `school_id` en session, mais il est chez
 * lui.
 */
declare(strict_types=1);

// `platform_visiting_school()` vit dans app/core/platform.php,
// justement pour être disponible ici : le layout se rend sur chaque
// page, quel que soit le module de la route.
$visiting = platform_visiting_school();

if ($visiting === null) {
    return;
}
?>
<div class="platform-banner<?= !empty($visiting['archived']) ? ' platform-banner--archived' : '' ?>">
    <div class="platform-banner__text">
        <i class="bi bi-eye-fill" aria-hidden="true"></i>
        <span>
            Vous travaillez dans <strong><?= e((string) $visiting['name']) ?></strong>
            (<?= e((string) $visiting['code']) ?>) — cette visite est journalisée.
            <?php if (!empty($visiting['archived'])): ?>
                <!-- L'école a été ARCHIVÉE pendant la visite. Le bandeau
                     disparaissait auparavant, laissant l'éditeur dedans
                     sans le savoir (audit 7B1). -->
                <strong>Cet établissement est archivé</strong> : quittez-le.
            <?php elseif (($visiting['status'] ?? 'active') !== 'active'): ?>
                Établissement <strong><?= e((string) $visiting['status']) ?></strong>.
            <?php endif; ?>
        </span>
    </div>

    <form method="post" action="<?= e(url('/plateforme/quitter')) ?>" class="platform-banner__form">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-light" type="submit">Quitter l'établissement</button>
    </form>
</div>
