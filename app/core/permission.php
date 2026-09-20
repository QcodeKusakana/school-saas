<?php
/**
 * Contrôle des permissions.
 *
 * Modèle : utilisateur -> rôles -> permissions.
 * Les permissions de l'utilisateur sont chargées une seule fois par
 * requête (une requête SQL), puis conservées en mémoire.
 *
 * ------------------------------------------------------------------
 *  UNE PERMISSION NE SUFFIT JAMAIS
 * ------------------------------------------------------------------
 * perm_has('grade.enter') répond « cet utilisateur a le droit de saisir
 * des notes ». Elle ne répond PAS « dans cette classe précise ».
 * La restriction au périmètre (ses classes, ses enfants) relève de la
 * couche métier et doit être appliquée en plus, systématiquement.
 */

declare(strict_types=1);

/** Codes de permission de l'utilisateur connecté. */
function perm_all(bool $refresh = false): array
{
    static $permissions = null;

    if ($refresh) {
        $permissions = null;
    }

    if ($permissions !== null) {
        return $permissions;
    }

    $userId = $_SESSION['user_id'] ?? null;

    if (!$userId) {
        return $permissions = [];
    }

    $rows = db_all(
        'SELECT DISTINCT p.code
           FROM user_roles ur
           JOIN roles r        ON r.id = ur.role_id AND r.is_active = 1
           JOIN role_permissions rp ON rp.role_id = r.id
           JOIN permissions p  ON p.id = rp.permission_id
          WHERE ur.user_id = :user_id',
        ['user_id' => (int) $userId],
        true // tables globales, hors périmètre du garde-fou multi-école
    );

    return $permissions = array_column($rows, 'code');
}

/** Vrai si l'utilisateur détient la permission. */
function perm_has(string $code): bool
{
    return in_array($code, perm_all(), true);
}

/** Vrai si l'utilisateur détient AU MOINS UNE des permissions. */
function perm_any(array $codes): bool
{
    return array_intersect($codes, perm_all()) !== [];
}

/** Vrai si l'utilisateur détient TOUTES les permissions. */
function perm_all_of(array $codes): bool
{
    return array_diff($codes, perm_all()) === [];
}

/**
 * Interrompt la requête si la permission est absente.
 * Utilisée par le middleware perm: du routeur.
 */
function perm_require(string $code): void
{
    if (perm_has($code)) {
        return;
    }

    log_warning('Accès refusé', [
        'permission' => $code,
        'path'       => request_path(),
        'user_id'    => $_SESSION['user_id'] ?? null,
    ]);

    audit_log('access_denied', 'permission', null, null, ['permission' => $code], 'Accès refusé : ' . $code);

    abort(403);
}

/** Codes des rôles de l'utilisateur connecté. */
function perm_roles(bool $refresh = false): array
{
    static $roles = null;

    if ($refresh) {
        $roles = null;
    }

    if ($roles !== null) {
        return $roles;
    }

    $userId = $_SESSION['user_id'] ?? null;

    if (!$userId) {
        return $roles = [];
    }

    $rows = db_all(
        'SELECT r.code, r.level
           FROM user_roles ur
           JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
          WHERE ur.user_id = :user_id',
        ['user_id' => (int) $userId],
        true
    );

    return $roles = $rows;
}

/** Vrai si l'utilisateur possède ce rôle. */
function perm_has_role(string $code): bool
{
    return in_array($code, array_column(perm_roles(), 'code'), true);
}

/**
 * Niveau hiérarchique le plus élevé de l'utilisateur.
 * Sert à empêcher qu'un compte modifie un compte de niveau supérieur
 * ou égal — et donc, notamment, qu'il s'auto-promeuve.
 */
function perm_level(): int
{
    $levels = array_column(perm_roles(), 'level');

    return $levels === [] ? 0 : max(array_map('intval', $levels));
}

/**
 * Vrai si l'utilisateur courant peut gérer le compte cible.
 *
 * Règle : on ne peut gérer qu'un compte dont le niveau est STRICTEMENT
 * inférieur au sien, et uniquement dans son propre établissement.
 */
function perm_can_manage_user(int $targetUserId): bool
{
    $currentId = $_SESSION['user_id'] ?? null;

    if (!$currentId) {
        return false;
    }

    // Un utilisateur ne modifie pas son propre rôle via cette voie.
    if ((int) $currentId === $targetUserId) {
        return false;
    }

    // Lecture d'identité : on cherche à quelle école appartient la CIBLE,
    // précisément pour vérifier ensuite qu'elle est bien dans la nôtre.
    // Filtrer sur school_id avant de le savoir serait circulaire.
    $target = tenant_scope_identity(static fn (): ?array => db_one(
        'SELECT u.school_id, COALESCE(MAX(r.level), 0) AS level
           FROM users u
           LEFT JOIN user_roles ur ON ur.user_id = u.id
           LEFT JOIN roles r       ON r.id = ur.role_id
          WHERE u.id = :id AND u.deleted_at IS NULL
          GROUP BY u.id, u.school_id',
        ['id' => $targetUserId],
        true
    ));

    if ($target === null) {
        return false;
    }

    // Un administrateur d'école ne sort jamais de son établissement.
    if (!auth_is_platform_admin()) {
        $targetSchool = $target['school_id'] !== null ? (int) $target['school_id'] : null;

        if ($targetSchool === null || $targetSchool !== tenant_id()) {
            return false;
        }
    }

    return perm_level() > (int) $target['level'];
}

/**
 * Affiche un bloc uniquement si la permission est détenue.
 * Raccourci pour les vues :
 *
 *   <?php if (can('student.create')): ?> ... <?php endif; ?>
 */
function can(string $code): bool
{
    return perm_has($code);
}
