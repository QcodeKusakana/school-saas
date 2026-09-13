<?php
/**
 * Module AUTH — contrôleurs.
 *
 * Responsabilité d'un contrôleur, et rien d'autre :
 *   1. lire l'entrée HTTP
 *   2. valider
 *   3. appeler un service
 *   4. rediriger ou rendre une vue
 *
 * Aucune requête SQL ici : elles vivent dans repositories.php.
 * Aucune règle métier ici : elle vit dans services.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';

/** Racine du site : oriente selon l'état de connexion. */
function ctrl_auth_root(): void
{
    redirect(auth_check() ? '/tableau-de-bord' : '/login');
}

// ---------------------------------------------------------------------
//  CONNEXION
// ---------------------------------------------------------------------

function ctrl_auth_login_form(): void
{
    $reason = session_pull('expired_reason');

    if ($reason === 'inactivity') {
        flash_info('Vous avez été déconnecté après une période d\'inactivité.');
    } elseif ($reason === 'absolute_timeout') {
        flash_info('Votre session a atteint sa durée maximale. Merci de vous reconnecter.');
    }

    view('auth/login', [], 'auth');
}

function ctrl_auth_login(): void
{
    $result = validate(input_all(), [
        'identifier' => 'required|string|max:190',
        'password'   => 'required|string|max:200',
    ], [
        'identifier' => 'Identifiant',
        'password'   => 'Mot de passe',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result));
        flash_error('Veuillez renseigner votre identifiant et votre mot de passe.');
        redirect('/login');
    }

    $identifier = (string) input('identifier');
    $password   = (string) input('password');

    $attempt = auth_attempt($identifier, $password, input_bool('remember'));

    if (!$attempt['ok']) {
        // L'identifiant est conservé, jamais le mot de passe.
        flash_old(['identifier' => $identifier]);
        flash_error($attempt['message']);
        redirect('/login');
    }

    // Le coût bcrypt a pu être relevé depuis la création du compte :
    // on remet le hachage à niveau de façon transparente.
    auth_rehash_if_needed((int) $attempt['user']['id'], $password, (string) $attempt['user']['password_hash']);

    // Mot de passe provisoire : changement imposé avant tout autre accès.
    if ((int) $attempt['user']['must_change_password'] === 1) {
        flash_warning('Pour votre sécurité, veuillez définir un nouveau mot de passe.');
        redirect('/mot-de-passe/changer');
    }

    $intended = session_pull('intended_url');

    flash_success('Bienvenue, ' . auth_display_name() . '.');
    redirect(is_string($intended) && $intended !== '' ? $intended : '/tableau-de-bord');
}

function ctrl_auth_logout(): void
{
    auth_logout();

    // Nouvelle session, uniquement pour porter le message de confirmation.
    session_start_secure();
    flash_success('Vous avez été déconnecté.');
    redirect('/login');
}

// ---------------------------------------------------------------------
//  MOT DE PASSE OUBLIÉ
// ---------------------------------------------------------------------

function ctrl_auth_forgot_form(): void
{
    view('auth/forgot', [], 'auth');
}

function ctrl_auth_forgot(): void
{
    $result = validate(input_all(), ['identifier' => 'required|string|max:190']);

    if (!validator_passes($result)) {
        flash_error('Veuillez saisir votre identifiant ou votre adresse email.');
        redirect('/mot-de-passe/oublie');
    }

    $outcome = auth_service_request_reset((string) input('identifier'));

    // Réponse identique que le compte existe ou non : le formulaire ne doit
    // pas permettre de découvrir quels identifiants sont enregistrés.
    flash_success(
        'Si ce compte existe, les instructions de réinitialisation ont été transmises. '
        . 'Sans adresse email enregistrée, rapprochez-vous de l\'administrateur de votre école.'
    );

    // En développement, le lien est affiché directement : aucun service
    // d'envoi d'email n'est encore branché (prévu en phase 6).
    if (config('app.debug') && $outcome['link'] !== null) {
        flash_info('Lien de réinitialisation (mode développement) : ' . $outcome['link']);
    }

    redirect('/login');
}

function ctrl_auth_reset_form(string $token): void
{
    $reset = auth_repo_find_valid_reset($token);

    if ($reset === null) {
        flash_error('Ce lien de réinitialisation est invalide ou a expiré.');
        redirect('/mot-de-passe/oublie');
    }

    view('auth/reset', ['token' => $token], 'auth');
}

function ctrl_auth_reset(): void
{
    $result = validate(input_all(), [
        'token'                 => 'required|string',
        'password'              => 'required|password',
        'password_confirmation' => 'required|confirmed:password',
    ], [
        'password'              => 'Nouveau mot de passe',
        'password_confirmation' => 'Confirmation',
    ]);

    $token = (string) input('token', '');

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result), []);
        redirect('/mot-de-passe/reinitialiser/' . urlencode($token));
    }

    $outcome = auth_service_reset_password($token, (string) input('password'));

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        redirect('/mot-de-passe/oublie');
    }

    flash_success('Votre mot de passe a été modifié. Vous pouvez vous connecter.');
    redirect('/login');
}

// ---------------------------------------------------------------------
//  CHANGEMENT DE MOT DE PASSE (utilisateur connecté)
// ---------------------------------------------------------------------

function ctrl_auth_change_form(): void
{
    view('auth/change-password', [
        'forced' => (int) (auth_user()['must_change_password'] ?? 0) === 1,
    ], 'auth');
}

function ctrl_auth_change(): void
{
    $user   = auth_user();
    $forced = (int) ($user['must_change_password'] ?? 0) === 1;

    $rules = [
        'password'              => 'required|password',
        'password_confirmation' => 'required|confirmed:password',
    ];

    // Le mot de passe actuel est exigé, sauf lors d'un changement imposé
    // après réinitialisation par un administrateur.
    if (!$forced) {
        $rules['current_password'] = 'required|string';
    }

    $result = validate(input_all(), $rules, [
        'current_password'      => 'Mot de passe actuel',
        'password'              => 'Nouveau mot de passe',
        'password_confirmation' => 'Confirmation',
    ]);

    if (!validator_passes($result)) {
        flash_errors(validator_errors($result), []);
        redirect('/mot-de-passe/changer');
    }

    if (!$forced && !password_verify((string) input('current_password'), (string) $user['password_hash'])) {
        flash_error('Le mot de passe actuel est incorrect.');
        redirect('/mot-de-passe/changer');
    }

    auth_service_change_password((int) $user['id'], (string) input('password'));

    flash_success('Votre mot de passe a été modifié.');
    redirect('/tableau-de-bord');
}
