<?php
/**
 * Module PLATEFORME — facturation SaaS (phase 7B2).
 *
 * CE QUE CE FICHIER ENCAISSE, ET CE QU'IL N'ENCAISSE PAS
 * ======================================================
 * Ici on encaisse ce que les ÉCOLES doivent à l'ÉDITEUR : leur
 * abonnement. À ne jamais confondre avec `app/modules/finance/`, qui
 * encaisse ce que les FAMILLES doivent aux écoles. Deux caisses, deux
 * tables, deux publics — et le commentaire du schéma le disait déjà.
 *
 * LES TROIS RÈGLES REPRISES DE LA PHASE 5
 * ---------------------------------------
 *  1. Une dette est due, et soldée, DANS SA DEVISE. Une somme remise en
 *     francs sur une dette en dollars se convertit au taux du jour, et
 *     ce taux SE FIGE sur le paiement : une quittance doit rester
 *     vérifiable dix ans plus tard.
 *  2. La précision d'une devise est une règle de STOCKAGE, pas
 *     d'affichage. Le franc congolais n'a pas de centimes.
 *  3. On n'efface pas un paiement : on l'annule, avec motif, auteur et
 *     date. Un trou dans une comptabilité signale un détournement.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * Décimales réelles d'une devise.
 *
 * Volontairement dupliqué depuis `finance/services.php` plutôt que
 * requis : ce module est chargé par la console de l'éditeur, qui ne
 * charge pas le module finance d'une école. Charger tout `finance`
 * pour une fonction de trois lignes ferait dépendre la facturation
 * SaaS de la caisse scolaire — deux domaines qui n'ont aucune raison
 * de se tenir.
 *
 * Si une troisième devise apparaît, les deux fonctions doivent bouger
 * ensemble ; un test le vérifie.
 */
function billing_decimals(string $currency): int
{
    return $currency === 'CDF' ? 0 : 2;
}

/** Arrondit à la précision RÉELLE de la devise. */
function billing_round(float $amount, string $currency): float
{
    return round($amount, billing_decimals($currency));
}

/** Formate un montant pour l'affichage, à sa vraie précision. */
function billing_format(float $amount, string $currency): string
{
    return number_format($amount, billing_decimals($currency), ',', ' ') . ' ' . $currency;
}

/**
 * CE QUI EST RÉELLEMENT DÛ SUR UN ABONNEMENT — AU PRORATA DU SERVI.
 *
 * LE DÉFAUT QUE CETTE EXPRESSION CORRIGE
 * ======================================
 * `platform_service_change_plan()` clôture l'abonnement en cours et en
 * ouvre un nouveau. Facturer chaque ligne à son tarif plein ferait
 * payer DEUX années à une école qui monte de gamme en janvier — et
 * trois si elle change deux fois. Trouvé par la sonde 7B2 : la liste
 * des impayés était vide alors qu'une école avait été doublement
 * facturée puis surpayée.
 *
 * On facture donc ce qui a été SERVI :
 *   · abonnement non clôturé, ou clôturé après son échéance → tarif plein ;
 *   · clôturé avant même son premier jour → ZÉRO, c'est une correction
 *     de saisie, pas une période vendue ;
 *   · clôturé en cours de terme → au prorata des jours servis.
 *
 * > On ne facture pas une période qu'on n'a pas servie, et on ne
 * > facture pas deux fois la même.
 *
 * L'expression est écrite une seule fois et partagée par les deux
 * requêtes qui en ont besoin : deux copies finiraient par diverger, et
 * c'est un montant dû.
 *
 * L'arrondi suit la devise DE LA LIGNE : le franc congolais n'a pas de
 * centimes, et un prorata en produirait sans cela.
 */
const BILLING_DUE_SQL = "ROUND(
    CASE
        WHEN sub.price_amount IS NULL THEN NULL
        WHEN sub.cancelled_at IS NULL
          OR DATE(sub.cancelled_at) >= sub.ends_on THEN sub.price_amount
        WHEN DATE(sub.cancelled_at) <= sub.starts_on THEN 0
        ELSE sub.price_amount
             * DATEDIFF(DATE(sub.cancelled_at), sub.starts_on)
             / DATEDIFF(sub.ends_on, sub.starts_on)
    END,
    CASE WHEN sub.price_currency = 'CDF' THEN 0 ELSE 2 END
)";

/**
 * Ce qu'une école doit, et ce qu'elle a versé, abonnement par abonnement.
 *
 * LE MONTANT DÛ EST CELUI QUI A ÉTÉ FIGÉ, pas celui du catalogue.
 * Un abonnement antérieur à la migration 028 n'en a pas : il rend
 * `price_amount = null`, et les écrans le DISENT au lieu d'inventer.
 *
 * Les paiements `cancelled_at IS NOT NULL` et `status = 'failed'` ne
 * comptent pas. Un versement `pending` non plus : tant qu'un opérateur
 * Mobile Money n'a pas confirmé, l'argent n'est pas arrivé — la leçon
 * de `external_status` en phase 5B.
 *
 * @return array<int, array<string, mixed>>
 */
function billing_repo_school_ledger(int $schoolId): array
{
    return platform_scope('platform.billing.manage', static fn (): array => db_all(
        'SELECT sub.id, sub.status, sub.billing_cycle, sub.starts_on, sub.ends_on,
                sub.price_amount, sub.price_currency, sub.cancelled_at,
                ' . BILLING_DUE_SQL . ' AS due,
                p.code AS plan_code, p.name AS plan_name,
                COALESCE(pay.paid, 0)  AS paid,
                COALESCE(pay.n, 0)     AS payments
           FROM subscriptions sub
           JOIN plans p ON p.id = sub.plan_id
           LEFT JOIN (
                SELECT subscription_id,
                       SUM(amount) AS paid,
                       COUNT(*)    AS n
                  FROM subscription_payments
                 WHERE school_id = :school_pay
                   AND status = \'confirmed\'
                   AND cancelled_at IS NULL
                 GROUP BY subscription_id
           ) pay ON pay.subscription_id = sub.id
          WHERE sub.school_id = :school_id
          ORDER BY sub.starts_on DESC, sub.id DESC',
        ['school_id' => $schoolId, 'school_pay' => $schoolId],
        true
    ));
}

/** Les versements d'une école, du plus récent au plus ancien. */
function billing_repo_payments(int $schoolId): array
{
    return platform_scope('platform.billing.manage', static fn (): array => db_all(
        'SELECT sp.*, u.first_name, u.last_name,
                p.code AS plan_code, sub.starts_on, sub.ends_on
           FROM subscription_payments sp
           LEFT JOIN users u ON u.id = sp.recorded_by
           LEFT JOIN subscriptions sub ON sub.id = sp.subscription_id
           LEFT JOIN plans p ON p.id = sub.plan_id
          WHERE sp.school_id = :school_id
          ORDER BY sp.paid_at DESC, sp.id DESC',
        ['school_id' => $schoolId],
        true
    ));
}

/**
 * Le solde d'une école, PAR DEVISE.
 *
 * Jamais un total unique : additionner des dollars et des francs
 * produit un nombre qui ne veut rien dire. La phase 5 l'a établi pour
 * la caisse scolaire, et la raison est la même ici.
 *
 * @return array<string, array{due: float, paid: float, balance: float, frozen: bool}>
 */
function billing_school_balance(int $schoolId): array
{
    $out = [];

    foreach (billing_repo_school_ledger($schoolId) as $row) {
        // Un abonnement sans tarif figé ne peut pas entrer dans un
        // solde : on ne devine pas ce qu'il devait.
        if ($row['price_amount'] === null) {
            continue;
        }

        $currency = (string) $row['price_currency'];

        $out[$currency] ??= ['due' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'frozen' => true];

        $out[$currency]['due']  += (float) $row['due'];
        $out[$currency]['paid'] += (float) $row['paid'];
    }

    foreach ($out as $currency => $line) {
        $out[$currency]['due']     = billing_round($line['due'], $currency);
        $out[$currency]['paid']    = billing_round($line['paid'], $currency);
        $out[$currency]['balance'] = billing_round($line['due'] - $line['paid'], $currency);
    }

    return $out;
}

/** Combien d'abonnements de cette école n'ont pas de tarif figé ? */
function billing_unpriced_count(int $schoolId): int
{
    return (int) platform_scope('platform.billing.manage', static fn (): int => (int) db_value(
        'SELECT COUNT(*) FROM subscriptions
          WHERE school_id = :school_id AND price_amount IS NULL',
        ['school_id' => $schoolId],
        true
    ));
}

/**
 * Enregistre un versement d'abonnement.
 *
 * @param array{subscription_id: int, tendered_currency: string, tendered_amount: float|string,
 *              exchange_rate?: float|string|null, paid_at?: string, method?: string,
 *              provider?: string, reference?: string, status?: string, notes?: string} $input
 * @return array{ok: bool, message: string, payment_id: ?int}
 */
function billing_service_record(int $schoolId, array $input): array
{
    return platform_scope('platform.billing.manage', static function () use ($schoolId, $input): array {
        $sub = db_one(
            'SELECT sub.id, sub.price_amount, sub.price_currency, s.name AS school_name
               FROM subscriptions sub
               JOIN schools s ON s.id = sub.school_id
              WHERE sub.id = :id AND sub.school_id = :school_id
              LIMIT 1',
            ['id' => (int) ($input['subscription_id'] ?? 0), 'school_id' => $schoolId],
            true
        );

        if ($sub === null) {
            return [
                'ok'         => false,
                'message'    => 'Abonnement introuvable pour cet établissement.',
                'payment_id' => null,
            ];
        }

        if ($sub['price_amount'] === null) {
            // ON N'ENCAISSE PAS SUR UNE DETTE INCONNUE.
            // L'abonnement est antérieur au figeage du tarif : rien ne
            // dit ce qu'il devait. Encaisser dessus produirait un solde
            // fantaisiste.
            return [
                'ok'         => false,
                'message'    => 'Cet abonnement n\'a pas de tarif figé : il est antérieur à la '
                    . 'migration 028. Appliquez-lui une offre depuis sa fiche avant d\'encaisser.',
                'payment_id' => null,
            ];
        }

        $creditedCurrency = (string) $sub['price_currency'];
        $tenderedCurrency = strtoupper(trim((string) ($input['tendered_currency'] ?? $creditedCurrency)));
        $tendered         = (float) ($input['tendered_amount'] ?? 0);

        if ($tendered <= 0) {
            return [
                'ok'         => false,
                'message'    => 'Le montant remis doit être supérieur à zéro.',
                'payment_id' => null,
            ];
        }

        // LE TAUX SE FIGE SUR LE PAIEMENT.
        //
        // Même monnaie : pas de taux, et en exiger un serait du bruit.
        // Monnaies différentes : le taux est OBLIGATOIRE — sans lui, le
        // montant crédité serait une invention, et la quittance
        // invérifiable dix ans plus tard.
        $rate = $input['exchange_rate'] ?? null;

        if ($tenderedCurrency === $creditedCurrency) {
            $rate     = null;
            $credited = $tendered;
        } else {
            if ($rate === null || (float) $rate <= 0) {
                return [
                    'ok'         => false,
                    'message'    => 'Un versement en ' . $tenderedCurrency . ' sur une dette en '
                        . $creditedCurrency . ' exige le taux de change du jour. '
                        . 'Sans lui, le montant porté au crédit serait une estimation.',
                    'payment_id' => null,
                ];
            }

            $rate     = (float) $rate;
            $credited = $tendered / $rate;
        }

        $status = (string) ($input['status'] ?? 'confirmed');

        if (!in_array($status, ['pending', 'confirmed'], true)) {
            return [
                'ok'         => false,
                'message'    => 'Un versement s\'enregistre « en attente » ou « confirmé ». '
                    . 'Un échec ou un remboursement se constate ensuite.',
                'payment_id' => null,
            ];
        }

        $method = (string) ($input['method'] ?? 'mobile_money');

        if (!in_array($method, ['mobile_money', 'bank_transfer', 'cash', 'card', 'other'], true)) {
            return ['ok' => false, 'message' => 'Moyen de paiement inconnu.', 'payment_id' => null];
        }

        $reference = trim((string) ($input['reference'] ?? ''));
        $provider  = trim((string) ($input['provider'] ?? ''));

        // LA RÉFÉRENCE EST UNIQUE PAR PRESTATAIRE — TANT QUE LA LIGNE VIT.
        //
        // C'est le garde-fou contre le double enregistrement d'un même
        // versement Mobile Money, que deux personnes peuvent saisir à
        // quelques minutes d'intervalle. On le vérifie AVANT d'insérer
        // pour rendre un message clair plutôt qu'une erreur SQL.
        //
        // MAIS IL NE DOIT PAS INTERDIRE LA CORRECTION (audit 7B2).
        // Une erreur de frappe sur le montant s'annule, avec motif, et
        // se ressaisit. Le versement, lui, existe dans la vraie vie et
        // porte exactement UNE référence, celle de l'opérateur.
        // Compter les lignes mortes forcerait l'éditeur soit à inventer
        // une fausse référence, soit à ne rien enregistrer — deux
        // mensonges pires que le doublon qu'on voulait éviter.
        //
        // `reference_live` porte la référence tant que la ligne compte,
        // et NULL sitôt qu'elle est annulée ou en échec ; l'index
        // unique de la base suit exactement la même règle.
        if ($reference !== '' && $provider !== '' && db_exists(
            'SELECT 1 FROM subscription_payments
              WHERE provider = :p AND reference_live = :r LIMIT 1',
            ['p' => $provider, 'r' => $reference],
            true
        )) {
            return [
                'ok'         => false,
                'message'    => 'Un versement portant déjà la référence « ' . $reference
                    . ' » chez ' . $provider . ' est enregistré et compte dans le solde. '
                    . 'Un même versement ne s\'encaisse pas deux fois — pour le corriger, '
                    . 'annulez d\'abord la ligne existante, puis ressaisissez-la.',
                'payment_id' => null,
            ];
        }

        $paymentId = db_insert('subscription_payments', [
            'school_id'         => $schoolId,
            'subscription_id'   => (int) $sub['id'],
            'tendered_currency' => $tenderedCurrency,
            'tendered_amount'   => billing_round($tendered, $tenderedCurrency),
            'exchange_rate'     => $rate,
            'amount'            => billing_round($credited, $creditedCurrency),
            'currency'          => $creditedCurrency,
            'method'            => $method,
            'provider'          => $provider !== '' ? $provider : null,
            'reference'         => $reference !== '' ? $reference : null,
            'status'            => $status,
            'paid_at'           => (string) ($input['paid_at'] ?? date('Y-m-d H:i:s')),
            'recorded_by'       => (int) auth_user()['id'],
            'notes'             => trim((string) ($input['notes'] ?? '')) ?: null,
        ], true);

        audit_log('platform.billing.record', 'subscription_payments', $paymentId, null, [
            'school'    => $sub['school_name'],
            'tendered'  => billing_format(billing_round($tendered, $tenderedCurrency), $tenderedCurrency),
            'rate'      => $rate,
            'credited'  => billing_format(billing_round($credited, $creditedCurrency), $creditedCurrency),
            'method'    => $method,
            'reference' => $reference,
            'status'    => $status,
        ]);

        return [
            'ok'      => true,
            'message' => billing_format(billing_round($tendered, $tenderedCurrency), $tenderedCurrency)
                . ' enregistré' . ($status === 'pending' ? ' EN ATTENTE' : '')
                . ($rate !== null
                    ? ' — ' . billing_format(billing_round($credited, $creditedCurrency), $creditedCurrency)
                      . ' portés au crédit (taux ' . rtrim(rtrim(number_format($rate, 6, ',', ' '), '0'), ',') . ')'
                    : '')
                . '.',
            'payment_id' => $paymentId,
        ];
    });
}

/**
 * Confirme un versement resté en attente.
 *
 * Un versement Mobile Money s'enregistre avant que l'opérateur ne l'ait
 * confirmé : c'est l'écart que `status` porte. Tant qu'il est
 * `pending`, il ne compte dans aucun solde.
 */
function billing_service_confirm(int $paymentId): array
{
    return platform_scope('platform.billing.manage', static function () use ($paymentId): array {
        $pay = db_one(
            'SELECT sp.*, s.name AS school_name
               FROM subscription_payments sp
               JOIN schools s ON s.id = sp.school_id
              WHERE sp.id = :id LIMIT 1',
            ['id' => $paymentId],
            true
        );

        if ($pay === null) {
            return ['ok' => false, 'message' => 'Versement introuvable.'];
        }

        if ($pay['cancelled_at'] !== null) {
            return ['ok' => false, 'message' => 'Ce versement est annulé : il ne se confirme plus.'];
        }

        if ((string) $pay['status'] === 'confirmed') {
            return ['ok' => false, 'message' => 'Ce versement est déjà confirmé.'];
        }

        db_query(
            'UPDATE subscription_payments SET status = \'confirmed\' WHERE id = :id',
            ['id' => $paymentId],
            true
        );

        audit_log('platform.billing.confirm', 'subscription_payments', $paymentId,
            ['status' => $pay['status']],
            ['status' => 'confirmed', 'school' => $pay['school_name']]
        );

        return ['ok' => true, 'message' => 'Versement confirmé.'];
    });
}

/**
 * Annule un versement — sans jamais l'effacer.
 *
 * La ligne reste, avec son motif, son auteur et sa date. Un trou dans
 * une comptabilité signale un détournement ; une annulation motivée
 * raconte ce qui s'est passé.
 */
function billing_service_cancel(int $paymentId, string $reason): array
{
    return platform_scope('platform.billing.manage', static function () use ($paymentId, $reason): array {
        if (trim($reason) === '') {
            return [
                'ok'      => false,
                'message' => 'Une annulation de versement doit porter un motif : '
                    . 'c\'est ce qui la distingue d\'une erreur.',
            ];
        }

        $pay = db_one(
            'SELECT sp.*, s.name AS school_name
               FROM subscription_payments sp
               JOIN schools s ON s.id = sp.school_id
              WHERE sp.id = :id LIMIT 1',
            ['id' => $paymentId],
            true
        );

        if ($pay === null) {
            return ['ok' => false, 'message' => 'Versement introuvable.'];
        }

        if ($pay['cancelled_at'] !== null) {
            return ['ok' => false, 'message' => 'Ce versement est déjà annulé.'];
        }

        db_query(
            'UPDATE subscription_payments
                SET cancelled_at = NOW(), cancelled_by = :by, cancelled_reason = :reason
              WHERE id = :id',
            ['by' => (int) auth_user()['id'], 'reason' => trim($reason), 'id' => $paymentId],
            true
        );

        audit_log('platform.billing.cancel', 'subscription_payments', $paymentId,
            ['status' => $pay['status'], 'amount' => $pay['amount']],
            ['school' => $pay['school_name'], 'reason' => trim($reason)]
        );

        return [
            'ok'      => true,
            'message' => 'Versement annulé. La ligne reste au journal, avec son motif.',
        ];
    });
}

/**
 * Les soldes non nuls, école par école ET PAR DEVISE.
 *
 * DEUX RAISONS DE NE PAS FILTRER SUR « balance > 0 ».
 * ---------------------------------------------------
 * La première version ne rendait que les DETTES. Un trop-perçu — une
 * école qui a versé plus que dû, parce qu'un taux a bougé ou qu'un
 * versement a été attribué deux fois — disparaissait complètement de
 * la console. Or il engage l'éditeur autant qu'une dette : il doit du
 * service ou un remboursement.
 *
 * > Un écran qui ne montre que ce qui nous est dû n'est pas une
 * > comptabilité, c'est un rappel de facture.
 *
 * Jamais de total toutes devises confondues : additionner des dollars
 * et des francs produit un nombre qui ne veut rien dire.
 *
 * TROISIÈME RAISON : NE PAS FILTRER LES ÉCOLES ARCHIVÉES.
 * -------------------------------------------------------
 * La première version joignait `schools ... AND s.deleted_at IS NULL`.
 * Une école archivée avec une dette impayée disparaissait donc de la
 * console — et archiver une école mauvaise payeuse suffisait à effacer
 * ce qu'elle devait. C'est le défaut du bandeau de la 7B1, transposé à
 * l'argent : le filtre retire l'école, l'obligation reste.
 *
 * > Archiver une école range son dossier ; cela n'éteint pas sa dette.
 *
 * Elles sortent donc avec un drapeau `archived`, et l'écran les montre
 * à part. Le jour où la dette est soldée, `HAVING balance <> 0` les
 * fait disparaître d'elles-mêmes — pour la bonne raison.
 */
function billing_repo_balances(): array
{
    return platform_scope('platform.billing.manage', static fn (): array => db_all(
        'SELECT s.id, s.code, s.name,
                (s.deleted_at IS NOT NULL)                  AS archived,
                sub.price_currency AS currency,
                SUM(' . BILLING_DUE_SQL . ')                AS due,
                COALESCE(SUM(pay.paid), 0)                  AS paid,
                SUM(' . BILLING_DUE_SQL . ') - COALESCE(SUM(pay.paid), 0) AS balance,
                MIN(sub.starts_on)                          AS since
           FROM subscriptions sub
           JOIN schools s ON s.id = sub.school_id
           LEFT JOIN (
                SELECT subscription_id, school_id, SUM(amount) AS paid
                  FROM subscription_payments
                 WHERE status = \'confirmed\' AND cancelled_at IS NULL
                 GROUP BY subscription_id, school_id
           ) pay ON pay.subscription_id = sub.id AND pay.school_id = sub.school_id
          WHERE sub.price_amount IS NOT NULL
          GROUP BY s.id, s.code, s.name, s.deleted_at, sub.price_currency
         HAVING balance <> 0
          ORDER BY archived, balance DESC, s.name',
        [],
        true
    ));
}
