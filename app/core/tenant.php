<?php
/**
 * Isolation multi-établissements (multi-tenant).
 *
 * ------------------------------------------------------------------
 *  POURQUOI CE FICHIER EXISTE
 * ------------------------------------------------------------------
 * Dans un SaaS à base de données partagée, la faille la plus fréquente
 * et la plus grave n'est pas l'injection SQL : c'est le WHERE school_id
 * oublié dans une requête. Une seule omission expose les élèves, les
 * notes et les finances d'un autre établissement.
 *
 * On ne peut pas compter sur la discipline du développeur sur des
 * centaines de requêtes. Ce fichier met donc en place deux protections
 * complémentaires :
 *
 *   1. Des helpers (db_tenant_*) qui injectent school_id automatiquement.
 *      C'est la voie normale : le développeur n'a rien à penser.
 *
 *   2. Un garde-fou (tenant_guard) qui inspecte CHAQUE requête. Si elle
 *      touche une table multi-école sans mentionner school_id, elle est
 *      bloquée en développement et journalisée en alerte en production.
 *
 * Le garde-fou est une ceinture, pas un parachute : il détecte l'oubli,
 * il ne remplace pas une revue de code.
 * ------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * Tables portant une colonne school_id.
 *
 * TOUTE nouvelle table métier DOIT être ajoutée ici au moment de sa
 * création, sinon le garde-fou la laissera passer sans contrôle.
 */
const TENANT_TABLES = [
    // Phase 1
    'school_settings',
    'school_cycles',
    'subscriptions',
    'subscription_payments',
    'users',
    'academic_years',
    'sync_devices',
    'sync_queue',
    'sync_conflicts',
    // Phases suivantes — déclarées d'avance pour ne pas être oubliées
    'sections',
    'options',
    'classrooms',
    'subjects',
    'curriculums',
    'curriculum_subjects',
    'students',
    'guardians',
    'student_guardians',
    'enrollments',
    'student_history',
    'orientations',
    'teachers',
    'teacher_subjects',
    'staff',
    'grade_periods',
    'evaluations',
    'grades',
    'attendance',
    'timetables',
    'rooms',
    'fees',
    'student_fees',
    'payments',
    'receipts',
    'expenses',
    'documents',
    'certificates',
    'school_cards',
    'notifications',
    'messages',
    'disciplinary_records',
    'rewards',
];

/**
 * Tables globales, volontairement hors périmètre du garde-fou.
 * Elles décrivent le produit ou le système éducatif national.
 */
const GLOBAL_TABLES = [
    'schools',
    'plans',
    'permissions',
    'roles',              // school_id nullable : rôles système partagés
    'role_permissions',
    'user_roles',
    'education_cycles',
    'education_levels',
    'audit_logs',         // school_id nullable : trace aussi la plateforme
    'login_attempts',
    'password_resets',
    'user_sessions',
];

// ---------------------------------------------------------------------
//  CONTEXTE COURANT
// ---------------------------------------------------------------------

/**
 * Définit l'établissement du contexte courant.
 * Appelé une seule fois par requête, après l'authentification.
 *
 * @param int|null $schoolId null = contexte plateforme (super admin)
 */
function tenant_set(?int $schoolId): void
{
    tenant_context('set', $schoolId);
}

/** Identifiant de l'établissement courant, ou null en contexte plateforme. */
function tenant_id(): ?int
{
    return tenant_context('get');
}

/**
 * Identifiant de l'établissement courant, obligatoire.
 * Lève une exception si l'on est en contexte plateforme : cela signifie
 * qu'une page destinée à une école a été atteinte sans école sélectionnée.
 */
function tenant_require(): int
{
    $id = tenant_id();

    if ($id === null) {
        throw new RuntimeException(
            'Aucun établissement dans le contexte courant. '
            . 'Un super administrateur doit sélectionner une école avant d\'accéder à cette page.'
        );
    }

    return $id;
}

/** Vrai si l'on agit au niveau de la plateforme et non d'une école. */
function tenant_is_platform(): bool
{
    return tenant_id() === null;
}

/**
 * Exécute une fonction dans le contexte d'une autre école, puis restaure
 * le contexte précédent. Réservé aux tâches d'administration plateforme.
 */
function tenant_as(int $schoolId, callable $callback): mixed
{
    $previous = tenant_id();
    tenant_set($schoolId);

    try {
        return $callback();
    } finally {
        tenant_set($previous);
    }
}

/** Stockage interne du contexte (évite une variable globale mutable). */
function tenant_context(string $action, ?int $value = null): ?int
{
    static $schoolId = null;

    if ($action === 'set') {
        $schoolId = $value;
    }

    return $schoolId;
}

// ---------------------------------------------------------------------
//  GARDE-FOU
// ---------------------------------------------------------------------

/**
 * Vérifie qu'une requête touchant une table multi-école filtre bien
 * sur school_id.
 *
 * Analyse volontairement simple et rapide (pas d'analyseur SQL complet) :
 * on extrait les tables citées après FROM / JOIN / INTO / UPDATE, et on
 * vérifie la présence du mot school_id dans la requête.
 *
 * Faux positifs possibles (requête légitime sans school_id) : passer
 * true en 3ème argument de db_query(), avec un commentaire justifiant
 * pourquoi la requête est volontairement inter-écoles.
 */
function tenant_guard(string $sql): void
{
    $tables = tenant_extract_tables($sql);

    if ($tables === []) {
        return;
    }

    $scoped = array_intersect($tables, TENANT_TABLES);

    if ($scoped === []) {
        return;
    }

    // La requête mentionne-t-elle school_id d'une manière ou d'une autre ?
    if (preg_match('/\bschool_id\b/i', $sql)) {
        return;
    }

    $message = sprintf(
        'Requête sur une table multi-école sans filtre school_id : %s',
        implode(', ', $scoped)
    );

    log_error('[TENANT GUARD] ' . $message, ['sql' => $sql]);

    if (config('app.debug')) {
        // En développement : on casse immédiatement pour que l'oubli
        // soit corrigé avant d'atteindre la production.
        throw new RuntimeException(
            $message . "\n\nRequête : " . $sql
            . "\n\nSi la requête est volontairement inter-écoles, appeler "
            . "db_query(\$sql, \$params, true) et justifier par un commentaire."
        );
    }

    // En production : on ne bloque pas l'utilisateur, mais l'incident est
    // tracé en erreur pour être traité. Adapter selon votre tolérance :
    // lever ici aussi est plus sûr, au prix d'une page d'erreur.
}

/**
 * Extrait les noms de tables d'une requête SQL.
 * Ignore les alias, les sous-requêtes imbriquées et la casse.
 */
function tenant_extract_tables(string $sql): array
{
    // Supprime les chaînes littérales et les commentaires pour éviter
    // de confondre un mot dans une valeur avec un nom de table.
    $clean = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $sql) ?? $sql;
    $clean = preg_replace('/--[^\n]*|\/\*.*?\*\//s', ' ', $clean) ?? $clean;

    $pattern = '/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i';

    if (!preg_match_all($pattern, $clean, $matches)) {
        return [];
    }

    return array_values(array_unique(array_map('strtolower', $matches[1])));
}

// ---------------------------------------------------------------------
//  HELPERS D'ACCÈS FILTRÉS
//  Voie normale d'accès aux tables multi-écoles.
// ---------------------------------------------------------------------

/**
 * SELECT filtré sur l'école courante.
 *
 *   tenant_all('academic_years', 'status = :status ORDER BY starts_on DESC',
 *              ['status' => 'active']);
 *
 * @param string $table      Nom de table en dur
 * @param string $conditions Conditions additionnelles (sans WHERE ni school_id)
 */
function tenant_all(string $table, string $conditions = '', array $params = [], string $columns = '*'): array
{
    [$sql, $params] = tenant_build_select($table, $conditions, $params, $columns);

    return db_all($sql, $params);
}

/** SELECT d'une seule ligne, filtré sur l'école courante. */
function tenant_one(string $table, string $conditions = '', array $params = [], string $columns = '*'): ?array
{
    [$sql, $params] = tenant_build_select($table, $conditions, $params, $columns);

    return db_one($sql . ' LIMIT 1', $params);
}

/**
 * Recherche par identifiant, avec garantie d'appartenance à l'école courante.
 *
 * C'est LA fonction à utiliser pour toute ressource atteinte par son id
 * dans l'URL. Elle rend impossible l'accès à l'enregistrement d'une autre
 * école par simple modification du paramètre.
 */
function tenant_find(string $table, int $id, string $columns = '*'): ?array
{
    return tenant_one($table, 'id = :id', ['id' => $id], $columns);
}

/** Comptage filtré sur l'école courante. */
function tenant_count(string $table, string $conditions = '', array $params = []): int
{
    [$sql, $params] = tenant_build_select($table, $conditions, $params, 'COUNT(*)');

    return (int) db_value($sql, $params);
}

/** INSERT avec school_id injecté automatiquement. */
function tenant_insert(string $table, array $data): int
{
    tenant_assert_scoped($table);

    $data['school_id'] = tenant_require();

    return db_insert($table, $data);
}

/** UPDATE restreint à l'école courante. */
function tenant_update(string $table, array $data, string $conditions, array $params = []): int
{
    tenant_assert_scoped($table);

    // school_id ne doit jamais être modifié par une mise à jour métier :
    // cela déplacerait l'enregistrement vers une autre école.
    unset($data['school_id']);

    $params['tenant_school_id'] = tenant_require();
    $where = 'school_id = :tenant_school_id' . ($conditions !== '' ? ' AND (' . $conditions . ')' : '');

    return db_update($table, $data, $where, $params);
}

/** DELETE restreint à l'école courante (tables techniques uniquement). */
function tenant_delete(string $table, string $conditions, array $params = []): int
{
    tenant_assert_scoped($table);

    if (trim($conditions) === '') {
        throw new InvalidArgumentException("tenant_delete({$table}) : conditions obligatoires.");
    }

    $params['tenant_school_id'] = tenant_require();

    return db_delete($table, 'school_id = :tenant_school_id AND (' . $conditions . ')', $params);
}

/** Construit un SELECT filtré. Usage interne. */
function tenant_build_select(string $table, string $conditions, array $params, string $columns): array
{
    tenant_assert_scoped($table);

    $params['tenant_school_id'] = tenant_require();

    // $columns et $conditions viennent du code, jamais de l'utilisateur.
    $sql = sprintf(
        'SELECT %s FROM %s WHERE school_id = :tenant_school_id%s',
        $columns,
        db_safe_identifier($table),
        $conditions !== '' ? ' AND ' . $conditions : ''
    );

    return [$sql, $params];
}

/** Vérifie qu'une table est bien déclarée comme multi-école. */
function tenant_assert_scoped(string $table): void
{
    if (!in_array(strtolower($table), TENANT_TABLES, true)) {
        throw new InvalidArgumentException(
            "La table « {$table} » n'est pas déclarée dans TENANT_TABLES. "
            . "Ajoutez-la à app/core/tenant.php, ou utilisez db_* si elle est globale."
        );
    }
}
