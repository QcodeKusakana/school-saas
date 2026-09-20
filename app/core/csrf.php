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

// =====================================================================
//  JETON À USAGE UNIQUE — CONTRE LE DOUBLE ENVOI
//
//  Le jeton CSRF ci-dessus vit deux heures et sert à TOUS les
//  formulaires : il prouve l'origine de la requête, jamais son unicité.
//  Un double clic sur « Encaisser » le présente deux fois, et deux reçus
//  naissent pour un seul billet (défaut trouvé à l'audit 5D, vérifié sur
//  les dépenses comme sur les paiements).
//
//  Le mécanisme ci-dessous est réservé aux écrans qui CRÉENT de
//  l'argent. L'appliquer partout casserait le retour arrière sur des
//  formulaires anodins sans rien protéger.
//
//  Il ne remplace pas le jeton CSRF : les deux sont vérifiés.
// =====================================================================

const FORM_NONCE_FIELD = '_once';
const FORM_NONCE_KEEP  = 12;

/**
 * Jeton à usage unique pour un formulaire donné.
 *
 * `$scope` isole les formulaires entre eux : un jeton d'encaissement
 * ne vaut pas pour une dépense.
 */
function form_nonce(string $scope): string
{
    $token = str_random(32);

    $_SESSION['form_nonces'][$scope][] = $token;

    // Un caissier peut avoir plusieurs onglets ouverts. On garde les
    // douze derniers jetons de chaque écran, pas un seul : sinon
    // l'ouverture d'un second dossier invaliderait le premier.
    $_SESSION['form_nonces'][$scope] = array_slice(
        $_SESSION['form_nonces'][$scope],
        -FORM_NONCE_KEEP
    );

    return $token;
}

/** Champ caché correspondant. */
function form_nonce_field(string $scope): string
{
    return '<input type="hidden" name="' . FORM_NONCE_FIELD . '" value="'
        . e(form_nonce($scope)) . '">';
}

/**
 * Consomme le jeton présenté. Vrai UNE seule fois par jeton.
 *
 * Le second envoi du même formulaire retourne faux : c'est au contrôleur
 * de l'annoncer clairement plutôt que de créer une deuxième écriture.
 */
function form_nonce_consume(string $scope, string $token): bool
{
    $tokens = $_SESSION['form_nonces'][$scope] ?? [];
    $index  = array_search($token, $tokens, true);

    if ($token === '' || $index === false) {
        return false;
    }

    unset($_SESSION['form_nonces'][$scope][$index]);

    $_SESSION['form_nonces'][$scope] = array_values($_SESSION['form_nonces'][$scope]);

    return true;
}
