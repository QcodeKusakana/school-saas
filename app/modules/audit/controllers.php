<?php
/**
 * Module JOURNAL — contrôleurs.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';

function ctrl_audit_index(): void
{
    $filtres = [
        'action' => (string) input('action', ''),
        'entity' => (string) input('entite', ''),
        'user'   => (string) input('auteur', ''),
        'du'     => (string) input('du', ''),
        'au'     => (string) input('au', ''),
    ];

    $resultat = audit_repo_search($filtres, max(1, (int) input_int('page', 1)));

    view('audit/index', [
        'title'    => 'Journal',
        'entrees'  => $resultat['rows'],
        'total'    => $resultat['total'],
        'pages'    => $resultat['pages'],
        'page'     => $resultat['page'],
        'filtres'  => $filtres,
        'actions'  => audit_repo_actions(),
        'entites'  => audit_repo_entities(),
        'auteurs'  => audit_repo_authors(),
        'volume'   => audit_repo_volume(),
        'plancher' => AUDIT_RETENTION_FLOOR_DAYS,
    ]);
}

/**
 * LE PARAMÈTRE DE ROUTE ARRIVE EN `string`.
 *
 * Il était typé `int` ici : le routeur levait un `TypeError` et l'écran
 * répondait 500. La convention du dépôt est `string $id` puis cast —
 * `ctrl_platform_school_show()` la suit déjà.
 */
function ctrl_audit_show(string $id): void
{
    $entree = audit_repo_find((int) $id);

    if ($entree === null) {
        flash('error', 'Cette entrée de journal n\'existe pas, ou ne concerne pas votre établissement.');
        redirect('/journal');
    }

    view('audit/entry', [
        'title'  => 'Entrée de journal',
        'entree' => $entree,
    ]);
}

function ctrl_audit_purge(): void
{
    csrf_verify();

    $resultat = audit_service_purge((int) input_int('jours', 0));

    flash($resultat['ok'] ? 'success' : 'error', $resultat['message']);
    redirect('/journal');
}
