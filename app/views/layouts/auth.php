<?php
/**
 * Layout des pages publiques (connexion, mot de passe oublié).
 *
 * @var string $content Contenu rendu par la vue
 */
declare(strict_types=1);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?= csrf_meta() ?>
    <title><?= e(view_title()) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="auth-body">

<main class="auth-shell">
    <div class="auth-card">

        <div class="auth-brand">
            <div class="auth-brand-mark" aria-hidden="true">
                <i class="bi bi-mortarboard-fill"></i>
            </div>
            <div>
                <h1 class="auth-brand-name"><?= e(config('app.name')) ?></h1>
                <p class="auth-brand-tag">Gestion scolaire — République Démocratique du Congo</p>
            </div>
        </div>

        <?php partial('partials/flash'); ?>

        <?= $content ?>

    </div>

    <p class="auth-footer">
        <?= e(config('app.name')) ?> · version <?= e(config('app.version')) ?>
    </p>
</main>

<?= script_tag('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>
</body>
</html>
