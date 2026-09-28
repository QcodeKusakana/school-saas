<?php
/**
 * BIBLIOTHÈQUE DE SAUVEGARDE — sans effet de bord, requise par
 * `backup.php` et par `restore.php`.
 *
 * POURQUOI UNE BIBLIOTHÈQUE PLUTÔT QUE DEUX COPIES
 * ================================================
 * `backup.php` enregistre une empreinte par table ; `restore.php` la
 * recalcule pour dire si la restauration a rendu la même chose. Si
 * chacun calculait la sienne, les deux définitions finiraient par
 * diverger — et la vérification répondrait « conforme » en comparant
 * deux choses différentes.
 *
 *   > Une vérification qui n'emprunte pas le même chemin que ce qu'elle
 *   > vérifie finit par valider autre chose.
 *
 * `backup_empreinte_table()` est donc l'UNIQUE définition. Elle coûte
 * une seconde lecture de la table au moment de la sauvegarde ; c'est le
 * prix d'une vérification qui veut dire quelque chose. Le principe de
 * décision du projet met la fiabilité des données (2) avant la
 * performance (4).
 */

declare(strict_types=1);

/**
 * Les tables de base, par ordre de nom.
 *
 * @return array<int, string>
 */
function backup_tables(PDO $pdo, string $dbName): array
{
    $requete = $pdo->prepare(
        "SELECT table_name FROM information_schema.tables
          WHERE table_schema = :base AND table_type = 'BASE TABLE'
          ORDER BY table_name"
    );
    $requete->execute(['base' => $dbName]);

    return $requete->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Les colonnes qui portent des OCTETS, pas du texte.
 *
 * Cinq colonnes de ce schéma portent des adresses IP compactées par
 * `inet_pton`. Traitées comme du texte, elles reviendraient corrompues
 * — et silencieusement, puisqu'une adresse abîmée reste une chaîne
 * d'octets.
 *
 * @return array<int, string>
 */
function backup_colonnes_binaires(PDO $pdo, string $dbName, string $table): array
{
    $requete = $pdo->prepare(
        "SELECT column_name FROM information_schema.columns
          WHERE table_schema = :base AND table_name = :table
            AND data_type IN ('blob','mediumblob','longblob','tinyblob','binary','varbinary')"
    );
    $requete->execute(['base' => $dbName, 'table' => $table]);

    return $requete->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Les colonnes calculées par MySQL.
 *
 * Elles ne s'insèrent pas — MySQL refuse — et se recalculent à la
 * restauration. C'est tout leur intérêt, et c'est pourquoi elles sont
 * aussi exclues de l'empreinte : leur valeur découle des autres.
 *
 * @return array<int, string>
 */
function backup_colonnes_generees(PDO $pdo, string $dbName, string $table): array
{
    $requete = $pdo->prepare(
        "SELECT column_name FROM information_schema.columns
          WHERE table_schema = :base AND table_name = :table
            AND generation_expression <> ''"
    );
    $requete->execute(['base' => $dbName, 'table' => $table]);

    return $requete->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Les colonnes de la clé primaire, dans l'ordre.
 *
 * @return array<int, string>
 */
function backup_cle_primaire(PDO $pdo, string $table): array
{
    $cles = [];

    foreach ($pdo->query('SHOW KEYS FROM `' . $table . "` WHERE Key_name = 'PRIMARY'") as $ligne) {
        $cles[(int) $ligne['Seq_in_index']] = (string) $ligne['Column_name'];
    }

    ksort($cles);

    return array_values($cles);
}

/**
 * Le nombre de lignes et l'empreinte d'une table.
 *
 * L'ORDRE EST IMPOSÉ PAR LA CLÉ PRIMAIRE. Sans `ORDER BY`, MySQL ne
 * promet aucun ordre : deux lectures de la même table pourraient rendre
 * deux empreintes différentes, et la vérification accuserait une
 * restauration parfaite.
 *
 * L'empreinte porte sur la DONNÉE — les valeurs, ligne par ligne — et
 * non sur le texte SQL produit : un changement de mise en forme du
 * fichier ne doit pas ressembler à une perte de données.
 *
 * @return array{lignes: int, empreinte: string}
 */
function backup_empreinte_table(PDO $pdo, string $dbName, string $table): array
{
    $generees = backup_colonnes_generees($pdo, $dbName, $table);
    $cles     = backup_cle_primaire($pdo, $table);

    $ordre = $cles !== []
        ? ' ORDER BY ' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cles))
        : '';

    $empreinte = hash_init('md5');
    $lignes    = 0;

    foreach ($pdo->query('SELECT * FROM `' . $table . '`' . $ordre) as $ligne) {
        foreach ($generees as $g) {
            unset($ligne[$g]);
        }

        hash_update($empreinte, serialize($ligne));
        $lignes++;
    }

    return ['lignes' => $lignes, 'empreinte' => hash_final($empreinte)];
}

/**
 * Un littéral SQL pour cette valeur.
 *
 * Une valeur binaire sort en hexadécimal : `PDO::quote()` la
 * corromprait. `0x` seul n'étant pas un littéral valide, une chaîne
 * binaire vide s'écrit comme une chaîne vide.
 */
function backup_litteral(PDO $pdo, mixed $valeur, bool $binaire): string
{
    if ($valeur === null) {
        return 'NULL';
    }

    if ($binaire) {
        $octets = (string) $valeur;

        return $octets === '' ? "''" : '0x' . bin2hex($octets);
    }

    if (is_int($valeur) || is_float($valeur)) {
        return (string) $valeur;
    }

    return $pdo->quote((string) $valeur);
}

/**
 * Les migrations appliquées, telles que le registre les connaît.
 *
 * @return array<string, string>
 */
function backup_migrations(PDO $pdo): array
{
    $migrations = [];

    try {
        foreach ($pdo->query('SELECT filename, checksum FROM schema_migrations ORDER BY filename') as $l) {
            $migrations[(string) $l['filename']] = (string) $l['checksum'];
        }
    } catch (Throwable) {
        // Une base antérieure au registre : on le dit en ne rien mettant.
    }

    return $migrations;
}

/**
 * Ouvre un INSTANTANÉ COHÉRENT : toutes les lectures qui suivent voient
 * la base au même instant, quoi qu'écrive l'école pendant ce temps.
 *
 * POURQUOI C'EST INDISPENSABLE, ET PAS UN RAFFINEMENT
 * ---------------------------------------------------
 * La sauvegarde parcourt 61 tables, chacune par son propre SELECT. Sans
 * transaction, chaque table est lue à un instant différent — mesuré :
 *
 *     SANS transaction : 14 ligne(s) avant, 15 après
 *     AVEC instantané  : 15 ligne(s) avant, 15 après
 *
 * Les tables sortent par ordre alphabétique, donc `classrooms` est lue
 * AVANT `enrollments`. Une classe créée entre les deux, et une
 * inscription qui la vise, donnent une archive portant l'inscription
 * SANS la classe.
 *
 * Et la restauration ne le verrait pas : elle suspend les clés
 * étrangères pendant le chargement, et MySQL NE REVALIDE PAS l'existant
 * quand on les rétablit — mesuré aussi. La clé pendante reste, en
 * silence, et les écritures suivantes passent.
 *
 *   > Une sauvegarde prise pendant que l'école travaille n'est pas une
 *   > photo : c'est un collage, à moins qu'on ne l'exige autrement.
 *
 * Une sauvegarde nocturne ne dispense pas de ce verrou : un
 * établissement qui a des surveillants, un comptable en heures
 * décalées, ou simplement un travail périodique, écrit la nuit.
 *
 * Les 61 tables sont en InnoDB — vérifié —, condition de
 * `WITH CONSISTENT SNAPSHOT`.
 */
function backup_ouvrir_instantane(PDO $pdo): void
{
    // REPEATABLE READ est le défaut de MySQL, mais on ne s'appuie pas
    // sur un défaut pour une garantie d'intégrité : on le pose.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
}

/** Referme l'instantané. La sauvegarde n'écrit rien : rien à valider. */
function backup_fermer_instantane(PDO $pdo): void
{
    $pdo->exec('COMMIT');
}

/** Ouvre la base de l'application. */
function backup_connexion(): PDO
{
    return new PDO(
        'mysql:host=' . (string) config('database.host')
        . ';port=' . (int) config('database.port', 3306)
        . ';dbname=' . (string) config('database.name') . ';charset=utf8mb4',
        (string) config('database.user'),
        (string) config('database.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function backup_taille_lisible(int $octets): string
{
    if ($octets < 1024) {
        return $octets . ' o';
    }

    return $octets < 1024 * 1024
        ? round($octets / 1024, 1) . ' Ko'
        : round($octets / 1024 / 1024, 1) . ' Mo';
}
