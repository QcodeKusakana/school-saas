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

    return [
        'ok'   => true,
        'link' => app_url('mot-de-passe/reinitialiser/' . $plainToken),
    ];
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
