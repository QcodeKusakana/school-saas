<?php
/**
 * Fonctions utilitaires transverses.
 *
 * Convention de nommage du projet (voir docs/CONVENTIONS.md) :
 *   - noyau         : préfixe du module technique  -> db_*, auth_*, perm_*
 *   - utilitaires   : nom court sans préfixe       -> e(), config(), csrf_field()
 *   - métier        : préfixe du module métier     -> student_*, grade_*
 * Aucune fonction ne doit être déclarée sans préfixe dans un module métier.
 */

declare(strict_types=1);

/**
 * Lit une valeur de configuration avec une notation pointée.
 *
 *   config('app.debug')          -> bool
 *   config('database.host')      -> string
 *   config('uploads.max_size')   -> int
 */
function config(string $key, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = require APP_PATH . '/config/config.php';
    }

    $value = $config;

    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

/** Chemin absolu depuis la racine du projet. */
function base_path(string $path = ''): string
{
    return BASE_PATH . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/** Chemin absolu dans storage/ (jamais accessible depuis le web). */
function storage_path(string $path = ''): string
{
    return BASE_PATH . '/storage' . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/** URL absolue de l'application. */
function app_url(string $path = ''): string
{
    return app_origin() . base_uri() . '/' . ltrim($path, '/');
}

/**
 * Préfixe d'URL sous lequel l'application est servie.
 *
 * Retourne '' quand la racine web pointe sur public/ (cas normal), ou
 * '/school-saas/public' quand l'application vit dans un sous-dossier.
 *
 * Déduit de SCRIPT_NAME, c'est-à-dire de la réalité du serveur — et non
 * d'une valeur de configuration qui peut être fausse ou oubliée. C'est
 * ce qui rend les liens CSS, JS et les redirections corrects quelle que
 * soit la configuration d'Apache.
 */
function base_uri(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

    // SCRIPT_NAME doit désigner le script réellement exécuté
    // (/index.php, ou /school-saas/public/index.php). Certains serveurs
    // — dont le serveur intégré de PHP en mode routeur — y placent
    // l'URI demandée : on ne s'y fie donc que s'il s'agit bien d'un
    // fichier PHP, sinon on considère le préfixe comme vide.
    if (!str_ends_with(strtolower($scriptName), '.php')) {
        return $base = '';
    }

    $directory = str_replace('\\', '/', dirname($scriptName));

    return $base = ($directory === '/' || $directory === '.') ? '' : rtrim($directory, '/');
}

/**
 * Origine réelle de la requête : schéma + hôte + port.
 *
 * Reconstruite depuis les en-têtes de la requête plutôt que lue dans
 * app.url. Conséquence directe : que l'on ouvre le site par
 * school-saas.test:8000, localhost:8000 ou 127.0.0.1, les liens
 * générés restent valides, sans aucune modification de configuration.
 *
 * app.url ne sert plus que hors contexte HTTP (ligne de commande,
 * tâches planifiées, liens envoyés par email).
 */
function app_origin(): string
{
    static $origin = null;

    if ($origin !== null) {
        return $origin;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';

    if ($host === '') {
        // Contexte CLI : on retombe sur la configuration.
        return $origin = rtrim((string) config('app.url'), '/');
    }

    $isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;

    // L'en-tête Host est fourni par le client : on le valide avant usage
    // pour éviter qu'une valeur forgée ne se retrouve dans un lien.
    if (!preg_match('/^[a-zA-Z0-9\.\-]+(:\d{1,5})?$/', $host)) {
        return $origin = rtrim((string) config('app.url'), '/');
    }

    return $origin = ($isHttps ? 'https://' : 'http://') . $host;
}

/**
 * Échappement HTML. À utiliser SYSTÉMATIQUEMENT dans les vues.
 *
 * Règle du projet : toute variable affichée passe par e().
 * Une sortie non échappée doit être justifiée par un commentaire explicite.
 */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Échappement pour insertion dans un contexte JavaScript. */
function e_js(mixed $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?: 'null';
}

/** Accès sécurisé à une clé de tableau. */
function array_get(array $array, string $key, mixed $default = null): mixed
{
    return array_key_exists($key, $array) ? $array[$key] : $default;
}

/**
 * UUID v4. Utilisé pour les identifiants publics (schools.uuid, users.uuid)
 * et les clés d'idempotence de la synchronisation hors ligne.
 */
function str_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** Chaîne aléatoire hexadécimale cryptographiquement sûre. */
function str_random(int $length = 32): string
{
    return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
}

/** Slug URL sans accent. */
function str_slug(string $value, string $separator = '-'): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', $separator, $value) ?? '';

    return trim($value, $separator);
}

/**
 * Convertit une adresse IP en binaire pour stockage en VARBINARY(16).
 * Gère IPv4 et IPv6 avec la même colonne.
 */
function ip_binary(?string $ip = null): ?string
{
    $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null);

    if (!$ip) {
        return null;
    }

    $packed = @inet_pton($ip);

    return $packed === false ? null : $packed;
}

/** Inverse de ip_binary(). */
function ip_readable(?string $binary): ?string
{
    if ($binary === null || $binary === '') {
        return null;
    }

    $ip = @inet_ntop($binary);

    return $ip === false ? null : $ip;
}

/** Agent utilisateur tronqué à la taille de la colonne. */
function user_agent(): ?string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

    return $ua ? mb_substr($ua, 0, 255) : null;
}

/** Date formatée pour l'affichage (jj/mm/aaaa). */
function format_date(?string $date, string $format = 'd/m/Y'): string
{
    if (!$date || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);

    return $ts === false ? '' : date($format, $ts);
}

/** Date et heure formatées pour l'affichage. */
function format_datetime(?string $datetime, string $format = 'd/m/Y H:i'): string
{
    return format_date($datetime, $format);
}

/** Montant formaté avec séparateur de milliers. */
function format_money(float|int|string|null $amount, string $currency = 'CDF'): string
{
    $amount = (float) ($amount ?? 0);

    return number_format($amount, 2, ',', ' ') . ' ' . $currency;
}

/** Nom complet selon l'usage RDC : NOM Postnom Prénom. */
function full_name(?string $lastName, ?string $postName, ?string $firstName): string
{
    return trim(implode(' ', array_filter([
        $lastName ? mb_strtoupper($lastName) : null,
        $postName,
        $firstName,
    ])));
}

/** Vrai si la requête courante est une requête AJAX. */
function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

/** Horodatage MySQL courant. */
function now(): string
{
    return date('Y-m-d H:i:s');
}
