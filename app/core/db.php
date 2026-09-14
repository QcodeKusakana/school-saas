<?php
/**
 * Couche d'accès aux données — PDO, requêtes préparées uniquement.
 *
 * Règles non négociables du projet :
 *   1. Aucune valeur n'est jamais concaténée dans une requête SQL.
 *      Tout passe par des paramètres liés.
 *   2. Les noms de tables et de colonnes ne viennent JAMAIS d'une saisie
 *      utilisateur. Quand ils sont dynamiques (tri), ils sont validés
 *      contre une liste blanche via db_safe_identifier().
 *   3. Toute écriture sur une table multi-école passe par les fonctions
 *      db_tenant_* de core/tenant.php, qui injectent school_id.
 */

declare(strict_types=1);

/**
 * Connexion PDO partagée (une seule par requête HTTP).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) config('database.host'),
        (int) config('database.port', 3306),
        (string) config('database.name'),
        (string) config('database.charset', 'utf8mb4')
    );

    try {
        $pdo = new PDO($dsn, (string) config('database.user'), (string) config('database.password'), [
            // Les erreurs SQL deviennent des exceptions : aucune erreur
            // ne peut être ignorée silencieusement.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Requêtes réellement préparées côté serveur MySQL, et non
            // émulées par le pilote : protection maximale contre l'injection.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', "
                . "time_zone='+01:00'",
        ]);
    } catch (PDOException $e) {
        // Le message PDO contient les identifiants de connexion :
        // il ne doit jamais atteindre le navigateur.
        log_error('Connexion à la base de données impossible', ['message' => $e->getMessage()]);
        throw new RuntimeException('Connexion à la base de données impossible.', 0, $e);
    }

    return $pdo;
}

/**
 * Exécute une requête préparée.
 *
 * @param string $sql             Requête avec paramètres nommés (:param)
 * @param array  $params          Valeurs liées
 * @param bool   $skipTenantGuard true uniquement pour les requêtes
 *                                volontairement inter-écoles (super admin,
 *                                installation, tâches planifiées)
 */
function db_query(string $sql, array $params = [], bool $skipTenantGuard = false): PDOStatement
{
    if (!$skipTenantGuard) {
        tenant_guard($sql);
    }

    $started = microtime(true);

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
    } catch (PDOException $e) {
        log_error('Échec de requête SQL', [
            'sql'    => $sql,
            // Les valeurs liées peuvent contenir des données personnelles :
            // on ne journalise que les noms de paramètres.
            'params' => array_keys($params),
            'error'  => $e->getMessage(),
        ]);
        throw $e;
    }

    $threshold = (float) config('database.slow_query_threshold', 0);
    $elapsed   = microtime(true) - $started;

    if ($threshold > 0 && $elapsed > $threshold) {
        log_warning('Requête lente', ['sql' => $sql, 'seconds' => round($elapsed, 3)]);
    }

    return $stmt;
}

/** Retourne une seule ligne, ou null. */
function db_one(string $sql, array $params = [], bool $skipTenantGuard = false): ?array
{
    $row = db_query($sql, $params, $skipTenantGuard)->fetch();

    return $row === false ? null : $row;
}

/** Retourne toutes les lignes. */
function db_all(string $sql, array $params = [], bool $skipTenantGuard = false): array
{
    return db_query($sql, $params, $skipTenantGuard)->fetchAll();
}

/** Retourne la première colonne de la première ligne. */
function db_value(string $sql, array $params = [], bool $skipTenantGuard = false): mixed
{
    $value = db_query($sql, $params, $skipTenantGuard)->fetchColumn();

    return $value === false ? null : $value;
}

/** Vrai si au moins une ligne correspond. */
function db_exists(string $sql, array $params = [], bool $skipTenantGuard = false): bool
{
    return db_value($sql, $params, $skipTenantGuard) !== null;
}

/**
 * Insertion générique.
 *
 * @param string $table Nom de table en dur dans le code appelant — JAMAIS une variable utilisateur
 * @param array  $data  Colonnes => valeurs
 * @return int          Identifiant inséré
 */
function db_insert(string $table, array $data, bool $skipTenantGuard = false): int
{
    if ($data === []) {
        throw new InvalidArgumentException("db_insert({$table}) : aucune donnée fournie.");
    }

    $columns = array_keys($data);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        db_safe_identifier($table),
        implode(', ', array_map('db_safe_identifier', $columns)),
        implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
    );

    db_query($sql, $data, $skipTenantGuard);

    return (int) db()->lastInsertId();
}

/**
 * Mise à jour générique.
 *
 * @param string $where  Clause WHERE avec paramètres nommés, sans le mot-clé WHERE
 * @return int           Nombre de lignes affectées
 */
function db_update(string $table, array $data, string $where, array $whereParams = [], bool $skipTenantGuard = false): int
{
    if ($data === []) {
        return 0;
    }

    if (trim($where) === '') {
        // Un UPDATE sans WHERE écraserait toute la table, toutes écoles confondues.
        throw new InvalidArgumentException("db_update({$table}) : clause WHERE obligatoire.");
    }

    $assignments = [];
    $params      = [];

    foreach ($data as $column => $value) {
        $assignments[]        = db_safe_identifier($column) . ' = :set_' . $column;
        $params['set_' . $column] = $value;
    }

    $sql = sprintf(
        'UPDATE %s SET %s WHERE %s',
        db_safe_identifier($table),
        implode(', ', $assignments),
        $where
    );

    return db_query($sql, array_merge($params, $whereParams), $skipTenantGuard)->rowCount();
}

/**
 * Suppression générique. À n'utiliser que pour les tables techniques :
 * les données métier sont archivées (deleted_at), jamais supprimées.
 */
function db_delete(string $table, string $where, array $params = [], bool $skipTenantGuard = false): int
{
    if (trim($where) === '') {
        throw new InvalidArgumentException("db_delete({$table}) : clause WHERE obligatoire.");
    }

    $sql = sprintf('DELETE FROM %s WHERE %s', db_safe_identifier($table), $where);

    return db_query($sql, $params, $skipTenantGuard)->rowCount();
}

/**
 * Exécute une fonction dans une transaction.
 * Annule tout et propage l'exception en cas d'erreur.
 *
 *   db_transaction(function () use ($data) {
 *       $id = db_insert('students', $data);
 *       db_insert('enrollments', ['student_id' => $id, ...]);
 *       return $id;
 *   });
 *
 * UN INTERBLOCAGE N'EST PAS UNE ERREUR APPLICATIVE
 * ------------------------------------------------
 * InnoDB détecte les interblocages et sacrifie l'une des deux
 * transactions, qu'il annule INTÉGRALEMENT. Ce n'est pas un défaut du
 * code appelant : c'est le comportement normal d'une base sous
 * concurrence, et la réponse attendue est de rejouer.
 *
 * Sans ce rejeu, deux caissiers qui encaissaient à la même seconde
 * voyaient l'un des deux tomber sur une page d'erreur, son paiement
 * perdu, avec une file d'attente devant lui — constaté en exécutant
 * quatre guichets en parallèle lors de l'audit de la phase 5B.
 *
 * Le rejeu est SÛR parce que la transaction annulée n'a rien laissé
 * derrière elle : on repart d'un état identique à celui du départ. Une
 * courte attente aléatoire évite que les deux perdants se retrouvent
 * aussitôt face à face.
 */
function db_transaction(callable $callback, int $attempts = 3): mixed
{
    $pdo = db();

    // Les transactions imbriquées ne sont pas gérées par MySQL sans
    // SAVEPOINT : on se contente de participer à celle déjà ouverte.
    // Le rejeu appartient alors à la transaction englobante — le faire
    // ici rejouerait une partie du travail sur un état déjà modifié.
    if ($pdo->inTransaction()) {
        return $callback();
    }

    for ($attempt = 1; ; $attempt++) {
        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($attempt < $attempts && db_is_deadlock($e)) {
                log_warning('Interblocage : transaction rejouée', [
                    'tentative' => $attempt,
                    'error'     => $e->getMessage(),
                ]);

                // 10 à 60 ms : assez pour que les deux transactions ne
                // repartent pas exactement en même temps.
                usleep(random_int(10000, 60000));

                continue;
            }

            log_error('Transaction annulée', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}

/**
 * L'exception est-elle un interblocage ou une attente de verrou
 * expirée — donc une situation qui se résout en rejouant ?
 */
function db_is_deadlock(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }

    // 40001 : échec de sérialisation (interblocage).
    // 1213 : deadlock ; 1205 : lock wait timeout.
    $code = (string) $e->getCode();

    if ($code === '40001') {
        return true;
    }

    $driverCode = (int) ($e->errorInfo[1] ?? 0);

    return $driverCode === 1213 || $driverCode === 1205;
}

/**
 * Valide un identifiant SQL (table ou colonne).
 *
 * N'accepte que [a-zA-Z0-9_]. Ne remplace PAS une liste blanche :
 * pour un tri dynamique, valider d'abord la colonne contre un tableau
 * autorisé, puis passer par cette fonction.
 */
function db_safe_identifier(string $identifier): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException("Identifiant SQL invalide : {$identifier}");
    }

    return '`' . $identifier . '`';
}

/**
 * Construit un ORDER BY sûr à partir d'une entrée utilisateur.
 *
 *   db_order_by($_GET['sort'] ?? '', $_GET['dir'] ?? '', ['name','created_at'], 'name')
 */
function db_order_by(string $column, string $direction, array $allowed, string $default): string
{
    $column    = in_array($column, $allowed, true) ? $column : $default;
    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

    return db_safe_identifier($column) . ' ' . $direction;
}

/**
 * Génère la liste de marqueurs pour une clause IN (...).
 * Retourne [placeholders, params].
 *
 *   [$in, $params] = db_in('id', [4, 7, 9]);
 *   db_all("SELECT * FROM students WHERE id IN ({$in})", $params);
 */
function db_in(string $prefix, array $values): array
{
    $placeholders = [];
    $params       = [];

    foreach (array_values($values) as $i => $value) {
        $key                 = $prefix . '_' . $i;
        $placeholders[]      = ':' . $key;
        $params[$key]        = $value;
    }

    return [$placeholders ? implode(', ', $placeholders) : 'NULL', $params];
}
