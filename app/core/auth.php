<?php
/**
 * Authentification.
 *
 * Le contexte authentifié est chargé une fois par requête depuis la base,
 * jamais reconstruit uniquement à partir de la session : si un compte est
 * suspendu ou si son école est désactivée, l'accès est coupé dès la
 * requête suivante, sans attendre l'expiration de la session.
 */

declare(strict_types=1);

/**
 * Tente d'authentifier un utilisateur.
 *
 * @return array{ok: bool, message: string, user: array|null}
 */
function auth_attempt(string $identifier, string $password, bool $remember = false): array
{
    $ip = ip_binary();

    // 1. Limitation des tentatives AVANT toute requête coûteuse.
    if (!auth_throttle_allows($identifier, $ip)) {
        auth_record_attempt($identifier, $ip, false);

        return [
            'ok'      => false,
            'message' => 'Trop de tentatives de connexion. Réessayez dans quelques minutes.',
            'user'    => null,
        ];
    }

    // 2. Recherche du compte.
    // Requête volontairement inter-écoles : à ce stade, aucun établissement
    // n'est encore dans le contexte — c'est la connexion qui l'établit.
    // Le même identifiant est lié DEUX FOIS sous deux noms distincts :
    // avec les requêtes réellement préparées côté MySQL
    // (ATTR_EMULATE_PREPARES = false), un paramètre nommé ne peut pas être
    // réutilisé dans une même requête. Le pilote rejette la requête.
    $user = tenant_scope_identity(static fn (): ?array => db_one(
        'SELECT u.*, s.status AS school_status, s.name AS school_name
           FROM users u
           LEFT JOIN schools s ON s.id = u.school_id
          WHERE (u.username = :username OR u.email = :email)
            AND u.deleted_at IS NULL
          LIMIT 1',
        ['username' => $identifier, 'email' => $identifier],
        true
    ));

    // 3. Vérification du mot de passe.
    // On exécute password_verify même quand le compte est introuvable, afin
    // que la durée de réponse ne révèle pas l'existence d'un identifiant.
    $hash    = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
    $isValid = password_verify($password, $hash);

    if (!$user || !$isValid) {
        auth_record_attempt($identifier, $ip, false);
        auth_increment_failures($user['id'] ?? null);

        return [
            'ok'      => false,
            // Message volontairement générique : ne jamais indiquer si
            // c'est l'identifiant ou le mot de passe qui est erroné.
            'message' => 'Identifiant ou mot de passe incorrect.',
            'user'    => null,
        ];
    }

    // 4. Contrôles d'état du compte.
    if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
        auth_record_attempt($identifier, $ip, false);

        return ['ok' => false, 'message' => 'Ce compte est temporairement verrouillé.', 'user' => null];
    }

    if ($user['status'] !== 'active') {
        auth_record_attempt($identifier, $ip, false);

        return ['ok' => false, 'message' => 'Ce compte n\'est pas actif. Contactez votre administrateur.', 'user' => null];
    }

    // 5. Contrôle d'état de l'établissement (sauf super admin plateforme).
    if ($user['school_id'] !== null && $user['school_status'] !== 'active') {
        auth_record_attempt($identifier, $ip, false);

        return [
            'ok'      => false,
            'message' => 'L\'accès à votre établissement est actuellement suspendu.',
            'user'    => null,
        ];
    }

    // 6. Succès.
    auth_record_attempt($identifier, $ip, true);
    auth_login($user, $remember);

    return ['ok' => true, 'message' => '', 'user' => $user];
}

/** Ouvre la session applicative pour un utilisateur vérifié. */
function auth_login(array $user, bool $remember = false): void
{
    // Nouvel identifiant de session : évite la fixation de session,
    // où un attaquant impose un identifiant avant la connexion.
    session_regenerate_id(true);

    $_SESSION['user_id']        = (int) $user['id'];
    $_SESSION['school_id']      = $user['school_id'] !== null ? (int) $user['school_id'] : null;
    $_SESSION['started_at']     = time();
    $_SESSION['last_activity']  = time();
    $_SESSION['regenerated_at'] = time();

    tenant_set($_SESSION['school_id']);

    tenant_scope_identity(static function () use ($user): void {
        db_query(
            'UPDATE users
                SET last_login_at = :now, last_login_ip = :ip, failed_attempts = 0, locked_until = NULL
              WHERE id = :id',
            ['now' => now(), 'ip' => ip_binary(), 'id' => (int) $user['id']],
            true
        );
    });

    auth_track_session((int) $user['id'], $_SESSION['school_id']);

    // Les caches de la requête ont été renseignés AVANT la connexion, alors
    // qu'aucun utilisateur n'était identifié. Sans cette réinitialisation,
    // auth_display_name() et les permissions resteraient vides jusqu'à la
    // requête suivante.
    auth_user(true);
    perm_all(true);
    perm_roles(true);

    audit_log('login', 'user', (int) $user['id'], null, null, 'Connexion réussie');
}

/** Ferme la session applicative. */
function auth_logout(): void
{
    $userId = auth_id();

    if ($userId !== null) {
        audit_log('logout', 'user', $userId, null, null, 'Déconnexion');
        auth_revoke_session($userId);
    }

    session_destroy_secure();
}

/** Vrai si un utilisateur valide est connecté. */
function auth_check(): bool
{
    return auth_user() !== null;
}

/** Identifiant de l'utilisateur connecté, ou null. */
function auth_id(): ?int
{
    $user = auth_user();

    return $user !== null ? (int) $user['id'] : null;
}

/**
 * Utilisateur connecté, rechargé depuis la base une fois par requête.
 *
 * Recharger plutôt que se fier à la session est un choix délibéré : c'est
 * ce qui rend effective, en temps réel, la désactivation d'un compte ou la
 * suspension d'une école.
 */
function auth_user(bool $refresh = false): ?array
{
    static $user = false; // false = pas encore chargé, null = non connecté

    if ($refresh) {
        $user = false;
    }

    if ($user !== false) {
        return $user;
    }

    $userId = $_SESSION['user_id'] ?? null;

    if (!$userId) {
        return $user = null;
    }

    // Rechargement du compte à chaque requête. L'école n'est pas encore
    // dans le contexte : c'est CETTE requête qui l'y place.
    $row = tenant_scope_identity(static fn (): ?array => db_one(
        'SELECT u.*, s.name AS school_name, s.status AS school_status, s.logo_path AS school_logo
           FROM users u
           LEFT JOIN schools s ON s.id = u.school_id
          WHERE u.id = :id AND u.deleted_at IS NULL
          LIMIT 1',
        ['id' => (int) $userId],
        true
    ));

    if (!$row || $row['status'] !== 'active') {
        session_destroy_secure();

        return $user = null;
    }

    if ($row['school_id'] !== null && $row['school_status'] !== 'active') {
        session_destroy_secure();

        return $user = null;
    }

    // Le contexte multi-école est réétabli à chaque requête depuis la base,
    // et non depuis la session : une session altérée ne peut pas changer d'école.
    tenant_set($row['school_id'] !== null ? (int) $row['school_id'] : null);

    return $user = $row;
}

/** Vrai si l'utilisateur est administrateur de la plateforme SaaS. */
function auth_is_platform_admin(): bool
{
    $user = auth_user();

    return $user !== null && $user['school_id'] === null && perm_has('platform.school.view');
}

/** Nom affichable de l'utilisateur connecté. */
function auth_display_name(): string
{
    $user = auth_user();

    if ($user === null) {
        return '';
    }

    return trim($user['first_name'] . ' ' . $user['last_name']);
}

/** Hache un mot de passe selon la politique du projet. */
function auth_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, [
        'cost' => (int) config('security.password_cost', 12),
    ]);
}

/**
 * Réhache le mot de passe si le coût de l'algorithme a été relevé.
 * Appelée après une connexion réussie, de façon transparente.
 */
function auth_rehash_if_needed(int $userId, string $plainPassword, string $currentHash): void
{
    $needsRehash = password_needs_rehash($currentHash, PASSWORD_BCRYPT, [
        'cost' => (int) config('security.password_cost', 12),
    ]);

    if (!$needsRehash) {
        return;
    }

    tenant_scope_identity(static function () use ($plainPassword, $userId): void {
        db_query(
            'UPDATE users SET password_hash = :hash, password_changed_at = :now WHERE id = :id',
            ['hash' => auth_hash_password($plainPassword), 'now' => now(), 'id' => $userId],
            true
        );
    });
}

// ---------------------------------------------------------------------
//  LIMITATION DES TENTATIVES
// ---------------------------------------------------------------------

/**
 * Double limitation : par identifiant ET par adresse IP.
 *
 * Limiter par identifiant seul permettrait à un attaquant de tester un
 * même mot de passe sur des milliers de comptes (credential stuffing).
 * Limiter par IP seule bloquerait toute une école derrière un NAT partagé.
 */
function auth_throttle_allows(string $identifier, ?string $ip): bool
{
    $window = (int) config('security.attempts_window', 900);
    $since  = date('Y-m-d H:i:s', time() - $window);

    $byUser = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts
          WHERE identifier = :identifier AND successful = 0 AND created_at > :since',
        ['identifier' => $identifier, 'since' => $since],
        true
    );

    if ($byUser >= (int) config('security.max_attempts_user', 5)) {
        return false;
    }

    if ($ip === null) {
        return true;
    }

    $byIp = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip_address = :ip AND successful = 0 AND created_at > :since',
        ['ip' => $ip, 'since' => $since],
        true
    );

    return $byIp < (int) config('security.max_attempts_ip', 20);
}

/** Enregistre une tentative de connexion. */
function auth_record_attempt(string $identifier, ?string $ip, bool $successful): void
{
    db_insert('login_attempts', [
        'identifier' => mb_substr($identifier, 0, 190),
        'ip_address' => $ip ?? inet_pton('0.0.0.0'),
        'successful' => $successful ? 1 : 0,
        'user_agent' => user_agent(),
    ], true);

    // Purge opportuniste : évite de dépendre d'une tâche planifiée,
    // souvent indisponible en mutualisé. 1 requête sur 100 environ.
    if (random_int(1, 100) === 1) {
        db_query(
            'DELETE FROM login_attempts WHERE created_at < :limit',
            ['limit' => date('Y-m-d H:i:s', time() - 86400 * 7)],
            true
        );
    }
}

/** Incrémente le compteur d'échecs et verrouille le compte si nécessaire. */
function auth_increment_failures(?int $userId): void
{
    if ($userId === null) {
        return;
    }

    $max      = (int) config('security.max_attempts_user', 5);
    $duration = (int) config('security.lockout_duration', 900);

    tenant_scope_identity(static function () use ($max, $duration, $userId): void {
        db_query(
            'UPDATE users
                SET failed_attempts = failed_attempts + 1,
                    locked_until = IF(failed_attempts + 1 >= :max, :until, locked_until)
              WHERE id = :id',
            [
                'max'   => $max,
                'until' => date('Y-m-d H:i:s', time() + $duration),
                'id'    => $userId,
            ],
            true
        );
    });
}

// ---------------------------------------------------------------------
//  SUIVI DES SESSIONS ACTIVES
// ---------------------------------------------------------------------

/** Enregistre la session courante (permet « déconnecter mes appareils »). */
function auth_track_session(int $userId, ?int $schoolId): void
{
    $token = hash('sha256', session_id());

    db_query(
        'INSERT INTO user_sessions (user_id, school_id, session_token, ip_address, user_agent, last_activity)
         VALUES (:user_id, :school_id, :token, :ip, :ua, :now)
         ON DUPLICATE KEY UPDATE last_activity = VALUES(last_activity), revoked_at = NULL',
        [
            'user_id'   => $userId,
            'school_id' => $schoolId,
            'token'     => $token,
            'ip'        => ip_binary(),
            'ua'        => user_agent(),
            'now'       => now(),
        ],
        true
    );
}

/** Révoque la session courante. */
function auth_revoke_session(int $userId): void
{
    db_query(
        'UPDATE user_sessions SET revoked_at = :now
          WHERE user_id = :user_id AND session_token = :token',
        ['now' => now(), 'user_id' => $userId, 'token' => hash('sha256', session_id())],
        true
    );
}
