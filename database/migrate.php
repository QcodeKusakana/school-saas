<?php
/**
 * Applicateur de migrations — ligne de commande uniquement.
 *
 *   php database/migrate.php            Applique les migrations en attente
 *   php database/migrate.php --status   Liste sans rien exécuter
 *   php database/migrate.php --seed     Applique aussi les seeds non joués
 *
 * Chaque fichier de database/migrations/ n'est exécuté qu'une seule
 * fois : la table schema_migrations garde la trace de ce qui a été
 * appliqué, avec la date et une empreinte du contenu.
 *
 * L'empreinte sert d'alerte : si un fichier déjà appliqué est modifié
 * après coup, le script le signale. En équipe, modifier une migration
 * déjà jouée chez un collègue produit deux bases divergentes — mieux
 * vaut écrire une nouvelle migration.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script ne s'exécute qu'en ligne de commande.\n");
}

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

require APP_PATH . '/core/helpers.php';
require APP_PATH . '/core/logger.php';

$options    = getopt('', ['status', 'seed', 'baseline', 'help']);
$statusOnly = isset($options['status']);
$withSeeds  = isset($options['seed']);
$baseline   = isset($options['baseline']);

if (isset($options['help'])) {
    echo <<<TXT

    Migrations School SaaS RDC
    --------------------------
      php database/migrate.php            Applique les migrations en attente
      php database/migrate.php --status   Affiche l'état sans rien exécuter
      php database/migrate.php --seed     Applique aussi les seeds non joués
      php database/migrate.php --baseline Marque les fichiers comme appliqués
                                          SANS les exécuter

    --baseline sert lorsqu'une base a été construite avant la mise en
    place de cet outil : les tables existent déjà, il faut seulement
    enregistrer les fichiers correspondants pour que les migrations
    suivantes s'appliquent normalement.

    TXT;
    exit(0);
}

// ---------------------------------------------------------------------
// Connexion
// ---------------------------------------------------------------------
try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) config('database.host'),
            (int) config('database.port', 3306),
            (string) config('database.name')
        ),
        (string) config('database.user'),
        (string) config('database.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "\n  ✗ Connexion impossible : {$e->getMessage()}\n\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Registre des migrations
// ---------------------------------------------------------------------
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        filename    VARCHAR(190) NOT NULL,
        checksum    CHAR(64)     NOT NULL,
        kind        ENUM(\'migration\', \'seed\') NOT NULL DEFAULT \'migration\',
        statements  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_migration_file (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = [];

foreach ($pdo->query('SELECT filename, checksum, applied_at FROM schema_migrations') as $row) {
    $applied[$row['filename']] = $row;
}

// ---------------------------------------------------------------------
// Fichiers candidats
// ---------------------------------------------------------------------
$files = [];

foreach (glob(__DIR__ . '/migrations/*.sql') ?: [] as $path) {
    $files[] = ['path' => $path, 'name' => basename($path), 'kind' => 'migration'];
}

if ($withSeeds) {
    foreach (glob(__DIR__ . '/seeds/*.sql') ?: [] as $path) {
        $files[] = ['path' => $path, 'name' => 'seeds/' . basename($path), 'kind' => 'seed'];
    }
}

usort($files, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

echo "\n  Migrations — base « " . config('database.name') . " »\n";
echo "  ──────────────────────────────────────────────────────\n";

if ($files === []) {
    echo "  Aucun fichier trouvé.\n\n";
    exit(0);
}

$pending  = [];
$modified = [];

foreach ($files as $file) {
    $checksum = hash_file('sha256', $file['path']);
    $isKnown  = isset($applied[$file['name']]);

    if (!$isKnown) {
        $pending[] = $file + ['checksum' => $checksum];
        printf("  ○ %-52s en attente\n", $file['name']);
        continue;
    }

    if ($applied[$file['name']]['checksum'] !== $checksum) {
        $modified[] = $file['name'];
        printf("  ⚠ %-52s MODIFIÉ depuis son application\n", $file['name']);
        continue;
    }

    printf("  ✓ %-52s %s\n", $file['name'], substr((string) $applied[$file['name']]['applied_at'], 0, 16));
}

if ($modified !== []) {
    echo "\n  ⚠  Fichiers modifiés après application :\n";

    foreach ($modified as $name) {
        echo "     · {$name}\n";
    }

    echo "     Ils ne seront PAS rejoués. Pour propager le changement,\n";
    echo "     écrivez une nouvelle migration plutôt que d'éditer celle-ci.\n";
}

if ($statusOnly) {
    echo "\n  " . count($pending) . " migration(s) en attente.\n\n";
    exit(0);
}

if ($pending === []) {
    echo "\n  Base à jour, rien à appliquer.\n\n";
    exit(0);
}

// ---------------------------------------------------------------------
// Mode baseline : enregistrer sans exécuter
// ---------------------------------------------------------------------
if ($baseline) {
    echo "\n  ⚠  Mode --baseline : les fichiers vont être marqués comme appliqués\n";
    echo "     SANS être exécutés. À n'utiliser que si les tables existent déjà.\n";
    echo "     Confirmez en tapant « baseline » : ";

    if (trim((string) fgets(STDIN)) !== 'baseline') {
        echo "  ✗ Annulé.\n\n";
        exit(1);
    }

    $statement = $pdo->prepare(
        'INSERT INTO schema_migrations (filename, checksum, kind, statements)
         VALUES (:filename, :checksum, :kind, 0)'
    );

    foreach ($pending as $file) {
        $statement->execute([
            'filename' => $file['name'],
            'checksum' => $file['checksum'],
            'kind'     => $file['kind'],
        ]);
        printf("  ✓ %-52s marqué appliqué\n", $file['name']);
    }

    echo "\n  " . count($pending) . " fichier(s) enregistré(s) sans exécution.\n\n";
    exit(0);
}

// ---------------------------------------------------------------------
// Application
// ---------------------------------------------------------------------
echo "\n  Application de " . count($pending) . " fichier(s)…\n\n";

foreach ($pending as $file) {
    $statements = sql_split((string) file_get_contents($file['path']));
    $executed   = 0;

    // Chaque fichier est appliqué dans sa propre transaction lorsque
    // c'est possible. MySQL valide implicitement les CREATE TABLE et
    // ALTER TABLE : la transaction ne protège donc pas un fichier de
    // schéma, d'où l'arrêt immédiat à la première erreur, pour ne pas
    // enchaîner sur une base à moitié migrée.
    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
            $executed++;
        }
    } catch (PDOException $e) {
        fwrite(STDERR, "  ✗ {$file['name']} — instruction " . ($executed + 1) . "\n");
        fwrite(STDERR, "    {$e->getMessage()}\n");
        fwrite(STDERR, "    " . substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 160) . "…\n\n");
        fwrite(STDERR, "  Migration interrompue. Corrigez le fichier puis relancez.\n\n");
        exit(1);
    }

    $pdo->prepare(
        'INSERT INTO schema_migrations (filename, checksum, kind, statements)
         VALUES (:filename, :checksum, :kind, :statements)'
    )->execute([
        'filename'   => $file['name'],
        'checksum'   => $file['checksum'],
        'kind'       => $file['kind'],
        'statements' => $executed,
    ]);

    printf("  ✓ %-52s %3d instructions\n", $file['name'], $executed);
}

echo "\n  Terminé.\n\n";
exit(0);

// =====================================================================

/**
 * Découpe un fichier SQL en instructions, en respectant les chaînes
 * littérales et les commentaires.
 *
 * @return string[]
 */
function sql_split(string $sql): array
{
    $statements = [];
    $current    = '';
    $inString   = false;
    $quoteChar  = '';
    $length     = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if (!$inString && (($char === '-' && $next === '-') || $char === '#')) {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            $current .= "\n";
            continue;
        }

        if (!$inString && $char === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i);
            $i   = $end === false ? $length : $end + 1;
            continue;
        }

        if (($char === "'" || $char === '"') && ($i === 0 || $sql[$i - 1] !== '\\')) {
            if (!$inString) {
                $inString  = true;
                $quoteChar = $char;
            } elseif ($char === $quoteChar) {
                if ($next === $quoteChar) {
                    $current .= $char . $next;
                    $i++;
                    continue;
                }
                $inString = false;
            }
        }

        if ($char === ';' && !$inString) {
            $statement = trim($current);

            if ($statement !== '') {
                $statements[] = $statement;
            }

            $current = '';
            continue;
        }

        $current .= $char;
    }

    $statement = trim($current);

    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}
