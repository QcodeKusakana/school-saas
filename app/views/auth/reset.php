<?php
/**
 * Définition d'un nouveau mot de passe depuis un lien de réinitialisation.
 *
 * @var string $token
 */
declare(strict_types=1);

set_title('Nouveau mot de passe');
?>

<h2 class="auth-title">Nouveau mot de passe</h2>
<p class="auth-subtitle">
    Choisissez un mot de passe d'au moins <?= (int) config('security.password_min_length') ?> caractères,
    contenant une minuscule, une majuscule et un chiffre.
</p>

<form method="post" action="<?= e(url('/mot-de-passe/reinitialiser')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <div class="mb-3">
        <label for="password" class="form-label">Nouveau mot de passe</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control <?= has_error('password') ? 'is-invalid' : '' ?>"
                   id="password" name="password" autocomplete="new-password" required autofocus>
            <button class="btn btn-outline-secondary" type="button"
                    data-toggle-password="#password" aria-label="Afficher le mot de passe">
                <i class="bi bi-eye"></i>
            </button>
            <?php if (has_error('password')): ?>
                <div class="invalid-feedback"><?= e(error_for('password')) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mb-4">
        <label for="password_confirmation" class="form-label">Confirmer le mot de passe</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
            <input type="password" class="form-control <?= has_error('password_confirmation') ? 'is-invalid' : '' ?>"
                   id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
            <?php if (has_error('password_confirmation')): ?>
                <div class="invalid-feedback"><?= e(error_for('password_confirmation')) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <button type="submit" class="btn btn-primary w-100">Enregistrer le mot de passe</button>
</form>
