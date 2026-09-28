<?php
/**
 * SAUVEGARDE — à exécuter EN LIGNE DE COMMANDE uniquement.
 *
 *   php database/backup.php                Archive complète (base + fichiers)
 *   php database/backup.php --sql          Le SQL seul, sans archive
 *   php database/backup.php --lister       Les sauvegardes présentes
 *
 * POURQUOI EN PHP, ET PAS AVEC mysqldump
 * ======================================
 * Deux raisons, dans l'ordre du principe de décision du projet.
 *
 * SÉCURITÉ. `mysqldump -u x -pMOTDEPASSE` affiche le mot de passe de la
 * base dans la liste des processus, lisible par tout compte du serveur —
 * et sur un hébergement mutualisé, ces comptes ne sont pas les vôtres.
 * Le contourner demande un fichier d'options temporaire, c'est-à-dire un
 * secret écrit sur le disque le temps de la sauvegarde.
 *
 * FIABILITÉ. `mysqldump` n'est pas garanti sur un hébergement mutualisé ;
 * PDO l'est, puisque le produit ne tourne pas sans. Prévoir les deux
 * donnerait deux chemins de code, dont un seul serait éprouvé — et ce
 * serait toujours l'autre le jour de l'incident.
 *
 *   > Une sauvegarde qui emprunte un chemin qu'on n'éprouve jamais est
 *   > une sauvegarde qu'on découvre le jour où elle doit servir.
 *
 * LES TROIS PIÈGES DE CE SCHÉMA, TRAITÉS ICI
 * ------------------------------------------
 *  1. CINQ COLONNES `varbinary` portent des adresses IP compactées
 *     (`inet_pton`). Écrites comme du texte, elles reviendraient
 *     corrompues sans que rien ne le signale. Elles sortent en
 *     littéraux hexadécimaux.
 *  2. UNE COLONNE GÉNÉRÉE — `subscription_payments.reference_live` —
 *     ne s'insère pas : MySQL refuse. Elle est exclue de la liste des
 *     colonnes et se recalcule à la restauration.
 *  3. LES CLÉS ÉTRANGÈRES interdisent tout ordre d'insertion naïf. Le
 *     fichier les suspend le temps de la restauration et les rétablit
 *     à la fin — jamais au-delà.
 *
 * CE QUE L'ARCHIVE CONTIENT
 * -------------------------
 *   base.sql        le schéma et toutes les données
 *   manifeste.json  date, migrations appliquées, empreinte par table
 *   uploads/        photos, logos et documents déposés
 *
 * Le manifeste n'est pas décoratif : `restore.php` s'en sert pour
 * refuser une archive plus récente que le code, et la recette s'en sert
 * pour prouver que la restauration rend EXACTEMENT ce qui a été pris.
 *
 * AVERTISSEMENT. Une sauvegarde contient l'intégralité des données
 * personnelles des élèves. Elle se range comme telle : hors de la racine
 * web, et hors d'un espace partagé.
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
require __DIR__ . '/dump.php';

$options = getopt('', ['sql', 'lister', 'help']);

if (isset($options['help'])) {
    echo <<<TXT

    Sauvegarde School SaaS RDC
    --------------------------
      php database/backup.php            Archive complète (base + fichiers)
      php database/backup.php --sql      Le SQL seul, sans archive
      php database/backup.php --lister   Les sauvegardes présentes

    Les sauvegardes vont dans storage/backups/. Elles contiennent des
    données personnelles : ne les déposez pas dans un espace partagé.

    TXT;
    exit(0);
}

$dossier = BASE_PATH . '/storage/backups';

// ---------------------------------------------------------------------
//  --lister
// ---------------------------------------------------------------------
if (isset($options['lister'])) {
    $fichiers = glob($dossier . '/*.{zip,sql}', GLOB_BRACE) ?: [];
    rsort($fichiers, SORT_STRING);

    echo "\n  Sauvegardes présentes dans storage/backups/\n";
    echo "  ──────────────────────────────────────────────\n";

    if ($fichiers === []) {
        echo "  (aucune)\n\n";
        exit(0);
    }

    foreach ($fichiers as $f) {
        printf("  %-46s %8s   %s\n",
            basename($f),
            backup_taille_lisible((int) filesize($f)),
            date('d/m/Y H:i', (int) filemtime($f)));
    }

    // AUCUNE SUPPRESSION AUTOMATIQUE.
    // Le projet interdit d'effacer des données sans avertissement
    // explicite, et une sauvegarde EST une donnée. C'est à l'exploitant
    // de décider ce qu'il garde.
    echo "\n  Ce script n'efface jamais une sauvegarde : c'est à vous de décider.\n\n";
    exit(0);
}

// ---------------------------------------------------------------------
//  La sauvegarde
// ---------------------------------------------------------------------
$dbName = (string) config('database.name');

echo "\n";
echo "  School SaaS RDC — sauvegarde\n";
echo "  ──────────────────────────────────────────────\n";
echo "  Base : {$dbName}\n\n";

try {
    $pdo = backup_connexion();
} catch (PDOException $e) {
    fwrite(STDERR, "  ✗ Connexion impossible : {$e->getMessage()}\n\n");
    exit(1);
}

if (!is_dir($dossier) && !mkdir($dossier, 0o775, true)) {
    fwrite(STDERR, "  ✗ storage/backups/ n'existe pas et ne peut pas être créé.\n\n");
    exit(1);
}

$horodatage = date('Ymd-His');
$cheminSql  = $dossier . '/school-saas-' . $horodatage . '.sql';

$resultat = backup_ecrire_sql($pdo, $dbName, $cheminSql);

printf("  ✓ %d table(s), %d ligne(s) — %s\n",
    count($resultat['tables']),
    array_sum(array_column($resultat['tables'], 'lignes')),
    backup_taille_lisible((int) filesize($cheminSql)));

$manifeste = [
    'produit'     => 'school-saas-rdc',
    'format'      => 1,
    'date'        => date('c'),
    'base'        => $dbName,
    'php'         => PHP_VERSION,
    'mysql'       => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'migrations'  => backup_migrations($pdo),
    'tables'      => $resultat['tables'],
];

// --- Le SQL seul ------------------------------------------------------
if (isset($options['sql'])) {
    file_put_contents(
        $dossier . '/school-saas-' . $horodatage . '.manifeste.json',
        json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    echo "  ✓ " . basename($cheminSql) . "\n";
    echo "\n  Sauvegarde terminée (SQL seul — les fichiers déposés n'y sont PAS).\n\n";
    exit(0);
}

// --- L'archive --------------------------------------------------------
if (!extension_loaded('zip')) {
    echo "\n  ! L'extension zip est absente : seul le SQL a été écrit.\n";
    echo "    " . basename($cheminSql) . "\n";
    echo "    Les fichiers déposés (photos, logos, documents) ne sont PAS dedans.\n";
    echo "    Relancez avec --sql pour ne plus voir cet avertissement, et\n";
    echo "    sauvegardez storage/uploads/ par un autre moyen.\n\n";
    exit(0);
}

$cheminZip = $dossier . '/school-saas-' . $horodatage . '.zip';
$zip       = new ZipArchive();

if ($zip->open($cheminZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "  ✗ Archive impossible à créer : {$cheminZip}\n\n");
    exit(1);
}

$zip->addFile($cheminSql, 'base.sql');

$uploads = BASE_PATH . '/storage/uploads';
$nbFichiers = 0;

if (is_dir($uploads)) {
    $entrees = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($entrees as $entree) {
        if (!$entree->isFile()) {
            continue;
        }

        $relatif = str_replace('\\', '/', substr($entree->getPathname(), strlen($uploads) + 1));
        $zip->addFile($entree->getPathname(), 'uploads/' . $relatif);
        $nbFichiers++;
    }
}

$manifeste['fichiers_deposes'] = $nbFichiers;

$zip->addFromString(
    'manifeste.json',
    json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$zip->close();
unlink($cheminSql);

printf("  ✓ %d fichier(s) déposé(s)\n", $nbFichiers);
echo "  ✓ " . basename($cheminZip) . " — " . backup_taille_lisible((int) filesize($cheminZip)) . "\n";
echo "\n  Sauvegarde terminée.\n";
echo "  Elle contient les données personnelles de tous les élèves :\n";
echo "  rangez-la hors de la racine web et hors d'un espace partagé.\n\n";

// =====================================================================
//  FONCTIONS
// =====================================================================

/**
 * Écrit le fichier SQL complet, table par table, en flux.
 *
 * En flux, et pas en mémoire : une école de dix ans de données ne doit
 * pas dépendre de `memory_limit`.
 *
 * @return array{tables: array<string, array{lignes: int, empreinte: string}>}
 */
function backup_ecrire_sql(PDO $pdo, string $dbName, string $chemin): array
{
    $sortie = fopen($chemin, 'wb');

    if ($sortie === false) {
        fwrite(STDERR, "  ✗ Écriture impossible : {$chemin}\n\n");
        exit(1);
    }

    fwrite($sortie, "-- School SaaS RDC — sauvegarde du " . date('d/m/Y H:i:s') . "\n");
    fwrite($sortie, "-- Base : {$dbName}\n");
    fwrite($sortie, "-- Restauration : php database/restore.php <archive>\n\n");
    fwrite($sortie, "SET NAMES utf8mb4;\n");
    fwrite($sortie, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
    // Les clés étrangères interdisent tout ordre d'insertion naïf : une
    // inscription précède son élève dans l'ordre alphabétique. On les
    // suspend le temps du fichier, et on les rétablit à la dernière
    // ligne — jamais au-delà.
    fwrite($sortie, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    // UN SEUL INSTANT POUR LES 61 TABLES, ET POUR LEURS EMPREINTES.
    //
    // L'instantané couvre aussi le calcul des empreintes : s'il ne
    // couvrait que l'écriture du SQL, le manifeste décrirait un autre
    // moment que le fichier qu'il accompagne, et la vérification
    // dénoncerait un écart qui n'existe pas — ou pire, en validerait un
    // qui existe.
    backup_ouvrir_instantane($pdo);

    $tables = [];

    foreach (backup_tables($pdo, $dbName) as $table) {
        $binaires = backup_colonnes_binaires($pdo, $dbName, $table);
        $generees = backup_colonnes_generees($pdo, $dbName, $table);

        $create = (string) $pdo->query('SHOW CREATE TABLE `' . $table . '`')
            ->fetch(PDO::FETCH_NUM)[1];

        fwrite($sortie, "-- ---------------------------------------------------------\n");
        fwrite($sortie, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($sortie, $create . ";\n\n");

        backup_ecrire_lignes($pdo, $sortie, $table, $binaires, $generees);

        // L'EMPREINTE VIENT DE LA BIBLIOTHÈQUE, PAS D'ICI.
        // C'est exactement la fonction que `restore.php` rejouera : si
        // chacun calculait la sienne, la vérification finirait par
        // comparer deux choses différentes. Le prix est une seconde
        // lecture de la table ; il est payé volontiers.
        $tables[$table] = backup_empreinte_table($pdo, $dbName, $table);
    }

    backup_fermer_instantane($pdo);

    fwrite($sortie, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($sortie);

    return ['tables' => $tables];
}

/**
 * Écrit les lignes d'une table et rend son empreinte.
 *
 * @param resource $sortie
 * @param array<int, string> $binaires
 * @param array<int, string> $generees
 */
function backup_ecrire_lignes(PDO $pdo, $sortie, string $table, array $binaires, array $generees): void
{
    $cles = backup_cle_primaire($pdo, $table);
    $ordre = $cles !== []
        ? ' ORDER BY ' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cles))
        : '';

    $paquet   = [];
    $colonnes = null;

    $curseur = $pdo->query('SELECT * FROM `' . $table . '`' . $ordre);

    foreach ($curseur as $ligne) {
        // LA COLONNE GÉNÉRÉE NE S'INSÈRE PAS : MySQL refuse. Elle se
        // recalcule à la restauration, c'est tout son intérêt.
        foreach ($generees as $g) {
            unset($ligne[$g]);
        }

        if ($colonnes === null) {
            $colonnes = array_keys($ligne);
        }

        $valeurs = [];

        foreach ($ligne as $nom => $valeur) {
            $valeurs[] = backup_litteral($pdo, $valeur, in_array($nom, $binaires, true));
        }

        $paquet[] = '(' . implode(',', $valeurs) . ')';

        // Par paquets : une instruction par ligne gonflerait le fichier,
        // une seule instruction pour tout dépasserait max_allowed_packet.
        if (count($paquet) >= 200) {
            backup_vider_paquet($sortie, $table, $colonnes, $paquet);
        }
    }

    if ($paquet !== [] && $colonnes !== null) {
        backup_vider_paquet($sortie, $table, $colonnes, $paquet);
    }

    fwrite($sortie, "\n");
}

/**
 * @param resource $sortie
 * @param array<int, string> $colonnes
 * @param array<int, string> $paquet
 */
function backup_vider_paquet($sortie, string $table, array $colonnes, array &$paquet): void
{
    $noms = implode(',', array_map(static fn (string $c): string => '`' . $c . '`', $colonnes));

    fwrite($sortie, "INSERT INTO `{$table}` ({$noms}) VALUES\n"
        . implode(",\n", $paquet) . ";\n");

    $paquet = [];
}
