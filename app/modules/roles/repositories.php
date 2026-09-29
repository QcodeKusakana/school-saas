<?php
/**
 * Module RÔLES — lectures (phase 11B).
 *
 * CE QUE CE MODULE VOIT, ET CE QU'IL NE VOIT PAS
 * ==============================================
 * Une école voit les rôles SYSTÈME — les neuf livrés avec le produit,
 * qu'elle ne peut pas modifier — et les SIENS. Jamais ceux d'une autre.
 *
 * `roles` n'est pas une table multi-école au sens du garde-fou : ses
 * lignes globales (`school_id IS NULL`) appartiennent à tout le monde.
 * Le filtre est donc écrit à la main, à chaque requête, et c'est
 * exactement pourquoi il est ici plutôt que dispersé dans les services.
 */

declare(strict_types=1);

/**
 * Les rôles visibles par l'école courante.
 *
 * @return array<int, array<string, mixed>>
 */
function roles_repo_all(): array
{
    // DEUX PARAMÈTRES POUR LA MÊME VALEUR, ET C'EST VOULU : les
    // requêtes préparées non émulées refusent qu'un paramètre nommé
    // serve deux fois.
    $ecole = tenant_require();

    return db_all(
        'SELECT r.id, r.code, r.name, r.description, r.level, r.is_system, r.is_active,
                r.school_id,
                (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permissions,
                (SELECT COUNT(*) FROM user_roles ur
                   JOIN users u ON u.id = ur.user_id AND u.deleted_at IS NULL
                  WHERE ur.role_id = r.id AND u.school_id = :school_comptes) AS comptes
           FROM roles r
          WHERE r.school_id IS NULL OR r.school_id = :school_id
          ORDER BY r.level DESC, r.name',
        ['school_id' => $ecole, 'school_comptes' => $ecole],
        true
    );
}

/** Un rôle visible par l'école courante, ou null. */
function roles_repo_find(int $roleId): ?array
{
    return db_one(
        'SELECT r.* FROM roles r
          WHERE r.id = :id AND (r.school_id IS NULL OR r.school_id = :school_id)
          LIMIT 1',
        ['id' => $roleId, 'school_id' => tenant_require()],
        true
    );
}

/**
 * Les permissions attachées à un rôle.
 *
 * @return array<int, int>
 */
function roles_repo_permission_ids(int $roleId): array
{
    return array_map('intval', array_column(
        db_all(
            'SELECT permission_id FROM role_permissions WHERE role_id = :r',
            ['r' => $roleId],
            true
        ),
        'permission_id'
    ));
}

/**
 * Le catalogue des permissions qu'une ÉCOLE peut accorder.
 *
 * LES PERMISSIONS DE PLATEFORME EN SONT EXCLUES, TOUJOURS.
 * Pas seulement parce qu'une école ne les détient pas : l'éditeur qui
 * entre dans une école cliente (phase 7B), lui, les détient. Sans cette
 * exclusion, il pourrait — par mégarde — laisser derrière lui un rôle
 * d'école portant `platform.school.erase`.
 *
 *   > Une permission qu'on n'accorde qu'à ceux qui la détiennent finit
 *   > par être accordée par celui qui passait par là.
 *
 * @return array<int, array<string, mixed>>
 */
function roles_repo_grantable_permissions(): array
{
    return db_all(
        'SELECT id, code, name, description, module
           FROM permissions
          WHERE is_platform = 0
          ORDER BY module, sort_order, code',
        [],
        true
    );
}

/** Le plus haut niveau de rôle de l'utilisateur courant. */
function roles_repo_actor_level(): int
{
    return perm_level();
}

/**
 * Combien de comptes vivants DE CETTE ÉCOLE portent ce rôle.
 *
 * LE PÉRIMÈTRE N'EST PAS UN DÉTAIL DE COMPTAGE.
 * Écrite « toutes écoles confondues », cette requête faisait deux dégâts
 * à la fois, tous deux constatés par sonde :
 *
 *  · elle touchait `users` sans lier `school_id` — le garde multi-école
 *    la refusait, et l'écran de modification comme la désactivation
 *    répondaient une page d'erreur ;
 *  · son homologue dans `roles_repo_all()`, elle, PASSAIT : la requête
 *    lie `school_id` ailleurs (`r.school_id = :school_id`), ce qui
 *    suffit au garde. Une école lisait donc, sur les rôles système, le
 *    nombre de comptes de TOUTES les écoles de la plateforme.
 *
 *   > Un garde qui s'estime satisfait par un filtre posé ailleurs ne
 *   > protège que la requête qu'il a lue.
 *
 * Le bon nombre est celui de l'école courante : c'est lui qui répond à
 * « qui perd ses accès si je désactive ce rôle ».
 */
function roles_repo_holders(int $roleId): int
{
    return (int) db_value(
        'SELECT COUNT(*) FROM user_roles ur
           JOIN users u ON u.id = ur.user_id AND u.deleted_at IS NULL
          WHERE ur.role_id = :r AND u.school_id = :school_id',
        ['r' => $roleId, 'school_id' => tenant_require()],
        true
    );
}
