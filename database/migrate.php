<?php
/**
 * Applicateur de migrations — ligne de commande uniquement.
 *
 *   php database/migrate.php            Applique les migrations en attente
 *   php database/migrate.php --status   Liste sans rien exécuter
 *   php database/migrate.php --seed     Applique aussi les seeds non joués
 *   php database/migrate.php --baseline Marque les fichiers comme appliqués
 *                                       SANS les exécuter
 *
 * Chaque fichier n'est exécuté qu'une seule fois : la table
 * schema_migrations garde la trace de ce qui a été appliqué, avec la
 * date et une empreinte du contenu.
 *
 * L'empreinte sert d'alerte : si un fichier déjà appliqué est modifié
 * après coup, le script le signale. En équipe, modifier une migration
 * déjà jouée chez un collègue produit deux bases divergentes — mieux
 * vaut écrire une nouvelle migration.
 *
 * La logique d'exécution vit dans runner.php, partagée avec install.php.
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
require __DIR__ . '/runner.php';

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

echo "\n  Migrations — base « " . config('database.name') . " »\n";
echo "  ──────────────────────────────────────────────────────\n";

// ---------------------------------------------------------------------
// Registre et reprise des bases antérieures
// ---------------------------------------------------------------------
runner_ledger_ensure($pdo);

$catalog = runner_catalog();
$adopted = runner_autobaseline($pdo, $catalog, runner_applied($pdo));

foreach ($adopted as $name) {
    printf("  ≡ %-52s déjà en place, enregistré\n", $name);
}

if ($adopted !== []) {
    echo "\n";
}

$applied = runner_applied($pdo);

// ---------------------------------------------------------------------
// État des fichiers
// ---------------------------------------------------------------------
$pending  = [];
$modified = [];

foreach ($catalog as $file) {
    if ($file['kind'] === 'seed' && !$withSeeds) {
        continue;
    }

    if (!is_file($file['path'])) {
        continue;
    }

    $checksum = hash_file('sha256', $file['path']);

    if (!isset($applied[$file['name']])) {
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
    echo "\n  " . count($pending) . " fichier(s) en attente.\n\n";
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

    foreach ($pending as $file) {
        runner_record($pdo, $file['name'], $file['checksum'], $file['kind'], 0);
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
    try {
        $executed = runner_apply($pdo, $file);
    } catch (RuntimeException $e) {
        fwrite(STDERR, "  ✗ {$e->getMessage()}\n\n");
        fwrite(STDERR, "  Migration interrompue. Corrigez le fichier puis relancez.\n\n");
        exit(1);
    }

    printf("  ✓ %-52s %3d instructions\n", $file['name'], $executed);
}

echo "\n  Terminé.\n\n";
exit(0);
