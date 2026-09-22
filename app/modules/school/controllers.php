<?php
/**
 * Module ÉTABLISSEMENT — contrôleurs.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';

/** L'écran « Mon établissement ». */
function ctrl_school_settings_form(): void
{
    view('school/settings', [
        'title'      => 'Mon établissement',
        'ecole'      => school_repo_current(),
        'reglages'   => school_repo_print_settings(),
        'champs'     => SCHOOL_PRINT_SETTINGS,
    ]);
}

/** Enregistre les informations imprimables. */
function ctrl_school_settings_save(): void
{
    $out = school_service_save_settings($_POST);

    $out['ok'] ? flash_success($out['message']) : flash_error($out['message']);

    redirect('/ecole/parametres');
}

/** Enregistre le logo. */
function ctrl_school_set_logo(): void
{
    $out = school_service_set_logo(input_file('logo') ?? []);

    $out['ok'] ? flash_success($out['message']) : flash_error($out['message']);

    redirect('/ecole/parametres');
}

/** Retire le logo. */
function ctrl_school_remove_logo(): void
{
    $out = school_service_remove_logo();

    $out['ok'] ? flash_success($out['message']) : flash_error($out['message']);

    redirect('/ecole/parametres');
}

/**
 * Sert le logo — derrière l'authentification, comme la photo d'élève.
 *
 * Deux chemins de service voudraient dire deux jeux de règles.
 */
function ctrl_school_logo(): void
{
    $out = school_service_logo_file();

    if (!$out['ok']) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $out['mime']);
    header('Content-Length: ' . (string) filesize($out['path']));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    readfile($out['path']);
    exit;
}
