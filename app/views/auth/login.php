<?php
/**
 * Page de connexion.
 */
declare(strict_types=1);

set_title('Connexion');
?>

<h2 class="auth-title">Connexion</h2>
<p class="auth-subtitle">Accédez à l'espace de votre établissement.</p>

<form method="post" action="<?= e(url('/login')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="identifier" class="form-label">Identifiant ou adresse email</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input type="text"
                   class="form-control <?= has_error('identifier') ? 'is-invalid' : '' ?>"
                   id="identifier"
                   name="identifier"
                   value="<?= old('identifier') ?>"
                   autocomplete="username"
                   autocapitalize="none"
                   spellcheck="false"
                   required
                   autofocus>
            <?php if (has_error('identifier')): ?>
                <div class="invalid-feedback"><?= e(error_for('identifier')) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Mot de passe</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password"
                   class="form-control <?= has_error('password') ? 'is-invalid' : '' ?>"
                   id="password"
                   name="password"
                   autocomplete="current-password"
                   required>
            <button class="btn btn-outline-secondary" type="button"
                    data-toggle-password="#password"
                    aria-label="Afficher le mot de passe">
                <i class="bi bi-eye"></i>
            </button>
            <?php if (has_error('password')): ?>
                <div class="invalid-feedback"><?= e(error_for('password')) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
            <label class="form-check-label" for="remember">Se souvenir de moi</label>
        </div>
        <a href="<?= e(url('/mot-de-passe/oublie')) ?>" class="small">Mot de passe oublié ?</a>
    </div>

    <button type="submit" class="btn btn-primary w-100 btn-lg">
        <i class="bi bi-box-arrow-in-right"></i> Se connecter
    </button>
</form>
