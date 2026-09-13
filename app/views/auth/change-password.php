<?php
/**
 * Changement de mot de passe par l'utilisateur connecté.
 *
 * @var bool $forced true lorsqu'un administrateur a imposé le changement
 */
declare(strict_types=1);

set_title('Changer mon mot de passe');
?>

<h2 class="auth-title">Changer mon mot de passe</h2>

<?php if ($forced): ?>
    <div class="alert alert-warning">
        <i class="bi bi-shield-exclamation"></i>
        Votre mot de passe a été défini par un administrateur. Vous devez en choisir
        un nouveau avant de continuer.
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/mot-de-passe/changer')) ?>" novalidate>
    <?= csrf_field() ?>

    <?php if (!$forced): ?>
        <div class="mb-3">
            <label for="current_password" class="form-label">Mot de passe actuel</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control <?= has_error('current_password') ? 'is-invalid' : '' ?>"
                       id="current_password" name="current_password" autocomplete="current-password" required autofocus>
                <?php if (has_error('current_password')): ?>
                    <div class="invalid-feedback"><?= e(error_for('current_password')) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="mb-3">
        <label for="password" class="form-label">Nouveau mot de passe</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="password" class="form-control <?= has_error('password') ? 'is-invalid' : '' ?>"
                   id="password" name="password" autocomplete="new-password" required
                   <?= $forced ? 'autofocus' : '' ?>>
            <button class="btn btn-outline-secondary" type="button"
                    data-toggle-password="#password" aria-label="Afficher le mot de passe">
                <i class="bi bi-eye"></i>
            </button>
            <?php if (has_error('password')): ?>
                <div class="invalid-feedback"><?= e(error_for('password')) ?></div>
            <?php endif; ?>
        </div>
        <div class="form-text">
            Au moins <?= (int) config('security.password_min_length') ?> caractères,
            avec une minuscule, une majuscule et un chiffre.
        </div>
    </div>

    <div class="mb-4">
        <label for="password_confirmation" class="form-label">Confirmer</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
            <input type="password" class="form-control <?= has_error('password_confirmation') ? 'is-invalid' : '' ?>"
                   id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
            <?php if (has_error('password_confirmation')): ?>
                <div class="invalid-feedback"><?= e(error_for('password_confirmation')) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <button type="submit" class="btn btn-primary w-100">Enregistrer</button>
</form>
