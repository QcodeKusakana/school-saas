<?php
/**
 * Configuration générale de l'application.
 *
 * Ce fichier est VERSIONNÉ et ne doit contenir AUCUN secret.
 * Les identifiants de base de données, les clés et les mots de passe
 * vivent dans app/config/config.local.php, qui est ignoré par Git.
 *
 * @return array
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Valeurs par défaut (environnement de développement Laragon).
// ---------------------------------------------------------------------
$config = [

    'app' => [
        'name'        => 'School SaaS RDC',
        'version'     => '0.1.0',
        'env'         => 'local',          // local | staging | production
        'debug'       => true,             // TOUJOURS false en production
        'url'         => 'http://school-saas.test',
        'timezone'    => 'Africa/Kinshasa',
        'locale'      => 'fr',
        'charset'     => 'UTF-8',
    ],

    'database' => [
        'host'        => '127.0.0.1',
        'port'        => 3306,
        'name'        => 'school_saas',
        'user'        => 'root',
        'password'    => '',
        'charset'     => 'utf8mb4',
        'collation'   => 'utf8mb4_unicode_ci',
        // Journalise toute requête dépassant ce seuil (secondes). 0 = désactivé.
        'slow_query_threshold' => 0.5,
    ],

    'session' => [
        'name'            => 'SCHOOLSAAS_SID',
        // Durée d'inactivité avant déconnexion automatique (secondes).
        'idle_timeout'    => 3600,
        // Durée absolue maximale d'une session, même active (secondes).
        'absolute_timeout'=> 43200,
        // Régénération de l'identifiant de session (secondes).
        'regenerate_every'=> 900,
        'cookie_secure'   => false,        // true dès que HTTPS est en place
        'cookie_samesite' => 'Lax',
        'save_path'       => null,         // null => storage/sessions
    ],

    'security' => [
        // Coût bcrypt. 12 est un bon compromis sur un mutualisé.
        // Vérifier le temps réel : doit rester sous ~250 ms.
        'password_cost'        => 12,
        'password_min_length'  => 10,
        // Limitation des tentatives de connexion.
        'max_attempts_user'    => 5,       // par identifiant
        'max_attempts_ip'      => 20,      // par adresse IP
        'attempts_window'      => 900,     // fenêtre d'observation (secondes)
        'lockout_duration'     => 900,     // durée du verrouillage (secondes)
        // Durée de validité d'un lien de réinitialisation (secondes).
        'reset_token_ttl'      => 3600,
        // Jeton CSRF.
        'csrf_token_ttl'       => 7200,
        // En-têtes de sécurité HTTP.
        'send_security_headers'=> true,
        // CLÉ DE CHIFFREMENT DES SECRETS RELISIBLES (mot de passe SMTP,
        // corps d'un message portant un lien). 32 octets en base64.
        // À renseigner dans config.local.php UNIQUEMENT — ce fichier-ci
        // est versionné. Générer avec :
        //   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
        'encryption_key'       => '',
    ],

    /**
     * MESSAGERIE DE LA PLATEFORME — le serveur de l'éditeur.
     *
     * Il ne vit pas en base, contrairement à celui de chaque école :
     * il appartient à l'éditeur, pas à un client, et il doit
     * fonctionner AVANT qu'un seul établissement existe.
     *
     * Renseigner dans config.local.php, jamais ici : ce fichier est
     * versionné, et un mot de passe n'a rien à faire dans un dépôt.
     */
    'mail' => [
        'host'       => '',
        'port'       => 587,
        'encryption' => 'tls',     // 'none', 'tls' (STARTTLS) ou 'ssl'
        'username'   => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => 'School SaaS RDC',
        'reply_to'   => null,
    ],

    'uploads' => [
        'max_size'          => 5 * 1024 * 1024,   // 5 Mo
        'allowed_images'    => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_documents' => ['pdf'],
        // Types MIME réellement acceptés (vérifiés via finfo, jamais via
        // l'extension ni via $_FILES['type'] fourni par le client).
        'allowed_mimes'     => [
            'image/jpeg', 'image/png', 'image/webp', 'application/pdf',
        ],
    ],

    'log' => [
        'level'      => 'debug',     // debug | info | warning | error
        'max_files'  => 30,          // rotation : nombre de jours conservés
    ],

    'pagination' => [
        'per_page'      => 25,
        'per_page_max'  => 200,
    ],
];

// ---------------------------------------------------------------------
// Surcharge locale (secrets, environnement).
// Copier config.local.example.php en config.local.php et l'adapter.
// ---------------------------------------------------------------------
$localFile = __DIR__ . '/config.local.php';

if (is_file($localFile)) {
    $local = require $localFile;

    if (is_array($local)) {
        // Fusion récursive sur deux niveaux : suffisant ici et bien plus
        // prévisible qu'un array_merge_recursive qui transforme les
        // scalaires en tableaux.
        foreach ($local as $section => $values) {
            if (is_array($values) && isset($config[$section]) && is_array($config[$section])) {
                $config[$section] = array_replace($config[$section], $values);
            } else {
                $config[$section] = $values;
            }
        }
    }
}

return $config;
