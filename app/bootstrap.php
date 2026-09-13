<?php
/**
 * Amorçage de l'application.
 *
 * Ordre volontairement figé — chaque étape dépend des précédentes :
 *   1. constantes de chemins
 *   2. helpers  (config(), e(), storage_path() : requis par tout le reste)
 *   3. logger   (requis par le gestionnaire d'erreurs)
 *   4. erreurs  (à installer le plus tôt possible)
 *   5. environnement PHP (fuseau, encodage)
 *   6. base de données, contexte multi-école
 *   7. session, CSRF
 *   8. couche HTTP, authentification, permissions
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// 1. Chemins
// ---------------------------------------------------------------------
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', __DIR__);
define('APP_START', microtime(true));

// ---------------------------------------------------------------------
// 2 à 8. Chargement du noyau
//
// Chargement explicite plutôt qu'un autoloader : le noyau est composé de
// fonctions, pas de classes, et l'ordre de chargement compte. Un include
// explicite rend cet ordre lisible et évite un autoloader qui ne servirait
// qu'à masquer la dépendance.
// ---------------------------------------------------------------------
require APP_PATH . '/core/helpers.php';
require APP_PATH . '/core/logger.php';
require APP_PATH . '/core/errors.php';

errors_register();

// ---------------------------------------------------------------------
// Environnement PHP
// ---------------------------------------------------------------------
date_default_timezone_set((string) config('app.timezone', 'Africa/Kinshasa'));
mb_internal_encoding('UTF-8');
setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'french');

// Vérification de la version minimale : PHP 8.0 introduit les arguments
// nommés et str_contains, utilisés partout dans le noyau.
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Ce logiciel requiert PHP 8.1 ou supérieur. Version détectée : ' . PHP_VERSION);
}

// ---------------------------------------------------------------------
// Noyau applicatif
// ---------------------------------------------------------------------
require APP_PATH . '/core/db.php';
require APP_PATH . '/core/tenant.php';
require APP_PATH . '/core/session.php';
require APP_PATH . '/core/csrf.php';
require APP_PATH . '/core/security.php';
require APP_PATH . '/core/validator.php';
require APP_PATH . '/core/request.php';
require APP_PATH . '/core/response.php';
require APP_PATH . '/core/flash.php';
require APP_PATH . '/core/view.php';
require APP_PATH . '/core/router.php';
require APP_PATH . '/core/audit.php';
require APP_PATH . '/core/auth.php';
require APP_PATH . '/core/permission.php';

// ---------------------------------------------------------------------
// Vérification des répertoires inscriptibles
// Détecté au démarrage plutôt qu'au premier échec d'écriture, souvent
// silencieux et difficile à diagnostiquer sur un hébergement mutualisé.
// ---------------------------------------------------------------------
foreach (['logs', 'sessions', 'cache', 'uploads'] as $directory) {
    $path = storage_path($directory);

    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }

    if (!is_writable($path)) {
        http_response_code(500);
        exit("Le dossier storage/{$directory} doit être accessible en écriture (chmod 775).");
    }
}

// ---------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------
session_start_secure();

// ---------------------------------------------------------------------
// Contexte multi-école
//
// Reconstruit depuis la base par auth_user() à chaque requête. L'appel
// ci-dessous suffit à établir le contexte pour toute la requête.
// ---------------------------------------------------------------------
auth_user();
