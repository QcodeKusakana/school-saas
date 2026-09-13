<?php
/**
 * Routeur.
 *
 * Un point d'entrée unique (public/index.php) et une table de routes
 * explicite. Aucun fichier PHP métier n'est accessible directement par
 * le navigateur : c'est ce qui permet de garantir que l'authentification,
 * la vérification CSRF et le contrôle de permission sont TOUJOURS appliqués.
 *
 * Déclaration d'une route :
 *
 *   route('GET',  '/students',      'students', 'ctrl_students_index', ['auth', 'perm:student.view']);
 *   route('POST', '/students/{id}', 'students', 'ctrl_students_update', ['auth', 'perm:student.edit']);
 *
 * Les paramètres {id} sont transmis au gestionnaire dans l'ordre.
 */

declare(strict_types=1);

/**
 * Enregistre une route.
 *
 * @param string   $method     GET, POST, PUT, PATCH, DELETE
 * @param string   $path       Chemin, paramètres entre accolades
 * @param string   $module     Dossier sous app/modules/
 * @param string   $handler    Nom de la fonction à appeler
 * @param string[] $middleware guest | auth | perm:<code> | platform | school
 */
function route(string $method, string $path, string $module, string $handler, array $middleware = []): void
{
    router_table('add', [
        'method'     => strtoupper($method),
        'path'       => '/' . trim($path, '/'),
        'module'     => $module,
        'handler'    => $handler,
        'middleware' => $middleware,
    ]);
}

/** Stockage interne de la table de routes. */
function router_table(string $action, ?array $route = null): array
{
    static $routes = [];

    if ($action === 'add' && $route !== null) {
        $routes[] = $route;
    }

    return $routes;
}

/**
 * Résout la requête courante et exécute le gestionnaire correspondant.
 */
function router_dispatch(): void
{
    $method = request_method();
    $path   = request_path();
    $path   = $path === '' ? '/' : $path;

    $pathMatched = false;

    foreach (router_table('get') as $route) {
        $params = router_match($route['path'], $path);

        if ($params === null) {
            continue;
        }

        $pathMatched = true;

        if ($route['method'] !== $method) {
            continue;
        }

        router_run_middleware($route['middleware']);
        router_call($route, $params);

        return;
    }

    // Le chemin existe mais pas pour cette méthode : 405 plutôt que 404,
    // c'est un signal utile en développement.
    abort($pathMatched ? 405 : 404);
}

/**
 * Compare un motif de route au chemin demandé.
 *
 * @return array|null Paramètres capturés, ou null si le motif ne correspond pas
 */
function router_match(string $pattern, string $path): ?array
{
    if ($pattern === $path) {
        return [];
    }

    if (!str_contains($pattern, '{')) {
        return null;
    }

    // Le motif est découpé en segments littéraux et en paramètres, puis
    // reconstruit. Chaque segment littéral est échappé séparément : on ne
    // peut donc pas échapper par erreur les accalades des paramètres.
    $segments = preg_split(
        '/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/',
        $pattern,
        -1,
        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
    ) ?: [];

    $regex = '';

    foreach ($segments as $segment) {
        if ($segment[0] === '{') {
            $name = trim($segment, '{}');
            // {id}, {student_id} n'acceptent que des chiffres ;
            // {slug}, {code} acceptent lettres, chiffres, tiret et souligné.
            $regex .= str_ends_with($name, 'id') ? '(\d+)' : '([a-zA-Z0-9_\-]+)';
        } else {
            $regex .= preg_quote($segment, '#');
        }
    }

    if (!preg_match('#^' . $regex . '$#', $path, $matches)) {
        return null;
    }

    array_shift($matches);

    return $matches;
}

/**
 * Applique les middlewares d'une route.
 * Chaque middleware interrompt la requête lui-même en cas de refus.
 */
function router_run_middleware(array $middleware): void
{
    foreach ($middleware as $rule) {
        [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

        switch ($name) {
            case 'auth':
                if (!auth_check()) {
                    if (is_ajax()) {
                        json_error('Votre session a expiré.', 401);
                    }
                    session_put('intended_url', request_path());
                    flash_warning('Veuillez vous connecter pour continuer.');
                    redirect('/login');
                }

                router_enforce_password_change();
                break;

            case 'guest':
                if (auth_check()) {
                    redirect('/tableau-de-bord');
                }
                break;

            case 'perm':
                perm_require((string) $parameter);
                break;

            case 'platform':
                // Réservé au super administrateur du SaaS.
                if (!auth_is_platform_admin()) {
                    abort(403);
                }
                break;

            case 'school':
                // La page exige un établissement dans le contexte courant.
                if (tenant_id() === null) {
                    flash_warning('Veuillez sélectionner un établissement.');
                    redirect('/plateforme/ecoles');
                }
                break;

            default:
                throw new InvalidArgumentException("Middleware inconnu : {$name}");
        }
    }
}

/**
 * Impose le changement de mot de passe avant tout autre accès.
 *
 * Sans ce contrôle, un utilisateur dont le mot de passe a été réinitialisé
 * par un administrateur pourrait simplement saisir une autre adresse et
 * continuer à travailler avec un mot de passe connu d'un tiers.
 *
 * Les routes de la liste ci-dessous restent accessibles, sinon
 * l'utilisateur se retrouverait enfermé dans une boucle de redirection.
 */
function router_enforce_password_change(): void
{
    $user = auth_user();

    if ($user === null || (int) $user['must_change_password'] !== 1) {
        return;
    }

    $allowed = ['/mot-de-passe/changer', '/logout'];

    if (in_array(request_path(), $allowed, true)) {
        return;
    }

    if (is_ajax()) {
        json_error('Vous devez définir un nouveau mot de passe avant de continuer.', 403);
    }

    flash_warning('Vous devez définir un nouveau mot de passe avant de continuer.');
    redirect('/mot-de-passe/changer');
}

/** Charge le module et invoque le gestionnaire. */
function router_call(array $route, array $params): void
{
    $moduleFile = APP_PATH . '/modules/' . $route['module'] . '/controllers.php';

    if (!is_file($moduleFile)) {
        throw new RuntimeException("Module introuvable : {$route['module']}");
    }

    require_once $moduleFile;

    if (!function_exists($route['handler'])) {
        throw new RuntimeException(
            "Gestionnaire « {$route['handler']} » absent de {$route['module']}/controllers.php"
        );
    }

    $route['handler'](...$params);
}

/**
 * Génère une URL interne, relative à la racine du site.
 *
 * Comme asset(), volontairement relative : le navigateur complète avec
 * l'origine de la page courante. Les liens restent donc valides quel que
 * soit l'hôte ou le port par lequel on accède à l'application.
 *
 * Pour un lien absolu destiné à sortir de l'application (email de
 * réinitialisation, QR code d'un bulletin), utiliser app_url().
 */
function url(string $path = '/', array $query = []): string
{
    $url = base_uri() . '/' . ltrim($path, '/');

    if ($query !== []) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    return $url;
}
