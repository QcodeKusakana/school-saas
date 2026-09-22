<?php
/**
 * Module AUTH — services (règles métier).
 *
 * Un service ne connaît ni $_POST ni les vues : il reçoit des valeurs,
 * applique la règle, retourne un résultat. C'est ce qui le rend testable
 * et réutilisable depuis une API ou une tâche planifiée.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * Prépare une réinitialisation de mot de passe.
 *
 * @return array{ok: bool, link: string|null}
 */
function auth_service_request_reset(string $identifier): array
{
    $user = auth_repo_find_by_identifier($identifier);

    // Le compte n'existe pas : on retourne un succès silencieux.
    // Le contrôleur affichera le même message dans tous les cas, afin de
    // ne pas transformer ce formulaire en outil d'énumération de comptes.
    if ($user === null || $user['status'] !== 'active') {
        // Coût artificiel équivalent à une vraie génération, pour que la
        // durée de réponse ne trahisse pas l'existence du compte.
        password_hash(str_random(16), PASSWORD_BCRYPT, ['cost' => (int) config('security.password_cost', 12)]);

        return ['ok' => true, 'link' => null];
    }

    // Le jeton en clair n'est jamais stocké : seul son hachage l'est.
    // Une fuite de la table ne permet donc pas de forger un lien valide.
    $plainToken = str_random(64);
    $ttl        = (int) config('security.reset_token_ttl', 3600);

    auth_repo_invalidate_resets((int) $user['id']);

    auth_repo_create_reset(
        (int) $user['id'],
        hash('sha256', $plainToken),
        date('Y-m-d H:i:s', time() + $ttl)
    );

    audit_log('password_reset_requested', 'user', (int) $user['id'], null, null, 'Demande de réinitialisation');

    $link = app_url('mot-de-passe/reinitialiser/' . $plainToken);

    // L'ENVOI, ENFIN (phase 8A).
    //
    // Beaucoup de comptes n'ont pas d'adresse — un enseignant, un
    // élève. `mail_queue()` le dit sans que ce soit une erreur, et le
    // contrôleur affiche le MÊME message dans tous les cas : varier la
    // réponse selon qu'un envoi a eu lieu transformerait ce formulaire
    // en outil d'énumération de comptes, ce que tout le reste de cette
    // fonction s'emploie à empêcher.
    $mail = ['ok' => false, 'sent' => false, 'error' => 'Aucune adresse enregistrée.'];

    if (($user['email'] ?? null) !== null && trim((string) $user['email']) !== '') {
        $mail = auth_send_reset_email($user, $link);
    }

    return [
        'ok'   => true,
        'link' => $link,
        'mail' => $mail,
    ];
}

/**
 * Compose et met en file le message de réinitialisation.
 *
 * LE CORPS EST MARQUÉ SENSIBLE : il contient le jeton en clair, celui
 * dont `password_resets` ne garde que le haché précisément pour qu'une
 * fuite de la base ne permette pas d'en forger un. Chiffré au repos,
 * effacé dès l'envoi.
 *
 * @return array{ok: bool, sent: bool, error: ?string}
 */
function auth_send_reset_email(array $user, string $link): array
{
    $minutes = max(1, (int) round(((int) config('security.reset_token_ttl', 3600)) / 60));
    $name    = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));

    $text = "Bonjour " . $name . ",\n\n"
        . "Une réinitialisation de mot de passe a été demandée pour le compte «"
        . " " . (string) $user['username'] . " ».\n\n"
        . "Ouvrez ce lien pour choisir un nouveau mot de passe :\n"
        . $link . "\n\n"
        . "Ce lien expire dans " . $minutes . " minutes et ne peut servir qu'une fois.\n\n"
        . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : "
        . "votre mot de passe actuel reste valable.\n";

    $html = mail_render_html(
        'Réinitialisation de votre mot de passe',
        '<p>Bonjour ' . e($name) . ',</p>'
        . '<p>Une réinitialisation a été demandée pour le compte '
        . '<strong>' . e((string) $user['username']) . '</strong>.</p>'
        . mail_button('Choisir un nouveau mot de passe', $link)
        . '<p style="margin-top:24px;">Ce lien expire dans <strong>' . $minutes
        . ' minutes</strong> et ne peut servir qu\'une fois.</p>',
        'Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : '
        . 'votre mot de passe actuel reste valable.'
    );

    $out = mail_queue([
        'to'           => (string) $user['email'],
        'to_name'      => $name,
        'subject'      => 'Réinitialisation de votre mot de passe',
        'text'         => $text,
        'html'         => $html,
        'purpose'      => 'password_reset',
        'school_id'    => $user['school_id'] !== null ? (int) $user['school_id'] : null,
        'sensitive'    => true,
        'related_type' => 'users',
        'related_id'   => (int) $user['id'],
    ]);

    return ['ok' => $out['ok'], 'sent' => $out['sent'], 'error' => $out['error']];
}

/**
 * Applique une réinitialisation à partir d'un jeton.
 *
 * @return array{ok: bool, message: string}
 */
function auth_service_reset_password(string $plainToken, string $newPassword): array
{
    $reset = auth_repo_find_valid_reset($plainToken);

    if ($reset === null) {
        return ['ok' => false, 'message' => 'Ce lien est invalide ou a expiré.'];
    }

    return db_transaction(static function () use ($reset, $newPassword): array {
        auth_repo_update_password((int) $reset['user_id'], auth_hash_password($newPassword), false);
        auth_repo_mark_reset_used((int) $reset['id']);

        // Toutes les sessions ouvertes sont révoquées : si le compte avait
        // été compromis, l'attaquant est déconnecté immédiatement.
        auth_repo_revoke_all_sessions((int) $reset['user_id']);

        audit_log('password_reset', 'user', (int) $reset['user_id'], null, null, 'Mot de passe réinitialisé');

        return ['ok' => true, 'message' => ''];
    });
}

/** Change le mot de passe d'un utilisateur connecté. */
function auth_service_change_password(int $userId, string $newPassword): void
{
    db_transaction(static function () use ($userId, $newPassword): void {
        auth_repo_update_password($userId, auth_hash_password($newPassword), false);
        auth_repo_invalidate_resets($userId);

        audit_log('password_changed', 'user', $userId, null, null, 'Mot de passe modifié par l\'utilisateur');
    });
}

/**
 * Réinitialise le mot de passe d'un utilisateur par un administrateur.
 * Retourne le mot de passe provisoire, à transmettre de vive voix.
 *
 * Le drapeau must_change_password force le changement à la connexion :
 * l'administrateur ne conserve donc aucun accès au compte.
 */
function auth_service_admin_reset(int $targetUserId): string
{
    if (!perm_can_manage_user($targetUserId)) {
        abort(403);
    }

    // Mot de passe lisible mais imprévisible : il sera dicté à l'utilisateur.
    $temporary = 'Ecole' . random_int(1000, 9999) . substr(str_random(6), 0, 4);

    db_transaction(static function () use ($targetUserId, $temporary): void {
        auth_repo_update_password($targetUserId, auth_hash_password($temporary), true);
        auth_repo_revoke_all_sessions($targetUserId);
        auth_repo_invalidate_resets($targetUserId);

        audit_log(
            'password_admin_reset',
            'user',
            $targetUserId,
            null,
            null,
            'Mot de passe réinitialisé par un administrateur'
        );
    });

    return $temporary;
}
