<?php
/**
 * Module AUTH — dépôts (accès aux données).
 *
 * Toutes les requêtes SQL du module vivent ici, et nulle part ailleurs.
 * Bénéfice concret : pour auditer l'exposition des comptes, il suffit de
 * relire ce fichier.
 *
 * Note sur le 3e argument `true` de db_query : ces requêtes portent sur
 * l'identité et s'exécutent AVANT l'établissement du contexte école
 * (connexion, réinitialisation). Elles sont donc volontairement hors
 * périmètre du garde-fou multi-établissement, mais toujours ciblées par
 * clé primaire ou par identifiant unique global.
 */

declare(strict_types=1);

/** Recherche un compte par nom d'utilisateur ou adresse email. */
function auth_repo_find_by_identifier(string $identifier): ?array
{
    // Deux paramètres distincts pour une même valeur : voir la note dans
    // auth_attempt() — les requêtes préparées natives interdisent la
    // réutilisation d'un paramètre nommé.
    return db_one(
        'SELECT id, school_id, username, email, status, first_name, last_name
           FROM users
          WHERE (username = :username OR email = :email)
            AND deleted_at IS NULL
          LIMIT 1',
        ['username' => $identifier, 'email' => $identifier],
        true
    );
}

/** Recherche un compte par identifiant technique. */
function auth_repo_find_by_id(int $userId): ?array
{
    return db_one(
        'SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1',
        ['id' => $userId],
        true
    );
}

/** Met à jour le mot de passe et l'horodatage associé. */
function auth_repo_update_password(int $userId, string $hash, bool $mustChange): int
{
    return db_query(
        'UPDATE users
            SET password_hash = :hash,
                password_changed_at = :now,
                must_change_password = :must_change,
                failed_attempts = 0,
                locked_until = NULL
          WHERE id = :id',
        [
            'hash'        => $hash,
            'now'         => now(),
            'must_change' => $mustChange ? 1 : 0,
            'id'          => $userId,
        ],
        true
    )->rowCount();
}

// ---------------------------------------------------------------------
//  JETONS DE RÉINITIALISATION
// ---------------------------------------------------------------------

/** Enregistre un jeton de réinitialisation (déjà haché par l'appelant). */
function auth_repo_create_reset(int $userId, string $tokenHash, string $expiresAt): int
{
    return db_insert('password_resets', [
        'user_id'    => $userId,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
        'ip_address' => ip_binary(),
    ], true);
}

/**
 * Retrouve un jeton valide à partir de sa valeur en clair.
 * La recherche se fait sur le hachage : la colonne indexée reste sûre.
 */
function auth_repo_find_valid_reset(string $plainToken): ?array
{
    return db_one(
        'SELECT id, user_id, expires_at
           FROM password_resets
          WHERE token_hash = :hash
            AND used_at IS NULL
            AND expires_at > :now
          LIMIT 1',
        ['hash' => hash('sha256', $plainToken), 'now' => now()],
        true
    );
}

/** Marque un jeton comme consommé (usage unique). */
function auth_repo_mark_reset_used(int $resetId): int
{
    return db_query(
        'UPDATE password_resets SET used_at = :now WHERE id = :id AND used_at IS NULL',
        ['now' => now(), 'id' => $resetId],
        true
    )->rowCount();
}

/** Invalide tous les jetons en attente d'un utilisateur. */
function auth_repo_invalidate_resets(int $userId): int
{
    return db_query(
        'UPDATE password_resets SET used_at = :now WHERE user_id = :user_id AND used_at IS NULL',
        ['now' => now(), 'user_id' => $userId],
        true
    )->rowCount();
}

// ---------------------------------------------------------------------
//  SESSIONS
// ---------------------------------------------------------------------

/** Révoque toutes les sessions actives d'un utilisateur. */
function auth_repo_revoke_all_sessions(int $userId): int
{
    return db_query(
        'UPDATE user_sessions SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL',
        ['now' => now(), 'user_id' => $userId],
        true
    )->rowCount();
}

/** Liste les sessions actives d'un utilisateur (page « Mes appareils »). */
function auth_repo_active_sessions(int $userId): array
{
    return db_all(
        'SELECT id, ip_address, user_agent, last_activity, created_at
           FROM user_sessions
          WHERE user_id = :user_id AND revoked_at IS NULL
          ORDER BY last_activity DESC',
        ['user_id' => $userId],
        true
    );
}
