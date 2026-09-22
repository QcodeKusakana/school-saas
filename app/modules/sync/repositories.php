<?php
/**
 * Module SYNC — lectures.
 *
 * Tout est filtré par `school_id` : une file d'attente est celle d'un
 * établissement, et les charges utiles contiennent des noms d'élèves.
 */

declare(strict_types=1);

/** Les états d'une opération reçue. */
const SYNC_STATUSES = [
    'pending'  => 'En attente',
    'applied'  => 'Appliquée',
    'conflict' => 'En conflit',
    'rejected' => 'Refusée',
];

/** Les issues d'un arbitrage. */
const SYNC_RESOLUTIONS = [
    'pending'     => 'À arbitrer',
    'server_wins' => 'Serveur conservé',
    'client_wins' => 'Appareil appliqué',
    'merged'      => 'Fusionné',
    'manual'      => 'Traité à la main',
];

/** Les conflits de l'établissement, les non arbitrés d'abord. */
function sync_repo_conflicts(bool $pendingOnly = false, int $limit = 100): array
{
    $where  = ['c.school_id = :school_id'];
    $params = ['school_id' => tenant_require()];

    if ($pendingOnly) {
        $where[] = 'c.resolution = \'pending\'';
    }

    return db_all(
        'SELECT c.id, c.entity_type, c.entity_id, c.server_values, c.client_values,
                c.resolution, c.resolved_at, c.created_at,
                q.client_uuid, q.payload, q.client_time, q.status AS queue_status,
                u.first_name, u.last_name, u.username,
                d.device_label
           FROM sync_conflicts c
           JOIN sync_queue q  ON q.id = c.sync_queue_id AND q.school_id = c.school_id
           LEFT JOIN users u  ON u.id = q.user_id AND u.school_id = c.school_id
           LEFT JOIN sync_devices d ON d.id = q.device_id AND d.school_id = c.school_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY c.resolution = \'pending\' DESC, c.created_at DESC
          LIMIT ' . max(1, min(500, $limit)),
        $params,
        true
    );
}

/** Le décompte, pour la pastille du menu. */
function sync_repo_counts(): array
{
    $rows = db_all(
        'SELECT status, COUNT(*) AS n FROM sync_queue
          WHERE school_id = :school_id GROUP BY status',
        ['school_id' => tenant_require()],
        true
    );

    $out = ['pending' => 0, 'applied' => 0, 'conflict' => 0, 'rejected' => 0, 'a_arbitrer' => 0];

    foreach ($rows as $r) {
        $out[(string) $r['status']] = (int) $r['n'];
    }

    $out['a_arbitrer'] = (int) db_value(
        'SELECT COUNT(*) FROM sync_conflicts
          WHERE school_id = :school_id AND resolution = \'pending\'',
        ['school_id' => tenant_require()],
        true
    );

    return $out;
}

/** Les appareils de l'établissement. */
function sync_repo_devices(int $limit = 200): array
{
    return db_all(
        'SELECT d.id, d.device_label, d.last_sync_at, d.last_seen_at, d.is_active,
                u.first_name, u.last_name, u.username,
                (SELECT COUNT(*) FROM sync_queue q
                  WHERE q.device_id = d.id AND q.school_id = d.school_id
                    AND q.status = \'conflict\') AS conflits
           FROM sync_devices d
           LEFT JOIN users u ON u.id = d.user_id AND u.school_id = d.school_id
          WHERE d.school_id = :school_id
          ORDER BY d.last_seen_at DESC
          LIMIT ' . max(1, min(500, $limit)),
        ['school_id' => tenant_require()],
        true
    );
}
