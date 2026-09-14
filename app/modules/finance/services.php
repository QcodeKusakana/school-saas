<?php
/**
 * Module FINANCE — règles métier de la grille tarifaire et des dettes.
 *
 * Ce fichier ne connaît pas encore les encaissements (phase 5B). Il
 * répond à une seule question : QUE DOIT CET ÉLÈVE, et pourquoi.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';
require_once APP_PATH . '/modules/students/repositories.php';

/**
 * Devises acceptées.
 *
 * Volontairement fermée. Une liste ouverte laisserait saisir « usd »,
 * « $ » ou « Usd » et produirait trois soldes distincts pour la même
 * monnaie — une erreur silencieuse et à peu près impossible à rattraper
 * une fois des centaines de lignes enregistrées.
 */
const FINANCE_CURRENCIES = [
    'USD' => 'Dollar américain (USD)',
    'CDF' => 'Franc congolais (CDF)',
];

const FINANCE_SCOPES = [
    'school'    => 'Toute l\'école',
    'level'     => 'Un niveau',
    'classroom' => 'Une classe',
];

/** Nombre de décimales d'affichage et d'arrondi, par devise. */
function finance_decimals(string $currency): int
{
    // Le franc congolais ne se compte pas en centimes dans la pratique
    // scolaire : un reçu de 45 000,37 FC n'a aucun sens au guichet.
    return $currency === 'CDF' ? 0 : 2;
}

/** Montant formaté avec sa devise — jamais un nombre nu. */
function finance_amount(float $amount, string $currency): string
{
    return number_format($amount, finance_decimals($currency), ',', ' ') . ' ' . $currency;
}

/** Devise par défaut de l'établissement. */
function finance_default_currency(): string
{
    $currency = strtoupper((string) school_setting('finance.currency', 'USD'));

    return isset(FINANCE_CURRENCIES[$currency]) ? $currency : 'USD';
}

// =====================================================================
//  PÉRIMÈTRE
//
//  finance.view est accordée à PARENT depuis la phase 1. La permission
//  ouvre la porte ; elle ne dit pas sur quel dossier. Sans cette couche,
//  un parent lirait la situation financière de toutes les familles de
//  l'établissement — la fuite exacte corrigée aux phases 3, 4B et 4D.
// =====================================================================

/**
 * L'utilisateur courant peut-il consulter la situation de CETTE
 * inscription ?
 *
 * S'appuie sur le périmètre élève, source unique du projet. Le
 * redéfinir ici créerait une seconde définition, qui finirait par
 * diverger de la première.
 */
function finance_can_view_enrollment(int $enrollmentId): bool
{
    [$scope, $scopeParams] = students_scope_clause();

    return db_exists(
        'SELECT 1
           FROM enrollments e
           JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
          WHERE e.id = :enrollment_id
            AND e.school_id = :school_id
            AND s.deleted_at IS NULL
            AND ' . $scope . '
          LIMIT 1',
        $scopeParams + [
            'enrollment_id' => $enrollmentId,
            'school_id'     => tenant_require(),
        ]
    );
}

// =====================================================================
//  LA GRILLE TARIFAIRE
// =====================================================================

/**
 * Crée ou met à jour une ligne de la grille.
 *
 * MODIFIER UN TARIF NE MODIFIE AUCUNE DETTE DÉJÀ AFFECTÉE.
 * C'est délibéré : le montant annoncé à une famille en septembre ne
 * doit pas pouvoir être réécrit en janvier. La correction d'une erreur
 * de saisie passe par une action séparée et explicite
 * (finance_service_resync_fee), qui dit combien de dettes elle change.
 *
 * @return array{ok: bool, message: string, id?: int}
 */
function finance_service_save_fee(array $input, ?int $feeId = null): array
{
    $schoolId = tenant_require();
    $yearId   = (int) ($input['academic_year_id'] ?? 0);
    $code     = strtoupper(trim((string) ($input['code'] ?? '')));
    $name     = trim((string) ($input['name'] ?? ''));
    $currency = strtoupper((string) ($input['currency'] ?? finance_default_currency()));
    $scope    = (string) ($input['scope'] ?? 'school');
    $amount   = (float) str_replace([' ', ','], ['', '.'], (string) ($input['amount'] ?? '0'));

    if ($name === '') {
        return ['ok' => false, 'message' => 'Le libellé du frais est obligatoire.'];
    }

    if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,30}$/', $code)) {
        return ['ok' => false, 'message' => 'Le code doit comporter 2 à 30 lettres, chiffres, tiret ou souligné.'];
    }

    if (!isset(FINANCE_CURRENCIES[$currency])) {
        return ['ok' => false, 'message' => 'Devise inconnue.'];
    }

    if (!isset(FINANCE_SCOPES[$scope])) {
        return ['ok' => false, 'message' => 'Portée inconnue.'];
    }

    // Un frais à zéro n'est pas une dette : c'est du bruit dans l'état
    // des impayés. Un frais négatif serait un remboursement déguisé,
    // qui doit passer par une remise motivée.
    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'Le montant doit être strictement positif.'];
    }

    if ($amount > 99999999.99) {
        return ['ok' => false, 'message' => 'Montant hors limites.'];
    }

    if (tenant_find('academic_years', $yearId) === null) {
        return ['ok' => false, 'message' => 'Année scolaire introuvable.'];
    }

    $levelId     = null;
    $classroomId = null;

    if ($scope === 'level') {
        $levelId = (int) ($input['level_id'] ?? 0);

        if (!db_exists('SELECT 1 FROM education_levels WHERE id = :id', ['id' => $levelId], true)) {
            return ['ok' => false, 'message' => 'Niveau introuvable.'];
        }
    } elseif ($scope === 'classroom') {
        $classroomId = (int) ($input['classroom_id'] ?? 0);
        $classroom   = tenant_find('classrooms', $classroomId);

        if ($classroom === null || (int) $classroom['academic_year_id'] !== $yearId) {
            return ['ok' => false, 'message' => 'Classe introuvable pour cette année.'];
        }
    }

    $dueOn = trim((string) ($input['due_on'] ?? ''));
    $dueOn = $dueOn !== '' && finance_valid_date($dueOn) ? $dueOn : null;

    $data = [
        'academic_year_id' => $yearId,
        'code'             => $code,
        'name'             => $name,
        'group_label'      => trim((string) ($input['group_label'] ?? '')) ?: null,
        'currency'         => $currency,
        'amount'           => number_format($amount, 2, '.', ''),
        'scope'            => $scope,
        'level_id'         => $levelId,
        'classroom_id'     => $classroomId,
        'due_on'           => $dueOn,
        'is_mandatory'     => !empty($input['is_mandatory']) ? 1 : 0,
        'is_active'        => !empty($input['is_active']) ? 1 : 0,
        'position'         => (int) ($input['position'] ?? 0),
    ];

    // Le code est unique par année : un doublon doit être refusé par un
    // message, pas par une exception SQL remontée à l'utilisateur.
    $clash = db_one(
        'SELECT id FROM fees
          WHERE school_id = :school_id AND academic_year_id = :year_id
            AND code = :code AND id <> :id',
        ['school_id' => $schoolId, 'year_id' => $yearId, 'code' => $code, 'id' => $feeId ?? 0]
    );

    if ($clash !== null) {
        return ['ok' => false, 'message' => 'Un autre frais porte déjà le code ' . $code . ' cette année.'];
    }

    if ($feeId === null) {
        $data['created_by'] = auth_id();
        $id = tenant_insert('fees', $data);

        audit_log('fee.create', 'fees', $id, null, $data);

        return ['ok' => true, 'message' => 'Frais créé.', 'id' => $id];
    }

    $before = finance_repo_fee($feeId);

    if ($before === null) {
        return ['ok' => false, 'message' => 'Frais introuvable.'];
    }

    tenant_update('fees', $data, 'id = :id', ['id' => $feeId]);
    audit_log('fee.update', 'fees', $feeId, $before, $data);

    return ['ok' => true, 'message' => 'Frais mis à jour.', 'id' => $feeId];
}

function finance_valid_date(string $date): bool
{
    $parsed = date_create_from_format('Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

// =====================================================================
//  L'AFFECTATION DES DETTES
// =====================================================================

/**
 * Affecte les frais actifs d'une année aux inscriptions concernées.
 *
 * IDEMPOTENT : rejouer l'affectation ne crée aucun doublon. La clé
 * unique (school_id, enrollment_id, fee_id) le garantit au niveau de la
 * base ; la comparaison préalable évite d'y arriver.
 *
 * Ce qui est déjà affecté n'est JAMAIS mis à jour : une dette figée le
 * reste, même si la grille a changé depuis.
 *
 * @param int|null $classroomId Restreindre à une classe.
 * @param int|null $feeId       Restreindre à un frais.
 * @return array{ok: bool, message: string, created: int, skipped: int, fees: int}
 */
function finance_service_assign(int $yearId, ?int $classroomId = null, ?int $feeId = null): array
{
    $schoolId = tenant_require();
    $fees     = finance_repo_fees($yearId, true);

    if ($feeId !== null) {
        $fees = array_values(array_filter(
            $fees,
            static fn (array $f): bool => (int) $f['id'] === $feeId
        ));
    }

    if ($fees === []) {
        return [
            'ok'      => false,
            'message' => 'Aucun frais actif à affecter pour cette année.',
            'created' => 0, 'skipped' => 0, 'fees' => 0,
        ];
    }

    $created = 0;
    $skipped = 0;
    $userId  = auth_id();

    db_transaction(static function () use ($fees, $classroomId, $schoolId, $userId, &$created, &$skipped): void {
        foreach ($fees as $fee) {
            $targets = finance_repo_targets($fee, $classroomId);

            if ($targets === []) {
                continue;
            }

            // Déjà affectés : une seule requête par frais, pas une par
            // élève. Sur une école de 1 200 inscrits, la différence est
            // entre 1 200 allers-retours et un seul.
            $inList   = implode(',', array_map('intval', $targets));
            $existing = array_map('intval', array_column(
                db_all(
                    'SELECT enrollment_id FROM student_fees
                      WHERE school_id = :school_id AND fee_id = :fee_id
                        AND enrollment_id IN (' . $inList . ')',
                    ['school_id' => $schoolId, 'fee_id' => (int) $fee['id']]
                ),
                'enrollment_id'
            ));

            foreach ($targets as $enrollmentId) {
                if (in_array($enrollmentId, $existing, true)) {
                    $skipped++;
                    continue;
                }

                db_insert('student_fees', [
                    'school_id'     => $schoolId,
                    'enrollment_id' => $enrollmentId,
                    'fee_id'        => (int) $fee['id'],
                    'label'         => (string) $fee['name'],
                    'currency'      => (string) $fee['currency'],
                    'amount_due'    => (string) $fee['amount'],
                    'due_on'        => $fee['due_on'],
                    'assigned_by'   => $userId,
                ]);

                $created++;
            }
        }
    });

    audit_log('fee.assign', 'fees', $feeId, null, [
        'year'      => $yearId,
        'classroom' => $classroomId,
        'created'   => $created,
        'skipped'   => $skipped,
    ]);

    return [
        'ok'      => true,
        'message' => $created . ' dette(s) créée(s), ' . $skipped . ' déjà en place.',
        'created' => $created,
        'skipped' => $skipped,
        'fees'    => count($fees),
    ];
}

/**
 * Réaligne les dettes déjà affectées sur le tarif actuel d'un frais.
 *
 * POURQUOI CETTE FONCTION EXISTE
 * ------------------------------
 * Le gel du tarif est la bonne règle, mais une règle sans échappatoire
 * devient un piège : un minerval saisi à 500 au lieu de 50 et affecté à
 * 400 élèves serait autrement irréparable, et l'école corrigerait à la
 * main, ligne par ligne, dans phpMyAdmin.
 *
 * L'échappatoire est donc explicite, tracée, et jamais silencieuse :
 * elle exige un motif, journalise l'ancien et le nouveau montant, et
 * rend le nombre de lignes touchées pour que l'écran le confirme AVANT
 * d'agir. Ce n'est pas une propagation automatique — c'est une
 * correction assumée.
 *
 * Les dettes annulées et les remises individuelles ne sont pas touchées.
 *
 * @return array{ok: bool, message: string, updated: int}
 */
function finance_service_resync_fee(int $feeId, string $reason): array
{
    $reason = trim($reason);

    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Un motif d\'au moins 5 caractères est obligatoire.', 'updated' => 0];
    }

    $fee = finance_repo_fee($feeId);

    if ($fee === null) {
        return ['ok' => false, 'message' => 'Frais introuvable.', 'updated' => 0];
    }

    $schoolId = tenant_require();

    // UNE CORRECTION DE TARIF NE CHANGE PAS DE MONNAIE.
    //
    // Réécrire la devise d'une dette sans convertir son montant ni ses
    // remises transforme silencieusement 10 USD en 10 CDF — un rapport
    // de 1 à 3 500. Et convertir supposerait un taux, que rien ici ne
    // fournit. Changer la monnaie d'un frais n'est pas la correction
    // d'une faute de frappe : c'est un autre frais.
    $foreign = (int) db_value(
        'SELECT COUNT(*) FROM student_fees
          WHERE school_id = :school_id AND fee_id = :fee_id
            AND is_cancelled = 0 AND currency <> :currency',
        ['school_id' => $schoolId, 'fee_id' => $feeId, 'currency' => (string) $fee['currency']]
    );

    if ($foreign > 0) {
        return [
            'ok'      => false,
            'updated' => 0,
            'message' => $foreign . ' dette(s) sont libellées dans une autre devise que le tarif actuel. '
                . 'Le réalignement ne convertit pas les monnaies : désactivez ce frais et créez-en un nouveau '
                . 'dans la devise voulue, puis annulez les dettes devenues sans objet.',
        ];
    }

    $affected = db_all(
        'SELECT id, amount_due, discount_amount, currency, label
           FROM student_fees
          WHERE school_id = :school_id AND fee_id = :fee_id AND is_cancelled = 0
            AND (amount_due <> :amount OR label <> :label OR discount_amount > :amount2)',
        [
            'school_id' => $schoolId,
            'fee_id'    => $feeId,
            'amount'    => (string) $fee['amount'],
            'amount2'   => (string) $fee['amount'],
            'label'     => (string) $fee['name'],
        ]
    );

    if ($affected === []) {
        return ['ok' => true, 'message' => 'Les dettes correspondent déjà au tarif.', 'updated' => 0];
    }

    // Une remise supérieure au nouveau tarif produirait un solde
    // NÉGATIF : l'école devrait de l'argent à la famille sans qu'aucun
    // paiement n'ait eu lieu. On la ramène au montant dû — la ligne
    // devient une exonération totale, ce que l'école voulait déjà — et
    // on annonce combien ont été rabotées.
    $capped = 0;

    foreach ($affected as $line) {
        if ((float) $line['discount_amount'] > (float) $fee['amount']) {
            $capped++;
        }
    }

    db_transaction(static function () use ($fee, $feeId, $schoolId): void {
        db_query(
            'UPDATE student_fees
                SET amount_due = :amount, label = :label, due_on = :due_on
              WHERE school_id = :school_id AND fee_id = :fee_id AND is_cancelled = 0',
            [
                'amount'    => (string) $fee['amount'],
                'label'     => (string) $fee['name'],
                'due_on'    => $fee['due_on'],
                'school_id' => $schoolId,
                'fee_id'    => $feeId,
            ]
        );

        db_query(
            'UPDATE student_fees
                SET discount_amount = amount_due
              WHERE school_id = :school_id AND fee_id = :fee_id AND is_cancelled = 0
                AND discount_amount > amount_due',
            ['school_id' => $schoolId, 'fee_id' => $feeId]
        );
    });

    audit_log('fee.resync', 'fees', $feeId, ['lines' => $affected], [
        'reason'   => $reason,
        'amount'   => $fee['amount'],
        'currency' => $fee['currency'],
        'updated'  => count($affected),
        'capped'   => $capped,
    ]);

    $message = count($affected) . ' dette(s) réalignée(s) sur le tarif actuel.';

    if ($capped > 0) {
        $message .= ' ' . $capped . ' remise(s) dépassaient le nouveau montant et ont été ramenées à '
            . 'une exonération totale — vérifiez ces dossiers.';
    }

    return ['ok' => true, 'message' => $message, 'updated' => count($affected), 'capped' => $capped];
}

/**
 * Combien de dettes une resynchronisation changerait — sans rien changer.
 * Sert à afficher la conséquence AVANT de la produire.
 */
function finance_service_resync_preview(int $feeId): int
{
    $fee = finance_repo_fee($feeId);

    if ($fee === null) {
        return 0;
    }

    // Même condition que le réalignement lui-même. Deux définitions
    // finiraient par diverger, et l'écran annoncerait un chiffre que
    // l'action ne produirait pas.
    return (int) db_value(
        'SELECT COUNT(*) FROM student_fees
          WHERE school_id = :school_id AND fee_id = :fee_id AND is_cancelled = 0
            AND (amount_due <> :amount OR currency <> :currency
                 OR label <> :label OR discount_amount > :amount2)',
        [
            'school_id' => tenant_require(),
            'fee_id'    => $feeId,
            'amount'    => (string) $fee['amount'],
            'amount2'   => (string) $fee['amount'],
            'currency'  => (string) $fee['currency'],
            'label'     => (string) $fee['name'],
        ]
    );
}

// =====================================================================
//  REMISES ET ANNULATIONS
// =====================================================================

/**
 * Accorde ou retire une remise sur une dette.
 *
 * Le motif est obligatoire dès que la remise est non nulle. Une
 * exonération sans justification écrite est la première porte ouverte
 * dans une caisse d'école : personne ne peut plus dire, six mois plus
 * tard, qui a décidé quoi.
 *
 * @return array{ok: bool, message: string}
 */
function finance_service_set_discount(int $studentFeeId, float $amount, string $reason): array
{
    $line = finance_repo_student_fee($studentFeeId);

    if ($line === null) {
        return ['ok' => false, 'message' => 'Ligne de frais introuvable.'];
    }

    if ((int) $line['is_cancelled'] === 1) {
        return ['ok' => false, 'message' => 'Cette dette est annulée : la remise n\'a plus d\'objet.'];
    }

    $reason = trim($reason);

    if ($amount < 0) {
        return ['ok' => false, 'message' => 'Une remise ne peut pas être négative.'];
    }

    // Une remise supérieure à la dette produirait un solde créditeur
    // fantôme : l'école devrait de l'argent à la famille sans qu'aucun
    // paiement n'ait eu lieu.
    if ($amount > (float) $line['amount_due']) {
        return [
            'ok'      => false,
            'message' => 'La remise ne peut pas dépasser le montant dû ('
                . finance_amount((float) $line['amount_due'], (string) $line['currency']) . ').',
        ];
    }

    if ($amount > 0 && mb_strlen($reason) < 3) {
        return ['ok' => false, 'message' => 'Le motif de la remise est obligatoire.'];
    }

    $before = ['discount' => $line['discount_amount'], 'reason' => $line['discount_reason']];

    tenant_update('student_fees', [
        'discount_amount' => number_format($amount, 2, '.', ''),
        'discount_reason' => $amount > 0 ? $reason : null,
    ], 'id = :id', ['id' => $studentFeeId]);

    audit_log('student_fee.discount', 'student_fees', $studentFeeId, $before, [
        'discount' => $amount,
        'reason'   => $reason,
    ]);

    return [
        'ok'      => true,
        'message' => $amount > 0
            ? 'Remise de ' . finance_amount($amount, (string) $line['currency']) . ' accordée.'
            : 'Remise retirée.',
    ];
}

/**
 * Annule une dette — sans la supprimer.
 *
 * Supprimer effacerait la trace d'un montant réclamé à une famille.
 * L'annulation la conserve, avec son auteur et son motif.
 *
 * @return array{ok: bool, message: string}
 */
function finance_service_cancel_line(int $studentFeeId, string $reason): array
{
    $line = finance_repo_student_fee($studentFeeId);

    if ($line === null) {
        return ['ok' => false, 'message' => 'Ligne de frais introuvable.'];
    }

    if ((int) $line['is_cancelled'] === 1) {
        return ['ok' => false, 'message' => 'Cette dette est déjà annulée.'];
    }

    $reason = trim($reason);

    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Un motif d\'au moins 5 caractères est obligatoire.'];
    }

    tenant_update('student_fees', [
        'is_cancelled'     => 1,
        'cancelled_reason' => $reason,
        'cancelled_by'     => auth_id(),
        'cancelled_at'     => date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => $studentFeeId]);

    audit_log('student_fee.cancel', 'student_fees', $studentFeeId, $line, ['reason' => $reason]);

    return ['ok' => true, 'message' => 'Dette annulée.'];
}

/**
 * Rétablit une dette annulée par erreur.
 *
 * POURQUOI CETTE FONCTION EXISTE
 * ------------------------------
 * L'annulation refuse de supprimer, ce qui est juste. Mais elle était
 * jusqu'ici SANS RETOUR : une annulation faite d'un clic de trop était
 * définitive, et la réaffectation ne recréait rien — la clé unique
 * (école, inscription, frais) l'en empêche, à raison, sinon chaque
 * réaffectation ressusciterait les annulations volontaires.
 *
 * Une école se serait donc retrouvée à corriger dans phpMyAdmin, ou à
 * renoncer à réclamer une somme réellement due. Le rétablissement est
 * la sortie propre : tracée, motivée, et distincte de l'affectation.
 *
 * Le montant rétabli est celui qui avait été GELÉ, pas le tarif du
 * jour : rétablir n'est pas refacturer.
 *
 * @return array{ok: bool, message: string}
 */
function finance_service_restore_line(int $studentFeeId, string $reason): array
{
    $line = finance_repo_student_fee($studentFeeId);

    if ($line === null) {
        return ['ok' => false, 'message' => 'Ligne de frais introuvable.'];
    }

    if ((int) $line['is_cancelled'] === 0) {
        return ['ok' => false, 'message' => 'Cette dette n\'est pas annulée.'];
    }

    $reason = trim($reason);

    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Un motif d\'au moins 5 caractères est obligatoire.'];
    }

    tenant_update('student_fees', [
        'is_cancelled'     => 0,
        'cancelled_reason' => null,
        'cancelled_by'     => null,
        'cancelled_at'     => null,
    ], 'id = :id', ['id' => $studentFeeId]);

    // Le motif de l'annulation disparaît de la ligne ; il survit dans le
    // journal, avec celui du rétablissement. Une caisse d'école doit
    // pouvoir répondre « qui a annulé quoi, et qui l'a repris ».
    audit_log('student_fee.restore', 'student_fees', $studentFeeId, $line, ['reason' => $reason]);

    return [
        'ok'      => true,
        'message' => 'Dette rétablie pour '
            . finance_amount((float) $line['amount_due'], (string) $line['currency']) . '.',
    ];
}
