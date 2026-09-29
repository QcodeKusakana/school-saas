<?php
/**
 * CONTRÔLE AVANT MISE EN SERVICE — à exécuter EN LIGNE DE COMMANDE.
 *
 *   php database/preflight.php
 *   php database/preflight.php --json     (sortie exploitable par un script)
 *
 * POURQUOI CE FICHIER EXISTE
 * ==========================
 * Le projet portait, en deux endroits, une liste de cases à cocher
 * « avant mise en production ». Elle a été confrontée au produit :
 *
 *   - « Câbler l'envoi d'e-mails » — câblé depuis la phase 8A ;
 *   - « config.local.php hors dépôt Git » — dans .gitignore depuis
 *     la phase 1 ;
 *   - « Supprimer public/diagnostic.php » — le fichier se refuse
 *     lui-même hors debug et hors machine locale.
 *
 * Trois lignes sur onze décrivaient un produit qui n'existait plus.
 * Pendant ce temps, la liste ne disait RIEN du manque qui empêchait
 * réellement une installation neuve de fonctionner : l'absence de clé
 * de chiffrement, mesurée en suivant le README à la lettre.
 *
 *   > Une liste de contrôle qu'on n'exécute pas décrit le produit du
 *   > jour où on l'a écrite.
 *
 * Ce script MESURE. Il lit la configuration réelle, interroge la base
 * réelle, regarde les fichiers réellement présents. Il ne corrige rien
 * et n'écrit rien : un outil qui répare ce qu'il contrôle finit par
 * masquer ce qu'il aurait dû signaler.
 *
 * DEUX NIVEAUX, ET LA DIFFÉRENCE COMPTE
 * -------------------------------------
 *   BLOQUANT     — mettre en ligne dans cet état expose les données,
 *                  ou laisse une fonction essentielle hors service.
 *   RECOMMANDÉ   — à traiter, sans empêcher l'ouverture du service.
 *
 * Code de sortie : 0 si aucun bloquant, 1 sinon. Utilisable en CI.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script ne s'exécute qu'en ligne de commande.\n");
}

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

require APP_PATH . '/core/helpers.php';
require __DIR__ . '/runner.php';

$options = getopt('', ['json', 'help']);

if (isset($options['help'])) {
    echo <<<TXT

    Contrôle avant mise en service
    ------------------------------
      php database/preflight.php           Rapport lisible
      php database/preflight.php --json    Sortie JSON

    Sortie 0 : aucun point bloquant. Sortie 1 : au moins un.

    TXT;
    exit(0);
}

$asJson = isset($options['json']);

/** @var array<int, array{niveau: string, sujet: string, etat: string, constat: string, remede: string}> $rapport */
$rapport = [];

/**
 * Enregistre un constat.
 *
 * `$ok` vaut null quand la mesure n'a pas pu être faite : on ne
 * transforme pas une mesure impossible en réussite.
 */
function verdict(string $niveau, string $sujet, ?bool $ok, string $constat, string $remede = ''): void
{
    global $rapport;

    $rapport[] = [
        'niveau'  => $niveau,
        'sujet'   => $sujet,
        'etat'    => $ok === true ? 'ok' : ($ok === null ? 'inconnu' : 'echec'),
        'constat' => $constat,
        'remede'  => $ok === true ? '' : $remede,
    ];
}

const BLOQUANT   = 'bloquant';
const RECOMMANDE = 'recommandé';

// =====================================================================
//  1. LA PLATEFORME
// =====================================================================

$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
verdict(BLOQUANT, 'Version de PHP', $phpOk,
    'PHP ' . PHP_VERSION,
    'Le produit exige PHP 8.1 ou supérieur.');

$manquantes = array_values(array_filter(
    ['pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo', 'sodium'],
    static fn (string $ext): bool => !extension_loaded($ext)
));

verdict(BLOQUANT, 'Extensions PHP', $manquantes === [],
    $manquantes === [] ? 'toutes présentes' : 'manquantes : ' . implode(', ', $manquantes),
    'Activez-les dans php.ini. `sodium` porte tout le chiffrement des secrets.');

// SANS `pdo_mysql`, CE SCRIPT NE PEUT PAS CONTINUER — ET DOIT LE DIRE.
//
// Mesuré : la première version diagnostiquait l'extension manquante,
// puis mourait vingt lignes plus loin sur `new PDO(...)`. Le déployeur
// sur mutualisé sans `pdo_mysql` — celui pour qui ce contrôle existe —
// recevait une erreur fatale au lieu du rapport qui nomme la cause.
//
//   > Un contrôle qui meurt de ce qu'il vient de diagnostiquer ne
//   > diagnostique rien.
//
// On rend donc le rapport ARRÊTÉ ICI, complet jusqu'à ce point, et on
// dit ce qui n'a pas pu être mesuré.
if (in_array('pdo_mysql', $manquantes, true)) {
    verdict(BLOQUANT, 'Suite du contrôle', null,
        'interrompue : sans pdo_mysql, la base ne peut pas être interrogée',
        'Activez pdo_mysql, puis relancez pour obtenir le rapport complet.');

    restituer($rapport, $asJson);
}

// =====================================================================
//  2. LA CONFIGURATION
// =====================================================================

$configLocal = APP_PATH . '/config/config.local.php';

verdict(BLOQUANT, 'config.local.php', is_file($configLocal),
    is_file($configLocal) ? 'présent' : 'ABSENT',
    'Copiez app/config/config.local.example.php et renseignez-le.');

// Hors du dépôt : on le DEMANDE À GIT, on ne le déduit pas du .gitignore.
// Une règle d'ignorance ne dit rien d'un fichier déjà suivi avant elle.
$suivi = null;

if (is_dir(BASE_PATH . '/.git')) {
    // `2>/dev/null` N'EXISTE PAS SOUS WINDOWS.
    //
    // Mesuré sur Laragon : `cmd` cherche un fichier « \dev\null », ne le
    // trouve pas, et écrit « The system cannot find the path specified. »
    // sur SA sortie d'erreur — qui n'est pas capturée par `exec()` et
    // remonte donc au processus appelant. Le rapport JSON s'en trouvait
    // précédé d'une ligne de texte, et devenait illisible.
    //
    //   > Un outil destiné à la machine du déployeur n'a pas le droit de
    //   > supposer le système du développeur.
    //
    // Le projet vise Windows en local et Linux en production : le fichier
    // « rien » n'a pas le même nom des deux côtés.
    $rien   = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $sortie = [];
    $code   = 0;

    exec('git -C ' . escapeshellarg(BASE_PATH)
        . ' ls-files --error-unmatch app/config/config.local.php 2>' . $rien, $sortie, $code);

    $suivi = $code === 0;
}

// Sans dépôt sur la machine de production — le cas d'un dépôt déposé par
// FTP sur un hébergement mutualisé — le fichier n'y est exposé par aucun
// dépôt : le contrôle est satisfait, et le dire vaut mieux que rendre un
// « non mesurable » qui bloquerait toute mise en ligne par FTP.
verdict(BLOQUANT, 'config.local.php hors dépôt',
    $suivi !== true,
    $suivi === null ? 'aucun dépôt Git dans cette copie' : ($suivi ? 'SUIVI PAR GIT' : 'non suivi'),
    'git rm --cached app/config/config.local.php  puis changez tous les secrets '
    . 'qu\'il contenait : ils sont dans l\'historique.');

$debug = (bool) config('app.debug', false);
verdict(BLOQUANT, 'app.debug', $debug === false,
    $debug ? 'true — les traces d\'exécution sont rendues au visiteur' : 'false',
    'Passez app.debug à false. Une page d\'erreur en mode debug publie les '
    . 'chemins absolus, la pile d\'appels et le nom des fichiers.');

$env = (string) config('app.env', '');
verdict(RECOMMANDE, 'app.env', $env === 'production',
    $env !== '' ? $env : '(vide)',
    'Renseignez app.env à « production ».');

$url = trim((string) config('app.url', ''));
$urlPubl = $url !== ''
    && !preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])#i', $url)
    && !preg_match('#\.(test|local|localhost)(/|$|:)#i', $url);

verdict(BLOQUANT, 'app.url', $urlPubl,
    $url !== '' ? $url : '(vide)',
    'app.url sert à composer les liens de réinitialisation envoyés par e-mail '
    . 'ET les codes de vérification IMPRIMÉS sur les reçus, les cartes et les '
    . 'attestations. Un lien d\'e-mail se renvoie ; un millier de reçus déjà '
    . 'remis aux familles, non.');

verdict(BLOQUANT, 'app.url en HTTPS', str_starts_with(strtolower($url), 'https://'),
    $url !== '' ? parse_url($url, PHP_URL_SCHEME) ?: '(indéterminé)' : '(vide)',
    'Sans HTTPS, session.cookie_secure rendrait la connexion impossible, et '
    . 'le mot de passe circule en clair.');

// --- LA LISIBILITÉ DES CODES IMPRIMÉS --------------------------------
//
// PRODUIRE UN CODE N'EST PAS LE RENDRE LISIBLE.
//
// La longueur de `app.url` décide du nombre de modules du code, donc de
// leur taille sur le papier. Sur un poste de développement, l'adresse
// est la plus courte possible et tout paraît net. Avec l'adresse réelle
// d'un établissement — « https://scolarite.complexe-scolaire-saint-joseph.gombe.cd »
// — chaque module tombe sous le seuil qu'un téléphone d'entrée de gamme
// sait accrocher, sans qu'aucune erreur ne soit levée.
//
// Le REÇU s'adapte : il calcule sa taille et prend la place qu'il faut.
// La CARTE D'ÉLÈVE ne le peut pas — elle a le format d'une carte
// bancaire, et son code est borné à 17 mm. C'est donc ici, avant la mise
// en service, que le problème doit se voir.
//
//   > Un défaut qui n'apparaît qu'après impression n'a plus de
//   > correctif : il a un coût de réimpression.
if ($url !== '') {
    require_once APP_PATH . '/core/qrcode.php';

    // Le gabarit d'un jeton réel : 12 caractères groupés par quatre.
    $urlType = rtrim($url, '/') . '/v/ABCD-EFGH-JKMN';
    $requis  = qr_taille_impression_mm($urlType, 4);   // la carte utilise une marge de 4

    verdict(
        RECOMMANDE,
        'Lisibilité du code sur la carte d\'élève',
        $requis <= 17.0,
        sprintf('%.1f mm nécessaires pour %d caractères, 17 mm disponibles sur la carte',
            $requis, mb_strlen($urlType)),
        'Le code tiendra sur le papier mais sera trop dense pour beaucoup de '
        . 'téléphones. Un sous-domaine plus court pour app.url (par exemple '
        . '« https://ecole.cd ») ramène le code à une densité lisible. Les reçus, '
        . 'eux, s\'adaptent d\'eux-mêmes.'
    );
}

$secure = (bool) config('session.cookie_secure', false);
verdict(BLOQUANT, 'session.cookie_secure', $secure === true,
    $secure ? 'true' : 'false — le cookie de session circule en clair',
    'Passez session.cookie_secure à true (exige HTTPS).');

// --- La clé de chiffrement -------------------------------------------
//
// Mesuré : sans elle, « mot de passe oublié » répondait HTTP 500 sur une
// installation pourtant conforme au README. L'installateur la réclame
// désormais ; ce contrôle vérifie qu'elle n'a pas disparu depuis.
$rawKey  = trim((string) config('security.encryption_key', ''));
$decoded = $rawKey !== '' ? base64_decode($rawKey, true) : false;
$keyOk   = $rawKey !== '' && $decoded !== false && strlen($decoded) === 32;

verdict(BLOQUANT, 'Clé de chiffrement', $keyOk,
    $rawKey === '' ? 'ABSENTE' : ($keyOk ? 'présente, 32 octets' : 'présente mais invalide'),
    'Ajoutez security.encryption_key dans config.local.php. Générez-la avec : '
    . 'php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"');

// =====================================================================
//  3. LA BASE
// =====================================================================

$dbUser = (string) config('database.user', '');
$dbPass = (string) config('database.password', '');

verdict(BLOQUANT, 'Compte MySQL', $dbUser !== '' && strtolower($dbUser) !== 'root',
    $dbUser !== '' ? $dbUser : '(vide)',
    'Créez un compte MySQL dédié à cette base, avec les seuls droits '
    . 'SELECT/INSERT/UPDATE/DELETE dessus. Un compte applicatif compromis '
    . 'ne doit pas emporter le serveur.');

verdict(BLOQUANT, 'Mot de passe MySQL', $dbPass !== '',
    $dbPass !== '' ? 'renseigné' : 'VIDE',
    'Donnez un mot de passe au compte MySQL de l\'application.');

$pdo = null;

try {
    $pdo = new PDO(
        'mysql:host=' . (string) config('database.host')
        . ';port=' . (int) config('database.port', 3306)
        . ';dbname=' . (string) config('database.name')
        . ';charset=utf8mb4',
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    verdict(BLOQUANT, 'Connexion à la base', true,
        'MySQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
} catch (PDOException $e) {
    verdict(BLOQUANT, 'Connexion à la base', false,
        'impossible : ' . $e->getMessage(),
        'Vérifiez app/config/config.local.php.');
}

// UN CONTRÔLE QUI DISPARAÎT LAISSE CROIRE QU'IL EST PASSÉ.
//
// Mesuré par accident, le serveur MySQL s'étant arrêté : le rapport
// passait de 26 à 21 contrôles, sans un mot. Cinq vérifications —
// migrations, école de démonstration, comptes par défaut, serveur
// d'envoi — s'évaporaient, et rien ne distinguait « non vérifié » de
// « vérifié et bon ». Deux exécutions du même contrôle n'étaient plus
// comparables.
//
//   > Un contrôle qui disparaît quand une mesure échoue laisse croire
//   > qu'il est passé.
//
// Le rapport garde donc TOUJOURS la même liste : ce qui n'a pas pu être
// mesuré est rendu « inconnu », en disant pourquoi.
if ($pdo === null) {
    foreach ([
        'Migrations appliquées', 'Migrations non altérées',
        'Établissement de démonstration',
    ] as $sujet) {
        verdict(BLOQUANT, $sujet, null, 'non mesuré : la base est injoignable',
            'Rétablissez la connexion à la base, puis relancez ce contrôle.');
    }

    verdict(RECOMMANDE, 'Comptes par défaut', null,
        'non mesuré : la base est injoignable',
        'Rétablissez la connexion à la base, puis relancez ce contrôle.');
}

if ($pdo !== null) {
    // --- Migrations : appliquées, et non modifiées depuis ------------
    //
    // Un fichier appliqué puis édité produit deux bases différentes
    // portant le même numéro. Le registre garde une empreinte ; on la
    // compare, elle est là pour ça.
    try {
        $applied = runner_applied($pdo);
        $reste   = [];
        $altere  = [];

        foreach (runner_catalog() as $file) {
            if (!isset($applied[$file['name']])) {
                $reste[] = $file['name'];
                continue;
            }

            if ($applied[$file['name']]['checksum'] !== hash_file('sha256', $file['path'])) {
                $altere[] = $file['name'];
            }
        }

        verdict(BLOQUANT, 'Migrations appliquées', $reste === [],
            $reste === [] ? count($applied) . ' fichier(s) au registre'
                : count($reste) . ' en attente : ' . implode(', ', array_slice($reste, 0, 3))
                  . (count($reste) > 3 ? '…' : ''),
            'php database/migrate.php');

        verdict(BLOQUANT, 'Migrations non altérées', $altere === [],
            $altere === [] ? 'empreintes conformes'
                : 'modifié après application : ' . implode(', ', $altere),
            'Un fichier déjà appliqué ne se modifie pas : ajoutez une nouvelle '
            . 'migration. En l\'état, deux installations divergent silencieusement.');
    } catch (Throwable $e) {
        verdict(BLOQUANT, 'Migrations appliquées', null,
            'non mesurable : ' . $e->getMessage(),
            'Le registre schema_migrations est-il présent ?');
    }

    // --- Le jeu de démonstration -------------------------------------
    //
    // Son mot de passe est imprimé par l'installateur et figure dans la
    // documentation. Laissé en ligne, c'est un compte connu de tous.
    try {
        $demo = (int) $pdo->query(
            "SELECT COUNT(*) FROM schools WHERE slug = 'complexe-scolaire-demo'"
        )->fetchColumn();

        verdict(BLOQUANT, 'Établissement de démonstration', $demo === 0,
            $demo === 0 ? 'absent' : 'PRÉSENT (slug complexe-scolaire-demo)',
            'Supprimez-le avant l\'ouverture du service : son mot de passe est '
            . 'imprimé par l\'installateur et connu de quiconque lit la documentation.');
    } catch (Throwable $e) {
        verdict(BLOQUANT, 'Établissement de démonstration', null,
            'non mesurable : ' . $e->getMessage());
    }

    // --- Les comptes laissés par l'installateur ----------------------
    try {
        $defaut = (int) $pdo->query(
            "SELECT COUNT(*) FROM users
              WHERE deleted_at IS NULL
                AND (email = 'admin@example.cd' OR username = 'superadmin')"
        )->fetchColumn();

        verdict(RECOMMANDE, 'Comptes par défaut', $defaut === 0,
            $defaut === 0 ? 'aucun' : $defaut . ' compte(s) « superadmin » / admin@example.cd',
            'Renommez le compte d\'administration et renseignez une adresse réelle : '
            . 'un identifiant connu divise par deux le travail d\'une attaque.');
    } catch (Throwable $e) {
        verdict(RECOMMANDE, 'Comptes par défaut', null, 'non mesurable : ' . $e->getMessage());
    }

}

// --- Un serveur d'envoi, quelque part --------------------------------
//
// Sans lui, « mot de passe oublié » met en file et n'envoie jamais. Le
// produit le dit dans son journal ; l'école, elle, ne le voit pas.
//
// CE CONTRÔLE NE DÉPEND DE LA BASE QUE S'IL LE DOIT. `mail.host` vit
// dans la configuration : quand il est renseigné, la réponse est oui,
// base joignable ou non. C'est seulement pour répondre « aucune école
// n'en a configuré un non plus » qu'il faut interroger la base.
//
//   > Un contrôle qui exige plus que ce dont il a besoin devient
//   > inutilisable le jour où il servirait le plus.
$smtpPlateforme = trim((string) config('mail.host', '')) !== '';
$smtpEcoles     = null;

if (!$smtpPlateforme && $pdo !== null) {
    try {
        $smtpEcoles = (int) $pdo->query(
            'SELECT COUNT(*) FROM email_settings WHERE is_active = 1'
        )->fetchColumn();
    } catch (Throwable) {
        $smtpEcoles = null;
    }
}

verdict(
    BLOQUANT,
    'Serveur d\'envoi',
    $smtpPlateforme ? true : ($smtpEcoles === null ? null : $smtpEcoles > 0),
    $smtpPlateforme
        ? 'plateforme : ' . (string) config('mail.host')
        : ($smtpEcoles === null
            ? 'aucun serveur de plateforme ; les écoles non mesurées (base injoignable)'
            : ($smtpEcoles > 0
                ? $smtpEcoles . ' école(s) configurée(s), aucun serveur de plateforme'
                : 'AUCUN')),
    'Renseignez mail.host dans config.local.php. Sans serveur d\'envoi, aucun '
    . 'lien de réinitialisation ne part : un directeur qui perd son mot de '
    . 'passe n\'a plus aucun moyen de rentrer.'
);

// =====================================================================
//  4. LES FICHIERS
// =====================================================================

$outils = [
    'database/seed_demo.php'  => 'Il crée des élèves, des notes et des paiements fictifs.',
    'database/install.php'    => 'Il crée des comptes d\'administration.',
];

foreach ($outils as $chemin => $pourquoi) {
    $present = is_file(BASE_PATH . '/' . $chemin);

    verdict(RECOMMANDE, $chemin, !$present,
        $present ? 'présent' : 'absent',
        $pourquoi . ' Il refuse de s\'exécuter hors ligne de commande, mais rien '
        . 'ne le rend utile en production : retirez-le.');
}

// diagnostic.php se refuse lui-même hors debug ET hors machine locale.
// On le vérifie plutôt que de l'affirmer : le contrôle doit pouvoir dire
// que la garde a disparu.
$diag = BASE_PATH . '/public/diagnostic.php';

if (is_file($diag)) {
    $source = (string) file_get_contents($diag);
    $garde  = str_contains($source, "config('app.debug')")
        && str_contains($source, '127.0.0.1')
        && str_contains($source, 'http_response_code(404)');

    verdict($garde ? RECOMMANDE : BLOQUANT, 'public/diagnostic.php',
        false,
        $garde ? 'présent, avec sa garde (debug + machine locale)'
               : 'PRÉSENT SANS GARDE',
        $garde
            ? 'Sa garde tient, mais un fichier absent ne peut pas être contourné : '
              . 'retirez-le de la mise en ligne.'
            : 'Il expose la configuration du serveur. Retirez-le immédiatement.');
} else {
    verdict(RECOMMANDE, 'public/diagnostic.php', true, 'absent');
}

// --- storage/ : inscriptible, et hors de la racine web ----------------
//
// ON ÉCRIT VRAIMENT, ET ON DIT SOUS QUEL COMPTE.
//
// `is_writable()` répond pour l'utilisateur qui exécute CE script — le
// déployeur, en SSH — pas pour celui qui fait tourner le serveur web.
// Mesuré : un `storage/` en 500 passait le contrôle, parce que le
// script tournait sous un compte privilégié. Le déployeur aurait lu
// « oui » et le produit n'aurait rien pu écrire.
//
//   > Un contrôle d'écriture qui ne teste pas le bon compte rassure
//   > sur une panne qu'il devait trouver.
//
// On ne peut pas éprouver le compte du serveur web depuis la ligne de
// commande. On fait donc les deux choses honnêtes : on écrit
// RÉELLEMENT, et on NOMME le compte sous lequel on a écrit, pour que le
// déployeur le compare à celui d'Apache ou de PHP-FPM.
$storage = BASE_PATH . '/storage';

/** Écrit puis efface un fichier témoin. Rend null si le dossier manque. */
function dossier_inscriptible(string $chemin): ?bool
{
    if (!is_dir($chemin)) {
        return null;
    }

    // LES DEUX SEULS `@` DE CE FICHIER, ET ILS NE MASQUENT RIEN.
    //
    // Le projet interdit d'étouffer une erreur avec `@` pour la faire
    // disparaître. Ici, l'échec d'écriture N'EST PAS une erreur à taire :
    // c'est la mesure elle-même, et elle est rendue au rapport. Ce que
    // `@` empêche, c'est l'avertissement PHP de s'écrire sur la sortie
    // standard et de rendre le JSON illisible — la panne exacte que cet
    // outil a connue sous Windows.
    $temoin = $chemin . '/.preflight-' . bin2hex(random_bytes(4));
    $ecrit  = @file_put_contents($temoin, 'x');

    if ($ecrit !== false) {
        @unlink($temoin);
    }

    return $ecrit !== false;
}

$compte = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : (string) (getenv('USER') ?: getenv('USERNAME') ?: '?');

// Les sous-dossiers comptent autant que le parent : un `storage/`
// inscriptible avec un `storage/uploads/` en lecture seule casse le
// téléversement des photos sans que rien ne le laisse voir.
$illisibles = [];

foreach (['', '/logs', '/sessions', '/cache', '/uploads', '/backups'] as $sous) {
    if (dossier_inscriptible($storage . $sous) !== true) {
        $illisibles[] = 'storage' . ($sous !== '' ? $sous : '');
    }
}

verdict(BLOQUANT, 'storage/ inscriptible', $illisibles === [],
    $illisibles === []
        ? 'écriture réussie sous « ' . $compte . ' »'
        : 'écriture REFUSÉE sous « ' . $compte . ' » : ' . implode(', ', $illisibles),
    'chmod 775 sur storage/ et ses sous-dossiers, et donnez-les au compte du '
    . 'serveur web (www-data, apache, ou le compte cPanel). Ce contrôle a écrit '
    . 'sous « ' . $compte . ' » : si le serveur web tourne sous un autre compte, '
    . 'ce « oui » ne vaut pas pour lui.');

$dansLaRacineWeb = str_starts_with(
    realpath($storage) ?: $storage,
    (realpath(BASE_PATH . '/public') ?: BASE_PATH . '/public') . DIRECTORY_SEPARATOR
);

verdict(BLOQUANT, 'storage/ hors racine web', !$dansLaRacineWeb,
    $dansLaRacineWeb ? 'DANS public/' : 'hors de public/',
    'La racine web doit pointer sur public/, jamais sur la racine du projet.');

// --- Une sauvegarde, et pas seulement l'intention d'en faire ---------
//
// La liste en prose portait « sauvegarde automatique de la base
// configurée ». On ne peut pas mesurer une intention. On peut mesurer
// s'il EXISTE une sauvegarde, et de quand elle date.
//
//   > Une sauvegarde qu'on prévoit de faire protège exactement autant
//   > qu'aucune sauvegarde.
//
// Ce contrôle ne dit pas qu'elle est restaurable : c'est
// `php database/restore.php <archive> --verifier` qui le dit, et rien
// d'autre ne le dira à sa place.
$sauvegardes = glob(BASE_PATH . '/storage/backups/*.{zip,sql}', GLOB_BRACE) ?: [];
$derniere    = $sauvegardes !== [] ? max(array_map('filemtime', $sauvegardes)) : null;
$jours       = $derniere !== null ? (int) floor((time() - $derniere) / 86400) : null;

verdict(BLOQUANT, 'Sauvegarde', $derniere !== null && $jours !== null && $jours <= 7,
    $derniere === null
        ? 'AUCUNE dans storage/backups/'
        : count($sauvegardes) . ' présente(s), la plus récente il y a ' . $jours . ' jour(s)',
    'php database/backup.php — puis planifiez-la (tâche cron, ou le '
    . 'planificateur de votre hébergeur). Contrôlez qu\'elle est restaurable '
    . 'avec : php database/restore.php <archive> --verifier');

foreach (['app', 'database', 'storage'] as $dossier) {
    $ht = BASE_PATH . '/' . $dossier . '/.htaccess';

    verdict(RECOMMANDE, $dossier . '/.htaccess', is_file($ht),
        is_file($ht) ? 'présent' : 'ABSENT',
        'Deuxième barrière si la racine web pointe par erreur sur la racine du '
        . 'projet. Sans effet sur Nginx : la configuration du serveur doit alors '
        . 'refuser ces chemins.');
}

// =====================================================================
//  RESTITUTION
// =====================================================================

restituer($rapport, $asJson);

/**
 * Rend le rapport et sort. Fonction, et non fin de fichier, parce qu'un
 * prérequis manquant doit pouvoir rendre le rapport ARRÊTÉ LÀ plutôt que
 * de mourir en chemin.
 *
 * @param array<int, array{niveau: string, sujet: string, etat: string, constat: string, remede: string}> $rapport
 */
function restituer(array $rapport, bool $asJson): never
{
    $bloquants = array_values(array_filter(
        $rapport,
        static fn (array $l): bool => $l['niveau'] === BLOQUANT && $l['etat'] !== 'ok'
    ));
    $conseils = array_values(array_filter(
        $rapport,
        static fn (array $l): bool => $l['niveau'] === RECOMMANDE && $l['etat'] !== 'ok'
    ));

    if ($asJson) {
        echo json_encode([
            'date'       => date('c'),
            'bloquants'  => count($bloquants),
            'recommande' => count($conseils),
            'controles'  => $rapport,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";

        exit($bloquants === [] ? 0 : 1);
    }

    echo "\n  Contrôle avant mise en service — " . date('d/m/Y H:i') . "\n";

    // CE QUE L'INSTALLATION SE DÉCLARE ÊTRE.
    //
    // Sur une machine de développement, huit points bloquants sont la
    // BONNE réponse : debug allumé, pas de HTTPS, école de démonstration
    // en place. Rendus sans un mot, ils ressemblent pourtant à une
    // alarme — et un développeur qui voit huit croix rouges chaque
    // semaine finit par ne plus les lire.
    //
    //   > Un avertissement qu'on apprend à ignorer ne protège plus
    //   > personne.
    //
    // Le code de sortie, lui, ne bouge pas : la question posée reste
    // « cette installation peut-elle être ouverte au public ». Seule la
    // lecture change.
    $env = (string) config('app.env', '');

    echo $env === 'production'
        ? "  Cette installation se déclare EN PRODUCTION (app.env).\n"
        : "  Cette installation se déclare « " . ($env !== '' ? $env : 'non renseigné')
          . " » (app.env) : les points ci-dessous sont\n"
          . "  ce qu'il faudra corriger AVANT la mise en ligne, pas une panne du jour.\n";

    echo "  ────────────────────────────────────────────────────────────────\n\n";

    foreach ($rapport as $ligne) {
        $marque = match ($ligne['etat']) {
            'ok'      => '  ✓ ',
            'inconnu' => '  ? ',
            default   => $ligne['niveau'] === BLOQUANT ? '  ✗ ' : '  ! ',
        };

        // `printf('%-32s')` compte des OCTETS : « Migrations non altérées »
        // contient des caractères accentués sur deux octets et la colonne
        // se décalait. On aligne sur des CARACTÈRES — et sans `mbstring`,
        // que ce contrôle sait justement trouver manquante, on se rabat
        // sur un décompte approché plutôt que de mourir à l'affichage.
        $sujet = $ligne['sujet'];
        $large = function_exists('mb_strlen')
            ? mb_strlen($sujet)
            : strlen((string) preg_replace('/[\x80-\xBF]/', '', $sujet));
        $sujet .= str_repeat(' ', max(1, 32 - $large));

        echo $marque . $sujet . ' ' . $ligne['constat'] . "\n";

        if ($ligne['remede'] !== '') {
            foreach (explode("\n", wordwrap($ligne['remede'], 64)) as $bout) {
                echo '        ' . $bout . "\n";
            }
        }
    }

    echo "\n  ────────────────────────────────────────────────────────────────\n";
    printf("  %d contrôle(s) · %d bloquant(s) · %d recommandation(s)\n\n",
        count($rapport), count($bloquants), count($conseils));

    if ($bloquants !== []) {
        echo "  Le service ne doit PAS être ouvert dans cet état :\n";

        foreach ($bloquants as $ligne) {
            echo '    ✗ ' . $ligne['sujet'] . "\n";
        }

        echo "\n";
        exit(1);
    }

    echo "  Aucun point bloquant.\n";

    if ($conseils !== []) {
        echo "  Restent " . count($conseils) . " recommandation(s) à traiter.\n";
    }

    echo "\n";
    exit(0);
}
