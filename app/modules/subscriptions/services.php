<?php
/**
 * Module ABONNEMENTS — services (phase 7A).
 *
 * CE QU'UNE LIMITE ATTEINTE DOIT FAIRE, ET NE JAMAIS FAIRE
 * ========================================================
 * Elle refuse la CRÉATION suivante, avec un message qui nomme l'offre,
 * le plafond et la situation. Elle ne supprime rien, ne cache rien, ne
 * verrouille pas la lecture.
 *
 * Et surtout : elle ne bloque JAMAIS l'encaissement. Une école dont
 * l'abonnement a expiré doit pouvoir continuer à enregistrer l'argent
 * qu'elle reçoit — lui retirer sa caisse la punirait deux fois, et
 * l'empêcherait précisément de payer ce qu'elle nous doit.
 *
 * Retenir les données d'une école pour la contraindre à payer serait
 * une prise d'otage. Le produit refuse de grandir, il ne se retourne
 * pas contre son client.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * Durée de l'essai offert à une école nouvelle, en jours.
 *
 * Trente jours couvrent une rentrée : le temps de saisir les classes,
 * les élèves et de faire un premier bulletin. Une semaine ne prouverait
 * rien, un an ferait de l'essai une offre gratuite.
 */
const SUBSCRIPTION_TRIAL_DAYS = 30;

/**
 * Garantit qu'une école a un abonnement.
 *
 * À appeler à la création d'une école. Un abonnement absent n'est pas
 * « pas de limite » : c'est une situation que chaque écran devrait
 * interpréter, et deux écrans finiraient par l'interpréter autrement.
 */
function subscription_ensure(): ?array
{
    $existing = subscription_any();

    if ($existing !== null) {
        return $existing;
    }

    $plan = db_one(
        'SELECT id FROM plans WHERE code = :code AND is_active = 1 LIMIT 1',
        ['code' => 'DECOUVERTE'],
        true
    );

    if ($plan === null) {
        // Le catalogue est vide : on ne fabrique pas une offre au vol.
        // Mieux vaut une absence signalée qu'un plan inventé.
        log_warning('Aucune offre DECOUVERTE au catalogue : abonnement non créé', [
            'school_id' => tenant_require(),
        ]);

        return null;
    }

    tenant_insert('subscriptions', [
        'plan_id'       => (int) $plan['id'],
        'status'        => 'trial',
        'billing_cycle' => 'yearly',
        'starts_on'     => date('Y-m-d'),
        'ends_on'       => date('Y-m-d', strtotime('+' . SUBSCRIPTION_TRIAL_DAYS . ' days')),
        'auto_renew'    => 0,
    ]);

    return subscription_any();
}

/**
 * L'abonnement à AFFICHER — et c'est le même qui fait foi.
 *
 * UN ÉCRAN, UNE VÉRITÉ.
 * --------------------
 * L'audit 7A a trouvé le contraire. Le contrôleur lisait
 * `subscription_any()` pour la carte tandis que les jauges lisaient les
 * limites de `subscription_current()`. Scénario réel : une école résilie
 * son offre annuelle en cours de terme — la résiliation garde la date
 * payée, loin devant — puis souscrit une offre mensuelle. Deux lignes
 * coexistent, `any()` rend la plus lointaine, donc la RÉSILIÉE.
 * Résultat à l'écran : « Réseau scolaire — résilié — 300 jours
 * restants », à une école qui paie une offre Essentiel active.
 *
 * L'abonnement qui GOUVERNE est celui qui s'affiche.
 * `subscription_any()` ne sert plus qu'au repli : montrer la trace d'un
 * abonnement éteint valait mieux qu'une page vide, et c'était sa seule
 * raison d'être.
 */
function subscription_to_show(): ?array
{
    return subscription_current() ?? subscription_any();
}

/**
 * Les limites EFFECTIVES d'une école.
 *
 * `max_students_override` sur l'abonnement l'emporte sur l'offre : une
 * école qui négocie 900 élèves sur une offre à 600 ne doit pas obliger
 * l'éditeur à créer une offre sur mesure.
 *
 * NULL = illimité, et c'est explicite dans le schéma (l'offre RÉSEAU
 * porte NULL). Ne jamais traduire NULL par 0 : le plus grand plan
 * deviendrait le plus petit.
 *
 * @return array{students: ?int, staff_users: ?int, storage_mb: ?int}
 */
function subscription_limits(): array
{
    $sub = subscription_current();

    if ($sub === null) {
        // Sans abonnement actif, aucune création n'est autorisée — mais
        // la lecture reste entière. Voir subscription_can_add_student().
        return ['students' => 0, 'staff_users' => 0, 'storage_mb' => 0];
    }

    $students = $sub['max_students_override'] !== null
        ? (int) $sub['max_students_override']
        : ($sub['max_students'] !== null ? (int) $sub['max_students'] : null);

    return [
        'students'    => $students,
        'staff_users' => $sub['max_users'] !== null ? (int) $sub['max_users'] : null,
        'storage_mb'  => $sub['max_storage_mb'] !== null ? (int) $sub['max_storage_mb'] : null,
    ];
}

/**
 * Où en est l'école de ses limites ?
 *
 * DEUX CORRECTIONS D'AUDIT VIVENT ICI.
 * ===================================
 *
 * 1. LE DÉCOMPTE PORTE SUR LES MÊMES ANNÉES QUE LA LIMITE.
 *    Cette fonction comptait l'année courante, alors que le refus se
 *    mesure sur l'année visée. Une école qui remplissait sa rentrée
 *    d'avance lisait « 1 / 3 — 2 places libres » pendant que
 *    l'inscription refusait. Une jauge et une règle qui ne mesurent pas
 *    la même chose finissent toujours par se contredire devant
 *    l'utilisateur.
 *
 * 2. ZÉRO N'EST PAS UN PLAFOND D'OFFRE.
 *    Sans abonnement actif, `subscription_limits()` rend 0 : c'est la
 *    bonne réponse pour DÉCIDER — rien ne peut se créer — mais affiché
 *    tel quel, l'écran annonçait « 1 / 0 élève », soit un plafond de
 *    zéro élève qui ne veut rien dire. L'absence d'abonnement est un
 *    état à NOMMER, pas un plafond à afficher : `governed` le porte, et
 *    la vue l'écrit en mots.
 *
 * @return array{
 *     governed: bool,
 *     years: array<int, array{id: int, name: string, is_current: bool,
 *                             used: int, limit: ?int, remaining: ?int}>,
 *     staff_users: array{used: int, limit: ?int, remaining: ?int},
 *     family_users: array{used: int, limit: ?int, remaining: ?int}
 * }
 */
function subscription_usage(): array
{
    $governed = subscription_current() !== null;
    $limits   = subscription_limits();

    // Sans abonnement qui gouverne, aucun plafond n'est à AFFICHER :
    // les décomptes restent vrais, les limites n'ont pas de valeur.
    $studentLimit = $governed ? $limits['students'] : null;
    $staffLimit   = $governed ? $limits['staff_users'] : null;

    $years = [];

    foreach (subscription_countable_years() as $year) {
        $used = subscription_student_count($year['id']);

        $years[] = $year + [
            'used'      => $used,
            'limit'     => $studentLimit,
            'remaining' => $studentLimit === null ? null : max(0, $studentLimit - $used),
        ];
    }

    $staff = subscription_staff_user_count();

    return [
        'governed' => $governed,
        'years'    => $years,
        'staff_users' => [
            'used'      => $staff,
            'limit'     => $staffLimit,
            'remaining' => $staffLimit === null ? null : max(0, $staffLimit - $staff),
        ],
        // Affiché, jamais limité : ouvrir le portail aux familles ne
        // doit pas coûter une montée de gamme.
        'family_users' => [
            'used'      => subscription_family_user_count(),
            'limit'     => null,
            'remaining' => null,
        ],
    ];
}

/**
 * L'école peut-elle inscrire un élève de plus ?
 *
 * @return array{ok: bool, message: string}
 */
function subscription_can_add_student(?int $yearId = null): array
{
    // L'INVARIANT SE GARANTIT SUR LE CHEMIN D'ÉCRITURE.
    //
    // « Toute école a un abonnement » doit être vrai au moment où la
    // règle s'applique. La migration 026 l'a établi pour les écoles
    // existantes, mais aucun service ne crée encore une école : sans ce
    // rattrapage, une école née après la migration se verrait refuser
    // sa première inscription pour une raison qu'elle ne comprendrait
    // pas.
    //
    // L'appel est ici et non dans `subscription_current()` : une lecture
    // ne doit pas écrire. Le rattrapage a lieu là où une écriture est de
    // toute façon demandée.
    subscription_ensure();

    $sub = subscription_current();

    if ($sub === null) {
        return [
            'ok'      => false,
            'message' => 'Cet établissement n\'a pas d\'abonnement actif : aucune nouvelle '
                . 'inscription n\'est possible. Les dossiers existants restent consultables. '
                . 'Contactez l\'éditeur pour réactiver l\'abonnement.',
        ];
    }

    // UN ABONNEMENT EXPIRÉ NE BLOQUE PAS LE JOUR MÊME.
    //
    // `past_due` est un statut de RETARD, pas de rupture : une école qui
    // paie avec trois jours de décalage ne doit pas voir sa rentrée
    // s'arrêter. C'est la suspension, décidée par l'éditeur, qui coupe.
    $limits = subscription_limits();

    if ($limits['students'] === null) {
        return ['ok' => true, 'message' => ''];
    }

    $used = subscription_student_count($yearId);

    if ($used < $limits['students']) {
        return ['ok' => true, 'message' => ''];
    }

    return [
        'ok'      => false,
        'message' => 'L\'offre ' . $sub['plan_name'] . ' est limitée à '
            . $limits['students'] . ' élèves, et ' . $used . ' sont déjà inscrits '
            . 'pour cette année. Aucune donnée n\'est perdue : les dossiers '
            . 'restent entiers et consultables. Pour inscrire davantage d\'élèves, '
            . 'changez d\'offre auprès de l\'éditeur.',
    ];
}

/**
 * L'école peut-elle créer un compte de PERSONNEL de plus ?
 *
 * Les comptes de familles ne passent jamais par ici : ils ne comptent
 * pas dans la limite (voir subscription_staff_user_count()).
 *
 * @return array{ok: bool, message: string}
 */
function subscription_can_add_staff_user(): array
{
    subscription_ensure();

    $sub = subscription_current();

    if ($sub === null) {
        return [
            'ok'      => false,
            'message' => 'Cet établissement n\'a pas d\'abonnement actif : aucun nouveau '
                . 'compte ne peut être créé.',
        ];
    }

    $limits = subscription_limits();

    if ($limits['staff_users'] === null) {
        return ['ok' => true, 'message' => ''];
    }

    $used = subscription_staff_user_count();

    if ($used < $limits['staff_users']) {
        return ['ok' => true, 'message' => ''];
    }

    return [
        'ok'      => false,
        'message' => 'L\'offre ' . $sub['plan_name'] . ' est limitée à '
            . $limits['staff_users'] . ' comptes du personnel, et ' . $used
            . ' sont déjà ouverts. Les comptes des familles — parents et élèves — '
            . 'ne comptent pas dans cette limite.',
    ];
}

/**
 * Jours restants avant l'échéance — négatif si elle est passée.
 *
 * Mesurés sur l'abonnement AFFICHÉ, jamais sur `subscription_any()` :
 * l'audit 7A a vu cette fonction annoncer 300 jours en lisant
 * l'échéance d'un abonnement résilié, à une école dont l'offre active
 * expirait sous 30 jours.
 */
function subscription_days_left(): ?int
{
    $sub = subscription_to_show();

    if ($sub === null) {
        return null;
    }

    $end = strtotime((string) $sub['ends_on'] . ' 23:59:59');

    return (int) floor(($end - time()) / 86400);
}
