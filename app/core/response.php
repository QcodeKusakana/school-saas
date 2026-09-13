<?php
/**
 * Émission des réponses HTTP.
 */

declare(strict_types=1);

/**
 * En-têtes de sécurité envoyés sur chaque réponse HTML.
 *
 * La CSP est volontairement stricte : aucun script inline n'est autorisé
 * sans nonce. Si une bibliothèque tierce exige 'unsafe-inline', c'est le
 * signe qu'il faut l'écarter plutôt qu'affaiblir la politique.
 */
function response_security_headers(): void
{
    if (!config('security.send_security_headers', true) || headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cross-Origin-Opener-Policy: same-origin');

    if ((bool) config('session.cookie_secure', false)) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $nonce = response_csp_nonce();

    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'nonce-{$nonce}'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; "
        . "font-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}

/** Nonce CSP de la requête courante, à placer sur chaque balise script. */
function response_csp_nonce(): string
{
    static $nonce = null;

    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

/**
 * Redirection interne.
 * Les URL externes sont refusées : protection contre la redirection ouverte.
 */
function redirect(string $path, int $status = 302): never
{
    $host = parse_url($path, PHP_URL_HOST);

    if ($host !== null && $host !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
        log_warning('Tentative de redirection externe bloquée', ['path' => $path]);
        $path = '/';
    }

    $url = str_starts_with($path, 'http') ? $path : app_url($path);

    if (!headers_sent()) {
        header('Location: ' . $url, true, $status);
    }

    exit;
}

/** Redirection vers la page précédente. */
function redirect_back(string $fallback = '/'): never
{
    redirect(request_referer($fallback));
}

/** Réponse JSON. */
function json_response(mixed $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Réponse JSON de succès. */
function json_ok(mixed $data = null, string $message = ''): never
{
    json_response(array_filter([
        'success' => true,
        'message' => $message !== '' ? $message : null,
        'data'    => $data,
    ], static fn ($v) => $v !== null));
}

/** Réponse JSON d'erreur. */
function json_error(string $message, int $status = 400, array $errors = []): never
{
    json_response(array_filter([
        'success' => false,
        'message' => $message,
        'errors'  => $errors ?: null,
    ], static fn ($v) => $v !== null), $status);
}

/**
 * Arrête la requête sur une page d'erreur.
 * Répond en JSON si la requête est AJAX.
 */
function abort(int $status, string $message = ''): never
{
    $messages = [
        400 => 'Requête invalide.',
        401 => 'Authentification requise.',
        403 => 'Vous n\'avez pas l\'autorisation d\'accéder à cette page.',
        404 => 'Page introuvable.',
        419 => 'Votre session a expiré. Veuillez recharger la page.',
        429 => 'Trop de tentatives. Veuillez réessayer plus tard.',
        500 => 'Une erreur interne est survenue.',
    ];

    $message = $message !== '' ? $message : ($messages[$status] ?? 'Erreur.');

    if (is_ajax()) {
        json_error($message, $status);
    }

    if (!headers_sent()) {
        http_response_code($status);
    }

    $view = APP_PATH . '/views/errors/' . $status . '.php';

    if (is_file($view)) {
        // La vue d'erreur est autonome : elle ne dépend d'aucun layout
        // ni d'aucune donnée, afin de rester affichable même si la base
        // de données est inaccessible.
        require $view;
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Erreur ' . $status . '</title>'
            . '<p style="font-family:system-ui;padding:2rem">' . e($message) . '</p>';
    }

    exit;
}
