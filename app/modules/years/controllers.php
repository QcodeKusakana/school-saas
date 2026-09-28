<?php
/**
 * Module ANNÉE SCOLAIRE — contrôleurs.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';

function ctrl_years_index(): void
{
    $annees = years_repo_all();

    // Le compte rendu n'est calculé que pour l'année qu'on s'apprête à
    // clôturer : sept requêtes par année feraient une page lente pour
    // une information que personne ne lit sur les exercices d'il y a
    // cinq ans.
    $detail = (int) input_int('detail', 0);
    $contenu = null;

    if ($detail > 0 && years_repo_find($detail) !== null) {
        $contenu = years_repo_contents($detail);
    }

    view('years/index', [
        'title'   => 'Années scolaires',
        'annees'  => $annees,
        'detail'  => $detail,
        'contenu' => $contenu,
        'labels'  => YEAR_STATUS_LABELS,
    ]);
}

function ctrl_years_create(): void
{
    csrf_verify();

    $resultat = years_service_create([
        'code'      => input('code', ''),
        'name'      => input('name', ''),
        'starts_on' => input('starts_on', ''),
        'ends_on'   => input('ends_on', ''),
    ]);

    flash($resultat['ok'] ? 'success' : 'error', $resultat['message']);
    redirect('/annees');
}

function ctrl_years_update(string $id): void
{
    csrf_verify();

    $resultat = years_service_update((int) $id, [
        'code'      => input('code', ''),
        'name'      => input('name', ''),
        'starts_on' => input('starts_on', ''),
        'ends_on'   => input('ends_on', ''),
    ]);

    flash($resultat['ok'] ? 'success' : 'error', $resultat['message']);
    redirect('/annees');
}

function ctrl_years_set_current(string $id): void
{
    csrf_verify();

    $resultat = years_service_set_current((int) $id);

    flash($resultat['ok'] ? 'success' : 'error', $resultat['message']);
    redirect('/annees');
}

function ctrl_years_close(string $id): void
{
    csrf_verify();

    $resultat = years_service_close((int) $id);

    flash($resultat['ok'] ? 'success' : 'error', $resultat['message']);
    redirect('/annees');
}

function ctrl_years_reopen(string $id): void
{
    csrf_verify();

    $resultat = years_service_reopen((int) $id, (string) input('motif', ''));

    flash($resultat['ok'] ? 'success' : 'error', $resultat['message']);
    redirect('/annees');
}

function ctrl_years_delete(string $id): void
{
    csrf_verify();

    $resultat = years_service_delete((int) $id);

    flash($resultat['ok'] ? 'success' : 'error', $resultat['message']);
    redirect('/annees');
}
