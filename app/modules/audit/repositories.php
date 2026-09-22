<?php
/**
 * Module JOURNAL — lectures.
 *
 * `audit_logs` est une table multi-école : toute lecture porte
 * `school_id`, sauf celle de l'éditeur, qui passe par `platform_scope()`.
 *
 * NOTE SUR `school_id` NULLABLE
 * =============================
 * `audit_log()` prend l'école dans `tenant_id()`, le contexte réétabli
 * depuis la base à chaque requête. Deux conséquences :
 *
 *  · une action de l'éditeur À L'INTÉRIEUR d'une école cliente porte
 *    l'identifiant de CETTE école, et apparaît donc dans son journal —
 *    ce sont ses données, elle doit pouvoir les relire ;
 *  · seules les actions de l'éditeur HORS de toute école (facturation,
 *    catalogue d'offres, gestion du parc) portent `NULL`, et celles-là
 *    n'appartiennent à aucun journal d'école.
 *
 * L'en-tête de ce fichier affirmait l'inverse — que toute action de
 * l'éditeur restait invisible à l'école. C'était vrai, et c'était le
 * défaut : le journal prenait son périmètre dans la session.
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
    //
    // ET ON VALIDE AVANT DE CALCULER.
    //
    // Le champ est un `<input type="date">`, mais rien n'oblige un client
    // à le respecter. Mesuré à l'exécution sur l'écran livré :
    //   · `au=n'importe quoi` → `strtotime()` rend `false`, `date()` le
    //     refuse en PHP 8, et la page répondait 500 ;
    //   · `du=2026-13-45` → aucune erreur, mais ZÉRO résultat : l'écran
    //     affirmait qu'il ne s'était rien passé.
    //
    //   > Un filtre qu'on n'a pas compris ne doit ni planter ni répondre
    //   > « rien » : il doit être ignoré, et le dire.
    $du = audit_date_valide($filtres['du'] ?? '');
    $au = audit_date_valide($filtres['au'] ?? '');

    if ($du !== null) {
        $where[]       = 'a.created_at >= :du';
        $params['du']  = $du . ' 00:00:00';
    }

    if ($au !== null) {
        $where[]       = 'a.created_at < :au';
        $params['au']  = date('Y-m-d', (int) strtotime($au . ' +1 day')) . ' 00:00:00';
    }
}

/**
 * Une date de filtre, ou `null` si elle n'est pas exploitable.
 *
 * On exige la forme exacte `Y-m-d` ET une date réellement existante :
 * `checkdate` refuse le 31 février, que `strtotime` accepterait en le
 * reportant au 3 mars.
 */
function audit_date_valide(mixed $valeur): ?string
{
    $texte = trim((string) $valeur);

    if ($texte === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $texte) !== 1) {
        return null;
    }

    [$a, $m, $j] = array_map('intval', explode('-', $texte));

    return checkdate($m, $j, $a) ? $texte : null;
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

    // `author_school_id` : L'AUTEUR EST-IL DE CETTE ÉCOLE ?
    //
    // Depuis que la trace prend son école dans le CONTEXTE et non dans
    // la session, une action de l'éditeur à l'intérieur d'une école
    // cliente apparaît dans le journal de cette école — c'est voulu, ce
    // sont ses données. Mais l'école doit pouvoir la distinguer de celles
    // de son propre personnel, sans quoi un nom inconnu s'affiche au
    // milieu du sien. Un compte de plateforme porte `school_id IS NULL`.
    //
    // (Cette explication était écrite DANS la chaîne SQL, en commentaire
    // `--` : dix lignes de prose envoyées à MySQL à chaque requête.)
    //
    // ON N'ORDONNE PAS PAR `created_at` SEUL.
    //
    // Plusieurs écritures partagent la même seconde — c'est courant lors
    // d'un enregistrement qui journalise deux fois. Deux pages
    // successives pourraient alors se recouvrir ou sauter une ligne.
    // `id` décroissant tranche, et suit l'ordre d'écriture réel.
    $rows = db_all(
        'SELECT a.id, a.school_id, a.user_id, a.action, a.entity_type, a.entity_id,
                a.description, a.created_at, a.ip_address,
                u.username, u.last_name, u.first_name,
                u.school_id AS author_school_id'
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
        'SELECT a.*, u.username, u.last_name, u.first_name,
                u.school_id AS author_school_id
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
