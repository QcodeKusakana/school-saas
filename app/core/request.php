<?php
/**
 * Accès normalisé aux données de la requête HTTP.
 *
 * Principe : aucune fonction métier ne lit directement $_GET / $_POST.
 * Tout passe par ici, ce qui garantit un traitement homogène
 * (trim, type, valeur par défaut) et facilite les tests.
 */

declare(strict_types=1);

/** Méthode HTTP, en tenant compte de la surcharge _method des formulaires. */
function request_method(): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'POST' && isset($_POST['_method'])) {
        $override = strtoupper((string) $_POST['_method']);

        if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
            return $override;
        }
    }

    return $method;
}

/**
 * Chemin demandé, sans query string ni sous-dossier d'installation.
 *
 * Le préfixe est retiré via base_uri(), la même fonction que celle
 * utilisée pour construire les liens : les deux ne peuvent donc pas
 * diverger. Une URL produite par url() est toujours reconnue ici.
 */
function request_path(): string
{
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_uri();

    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }

    $uri = '/' . trim($uri, '/');

    return $uri === '/' ? '/' : rtrim($uri, '/');
}

/**
 * Valeur d'entrée (POST prioritaire sur GET), nettoyée.
 * Les chaînes sont trimées ; les tableaux sont retournés tels quels.
 */
function input(string $key, mixed $default = null): mixed
{
    $value = $_POST[$key] ?? $_GET[$key] ?? null;

    if ($value === null) {
        return $default;
    }

    if (is_string($value)) {
        $value = trim($value);

        // Supprime les octets nuls, vecteur classique de contournement
        // des vérifications d'extension de fichier.
        $value = str_replace("\0", '', $value);

        return $value === '' ? $default : $value;
    }

    return $value;
}

/** Entrée forcée en entier. */
function input_int(string $key, ?int $default = null): ?int
{
    $value = input($key);

    if ($value === null || !is_scalar($value)) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $default;
}

/** Entrée forcée en nombre décimal. */
function input_float(string $key, ?float $default = null): ?float
{
    $value = input($key);

    if ($value === null || !is_scalar($value)) {
        return $default;
    }

    $value = str_replace(',', '.', (string) $value);

    return is_numeric($value) ? (float) $value : $default;
}

/** Entrée forcée en booléen (cases à cocher). */
function input_bool(string $key): bool
{
    return in_array(input($key), ['1', 'on', 'true', 'yes', 1, true], true);
}

/** Entrée de type tableau (cases à cocher multiples, listes). */
function input_array(string $key): array
{
    $value = $_POST[$key] ?? $_GET[$key] ?? [];

    return is_array($value) ? $value : [];
}

/** Toutes les entrées, sauf les clés exclues. */
function input_all(array $except = []): array
{
    $data = array_merge($_GET, $_POST);

    foreach (array_merge($except, [CSRF_FIELD, '_method']) as $key) {
        unset($data[$key]);
    }

    return $data;
}

/** Fichier téléversé normalisé, ou null. */
function input_file(string $key): ?array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        return null;
    }

    $file = $_FILES[$key];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return $file;
}

/** Numéro de page demandé, borné. */
function request_page(): int
{
    return max(1, (int) (input_int('page', 1) ?? 1));
}

/** Taille de page demandée, bornée par la configuration. */
function request_per_page(): int
{
    $default = (int) config('pagination.per_page', 25);
    $max     = (int) config('pagination.per_page_max', 200);

    return max(1, min($max, (int) (input_int('per_page', $default) ?? $default)));
}

/** Adresse IP du client, en tenant compte d'un éventuel proxy de confiance. */
function request_ip(): ?string
{
    // Attention : n'activer la lecture de X-Forwarded-For que derrière un
    // proxy maîtrisé (Cloudflare, reverse proxy). Sinon l'en-tête est
    // falsifiable et contournerait la limitation de tentatives.
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/** URL précédente sûre (interne uniquement). */
function request_referer(string $fallback = '/'): string
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if ($referer === '') {
        return $fallback;
    }

    $host        = parse_url($referer, PHP_URL_HOST);
    $currentHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    // Empêche une redirection ouverte vers un domaine externe.
    return $host === $currentHost ? $referer : $fallback;
}
