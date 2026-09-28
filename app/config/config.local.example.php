<?php
/**
 * MODÈLE de configuration locale.
 *
 * Procédure :
 *   1. Copier ce fichier en app/config/config.local.php
 *   2. Renseigner les valeurs réelles — LA CLÉ DE CHIFFREMENT COMPRISE
 *   3. Ne JAMAIS committer config.local.php (déjà dans .gitignore)
 *
 * Seules les clés à surcharger doivent figurer ici.
 *
 * ---------------------------------------------------------------------
 * Ce fichier a longtemps omis `security.encryption_key`. L'instruction
 * existait, mais dans `config.php` — le fichier que personne ne copie.
 * Une installation conforme au README produisait donc un produit sans
 * clé, et « mot de passe oublié » y répondait par une erreur 500, pour
 * tout le monde, dès le premier jour.
 *
 *   > Une consigne écrite dans le fichier qu'on ne copie pas n'est pas
 *   > une consigne : c'est une note pour soi-même.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

return [

    'app' => [
        'env'   => 'local',                        // 'production' en ligne
        'debug' => true,                           // false en production, sans exception
        'url'   => 'http://school-saas.test',      // en ligne : l'URL publique réelle
    ],

    'database' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'school_saas',
        // EN LOCAL (Laragon) : 'root' avec un mot de passe vide convient.
        // EN PRODUCTION : un compte dédié à cette base, JAMAIS 'root'.
        // Un compte applicatif compromis ne doit pas emporter le serveur.
        'user'     => 'root',
        'password' => '',
    ],

    'security' => [
        // CLÉ DE CHIFFREMENT — OBLIGATOIRE, dès l'installation.
        //
        // Elle protège les secrets qu'il faut RELIRE : le mot de passe
        // SMTP de chaque école, et le corps des messages qui portent un
        // lien de réinitialisation. Sans elle, ces fonctions LÈVENT —
        // délibérément : un repli silencieux en clair serait pire que
        // pas de chiffrement du tout, car il en donnerait l'illusion.
        //
        // Générez la vôtre, maintenant, et collez-la ci-dessous :
        //
        //   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
        //
        // Elle ne se partage pas entre environnements, ne se met pas
        // dans Git, et ne se change pas sans ressaisir les mots de
        // passe SMTP déjà enregistrés (ils deviendraient illisibles).
        'encryption_key' => '',
    ],

    'session' => [
        // Passer à true dès que le site est servi en HTTPS,
        // sinon le cookie de session circule en clair.
        'cookie_secure' => false,
    ],
];
