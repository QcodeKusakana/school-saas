<?php
/**
 * Gestion des sessions.
 *
 * Durcissements appliqués :
 *   - cookie HttpOnly + SameSite (protection XSS / CSRF)
 *   - stockage hors du répertoire web (storage/sessions)
 *   - régénération périodique de l'identifiant (protection contre la fixation)
 *   - double expiration : inactivité ET durée absolue
 *   - empreinte navigateur : une session volée depuis un autre poste est rejetée
 */

declare(strict_types=1);

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $savePath = config('session.save_path') ?: storage_path('sessions');

    if (!is_dir($savePath)) {
        @mkdir($savePath, 0775, true);
    }

    if (is_dir($savePath) && is_writable($savePath)) {
        session_save_path($savePath);
    }

    // Interdit à PHP d'accepter un identifiant de session non initialisé
    // par le serveur (protection contre la fixation de session).
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) (int) config('session.absolute_timeout', 43200));

    session_name((string) config('session.name', 'SCHOOLSAAS_SID'));

    session_set_cookie_params([
        'lifetime' => 0,                 // cookie de session : expire à la fermeture
        'path'     => '/',
        'domain'   => '',
        'secure'   => (bool) config('session.cookie_secure', false),
        'httponly' => true,
        'samesite' => (string) config('session.cookie_samesite', 'Lax'),
    ]);

    session_start();

    session_enforce_lifetime();
    session_enforce_fingerprint();
    session_rotate_id();
}

/**
 * Applique les deux expirations : inactivité et durée absolue.
 */
function session_enforce_lifetime(): void
{
    $now      = time();
    $idle     = (int) config('session.idle_timeout', 3600);
    $absolute = (int) config('session.absolute_timeout', 43200);

    if (!isset($_SESSION['started_at'])) {
        $_SESSION['started_at'] = $now;
    }

    if (isset($_SESSION['last_activity'])) {
        $inactiveFor = $now - (int) $_SESSION['last_activity'];
        $aliveFor    = $now - (int) $_SESSION['started_at'];

        if ($inactiveFor > $idle || $aliveFor > $absolute) {
            $reason = $inactiveFor > $idle ? 'inactivity' : 'absolute_timeout';
            session_destroy_secure();
            session_start_secure();
            $_SESSION['expired_reason'] = $reason;

            return;
        }
    }

    $_SESSION['last_activity'] = $now;
}

/**
 * Empreinte du client. Volontairement limitée à l'agent utilisateur :
 * inclure l'adresse IP déconnecterait en permanence les utilisateurs
 * en réseau mobile congolais, où l'IP change très souvent.
 */
function session_enforce_fingerprint(): void
{
    $fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . config('app.name'));

    if (!isset($_SESSION['fingerprint'])) {
        $_SESSION['fingerprint'] = $fingerprint;

        return;
    }

    if (!hash_equals($_SESSION['fingerprint'], $fingerprint)) {
        log_warning('Empreinte de session invalide — session détruite', [
            'user_id' => $_SESSION['user_id'] ?? null,
        ]);
        session_destroy_secure();
        session_start_secure();
    }
}

/**
 * Régénère périodiquement l'identifiant de session sans perdre les données.
 */
function session_rotate_id(): void
{
    $interval = (int) config('session.regenerate_every', 900);

    if ($interval <= 0) {
        return;
    }

    $last = (int) ($_SESSION['regenerated_at'] ?? 0);

    if ($last === 0 || (time() - $last) > $interval) {
        session_regenerate_id(true);
        $_SESSION['regenerated_at'] = time();
    }
}

/** Détruit complètement la session et son cookie. */
function session_destroy_secure(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/** Lit une valeur de session. */
function session_get(string $key, mixed $default = null): mixed
{
    return $_SESSION[$key] ?? $default;
}

/** Écrit une valeur de session. */
function session_put(string $key, mixed $value): void
{
    $_SESSION[$key] = $value;
}

/** Lit puis supprime une valeur de session. */
function session_pull(string $key, mixed $default = null): mixed
{
    $value = $_SESSION[$key] ?? $default;
    unset($_SESSION[$key]);

    return $value;
}
