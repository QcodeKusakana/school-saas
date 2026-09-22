<?php
/**
 * Module JOURNAL — lectures.
 *
 * `audit_logs` est une table multi-école : toute lecture porte
 * `school_id`, sauf celle de l'éditeur, qui passe par `platform_scope()`.
 *
 * NOTE SUR `school_id` NULLABLE
 * =============================
 * Les actions de plateforme (visite d'une école par l'éditeur,
 * facturation) s'écrivent avec `school_id = NULL`. Une école filtrant
 * `school_id = :id` ne les voit donc pas — ce qui est voulu : ce sont
 * des actions de l'éditeur, pas de l'école.
 */

declare(strict_types=1);

/**
 * Les entrées du journal de l'école courante.
 *
 * @param array<string, mixed> $filtres
 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int}
 */
function audit_repo_search(array $filtres, int $page = 1, int $parPage = 50): array
{
    $where  = ['a.school_id = :school_id'];
    $params = ['school_id' => tenant_require()];

    audit_repo_apply_filters($filtres, $where, $params);

    return audit_repo_run($where, $params, $page, $parPage, false);
}

/**
 * Le journal de TOUT le parc — éditeur uniquement.
 *
 * L'appelant doit avoir ouvert `platform_scope()` : le garde-fou le
 * vérifie, ce n'est pas à cette fonction de le promettre.
 *
 * @param array<string, mixed> $filtres
 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int}
 */
function audit_repo_search_platform(array $filtres, int $page = 1, int $parPage = 50): array
{
    $where  = ['1 = 1'];
    $params = [];

    if (!empty($filtres['school'])) {
        $where[]            = 'a.school_id = :ecole';
        $params['ecole']    = (int) $filtres['school'];
    }

    audit_repo_apply_filters($filtres, $where, $params);

    return audit_repo_run($where, $params, $page, $parPage, true);
}

/**
 * Les filtres communs aux deux journaux.
 *
 * @param array<string, mixed>  $filtres
 * @param array<int, string>    $where
 * @param array<string, mixed>  $params
 */
function audit_repo_apply_filters(array $filtres, array &$where, array &$params): void
{
    if (!empty($filtres['action'])) {
        $where[]           = 'a.action = :action';
        $params['action']  = (string) $filtres['action'];
    }

    if (!empty($filtres['entity'])) {
        $where[]           = 'a.entity_type = :entity';
        $params['entity']  = (string) $filtres['entity'];
    }

    if (!empty($filtres['user'])) {
        $where[]           = 'a.user_id = :user';
        $params['user']    = (int) $filtres['user'];
    }

    // LES BORNES SONT INCLUSIVES DES DEUX CÔTÉS.
    //
    // `created_at` est un `datetime`. Comparer `<= '2026-09-22'` exclut
    // toute la journée du 22, qui vaut `2026-09-22 00:00:00`. On borne
    // donc au lendemain, strictement.
    if (!empty($filtres['du'])) {
        $where[]        = 'a.created_at >= :du';
        $params['du']   = (string) $filtres['du'] . ' 00:00:00';
    }

    if (!empty($filtres['au'])) {
        $where[]        = 'a.created_at < :au';
        $params['au']   = date('Y-m-d', strtotime((string) $filtres['au'] . ' +1 day')) . ' 00:00:00';
    }
}

/**
 * @param array<int, string>   $where
 * @param array<string, mixed> $params
 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int}
 */
function audit_repo_run(array $where, array $params, int $page, int $parPage, bool $platform): array
{
    $from = 'FROM audit_logs a WHERE ' . implode(' AND ', $where);

    $total  = (int) db_value('SELECT COUNT(*) ' . $from, $params, true);
    $pages  = max(1, (int) ceil($total / $parPage));
    $page   = min(max(1, $page), $pages);
    $offset = ($page - 1) * $parPage;

    // ON N'ORDONNE PAS PAR `created_at` SEUL.
    //
    // Plusieurs écritures partagent la même seconde — c'est courant lors
    // d'un enregistrement qui journalise deux fois. Deux pages
    // successives pourraient alors se recouvrir ou sauter une ligne.
    // `id` décroissant tranche, et suit l'ordre d'écriture réel.
    $rows = db_all(
        'SELECT a.id, a.school_id, a.user_id, a.action, a.entity_type, a.entity_id,
                a.description, a.created_at, a.ip_address,
                u.username, u.last_name, u.first_name'
        . ($platform ? ', s.name AS school_name, s.code AS school_code' : '') . '
           FROM audit_logs a
           LEFT JOIN users u ON u.id = a.user_id'
        . ($platform ? ' LEFT JOIN schools s ON s.id = a.school_id' : '') . '
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY a.id DESC
          LIMIT ' . (int) $parPage . ' OFFSET ' . (int) $offset,
        $params,
        true
    );

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/**
 * Une entrée complète, valeurs comprises.
 *
 * @return array<string, mixed>|null
 */
function audit_repo_find(int $id): ?array
{
    $ligne = db_one(
        'SELECT a.*, u.username, u.last_name, u.first_name
           FROM audit_logs a
           LEFT JOIN users u ON u.id = a.user_id
          WHERE a.id = :id AND a.school_id = :school_id
          LIMIT 1',
        ['id' => $id, 'school_id' => tenant_require()],
        true
    );

    if ($ligne === null) {
        return null;
    }

    $ligne['old'] = $ligne['old_values'] !== null
        ? (array) json_decode((string) $ligne['old_values'], true) : [];
    $ligne['new'] = $ligne['new_values'] !== null
        ? (array) json_decode((string) $ligne['new_values'], true) : [];

    return $ligne;
}

/**
 * Les actions présentes dans le journal de l'école, pour le filtre.
 *
 * On les lit plutôt que de les déclarer : une action ajoutée par un
 * module futur apparaît sans qu'on ait à tenir une liste à jour.
 *
 *   > Une liste déroulante tenue à la main finit toujours par mentir
 *   > sur ce que contient la table.
 *
 * @return array<int, string>
 */
function audit_repo_actions(): array
{
    return array_map(
        static fn (array $r): string => (string) $r['action'],
        db_all(
            'SELECT DISTINCT action FROM audit_logs
              WHERE school_id = :school_id ORDER BY action',
            ['school_id' => tenant_require()],
            true
        )
    );
}

/** @return array<int, string> */
function audit_repo_entities(): array
{
    return array_map(
        static fn (array $r): string => (string) $r['entity_type'],
        db_all(
            'SELECT DISTINCT entity_type FROM audit_logs
              WHERE school_id = :school_id AND entity_type IS NOT NULL
              ORDER BY entity_type',
            ['school_id' => tenant_require()],
            true
        )
    );
}

/**
 * Les auteurs présents dans le journal, pour le filtre.
 *
 * @return array<int, array<string, mixed>>
 */
function audit_repo_authors(): array
{
    return db_all(
        'SELECT DISTINCT u.id, u.username, u.last_name, u.first_name
           FROM audit_logs a
           JOIN users u ON u.id = a.user_id
          WHERE a.school_id = :school_id
          ORDER BY u.last_name, u.first_name',
        ['school_id' => tenant_require()],
        true
    );
}

/**
 * La plus ancienne entrée et le volume — pour l'écran de rétention.
 *
 * @return array{total: int, oldest: string|null}
 */
function audit_repo_volume(): array
{
    $r = db_one(
        'SELECT COUNT(*) AS total, MIN(created_at) AS oldest
           FROM audit_logs WHERE school_id = :school_id',
        ['school_id' => tenant_require()],
        true
    );

    return [
        'total'  => (int) ($r['total'] ?? 0),
        'oldest' => $r['oldest'] !== null ? (string) $r['oldest'] : null,
    ];
}
