<?php
/**
 * Module PLATEFORME — services (phase 7B).
 *
 * CE QUE CE MODULE PORTE
 * ======================
 *  1. L'INVARIANT « une école, un abonnement en cours ». Le schéma ne
 *     peut pas le tenir : MySQL n'exprime pas un UNIQUE conditionnel sur
 *     `status IN ('trial','active','past_due')`. C'est donc le service
 *     qui le tient — à condition d'être le SEUL créateur d'abonnements.
 *
 *  2. L'ENTRÉE DE L'ÉDITEUR DANS UNE ÉCOLE CLIENTE. Un acte qui doit
 *     être tracé, visible, et réversible.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

// `billing_round()` fige le tarif à la précision réelle de sa devise :
// le service en a besoin dès qu'il applique une offre.
require_once __DIR__ . '/billing.php';

/**
 * Change l'offre d'une école — le SEUL chemin.
 *
 * POURQUOI CLÔTURER PUIS OUVRIR, PLUTÔT QUE MUTER
 * ------------------------------------------------
 * Muter la ligne existante serait plus simple et perdrait l'historique :
 * une école passée de Découverte à Essentiel puis à Pro ne pourrait plus
 * le prouver. Or c'est la base de la facturation (7B2) et la réponse à
 * « depuis quand payons-nous ce tarif ? ».
 *
 * On clôture donc l'abonnement en cours et on en ouvre un nouveau, dans
 * une TRANSACTION, avec un `SELECT … FOR UPDATE` sur les lignes de
 * l'école — le motif éprouvé depuis l'interblocage de la phase 5B.
 * Sans le verrou, deux changements simultanés laisseraient l'école avec
 * deux abonnements actifs, exactement ce que l'invariant interdit.
 *
 * LA DATE DE DÉPART N'EST PAS AUJOURD'HUI PAR PRINCIPE.
 * Une école qui monte de gamme en cours de terme démarre le jour même ;
 * un renouvellement anticipé démarre à la fin de l'abonnement courant.
 * L'appelant tranche, le service refuse l'incohérent.
 *
 * @param array{plan_code: string, billing_cycle?: string, starts_on?: string,
 *              ends_on?: string, max_students_override?: int|null,
 *              auto_renew?: bool, reason?: string} $input
 * @return array{ok: bool, message: string, subscription_id: ?int}
 */
function platform_service_change_plan(int $schoolId, array $input): array
{
    return platform_scope('platform.subscription.manage', static function () use ($schoolId, $input): array {
        $school = db_one(
            'SELECT id, name FROM schools WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $schoolId],
            true
        );

        if ($school === null) {
            return ['ok' => false, 'message' => 'Établissement introuvable.', 'subscription_id' => null];
        }

        $plan = db_one(
            'SELECT * FROM plans WHERE code = :code AND is_active = 1 LIMIT 1',
            ['code' => (string) ($input['plan_code'] ?? '')],
            true
        );

        if ($plan === null) {
            return [
                'ok'              => false,
                'message'         => 'Offre inconnue ou retirée du catalogue.',
                'subscription_id' => null,
            ];
        }

        $cycle = (string) ($input['billing_cycle'] ?? 'yearly');

        if (!in_array($cycle, ['monthly', 'yearly'], true)) {
            return ['ok' => false, 'message' => 'Cycle de facturation invalide.', 'subscription_id' => null];
        }

        $startsOn = (string) ($input['starts_on'] ?? date('Y-m-d'));
        $endsOn   = (string) ($input['ends_on'] ?? '');

        if ($endsOn === '') {
            $endsOn = $cycle === 'monthly'
                ? date('Y-m-d', strtotime($startsOn . ' +1 month'))
                : date('Y-m-d', strtotime($startsOn . ' +1 year'));
        }

        if (strtotime($endsOn) <= strtotime($startsOn)) {
            return [
                'ok'              => false,
                'message'         => 'L\'échéance doit être postérieure à la date de départ.',
                'subscription_id' => null,
            ];
        }

        $override = $input['max_students_override'] ?? null;

        if ($override !== null && (int) $override < 1) {
            return [
                'ok'              => false,
                'message'         => 'Un plafond négocié doit valoir au moins 1 élève. '
                    . 'Pour retirer le plafond négocié, laissez le champ vide.',
                'subscription_id' => null,
            ];
        }

        // UN PRIX NÉGOCIÉ, VALIDÉ COMME LE RESTE.
        //
        // Vide = le tarif du catalogue. Zéro est ACCEPTÉ et signifie
        // gratuit — une école pilote, un partenariat. C'est un cas réel,
        // pas une erreur de saisie : le distinguer du vide est
        // précisément ce qui évite de facturer un partenaire.
        $priceInput = $input['price_amount'] ?? null;

        if ($priceInput !== null && (float) $priceInput < 0) {
            return [
                'ok'              => false,
                'message'         => 'Un tarif négocié ne peut pas être négatif. '
                    . 'Pour une offre gratuite, saisissez 0 ; pour le tarif du '
                    . 'catalogue, laissez le champ vide.',
                'subscription_id' => null,
            ];
        }

        $priceOverride = $priceInput === null ? null : (float) $priceInput;

        // `db_transaction()` rejoue sur interblocage — le motif établi
        // depuis la phase 5B, où deux guichets simultanés faisaient
        // tomber l'un des deux sur une page d'erreur.
        [$newId, $closed] = db_transaction(
            static function () use ($schoolId, $plan, $cycle, $startsOn, $endsOn, $override, $priceOverride, $input): array {
                // LE VERROU EST LA CONDITION DE L'INVARIANT.
                //
                // Il sérialise deux changements simultanés sur la même
                // école. Sans lui, les deux liraient « un abonnement en
                // cours », les deux le clôtureraient, et les deux en
                // ouvriraient un : l'école finirait à deux.
                $current = db_all(
                    'SELECT id FROM subscriptions
                      WHERE school_id = :school_id
                        AND status IN (\'trial\', \'active\', \'past_due\')
                      FOR UPDATE',
                    ['school_id' => $schoolId],
                    true
                );

                foreach ($current as $row) {
                    db_query(
                        'UPDATE subscriptions
                            SET status = \'cancelled\', cancelled_at = NOW()
                          WHERE id = :id AND school_id = :school_id',
                        ['id' => (int) $row['id'], 'school_id' => $schoolId],
                        true
                    );
                }

                // LE TARIF SE FIGE ICI — phase 7B2.
                //
                // Le lire dans `plans` au moment de facturer laisserait
                // le catalogue réécrire ce que doivent toutes les
                // écoles, y compris pour des périodes déjà servies et
                // déjà payées. C'est le défaut de la phase 5A, et sa
                // réponse : `student_fees.amount_due` fige, parce que
                // relever le minerval en janvier ne réécrit pas
                // septembre.
                //
                // Un prix NÉGOCIÉ l'emporte sur celui du catalogue :
                // une école peut avoir obtenu 200 au lieu de 250.
                $price = $priceOverride ?? (float) ($cycle === 'monthly'
                    ? $plan['price_monthly']
                    : $plan['price_yearly']);

                $id = db_insert('subscriptions', [
                    'school_id'             => $schoolId,
                    'plan_id'               => (int) $plan['id'],
                    'status'                => 'active',
                    'billing_cycle'         => $cycle,
                    'price_amount'          => billing_round($price, (string) $plan['currency']),
                    'price_currency'        => (string) $plan['currency'],
                    'starts_on'             => $startsOn,
                    'ends_on'               => $endsOn,
                    'max_students_override' => $override === null ? null : (int) $override,
                    'auto_renew'            => !empty($input['auto_renew']) ? 1 : 0,
                ], true);

                return [$id, count($current)];
            }
        );

        // LA TRACE PORTE LE MOTIF, PAS SEULEMENT LE RÉSULTAT.
        // « Passée en Pro » ne dit pas pourquoi ; « Pro — dépassement de
        // plafond constaté, accord commercial du 20/09 » le dit.
        audit_log('platform.plan.change', 'subscriptions', $newId, null, [
            'school'    => $school['name'],
            'plan'      => $plan['code'],
            'cycle'     => $cycle,
            'starts_on' => $startsOn,
            'ends_on'   => $endsOn,
            'override'  => $override,
            'price'     => $priceOverride,
            'closed'    => $closed,
            'reason'    => trim((string) ($input['reason'] ?? '')),
        ]);

        return [
            'ok'              => true,
            'message'         => 'Offre ' . $plan['name'] . ' appliquée à ' . $school['name']
                . ' jusqu\'au ' . date('d/m/Y', strtotime($endsOn)) . '.'
                . ($closed > 0 ? ' L\'abonnement précédent est clôturé.' : ''),
            'subscription_id' => $newId,
        ];
    });
}

/**
 * Suspend ou réactive l'abonnement d'une école.
 *
 * CE QUE LA SUSPENSION FAIT, ET NE FAIT JAMAIS.
 * --------------------------------------------
 * Elle empêche les CRÉATIONS — inscriptions, comptes. Elle ne ferme ni
 * la lecture des dossiers, ni la caisse (phase 7A). Retenir l'argent
 * d'une école pour la contraindre à payer serait une prise d'otage, et
 * l'empêcherait précisément de payer.
 *
 * @return array{ok: bool, message: string}
 */
function platform_service_set_subscription_status(int $schoolId, string $status, string $reason = ''): array
{
    return platform_scope('platform.subscription.manage', static function () use ($schoolId, $status, $reason): array {
        if (!in_array($status, ['active', 'past_due', 'suspended'], true)) {
            return [
                'ok'      => false,
                'message' => 'Statut invalide. La résiliation passe par un changement d\'offre, '
                    . 'pour que l\'école ne se retrouve jamais sans abonnement en cours.',
            ];
        }

        $sub = db_one(
            'SELECT sub.id, sub.status, s.name
               FROM subscriptions sub
               JOIN schools s ON s.id = sub.school_id
              WHERE sub.school_id = :school_id
                AND sub.status IN (\'trial\', \'active\', \'past_due\', \'suspended\')
              ORDER BY sub.ends_on DESC, sub.id DESC
              LIMIT 1',
            ['school_id' => $schoolId],
            true
        );

        if ($sub === null) {
            return ['ok' => false, 'message' => 'Cet établissement n\'a aucun abonnement à modifier.'];
        }

        if ($status === 'suspended' && trim($reason) === '') {
            // UNE SUSPENSION SANS MOTIF EST INDÉFENDABLE.
            // L'école demandera pourquoi ; l'éditeur doit pouvoir le dire
            // six mois plus tard.
            return [
                'ok'      => false,
                'message' => 'Une suspension doit porter un motif : l\'école le demandera.',
            ];
        }

        db_query(
            'UPDATE subscriptions SET status = :status WHERE id = :id AND school_id = :school_id',
            ['status' => $status, 'id' => (int) $sub['id'], 'school_id' => $schoolId],
            true
        );

        audit_log('platform.subscription.status', 'subscriptions', (int) $sub['id'],
            ['status' => $sub['status']],
            ['status' => $status, 'school' => $sub['name'], 'reason' => trim($reason)]
        );

        $labels = [
            'active'    => 'réactivé',
            'past_due'  => 'marqué en retard de paiement',
            'suspended' => 'suspendu',
        ];

        return [
            'ok'      => true,
            'message' => 'Abonnement de ' . $sub['name'] . ' ' . $labels[$status] . '.',
        ];
    });
}

/**
 * L'éditeur ENTRE dans une école cliente.
 *
 * TROIS EXIGENCES, ET AUCUNE N'EST OPTIONNELLE
 * ============================================
 *  1. TRACÉ — ouvrir le dossier d'une école cliente laisse une trace.
 *     Ce n'est pas une commodité technique, c'est ce qui rend la
 *     relation défendable : l'éditeur peut prouver ce qu'il a consulté,
 *     et l'école peut le lui demander.
 *  2. VISIBLE — un bandeau permanent dit dans quelle école on se trouve.
 *     Sans signal, on modifie les données d'un client en croyant être
 *     chez soi.
 *  3. RÉVERSIBLE — `platform_service_leave_school()`. Un produit qui
 *     fait entrer doit savoir faire sortir.
 *
 * ⚠️ DÉCISION OUVERTE, SIGNALÉE PLUTÔT QUE PRISE EN SILENCE.
 * L'ouverture donne aujourd'hui l'accès COMPLET : le super
 * administrateur conserve toutes ses permissions dans le contexte de
 * l'école, ce qui était déjà vrai avant cet écran. Un accès en lecture
 * seule serait défendable — l'éditeur n'a pas à corriger une cote — mais
 * il empêcherait le dépannage, et il demande de filtrer les permissions
 * par contexte : son propre chantier. Voir claude/phase-7b-console.md.
 *
 * @return array{ok: bool, message: string}
 */
function platform_service_enter_school(int $schoolId): array
{
    $school = platform_repo_school($schoolId);

    if ($school === null) {
        return ['ok' => false, 'message' => 'Établissement introuvable.'];
    }

    // CHANGER D'ÉCOLE FERME LA PRÉCÉDENTE — audit 7B1.
    //
    // Sans cela, passer de l'école A à l'école B laissait DEUX entrées
    // et AUCUNE sortie dans le journal : rien ne disait quand l'éditeur
    // avait quitté A. Or c'est exactement la question qu'une école
    // poserait — « jusqu'à quand avez-vous eu accès à nos données ? ».
    //
    // > Une trace qui note les entrées sans les sorties ne date rien.
    $previous = auth_user()['visiting_school_id'] ?? null;

    if ($previous !== null && (int) $previous !== (int) $school['id']) {
        platform_service_leave_school();
    }

    // LA VISITE EST UN ÉTAT DE LA BASE, PAS DE LA SESSION.
    //
    // `auth_user()` réétablit le contexte depuis la base à chaque
    // requête : une visite posée en session était effacée avant la
    // page suivante — mesuré par la sonde, le bandeau annonçait
    // l'école et `tenant_id()` valait NULL.
    //
    // La colonne, elle, tient. Et comme seul ce service l'écrit, une
    // session bricolée ne permet pas d'entrer sans laisser de trace.
    tenant_scope_identity(static function () use ($school): void {
        db_query(
            'UPDATE users SET visiting_school_id = :school
              WHERE id = :id AND school_id IS NULL',
            ['school' => (int) $school['id'], 'id' => (int) auth_user()['id']],
            true
        );
    });

    $_SESSION['school_id'] = (int) $school['id'];

    tenant_set((int) $school['id']);
    auth_user(true);
    perm_all(true);
    perm_roles(true);
    school_settings_all(true);

    audit_log('platform.school.enter', 'schools', (int) $school['id'], null, [
        'school' => $school['name'],
        'code'   => $school['code'],
    ]);

    return [
        'ok'      => true,
        'message' => 'Vous travaillez maintenant dans « ' . $school['name'] . ' ».',
    ];
}

/** L'éditeur SORT de l'école et retrouve son contexte plateforme. */
function platform_service_leave_school(): array
{
    $user     = auth_user();
    $schoolId = $user['visiting_school_id'] ?? null;

    if ($schoolId !== null) {
        $school = db_one(
            'SELECT name FROM schools WHERE id = :id LIMIT 1',
            ['id' => (int) $schoolId],
            true
        );

        audit_log('platform.school.leave', 'schools', (int) $schoolId, null, [
            'school' => $school['name'] ?? '(supprimée)',
        ]);
    }

    tenant_scope_identity(static function () use ($user): void {
        db_query(
            'UPDATE users SET visiting_school_id = NULL
              WHERE id = :id AND school_id IS NULL',
            ['id' => (int) $user['id']],
            true
        );
    });

    $_SESSION['school_id'] = null;

    tenant_set(null);
    auth_user(true);
    perm_all(true);
    perm_roles(true);

    return ['ok' => true, 'message' => 'Vous êtes revenu à la console de la plateforme.'];
}
