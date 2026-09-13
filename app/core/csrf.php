<?php
/**
 * Protection CSRF.
 *
 * Un jeton unique par session, renouvelé après expiration.
 * Toute requête POST / PUT / PATCH / DELETE doit présenter le jeton,
 * soit dans le champ _token, soit dans l'en-tête X-CSRF-Token (AJAX).
 *
 * La vérification est appliquée globalement dans public/index.php :
 * il est donc impossible d'oublier de protéger un formulaire.
 */

declare(strict_types=1);

const CSRF_FIELD  = '_token';
const CSRF_HEADER = 'HTTP_X_CSRF_TOKEN';

/** Jeton courant, créé ou renouvelé si nécessaire. */
function csrf_token(): string
{
    $ttl = (int) config('security.csrf_token_ttl', 7200);

    $expired = !isset($_SESSION['csrf_token'], $_SESSION['csrf_token_at'])
        || (time() - (int) $_SESSION['csrf_token_at']) > $ttl;

    if ($expired) {
        $_SESSION['csrf_token']    = str_random(64);
        $_SESSION['csrf_token_at'] = time();
    }

    return $_SESSION['csrf_token'];
}

/** Champ caché à insérer dans chaque formulaire. */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_FIELD . '" value="' . e(csrf_token()) . '">';
}

/** Balise meta à placer dans le <head> pour les requêtes AJAX. */
function csrf_meta(): string
{
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

/**
 * Vérifie le jeton de la requête courante.
 * hash_equals évite les attaques temporelles sur la comparaison.
 */
function csrf_verify(): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if ($sessionToken === '') {
        return false;
    }

    $submitted = $_POST[CSRF_FIELD] ?? $_SERVER[CSRF_HEADER] ?? '';

    if (!is_string($submitted) || $submitted === '') {
        return false;
    }

    return hash_equals($sessionToken, $submitted);
}

/** Vrai si la méthode HTTP courante doit être protégée. */
function csrf_method_requires_check(string $method): bool
{
    return in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}
