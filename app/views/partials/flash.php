<?php
/**
 * Affichage des messages éphémères.
 * Inclus dans les deux layouts : un message flash ne peut pas être oublié.
 */
declare(strict_types=1);

$messages = flash_all();

if ($messages === []) {
    return;
}

$styles = [
    'success' => ['alert-success', 'bi-check-circle-fill'],
    'error'   => ['alert-danger',  'bi-exclamation-octagon-fill'],
    'warning' => ['alert-warning', 'bi-exclamation-triangle-fill'],
    'info'    => ['alert-info',    'bi-info-circle-fill'],
];
?>
<div class="flash-stack">
    <?php foreach ($messages as $type => $list): ?>
        <?php [$class, $icon] = $styles[$type] ?? $styles['info']; ?>
        <?php foreach ($list as $message): ?>
            <div class="alert <?= e($class) ?> alert-dismissible fade show" role="alert">
                <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
                <span><?= e($message) ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
