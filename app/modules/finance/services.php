<?php
/**
 * Module FINANCE — règles métier.
 *
 * Deux moitiés, et il faut les garder distinctes :
 *   · 5A — ce que l'école RÉCLAME : grille tarifaire, dettes figées,
 *     remises, annulations ;
 *   · 5B — ce qu'elle ENCAISSE : paiements, taux figé, reçus numérotés,
 *     répartition sur les dettes.
 *
 * La règle qui tient les deux ensemble : une dette est due, et soldée,
 * DANS SA DEVISE. Le parent peut remettre une autre monnaie — le taux
 * est alors figé sur le paiement, jamais sur la dette.
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

    // ET LE MONTANT DÛ NE DESCEND PAS SOUS CE QUI A ÉTÉ ENCAISSÉ.
    //
    // Corriger un minerval de 80 à 20 alors que 50 ont été reçus
    // produisait un solde de −30 : un trop-perçu né d'une correction de
    // saisie. Ce module ne sait pas enregistrer un remboursement ; le
    // plancher est donc le montant encaissé, et les dossiers concernés
    // sont NOMMÉS pour être traités à part.
    $floored = [];

    foreach ($affected as $line) {
        $paid = finance_repo_paid_on_fee((int) $line['id']);

        if ($paid > (float) $fee['amount'] + 0.005) {
            $floored[(int) $line['id']] = $paid;
        }
    }

    db_transaction(static function () use ($fee, $feeId, $schoolId, $floored): void {
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

        // Plancher : jamais en dessous de l'encaissé.
        foreach ($floored as $lineId => $paid) {
            db_query(
                'UPDATE student_fees SET amount_due = :amount
                  WHERE id = :id AND school_id = :school_id',
                [
                    'amount'    => number_format($paid, 2, '.', ''),
                    'id'        => $lineId,
                    'school_id' => $schoolId,
                ]
            );
        }

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
        'floored'  => $floored,
    ]);

    $message = count($affected) . ' dette(s) réalignée(s) sur le tarif actuel.';

    if ($capped > 0) {
        $message .= ' ' . $capped . ' remise(s) dépassaient le nouveau montant et ont été ramenées à '
            . 'une exonération totale — vérifiez ces dossiers.';
    }

    if ($floored !== []) {
        $message .= ' ' . count($floored) . ' dossier(s) avaient déjà encaissé plus que le nouveau tarif : '
            . 'leur dette a été ramenée au montant reçu, et non au tarif. '
            . 'Aucun remboursement n\'est enregistré — traitez-les à part.';
    }

    return [
        'ok'      => true,
        'message' => $message,
        'updated' => count($affected),
        'capped'  => $capped,
        'floored' => count($floored),
    ];
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

    // UNE REMISE NE DESCEND PAS SOUS CE QUI A DÉJÀ ÉTÉ ENCAISSÉ.
    //
    // Accorder 60 de remise sur une dette de 80 dont 50 sont déjà payés
    // laissait un reste à payer de −30 : l'école devait de l'argent à la
    // famille, née d'une saisie et non d'un remboursement décidé. Ce
    // module ne sait pas représenter un remboursement ; il doit donc
    // refuser de le créer par accident.
    $paid = finance_repo_paid_on_fee($studentFeeId);

    if ((float) $line['amount_due'] - $amount < $paid - 0.005) {
        return [
            'ok'      => false,
            'message' => 'Cette dette a déjà encaissé '
                . finance_amount($paid, (string) $line['currency'])
                . '. Une remise qui ramènerait le montant dû en dessous créerait un trop-perçu : '
                . 'la remise ne peut pas dépasser '
                . finance_amount(max(0.0, (float) $line['amount_due'] - $paid), (string) $line['currency'])
                . '. Annulez d\'abord le reçu si le paiement est à reprendre.',
        ];
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

    // UNE DETTE DÉJÀ PAYÉE NE S'ANNULE PAS EN SILENCE.
    //
    // Les allocations survivent (elles gardent la trace de ce qui avait
    // été soldé) mais cessent de compter : les sommes reçues basculent
    // en AVANCE, réutilisable sur une autre dette. Sans ce message, le
    // caissier verrait simplement un solde changer sans savoir où sont
    // passés les encaissements.
    $paid = finance_repo_paid_on_fee($studentFeeId);

    tenant_update('student_fees', [
        'is_cancelled'     => 1,
        'cancelled_reason' => $reason,
        'cancelled_by'     => auth_id(),
        'cancelled_at'     => date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => $studentFeeId]);

    audit_log('student_fee.cancel', 'student_fees', $studentFeeId, $line, [
        'reason'    => $reason,
        'paid_back' => $paid,
    ]);

    if ($paid > 0.005) {
        return [
            'ok'      => true,
            'message' => 'Dette annulée. ' . finance_amount($paid, (string) $line['currency'])
                . ' avaient déjà été encaissés sur cette ligne : ils redeviennent une AVANCE, '
                . 'affectable à une autre dette. Les reçus ne sont pas touchés.',
        ];
    }

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

/**
 * Annule en bloc les dettes devenues hors portée.
 *
 * Rétrécir la portée d'un frais déjà affecté laissait des dettes
 * orphelines : deux classes entières restaient facturées d'un frais qui
 * ne les concernait plus, sans le moindre signal. L'affectation ne sait
 * qu'ajouter, et le gel du tarif interdit de réécrire une dette.
 *
 * L'annulation est la sortie juste : elle conserve la ligne, son auteur
 * et son motif. Elle reste une DÉCISION — le comptable la déclenche, le
 * programme se contente de compter et de montrer.
 *
 * @return array{ok: bool, message: string, cancelled: int}
 */
function finance_service_cancel_out_of_scope(int $yearId, string $reason): array
{
    $reason = trim($reason);

    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Un motif d\'au moins 5 caractères est obligatoire.', 'cancelled' => 0];
    }

    $lines = finance_repo_out_of_scope($yearId);

    if ($lines === []) {
        return ['ok' => true, 'message' => 'Aucune dette hors portée.', 'cancelled' => 0];
    }

    $userId = auth_id();
    $now    = date('Y-m-d H:i:s');

    db_transaction(static function () use ($lines, $reason, $userId, $now): void {
        foreach ($lines as $line) {
            tenant_update('student_fees', [
                'is_cancelled'     => 1,
                'cancelled_reason' => mb_substr($reason, 0, 160),
                'cancelled_by'     => $userId,
                'cancelled_at'     => $now,
            ], 'id = :id AND is_cancelled = 0', ['id' => (int) $line['id']]);
        }
    });

    audit_log('student_fee.cancel_out_of_scope', 'academic_years', $yearId, ['lines' => $lines], [
        'reason'    => $reason,
        'cancelled' => count($lines),
    ]);

    return [
        'ok'        => true,
        'message'   => count($lines) . ' dette(s) hors portée annulée(s). Elles restent visibles, barrées, avec leur motif.',
        'cancelled' => count($lines),
    ];
}

// =====================================================================
//  ENCAISSEMENTS (phase 5B)
// =====================================================================

const FINANCE_METHODS = [
    'cash'         => 'Espèces',
    'mobile_money' => 'Mobile Money',
    'bank'         => 'Banque',
    'cheque'       => 'Chèque',
    'other'        => 'Autre',
];

/** Taux par défaut du formulaire : combien de CDF pour 1 USD. */
function finance_default_rate(): float
{
    return (float) school_setting('finance.usd_rate', 2800);
}

/**
 * Crée la ligne du compteur si elle n'existe pas — HORS TRANSACTION.
 *
 * POURQUOI CETTE FONCTION EST SÉPARÉE
 * -----------------------------------
 * Cet INSERT IGNORE vivait à l'intérieur de la transaction, juste avant
 * le SELECT … FOR UPDATE. Quand la ligne existe déjà, InnoDB pose un
 * verrou PARTAGÉ pour vérifier le doublon ; le SELECT … FOR UPDATE qui
 * suit en réclame un EXCLUSIF. Deux caissiers simultanés détenaient
 * donc chacun un verrou partagé et attendaient l'exclusif de l'autre :
 * INTERBLOCAGE.
 *
 * Constaté en exécutant quatre guichets en parallèle pendant l'audit de
 * la phase 5B — deux des quatre tombaient sur une erreur fatale, leur
 * paiement perdu.
 *
 * Appelée hors transaction, elle valide immédiatement et libère son
 * verrou partagé avant que la transaction ne commence. Il ne reste
 * alors qu'une seule prise de verrou exclusif, qui se met simplement en
 * file d'attente.
 */
function finance_ensure_receipt_counter(string $yearCode): void
{
    db_query(
        'INSERT IGNORE INTO receipt_counters (school_id, counter_key, last_number)
         VALUES (:school_id, :key, 0)',
        ['school_id' => tenant_require(), 'key' => $yearCode]
    );
}

/**
 * Numéro de reçu suivant — sans trou ni doublon.
 *
 * Le compteur est verrouillé par SELECT … FOR UPDATE : deux caissiers
 * qui encaissent à la même seconde obtiennent deux numéros distincts et
 * consécutifs.
 *
 * À n'appeler QUE dans une transaction déjà ouverte, et APRÈS
 * finance_ensure_receipt_counter() — voir la raison ci-dessus.
 *
 * @return array{0: string, 1: int}
 */
function finance_next_receipt(string $yearCode): array
{
    $schoolId = tenant_require();
    $prefix   = (string) school_setting('finance.receipt_prefix', 'REC');

    $current = (int) db_value(
        'SELECT last_number FROM receipt_counters
          WHERE school_id = :school_id AND counter_key = :key
          FOR UPDATE',
        ['school_id' => $schoolId, 'key' => $yearCode]
    );

    $next = $current + 1;

    db_query(
        'UPDATE receipt_counters SET last_number = :next
          WHERE school_id = :school_id AND counter_key = :key',
        ['next' => $next, 'school_id' => $schoolId, 'key' => $yearCode]
    );

    return [
        $prefix . '-' . $yearCode . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT),
        $next,
    ];
}

/**
 * Enregistre un encaissement et le répartit sur les dettes.
 *
 * LES TROIS MONTANTS
 * ------------------
 * `tendered` est ce que le parent a remis au guichet ; `credited` est ce
 * qui est porté au crédit des dettes. Quand les devises diffèrent, le
 * TAUX est figé sur le paiement — jamais relu ensuite. Un reçu imprimé
 * aujourd'hui doit rester vérifiable dans dix ans sans connaître le
 * cours du jour.
 *
 * LA RÉPARTITION
 * --------------
 * Par défaut, la plus ancienne échéance d'abord (FIFO) : c'est ce que
 * fait un caissier, et c'est ce qui minimise les impayés anciens. Le
 * comptable peut imposer sa propre répartition via $allocations.
 *
 * Le reliquat non affecté est CONSERVÉ comme avance. Le perdre
 * reviendrait à voler la famille ; le refuser obligerait le caissier à
 * mentir sur le montant reçu.
 *
 * @param array<int,mixed> $allocations [student_fee_id => montant], vide = FIFO
 * @return array{ok: bool, message: string, id?: int, receipt?: string, unallocated?: float}
 */
function finance_service_record_payment(int $enrollmentId, array $input, array $allocations = []): array
{
    $schoolId   = tenant_require();
    $enrollment = tenant_find('enrollments', $enrollmentId);

    if ($enrollment === null) {
        return ['ok' => false, 'message' => 'Inscription introuvable.'];
    }

    // Le périmètre vaut pour l'écriture comme pour la lecture.
    if (!finance_can_view_enrollment($enrollmentId)) {
        return ['ok' => false, 'message' => 'Dossier hors de votre périmètre.'];
    }

    $year = tenant_find('academic_years', (int) $enrollment['academic_year_id']);

    if ($year === null) {
        return ['ok' => false, 'message' => 'Année scolaire introuvable.'];
    }

    $tenderedCurrency = strtoupper((string) ($input['tendered_currency'] ?? ''));
    $creditedCurrency = strtoupper((string) ($input['credited_currency'] ?? ''));

    if (!isset(FINANCE_CURRENCIES[$tenderedCurrency], FINANCE_CURRENCIES[$creditedCurrency])) {
        return ['ok' => false, 'message' => 'Devise inconnue.'];
    }

    $tendered = finance_parse_amount((string) ($input['tendered_amount'] ?? '0'));

    if ($tendered <= 0) {
        return ['ok' => false, 'message' => 'Le montant remis doit être strictement positif.'];
    }

    $method = (string) ($input['method'] ?? 'cash');

    if (!isset(FINANCE_METHODS[$method])) {
        return ['ok' => false, 'message' => 'Mode de paiement inconnu.'];
    }

    $paidOn = trim((string) ($input['paid_on'] ?? ''));

    if (!finance_valid_date($paidOn)) {
        return ['ok' => false, 'message' => 'Date de paiement invalide.'];
    }

    // Une caisse ne s'alimente pas demain. Antidater reste possible — le
    // caissier saisit parfois le lendemain — mais postdater fausserait
    // tous les arrêtés de caisse déjà signés.
    if ($paidOn > date('Y-m-d')) {
        return ['ok' => false, 'message' => 'Un encaissement ne peut pas être daté dans le futur.'];
    }

    // ----- LE TAUX ---------------------------------------------------
    $rate = null;

    if ($tenderedCurrency === $creditedCurrency) {
        $credited = $tendered;
    } else {
        $rate = finance_parse_amount((string) ($input['exchange_rate'] ?? '0'));

        if ($rate <= 0) {
            return [
                'ok'      => false,
                'message' => 'Un taux de change est obligatoire dès que la monnaie remise diffère de '
                    . 'celle des dettes à solder.',
            ];
        }

        // Le taux s'exprime en unités REMISES pour une unité CRÉDITÉE.
        $credited = round($tendered / $rate, 2);

        if ($credited <= 0) {
            return ['ok' => false, 'message' => 'Le taux saisi produit un montant crédité nul.'];
        }
    }

    $outcome = ['unallocated' => 0.0];

    // La ligne du compteur est créée AVANT d'ouvrir la transaction :
    // dedans, son verrou partagé provoquerait un interblocage avec le
    // SELECT … FOR UPDATE d'un autre guichet.
    finance_ensure_receipt_counter((string) $year['code']);

    db_transaction(static function () use (
        $schoolId, $enrollmentId, $year, $input, $method, $paidOn,
        $tenderedCurrency, $tendered, $creditedCurrency, $credited, $rate,
        $allocations, &$outcome
    ): void {
        [$receiptNo, $seq] = finance_next_receipt((string) $year['code']);

        $paymentId = db_insert('payments', [
            'school_id'         => $schoolId,
            'enrollment_id'     => $enrollmentId,
            'receipt_no'        => $receiptNo,
            'receipt_seq'       => $seq,
            'paid_on'           => $paidOn,
            'tendered_currency' => $tenderedCurrency,
            'tendered_amount'   => number_format($tendered, 2, '.', ''),
            'exchange_rate'     => $rate !== null ? number_format($rate, 6, '.', '') : null,
            'credited_currency' => $creditedCurrency,
            'credited_amount'   => number_format($credited, 2, '.', ''),
            'method'            => $method,
            'reference'         => trim((string) ($input['reference'] ?? '')) ?: null,
            'external_status'   => $method === 'cash' ? 'settled' : (string) ($input['external_status'] ?? 'settled'),
            'payer_name'        => trim((string) ($input['payer_name'] ?? '')) ?: null,
            'note'              => trim((string) ($input['note'] ?? '')) ?: null,
            'received_by'       => auth_id(),
        ]);

        $left = finance_service_apply_allocations($paymentId, $enrollmentId, $creditedCurrency, $credited, $allocations);

        $outcome = [
            'id'          => $paymentId,
            'receipt'     => $receiptNo,
            'unallocated' => $left,
        ];
    });

    audit_log('payment.record', 'payments', $outcome['id'] ?? null, null, [
        'receipt'   => $outcome['receipt'] ?? null,
        'tendered'  => $tendered . ' ' . $tenderedCurrency,
        'credited'  => $credited . ' ' . $creditedCurrency,
        'rate'      => $rate,
        'method'    => $method,
    ]);

    $message = 'Reçu ' . ($outcome['receipt'] ?? '') . ' — '
        . finance_amount($credited, $creditedCurrency) . ' porté au crédit.';

    if (($outcome['unallocated'] ?? 0.0) > 0.005) {
        $message .= ' ' . finance_amount((float) $outcome['unallocated'], $creditedCurrency)
            . ' restent en avance, aucune dette ouverte ne les absorbe.';
    }

    return [
        'ok'          => true,
        'message'     => $message,
        'id'          => (int) ($outcome['id'] ?? 0),
        'receipt'     => (string) ($outcome['receipt'] ?? ''),
        'unallocated' => (float) ($outcome['unallocated'] ?? 0.0),
    ];
}

/**
 * Répartit un montant crédité sur les dettes ouvertes.
 *
 * Ne dépasse JAMAIS le reste dû d'une dette : une dette sur-payée
 * produirait un solde négatif, c'est-à-dire une créance de la famille
 * sur l'école née d'une erreur de saisie.
 *
 * @param array<int,mixed> $requested [student_fee_id => montant], vide = FIFO
 * @return float Le reliquat non affecté.
 */
function finance_service_apply_allocations(
    int $paymentId,
    int $enrollmentId,
    string $currency,
    float $credited,
    array $requested = []
): float {
    $schoolId  = tenant_require();
    $remaining = $credited;

    // Les dettes de CETTE inscription, dans CETTE devise, non annulées.
    // Filtrer sur l'inscription interdit d'imputer le paiement d'un
    // élève sur la dette d'un autre.
    $lines = array_values(array_filter(
        finance_repo_fees_with_paid($enrollmentId),
        static fn (array $l): bool => (string) $l['currency'] === $currency
    ));

    foreach ($lines as $line) {
        if ($remaining <= 0.005) {
            break;
        }

        $lineId = (int) $line['id'];
        $open   = round((float) $line['amount_net'] - (float) $line['paid'], 2);

        if ($open <= 0.005) {
            continue;
        }

        if ($requested !== []) {
            if (!array_key_exists($lineId, $requested)) {
                continue;
            }

            $wanted = finance_parse_amount((string) $requested[$lineId]);

            if ($wanted <= 0) {
                continue;
            }

            $amount = min($wanted, $open, $remaining);
        } else {
            // FIFO : la plus ancienne échéance d'abord. C'est ce que
            // fait un caissier, et cela réduit les impayés anciens.
            $amount = min($open, $remaining);
        }

        $amount = round($amount, 2);

        if ($amount <= 0.005) {
            continue;
        }

        db_query(
            'INSERT INTO payment_allocations
                 (school_id, payment_id, student_fee_id, currency, amount)
             VALUES (:school_id, :payment_id, :fee_id, :currency, :amount)
             ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)',
            [
                'school_id'  => $schoolId,
                'payment_id' => $paymentId,
                'fee_id'     => $lineId,
                'currency'   => $currency,
                'amount'     => number_format($amount, 2, '.', ''),
            ]
        );

        $remaining = round($remaining - $amount, 2);
    }

    return max(0.0, $remaining);
}

/**
 * Annule un encaissement — sans le supprimer.
 *
 * Le numéro de reçu reste CONSOMMÉ. C'est ce qui rend la séquence
 * vérifiable : un trou signifie une ligne effacée, donc un incident,
 * jamais une annulation régulière.
 *
 * Les allocations sont conservées : les effacer ferait perdre la trace
 * de ce qui avait été soldé. C'est la lecture qui les écarte, en
 * filtrant sur `payments.is_cancelled = 0`.
 *
 * @return array{ok: bool, message: string}
 */
function finance_service_cancel_payment(int $paymentId, string $reason): array
{
    $payment = finance_repo_payment($paymentId);

    if ($payment === null) {
        return ['ok' => false, 'message' => 'Paiement introuvable.'];
    }

    if ((int) $payment['is_cancelled'] === 1) {
        return ['ok' => false, 'message' => 'Ce paiement est déjà annulé.'];
    }

    $reason = trim($reason);

    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Un motif d\'au moins 5 caractères est obligatoire.'];
    }

    tenant_update('payments', [
        'is_cancelled'     => 1,
        'cancelled_reason' => mb_substr($reason, 0, 160),
        'cancelled_by'     => auth_id(),
        'cancelled_at'     => date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => $paymentId]);

    audit_log('payment.cancel', 'payments', $paymentId, $payment, ['reason' => $reason]);

    return [
        'ok'      => true,
        'message' => 'Reçu ' . $payment['receipt_no'] . ' annulé. Le numéro reste consommé : '
            . 'un trou dans la séquence signalerait un incident, pas une annulation.',
    ];
}

/**
 * Remplace la répartition d'un paiement.
 *
 * Utile quand le caissier a laissé le FIFO imputer la mauvaise tranche.
 * Les anciennes allocations sont retirées puis reconstruites dans la
 * même transaction : à aucun moment la dette n'apparaît soldée deux
 * fois.
 *
 * @param array<int,mixed> $allocations [student_fee_id => montant]
 * @return array{ok: bool, message: string, unallocated?: float}
 */
function finance_service_reallocate(int $paymentId, array $allocations): array
{
    $payment = finance_repo_payment($paymentId);

    if ($payment === null) {
        return ['ok' => false, 'message' => 'Paiement introuvable.'];
    }

    if ((int) $payment['is_cancelled'] === 1) {
        return ['ok' => false, 'message' => 'Un paiement annulé ne se réaffecte pas.'];
    }

    $before = finance_repo_allocations($paymentId);
    $left   = 0.0;

    db_transaction(static function () use ($payment, $paymentId, $allocations, &$left): void {
        db_query(
            'DELETE FROM payment_allocations
              WHERE school_id = :school_id AND payment_id = :payment_id',
            ['school_id' => tenant_require(), 'payment_id' => $paymentId]
        );

        $left = finance_service_apply_allocations(
            $paymentId,
            (int) $payment['enrollment_id'],
            (string) $payment['credited_currency'],
            (float) $payment['credited_amount'],
            $allocations
        );
    });

    audit_log('payment.reallocate', 'payments', $paymentId, ['before' => $before], [
        'requested'   => $allocations,
        'unallocated' => $left,
    ]);

    $message = 'Répartition mise à jour.';

    if ($left > 0.005) {
        $message .= ' ' . finance_amount($left, (string) $payment['credited_currency'])
            . ' restent non affectés.';
    }

    return ['ok' => true, 'message' => $message, 'unallocated' => $left];
}

/**
 * Lit un montant saisi par un humain.
 *
 * « 1 250,50 », « 1250.50 » et « 1.250,50 » désignent la même somme au
 * guichet. Un caissier qui tape une virgule ne doit pas encaisser 1 USD
 * au lieu de 1 250.
 */
function finance_parse_amount(string $raw): float
{
    $clean = str_replace([' ', "\u{00A0}"], '', trim($raw));

    // Séparateur décimal : le dernier signe de ponctuation rencontré.
    $lastComma = strrpos($clean, ',');
    $lastDot   = strrpos($clean, '.');

    if ($lastComma !== false && $lastDot !== false) {
        $decimal = $lastComma > $lastDot ? ',' : '.';
        $clean   = str_replace($decimal === ',' ? '.' : ',', '', $clean);
        $clean   = str_replace($decimal, '.', $clean);
    } elseif ($lastComma !== false) {
        $clean = str_replace(',', '.', $clean);
    }

    return (float) $clean;
}
