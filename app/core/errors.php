<?php
/**
 * Gestion centralisée des erreurs.
 *
 * Principe : aucune erreur n'est masquée. En développement, elle s'affiche
 * intégralement ; en production, elle est journalisée et l'utilisateur voit
 * une page neutre — jamais une trace d'exécution, qui révélerait chemins,
 * requêtes SQL et parfois identifiants de connexion.
 *
 * L'opérateur @ et error_reporting(0) sont proscrits dans ce projet.
 */

declare(strict_types=1);

function errors_register(): void
{
    $debug = (bool) config('app.debug', false);

    // On signale TOUJOURS tout à PHP : c'est l'affichage qui varie,
    // pas la détection.
    error_reporting(E_ALL);
    ini_set('display_errors', $debug ? '1' : '0');
    ini_set('log_errors', '1');
    ini_set('error_log', storage_path('logs/php-' . date('Y-m-d') . '.log'));

    set_error_handler('errors_handle_php');
    set_exception_handler('errors_handle_exception');
    register_shutdown_function('errors_handle_shutdown');
}

/**
 * Transforme les avertissements PHP en exceptions.
 * Une notice « undefined array key » devient ainsi une erreur visible,
 * au lieu de produire silencieusement une valeur nulle en base.
 */
function errors_handle_php(int $severity, string $message, string $file = '', int $line = 0): bool
{
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
}

/** Gestionnaire d'exceptions non rattrapées. */
function errors_handle_exception(Throwable $e): void
{
    errors_report($e);
    errors_render($e);
}

/** Capture les erreurs fatales, invisibles pour set_error_handler. */
function errors_handle_shutdown(): void
{
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    $e = new ErrorException(
        $error['message'],
        0,
        $error['type'],
        $error['file'],
        $error['line']
    );

    errors_report($e);
    errors_render($e);
}

/** Journalise l'erreur avec son contexte. */
function errors_report(Throwable $e): void
{
    log_error($e->getMessage(), [
        'exception' => get_class($e),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
        'path'      => $_SERVER['REQUEST_URI'] ?? null,
        'method'    => $_SERVER['REQUEST_METHOD'] ?? null,
        // La trace complète part dans le fichier, jamais dans la réponse.
        'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
    ]);
}

/** Affiche l'erreur selon l'environnement. */
function errors_render(Throwable $e): void
{
    // Une transaction laissée ouverte verrouillerait des lignes jusqu'au
    // délai d'expiration MySQL.
    try {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    } catch (Throwable) {
        // La base est peut-être justement la cause de l'erreur.
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // EN LIGNE DE COMMANDE, UNE ERREUR EST UN TEXTE, PAS UNE PAGE WEB.
    //
    // Sans cette branche, `php database/migrate.php` qui échoue déversait
    // dans le terminal une page HTML complète — doctype, feuille de style,
    // balises — dans laquelle le message d'erreur était noyé. L'outil
    // devenait illisible exactement au moment où il avait quelque chose
    // d'important à dire.
    if (PHP_SAPI === 'cli') {
        errors_render_cli($e);
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (is_ajax()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            config('app.debug')
                ? ['success' => false, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
                : ['success' => false, 'message' => 'Une erreur interne est survenue.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if (config('app.debug')) {
        errors_render_debug($e);
        exit;
    }

    $view = APP_PATH . '/views/errors/500.php';

    if (is_file($view)) {
        require $view;
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Erreur</title>'
            . '<p style="font-family:system-ui;padding:2rem">Une erreur interne est survenue.</p>';
    }

    exit;
}

/**
 * Erreur en ligne de commande : message, emplacement, pile.
 *
 * La pile n'est affichée qu'en développement — un script planifié tournant
 * en production ne doit pas écrire de chemins serveur dans un journal de
 * tâches, mais le détail part dans storage/logs/ via errors_report().
 */
function errors_render_cli(Throwable $e): void
{
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  ✗ " . get_class($e) . "\n");
    fwrite(STDERR, "  " . str_repeat('─', 60) . "\n");
    fwrite(STDERR, "  " . $e->getMessage() . "\n\n");
    fwrite(STDERR, "  " . $e->getFile() . ' ligne ' . $e->getLine() . "\n");

    $previous = $e->getPrevious();

    while ($previous !== null) {
        fwrite(STDERR, "\n  Cause : " . $previous->getMessage() . "\n");
        fwrite(STDERR, "  " . $previous->getFile() . ' ligne ' . $previous->getLine() . "\n");
        $previous = $previous->getPrevious();
    }

    if (config('app.debug')) {
        fwrite(STDERR, "\n  Pile d'appels\n");

        foreach (explode("\n", $e->getTraceAsString()) as $frame) {
            fwrite(STDERR, "    " . $frame . "\n");
        }
    }

    fwrite(STDERR, "\n  Détail complet dans storage/logs/\n\n");

    exit(1);
}

/** Page de diagnostic, affichée uniquement en développement. */
function errors_render_debug(Throwable $e): void
{
    $class = get_class($e);
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Erreur — <?= e($class) ?></title>
<style>
    body{font:14px/1.6 ui-monospace,Menlo,Consolas,monospace;margin:0;background:#0f172a;color:#e2e8f0}
    .wrap{max-width:960px;margin:0 auto;padding:2rem 1.25rem}
    h1{font-size:1.05rem;color:#fca5a5;margin:0 0 .25rem;font-weight:600}
    .msg{font-size:1.15rem;color:#f8fafc;margin:0 0 1.5rem;line-height:1.45}
    .loc{background:#1e293b;border-left:3px solid #ef4444;padding:.75rem 1rem;border-radius:0 6px 6px 0;margin-bottom:1.5rem;word-break:break-all}
    .loc b{color:#fbbf24;font-weight:600}
    h2{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin:1.5rem 0 .5rem}
    pre{background:#1e293b;padding:1rem;border-radius:6px;overflow-x:auto;font-size:12.5px;margin:0}
    .note{margin-top:2rem;padding:.85rem 1rem;background:#1e293b;border-radius:6px;color:#94a3b8;font-size:12.5px}
</style>
</head>
<body>
<div class="wrap">
    <h1><?= e($class) ?></h1>
    <p class="msg"><?= e($e->getMessage()) ?></p>
    <div class="loc"><b><?= e($e->getFile()) ?></b> ligne <b><?= (int) $e->getLine() ?></b></div>
    <h2>Pile d'appels</h2>
    <pre><?= e($e->getTraceAsString()) ?></pre>
    <p class="note">Cette page n'apparaît qu'avec <code>app.debug = true</code>. En production, l'utilisateur voit une page neutre et le détail part dans <code>storage/logs/</code>.</p>
</div>
</body>
</html>
    <?php
}
