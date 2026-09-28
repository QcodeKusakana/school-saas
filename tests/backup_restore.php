<?php
/**
 * Phase 10B — la sauvegarde et SA RESTAURATION ÉPROUVÉE.
 *
 * CE QUE CES TESTS PROTÈGENT
 * --------------------------
 * Une sauvegarde qu'on n'a jamais restaurée n'est pas une protection,
 * c'est une croyance. Ce fichier la met à l'épreuve pour de vrai :
 *
 *  · une base complète est prise, DÉTRUITE, puis rendue ;
 *  · les 61 empreintes de table sont comparées une à une ;
 *  · la vérification sait dire NON — sinon elle ne vérifie rien ;
 *  · une adresse IP binaire revient OCTET POUR OCTET ;
 *  · la colonne générée se recalcule au lieu d'être insérée ;
 *  · une archive plus récente que le code est REFUSÉE ;
 *  · la restauration exige le nom de la base, en toutes lettres ;
 *  · les fichiers déposés reviennent avec la base.
 *
 * SUR UN DÉCOR JETABLE, JAMAIS SUR LA BASE DE TRAVAIL.
 * Le test détruit une base pour prouver qu'on sait la rendre. Le faire
 * sur celle du développeur rendrait la suite impossible à relancer le
 * jour où elle trouve un vrai défaut — c'est-à-dire le seul jour qui
 * compte.
 *
 * Usage : php tests/backup_restore.php
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

echo "\n  PHASE 10B — SAUVEGARDE ET RESTAURATION\n";
echo "  ══════════════════════════════════════════════════════════\n";

// =====================================================================
//  LE DÉCOR
// =====================================================================

$base   = 'school_saas_recette_sauvegarde';
$racine = sys_get_temp_dir() . '/sauvegarde-' . bin2hex(random_bytes(6));

/** Le serveur, sans base sélectionnée — pour créer et détruire la nôtre. */
function serveur(): PDO
{
    return new PDO(
        'mysql:host=' . (string) config('database.host')
        . ';port=' . (int) config('database.port', 3306) . ';charset=utf8mb4',
        (string) config('database.user'),
        (string) config('database.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function decor_base(string $base): PDO
{
    return new PDO(
        'mysql:host=' . (string) config('database.host')
        . ';port=' . (int) config('database.port', 3306)
        . ';dbname=' . $base . ';charset=utf8mb4',
        (string) config('database.user'),
        (string) config('database.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/** Lance un script du décor et rend sa sortie et son code. */
function lancer(string $racine, string $script, array $args = [], string $entree = ''): array
{
    $commande = 'php ' . escapeshellarg($racine . '/database/' . $script);

    foreach ($args as $a) {
        $commande .= ' ' . escapeshellarg($a);
    }

    $processus = proc_open(
        $commande,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $tuyaux
    );

    if (!is_resource($processus)) {
        return ['sortie' => '', 'erreur' => 'proc_open a échoué', 'code' => -1];
    }

    fwrite($tuyaux[0], $entree);
    fclose($tuyaux[0]);

    $sortie = (string) stream_get_contents($tuyaux[1]);
    $erreur = (string) stream_get_contents($tuyaux[2]);

    fclose($tuyaux[1]);
    fclose($tuyaux[2]);

    return ['sortie' => $sortie, 'erreur' => $erreur, 'code' => proc_close($processus)];
}

function effacer(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }

    $entrees = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entrees as $entree) {
        $entree->isDir() ? rmdir($entree->getPathname()) : unlink($entree->getPathname());
    }

    rmdir($chemin);
}

// --- Bâtir --------------------------------------------------------------
mkdir($racine . '/app/config', 0o775, true);
mkdir($racine . '/app/core', 0o775, true);
mkdir($racine . '/storage/backups', 0o775, true);
mkdir($racine . '/storage/uploads/photos/1', 0o775, true);

// LE CODE RÉELLEMENT LIVRÉ, pas une copie réécrite.
copy(BASE_PATH . '/app/config/config.php', $racine . '/app/config/config.php');
copy(BASE_PATH . '/app/core/helpers.php',  $racine . '/app/core/helpers.php');
copy(BASE_PATH . '/app/core/logger.php',   $racine . '/app/core/logger.php');

$copierDossier = static function (string $de, string $vers) use (&$copierDossier): void {
    if (!is_dir($vers)) {
        mkdir($vers, 0o775, true);
    }

    foreach (scandir($de) ?: [] as $entree) {
        if ($entree === '.' || $entree === '..') {
            continue;
        }

        is_dir($de . '/' . $entree)
            ? $copierDossier($de . '/' . $entree, $vers . '/' . $entree)
            : copy($de . '/' . $entree, $vers . '/' . $entree);
    }
};

$copierDossier(BASE_PATH . '/database', $racine . '/database');

file_put_contents(
    $racine . '/app/config/config.local.php',
    "<?php\ndeclare(strict_types=1);\nreturn [\n"
    . "    'app' => ['env' => 'local', 'debug' => true, 'url' => 'http://recette.test'],\n"
    . "    'database' => ['host' => " . var_export((string) config('database.host'), true)
    . ", 'port' => " . (int) config('database.port', 3306)
    . ", 'name' => " . var_export($base, true)
    . ", 'user' => " . var_export((string) config('database.user'), true)
    . ", 'password' => " . var_export((string) config('database.password'), true) . "],\n"
    . "    'security' => ['encryption_key' => " . var_export(base64_encode(random_bytes(32)), true) . "],\n"
    . "];\n"
);

// Un fichier déposé, pour vérifier qu'il revient avec la base.
$photo = $racine . '/storage/uploads/photos/1/eleve-recette.png';
file_put_contents($photo, random_bytes(512));
$empreintePhoto = md5_file($photo);

try {
    // LE COMPTE DE L'APPLICATION N'A DE DROITS QUE SUR SA BASE — et c'est
    // ainsi que le contrôle avant mise en service exige qu'il soit. Ce
    // test a besoin d'une base à lui : s'il ne peut pas la créer, il le
    // DIT et s'arrête, au lieu de tomber sur une exception que personne
    // ne saura relier au droit manquant.
    try {
        $serveur = serveur();
        $serveur->exec("DROP DATABASE IF EXISTS `{$base}`");
        $serveur->exec("CREATE DATABASE `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        check('Le compte MySQL peut créer la base de recette', false, $e->getMessage());

        echo "\n  Cette recette détruit une base pour prouver qu'on sait la rendre.\n";
        echo "  Elle lui en faut donc une à elle. Accordez le droit :\n\n";
        echo "    GRANT ALL PRIVILEGES ON `school\\_saas\\_recette\\_%`.* TO '"
            . (string) config('database.user') . "'@'localhost';\n\n";
        echo "  (sous Laragon, le compte root l'a déjà)\n";

        printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);
        effacer($racine);
        exit(1);
    }

    // =================================================================
    //  1. UNE BASE COMPLÈTE, INSTALLÉE PAR LE PRODUIT
    // =================================================================

    echo "\n  Une base installée par le produit lui-même\n";

    $install = lancer($racine, 'install.php', [], "recette.sauv\nrecette@exemple.cd\nRecette2026Ab\n");

    check('L\'installateur mène l\'installation à son terme',
        str_contains($install['sortie'], 'Installation terminée'),
        $install['code'] !== 0 ? 'code ' . $install['code'] : '');

    $db = decor_base($base);

    $nbTables = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    )->fetchColumn();

    check('Le schéma est complet', $nbTables >= 60, $nbTables . ' table(s)');

    // --- Les deux pièges du schéma, plantés exprès -------------------
    //
    // UNE ADRESSE IP BINAIRE. Cinq colonnes du schéma portent des
    // octets bruts, pas du texte. C'est LE piège d'un export écrit à la
    // main : traitée comme une chaîne, l'adresse revient corrompue sans
    // que rien ne le signale.
    $ipBrute = inet_pton('2001:db8::dead:beef');

    $db->prepare(
        "INSERT INTO login_attempts (identifier, ip_address, successful, created_at)
         VALUES ('recette.binaire', :ip, 0, NOW())"
    )->execute(['ip' => $ipBrute]);

    $idIp = (int) $db->lastInsertId();

    check('Une adresse IPv6 binaire est posée dans le décor',
        strlen((string) $ipBrute) === 16);

    // --- LE TEXTE QUI CASSE LES EXPORTS ÉCRITS À LA MAIN -------------
    //
    // Une apostrophe, une antislash, un point-virgule, un commentaire
    // SQL, un accent, un émoji, un retour à la ligne : chacun a fait
    // tomber un export un jour. Ils traversent tous `PDO::quote()`, le
    // fichier, `sql_split()`, puis MySQL. On les fait voyager ensemble.
    $textes = [
        'apostrophe'      => "O'Brien, dit l'Ancien",
        'antislash'       => 'C:\\laragon\\www\\school-saas',
        'point-virgule'   => 'fin; DROP TABLE users; --',
        'commentaire'     => '-- ceci n\'est pas un commentaire',
        'accents'         => 'Élève à Kinshasa — « guillemets »',
        'emoji'           => 'école 👨‍🏫 rentrée 🎒',
        'retour-ligne'    => "ligne 1\nligne 2\r\nligne 3",
        'tabulation'      => "avant\tapres",
        'chaine-vide'     => '',
    ];

    $idsTexte = [];

    foreach ($textes as $nom => $valeur) {
        $req = $db->prepare(
            "INSERT INTO login_attempts (identifier, ip_address, successful, user_agent, created_at)
             VALUES (:id, :ip, 0, :ua, NOW())"
        );
        $req->execute([
            'id' => 'texte.' . $nom,
            'ip' => inet_pton('10.0.0.1'),
            'ua' => $valeur,
        ]);
        $idsTexte[$nom] = (int) $db->lastInsertId();
    }

    // Et la distinction NULL / chaîne vide, que beaucoup d'exports
    // écrasent l'une sur l'autre.
    $req = $db->prepare(
        "INSERT INTO login_attempts (identifier, ip_address, successful, user_agent, created_at)
         VALUES ('texte.null', :ip, 0, NULL, NOW())"
    );
    $req->execute(['ip' => inet_pton('10.0.0.2')]);
    $idNull = (int) $db->lastInsertId();

    check('Neuf textes pièges sont posés dans le décor', count($idsTexte) === 9);

    // --- Un fichier déposé, dans le décor ----------------------------
    check('Un fichier déposé est présent', is_file($photo));

    // =================================================================
    //  1bis. UN SEUL INSTANT POUR LES 61 TABLES
    // =================================================================
    //
    // La sauvegarde parcourt les tables l'une après l'autre. Sans
    // isolation, chacune est lue à un instant différent : `classrooms`
    // est lue AVANT `enrollments`, si bien qu'une classe créée entre
    // les deux donnerait une archive portant l'inscription SANS la
    // classe.
    //
    // Et la restauration ne le verrait pas : elle suspend les clés
    // étrangères pendant le chargement, et MySQL NE REVALIDE PAS
    // l'existant en les rétablissant — la clé pendante resterait, en
    // silence, et les écritures suivantes passeraient.
    //
    //   > Une sauvegarde prise pendant que l'école travaille n'est pas
    //   > une photo : c'est un collage, à moins qu'on ne l'exige
    //   > autrement.
    //
    // On éprouve la fonction du produit, pas le comportement de MySQL
    // en général.

    echo "\n  Un seul instant pour les 61 tables\n";

    require_once BASE_PATH . '/database/dump.php';

    $lecteur  = decor_base($base);
    $ecrivain = decor_base($base);

    backup_ouvrir_instantane($lecteur);

    $avantEcriture = (int) $lecteur->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();

    $ecrivain->prepare(
        "INSERT INTO login_attempts (identifier, ip_address, successful, created_at)
         VALUES ('pendant.la.sauvegarde', :ip, 0, NOW())"
    )->execute(['ip' => inet_pton('10.7.7.7')]);

    $pendant = (int) $lecteur->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();

    backup_fermer_instantane($lecteur);

    $apresFermeture = (int) $lecteur->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();

    check('Une écriture concurrente reste INVISIBLE pendant l\'instantané',
        $pendant === $avantEcriture,
        $avantEcriture . ' puis ' . $pendant);

    check('… alors qu\'elle a bien eu lieu', $apresFermeture === $avantEcriture + 1,
        $apresFermeture . ' après fermeture');

    $ecrivain->exec("DELETE FROM login_attempts WHERE identifier = 'pendant.la.sauvegarde'");

    // =================================================================
    //  2. LA SAUVEGARDE
    // =================================================================

    echo "\n  La sauvegarde\n";

    $sauvegarde = lancer($racine, 'backup.php');

    check('La sauvegarde se termine sans erreur', $sauvegarde['code'] === 0,
        $sauvegarde['erreur'] !== '' ? trim($sauvegarde['erreur']) : '');

    $archives = glob($racine . '/storage/backups/*.zip') ?: [];

    check('Une archive est écrite', count($archives) === 1);

    $archive = $archives[0] ?? '';

    $zip = new ZipArchive();
    $ouvert = $archive !== '' && $zip->open($archive) === true;

    check('L\'archive s\'ouvre', $ouvert);

    if ($ouvert) {
        check('Elle contient base.sql', $zip->locateName('base.sql') !== false);
        check('Elle contient manifeste.json', $zip->locateName('manifeste.json') !== false);
        check('Elle contient le fichier déposé',
            $zip->locateName('uploads/photos/1/eleve-recette.png') !== false);

        $manifeste = json_decode((string) $zip->getFromName('manifeste.json'), true);
        $sql       = (string) $zip->getFromName('base.sql');
        $zip->close();

        check('Le manifeste décrit chaque table',
            is_array($manifeste) && count($manifeste['tables'] ?? []) === $nbTables,
            count($manifeste['tables'] ?? []) . ' contre ' . $nbTables);

        check('Le manifeste garde la liste des migrations',
            count($manifeste['migrations'] ?? []) > 30);

        // --- Les trois pièges, dans le fichier produit ---------------
        check('L\'adresse binaire sort en hexadécimal',
            str_contains($sql, '0x' . bin2hex((string) $ipBrute)),
            'et non comme du texte');

        check('La colonne générée n\'est jamais insérée',
            !preg_match('/INSERT INTO `subscription_payments` \([^)]*reference_live/', $sql));

        check('Les clés étrangères sont suspendues puis rétablies',
            str_contains($sql, 'SET FOREIGN_KEY_CHECKS = 0;')
            && str_contains($sql, 'SET FOREIGN_KEY_CHECKS = 1;'));
    }

    // =================================================================
    //  3. LA VÉRIFICATION SAIT-ELLE DIRE NON ?
    // =================================================================
    //
    // Une vérification qui répond « conforme » quoi qu'il arrive ne
    // vérifie rien. On la met à l'épreuve AVANT de lui faire confiance.

    echo "\n  La vérification sait-elle dire non ?\n";

    $avant = lancer($racine, 'restore.php', [$archive, '--verifier']);

    check('Base intacte : elle dit conforme', $avant['code'] === 0,
        trim(explode("\n", trim($avant['sortie']))[count(explode("\n", trim($avant['sortie']))) - 1] ?? ''));

    // Une seule valeur changée, sans toucher au nombre de lignes : c'est
    // le cas qu'un simple comptage laisserait passer.
    //
    // ON VÉRIFIE QUE LA MODIFICATION A BIEN EU LIEU.
    // Première version : elle modifiait `schools`, table VIDE sans
    // l'option --demo. Zéro ligne touchée, donc rien de changé, donc une
    // vérification qui répondait « conforme » — et une recette qui
    // accusait un produit sain.
    //
    //   > Une sonde qui s'exécute sur un décor vide ne mesure pas le
    //   > produit, elle mesure le vide.
    $modifie = $db->exec(
        "UPDATE users SET email = 'modifie-apres-sauvegarde@exemple.cd'
          WHERE username = 'recette.sauv'"
    );

    check('Le décor a bien été modifié avant de vérifier', $modifie === 1,
        (int) $modifie . ' ligne(s) touchée(s)');

    $apres = lancer($racine, 'restore.php', [$archive, '--verifier']);

    check('Un seul champ modifié : elle dit NON', $apres['code'] !== 0);
    check('… et elle NOMME la table en écart',
        str_contains($apres['sortie'], 'users'));
    check('… en signalant que le compte de lignes n\'a pas bougé',
        str_contains($apres['sortie'], 'CONTENU DIFFÉRENT'));

    // =================================================================
    //  4. LA DESTRUCTION, PUIS LE RETOUR
    // =================================================================

    echo "\n  La destruction, puis le retour\n";

    // On détruit pour de bon : quelques tables effacées, des données
    // écrasées, et le fichier déposé supprimé.
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    $db->exec('DROP TABLE IF EXISTS grades');
    $db->exec('DROP TABLE IF EXISTS enrollments');
    $db->exec('DELETE FROM login_attempts');
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    unlink($photo);

    $restantes = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    )->fetchColumn();

    check('Le décor est réellement abîmé', $restantes < $nbTables,
        $restantes . ' table(s) au lieu de ' . $nbTables);

    // --- Le nom de la base est exigé ---------------------------------
    $refus = lancer($racine, 'restore.php', [$archive], "n'importe quoi\n");

    check('Sans le nom de la base, la restauration REFUSE',
        $refus['code'] !== 0 && str_contains($refus['sortie'], 'Confirmation incorrecte'));

    check('… et elle n\'a rien écrit',
        (int) $db->query("SELECT COUNT(*) FROM information_schema.tables
                           WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")
            ->fetchColumn() === $restantes);

    // --- La vraie restauration ---------------------------------------
    $retour = lancer($racine, 'restore.php', [$archive], $base . "\n");

    check('La restauration se termine et se déclare VÉRIFIÉE',
        $retour['code'] === 0 && str_contains($retour['sortie'], 'VÉRIFIÉE'),
        $retour['code'] !== 0 ? 'code ' . $retour['code'] : '');

    check('Les 61 tables sont conformes',
        (bool) preg_match('/(\d+) table\(s\) conforme\(s\) sur \1/', $retour['sortie']));

    // --- Ce que la restauration a rendu ------------------------------
    $db = decor_base($base);

    check('Les tables détruites sont revenues',
        (int) $db->query("SELECT COUNT(*) FROM information_schema.tables
                           WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")
            ->fetchColumn() === $nbTables);

    check('La valeur modifiée est revenue à l\'originale',
        $db->query("SELECT email FROM users WHERE username = 'recette.sauv'")->fetchColumn()
            === 'recette@exemple.cd');

    // LE PIÈGE : l'adresse doit revenir OCTET POUR OCTET.
    $ipRendue = $db->query("SELECT ip_address FROM login_attempts WHERE id = {$idIp}")->fetchColumn();

    check('L\'adresse IPv6 binaire revient octet pour octet',
        $ipRendue === $ipBrute,
        $ipRendue === false ? 'ligne absente' : 'inet_ntop : ' . (string) @inet_ntop((string) $ipRendue));

    check('… et elle se relit comme l\'adresse d\'origine',
        $ipRendue !== false && @inet_ntop((string) $ipRendue) === '2001:db8::dead:beef');

    // --- Les textes pièges, après l'aller-retour ---------------------
    $rendus = 0;
    $perdus = [];

    foreach ($idsTexte as $nom => $id) {
        $rendu = $db->query("SELECT user_agent FROM login_attempts WHERE id = {$id}")->fetchColumn();

        $rendu === $textes[$nom] ? $rendus++ : $perdus[] = $nom;
    }

    check('Les neuf textes pièges reviennent à l\'identique', $perdus === [],
        $perdus !== [] ? 'perdus : ' . implode(', ', $perdus) : $rendus . '/9');

    // NULL ne doit pas devenir une chaîne vide, ni l'inverse.
    check('NULL reste NULL',
        $db->query("SELECT user_agent FROM login_attempts WHERE id = {$idNull}")->fetchColumn() === null);

    check('… et la chaîne vide reste une chaîne vide',
        $db->query("SELECT user_agent FROM login_attempts WHERE id = {$idsTexte['chaine-vide']}")
            ->fetchColumn() === '');

    // Le point-virgule et le commentaire ont traversé sql_split() : si
    // le découpage les avait pris pour des séparateurs, la table serait
    // tronquée ou l'instruction coupée en deux.
    check('Le découpage SQL n\'a pas été trompé par « ; » ni par « -- »',
        (int) $db->query("SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE 'texte.%'")
            ->fetchColumn() === 10);

    check('Le fichier déposé est revenu', is_file($photo));
    check('… identique à l\'octet près', is_file($photo) && md5_file($photo) === $empreintePhoto);

    // La colonne générée doit exister et se recalculer.
    $generee = $db->query(
        "SELECT generation_expression FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'subscription_payments'
            AND column_name = 'reference_live'"
    )->fetchColumn();

    check('La colonne générée est revenue comme générée',
        is_string($generee) && $generee !== '');

    // =================================================================
    //  5. UNE ARCHIVE PLUS RÉCENTE QUE LE CODE
    // =================================================================
    //
    // Restaurer un schéma d'après-demain sous un code d'hier ne casse
    // pas tout de suite : ça casse à la première requête qui cherche une
    // colonne absente, des jours plus tard, sur des données modifiées.

    echo "\n  Une archive venue d'une version plus récente\n";

    $futur = $racine . '/storage/backups/futur.zip';
    copy($archive, $futur);

    $zip = new ZipArchive();
    $zip->open($futur);
    $m = json_decode((string) $zip->getFromName('manifeste.json'), true);
    $m['migrations']['2099_01_01_999_venue_du_futur.sql'] = str_repeat('a', 64);
    $zip->addFromString('manifeste.json', json_encode($m));
    $zip->close();

    $refusFutur = lancer($racine, 'restore.php', [$futur], $base . "\n");

    check('Une archive plus récente que le code est REFUSÉE',
        $refusFutur['code'] !== 0);
    check('… et le refus NOMME la migration inconnue',
        str_contains($refusFutur['erreur'] . $refusFutur['sortie'], '2099_01_01_999_venue_du_futur.sql'));
    check('… avant toute demande de confirmation',
        !str_contains($refusFutur['sortie'], 'VA ÊTRE ÉCRASÉE'));

    // =================================================================
    //  6. LA LISTE N'EFFACE JAMAIS
    // =================================================================

    echo "\n  La liste des sauvegardes\n";

    $liste = lancer($racine, 'backup.php', ['--lister']);

    check('Elle énumère les archives présentes',
        str_contains($liste['sortie'], basename($archive)));
    check('Elle dit qu\'elle n\'efface rien',
        str_contains($liste['sortie'], 'n\'efface jamais'));
    check('… et rien n\'a effectivement disparu',
        count(glob($racine . '/storage/backups/*.zip') ?: []) === 2);
} finally {
    try {
        serveur()->exec("DROP DATABASE IF EXISTS `{$base}`");
    } catch (Throwable) {
        // La base n'a peut-être jamais existé : ce n'est pas une erreur.
    }

    effacer($racine);
}

check('Le décor jetable est nettoyé', !is_dir($racine));

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
