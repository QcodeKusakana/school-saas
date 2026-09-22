<?php
/**
 * Module SYNC — contrôleurs.
 *
 * LES TROIS POINTS D'ENTRÉE DE L'APPAREIL RENDENT DU JSON, et passent
 * par les mêmes intergiciels que le reste : `auth`, `school`, et le
 * jeton CSRF. Un point d'entrée de synchronisation dispensé de CSRF
 * serait une porte ouverte à la falsification de requête — l'appareil
 * envoie le jeton dans un en-tête, comme le reste des appels AJAX.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';

/** Le corps JSON de la requête, ou un tableau vide. */
function sync_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function ctrl_sync_device(): void
{
    $body = sync_json_body();

    $out = sync_service_register_device(
        (string) ($body['device_uuid'] ?? ''),
        (string) ($body['label'] ?? '')
    );

    $out['ok']
        ? json_ok(['device_id' => $out['device_id']], $out['message'])
        : json_error($out['message'], 422);
}

function ctrl_sync_push(): void
{
    $body = sync_json_body();

    $out = sync_service_receive(
        (int) ($body['device_id'] ?? 0),
        (array) ($body['operations'] ?? [])
    );

    $out['ok']
        ? json_ok(['results' => $out['results']], $out['message'])
        : json_error($out['message'], 422);
}

function ctrl_sync_state(): void
{
    json_ok([
        'counts' => sync_repo_counts(),
        'server' => date('c'),
    ]);
}

function ctrl_sync_conflicts(): void
{
    view('sync/conflicts', [
        'title'       => 'Synchronisation',
        'conflicts'   => sync_repo_conflicts(),
        'devices'     => sync_repo_devices(),
        'counts'      => sync_repo_counts(),
        'statuses'    => SYNC_STATUSES,
        'resolutions' => SYNC_RESOLUTIONS,
    ]);
}

function ctrl_sync_resolve(string $id): void
{
    $out = sync_service_resolve((int) $id, (string) input('decision', ''));

    $out['ok'] ? flash_success($out['message']) : flash_error($out['message']);

    redirect('/synchronisation');
}
