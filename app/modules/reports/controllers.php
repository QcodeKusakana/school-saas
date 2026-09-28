<?php
/**
 * Module RAPPORTS — contrôleurs.
 *
 * Chaque rapport a deux sorties : l'écran, et le CSV. Elles partent des
 * MÊMES lignes, produites par la même fonction du dépôt — sans quoi le
 * fichier et l'écran finiraient par diverger sans que personne ne le
 * remarque.
 *
 *   > Un export qui recalcule ce que l'écran a déjà calculé finit par
 *   > dire autre chose que lui.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';

/**
 * L'année demandée, validée.
 *
 * Un identifiant d'année venu de la requête ne vaut rien tant qu'on n'a
 * pas vérifié qu'il désigne une année DE CETTE ÉCOLE.
 *
 * @param array<int, array<string, mixed>> $annees
 * @return array<string, mixed>|null
 */
function reports_year_from_request(array $annees): ?array
{
    $demande = (int) input_int('annee', 0);

    foreach ($annees as $annee) {
        if ((int) $annee['id'] === $demande) {
            return $annee;
        }
    }

    return reports_repo_default_year();
}

// ---------------------------------------------------------------------
//  EFFECTIFS
// ---------------------------------------------------------------------

function ctrl_reports_headcount(): void
{
    $annees = reports_repo_years();
    $annee  = reports_year_from_request($annees);

    if ($annee === null) {
        flash('error', 'Aucune année scolaire n\'est enregistrée : créez-en une avant de demander des rapports.');
        redirect('/tableau-de-bord');
    }

    $lignes = reports_repo_headcount((int) $annee['id']);

    if (input('export') === 'csv') {
        reports_require_export();
        reports_export_headcount($lignes, (string) $annee['code']);
    }

    view('reports/headcount', [
        'title'  => 'Effectifs',
        'annees' => $annees,
        'annee'  => $annee,
        'lignes' => $lignes,
        'total'  => reports_total($lignes, ['garcons', 'filles', 'effectif', 'partis', 'redoublants', 'capacity']),
    ]);
}

/** @param array<int, array<string, mixed>> $lignes */
function reports_export_headcount(array $lignes, string $anneeCode): never
{
    $csv = reports_build_csv(
        ['Cycle', 'Niveau', 'Classe', 'Garçons', 'Filles', 'Effectif',
         'Partis', 'Redoublants', 'Capacité', 'Remplissage %'],
        array_map(
            static fn (array $l): array => [
                (string) ($l['cycle_name'] ?? ''),
                (string) ($l['level_name'] ?? ''),
                (string) $l['name'],
                (string) $l['garcons'],
                (string) $l['filles'],
                (string) $l['effectif'],
                (string) $l['partis'],
                (string) $l['redoublants'],
                (string) ($l['capacity'] ?? ''),
                reports_csv_number(reports_rate((int) $l['effectif'], (int) ($l['capacity'] ?? 0))),
            ],
            $lignes
        )
    );

    reports_send_csv('effectifs-' . $anneeCode, $csv, ['annee' => $anneeCode, 'lignes' => count($lignes)]);
}

// ---------------------------------------------------------------------
//  RÉSULTATS
// ---------------------------------------------------------------------

function ctrl_reports_results(): void
{
    $annees = reports_repo_years();
    $annee  = reports_year_from_request($annees);

    if ($annee === null) {
        flash('error', 'Aucune année scolaire n\'est enregistrée.');
        redirect('/tableau-de-bord');
    }

    $periodes = reports_repo_period_keys((int) $annee['id']);
    $demandee = (string) input('periode', '');
    $periode  = in_array($demandee, $periodes, true) ? $demandee : ($periodes[0] ?? '');

    $lignes = $periode === '' ? [] : reports_repo_results((int) $annee['id'], $periode);

    if (input('export') === 'csv' && $lignes !== []) {
        reports_require_export();
        reports_export_results($lignes, (string) $annee['code'], $periode);
    }

    view('reports/results', [
        'title'    => 'Résultats',
        'annees'   => $annees,
        'annee'    => $annee,
        'periodes' => $periodes,
        'periode'  => $periode,
        'lignes'   => $lignes,
        'total'    => reports_total($lignes, [
            'bulletins', 'decides', 'sans_decision',
            'reussites', 'echecs', 'conditionnels', 'cotes_manquantes',
        ]),
    ]);
}

/** @param array<int, array<string, mixed>> $lignes */
function reports_export_results(array $lignes, string $anneeCode, string $periode): never
{
    $csv = reports_build_csv(
        ['Niveau', 'Classe', 'Bulletins', 'Moyenne %', 'Mini %', 'Maxi %',
         'Décidés', 'Sans décision', 'Réussites', 'Échecs', 'Conditionnels',
         'Taux de réussite %', 'Cotes manquantes'],
        array_map(
            static fn (array $l): array => [
                (string) ($l['level_name'] ?? ''),
                (string) $l['name'],
                (string) $l['bulletins'],
                reports_csv_number($l['moyenne']),
                reports_csv_number($l['mini']),
                reports_csv_number($l['maxi']),
                (string) $l['decides'],
                (string) $l['sans_decision'],
                (string) $l['reussites'],
                (string) $l['echecs'],
                (string) $l['conditionnels'],
                reports_csv_number(reports_rate((int) $l['reussites'], (int) $l['decides'])),
                (string) $l['cotes_manquantes'],
            ],
            $lignes
        )
    );

    reports_send_csv('resultats-' . $anneeCode . '-' . $periode, $csv,
        ['annee' => $anneeCode, 'periode' => $periode, 'lignes' => count($lignes)]);
}

// ---------------------------------------------------------------------
//  ASSIDUITÉ
// ---------------------------------------------------------------------

function ctrl_reports_attendance(): void
{
    $annees = reports_repo_years();
    $annee  = reports_year_from_request($annees);

    if ($annee === null) {
        flash('error', 'Aucune année scolaire n\'est enregistrée.');
        redirect('/tableau-de-bord');
    }

    $plage = reports_repo_attendance_span((int) $annee['id']);

    // La même validation que le journal : une date qu'on ne comprend pas
    // est ignorée, jamais appliquée de travers.
    $du = date_filtre(input('du', '')) ?? $plage['first'];
    $au = date_filtre(input('au', '')) ?? $plage['last'];

    $lignes = reports_repo_attendance((int) $annee['id'], $du, $au);

    if (input('export') === 'csv') {
        reports_require_export();
        reports_export_attendance($lignes, (string) $annee['code'], $du, $au);
    }

    view('reports/attendance', [
        'title'  => 'Assiduité',
        'annees' => $annees,
        'annee'  => $annee,
        'du'     => $du,
        'au'     => $au,
        'plage'  => $plage,
        'lignes' => $lignes,
        'total'  => reports_total($lignes, ['seances', 'pointages', 'presences', 'absences', 'retards', 'justifiees']),
    ]);
}

/** @param array<int, array<string, mixed>> $lignes */
function reports_export_attendance(array $lignes, string $anneeCode, ?string $du, ?string $au): never
{
    $csv = reports_build_csv(
        ['Niveau', 'Classe', 'Séances', 'Pointages', 'Présences', 'Absences',
         'Retards', 'Absences justifiées', 'Taux de présence %'],
        array_map(
            static fn (array $l): array => [
                (string) ($l['level_name'] ?? ''),
                (string) $l['name'],
                (string) $l['seances'],
                (string) $l['pointages'],
                (string) $l['presences'],
                (string) $l['absences'],
                (string) $l['retards'],
                (string) $l['justifiees'],
                reports_csv_number(reports_rate(
                    (int) $l['presences'] + (int) $l['retards'],
                    (int) $l['pointages']
                )),
            ],
            $lignes
        )
    );

    reports_send_csv('assiduite-' . $anneeCode, $csv,
        ['annee' => $anneeCode, 'du' => (string) $du, 'au' => (string) $au, 'lignes' => count($lignes)]);
}

// ---------------------------------------------------------------------
//  COMMUN
// ---------------------------------------------------------------------

/**
 * L'export porte sa PROPRE permission.
 *
 * Consulter un rapport à l'écran et en sortir un fichier ne sont pas le
 * même geste : le fichier quitte le produit. `report.export` existe
 * depuis la phase 1 pour cette raison ; elle dormait.
 */
function reports_require_export(): void
{
    if (!can('report.export')) {
        abort(403, 'Vous pouvez consulter ce rapport, mais pas l\'exporter.');
    }
}

