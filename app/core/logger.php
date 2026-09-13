<?php
/**
 * Journalisation fichier.
 *
 * Choix : pas de dépendance externe (Monolog), car l'hébergement cible
 * est un mutualisé cPanel où Composer n'est pas toujours disponible.
 * Un fichier par jour, rotation automatique.
 */

declare(strict_types=1);

const LOG_LEVELS = [
    'debug'   => 10,
    'info'    => 20,
    'warning' => 30,
    'error'   => 40,
];

/**
 * Écrit une entrée dans le journal du jour.
 *
 * @param string $level   debug|info|warning|error
 * @param string $message Message court, sans donnée personnelle sensible
 * @param array  $context Données additionnelles (encodées en JSON)
 */
function log_write(string $level, string $message, array $context = []): void
{
    $minLevel = LOG_LEVELS[(string) config('log.level', 'debug')] ?? 10;
    $current  = LOG_LEVELS[$level] ?? 10;

    if ($current < $minLevel) {
        return;
    }

    $dir = storage_path('logs');

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return; // Ne jamais faire échouer une requête à cause du journal.
    }

    $line = sprintf(
        "[%s] %s.%s: %s %s | user=%s school=%s ip=%s%s",
        now(),
        (string) config('app.env', 'local'),
        strtoupper($level),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}',
        $_SESSION['user_id']   ?? '-',
        $_SESSION['school_id'] ?? '-',
        $_SERVER['REMOTE_ADDR'] ?? '-',
        PHP_EOL
    );

    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);

    log_rotate($dir);
}

function log_debug(string $message, array $context = []): void
{
    log_write('debug', $message, $context);
}

function log_info(string $message, array $context = []): void
{
    log_write('info', $message, $context);
}

function log_warning(string $message, array $context = []): void
{
    log_write('warning', $message, $context);
}

function log_error(string $message, array $context = []): void
{
    log_write('error', $message, $context);
}

/**
 * Supprime les journaux plus anciens que log.max_files jours.
 * Exécutée au plus une fois par requête et une fois par heure.
 */
function log_rotate(string $dir): void
{
    static $done = false;

    if ($done) {
        return;
    }
    $done = true;

    $marker = $dir . '/.last_rotate';

    if (is_file($marker) && (time() - (int) @filemtime($marker)) < 3600) {
        return;
    }

    @touch($marker);

    $maxFiles = (int) config('log.max_files', 30);
    $limit    = strtotime('-' . $maxFiles . ' days');

    foreach (glob($dir . '/app-*.log') ?: [] as $file) {
        if (@filemtime($file) < $limit) {
            @unlink($file);
        }
    }
}
