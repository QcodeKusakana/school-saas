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

/**
 * Arrondit un montant à la précision RÉELLE de sa devise.
 *
 * CE QUI EST AFFICHÉ DOIT ÊTRE CE QUI EST STOCKÉ
 * ----------------------------------------------
 * Défaut trouvé à l'audit 5D : `finance_decimals()` n'était utilisée
 * que pour l'AFFICHAGE. Un montant crédité se calculait toujours à deux
 * décimales — `round($remis / $taux, 2)` — et 20 USD convertis en francs
 * donnaient 56 022,41 CDF. L'écran, lui, montrait « 56 022 CDF ».
 *
 * Conséquences observées, toutes sur de l'argent réel :
 *   · le reste dû imprimé sur le reçu n'était pas le reste dû ;
 *   · une dette pouvait garder un solde de 0,49 CDF et rester dans
 *     l'état des impayés en affichant « 0 CDF » — introuvable pour le
 *     comptable, insoldable par la famille : la pièce n'existe pas ;
 *   · l'école accumulait des avances de quelques centimes de franc,
 *     invisibles et éternelles.
 *
 * Toute somme qui ENTRE en base passe désormais par ici. Un franc est
 * un entier ; un dollar a deux décimales.
 */
function finance_round(float $amount, string $currency): float
{
    return round($amount, finance_decimals($currency));
}

/** Montant formaté avec sa devise — jamais un nombre nu. */
function finance_amount(float $amount, string $currency): string
{
    return number_format($amount, finance_decimals($currency), ',', ' ') . ' ' . $currency;
}

/**
 * Une année scolaire close n'accepte plus aucun mouvement d'argent.
 *
 * Un exercice clôturé est un exercice dont les chiffres ont été remis
 * au promoteur, à l'inspection ou au comptable. Y ajouter un
 * encaissement ou une dépense après coup réécrit un état déjà signé —
 * c'est la définition même d'une écriture frauduleuse.
 *
 * Il n'existe pas encore d'écran de clôture (il viendra avec la phase
 * 10). Le garde-fou est posé MAINTENANT, avant que l'écran n'arrive :
 * une protection ajoutée après coup à un module d'argent déjà en
 * service ne rattrape jamais les lignes déjà écrites.
 *
 * L'échappatoire est la réouverture explicite de l'année — jamais une
 * exception silencieuse dans le module finances.
 */
function finance_year_is_open(array $year): bool
{
    return in_array((string) ($year['status'] ?? ''), ['draft', 'active'], true);
}

/**
 * Message unique de refus sur une année close.
 */
function finance_year_closed_message(array $year): string
{
    return 'L\'année ' . (string) $year['code'] . ' est clôturée : elle n\'accepte plus '
        . 'aucun mouvement de caisse. Rouvrez-la explicitement si une écriture doit '
        . 'vraiment y être ajoutée.';
}

/**
 * La date d'une opération appartient-elle bien à l'exercice visé ?
 *
 * Défaut trouvé à l'audit 5D : une dépense datée du 15/01/2020 était
 * acceptée sur l'exercice 2092-2093. Le total annuel des dépenses
 * devenait donc librement falsifiable, et l'état de caisse d'une
 * journée ne se recoupait avec rien.
 *
 * LA RÈGLE N'EST PAS « DANS LES BORNES DE L'EXERCICE »
 * ----------------------------------------------------
 * Elle serait fausse, et le travail réel d'une école le montre :
 *   · les familles versent l'inscription de l'année suivante dès le
 *     troisième trimestre de l'année en cours ;
 *   · l'école règle la facture d'électricité de juin fin juillet,
 *     après la clôture des cours.
 *
 * Ce n'est pas non plus « la date ne tombe pas dans un AUTRE exercice » :
 * cette règle-là refuserait précisément le versement d'avance, qui est
 * le cas le plus fréquent de l'année.
 *
 * La règle retenue est la seule qui laisse passer le travail réel tout
 * en refusant l'absurde : la date doit rester dans une FENÊTRE autour
 * de l'exercice — large avant lui, plus courte après.
 */
const FINANCE_YEAR_MARGIN_BEFORE = 180;   // jours : versements d'avance
const FINANCE_YEAR_MARGIN_AFTER  = 90;    // jours : règlements tardifs

/** Motif de refus d'une date sur un exercice — null si la date convient. */
function finance_date_conflict(array $year, string $date): ?string
{
    $floor = strtotime((string) $year['starts_on'] . ' -' . FINANCE_YEAR_MARGIN_BEFORE . ' days');
    $ceil  = strtotime((string) $year['ends_on'] . ' +' . FINANCE_YEAR_MARGIN_AFTER . ' days');
    $stamp = strtotime($date);

    if ($stamp >= $floor && $stamp <= $ceil) {
        return null;
    }

    return 'Le ' . date('d/m/Y', strtotime($date)) . ' est trop éloigné de l\'exercice '
        . (string) $year['code'] . ' ('
        . date('d/m/Y', strtotime((string) $year['starts_on'])) . ' au '
        . date('d/m/Y', strtotime((string) $year['ends_on']))
        . ') pour lui être imputé. Choisissez l\'exercice correspondant, ou corrigez la date.';
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

    // Un franc n'a pas de centimes : le tarif est ramené à la précision
    // réelle de sa devise AVANT toute vérification, sinon un frais de
    // 45 000,40 CDF s'afficherait « 45 000 CDF » et aucune dette ne
    // pourrait plus jamais être soldée exactement (audit 5D).
    $amount = finance_round($amount, $currency);

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
        'amount'           => number_format($amount, finance_decimals($currency), '.', ''),
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
    // Le réalignement réécrit des dettes DÉJÀ FIGÉES, et peut les
    // abaisser. Même règle que la remise : direction seulement.
    if (!can('fee.waive')) {
        return [
            'ok'      => false,
            'message' => 'Réaligner des dettes déjà annoncées relève de la direction.',
            'updated' => 0,
        ];
    }

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
    // LA SÉPARATION DES RÔLES EST PORTÉE PAR LE SERVICE, PAS SEULEMENT
    // PAR LA ROUTE (leçon des audits 3, 4A et 5D).
    //
    // Celui qui encaisse ne réduit pas ce qui est dû : il pourrait
    // recevoir l'argent en espèces, effacer la dette, et garder la
    // somme sans qu'aucun compte ne bouge.
    if (!can('fee.waive')) {
        return [
            'ok'      => false,
            'message' => 'Modifier ce qu\'une famille doit relève de la direction : '
                . 'celui qui encaisse ne réduit pas la dette.',
        ];
    }

    $line = finance_repo_student_fee($studentFeeId);

    if ($line === null) {
        return ['ok' => false, 'message' => 'Ligne de frais introuvable.'];
    }

    if ((int) $line['is_cancelled'] === 1) {
        return ['ok' => false, 'message' => 'Cette dette est annulée : la remise n\'a plus d\'objet.'];
    }

    $reason = trim($reason);

    // À la précision de la devise, comme tout montant qui entre en base.
    $amount = finance_round($amount, (string) $line['currency']);

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
        'discount_amount' => number_format($amount, finance_decimals((string) $line['currency']), '.', ''),
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
    // LA SÉPARATION DES RÔLES EST PORTÉE PAR LE SERVICE, PAS SEULEMENT
    // PAR LA ROUTE (leçon des audits 3, 4A et 5D).
    //
    // Celui qui encaisse ne réduit pas ce qui est dû : il pourrait
    // recevoir l'argent en espèces, effacer la dette, et garder la
    // somme sans qu'aucun compte ne bouge.
    if (!can('fee.waive')) {
        return [
            'ok'      => false,
            'message' => 'Modifier ce qu\'une famille doit relève de la direction : '
                . 'celui qui encaisse ne réduit pas la dette.',
        ];
    }

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
    // Ses allocations sont SUPPRIMÉES, et c'est une correction de la
    // phase 5B, qui les conservait « pour garder la trace ». Ce
    // raisonnement posait une bombe, démontrée par l'audit de la 5C :
    // l'allocation restait en base tout en cessant de compter, une
    // imputation d'avance en créait une SECONDE pour le même argent, et
    // rétablir la dette réactivait la première — 200 USD d'allocations
    // pour 100 USD encaissés, et une avance négative.
    //
    // Une dette annulée n'est plus soldée par rien : l'allocation qui
    // la visait n'a plus d'objet. La trace vit dans le journal d'audit,
    // qui en conserve le détail, dette par dette.
    //
    // L'argent, lui, ne bouge pas : il redevient une AVANCE, réutilisable
    // sur une autre dette.
    $paid     = finance_repo_paid_on_fee($studentFeeId);
    $released = db_all(
        'SELECT pa.id, pa.payment_id, pa.amount, pa.currency, p.receipt_no
           FROM payment_allocations pa
           JOIN payments p ON p.id = pa.payment_id AND p.school_id = pa.school_id
          WHERE pa.school_id = :school_id AND pa.student_fee_id = :fee_id',
        ['school_id' => tenant_require(), 'fee_id' => $studentFeeId]
    );

    db_transaction(static function () use ($studentFeeId, $reason): void {
        tenant_update('student_fees', [
            'is_cancelled'     => 1,
            'cancelled_reason' => $reason,
            'cancelled_by'     => auth_id(),
            'cancelled_at'     => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $studentFeeId]);

        db_query(
            'DELETE FROM payment_allocations
              WHERE school_id = :school_id AND student_fee_id = :fee_id',
            ['school_id' => tenant_require(), 'fee_id' => $studentFeeId]
        );
    });

    audit_log('student_fee.cancel', 'student_fees', $studentFeeId, $line, [
        'reason'    => $reason,
        'paid_back' => $paid,
        'released'  => $released,
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
    // LA SÉPARATION DES RÔLES EST PORTÉE PAR LE SERVICE, PAS SEULEMENT
    // PAR LA ROUTE (leçon des audits 3, 4A et 5D).
    //
    // Celui qui encaisse ne réduit pas ce qui est dû : il pourrait
    // recevoir l'argent en espèces, effacer la dette, et garder la
    // somme sans qu'aucun compte ne bouge.
    if (!can('fee.waive')) {
        return [
            'ok'      => false,
            'message' => 'Modifier ce qu\'une famille doit relève de la direction : '
                . 'celui qui encaisse ne réduit pas la dette.',
        ];
    }

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

    // Le rétablissement ne réimpute RIEN. Les allocations de la dette
    // ont été supprimées à l'annulation ; l'argent est resté sous forme
    // d'avance, et c'est au comptable de décider s'il la rattache ici
    // ou ailleurs. Réimputer d'office ressusciterait le défaut que la
    // suppression vient de fermer.
    return [
        'ok'      => true,
        'message' => 'Dette rétablie pour '
            . finance_amount((float) $line['amount_due'], (string) $line['currency'])
            . '. Aucun versement n\'y est rattaché : si une avance doit la solder, '
            . 'imputez-la depuis l\'état des impayés.',
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
    // Annulation en masse de dettes : direction seulement.
    if (!can('fee.waive')) {
        return [
            'ok'        => false,
            'message'   => 'Annuler des dettes relève de la direction.',
            'cancelled' => 0,
        ];
    }

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

    if (!finance_year_is_open($year)) {
        return ['ok' => false, 'message' => finance_year_closed_message($year)];
    }

    $tenderedCurrency = strtoupper((string) ($input['tendered_currency'] ?? ''));
    $creditedCurrency = strtoupper((string) ($input['credited_currency'] ?? ''));

    if (!isset(FINANCE_CURRENCIES[$tenderedCurrency], FINANCE_CURRENCIES[$creditedCurrency])) {
        return ['ok' => false, 'message' => 'Devise inconnue.'];
    }

    $tendered = finance_round(
        finance_parse_amount((string) ($input['tendered_amount'] ?? '0')),
        $tenderedCurrency
    );

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

    // Un versement daté à l'intérieur d'un AUTRE exercice ne relève pas
    // de celui-ci : il fausserait les deux journaux de caisse à la fois.
    $conflict = finance_date_conflict($year, $paidOn);

    if ($conflict !== null) {
        return [
            'ok'      => false,
            'message' => $conflict . ' Encaissez sur l\'inscription de l\'année concernée.',
        ];
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
        //
        // L'arrondi se fait à la précision de la monnaie CRÉDITÉE, pas
        // à deux décimales : convertir 20 USD en francs donnait
        // 56 022,41 CDF, un montant que l'écran affichait « 56 022 CDF »
        // et qu'aucune famille ne pouvait solder (audit 5D).
        $credited = finance_round($tendered / $rate, $creditedCurrency);

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
            'tendered_amount'   => number_format($tendered, finance_decimals($tenderedCurrency), '.', ''),
            'exchange_rate'     => $rate !== null ? number_format($rate, 6, '.', '') : null,
            'credited_currency' => $creditedCurrency,
            'credited_amount'   => number_format($credited, finance_decimals($creditedCurrency), '.', ''),
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
        $open   = finance_round((float) $line['amount_net'] - (float) $line['paid'], $currency);

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

        // À la précision de la devise : une imputation de 0,41 CDF
        // laisserait une dette au solde insoldable (audit 5D).
        $amount = finance_round($amount, $currency);

        if ($amount <= 0.005) {
            continue;
        }

        // INSERT simple : chaque dette n'est visitée qu'une fois dans
        // cette boucle, et la réaffectation efface tout avant de
        // reconstruire. Un ON DUPLICATE KEY ici ne pourrait jamais se
        // déclencher — et masquerait un défaut s'il le faisait.
        db_query(
            'INSERT INTO payment_allocations
                 (school_id, payment_id, student_fee_id, currency, amount)
             VALUES (:school_id, :payment_id, :fee_id, :currency, :amount)',
            [
                'school_id'  => $schoolId,
                'payment_id' => $paymentId,
                'fee_id'     => $lineId,
                'currency'   => $currency,
                'amount'     => number_format($amount, finance_decimals($currency), '.', ''),
            ]
        );

        $remaining = finance_round($remaining - $amount, $currency);
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

    // LA SÉPARATION DES RÔLES EST PORTÉE PAR LE SERVICE, PAS SEULEMENT
    // PAR LA ROUTE.
    //
    // « Une restriction d'accès ne vaut que si TOUTES les portes la
    // portent » — leçon des audits 3 et 4A. Le comptable encaisse mais
    // n'annule pas ; la route le sait, le service l'ignorait. Le jour où
    // une annulation sera déclenchée depuis un autre écran (une reprise
    // de caisse, un import, une API mobile), la règle tiendra encore.
    if (!can('payment.cancel')) {
        return [
            'ok'      => false,
            'message' => 'Annuler un reçu relève de la direction : celui qui encaisse '
                . 'n\'efface pas la trace de son encaissement.',
        ];
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

// =====================================================================
//  RECOUVREMENT (phase 5C)
// =====================================================================

/**
 * Impute les avances sur les dettes ouvertes.
 *
 * POURQUOI CE N'EST PAS AUTOMATIQUE
 * ---------------------------------
 * Une avance encaissée en octobre attend la tranche de janvier. Quand
 * cette tranche est affectée, l'argent est là mais la dette apparaît
 * impayée : l'école relancerait une famille dont elle détient déjà le
 * versement.
 *
 * Le faire silencieusement à l'affichage serait pire : consulter un
 * état ne doit jamais modifier les comptes. L'imputation est donc une
 * ACTION, déclenchée par le comptable, tracée, et rendue visible par la
 * colonne « avance » de l'état des impayés.
 *
 * Elle ne peut rien perdre : elle déplace un crédit déjà encaissé vers
 * une dette de la MÊME inscription et de la MÊME devise, sans jamais
 * dépasser le reste dû.
 *
 * @return array{ok: bool, message: string, applied: int, amount: array<string,float>}
 */
function finance_service_apply_advances(int $yearId, ?int $classroomId = null): array
{
    if (tenant_find('academic_years', $yearId) === null) {
        return ['ok' => false, 'message' => 'Année scolaire introuvable.', 'applied' => 0, 'amount' => []];
    }

    $advances = finance_repo_advances($yearId);

    if ($advances === []) {
        return ['ok' => true, 'message' => 'Aucune avance à imputer.', 'applied' => 0, 'amount' => []];
    }

    // On ne travaille que sur les inscriptions qui ont ENCORE une dette
    // ouverte : une avance sans dette en face reste une avance.
    $debtors = [];

    foreach (finance_repo_outstanding($yearId, ['classroom_id' => $classroomId]) as $row) {
        $debtors[(int) $row['enrollment_id']][(string) $row['currency']] = true;
    }

    $applied = 0;
    $amounts = [];

    db_transaction(static function () use ($advances, $debtors, &$applied, &$amounts): void {
        foreach ($advances as $enrollmentId => $byCurrency) {
            foreach ($byCurrency as $currency => $advance) {
                if (!isset($debtors[$enrollmentId][$currency])) {
                    continue;
                }

                foreach (finance_repo_payments_with_remainder($enrollmentId, $currency) as $payment) {
                    $before = (float) $payment['remainder'];
                    $left   = finance_service_allocate_remainder(
                        (int) $payment['id'],
                        $enrollmentId,
                        $currency,
                        $before
                    );

                    $used = round($before - $left, 2);

                    if ($used > 0.005) {
                        $applied++;
                        $amounts[$currency] = round(($amounts[$currency] ?? 0.0) + $used, 2);
                    }
                }
            }
        }
    });

    if ($applied === 0) {
        return [
            'ok'      => true,
            'message' => 'Aucune avance ne correspond à une dette ouverte.',
            'applied' => 0,
            'amount'  => [],
        ];
    }

    audit_log('payment.apply_advances', 'academic_years', $yearId, null, [
        'classroom' => $classroomId,
        'applied'   => $applied,
        'amounts'   => $amounts,
    ]);

    $parts = [];

    foreach ($amounts as $currency => $total) {
        $parts[] = finance_amount($total, (string) $currency);
    }

    return [
        'ok'      => true,
        'message' => implode(' et ', $parts) . ' d\'avances imputés sur ' . $applied . ' reçu(s).',
        'applied' => $applied,
        'amount'  => $amounts,
    ];
}

/**
 * Impute le RELIQUAT d'un paiement sur les dettes encore ouvertes.
 *
 * Distincte de finance_service_apply_allocations() : celle-ci ajoute au
 * déjà-imputé au lieu de repartir de zéro. Les confondre doublerait les
 * montants déjà affectés.
 *
 * @return float Ce qui reste encore non imputé.
 */
function finance_service_allocate_remainder(
    int $paymentId,
    int $enrollmentId,
    string $currency,
    float $remainder
): float {
    $schoolId = tenant_require();

    foreach (finance_repo_fees_with_paid($enrollmentId) as $line) {
        if ($remainder <= 0.005) {
            break;
        }

        if ((string) $line['currency'] !== $currency) {
            continue;
        }

        $open = finance_round((float) $line['amount_net'] - (float) $line['paid'], $currency);

        if ($open <= 0.005) {
            continue;
        }

        $amount = finance_round(min($open, $remainder), $currency);

        db_query(
            'INSERT INTO payment_allocations
                 (school_id, payment_id, student_fee_id, currency, amount)
             VALUES (:school_id, :payment_id, :fee_id, :currency, :amount)
             ON DUPLICATE KEY UPDATE amount = amount + :added',
            [
                'school_id'  => $schoolId,
                'payment_id' => $paymentId,
                'fee_id'     => (int) $line['id'],
                'currency'   => $currency,
                'amount'     => number_format($amount, finance_decimals($currency), '.', ''),
                'added'      => number_format($amount, finance_decimals($currency), '.', ''),
            ]
        );

        $remainder = finance_round($remainder - $amount, $currency);
    }

    return max(0.0, $remainder);
}

// =====================================================================
//  DÉPENSES (phase 5D)
// =====================================================================

/**
 * Crée la ligne du compteur de bons — HORS TRANSACTION.
 *
 * Même raison qu'aux reçus et aux matricules : à l'intérieur, le verrou
 * partagé de cet INSERT IGNORE provoque un interblocage avec le
 * SELECT … FOR UPDATE d'une saisie simultanée (audit 5B).
 */
function finance_ensure_expense_counter(string $yearCode): void
{
    db_query(
        'INSERT IGNORE INTO expense_counters (school_id, counter_key, last_number)
         VALUES (:school_id, :key, 0)',
        ['school_id' => tenant_require(), 'key' => $yearCode]
    );
}

/**
 * Numéro de bon de sortie suivant — sans trou ni doublon.
 *
 * À n'appeler QUE dans une transaction déjà ouverte, et APRÈS
 * finance_ensure_expense_counter().
 *
 * @return array{0: string, 1: int}
 */
function finance_next_voucher(string $yearCode): array
{
    $schoolId = tenant_require();

    $current = (int) db_value(
        'SELECT last_number FROM expense_counters
          WHERE school_id = :school_id AND counter_key = :key
          FOR UPDATE',
        ['school_id' => $schoolId, 'key' => $yearCode]
    );

    $next = $current + 1;

    db_query(
        'UPDATE expense_counters SET last_number = :next
          WHERE school_id = :school_id AND counter_key = :key',
        ['next' => $next, 'school_id' => $schoolId, 'key' => $yearCode]
    );

    return ['DEP-' . $yearCode . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT), $next];
}

/**
 * Enregistre une sortie de caisse.
 *
 * LE BÉNÉFICIAIRE ET LA DESCRIPTION SONT OBLIGATOIRES
 * ---------------------------------------------------
 * Une dépense sans bénéficiaire nommé n'est pas une dépense, c'est un
 * trou dans la caisse. C'est la seule chose qui permette, six mois plus
 * tard, de savoir à qui l'argent est allé.
 *
 * @return array{ok: bool, message: string, id?: int, voucher?: string}
 */
function finance_service_record_expense(int $yearId, array $input): array
{
    $schoolId = tenant_require();
    $year     = tenant_find('academic_years', $yearId);

    if ($year === null) {
        return ['ok' => false, 'message' => 'Année scolaire introuvable.'];
    }

    if (!finance_year_is_open($year)) {
        return ['ok' => false, 'message' => finance_year_closed_message($year)];
    }

    $currency = strtoupper((string) ($input['currency'] ?? ''));

    if (!isset(FINANCE_CURRENCIES[$currency])) {
        return ['ok' => false, 'message' => 'Devise inconnue.'];
    }

    $amount = finance_round(finance_parse_amount((string) ($input['amount'] ?? '0')), $currency);

    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'Le montant doit être strictement positif.'];
    }

    if ($amount > 99999999.99) {
        return ['ok' => false, 'message' => 'Montant hors limites.'];
    }

    $method = (string) ($input['method'] ?? 'cash');

    if (!isset(FINANCE_METHODS[$method])) {
        return ['ok' => false, 'message' => 'Mode de paiement inconnu.'];
    }

    $spentOn = trim((string) ($input['spent_on'] ?? ''));

    if (!finance_valid_date($spentOn)) {
        return ['ok' => false, 'message' => 'Date de dépense invalide.'];
    }

    // Une caisse ne se vide pas demain. Antidater reste possible — le
    // comptable saisit parfois le lendemain — mais postdater fausserait
    // tous les arrêtés de caisse déjà signés.
    if ($spentOn > date('Y-m-d')) {
        return ['ok' => false, 'message' => 'Une dépense ne peut pas être datée dans le futur.'];
    }

    // LA DATE DOIT APPARTENIR À L'EXERCICE IMPUTÉ.
    //
    // Sans ce contrôle, une dépense du 15/01/2020 s'imputait sur
    // l'exercice 2092-2093 : le total annuel des dépenses devenait
    // librement falsifiable, et l'état de caisse d'une journée ne se
    // recoupait avec rien (audit 5D).
    $conflict = finance_date_conflict($year, $spentOn);

    if ($conflict !== null) {
        return ['ok' => false, 'message' => $conflict];
    }

    $beneficiary = trim((string) ($input['beneficiary'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));

    if (mb_strlen($beneficiary) < 2) {
        return [
            'ok'      => false,
            'message' => 'Le bénéficiaire est obligatoire : une dépense sans destinataire nommé '
                . 'est un trou dans la caisse.',
        ];
    }

    if (mb_strlen($description) < 3) {
        return ['ok' => false, 'message' => 'La description de la dépense est obligatoire.'];
    }

    $categoryId = (int) ($input['category_id'] ?? 0);

    if ($categoryId <= 0 || !db_exists(
        'SELECT 1 FROM expense_categories WHERE id = :id AND is_active = 1',
        ['id' => $categoryId],
        true // table globale
    )) {
        return ['ok' => false, 'message' => 'Poste de dépense inconnu.'];
    }

    // Hors transaction : voir finance_ensure_expense_counter().
    finance_ensure_expense_counter((string) $year['code']);

    $outcome = [];

    db_transaction(static function () use (
        $schoolId, $yearId, $year, $categoryId, $spentOn, $currency, $amount,
        $beneficiary, $description, $method, $input, &$outcome
    ): void {
        [$voucherNo, $seq] = finance_next_voucher((string) $year['code']);

        $id = db_insert('expenses', [
            'school_id'        => $schoolId,
            'academic_year_id' => $yearId,
            'category_id'      => $categoryId,
            'voucher_no'       => $voucherNo,
            'voucher_seq'      => $seq,
            'spent_on'         => $spentOn,
            'currency'         => $currency,
            'amount'           => number_format($amount, finance_decimals($currency), '.', ''),
            'beneficiary'      => mb_substr($beneficiary, 0, 160),
            'description'      => mb_substr($description, 0, 255),
            'method'           => $method,
            'reference'        => trim((string) ($input['reference'] ?? '')) ?: null,
            'supporting_doc'   => trim((string) ($input['supporting_doc'] ?? '')) ?: null,
            'recorded_by'      => auth_id(),
        ]);

        $outcome = ['id' => $id, 'voucher' => $voucherNo];
    });

    audit_log('expense.record', 'expenses', $outcome['id'] ?? null, null, [
        'voucher'     => $outcome['voucher'] ?? null,
        'amount'      => $amount . ' ' . $currency,
        'beneficiary' => $beneficiary,
        'category'    => $categoryId,
    ]);

    return [
        'ok'      => true,
        'message' => 'Bon de sortie ' . ($outcome['voucher'] ?? '') . ' — '
            . finance_amount($amount, $currency) . ' remis à ' . $beneficiary . '.',
        'id'      => (int) ($outcome['id'] ?? 0),
        'voucher' => (string) ($outcome['voucher'] ?? ''),
    ];
}

/**
 * Annule une dépense — sans la supprimer.
 *
 * Le numéro du bon reste CONSOMMÉ : un trou dans la séquence signalerait
 * une ligne effacée, donc un détournement — jamais une annulation
 * régulière.
 *
 * @return array{ok: bool, message: string}
 */
function finance_service_cancel_expense(int $expenseId, string $reason): array
{
    $expense = finance_repo_expense($expenseId);

    if ($expense === null) {
        return ['ok' => false, 'message' => 'Dépense introuvable.'];
    }

    // Même raison qu'à l'annulation d'un reçu : la règle « qui engage la
    // dépense ne l'annule pas » ne tenait que par le middleware de la
    // route. Une seule porte ne suffit pas (audit 5D).
    if (!can('expense.cancel')) {
        return [
            'ok'      => false,
            'message' => 'Annuler un bon de sortie relève de la direction : celui qui '
                . 'engage la dépense n\'en efface pas la trace.',
        ];
    }

    if ((int) $expense['is_cancelled'] === 1) {
        return ['ok' => false, 'message' => 'Cette dépense est déjà annulée.'];
    }

    $reason = trim($reason);

    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Un motif d\'au moins 5 caractères est obligatoire.'];
    }

    tenant_update('expenses', [
        'is_cancelled'     => 1,
        'cancelled_reason' => mb_substr($reason, 0, 160),
        'cancelled_by'     => auth_id(),
        'cancelled_at'     => date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => $expenseId]);

    audit_log('expense.cancel', 'expenses', $expenseId, $expense, ['reason' => $reason]);

    return [
        'ok'      => true,
        'message' => 'Bon ' . $expense['voucher_no'] . ' annulé. Le numéro reste consommé : '
            . 'un trou dans la séquence signalerait un incident, pas une annulation.',
    ];
}
