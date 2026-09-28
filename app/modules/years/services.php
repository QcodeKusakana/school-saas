<?php
/**
 * Module ANNÉE SCOLAIRE — création, désignation, clôture, réouverture.
 *
 * POURQUOI CE MODULE EST UN BLOCAGE DE MISE EN SERVICE, PAS UN CONFORT
 * ====================================================================
 * Trois permissions — `academic_year.view`, `.manage`, `.close` — sont
 * semées depuis la phase 1 et n'ouvrent aucun écran. Pendant ce temps,
 * QUATORZE gardes répartis dans SEPT modules refusent d'écrire quand
 * l'année porte `closed` ou `archived` :
 *
 *     élèves · classes · enseignants · référentiel
 *     présences · bulletins · finances
 *
 * Le produit tout entier est bâti autour d'un état que rien ne permet
 * d'atteindre. Et une école ne peut pas davantage créer sa DEUXIÈME
 * année scolaire autrement qu'en base : la plateforme ne passe pas son
 * premier mois de juillet.
 *
 *   > Un état que sept modules font respecter et qu'aucun écran ne sait
 *   > poser n'est pas une protection : c'est une impasse.
 *
 * CE QUE CLÔTURER VEUT DIRE, ET CE QUE CELA NE VEUT PAS DIRE
 * ===========================================================
 * Clôturer FIGE L'ÉCRITURE, jamais la lecture. Les bulletins restent
 * consultables, les documents délivrables, le parcours de l'élève
 * intact. Rien n'est supprimé, rien n'est déplacé.
 *
 * L'échappatoire est la RÉOUVERTURE EXPLICITE, journalisée — jamais une
 * exception silencieuse dans l'un des sept modules. Le module Finances
 * l'exigeait déjà par écrit, avant même que cet écran existe.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * Les états d'une année, et ce qu'ils autorisent.
 *
 * `archived` existe dans l'énumération et n'est posé par aucun chemin :
 * il est traité comme `closed` par les quatorze gardes. On ne l'expose
 * pas tant qu'il ne se distingue de `closed` par rien de concret —
 * offrir deux boutons pour un seul comportement ne ferait qu'ajouter une
 * question sans réponse.
 */
const YEAR_STATUS_LABELS = [
    'draft'    => 'Préparation',
    'active'   => 'En cours',
    'closed'   => 'Clôturée',
    'archived' => 'Archivée',
];

/**
 * Crée une année scolaire.
 *
 * @param array<string, mixed> $input
 * @return array{ok: bool, message: string, id?: int}
 */
function years_service_create(array $input): array
{
    if (!can('academic_year.manage')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de créer une année scolaire.'];
    }

    $valide = years_validate($input, null);

    if ($valide['erreurs'] !== []) {
        return ['ok' => false, 'message' => implode(' ', $valide['erreurs'])];
    }

    $courante = years_repo_current();

    try {
        $id = db_transaction(static function () use ($valide, $courante): int {
            // UNE ÉCOLE SANS AUCUNE ANNÉE COURANTE N'A PLUS D'ÉCRAN.
            //
            // Douze endroits du produit lisent `is_current = 1`. La
            // toute première année créée devient donc courante d'office :
            // sans elle, l'école ouvrirait un compte sur un produit muet.
            $premiere = $courante === null;

            return tenant_insert('academic_years', [
                'code'       => $valide['code'],
                'name'       => $valide['name'],
                'starts_on'  => $valide['starts_on'],
                'ends_on'    => $valide['ends_on'],
                'status'     => $premiere ? 'active' : 'draft',
                // `is_current` VAUT NULL, JAMAIS ZÉRO.
                //
                // `uq_year_current (school_id, is_current)` est UNIQUE.
                // En MySQL, NULL n'entre pas en collision ; `0` si.
                // Mesuré : deux années passées portant `0` — la seconde
                // était REFUSÉE. Une école n'aurait pas pu enregistrer
                // son troisième exercice.
                'is_current' => $premiere ? 1 : null,
            ]);
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'message' => 'Une année portant ce code existe déjà.'];
        }

        throw $e;
    }

    audit_log('academic_year.created', 'academic_years', $id, null,
        ['code' => $valide['code']], 'Année scolaire créée');

    return ['ok' => true, 'id' => $id, 'message' => 'Année ' . $valide['code'] . ' créée.'];
}

/**
 * Modifie une année.
 *
 * @param array<string, mixed> $input
 * @return array{ok: bool, message: string}
 */
function years_service_update(int $id, array $input): array
{
    if (!can('academic_year.manage')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de modifier une année scolaire.'];
    }

    $annee = years_repo_find($id);

    if ($annee === null) {
        return ['ok' => false, 'message' => 'Cette année n\'existe pas.'];
    }

    // Une année clôturée ne se modifie pas : ce serait contourner la
    // clôture par la porte de derrière.
    if (in_array((string) $annee['status'], ['closed', 'archived'], true)) {
        return [
            'ok'      => false,
            'message' => 'Cette année est clôturée. Rouvrez-la d\'abord si vous devez la corriger.',
        ];
    }

    $valide = years_validate($input, $id);

    if ($valide['erreurs'] !== []) {
        return ['ok' => false, 'message' => implode(' ', $valide['erreurs'])];
    }

    tenant_update('academic_years', [
        'code'      => $valide['code'],
        'name'      => $valide['name'],
        'starts_on' => $valide['starts_on'],
        'ends_on'   => $valide['ends_on'],
    ], 'id = :id', ['id' => $id]);

    audit_update('academic_years', $id,
        ['code' => $annee['code'], 'starts_on' => $annee['starts_on'], 'ends_on' => $annee['ends_on']],
        ['code' => $valide['code'], 'starts_on' => $valide['starts_on'], 'ends_on' => $valide['ends_on']],
        'Année scolaire modifiée');

    return ['ok' => true, 'message' => 'Année ' . $valide['code'] . ' mise à jour.'];
}

/**
 * Désigne l'année courante.
 *
 * @return array{ok: bool, message: string}
 */
function years_service_set_current(int $id): array
{
    if (!can('academic_year.manage')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de changer l\'année courante.'];
    }

    $annee = years_repo_find($id);

    if ($annee === null) {
        return ['ok' => false, 'message' => 'Cette année n\'existe pas.'];
    }

    if (in_array((string) $annee['status'], ['closed', 'archived'], true)) {
        return [
            'ok'      => false,
            'message' => 'Une année clôturée ne peut pas redevenir l\'année courante.',
        ];
    }

    if ((int) $annee['is_current'] === 1) {
        return ['ok' => true, 'message' => 'Cette année est déjà l\'année courante.'];
    }

    db_transaction(static function () use ($id): void {
        // L'ORDRE COMPTE, ET L'INDEX L'IMPOSE.
        //
        // `uq_year_current` interdit deux années courantes. Poser la
        // nouvelle avant de libérer l'ancienne échouerait. On libère
        // d'abord — dans la MÊME transaction, pour qu'aucune requête
        // concurrente ne trouve l'école sans année courante.
        tenant_update('academic_years', ['is_current' => null], 'is_current = 1');
        tenant_update('academic_years', ['is_current' => 1, 'status' => 'active'],
            'id = :id', ['id' => $id]);
    });

    audit_log('academic_year.set_current', 'academic_years', $id, null,
        ['code' => $annee['code']], 'Année courante désignée');

    return ['ok' => true, 'message' => 'L\'année ' . $annee['code'] . ' est désormais l\'année courante.'];
}

/**
 * Clôture une année.
 *
 * @return array{ok: bool, message: string}
 */
function years_service_close(int $id): array
{
    if (!can('academic_year.close')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de clôturer une année scolaire.'];
    }

    $annee = years_repo_find($id);

    if ($annee === null) {
        return ['ok' => false, 'message' => 'Cette année n\'existe pas.'];
    }

    if (in_array((string) $annee['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'message' => 'Cette année est déjà clôturée.'];
    }

    // LE SEUL REFUS DE CE MODULE, ET IL PROTÈGE TOUT LE PRODUIT.
    //
    // Douze endroits lisent `is_current = 1`. Clôturer l'année courante
    // sans lui désigner de remplaçante laisserait l'école devant des
    // écrans vides : ni inscription, ni classe, ni encaissement.
    //
    //   > On ne retire pas le sol sous les pieds d'une école au motif
    //   > que l'année est finie.
    //
    // L'ordre naturel est celui-ci : créer l'année suivante, la désigner
    // courante, puis clôturer la précédente.
    if ((int) $annee['is_current'] === 1) {
        return [
            'ok'      => false,
            'message' => 'L\'année ' . $annee['code'] . ' est l\'année courante. '
                . 'Créez l\'année suivante et désignez-la comme courante avant de clôturer '
                . 'celle-ci — sans année courante, l\'établissement n\'a plus d\'écran de travail.',
        ];
    }

    $contenu = years_repo_contents($id);

    db_transaction(static function () use ($id): void {
        tenant_update('academic_years', [
            'status'     => 'closed',
            'is_current' => null,
            'closed_at'  => now(),
            'closed_by'  => auth_id(),
        ], 'id = :id', ['id' => $id]);
    });

    // LE COMPTE RENDU ENTRE DANS LA TRACE.
    //
    // Ce qui était inachevé au moment de la signature doit rester
    // lisible des années plus tard : c'est la seule façon d'expliquer
    // un bulletin sans décision retrouvé dans un dossier.
    audit_log('academic_year.closed', 'academic_years', $id, null,
        ['code' => $annee['code']] + $contenu, 'Année scolaire clôturée');

    return [
        'ok'      => true,
        'message' => 'Année ' . $annee['code'] . ' clôturée. '
            . 'Les écritures y sont désormais refusées ; la consultation reste ouverte.',
    ];
}

/**
 * Rouvre une année clôturée.
 *
 * @return array{ok: bool, message: string}
 */
function years_service_reopen(int $id, string $motif): array
{
    if (!can('academic_year.close')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de rouvrir une année scolaire.'];
    }

    $annee = years_repo_find($id);

    if ($annee === null) {
        return ['ok' => false, 'message' => 'Cette année n\'existe pas.'];
    }

    if (!in_array((string) $annee['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'message' => 'Cette année n\'est pas clôturée.'];
    }

    // UN MOTIF EST EXIGÉ, ET CE N'EST PAS UNE FORMALITÉ.
    //
    // Rouvrir une année rend de nouveau modifiables des bulletins signés
    // et des écritures comptables arrêtées. Sans motif, la trace dirait
    // seulement QUE c'est arrivé ; l'école a besoin de savoir POURQUOI.
    $motif = trim($motif);

    if (mb_strlen($motif) < 10) {
        return [
            'ok'      => false,
            'message' => 'Indiquez le motif de la réouverture (10 caractères au minimum). '
                . 'Il reste inscrit au journal.',
        ];
    }

    tenant_update('academic_years', [
        'status'    => 'active',
        'closed_at' => null,
        'closed_by' => null,
    ], 'id = :id', ['id' => $id]);

    audit_log('academic_year.reopened', 'academic_years', $id,
        ['status' => 'closed'],
        ['status' => 'active', 'motif' => mb_substr($motif, 0, 300)],
        'Année scolaire rouverte');

    return [
        'ok'      => true,
        'message' => 'Année ' . $annee['code'] . ' rouverte. Les écritures y sont de nouveau '
            . 'possibles, et la réouverture est inscrite au journal.',
    ];
}

/**
 * Les tables qui disparaîtraient avec une année.
 *
 * NEUF D'ENTRE ELLES SONT EN `ON DELETE CASCADE`.
 * Supprimer une année effacerait donc, sans un mot, ses inscriptions,
 * ses classes, ses programmes, ses frais, ses dépenses, ses périodes,
 * ses orientations et ses affectations d'enseignants. Seuls les
 * documents délivrés sont protégés par un `RESTRICT`.
 *
 *   > Une suppression qu'on confie à la base efface ce que la base sait
 *   > relier, pas ce que l'école accepterait de perdre.
 *
 * Le refus est donc posé ICI, table par table, et il NOMME ce qui
 * bloque. La cascade n'est plus qu'un filet de sécurité qu'on n'atteint
 * jamais.
 */
const YEAR_BLOCKING_TABLES = [
    'enrollments'      => 'inscription(s)',
    'classrooms'       => 'classe(s)',
    'curriculums'      => 'programme(s)',
    'grade_periods'    => 'période(s) d\'évaluation',
    'fees'             => 'frais',
    'expenses'         => 'dépense(s)',
    'orientations'     => 'orientation(s)',
    'teacher_subjects' => 'affectation(s) d\'enseignant',
    'documents'        => 'document(s) délivré(s)',
];

/**
 * Supprime une année VIDE.
 *
 * Une année créée par erreur — une faute de frappe dans le code, des
 * dates inversées — devait rester là pour toujours : rien ne permettait
 * de la retirer. Trouvé parce que la recette navigateur mentait à son
 * second passage, faute de pouvoir nettoyer ce qu'elle avait créé.
 *
 * @return array{ok: bool, message: string}
 */
function years_service_delete(int $id): array
{
    if (!can('academic_year.manage')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de supprimer une année scolaire.'];
    }

    $annee = years_repo_find($id);

    if ($annee === null) {
        return ['ok' => false, 'message' => 'Cette année n\'existe pas.'];
    }

    if ((int) $annee['is_current'] === 1) {
        return [
            'ok'      => false,
            'message' => 'L\'année courante ne peut pas être supprimée. '
                . 'Désignez d\'abord une autre année courante.',
        ];
    }

    if (in_array((string) $annee['status'], ['closed', 'archived'], true)) {
        return [
            'ok'      => false,
            'message' => 'Une année clôturée ne se supprime pas : elle est la mémoire '
                . 'd\'un exercice achevé.',
        ];
    }

    $occupee = years_repo_blocking($id);

    if ($occupee !== []) {
        $details = [];

        foreach ($occupee as $table => $nombre) {
            $details[] = $nombre . ' ' . YEAR_BLOCKING_TABLES[$table];
        }

        return [
            'ok'      => false,
            'message' => 'Cette année n\'est pas vide : elle porte ' . implode(', ', $details)
                . '. Une année qui contient des données ne se supprime pas — '
                . 'clôturez-la plutôt.',
        ];
    }

    // La trace est écrite AVANT la suppression : après, la ligne
    // n'existe plus, et l'on ne saurait plus dire ce qui a disparu.
    audit_log('academic_year.deleted', 'academic_years', $id,
        ['code' => $annee['code'], 'name' => $annee['name'],
         'starts_on' => $annee['starts_on'], 'ends_on' => $annee['ends_on']],
        null, 'Année scolaire vide supprimée');

    tenant_delete('academic_years', 'id = :id', ['id' => $id]);

    return ['ok' => true, 'message' => 'Année ' . $annee['code'] . ' supprimée.'];
}

/**
 * Valide les champs d'une année.
 *
 * @param array<string, mixed> $input
 * @return array{erreurs: array<int, string>, code: string, name: string, starts_on: string, ends_on: string}
 */
function years_validate(array $input, ?int $exceptId): array
{
    $erreurs = [];

    $code  = trim((string) ($input['code'] ?? ''));
    $name  = trim((string) ($input['name'] ?? ''));
    $debut = date_filtre($input['starts_on'] ?? '');
    $fin   = date_filtre($input['ends_on'] ?? '');

    if ($code === '' || mb_strlen($code) > 20) {
        $erreurs[] = 'Le code de l\'année est obligatoire (20 caractères au maximum).';
    }

    if ($name === '' || mb_strlen($name) > 100) {
        $erreurs[] = 'Le libellé est obligatoire (100 caractères au maximum).';
    }

    if ($debut === null || $fin === null) {
        $erreurs[] = 'Les dates de début et de fin sont obligatoires, au format jour/mois/année.';
    } elseif ($debut >= $fin) {
        $erreurs[] = 'La date de fin doit suivre la date de début.';
    }

    // LE CHEVAUCHEMENT EST REFUSÉ.
    //
    // Deux années qui se recouvrent rendraient ambigu le rattachement
    // d'une inscription, d'une note ou d'un encaissement — et aucun
    // écran ne saurait dire à laquelle une séance du 15 septembre
    // appartient.
    if ($debut !== null && $fin !== null) {
        $params = [
            'school_id' => tenant_require(),
            'debut'     => $debut,
            'fin'       => $fin,
            'except'    => $exceptId ?? 0,
        ];

        $chevauche = db_one(
            'SELECT code FROM academic_years
              WHERE school_id = :school_id AND id <> :except
                AND starts_on <= :fin AND ends_on >= :debut
              LIMIT 1',
            $params,
            true
        );

        if ($chevauche !== null) {
            $erreurs[] = 'Cette période chevauche l\'année ' . (string) $chevauche['code'] . '.';
        }
    }

    return [
        'erreurs'   => $erreurs,
        'code'      => $code,
        'name'      => $name,
        'starts_on' => (string) $debut,
        'ends_on'   => (string) $fin,
    ];
}
