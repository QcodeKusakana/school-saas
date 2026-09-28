<?php
/**
 * RESTAURATION — à exécuter EN LIGNE DE COMMANDE uniquement.
 *
 *   php database/restore.php storage/backups/school-saas-....zip
 *   php database/restore.php storage/backups/school-saas-....sql
 *   php database/restore.php <archive> --verifier   (ne restaure rien)
 *
 * POURQUOI CE SCRIPT EXISTE
 * =========================
 * Une sauvegarde qu'on n'a jamais restaurée n'est pas une protection,
 * c'est une croyance. Tant qu'aucun chemin de retour n'est écrit ET
 * éprouvé, personne ne sait si les fichiers accumulés dans
 * `storage/backups/` valent quelque chose.
 *
 *   > Une sauvegarde jamais restaurée est une croyance, pas une
 *   > protection.
 *
 * CE QUI DISTINGUE CE SCRIPT D'UN « SOURCE dump.sql »
 * ---------------------------------------------------
 *  1. IL REFUSE UNE ARCHIVE PLUS RÉCENTE QUE LE CODE. Restaurer un
 *     schéma d'après-demain sous un code d'hier ne casse pas tout de
 *     suite : ça casse à la première requête qui cherche une colonne
 *     que le code ignore, des jours plus tard, sur une donnée déjà
 *     modifiée.
 *  2. IL DEMANDE LE NOM DE LA BASE. La restauration ÉCRASE tout. On ne
 *     tape pas ce nom par accident.
 *  3. IL VÉRIFIE APRÈS COUP, table par table, contre les empreintes du
 *     manifeste — avec la MÊME fonction qui les a produites. Une
 *     restauration qu'on ne vérifie pas est un espoir.
 *
 * `--verifier` fait la troisième étape SEULE, sans rien écrire : c'est
 * ce qui permet de contrôler une sauvegarde sans attendre le sinistre.
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

// `getopt()` NE CONVIENT PAS ICI.
//
// Mesuré : `php database/restore.php <archive> --verifier` ignorait le
// drapeau. `getopt()` suit la convention POSIX et cesse d'analyser au
// PREMIER argument positionnel — or l'archive en est un, et elle vient
// forcément avant. Le script s'apprêtait donc à écraser la base alors
// qu'on lui demandait de ne rien écrire.
//
//   > Une option qu'on place après l'argument obligatoire n'est pas une
//   > option exotique : c'est l'ordre naturel.
//
// On lit donc `$argv` directement, où la position n'a aucune importance.
$arguments = [];
$drapeaux  = [];

foreach (array_slice($argv, 1) as $argument) {
    str_starts_with($argument, '--')
        ? $drapeaux[ltrim($argument, '-')] = true
        : $arguments[] = $argument;
}

$options = $drapeaux;

if (isset($options['help']) || $arguments === []) {
    echo <<<TXT

    Restauration School SaaS RDC
    ----------------------------
      php database/restore.php <archive.zip|.sql>
      php database/restore.php <archive> --verifier   Contrôle seul, sans écrire

    La restauration ÉCRASE la base en place. Elle demande confirmation.
    Listez les sauvegardes avec : php database/backup.php --lister

    TXT;
    exit($arguments === [] ? 1 : 0);
}

$archive = $arguments[0];

if (!is_file($archive)) {
    $essai = BASE_PATH . '/' . ltrim($archive, '/\\');
    $archive = is_file($essai) ? $essai : $archive;
}

if (!is_file($archive)) {
    fwrite(STDERR, "\n  ✗ Archive introuvable : {$arguments[0]}\n\n");
    exit(1);
}

$dbName = (string) config('database.name');

echo "\n";
echo "  School SaaS RDC — restauration\n";
echo "  ──────────────────────────────────────────────\n";
echo "  Archive : " . basename($archive) . "\n";
echo "  Base    : {$dbName}\n\n";

// ---------------------------------------------------------------------
//  Ouvrir l'archive
// ---------------------------------------------------------------------
$contenu = restore_ouvrir($archive);

if ($contenu['manifeste'] === null) {
    echo "  ! Aucun manifeste : l'archive sera restaurée, mais RIEN ne pourra\n";
    echo "    être vérifié ensuite. Les sauvegardes produites par backup.php\n";
    echo "    en portent toujours un.\n\n";
}

$manifeste = $contenu['manifeste'];

if ($manifeste !== null) {
    printf("  Prise le %s · %d table(s) · %d migration(s)\n\n",
        date('d/m/Y H:i', strtotime((string) $manifeste['date'])),
        count($manifeste['tables'] ?? []),
        count($manifeste['migrations'] ?? []));
}

try {
    $pdo = backup_connexion();
} catch (PDOException $e) {
    fwrite(STDERR, "  ✗ Connexion impossible : {$e->getMessage()}\n\n");
    exit(1);
}

// ---------------------------------------------------------------------
//  --verifier : la troisième étape SEULE
// ---------------------------------------------------------------------
if (isset($options['verifier'])) {
    if ($manifeste === null) {
        fwrite(STDERR, "  ✗ Sans manifeste, il n'y a rien à vérifier.\n\n");
        exit(1);
    }

    echo "  Vérification SANS restauration : on compare la base EN PLACE\n";
    echo "  aux empreintes de l'archive.\n\n";

    exit(restore_verifier($pdo, $dbName, $manifeste) ? 0 : 1);
}

// ---------------------------------------------------------------------
//  L'archive est-elle plus récente que le code ?
// ---------------------------------------------------------------------
if ($manifeste !== null) {
    $connues = [];

    foreach (runner_catalog() as $fichier) {
        $connues[$fichier['name']] = true;
    }

    $inconnues = array_values(array_filter(
        array_keys((array) ($manifeste['migrations'] ?? [])),
        static fn (string $nom): bool => !isset($connues[$nom])
    ));

    if ($inconnues !== []) {
        fwrite(STDERR, "  ✗ Cette archive vient d'une version PLUS RÉCENTE du produit.\n\n");
        fwrite(STDERR, "    Migrations qu'elle connaît et que ce code ignore :\n");

        foreach (array_slice($inconnues, 0, 5) as $nom) {
            fwrite(STDERR, "      · {$nom}\n");
        }

        if (count($inconnues) > 5) {
            fwrite(STDERR, "      · … et " . (count($inconnues) - 5) . " autre(s)\n");
        }

        fwrite(STDERR, "\n    La restaurer casserait à la première requête cherchant une\n");
        fwrite(STDERR, "    colonne que ce code ignore — pas maintenant, plus tard, sur\n");
        fwrite(STDERR, "    des données déjà modifiées. Mettez le code à jour d'abord.\n\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------
//  Confirmation
// ---------------------------------------------------------------------
echo "  ⚠  LA BASE « {$dbName} » VA ÊTRE ÉCRASÉE.\n";
echo "     Toutes les données actuelles seront remplacées par celles de\n";
echo "     l'archive. Cette opération ne s'annule pas.\n\n";
echo "     Confirmez en tapant le nom de la base : ";

$confirmation = trim((string) fgets(STDIN));

if ($confirmation !== $dbName) {
    echo "\n  ✗ Confirmation incorrecte. Aucune modification effectuée.\n\n";
    exit(1);
}

// ---------------------------------------------------------------------
//  Restaurer
// ---------------------------------------------------------------------
echo "\n";

$instructions = sql_split((string) file_get_contents($contenu['sql']));
$executees    = 0;
$courante     = '';

try {
    foreach ($instructions as $instruction) {
        $courante = $instruction;
        $pdo->exec($instruction);
        $executees++;
    }
} catch (PDOException $e) {
    // ON NE RÉTABLIT PAS LES CLÉS ÉTRANGÈRES EN CATIMINI.
    // À ce point la base est à moitié restaurée : le dire vaut mieux
    // que laisser croire à un échec propre.
    fwrite(STDERR, "  ✗ Échec à l'instruction {$executees} :\n");
    fwrite(STDERR, "    {$e->getMessage()}\n");
    fwrite(STDERR, "    " . substr((string) preg_replace('/\s+/', ' ', $courante), 0, 160) . "…\n\n");
    fwrite(STDERR, "    LA BASE EST DANS UN ÉTAT INTERMÉDIAIRE. Relancez la\n");
    fwrite(STDERR, "    restauration, ou repartez d'une autre archive.\n\n");
    exit(1);
}

printf("  ✓ %d instruction(s) exécutée(s)\n", $executees);

// --- Les fichiers déposés --------------------------------------------
if ($contenu['uploads'] !== null) {
    $restaures = restore_uploads($contenu['uploads'], BASE_PATH . '/storage/uploads');
    printf("  ✓ %d fichier(s) déposé(s) restauré(s)\n", $restaures);
} elseif ($manifeste !== null && (int) ($manifeste['fichiers_deposes'] ?? 0) > 0) {
    echo "  ! L'archive annonçait " . (int) $manifeste['fichiers_deposes']
        . " fichier(s) déposé(s), aucun n'a été trouvé.\n";
}

restore_nettoyer($contenu);

// ---------------------------------------------------------------------
//  Vérifier — la partie qui distingue une restauration d'un espoir
// ---------------------------------------------------------------------
if ($manifeste === null) {
    echo "\n  Restauration terminée. Sans manifeste, elle n'a PAS pu être vérifiée.\n\n";
    exit(0);
}

echo "\n";
$conforme = restore_verifier($pdo, $dbName, $manifeste);

echo $conforme
    ? "\n  Restauration terminée et VÉRIFIÉE.\n\n"
    : "\n  Restauration terminée mais NON CONFORME — voir ci-dessus.\n\n";

exit($conforme ? 0 : 1);

// =====================================================================
//  FONCTIONS
// =====================================================================

/**
 * Ouvre l'archive et rend les chemins de son contenu.
 *
 * @return array{sql: string, manifeste: ?array<string, mixed>, uploads: ?string, temporaire: ?string}
 */
function restore_ouvrir(string $archive): array
{
    // Un .sql nu : son manifeste, s'il existe, est le fichier frère.
    if (!str_ends_with(strtolower($archive), '.zip')) {
        $frere = preg_replace('/\.sql$/i', '.manifeste.json', $archive);
        $manif = ($frere !== null && is_file($frere))
            ? json_decode((string) file_get_contents($frere), true)
            : null;

        return [
            'sql'        => $archive,
            'manifeste'  => is_array($manif) ? $manif : null,
            'uploads'    => null,
            'temporaire' => null,
        ];
    }

    if (!extension_loaded('zip')) {
        fwrite(STDERR, "  ✗ L'extension zip est absente : cette archive ne peut pas être ouverte.\n");
        fwrite(STDERR, "    Décompressez-la à la main et restaurez le base.sql qu'elle contient.\n\n");
        exit(1);
    }

    $zip = new ZipArchive();

    if ($zip->open($archive) !== true) {
        fwrite(STDERR, "  ✗ Archive illisible : {$archive}\n\n");
        exit(1);
    }

    $temporaire = sys_get_temp_dir() . '/school-saas-restore-' . bin2hex(random_bytes(6));

    if (!mkdir($temporaire, 0o700, true)) {
        fwrite(STDERR, "  ✗ Dossier temporaire impossible à créer.\n\n");
        exit(1);
    }

    $zip->extractTo($temporaire);
    $zip->close();

    $sql = $temporaire . '/base.sql';

    if (!is_file($sql)) {
        fwrite(STDERR, "  ✗ L'archive ne contient pas de base.sql.\n\n");
        restore_effacer($temporaire);
        exit(1);
    }

    $manif = is_file($temporaire . '/manifeste.json')
        ? json_decode((string) file_get_contents($temporaire . '/manifeste.json'), true)
        : null;

    return [
        'sql'        => $sql,
        'manifeste'  => is_array($manif) ? $manif : null,
        'uploads'    => is_dir($temporaire . '/uploads') ? $temporaire . '/uploads' : null,
        'temporaire' => $temporaire,
    ];
}

/** @param array{temporaire: ?string} $contenu */
function restore_nettoyer(array $contenu): void
{
    if ($contenu['temporaire'] !== null) {
        restore_effacer($contenu['temporaire']);
    }
}

/** Efface un dossier et son contenu, sans dépendre du système. */
function restore_effacer(string $chemin): void
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

/**
 * Recopie les fichiers déposés. N'EFFACE RIEN de ce qui est en place :
 * le projet interdit de supprimer des données sans avertissement, et un
 * fichier présent mais absent de l'archive peut être plus récent.
 */
function restore_uploads(string $depuis, string $vers): int
{
    if (!is_dir($vers) && !mkdir($vers, 0o775, true)) {
        return 0;
    }

    $n = 0;

    $entrees = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($depuis, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($entrees as $entree) {
        if (!$entree->isFile()) {
            continue;
        }

        $relatif = substr($entree->getPathname(), strlen($depuis) + 1);
        $cible   = $vers . '/' . $relatif;
        $dossier = dirname($cible);

        if (!is_dir($dossier)) {
            mkdir($dossier, 0o775, true);
        }

        if (copy($entree->getPathname(), $cible)) {
            $n++;
        }
    }

    return $n;
}

/**
 * Compare la base en place aux empreintes du manifeste.
 *
 * Elle emploie `backup_empreinte_table()` — LA MÊME fonction qui a
 * produit les empreintes. Une vérification qui recalculerait à sa façon
 * finirait par comparer deux choses différentes.
 *
 * @param array<string, mixed> $manifeste
 */
function restore_verifier(PDO $pdo, string $dbName, array $manifeste): bool
{
    /** @var array<string, array{lignes: int, empreinte: string}> $attendu */
    $attendu = (array) ($manifeste['tables'] ?? []);
    $presentes = array_flip(backup_tables($pdo, $dbName));

    $conformes = 0;
    $ecarts    = [];

    foreach ($attendu as $table => $reference) {
        if (!isset($presentes[$table])) {
            $ecarts[] = "  ✗ {$table} — TABLE ABSENTE";
            continue;
        }

        $mesure = backup_empreinte_table($pdo, $dbName, (string) $table);

        if ($mesure['empreinte'] === ($reference['empreinte'] ?? null)) {
            $conformes++;
            continue;
        }

        $ecarts[] = sprintf('  ✗ %s — %d ligne(s) attendue(s), %d trouvée(s)%s',
            $table,
            (int) ($reference['lignes'] ?? 0),
            $mesure['lignes'],
            $mesure['lignes'] === (int) ($reference['lignes'] ?? 0)
                ? ' — même compte, CONTENU DIFFÉRENT'
                : '');
    }

    // Une table présente et absente du manifeste compte aussi : elle
    // vient d'ailleurs, et la base n'est pas celle de l'archive.
    $enTrop = array_values(array_diff(array_keys($presentes), array_keys($attendu)));

    printf("  %d table(s) conforme(s) sur %d\n", $conformes, count($attendu));

    foreach ($ecarts as $ligne) {
        echo $ligne . "\n";
    }

    if ($enTrop !== []) {
        echo "  ! " . count($enTrop) . " table(s) présente(s) hors de l'archive : "
            . implode(', ', array_slice($enTrop, 0, 4))
            . (count($enTrop) > 4 ? '…' : '') . "\n";
    }

    return $ecarts === [] && $enTrop === [];
}
