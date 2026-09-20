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
    // Phase 2 — référentiel scolaire
    'sections',
    'options',
    'subjects',
    'grade_periods',
    'curriculums',
    'curriculum_subjects',
    // Phase 3 — élèves, classes et parcours
    'rooms',
    'classrooms',
    'students',
    'student_counters',
    'guardians',
    'student_guardians',
    'enrollments',
    'orientations',
    'student_history',

    // Résultats figés des bulletins (phase 4C) et leur détail figé
    // (phase 6A) : le bulletin remis à une famille est une pièce, il ne
    // se recalcule pas.
    'bulletins',
    'bulletin_lines',

    // Journal d'audit : school_id vaut NULL pour les actions de la
    // plateforme, et l'identifiant de l'école pour toutes les autres.
    // Il est soumis au garde-fou : une future page « historique des
    // actions » qui oublierait le filtre exposerait l'activité complète
    // d'un autre établissement — c'est précisément ce qu'une trace ne
    // doit jamais laisser faire.
    'audit_logs',

    // Phases suivantes — déclarées d'avance pour ne pas être oubliées
    'teachers',
    'teacher_subjects',
    'staff',
    'evaluations',
    'grades',
    'attendance',
    'timetables',
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

    // Phase 4D — présences. Déclarées dès la création des tables : la
    // table `bulletins` avait été oubliée en phase 4C et n'a été
    // rattrapée que par un contrôle manuel.
    'attendance_sessions',
    'attendance_records',

    // Phase 5 — finances. `fees`, `student_fees` et `payments` étaient
    // déjà déclarés plus haut ; les suivants sont ajoutés à la création
    // des tables, même règle.
    'payment_allocations',
    'receipt_counters',

    // Phase 5D — dépenses. `expenses` était déclarée d'avance ; le
    // compteur des bons de sortie est ajouté à la création de la table.
    'expense_counters',
];

/**
 * Tables globales, volontairement hors périmètre du garde-fou.
 * Elles décrivent le produit ou le système éducatif national.
 */
const GLOBAL_TABLES = [
    // Référentiel des postes de dépense, partagé par toutes les écoles
    // au même titre que les niveaux scolaires (phase 5D).
    'expense_categories',
    'schools',
    'plans',
    'permissions',
    'education_cycles',
    'education_levels',
    'learning_domains',      // modèle national, phase 2
    'learning_subdomains',   // strate intercalaire, phase 4C
    'reference_sections',
    'reference_options',
    'reference_subjects',
    'login_attempts',
    'password_resets',

    // ----------------------------------------------------------------
    //  Deux tables portent une colonne school_id et restent pourtant
    //  déclarées globales. Ce n'est pas un oubli, et la raison doit
    //  rester écrite ici, sinon quelqu'un « corrigera » un jour la
    //  classification et cassera l'authentification.
    // ----------------------------------------------------------------

    // roles : les rôles système ont school_id NULL et sont partagés par
    // toutes les écoles. perm_all() et perm_roles() résolvent les rôles
    // par user_id — un utilisateur n'appartenant qu'à une seule école,
    // le cloisonnement vient de là. Toute page listant les rôles devra
    // en revanche filtrer explicitement
    // (school_id IS NULL OR school_id = :school_id), faute de quoi les
    // rôles personnalisés d'une autre école deviendraient visibles.
    'roles',
    'role_permissions',
    'user_roles',

    // user_sessions : la session est créée à l'authentification, avant
    // qu'un établissement ne soit placé dans le contexte. Elle est lue
    // et révoquée par user_id. La colonne school_id n'y est qu'une
    // dénormalisation de confort.
    'user_sessions',

    // Registre de l'applicateur de migrations : infrastructure, jamais
    // rattachée à un établissement.
    'schema_migrations',
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
 * Vérifie qu'une requête touchant une table multi-école LIE bien
 * school_id — et non qu'elle le mentionne.
 *
 * POURQUOI « LIER » ET NON « MENTIONNER »
 * =======================================
 * Ce contrôle était `preg_match('/\bschool_id\b/i', $sql)`. Trois
 * requêtes le franchissaient, démontrées par exécution avant la
 * phase 7B :
 *
 *   SELECT school_id, COUNT(*) FROM students GROUP BY school_id
 *     → 124 élèves de TOUTES les écoles, sans une alerte.
 *   SELECT COUNT(*) FROM students -- school_id
 *     → le mot suffisait, même en commentaire.
 *   SELECT COUNT(*) FROM students WHERE 'school_id' <> ''
 *     → même dans une chaîne littérale.
 *
 * La première n'est pas un cas d'école : c'est exactement la forme d'un
 * tableau de bord éditeur — et exactement celle d'une fuite. Un
 * garde-fou qui ne distingue pas les deux ne garde rien.
 *
 * Ce qui compte n'est donc pas la présence du mot, mais qu'il occupe
 * une position LIANTE :
 *
 *   · un prédicat      → school_id = / <> / < / IN / IS / BETWEEN …
 *   · une jointure     → USING (school_id)
 *   · une affectation  → INSERT (… school_id …) / SET school_id =
 *
 * Un placeholder (`:school_id`) ne lie rien : il ne compte pas.
 *
 * L'analyse reste volontairement sans analyseur SQL complet — pas de
 * dépendance sur un hébergement cPanel — mais elle travaille sur une
 * requête débarrassée de ses commentaires et de ses littéraux.
 *
 * REQUÊTES VOLONTAIREMENT INTER-ÉCOLES
 * ------------------------------------
 * Elles existent : la console de l'éditeur doit lire toutes les écoles.
 * Elles passent par `platform_scope()` (app/core/platform.php), qui
 * exige d'abord une habilitation plateforme. Le troisième argument de
 * `db_query()` ne suffit plus à lui seul : voir
 * `tenant_guard_unscoped()`.
 */
function tenant_guard(string $sql): void
{
    $scoped = tenant_guard_scoped_tables($sql);

    if ($scoped === []) {
        return;
    }

    if (tenant_sql_binds_school(tenant_sql_strip($sql))) {
        return;
    }

    // Le libellé « sans filtre school_id » est un CONTRAT : onze suites
    // de tests l'assertent pour prouver que le garde-fou mord encore.
    // Le préciser est utile, le remplacer casserait la preuve.
    tenant_guard_refuse(
        sprintf(
            'Requête sur une table multi-école sans filtre school_id '
            . '(le mot doit être LIÉ à une valeur, pas seulement mentionné) : %s',
            implode(', ', $scoped)
        ),
        $sql,
        "Si la requête est volontairement inter-écoles, l'ouvrir dans "
        . "platform_scope(...) — voir app/core/platform.php. Mentionner "
        . "school_id sans le lier ne suffit pas."
    );
}

/**
 * Contrôle des requêtes déclarées « sans filtre école ».
 *
 * `db_query($sql, $params, true)` servait d'échappatoire sur l'honneur :
 * rien ne distinguait une lecture légitime d'une table globale (`plans`,
 * `education_levels`) d'une fuite inter-écoles sur `students`.
 *
 * Désormais l'échappatoire ne couvre que ce qu'elle était censée
 * couvrir. Toucher une table multi-école SANS lier school_id exige un
 * périmètre plateforme ouvert — donc une habilitation vérifiée.
 */
function tenant_guard_unscoped(string $sql): void
{
    $scoped = tenant_guard_scoped_tables($sql);

    if ($scoped === []) {
        return;   // tables globales : c'est l'usage prévu du drapeau
    }

    if (tenant_sql_binds_school(tenant_sql_strip($sql))) {
        return;   // le filtre est là malgré le drapeau : rien à dire
    }

    if (function_exists('platform_scope_is_open') && platform_scope_is_open()) {
        return;   // console éditeur : lecture transversale assumée
    }

    tenant_guard_refuse(
        sprintf(
            'Lecture inter-écoles hors périmètre plateforme : %s',
            implode(', ', $scoped)
        ),
        $sql,
        "Une requête transversale doit être ouverte dans platform_scope(...), "
        . "qui vérifie l'habilitation de l'appelant. Le drapeau "
        . "db_query(\$sql, \$params, true) ne couvre que les tables globales."
    );
}

/** Les tables multi-écoles citées par une requête. */
function tenant_guard_scoped_tables(string $sql): array
{
    $tables = tenant_extract_tables($sql);

    if ($tables === []) {
        return [];
    }

    return array_values(array_intersect($tables, TENANT_TABLES));
}

/**
 * Refus commun aux deux gardes.
 *
 * En développement on casse : l'oubli doit être corrigé avant la
 * production. En production on ne renvoie pas l'utilisateur sur une page
 * d'erreur, mais l'incident est tracé en ERROR pour être traité.
 */
function tenant_guard_refuse(string $message, string $sql, string $hint): void
{
    log_error('[TENANT GUARD] ' . $message, ['sql' => $sql]);

    if (config('app.debug')) {
        throw new RuntimeException(
            $message . "\n\nRequête : " . $sql . "\n\n" . $hint
        );
    }
}

/**
 * Retire d'une requête ce qui peut contenir le mot « school_id » sans
 * rien lier : littéraux et commentaires.
 *
 * Les littéraux partent en premier : un commentaire peut vivre dans une
 * chaîne, et l'inverse aussi.
 */
function tenant_sql_strip(string $sql): string
{
    $clean = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $sql) ?? $sql;
    $clean = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $clean) ?? $clean;

    return preg_replace('/--[^\n]*|#[^\n]*|\/\*.*?\*\//s', ' ', $clean) ?? $clean;
}

/**
 * school_id occupe-t-il une position LIANTE dans cette requête ?
 *
 * Trois positions comptent, et elles seules :
 *
 *   1. PRÉDICAT — `s.school_id = :x`, `school_id IN (…)`,
 *      `school_id IS NOT NULL`, `school_id BETWEEN …`
 *   2. JOINTURE — `USING (school_id)`
 *   3. AFFECTATION — la colonne figure dans la liste d'un INSERT, ou
 *      dans un `SET school_id = …`
 *
 * Un `:school_id` est écarté : un placeholder est une valeur, pas une
 * colonne. Sans cette exclusion, `WHERE id = :school_id` sur une table
 * multi-école passerait pour filtrée.
 *
 * Le contrôle est volontairement PERMISSIF sur la forme et STRICT sur la
 * nature : mieux vaut accepter une jointure exotique bien écrite que
 * refuser du code juste — un garde-fou qui crie à tort finit désactivé.
 */
function tenant_sql_binds_school(string $clean): bool
{
    // 1. Prédicat : la colonne, non précédée de « : », suivie d'un
    //    opérateur de comparaison ou d'appartenance.
    if (preg_match(
        '/(?<![:\w])(?:`?\w+`?\s*\.\s*)?`?school_id`?\s*(?:=|<|>|!=|<>|<=|>=|\b(?:IN|IS|BETWEEN|LIKE)\b)/i',
        $clean
    )) {
        return true;
    }

    // 2. Jointure naturelle explicite.
    if (preg_match('/\bUSING\s*\(\s*`?school_id`?/i', $clean)) {
        return true;
    }

    // 3. Affectation : SET school_id = …  (déjà couvert par le prédicat,
    //    gardé pour la lisibilité de l'intention) ou colonne insérée.
    if (preg_match('/\bINSERT\b.*?\(([^)]*)\)/is', $clean, $m)
        && preg_match('/(?<![:\w])`?school_id`?/i', $m[1])) {
        return true;
    }

    return false;
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
