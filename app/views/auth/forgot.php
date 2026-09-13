<?php
/**
 * Demande de réinitialisation du mot de passe.
 */
declare(strict_types=1);

set_title('Mot de passe oublié');
?>

<h2 class="auth-title">Mot de passe oublié</h2>
<p class="auth-subtitle">
    Saisissez votre identifiant. Si une adresse email est enregistrée sur votre
    compte, vous recevrez un lien de réinitialisation.
</p>

<form method="post" action="<?= e(url('/mot-de-passe/oublie')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="mb-4">
        <label for="identifier" class="form-label">Identifiant ou adresse email</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input type="text" class="form-control" id="identifier" name="identifier"
                   value="<?= old('identifier') ?>" autocapitalize="none" required autofocus>
        </div>
    </div>

    <button type="submit" class="btn btn-primary w-100">Envoyer les instructions</button>

    <p class="text-center mt-3 mb-0">
        <a href="<?= e(url('/login')) ?>" class="small">
            <i class="bi bi-arrow-left"></i> Retour à la connexion
        </a>
    </p>
</form>
