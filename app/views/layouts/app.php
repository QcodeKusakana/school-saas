<?php
/**
 * Layout de l'application authentifiée.
 *
 * Structure : barre latérale rétractable + barre supérieure.
 * Sur mobile, la barre latérale devient un tiroir — c'est la disposition
 * qui limite le nombre de clics tout en restant lisible sur petit écran.
 *
 * @var string $content
 */
declare(strict_types=1);

$user = auth_user();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0f3d3e">
    <?= csrf_meta() ?>
    <title><?= e(view_title()) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>

<div class="app-shell">

    <?php partial('partials/sidebar', ['user' => $user]); ?>

    <div class="app-main">

        <?php partial('partials/topbar', ['user' => $user]); ?>

        <main class="app-content">
            <?php partial('partials/flash'); ?>
            <?= $content ?>
        </main>

        <footer class="app-footer">
            <span><?= e(config('app.name')) ?> · v<?= e(config('app.version')) ?></span>
            <?php if (config('app.debug')): ?>
                <span class="text-muted">
                    <?= number_format((microtime(true) - APP_START) * 1000, 0) ?> ms
                </span>
            <?php endif; ?>
        </footer>

    </div>
</div>

<!-- Voile affiché derrière le tiroir de navigation sur mobile -->
<div class="app-overlay" data-sidebar-overlay hidden></div>

<?= script_tag('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>
<?= script_tag('assets/js/app.js') ?>
</body>
</html>
