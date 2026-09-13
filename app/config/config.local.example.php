<?php
/**
 * MODÈLE de configuration locale.
 *
 * Procédure :
 *   1. Copier ce fichier en app/config/config.local.php
 *   2. Renseigner les valeurs réelles
 *   3. Ne JAMAIS committer config.local.php (déjà dans .gitignore)
 *
 * Seules les clés à surcharger doivent figurer ici.
 */

declare(strict_types=1);

return [

    'app' => [
        'env'   => 'local',                        // 'production' en ligne
        'debug' => true,                           // false en production, sans exception
        'url'   => 'http://school-saas.test',
    ],

    'database' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'school_saas',
        'user'     => 'root',
        'password' => '',
    ],

    'session' => [
        // Passer à true dès que le site est servi en HTTPS,
        // sinon le cookie de session circule en clair.
        'cookie_secure' => false,
    ],
];
