<?php
/**
 * Rendu des vues.
 *
 * Choix : PHP nu comme moteur de gabarits, sans dépendance externe.
 * Contrepartie assumée : l'échappement n'est pas automatique. La règle
 * du projet est donc stricte — toute variable affichée passe par e().
 */

declare(strict_types=1);

/**
 * Rend une vue dans un layout.
 *
 *   view('students/index', ['students' => $rows], 'app');
 *
 * @param string      $template Chemin relatif à app/views, sans .php
 * @param array       $data     Variables extraites dans la vue
 * @param string|null $layout   Nom du layout dans views/layouts, ou null
 */
function view(string $template, array $data = [], ?string $layout = 'app'): void
{
    echo view_render($template, $data, $layout);
}

/** Identique à view(), mais retourne la chaîne au lieu de l'afficher. */
function view_render(string $template, array $data = [], ?string $layout = 'app'): string
{
    $content = view_capture($template, $data);

    if ($layout === null) {
        return $content;
    }

    return view_capture('layouts/' . $layout, array_merge($data, ['content' => $content]));
}

/**
 * Inclut une vue partielle (composant réutilisable).
 *
 *   <?php partial('partials/student-card', ['student' => $s]); ?>
 */
function partial(string $template, array $data = []): void
{
    echo view_capture($template, $data);
}

/** Exécute un gabarit et capture sa sortie. Usage interne. */
function view_capture(string $template, array $data): string
{
    $file = APP_PATH . '/views/' . ltrim($template, '/') . '.php';

    // Le nom de gabarit vient toujours du code, jamais d'une entrée
    // utilisateur. Ce contrôle bloque néanmoins toute traversée de chemin.
    if (str_contains($template, '..') || !is_file($file)) {
        throw new RuntimeException("Vue introuvable : {$template}");
    }

    // EXTR_SKIP : une variable de $data ne peut pas écraser $file ni $data.
    extract($data, EXTR_SKIP);

    ob_start();

    try {
        require $file;
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    return (string) ob_get_clean();
}

/**
 * Définit le titre de la page depuis une vue.
 * Lu par le layout via view_title().
 */
function set_title(string $title): void
{
    $GLOBALS['__view_title'] = $title;
}

function view_title(): string
{
    $appName = (string) config('app.name');
    $title   = $GLOBALS['__view_title'] ?? '';

    return $title !== '' ? $title . ' — ' . $appName : $appName;
}

/**
 * Classe CSS « active » pour un élément de navigation.
 *
 *   <a class="nav-link <?= nav_active('/students') ?>" ...>
 */
function nav_active(string $prefix, string $class = 'active'): string
{
    return str_starts_with(request_path(), rtrim($prefix, '/')) ? $class : '';
}

/**
 * Balise <script> portant le nonce CSP.
 * Toute inclusion de script doit passer par ici, sinon elle sera bloquée
 * par la politique de sécurité du contenu.
 */
function script_tag(string $src = '', string $inline = ''): string
{
    $nonce = e(response_csp_nonce());

    if ($src !== '') {
        return '<script nonce="' . $nonce . '" src="' . e(asset($src)) . '"></script>';
    }

    return '<script nonce="' . $nonce . '">' . $inline . '</script>';
}

/**
 * URL d'un fichier statique, avec un paramètre de version basé sur la
 * date de modification : le navigateur recharge le fichier dès qu'il change.
 */
function asset(string $path): string
{
    $path     = ltrim($path, '/');
    $fullPath = BASE_PATH . '/public/' . $path;
    $version  = is_file($fullPath) ? (string) filemtime($fullPath) : (string) config('app.version');

    return app_url($path) . '?v=' . $version;
}
