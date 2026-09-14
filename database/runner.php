<?php
/**
 * Noyau commun d'application des fichiers SQL.
 *
 * Ce fichier est le SEUL endroit du projet qui exécute un fichier .sql.
 * install.php et migrate.php s'appuient tous les deux dessus.
 *
 * Pourquoi : avant, chacun des deux scripts appliquait des fichiers de
 * son côté, avec sa propre copie de sql_split(), et un seul des deux
 * tenait un registre. Résultat : une base installée par install.php ne
 * contenait pas le référentiel national (seed 003, jamais listé), et
 * migrate.php --seed rejouait les seeds 001 et 002 jusqu'à l'erreur
 * « Duplicate entry ». Un registre unique supprime la classe entière.
 *
 * Trois règles portées ici :
 *
 *  1. ORDRE — schéma, puis seeds, puis migrations. Un seul enchaînement
 *     possible, et il découle des dépendances réelles :
 *
 *       schema.sql   crée les tables de base
 *       seeds/*      remplissent CES tables — référentiel national,
 *                    rôles et permissions du produit
 *       migrations/* font évoluer structure ET données à partir de là
 *
 *     Un seed ne peut donc dépendre que de schema.sql. Tout fichier de
 *     données qui dépend d'une migration est lui-même une migration :
 *     c'est le cas du référentiel national RDC, qui remplit des tables
 *     créées en phase 2 et vit dans migrations/ pour cette raison.
 *
 *     Cette règle n'est pas cosmétique. Rangée dans l'autre sens
 *     (migrations avant seeds), la migration 004 attribuait une
 *     permission à des rôles que le seed 002 n'avait pas encore créés :
 *     elle s'appliquait sans erreur et sans effet.
 *
 *  2. TRANSACTION — un seed ne contient que des INSERT : il est appliqué
 *     dans une transaction, donc tout ou rien. Une migration contient du
 *     DDL, que MySQL valide implicitement : la transaction n'y protège
 *     rien, on s'arrête à la première erreur pour ne pas enchaîner sur
 *     une base à moitié migrée.
 *
 *  3. TÉMOIN — un fichier de base peut avoir été appliqué avant que ce
 *     registre n'existe (installation manuelle par phpMyAdmin, base
 *     antérieure à cet outil). Chaque fichier de base porte donc une
 *     requête témoin qui PROUVE que son contenu est déjà en place. Si le
 *     témoin répond oui, le fichier est enregistré sans être exécuté.
 *     Un témoin ne supprime ni ne modifie jamais rien.
 */

declare(strict_types=1);

/**
 * Catalogue ordonné des fichiers applicables.
 *
 * @return array<int, array{path: string, name: string, kind: string, witness: ?string}>
 */
function runner_catalog(): array
{
    $directory = __DIR__;
    $files     = [];

    // 1. Schéma de base.
    $files[] = [
        'path'    => $directory . '/schema.sql',
        'name'    => 'schema.sql',
        'kind'    => 'migration',
        'witness' => "SELECT COUNT(*) FROM information_schema.tables
                       WHERE table_schema = DATABASE() AND table_name = 'schools'",
    ];

    // 2. Données de base, par ordre de nom de fichier.
    $seeds = glob($directory . '/seeds/*.sql') ?: [];
    sort($seeds, SORT_STRING);

    foreach ($seeds as $path) {
        $files[] = [
            'path'    => $path,
            'name'    => 'seeds/' . basename($path),
            'kind'    => 'seed',
            'witness' => runner_witness(basename($path)),
        ];
    }

    // 3. Migrations, par ordre de nom de fichier (préfixe daté).
    $migrations = glob($directory . '/migrations/*.sql') ?: [];
    sort($migrations, SORT_STRING);

    foreach ($migrations as $path) {
        $files[] = [
            'path'    => $path,
            'name'    => basename($path),
            'kind'    => 'migration',
            'witness' => runner_witness(basename($path)),
        ];
    }

    return $files;
}

/**
 * Requête prouvant qu'un fichier livré avec le produit est déjà en place.
 *
 * Seuls les fichiers susceptibles d'avoir été appliqués hors registre
 * en ont un : les données de base, importées à la main sur hébergement
 * mutualisé, et le référentiel national, qui a changé d'emplacement.
 *
 * Un fichier écrit après coup n'a pas de témoin : il s'applique
 * normalement, ce qui est le comportement attendu.
 */
function runner_witness(string $basename): ?string
{
    return match ($basename) {
        '001_reference_data.sql'    => 'SELECT COUNT(*) FROM education_cycles',
        '002_roles_permissions.sql' => 'SELECT COUNT(*) FROM permissions',

        // Ce fichier s'appelait seeds/003_referentiel_national.sql et a
        // été déplacé dans migrations/ : il remplit des tables créées en
        // phase 2, il ne pouvait donc pas rester un seed.
        //
        // Les deux noms portent le même témoin. Sans celui du nouveau
        // nom, les bases déjà installées rejoueraient le fichier et
        // échoueraient sur « Duplicate entry » ; sans celui de l'ancien,
        // un dossier de travail où le fichier d'origine traîne encore
        // ferait échouer la migration au lieu de l'ignorer.
        '2026_09_13_001b_referentiel_national.sql' => 'SELECT COUNT(*) FROM learning_domains',
        '003_referentiel_national.sql'             => 'SELECT COUNT(*) FROM learning_domains',

        default => null,
    };
}

/** Crée le registre s'il n'existe pas encore. */
function runner_ledger_ensure(PDO $pdo): void
{
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
}

/**
 * Contenu actuel du registre, indexé par nom de fichier.
 *
 * @return array<string, array{filename: string, checksum: string, applied_at: string}>
 */
function runner_applied(PDO $pdo): array
{
    $applied = [];

    foreach ($pdo->query('SELECT filename, checksum, applied_at FROM schema_migrations') as $row) {
        $applied[$row['filename']] = $row;
    }

    return $applied;
}

/**
 * Enregistre un fichier dans le registre.
 */
function runner_record(PDO $pdo, string $name, string $checksum, string $kind, int $statements): void
{
    $pdo->prepare(
        'INSERT INTO schema_migrations (filename, checksum, kind, statements)
         VALUES (:filename, :checksum, :kind, :statements)'
    )->execute([
        'filename'   => $name,
        'checksum'   => $checksum,
        'kind'       => $kind,
        'statements' => $statements,
    ]);
}

/**
 * Enregistre sans exécuter les fichiers dont le témoin prouve qu'ils
 * sont déjà en place.
 *
 * @param  array<int, array{path: string, name: string, kind: string, witness: ?string}> $catalog
 * @param  array<string, mixed>                                                          $applied
 * @return string[]  Noms des fichiers auto-enregistrés.
 */
function runner_autobaseline(PDO $pdo, array $catalog, array $applied): array
{
    $recorded = [];

    foreach ($catalog as $file) {
        if (isset($applied[$file['name']]) || $file['witness'] === null || !is_file($file['path'])) {
            continue;
        }

        try {
            $present = (int) $pdo->query($file['witness'])->fetchColumn() > 0;
        } catch (PDOException) {
            // Table absente : le fichier n'a jamais été appliqué.
            $present = false;
        }

        if (!$present) {
            continue;
        }

        runner_record($pdo, $file['name'], hash_file('sha256', $file['path']), $file['kind'], 0);
        $recorded[] = $file['name'];
    }

    return $recorded;
}

/**
 * Applique un fichier et l'enregistre.
 *
 * Les seeds passent en transaction (INSERT seulement, donc réversible).
 * Les migrations non : MySQL valide implicitement le DDL.
 *
 * @param  array{path: string, name: string, kind: string, witness: ?string} $file
 * @return int Nombre d'instructions exécutées.
 * @throws RuntimeException si une instruction échoue.
 */
function runner_apply(PDO $pdo, array $file): int
{
    if (!is_file($file['path'])) {
        throw new RuntimeException("Fichier manquant : {$file['path']}");
    }

    $sql           = (string) file_get_contents($file['path']);
    $statements    = sql_split($sql);
    $executed      = 0;
    $current       = '';

    // La transaction est décidée par le CONTENU, pas par le dossier :
    // un fichier de migration qui ne contient que des INSERT mérite
    // d'être tout ou rien, et un seed contenant du DDL ne le peut pas.
    $transactional = !preg_match(
        '/\b(CREATE|ALTER|DROP|TRUNCATE|RENAME)\s+(TABLE|INDEX|DATABASE|VIEW|SCHEMA)\b/i',
        $sql
    );

    if ($transactional) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($statements as $statement) {
            $current = $statement;
            $pdo->exec($statement);
            $executed++;
        }
    } catch (PDOException $e) {
        if ($transactional && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw new RuntimeException(sprintf(
            "%s — instruction %d\n    %s\n    %s…",
            $file['name'],
            $executed + 1,
            $e->getMessage(),
            substr(preg_replace('/\s+/', ' ', $current) ?? '', 0, 160)
        ), 0, $e);
    }

    if ($transactional) {
        $pdo->commit();
    }

    runner_record($pdo, $file['name'], hash_file('sha256', $file['path']), $file['kind'], $executed);

    return $executed;
}

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
