<?php
/**
 * Phase 7B2 — la facturation SaaS.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · le tarif est FIGÉ sur l'abonnement : relever le catalogue ne
 *    réécrit aucune dette déjà engagée ;
 *  · un tarif négocié l'emporte, et zéro veut dire GRATUIT, pas « vide » ;
 *  · une dette est due, et soldée, DANS SA DEVISE — le taux se fige sur
 *    le versement, et il est obligatoire dès que les monnaies diffèrent ;
 *  · le franc congolais n'a pas de centimes, au stockage comme ailleurs ;
 *  · un versement en attente ne compte pas ; un versement annulé non
 *    plus, mais sa ligne reste ;
 *  · une même référence ne s'encaisse pas deux fois ;
 *  · changer d'offre en cours de terme ne facture PAS deux périodes ;
 *  · un trop-perçu se voit ;
 *  · on n'encaisse pas sur une dette inconnue.
 *
 * Usage : php tests/platform_billing.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/platform/services.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

/** La première ligne du relevé — l'abonnement le plus récent. */
function latest(int $schoolId): array
{
    return billing_repo_school_ledger($schoolId)[0];
}

$schools = [];
$users   = [];

try {
    foreach (['BILL-A' => 'École Alpha', 'BILL-B' => 'École Beta'] as $code => $name) {
        $schools[$code] = db_insert('schools', [
            'uuid' => str_uuid(), 'code' => $code, 'slug' => strtolower($code),
            'name' => $name, 'status' => 'active',
        ], true);
    }

    $editor = db_insert('users', [
        'uuid' => str_uuid(), 'school_id' => null, 'username' => 'billing.editeur',
        'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name' => 'BILLING', 'first_name' => 'Editeur', 'status' => 'active',
    ], true);
    $users[] = $editor;

    db_query(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :u, id FROM roles WHERE code = \'SUPER_ADMIN\' AND school_id IS NULL',
        ['u' => $editor],
        true
    );

    $schoolAdmin = db_insert('users', [
        'uuid' => str_uuid(), 'school_id' => $schools['BILL-A'], 'username' => 'billing.ecole',
        'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name' => 'BILLING', 'first_name' => 'Ecole', 'status' => 'active',
    ], true);
    $users[] = $schoolAdmin;

    db_query(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :u, id FROM roles WHERE code = \'SCHOOL_ADMIN\' AND school_id IS NULL',
        ['u' => $schoolAdmin],
        true
    );

    $_SESSION['user_id']   = $editor;
    $_SESSION['school_id'] = null;
    auth_user(true); perm_all(true); perm_roles(true);
    tenant_set(null);

    // =================================================================
    echo "\n  LA FACTURATION EST RÉSERVÉE, ET DISTINCTE\n";

    check('Une permission PROPRE existe',
        db_exists('SELECT 1 FROM permissions WHERE code = :c',
            ['c' => 'platform.billing.manage'], true));

    $_SESSION['user_id']   = $schoolAdmin;
    $_SESSION['school_id'] = $schools['BILL-A'];
    auth_user(true); perm_all(true); perm_roles(true);
    tenant_set($schools['BILL-A']);

    check('Un compte d\'école ne l\'a pas',
        platform_refusal('platform.billing.manage') !== null);

    $_SESSION['user_id']   = $editor;
    $_SESSION['school_id'] = null;
    auth_user(true); perm_all(true); perm_roles(true);
    tenant_set(null);

    // =================================================================
    echo "\n  LE TARIF SE FIGE\n";

    platform_service_change_plan($schools['BILL-A'], [
        'plan_code' => 'ESSENTIEL', 'reason' => 'ouverture',
    ]);

    $sub = latest($schools['BILL-A']);

    check('Le tarif est enregistré sur l\'abonnement',
        (float) $sub['price_amount'] === 250.0, (string) $sub['price_amount']);

    check('Avec sa devise', (string) $sub['price_currency'] === 'USD');

    // LE CATALOGUE CHANGE — la dette ne doit pas bouger.
    platform_scope_cli(static fn () => db_query(
        'UPDATE plans SET price_yearly = 999 WHERE code = :c', ['c' => 'ESSENTIEL'], true
    ));

    check('Relever le catalogue ne réécrit PAS la dette engagée',
        (float) latest($schools['BILL-A'])['price_amount'] === 250.0);

    platform_scope_cli(static fn () => db_query(
        'UPDATE plans SET price_yearly = 250 WHERE code = :c', ['c' => 'ESSENTIEL'], true
    ));

    // Un tarif NÉGOCIÉ l'emporte.
    platform_service_change_plan($schools['BILL-A'], [
        'plan_code' => 'ESSENTIEL', 'price_amount' => 200, 'reason' => 'remise commerciale',
    ]);

    check('Un tarif négocié l\'emporte sur le catalogue',
        (float) latest($schools['BILL-A'])['price_amount'] === 200.0);

    // ZÉRO N'EST PAS VIDE.
    platform_service_change_plan($schools['BILL-A'], [
        'plan_code' => 'ESSENTIEL', 'price_amount' => 0, 'reason' => 'école pilote',
    ]);

    check('Zéro veut dire GRATUIT, pas « tarif du catalogue »',
        (float) latest($schools['BILL-A'])['price_amount'] === 0.0,
        (string) latest($schools['BILL-A'])['price_amount']);

    check('Un tarif négatif est refusé',
        !platform_service_change_plan($schools['BILL-A'],
            ['plan_code' => 'ESSENTIEL', 'price_amount' => -5])['ok']);

    // On repart sur un tarif normal pour la suite.
    platform_service_change_plan($schools['BILL-A'], [
        'plan_code' => 'ESSENTIEL', 'reason' => 'retour au tarif',
    ]);

    $subId = (int) latest($schools['BILL-A'])['id'];

    // =================================================================
    echo "\n  UNE DETTE SE SOLDE DANS SA DEVISE\n";

    $r = billing_service_record($schools['BILL-A'], [
        'subscription_id' => $subId, 'tendered_currency' => 'CDF', 'tendered_amount' => 700000,
    ]);

    check('Un versement en CDF sur une dette en USD SANS taux est refusé', !$r['ok']);
    check('Et le message dit pourquoi',
        str_contains($r['message'], 'taux'), $r['message']);

    $r = billing_service_record($schools['BILL-A'], [
        'subscription_id' => $subId, 'tendered_currency' => 'CDF', 'tendered_amount' => 700000,
        'exchange_rate' => 2800, 'provider' => 'M-Pesa', 'reference' => 'MP-TEST-1',
    ]);

    check('Avec le taux, il passe', $r['ok'], $r['message']);

    $row = platform_scope_cli(static fn (): ?array => db_one(
        'SELECT * FROM subscription_payments WHERE id = :id',
        ['id' => $GLOBALS['r']['payment_id']], true
    ));

    check('La somme REMISE est conservée dans sa monnaie',
        (float) $row['tendered_amount'] === 700000.0 && $row['tendered_currency'] === 'CDF');

    check('Le TAUX est figé sur le versement',
        (float) $row['exchange_rate'] === 2800.0);

    check('La somme CRÉDITÉE est dans la devise de la dette',
        (float) $row['amount'] === 250.0 && $row['currency'] === 'USD');

    // LE FRANC N'A PAS DE CENTIMES.
    platform_scope_cli(static fn () => db_query(
        'UPDATE plans SET currency = \'CDF\' WHERE code = :c', ['c' => 'PRO'], true
    ));

    platform_service_change_plan($schools['BILL-B'], ['plan_code' => 'PRO', 'reason' => 'dette en francs']);

    $subB = latest($schools['BILL-B']);

    check('Une dette en CDF est figée sans décimales',
        (float) $subB['price_amount'] === 600.0);

    $r2 = billing_service_record($schools['BILL-B'], [
        'subscription_id' => (int) $subB['id'], 'tendered_currency' => 'USD',
        'tendered_amount' => 0.37, 'exchange_rate' => 0.000357,
    ]);

    $credited = platform_scope_cli(static fn (): string => (string) db_value(
        'SELECT amount FROM subscription_payments WHERE id = :id',
        ['id' => $GLOBALS['r2']['payment_id']], true
    ));

    check('Le crédit en CDF est ENTIER', (float) $credited === floor((float) $credited),
        $credited);

    platform_scope_cli(static fn () => db_query(
        'UPDATE plans SET currency = \'USD\' WHERE code = :c', ['c' => 'PRO'], true
    ));

    // La règle doit être la MÊME que celle de la caisse scolaire.
    require_once APP_PATH . '/modules/finance/services.php';

    check('`billing_decimals` et `finance_decimals` disent la même chose',
        billing_decimals('CDF') === finance_decimals('CDF')
            && billing_decimals('USD') === finance_decimals('USD'));

    // =================================================================
    echo "\n  CE QUI COMPTE DANS UN SOLDE, ET CE QUI N'Y COMPTE PAS\n";

    $before = billing_school_balance($schools['BILL-A']);

    check('La dette est soldée', (float) $before['USD']['balance'] === 0.0,
        (string) $before['USD']['balance']);

    $pending = billing_service_record($schools['BILL-A'], [
        'subscription_id' => $subId, 'tendered_currency' => 'USD', 'tendered_amount' => 100,
        'status' => 'pending', 'provider' => 'Airtel', 'reference' => 'AT-TEST-1',
    ]);

    check('Un versement EN ATTENTE ne compte pas',
        (float) billing_school_balance($schools['BILL-A'])['USD']['paid']
            === (float) $before['USD']['paid']);

    billing_service_confirm((int) $pending['payment_id']);

    check('Une fois confirmé, il compte',
        (float) billing_school_balance($schools['BILL-A'])['USD']['paid']
            > (float) $before['USD']['paid']);

    check('Confirmer deux fois est refusé',
        !billing_service_confirm((int) $pending['payment_id'])['ok']);

    check('Une annulation sans motif est refusée',
        !billing_service_cancel((int) $pending['payment_id'], '')['ok']);

    check('Une annulation motivée passe',
        billing_service_cancel((int) $pending['payment_id'], 'attribué à la mauvaise école')['ok']);

    check('Le versement annulé sort du solde',
        (float) billing_school_balance($schools['BILL-A'])['USD']['paid']
            === (float) $before['USD']['paid']);

    check('Mais SA LIGNE RESTE, avec son motif',
        platform_scope_cli(static fn (): bool => db_exists(
            'SELECT 1 FROM subscription_payments
              WHERE id = :id AND cancelled_at IS NOT NULL AND cancelled_reason <> \'\'',
            ['id' => $GLOBALS['pending']['payment_id']], true
        )));

    check('Annuler deux fois est refusé',
        !billing_service_cancel((int) $pending['payment_id'], 'encore')['ok']);

    // =================================================================
    echo "\n  UN MÊME VERSEMENT NE S'ENCAISSE PAS DEUX FOIS\n";

    $dup = billing_service_record($schools['BILL-A'], [
        'subscription_id' => $subId, 'tendered_currency' => 'CDF', 'tendered_amount' => 700000,
        'exchange_rate' => 2800, 'provider' => 'M-Pesa', 'reference' => 'MP-TEST-1',
    ]);

    check('La même référence chez le même prestataire est refusée', !$dup['ok']);
    check('Et le message nomme la référence',
        str_contains($dup['message'], 'MP-TEST-1'), $dup['message']);

    $other = billing_service_record($schools['BILL-A'], [
        'subscription_id' => $subId, 'tendered_currency' => 'CDF', 'tendered_amount' => 700000,
        'exchange_rate' => 2800, 'provider' => 'Airtel', 'reference' => 'MP-TEST-1',
    ]);

    check('La même référence chez un AUTRE prestataire passe', $other['ok'], $other['message']);

    billing_service_cancel((int) $other['payment_id'], 'nettoyage du test');

    // =================================================================
    // AUDIT 7B2 — UNE RÉFÉRENCE ANNULÉE SE LIBÈRE
    //
    // Une erreur de frappe sur le montant s'annule, avec motif. Le
    // versement, lui, existe dans la vraie vie et porte exactement UNE
    // référence. Si elle restait consommée, l'éditeur devrait soit en
    // inventer une fausse, soit ne rien enregistrer — deux mensonges
    // pires que le doublon que le garde-fou voulait éviter.
    echo "\n  UNE RÉFÉRENCE ANNULÉE SE LIBÈRE — MAIS ELLE SEULE\n";

    $typo = billing_service_record($schools['BILL-A'], [
        'subscription_id' => $subId, 'tendered_currency' => 'CDF', 'tendered_amount' => 333333,
        'exchange_rate' => 2800, 'provider' => 'Equity', 'reference' => 'EQ-CORRECTION',
    ]);

    check('Un versement mal saisi s\'enregistre', $typo['ok'], $typo['message']);

    check('Tant qu\'il vit, sa référence est prise',
        !billing_service_record($schools['BILL-A'], [
            'subscription_id' => $subId, 'tendered_currency' => 'CDF', 'tendered_amount' => 350000,
            'exchange_rate' => 2800, 'provider' => 'Equity', 'reference' => 'EQ-CORRECTION',
        ])['ok']);

    check('Et le message dit COMMENT corriger',
        str_contains(billing_service_record($schools['BILL-A'], [
            'subscription_id' => $subId, 'tendered_currency' => 'CDF', 'tendered_amount' => 350000,
            'exchange_rate' => 2800, 'provider' => 'Equity', 'reference' => 'EQ-CORRECTION',
        ])['message'], 'annulez'));

    billing_service_cancel((int) $typo['payment_id'], 'erreur de frappe sur le montant');

    $redo = billing_service_record($schools['BILL-A'], [
        'subscription_id' => $subId, 'tendered_currency' => 'CDF', 'tendered_amount' => 350000,
        'exchange_rate' => 2800, 'provider' => 'Equity', 'reference' => 'EQ-CORRECTION',
    ]);

    check('Une fois annulée, la MÊME référence se ressaisit', $redo['ok'], $redo['message']);

    check('La ligne annulée est conservée',
        (int) platform_scope_cli(static fn (): int => (int) db_value(
            'SELECT COUNT(*) FROM subscription_payments WHERE id = :id',
            ['id' => $typo['payment_id']], true
        )) === 1);

    // LA BASE PORTE L'INVARIANT, PAS SEULEMENT LE SERVICE.
    // Un import ou une API mobile contourneraient le contrôle PHP.
    $bypass = null;

    try {
        platform_scope_cli(static fn () => db_insert('subscription_payments', [
            'school_id' => $schools['BILL-A'], 'subscription_id' => $subId,
            'tendered_currency' => 'CDF', 'tendered_amount' => 350000,
            'exchange_rate' => 2800, 'amount' => 125, 'currency' => 'USD',
            'method' => 'mobile_money', 'provider' => 'Equity',
            'reference' => 'EQ-CORRECTION', 'status' => 'confirmed',
            'paid_at' => date('Y-m-d H:i:s'),
        ], true));
    } catch (Throwable $e) {
        $bypass = $e->getMessage();
    }

    check('Contourner le service ne suffit pas : la BASE refuse',
        $bypass !== null && str_contains($bypass, '1062'));

    billing_service_cancel((int) $redo['payment_id'], 'nettoyage du test');

    // =================================================================
    echo "\n  CHANGER D'OFFRE NE FACTURE PAS DEUX PÉRIODES\n";

    $c = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'BILL-C', 'slug' => 'bill-c',
        'name' => 'École Gamma', 'status' => 'active',
    ], true);
    $schools['BILL-C'] = $c;

    // Un abonnement ouvert il y a six mois…
    platform_service_change_plan($c, [
        'plan_code' => 'ESSENTIEL',
        'starts_on' => date('Y-m-d', strtotime('-180 days')),
        'ends_on'   => date('Y-m-d', strtotime('+185 days')),
        'reason'    => 'ouverture',
    ]);

    // …remplacé aujourd'hui par une offre supérieure.
    platform_service_change_plan($c, ['plan_code' => 'PRO', 'reason' => 'montée de gamme']);

    $rows  = billing_repo_school_ledger($c);
    $total = 0.0;

    foreach ($rows as $row) {
        $total += (float) $row['due'];
    }

    check('Deux périodes existent', count($rows) === 2);

    check('Mais le total facturé N\'EST PAS 250 + 600',
        $total < 850.0, round($total, 2) . ' USD');

    $closed = null;

    foreach ($rows as $row) {
        if ($row['cancelled_at'] !== null) {
            $closed = $row;
        }
    }

    check('La période clôturée est facturée AU PRORATA',
        $closed !== null
            && (float) $closed['due'] > 0.0
            && (float) $closed['due'] < (float) $closed['price_amount'],
        $closed === null ? 'absente' : $closed['due'] . ' sur ' . $closed['price_amount']);

    // UNE PÉRIODE JAMAIS SERVIE NE SE FACTURE PAS.
    //
    // Corriger une saisie — appliquer deux offres le même jour — ne doit
    // rien coûter à l'école.
    $d = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'BILL-D', 'slug' => 'bill-d',
        'name' => 'École Delta', 'status' => 'active',
    ], true);
    $schools['BILL-D'] = $d;

    platform_service_change_plan($d, ['plan_code' => 'ESSENTIEL', 'reason' => 'erreur de saisie']);
    platform_service_change_plan($d, ['plan_code' => 'PRO', 'reason' => 'correction']);

    $sameDay = null;

    foreach (billing_repo_school_ledger($d) as $row) {
        if ($row['cancelled_at'] !== null) {
            $sameDay = $row;
        }
    }

    check('Une période clôturée le jour même est facturée ZÉRO',
        $sameDay !== null && (float) $sameDay['due'] === 0.0,
        $sameDay === null ? 'absente' : (string) $sameDay['due']);

    // =================================================================
    echo "\n  UN TROP-PERÇU SE VOIT\n";

    // Beta a versé 1 036 CDF sur une dette de 600.
    $balances = billing_repo_balances();
    $credit   = null;

    foreach ($balances as $b) {
        if ((float) $b['balance'] < 0) {
            $credit = $b;
        }
    }

    check('Un solde NÉGATIF apparaît dans la liste', $credit !== null,
        $credit === null ? 'aucun' : $credit['name'] . ' ' . $credit['balance'] . ' ' . $credit['currency']);

    $debt = null;

    foreach ($balances as $b) {
        if ((float) $b['balance'] > 0) {
            $debt = $b;
        }
    }

    check('Et les dettes aussi', $debt !== null);

    check('Chaque ligne porte SA devise',
        $credit !== null && in_array((string) $credit['currency'], ['USD', 'CDF'], true));

    // =================================================================
    // AUDIT 7B2 — ARCHIVER UNE ÉCOLE N'ÉTEINT PAS SA DETTE
    //
    // La liste joignait `schools ... AND deleted_at IS NULL` : archiver
    // une école mauvaise payeuse suffisait à effacer ce qu'elle devait.
    // C'est le défaut du bandeau de la 7B1, transposé à l'argent.
    echo "\n  ARCHIVER UNE ÉCOLE N'ÉTEINT PAS SA DETTE\n";

    $indebted = (int) $debt['id'];

    platform_scope_cli(static fn () => db_query(
        'UPDATE schools SET deleted_at = NOW() WHERE id = :id', ['id' => $indebted], true
    ));

    $after = array_values(array_filter(billing_repo_balances(),
        static fn (array $r): bool => (int) $r['id'] === $indebted));

    check('Une école archivée qui doit reste listée', $after !== [],
        count($after) . ' ligne(s)');

    check('Et l\'écran sait qu\'elle est archivée',
        $after !== [] && (int) $after[0]['archived'] === 1);

    check('Son solde est inchangé',
        $after !== [] && (float) $after[0]['balance'] === (float) $debt['balance']);

    platform_scope_cli(static fn () => db_query(
        'UPDATE schools SET deleted_at = NULL WHERE id = :id', ['id' => $indebted], true
    ));

    check('Une école à jour ne figure dans aucune des deux listes',
        !in_array(0.0, array_map(
            static fn (array $r): float => (float) $r['balance'],
            billing_repo_balances()
        ), true));

    // =================================================================
    echo "\n  ON N'ENCAISSE PAS SUR UNE DETTE INCONNUE\n";

    $legacy = platform_scope_cli(static fn (): int => db_insert('subscriptions', [
        'school_id' => $GLOBALS['schools']['BILL-B'],
        'plan_id' => (int) db_value("SELECT id FROM plans WHERE code = 'DECOUVERTE'", [], true),
        'status' => 'cancelled', 'billing_cycle' => 'yearly',
        'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
    ], true));

    check('Un abonnement sans tarif figé est compté à part',
        billing_unpriced_count($schools['BILL-B']) === 1);

    $r = billing_service_record($schools['BILL-B'], [
        'subscription_id' => $legacy, 'tendered_currency' => 'USD', 'tendered_amount' => 50,
    ]);

    check('Encaisser dessus est REFUSÉ', !$r['ok']);
    check('Et le message dit quoi faire',
        str_contains($r['message'], 'Appliquez'), $r['message']);

    check('Il n\'entre pas non plus dans le solde',
        !isset(billing_school_balance($schools['BILL-B'])['USD']));

    // Encaisser sur l'abonnement d'une AUTRE école est refusé.
    $cross = billing_service_record($schools['BILL-A'], [
        'subscription_id' => (int) latest($schools['BILL-B'])['id'],
        'tendered_currency' => 'USD', 'tendered_amount' => 10,
    ]);

    check('Encaisser sur l\'abonnement d\'une AUTRE école est refusé', !$cross['ok']);

    // =================================================================
    echo "\n  TOUT EST TRACÉ\n";

    foreach (['platform.billing.record', 'platform.billing.confirm', 'platform.billing.cancel'] as $action) {
        check('Une trace pour ' . $action,
            platform_scope_cli(static fn (): bool => db_exists(
                'SELECT 1 FROM audit_logs WHERE action = :a', ['a' => $action], true
            )));
    }
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    platform_scope_cli(static function () use ($schools, $users): void {
        foreach ($users as $u) {
            db_query('UPDATE users SET visiting_school_id = NULL WHERE id = :id', ['id' => $u], true);
        }

        foreach ($schools as $id) {
            db_query('DELETE FROM subscription_payments WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM subscriptions WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM audit_logs WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM users WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM schools WHERE id = :id', ['id' => $id], true);
        }

        foreach ($users as $u) {
            db_query('DELETE FROM user_roles WHERE user_id = :u', ['u' => $u], true);
            db_query('DELETE FROM users WHERE id = :id', ['id' => $u], true);
        }

        db_query('DELETE FROM audit_logs WHERE action LIKE :a AND school_id IS NULL',
            ['a' => 'platform.%'], true);

        // Le catalogue doit repartir intact, quoi qu'il arrive.
        db_query('UPDATE plans SET price_yearly = 250, currency = \'USD\' WHERE code = \'ESSENTIEL\'', [], true);
        db_query('UPDATE plans SET price_yearly = 600, currency = \'USD\' WHERE code = \'PRO\'', [], true);
    });
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
