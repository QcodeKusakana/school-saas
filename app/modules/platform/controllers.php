<?php
/**
 * Module PLATEFORME — contrôleurs (phase 7B).
 *
 * Ces écrans appartiennent à l'ÉDITEUR, pas aux écoles. Ils n'ont donc
 * pas le middleware `school` : ils vivent hors de tout établissement,
 * et c'est `platform_scope()` — ouvert par le dépôt et les services —
 * qui vérifie l'habilitation.
 *
 * La permission de route reste posée en plus : une défense qui se
 * répète à deux niveaux résiste à la disparition de l'un des deux.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';

/** La liste des établissements — la porte d'entrée de la console. */
function ctrl_platform_schools(): void
{
    $filters = [
        'q'      => (string) input('q', ''),
        'status' => (string) input('status', ''),
    ];

    view('platform/schools', [
        'title'    => 'Établissements',
        'schools'  => platform_repo_schools($filters),
        'filters'  => $filters,
        'visiting' => platform_visiting_school(),
    ], 'app');
}

/** L'éditeur entre dans une école cliente. */
function ctrl_platform_enter_school(string $id): void
{
    csrf_verify();

    $result = platform_service_enter_school((int) $id);

    $result['ok']
        ? flash_success($result['message'])
        : flash_error($result['message']);

    redirect($result['ok'] ? '/tableau-de-bord' : '/plateforme/ecoles');
}

/** …et il en sort. */
function ctrl_platform_leave_school(): void
{
    csrf_verify();

    $result = platform_service_leave_school();

    flash_success($result['message']);

    redirect('/plateforme/ecoles');
}

/**
 * La veille des abonnements.
 *
 * L'écran ne liste PAS tout le parc : il ne montre que ce qui demande
 * une décision — ce qui expire, ce qui n'a plus d'abonnement, ce qui
 * dépasse son plafond. Une console qui affiche tout n'affiche rien.
 */
function ctrl_platform_subscriptions(): void
{
    view('platform/subscriptions', [
        'title'     => 'Abonnements',
        'watch'     => platform_repo_subscription_watch(30),
        'conflicts' => platform_repo_subscription_conflicts(),
        'plans'     => db_all(
            'SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_yearly',
            [],
            true
        ),
        'visiting'  => platform_visiting_school(),
    ], 'app');
}

/** Le détail d'une école : son offre, son historique, ses leviers. */
function ctrl_platform_school_show(string $id): void
{
    $schoolId = (int) $id;
    $school   = platform_repo_school($schoolId);

    if ($school === null) {
        abort(404, 'Établissement introuvable.');
    }

    view('platform/school', [
        'title'         => $school['name'],
        'school'        => $school,
        'subscriptions' => platform_repo_school_subscriptions($schoolId),
        'plans'         => db_all(
            'SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_yearly',
            [],
            true
        ),
        'visiting'      => platform_visiting_school(),
    ], 'app');
}

/** Applique une offre à une école. */
function ctrl_platform_change_plan(string $id): void
{
    csrf_verify();

    $schoolId = (int) $id;

    $override = trim((string) input('max_students_override', ''));

    $result = platform_service_change_plan($schoolId, [
        'plan_code'             => (string) input('plan_code', ''),
        'billing_cycle'         => (string) input('billing_cycle', 'yearly'),
        'starts_on'             => (string) input('starts_on', date('Y-m-d')),
        'ends_on'               => (string) input('ends_on', ''),
        // Un champ vide RETIRE le plafond négocié ; il ne vaut pas zéro.
        'max_students_override' => $override === '' ? null : (int) $override,
        // Vide = le tarif du catalogue. « 0 » est une valeur, pas un
        // vide : une école pilote se facture zéro, et le distinguer
        // évite de facturer un partenaire.
        'price_amount'          => trim((string) input('price_amount', '')) === ''
            ? null
            : (float) input('price_amount', ''),
        'auto_renew'            => (bool) input('auto_renew', false),
        'reason'                => (string) input('reason', ''),
    ]);

    $result['ok']
        ? flash_success($result['message'])
        : flash_error($result['message']);

    redirect('/plateforme/ecoles/' . $schoolId);
}

/** Suspend, réactive, ou marque un retard de paiement. */
function ctrl_platform_subscription_status(string $id): void
{
    csrf_verify();

    $schoolId = (int) $id;

    $result = platform_service_set_subscription_status(
        $schoolId,
        (string) input('status', ''),
        (string) input('reason', '')
    );

    $result['ok']
        ? flash_success($result['message'])
        : flash_error($result['message']);

    redirect('/plateforme/ecoles/' . $schoolId);
}

// =====================================================================
//  FACTURATION SaaS — phase 7B2
// =====================================================================

require_once __DIR__ . '/billing.php';

/** Le relevé d'une école : ce qu'elle doit, ce qu'elle a versé. */
function ctrl_platform_billing_school(string $id): void
{
    $schoolId = (int) $id;
    $school   = platform_repo_school($schoolId);

    if ($school === null) {
        abort(404, 'Établissement introuvable.');
    }

    view('platform/billing', [
        'title'    => 'Facturation — ' . $school['name'],
        'school'   => $school,
        'ledger'   => billing_repo_school_ledger($schoolId),
        'payments' => billing_repo_payments($schoolId),
        'balance'  => billing_school_balance($schoolId),
        'unpriced' => billing_unpriced_count($schoolId),
        'visiting' => platform_visiting_school(),
    ], 'app');
}

/** La vue transversale : qui doit, et à qui l'on doit. */
function ctrl_platform_billing_index(): void
{
    view('platform/billing_index', [
        'title'    => 'Soldes',
        'balances' => billing_repo_balances(),
        'visiting' => platform_visiting_school(),
    ], 'app');
}

/** Enregistre un versement. */
function ctrl_platform_billing_record(string $id): void
{
    csrf_verify();

    $schoolId = (int) $id;
    $rate     = trim((string) input('exchange_rate', ''));

    $result = billing_service_record($schoolId, [
        'subscription_id'   => (int) input('subscription_id', 0),
        'tendered_currency' => (string) input('tendered_currency', ''),
        'tendered_amount'   => (string) input('tendered_amount', '0'),
        // Un champ vide n'est PAS un taux de zéro : c'est l'absence de
        // taux, que le service interprète selon les devises en jeu.
        'exchange_rate'     => $rate === '' ? null : $rate,
        'method'            => (string) input('method', 'mobile_money'),
        'provider'          => (string) input('provider', ''),
        'reference'         => (string) input('reference', ''),
        'status'            => (string) input('status', 'confirmed'),
        'paid_at'           => (string) input('paid_at', date('Y-m-d H:i:s')),
        'notes'             => (string) input('notes', ''),
    ]);

    $result['ok'] ? flash_success($result['message']) : flash_error($result['message']);

    redirect('/plateforme/ecoles/' . $schoolId . '/facturation');
}

/** Confirme un versement resté en attente. */
function ctrl_platform_billing_confirm(string $id, string $paymentId): void
{
    csrf_verify();

    $result = billing_service_confirm((int) $paymentId);

    $result['ok'] ? flash_success($result['message']) : flash_error($result['message']);

    redirect('/plateforme/ecoles/' . (int) $id . '/facturation');
}

/** Annule un versement — sans l'effacer. */
function ctrl_platform_billing_cancel(string $id, string $paymentId): void
{
    csrf_verify();

    $result = billing_service_cancel((int) $paymentId, (string) input('reason', ''));

    $result['ok'] ? flash_success($result['message']) : flash_error($result['message']);

    redirect('/plateforme/ecoles/' . (int) $id . '/facturation');
}

// ---------------------------------------------------------------------
//  LE JOURNAL GLOBAL — phase 9B
//
// `platform.audit.view` était semée depuis la phase 1, et le lien de la
// barre latérale était masqué par `route_exists()` faute d'écran.
//
// LA LECTURE EST INTER-ÉCOLES, DONC ELLE PASSE PAR LE PÉRIMÈTRE.
// `platform_scope()` vérifie d'abord l'habilitation ; sans lui, le
// garde-fou refuse la requête — et c'est bien ce qu'on veut.
// ---------------------------------------------------------------------

function ctrl_platform_audit(): void
{
    require_once APP_PATH . '/modules/audit/services.php';

    $filtres = [
        'school' => (string) input('ecole', ''),
        'action' => (string) input('action', ''),
        'entity' => (string) input('entite', ''),
        'du'     => (string) input('du', ''),
        'au'     => (string) input('au', ''),
    ];

    $page = max(1, (int) input_int('page', 1));

    [$resultat, $ecoles, $actions] = platform_scope(
        'platform.audit.view',
        static function () use ($filtres, $page): array {
            return [
                audit_repo_search_platform($filtres, $page),
                db_all(
                    'SELECT id, code, name FROM schools ORDER BY name',
                    [],
                    true
                ),
                array_map(
                    static fn (array $r): string => (string) $r['action'],
                    db_all('SELECT DISTINCT action FROM audit_logs ORDER BY action', [], true)
                ),
            ];
        }
    );

    view('platform/audit', [
        'title'   => 'Journal global',
        'entrees' => $resultat['rows'],
        'total'   => $resultat['total'],
        'pages'   => $resultat['pages'],
        'page'    => $resultat['page'],
        'filtres' => $filtres,
        'ecoles'  => $ecoles,
        'actions' => $actions,
    ], 'app');
}
