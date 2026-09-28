<?php
/**
 * Phase 10A — le contrôle avant mise en service.
 *
 * CE QUE CES TESTS PROTÈGENT
 * --------------------------
 * Une installation conforme au README produisait un produit SANS clé de
 * chiffrement, et « mot de passe oublié » y répondait HTTP 500 — pour
 * tout le monde, dès le premier jour. La consigne existait, mais dans
 * `config.php`, le fichier que personne ne copie.
 *
 *   > Une consigne écrite dans le fichier qu'on ne copie pas n'est pas
 *   > une consigne : c'est une note pour soi-même.
 *
 * Trois choses sont verrouillées ici :
 *
 *  1. le MODÈLE de configuration porte la clé — c'est lui qu'on copie ;
 *  2. sans clé, un message sensible est REFUSÉ, jamais écrit en clair,
 *     et surtout jamais par une exception sur une route publique ;
 *  3. le contrôle avant mise en service s'EXÉCUTE, et ses verdicts
 *     correspondent à la configuration réellement lue.
 *
 * Le troisième point mérite un mot : une liste de contrôle en prose
 * décrivait, dans ce projet, trois points déjà réglés et taisait celui
 * qui empêchait le produit de fonctionner.
 *
 *   > Une liste de contrôle qu'on n'exécute pas décrit le produit du
 *   > jour où on l'a écrite.
 *
 * Usage : php tests/preflight_mise_en_service.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

echo "\n  PHASE 10A — CONTRÔLE AVANT MISE EN SERVICE\n";
echo "  ══════════════════════════════════════════════════════════\n";

// =====================================================================
//  1. LE MODÈLE DE CONFIGURATION — celui qu'on copie
// =====================================================================

echo "\n  Le modèle que le README fait copier\n";

$modele = BASE_PATH . '/app/config/config.local.example.php';

check('Le modèle existe', is_file($modele));

/** @var array<string, mixed> $exemple */
$exemple = is_file($modele) ? require $modele : [];

check('Il déclare une section security',
    array_key_exists('security', $exemple) && is_array($exemple['security']));

check('Il déclare security.encryption_key',
    isset($exemple['security']) && is_array($exemple['security'])
        && array_key_exists('encryption_key', $exemple['security']),
    'sans cette ligne, l\'installation produit un produit sans clé');

// La valeur reste vide : un modèle versionné qui porterait une vraie clé
// livrerait à chaque déployeur LA MÊME clé, ce qui revient à ne pas en
// avoir. C'est l'installateur qui en tire une, à l'exécution.
check('La valeur d\'exemple est vide',
    ($exemple['security']['encryption_key'] ?? null) === '',
    'une clé écrite dans un fichier versionné serait partagée par tous');

$source = is_file($modele) ? (string) file_get_contents($modele) : '';

check('Il donne la commande de génération',
    str_contains($source, 'base64_encode(random_bytes(32))'));

check('Il avertit que « root » ne va pas en production',
    stripos($source, 'root') !== false && stripos($source, 'JAMAIS') !== false);

// =====================================================================
//  2. L'INSTALLATEUR REFUSE D'INSTALLER SANS CLÉ
// =====================================================================

echo "\n  L'installateur, avant la première écriture\n";

$installeur = (string) file_get_contents(BASE_PATH . '/database/install.php');

$posControle = strpos($installeur, "config('security.encryption_key'");
$posBase     = strpos($installeur, 'new PDO(');

check('L\'installateur lit la clé', $posControle !== false);

check('Il la contrôle AVANT de se connecter à la base',
    $posControle !== false && $posBase !== false && $posControle < $posBase,
    'un prérequis vérifié après coup n\'est pas un prérequis');

// =====================================================================
//  3. SANS CLÉ : REFUS, JAMAIS DE CLAIR, JAMAIS D'EXCEPTION
// =====================================================================
//
// `mail_body_storage()` est la décision réellement livrée : `mail_queue()`
// l'appelle avec `crypto_available()`. On la joue ici avec `false`, ce
// qui éprouve le code du produit sans démonter la configuration du
// serveur qui exécute le test.

echo "\n  Le corps d'un message, sans clé de chiffrement\n";

$lien   = 'https://ecole.cd/mot-de-passe/reinitialiser/SECRET-EN-CLAIR-0123456789';
$texte  = "Bonjour,\nOuvrez ce lien : " . $lien . "\n";
$html   = '<p>' . $lien . '</p>';

$sansCle = mail_body_storage($texte, $html, true, false);

check('Un corps SENSIBLE est refusé', $sansCle['ok'] === false);
check('Rien n\'est rendu à écrire', $sansCle['text'] === null && $sansCle['html'] === null);
check('Le refus nomme la cause',
    str_contains($sansCle['error'], 'security.encryption_key'));
check('Le refus ne recopie pas le secret',
    !str_contains($sansCle['error'], $lien),
    'un message d\'erreur voyage dans les journaux');

// Un message ORDINAIRE n'a pas besoin de clé : une annonce de l'école
// n'est pas un secret, et la bloquer serait une panne inventée.
$ordinaire = mail_body_storage('Réunion de parents le 12.', '<p>Réunion</p>', false, false);

check('Un corps ORDINAIRE passe sans clé', $ordinaire['ok'] === true);
check('Il est stocké tel quel', $ordinaire['text'] === 'Réunion de parents le 12.');

// Avec clé — ce que produit ce serveur-ci.
if (crypto_available()) {
    $avecCle = mail_body_storage($texte, $html, true, true);

    check('Avec clé, le corps sensible est accepté', $avecCle['ok'] === true);
    check('Il est chiffré', str_starts_with((string) $avecCle['text'], 'v1:'));
    check('Le HTML aussi', str_starts_with((string) $avecCle['html'], 'v1:'));
    check('Le clair n\'apparaît nulle part',
        !str_contains((string) $avecCle['text'], $lien)
            && !str_contains((string) $avecCle['html'], $lien));
    check('Il se relit à l\'identique', crypto_decrypt((string) $avecCle['text']) === $texte);

    // Un HTML absent reste absent : on ne chiffre pas du vide.
    $sansHtml = mail_body_storage($texte, null, true, true);
    check('Un HTML absent reste null', $sansHtml['html'] === null);
    $htmlVide = mail_body_storage($texte, '', true, true);
    check('Un HTML vide reste vide', $htmlVide['html'] === '');
} else {
    check('Ce serveur a une clé de chiffrement', false,
        'les contrôles « avec clé » n\'ont pas pu être joués');
}

// =====================================================================
//  4. LE CONTRÔLE AVANT MISE EN SERVICE S'EXÉCUTE
// =====================================================================

echo "\n  Le contrôle lui-même\n";

$script = BASE_PATH . '/database/preflight.php';

check('Le contrôle existe', is_file($script));

// ON NE FUSIONNE PAS LA SORTIE D'ERREUR DANS LE JSON.
//
// Mesuré sur Windows : `2>&1` ramenait un message de `cmd` devant le
// rapport, `json_decode` rendait null, et ONZE contrôles tombaient en
// cascade sur une cause qui n'avait rien à voir avec eux.
//
//   > Une sortie d'erreur mêlée à une sortie de données transforme un
//   > incident en mystère.
$sortie = [];
$code   = 0;
exec('php ' . escapeshellarg($script) . ' --json', $sortie, $code);

$json = json_decode(implode("\n", $sortie), true);

check('Il rend du JSON exploitable', is_array($json) && isset($json['controles']));

$controles = is_array($json['controles'] ?? null) ? $json['controles'] : [];

check('Il joue plus de vingt contrôles', count($controles) >= 20,
    count($controles) . ' contrôle(s)');

$formeOk = $controles !== [];

foreach ($controles as $c) {
    if (!isset($c['niveau'], $c['sujet'], $c['etat'])
        || !in_array($c['niveau'], ['bloquant', 'recommandé'], true)
        || !in_array($c['etat'], ['ok', 'echec', 'inconnu'], true)) {
        $formeOk = false;
        break;
    }
}

check('Chaque contrôle porte un niveau et un état', $formeOk);

/** Retrouve un contrôle par son sujet. */
function controle(array $controles, string $sujet): ?array
{
    foreach ($controles as $c) {
        if (($c['sujet'] ?? '') === $sujet) {
            return $c;
        }
    }

    return null;
}

// Les verdicts doivent correspondre à ce que ce serveur EST, pas à une
// constante : c'est la différence entre une mesure et une déclaration.
$cle = controle($controles, 'Clé de chiffrement');
check('Il mesure la clé de chiffrement', $cle !== null);
check('Son verdict sur la clé correspond à la configuration lue',
    $cle !== null && ($cle['etat'] === 'ok') === crypto_available());

$debug = controle($controles, 'app.debug');
check('Il mesure app.debug', $debug !== null);
check('Son verdict sur app.debug correspond à la configuration lue',
    $debug !== null && ($debug['etat'] === 'ok') === (config('app.debug', false) === false));

$mysql = controle($controles, 'Compte MySQL');
check('Il refuse le compte « root »',
    $mysql !== null
        && ($mysql['etat'] === 'ok') === (strtolower((string) config('database.user', '')) !== 'root'));

// Un bloquant doit porter un remède : un contrôle qui dit « non » sans
// dire quoi faire déplace le problème, il ne le règle pas.
$sansRemede = array_values(array_filter(
    $controles,
    static fn (array $c): bool => ($c['etat'] ?? '') !== 'ok' && trim((string) ($c['remede'] ?? '')) === ''
));

// DEUX ASSERTIONS PASSAIENT SUR UN RAPPORT VIDE.
//
// Constaté sur la sortie Windows : le JSON était illisible, `$controles`
// valait `[]`, et « tout constat en échec porte un remède » répondait ✓
// — parce qu'il n'y avait aucun constat. Le décompte des bloquants
// passait de même, en comparant -1 à 0.
//
//   > Une assertion qui réussit sur le vide ne mesure pas le produit,
//   > elle mesure son absence.
check('Tout constat en échec porte un remède',
    $controles !== [] && $sansRemede === [],
    $sansRemede !== [] ? 'sans remède : ' . ($sansRemede[0]['sujet'] ?? '?') : '');

// Le code de sortie doit suivre le décompte, sinon il est inutilisable
// dans un enchaînement de mise en ligne.
$compte    = is_array($json) && array_key_exists('bloquants', $json);
$bloquants = $compte ? (int) $json['bloquants'] : -1;

check('Le code de sortie suit le décompte des bloquants',
    $compte && (($code === 0) === ($bloquants === 0)),
    $compte ? "sortie {$code}, {$bloquants} bloquant(s)" : 'aucun décompte rendu');

// Les sujets que la documentation promet de contrôler.
foreach ([
    'app.debug', 'session.cookie_secure', 'app.url', 'Clé de chiffrement',
    'Compte MySQL', 'Migrations appliquées', 'Établissement de démonstration',
    'Serveur d\'envoi', 'storage/ hors racine web', 'public/diagnostic.php',
] as $sujet) {
    check('Contrôlé : ' . $sujet, controle($controles, $sujet) !== null);
}

// =====================================================================
//  5. CHAQUE CONTRÔLE SAIT-IL DIRE « NON » ?
// =====================================================================
//
// Le contrôle rend « ✓ » sur une installation préparée. Cela ne prouve
// rien : un contrôle qui rendrait « ✓ » par construction rendrait
// exactement la même chose.
//
//   > Un contrôle qu'on n'a jamais vu échouer n'est pas un contrôle,
//   > c'est une décoration.
//
// On casse UNE condition à la fois, sur un décor jetable — jamais sur la
// configuration du projet, qu'un test qui tombe laisserait abîmée.

echo "\n  Chaque contrôle sait-il dire « non » ?\n";

$decor = sys_get_temp_dir() . '/preflight-' . bin2hex(random_bytes(6));

/** Bâtit un décor minimal que `preflight.php` sait inspecter. */
function decor_batir(string $racine): void
{
    foreach (['app/config', 'app/core', 'database', 'public',
              'storage/logs', 'storage/sessions', 'storage/cache',
              'storage/uploads', 'storage/backups'] as $d) {
        mkdir($racine . '/' . $d, 0o775, true);
    }

    // Le produit RÉEL, pas une copie : on éprouve le code livré.
    foreach ([
        'app/config/config.php'  => BASE_PATH . '/app/config/config.php',
        'app/core/helpers.php'   => BASE_PATH . '/app/core/helpers.php',
        'database/runner.php'    => BASE_PATH . '/database/runner.php',
        'database/preflight.php' => BASE_PATH . '/database/preflight.php',
    ] as $vers => $depuis) {
        copy($depuis, $racine . '/' . $vers);
    }

    foreach (['app', 'database', 'storage'] as $d) {
        file_put_contents($racine . '/' . $d . '/.htaccess', "Deny from all\n");
    }
}

/** Écrit une configuration locale à partir d'un gabarit sain. */
function decor_config(string $racine, array $remplacements = []): void
{
    $sain = <<<'PHP'
    <?php
    declare(strict_types=1);
    return [
        'app' => ['env' => 'production', 'debug' => false, 'url' => 'https://ecole.exemple.cd'],
        'database' => ['host' => '127.0.0.1', 'port' => 3307, 'name' => 'aucune_base',
                       'user' => 'compte_dedie', 'password' => 'MotDePasse!2026'],
        'security' => ['encryption_key' => 'MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE='],
        'session'  => ['cookie_secure' => true],
        'mail'     => ['host' => 'smtp.exemple.cd'],
    ];
    PHP;

    foreach ($remplacements as $de => $vers) {
        $sain = str_replace($de, $vers, $sain);
    }

    file_put_contents($racine . '/app/config/config.local.php', $sain);
}

/** Exécute le contrôle sur le décor et rend son rapport. */
function decor_mesurer(string $racine): array
{
    $sortie = [];
    exec('php ' . escapeshellarg($racine . '/database/preflight.php') . ' --json', $sortie);

    $json = json_decode(implode("\n", $sortie), true);

    return is_array($json['controles'] ?? null) ? $json['controles'] : [];
}

/** Retire un lien symbolique, quel que soit le système. */
function lien_retirer(string $chemin): bool
{
    return PHP_OS_FAMILY === 'Windows' && is_dir($chemin)
        ? rmdir($chemin)
        : unlink($chemin);
}

/** Efface un dossier et son contenu, sans dépendre du système. */
function decor_effacer(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }

    $entrees = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entrees as $entree) {
        // Un lien vers un dossier se retire avec rmdir sous Windows et
        // unlink ailleurs. On choisit selon le système : masquer l'échec
        // avec @ reviendrait à ne pas savoir ce qu'on a effacé.
        if ($entree->isLink()) {
            lien_retirer($entree->getPathname());
            continue;
        }

        $entree->isDir() ? rmdir($entree->getPathname()) : unlink($entree->getPathname());
    }

    rmdir($chemin);
}

/** L'état rendu pour ce sujet. */
function decor_etat(array $controles, string $sujet): string
{
    foreach ($controles as $c) {
        if (($c['sujet'] ?? '') === $sujet) {
            return (string) $c['etat'];
        }
    }

    return 'ABSENT';
}

decor_batir($decor);

try {
    // Référence : la même clé, le même gabarit, rien de cassé.
    decor_config($decor);
    $sain = decor_mesurer($decor);

    check('Le décor de référence est mesurable', $sain !== [],
        count($sain) . ' contrôle(s)');

    $reference = count($sain);

    // Un contrôle par condition cassée. Le sujet visé doit basculer.
    $ruptures = [
        ['app.debug',              ["'debug' => false" => "'debug' => true"]],
        ['app.env',                ["'env' => 'production'" => "'env' => 'local'"]],
        ['app.url',                ["'https://ecole.exemple.cd'" => "'http://localhost:8080'"]],
        ['app.url en HTTPS',       ["'https://ecole.exemple.cd'" => "'http://ecole.exemple.cd'"]],
        ['session.cookie_secure',  ["'cookie_secure' => true" => "'cookie_secure' => false"]],
        ['Compte MySQL',           ["'user' => 'compte_dedie'" => "'user' => 'root'"]],
        ['Mot de passe MySQL',     ["'password' => 'MotDePasse!2026'" => "'password' => ''"]],
    ];

    foreach ($ruptures as [$sujet, $remplacements]) {
        decor_config($decor, $remplacements);
        check('Sait dire non : ' . $sujet,
            decor_etat(decor_mesurer($decor), $sujet) === 'echec');
    }

    // Le serveur d'envoi : `mail.host` suffit à répondre OUI sans la base.
    // Vidé, et la base injoignable, la réponse honnête est « non mesuré »
    // — pas « ok », et pas une disparition.
    check('Serveur d\'envoi : mail.host seul suffit à répondre oui',
        decor_etat($sain, 'Serveur d\'envoi') === 'ok');

    decor_config($decor, ["'host' => 'smtp.exemple.cd'" => "'host' => ''"]);
    check('Serveur d\'envoi : vidé et sans base, c\'est « non mesuré »',
        decor_etat(decor_mesurer($decor), 'Serveur d\'envoi') === 'inconnu');

    // La clé, sous ses trois formes de panne.
    $clesFausses = [
        'vide'              => "''",
        'pas du base64'     => "'ceci n est pas du base64 !'",
        'trop courte'       => "'MTIzNDU2Nzg5MDEyMzQ1Ng=='",
    ];

    foreach ($clesFausses as $forme => $valeur) {
        decor_config($decor, ["'MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE='" => $valeur]);
        check('Sait dire non : clé ' . $forme,
            decor_etat(decor_mesurer($decor), 'Clé de chiffrement') === 'echec');
    }

    decor_config($decor);

    // --- Les fichiers -------------------------------------------------
    file_put_contents($decor . '/public/diagnostic.php', "<?php phpinfo();\n");
    $ctrl = decor_mesurer($decor);
    check('Sait dire non : diagnostic.php SANS garde',
        decor_etat($ctrl, 'public/diagnostic.php') === 'echec');

    // Sans garde, il devient BLOQUANT — la nuance est le contrôle.
    $niveauDiag = '';
    foreach ($ctrl as $c) {
        if ($c['sujet'] === 'public/diagnostic.php') {
            $niveauDiag = (string) $c['niveau'];
        }
    }
    check('… et il passe alors en bloquant', $niveauDiag === 'bloquant');
    unlink($decor . '/public/diagnostic.php');

    file_put_contents($decor . '/database/seed_demo.php', "<?php\n");
    check('Sait dire non : seed_demo.php laissé en ligne',
        decor_etat(decor_mesurer($decor), 'database/seed_demo.php') === 'echec');
    unlink($decor . '/database/seed_demo.php');

    unlink($decor . '/app/.htaccess');
    check('Sait dire non : app/.htaccess supprimé',
        decor_etat(decor_mesurer($decor), 'app/.htaccess') === 'echec');
    file_put_contents($decor . '/app/.htaccess', "Deny from all\n");

    // CETTE CONDITION NE SE CONSTRUIT PAS SANS LIEN SYMBOLIQUE.
    //
    // Sous Windows, `symlink()` exige des privilèges d'administrateur.
    // La première version ne s'en souciait pas : elle PLANTAIT, et les
    // contrôles suivants n'étaient jamais joués.
    //
    //   > Un test qui ne sait pas jouer un cas doit le DIRE, pas mourir
    //   > en essayant.
    //
    // On tente, et si la plateforme refuse, on le déclare non joué —
    // sans l'écrire comme une réussite.
    rename($decor . '/storage', $decor . '/public/storage');

    $lienPose = false;

    try {
        $lienPose = symlink($decor . '/public/storage', $decor . '/storage');
    } catch (Throwable) {
        $lienPose = false;
    }

    if ($lienPose) {
        check('Sait dire non : storage/ dans la racine web',
            decor_etat(decor_mesurer($decor), 'storage/ hors racine web') === 'echec');

        if (is_link($decor . '/storage')) {
            lien_retirer($decor . '/storage');
        }
    } else {
        echo "  · storage/ dans la racine web : non joué — cette plateforme "
            . "refuse les liens symboliques\n";
    }

    rename($decor . '/public/storage', $decor . '/storage');

    rename($decor . '/app/config/config.local.php', $decor . '/app/config/config.local.off');
    check('Sait dire non : config.local.php absent',
        decor_etat(decor_mesurer($decor), 'config.local.php') === 'echec');
    rename($decor . '/app/config/config.local.off', $decor . '/app/config/config.local.php');

    // --- La liste ne rétrécit JAMAIS ----------------------------------
    //
    // Constaté par accident, le serveur MySQL s'étant arrêté : le rapport
    // passait de 26 à 21 contrôles sans un mot, et rien ne distinguait
    // « non vérifié » de « vérifié et bon ».
    //
    //   > Un contrôle qui disparaît quand une mesure échoue laisse croire
    //   > qu'il est passé.
    $injoignable = decor_mesurer($decor);

    check('Base injoignable : la liste garde sa longueur',
        count($injoignable) === $reference,
        count($injoignable) . ' contre ' . $reference);

    foreach (['Migrations appliquées', 'Migrations non altérées',
              'Établissement de démonstration', 'Comptes par défaut',
              'Serveur d\'envoi'] as $sujet) {
        check('Base injoignable : « ' . $sujet .' » est rendu',
            decor_etat($injoignable, $sujet) !== 'ABSENT');
    }
} finally {
    // `rm -rf` N'EXISTE PAS SOUS WINDOWS : le décor jetable y restait sur
    // le disque à chaque exécution. On efface en PHP, partout pareil.
    decor_effacer($decor);
}

check('Le décor jetable est nettoyé', !is_dir($decor));

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
