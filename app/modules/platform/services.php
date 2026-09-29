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

// =====================================================================
//  PHASE 11A — LE CYCLE DE VIE D'UN ÉTABLISSEMENT
// =====================================================================
//
//  POURQUOI CETTE PHASE EXISTE
//  ---------------------------
//  Mesuré : `platform.school.create`, `.edit` et `.suspend` étaient
//  DORMANTES. Une école ne naissait que par `install.php` ou à la main
//  en SQL — on ne vend pas un SaaS où signer un client exige un DBA.
//
//  Pire : RIEN dans le produit ne savait écrire `schools.status`. La
//  console ne faisait que FILTRER dessus. Or `auth.php` refuse la
//  connexion ET la session de toute école qui n'est pas `active`
//  (lignes 84 et 209), et l'effacement de la phase 10C exige le statut
//  `cancelled`.
//
//    > Un état que le produit fait respecter et qu'aucun écran ne sait
//    > poser n'est pas une protection : c'est une impasse.
//
//  C'est le motif exact de la phase 9D, sur l'année scolaire. Il s'est
//  reproduit un cran plus haut, sur l'établissement lui-même — et il
//  rendait l'effacement livré la veille INATTEIGNABLE par le produit.

/** Les cycles qu'une école peut déclarer dispenser. */
const PLATFORM_CYCLES = ['MATERNELLE', 'PRIMAIRE', 'CTEB', 'HUMANITES'];

/**
 * Combien de fois rejouer une création dont le code a été pris.
 *
 * Trois suffisent : la fenêtre de collision est celle d'une transaction,
 * et deux rejeux espacés de quelques dizaines de millisecondes règlent
 * tout ce qu'un éditeur humain peut produire.
 */
const PLATFORM_CREATION_TENTATIVES = 3;

/** Les statuts qu'un établissement peut porter. */
const PLATFORM_SCHOOL_STATUSES = [
    'active'    => 'En service',
    'suspended' => 'Suspendu',
    'cancelled' => 'Résilié',
];

/**
 * Crée un établissement, et tout ce qu'il lui faut pour être UTILISABLE.
 *
 * CE N'EST PAS UN « INSERT INTO schools »
 * ---------------------------------------
 * Une ligne dans `schools` donne une école muette : pas de cycle, donc
 * pas de niveau ; pas d'année courante, donc aucun écran de travail ;
 * pas de compte, donc personne pour ouvrir la porte. `install.php`
 * l'établit depuis la phase 1 — six écritures, pas une. Ce service fait
 * les mêmes, dans le même ordre, dans UNE transaction.
 *
 *   > Créer un client, ce n'est pas créer sa fiche : c'est lui rendre le
 *   > produit ouvrable.
 *
 * LE MOT DE PASSE N'EST RENDU QU'UNE FOIS, ET N'EST JAMAIS JOURNALISÉ.
 * Il est tiré au hasard, le changement est imposé à la première
 * connexion, et il ne passe par aucun `audit_log` — pas même rédigé :
 * ce qui n'est pas écrit ne fuit pas.
 *
 * @param array{name: string, school_type: string, province?: string, city?: string,
 *              commune?: string, address?: string, phone?: string, email?: string,
 *              director_name?: string, cycles: array<int, string>,
 *              admin_last_name: string, admin_first_name: string,
 *              admin_username: string, admin_email?: string} $input
 * @return array{ok: bool, message: string, school_id: ?int, code: ?string,
 *               username: ?string, password: ?string}
 */
function platform_service_create_school(array $input): array
{
    return platform_scope('platform.school.create', static function () use ($input): array {
        $echec = static fn (string $m): array => [
            'ok' => false, 'message' => $m, 'school_id' => null,
            'code' => null, 'username' => null, 'password' => null,
        ];

        $nom = trim((string) ($input['name'] ?? ''));

        if (mb_strlen($nom) < 3) {
            return $echec('Le nom de l\'établissement est requis (3 caractères au moins).');
        }

        if (!in_array((string) ($input['school_type'] ?? ''), ['public', 'conventionne', 'prive', 'autre'], true)) {
            return $echec('Le type d\'établissement est requis.');
        }

        // AU MOINS UN CYCLE, SINON L'ÉCOLE N'A AUCUN NIVEAU.
        // Le référentiel national s'attache aux cycles ; sans cycle,
        // l'écran des classes n'a rien à proposer et l'école découvre le
        // produit par une page vide.
        $cycles = array_values(array_intersect(
            array_map('strval', (array) ($input['cycles'] ?? [])),
            PLATFORM_CYCLES
        ));

        if ($cycles === []) {
            return $echec('Indiquez au moins un cycle d\'enseignement.');
        }

        $identifiant = trim((string) ($input['admin_username'] ?? ''));
        $nomAdmin    = trim((string) ($input['admin_last_name'] ?? ''));
        $prenomAdmin = trim((string) ($input['admin_first_name'] ?? ''));

        if ($identifiant === '' || $nomAdmin === '' || $prenomAdmin === '') {
            return $echec('L\'identité du premier administrateur est requise.');
        }

        if (preg_match('/^[a-z0-9._-]{4,50}$/', $identifiant) !== 1) {
            return $echec('L\'identifiant doit faire 4 à 50 caractères : minuscules, chiffres, point, tiret.');
        }

        $courrielAdmin = trim((string) ($input['admin_email'] ?? ''));

        if ($courrielAdmin !== '' && !filter_var($courrielAdmin, FILTER_VALIDATE_EMAIL)) {
            return $echec('L\'adresse e-mail de l\'administrateur est invalide.');
        }

        // Les identifiants sont uniques SUR TOUTE LA PLATEFORME
        // (uq_users_username, uq_users_email) : on le dit avant
        // d'échouer sur une contrainte, pour que l'éditeur comprenne.
        $pris = db_value(
            'SELECT 1 FROM users WHERE username = :u LIMIT 1',
            ['u' => $identifiant],
            true
        );

        if ($pris !== null) {
            return $echec('Cet identifiant est déjà pris sur la plateforme.');
        }

        if ($courrielAdmin !== '') {
            $prise = db_value(
                'SELECT 1 FROM users WHERE email = :e LIMIT 1',
                ['e' => $courrielAdmin],
                true
            );

            if ($prise !== null) {
                return $echec('Cette adresse e-mail est déjà utilisée sur la plateforme.');
            }
        }

        $motDePasse = platform_generer_mot_de_passe();

        // DEUX CRÉATIONS SIMULTANÉES SE DISPUTENT LE MÊME CODE.
        //
        // `platform_prochain_code_ecole()` calcule MAX+1. Deux
        // transactions ouvertes en même temps lisent le même maximum et
        // visent donc le même `ECO-xxxxxx` ; `uq_schools_code` en refuse
        // une. La donnée est sauve — c'est le rôle de la contrainte —
        // mais mesuré sur seize créations simultanées :
        //
        //     8 réussies, 8 REFUSÉES
        //     « SQLSTATE[23000] … Duplicate entry 'ECO-000002' … »
        //
        // L'éditeur n'a rien fait de mal : deux personnes qui inscrivent
        // un client en même temps, ou un simple double-clic, suffisent.
        // Et le message qu'il reçoit lui parle d'une clé d'index.
        //
        //   > Une collision que la base sait résoudre ne doit pas
        //   > remonter jusqu'à l'utilisateur, et surtout pas dans sa
        //   > langue à elle.
        //
        // On rejoue, comme `db_transaction()` rejoue un interblocage
        // depuis la phase 5B — même motif, même raison.
        $resultat = null;
        $derniere = null;

        for ($tentative = 1; $tentative <= PLATFORM_CREATION_TENTATIVES; $tentative++) {
            try {
                $resultat = db_transaction(static function () use (
                $input, $nom, $cycles, $identifiant, $nomAdmin, $prenomAdmin,
                $courrielAdmin, $motDePasse
            ): array {
                $code = platform_prochain_code_ecole();
                $slug = platform_slug_libre($nom);

                $ecoleId = db_insert('schools', [
                    'uuid'          => str_uuid(),
                    'code'          => $code,
                    'slug'          => $slug,
                    'name'          => $nom,
                    'short_name'    => platform_texte($input['short_name'] ?? null, 60),
                    'school_type'   => (string) $input['school_type'],
                    'province'      => platform_texte($input['province'] ?? null, 100),
                    'city'          => platform_texte($input['city'] ?? null, 100),
                    'commune'       => platform_texte($input['commune'] ?? null, 100),
                    'address'       => platform_texte($input['address'] ?? null, 255),
                    'phone'         => platform_texte($input['phone'] ?? null, 40),
                    'email'         => platform_texte($input['email'] ?? null, 190),
                    'director_name' => platform_texte($input['director_name'] ?? null, 150),
                    'status'        => 'active',
                ], true);

                // Les cycles dispensés.
                foreach ($cycles as $cycle) {
                    db_query(
                        'INSERT INTO school_cycles (school_id, cycle_id, is_active)
                         SELECT :ecole, id, 1 FROM education_cycles WHERE code = :code',
                        ['ecole' => $ecoleId, 'code' => $cycle],
                        true
                    );
                }

                // L'ANNÉE COURANTE, sur le calendrier congolais : rentrée
                // en septembre, fin en juillet. Sans elle, douze écrans du
                // produit n'ont rien à montrer.
                $debut = (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;

                db_query(
                    'INSERT INTO academic_years (school_id, code, name, starts_on, ends_on, status, is_current)
                     VALUES (:ecole, :code, :nom, :debut, :fin, \'active\', 1)',
                    [
                        'ecole' => $ecoleId,
                        'code'  => $debut . '-' . ($debut + 1),
                        'nom'   => 'Année scolaire ' . $debut . '-' . ($debut + 1),
                        'debut' => $debut . '-09-01',
                        'fin'   => ($debut + 1) . '-07-31',
                    ],
                    true
                );

                // Un essai de 60 jours sur l'offre d'entrée : l'école
                // travaille avant de payer, et l'invariant « une école, un
                // abonnement en cours » est respecté dès la naissance.
                db_query(
                    'INSERT INTO subscriptions (school_id, plan_id, status, billing_cycle, starts_on, ends_on)
                     SELECT :ecole, id, \'trial\', \'yearly\', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                       FROM plans WHERE code = \'DECOUVERTE\'',
                    ['ecole' => $ecoleId],
                    true
                );

                // Le premier administrateur.
                $adminId = db_insert('users', [
                    'uuid'                 => str_uuid(),
                    'school_id'            => $ecoleId,
                    'username'             => $identifiant,
                    'email'                => $courrielAdmin !== '' ? $courrielAdmin : null,
                    'password_hash'        => auth_hash_password($motDePasse),
                    'last_name'            => $nomAdmin,
                    'first_name'           => $prenomAdmin,
                    'status'               => 'active',
                    'must_change_password' => 1,
                    'password_changed_at'  => date('Y-m-d H:i:s'),
                ], true);

                db_query(
                    'INSERT INTO user_roles (user_id, role_id)
                     SELECT :u, id FROM roles WHERE code = \'SCHOOL_ADMIN\' AND school_id IS NULL',
                    ['u' => $adminId],
                    true
                );

                // LE JOURNAL NE VOIT PAS LE MOT DE PASSE.
                // Pas même rédigé : ce qui n'est pas écrit ne fuit pas.
                audit_log(
                    'platform.school.create',
                    'schools',
                    $ecoleId,
                    null,
                    ['code' => $code, 'name' => $nom, 'cycles' => $cycles,
                     'admin_username' => $identifiant],
                    'Création de l\'établissement ' . $code . ' — ' . $nom
                );

                return ['id' => $ecoleId, 'code' => $code];
                });

                break;
            } catch (Throwable $e) {
                $derniere = $e;

                if ($tentative < PLATFORM_CREATION_TENTATIVES && platform_collision_de_code($e)) {
                    log_warning('Code d\'établissement déjà pris : création rejouée', [
                        'tentative' => $tentative,
                    ]);

                    // 10 à 60 ms, pour que les deux ne repartent pas
                    // exactement ensemble — la mesure de la phase 5B.
                    usleep(random_int(10000, 60000));

                    continue;
                }

                break;
            }
        }

        if ($resultat === null) {
            // LE MESSAGE DE MySQL NE REMONTE PAS JUSQU'À L'ÉCRAN.
            // Il nomme des index et des contraintes : il n'apprend rien
            // à l'éditeur et renseigne un visiteur mal intentionné.
            log_error('Création d\'établissement impossible', [
                'error' => $derniere !== null ? $derniere->getMessage() : 'inconnue',
            ]);

            return $echec(
                $derniere !== null && platform_collision_de_code($derniere)
                    ? 'Plusieurs créations simultanées se sont disputé le même code. '
                      . 'Réessayez : la saisie est conservée.'
                    : 'Création impossible. Le détail est au journal du serveur.'
            );
        }

        return [
            'ok'        => true,
            'message'   => 'Établissement ' . $resultat['code'] . ' créé.',
            'school_id' => $resultat['id'],
            'code'      => $resultat['code'],
            'username'  => $identifiant,
            'password'  => $motDePasse,
        ];
    });
}

/**
 * Modifie les coordonnées d'un établissement.
 *
 * CE QUI N'EST PAS MODIFIABLE ICI, ET POURQUOI
 * --------------------------------------------
 *  · le CODE est l'identité de l'école : il figure sur des documents
 *    déjà remis et sur la trace d'un éventuel effacement ;
 *  · le SLUG a servi à nommer des dossiers de fichiers ;
 *  · le STATUT relève d'une autre permission — suspendre un client et
 *    corriger son adresse ne sont pas le même pouvoir.
 *
 * @param array<string, mixed> $input
 * @return array{ok: bool, message: string}
 */
function platform_service_update_school(int $schoolId, array $input): array
{
    return platform_scope('platform.school.edit', static function () use ($schoolId, $input): array {
        $ecole = db_one('SELECT * FROM schools WHERE id = :id', ['id' => $schoolId], true);

        if ($ecole === null) {
            return ['ok' => false, 'message' => 'Établissement introuvable.'];
        }

        $nom = trim((string) ($input['name'] ?? ''));

        if (mb_strlen($nom) < 3) {
            return ['ok' => false, 'message' => 'Le nom de l\'établissement est requis.'];
        }

        if (!in_array((string) ($input['school_type'] ?? ''), ['public', 'conventionne', 'prive', 'autre'], true)) {
            return ['ok' => false, 'message' => 'Le type d\'établissement est requis.'];
        }

        $champs = [
            'name'            => $nom,
            'short_name'      => platform_texte($input['short_name'] ?? null, 60),
            'school_type'     => (string) $input['school_type'],
            'sernie_number'   => platform_texte($input['sernie_number'] ?? null, 50),
            'approval_number' => platform_texte($input['approval_number'] ?? null, 50),
            'province'        => platform_texte($input['province'] ?? null, 100),
            'city'            => platform_texte($input['city'] ?? null, 100),
            'commune'         => platform_texte($input['commune'] ?? null, 100),
            'address'         => platform_texte($input['address'] ?? null, 255),
            'phone'           => platform_texte($input['phone'] ?? null, 40),
            'phone_alt'       => platform_texte($input['phone_alt'] ?? null, 40),
            'email'           => platform_texte($input['email'] ?? null, 190),
            'website'         => platform_texte($input['website'] ?? null, 190),
            'director_name'   => platform_texte($input['director_name'] ?? null, 150),
        ];

        $avant = [];

        foreach (array_keys($champs) as $cle) {
            $avant[$cle] = $ecole[$cle] ?? null;
        }

        db_update('schools', $champs, 'id = :id', ['id' => $schoolId], true);

        audit_log('platform.school.update', 'schools', $schoolId, $avant, $champs,
            'Modification de l\'établissement ' . (string) $ecole['code']);

        return ['ok' => true, 'message' => 'Établissement mis à jour.'];
    });
}

/**
 * Pose le statut d'un établissement.
 *
 * C'EST CE SERVICE QUI REND LA 10C ATTEIGNABLE
 * ---------------------------------------------
 * `auth.php` refuse la connexion ET la session de toute école qui n'est
 * pas `active` — lignes 84 et 209, depuis la phase 1. Et l'effacement de
 * la phase 10C exige `cancelled`. Ces deux règles étaient tenues par un
 * état que RIEN dans le produit ne savait écrire.
 *
 *   > Un état que le produit fait respecter et qu'aucun écran ne sait
 *   > poser n'est pas une protection : c'est une impasse.
 *
 * SUSPENDRE MET TOUT LE MONDE DEHORS, SÉANCE TENANTE.
 * Ce n'est pas un drapeau décoratif : à la requête suivante, chaque
 * session de l'école est détruite. L'écran doit le dire avant, pas
 * l'école le découvrir après.
 *
 * UN MOTIF EST EXIGÉ pour suspendre comme pour résilier. L'école
 * demandera pourquoi ; sans motif, la trace dirait QUE c'est arrivé,
 * jamais POURQUOI. C'est la règle posée en 9D pour la réouverture d'une
 * année, et elle vaut ici davantage.
 *
 * @return array{ok: bool, message: string}
 */
function platform_service_set_school_status(int $schoolId, string $status, string $reason = ''): array
{
    return platform_scope('platform.school.suspend', static function () use ($schoolId, $status, $reason): array {
        if (!array_key_exists($status, PLATFORM_SCHOOL_STATUSES)) {
            return ['ok' => false, 'message' => 'Statut invalide.'];
        }

        $ecole = db_one(
            'SELECT id, code, name, status FROM schools WHERE id = :id',
            ['id' => $schoolId],
            true
        );

        if ($ecole === null) {
            return ['ok' => false, 'message' => 'Établissement introuvable.'];
        }

        $avant = (string) $ecole['status'];

        if ($avant === $status) {
            return ['ok' => false, 'message' => 'Cet établissement est déjà « '
                . PLATFORM_SCHOOL_STATUSES[$status] . ' ».'];
        }

        $motif = trim($reason);

        if ($status !== 'active' && mb_strlen($motif) < 10) {
            return ['ok' => false, 'message' => 'Un motif d\'au moins 10 caractères est exigé : '
                . 'l\'établissement demandera pourquoi, et la trace doit pouvoir le dire.'];
        }

        db_update('schools', ['status' => $status], 'id = :id', ['id' => $schoolId], true);

        audit_log(
            'platform.school.status',
            'schools',
            $schoolId,
            ['status' => $avant],
            ['status' => $status, 'reason' => $motif],
            // `.` lie plus fort que `??` : écrire « A . B ?? C » aurait
            // donné « (A . B) ?? C », c'est-à-dire jamais C. Et la trace
            // doit dire la TRANSITION, pas seulement l'état de départ.
            sprintf(
                'Établissement %s : %s → %s',
                (string) $ecole['code'],
                PLATFORM_SCHOOL_STATUSES[$avant] ?? $avant,
                PLATFORM_SCHOOL_STATUSES[$status]
            )
        );

        $suite = match ($status) {
            'suspended' => ' Les sessions en cours de cet établissement sont closes.',
            'cancelled' => ' L\'établissement peut désormais être effacé définitivement '
                . '(php database/erase_school.php ' . (string) $ecole['code'] . ').',
            default     => ' Ses utilisateurs peuvent de nouveau se connecter.',
        };

        return ['ok' => true, 'message' => 'Établissement « ' . (string) $ecole['name']
            . ' » : ' . PLATFORM_SCHOOL_STATUSES[$status] . '.' . $suite];
    });
}

// ---------------------------------------------------------------------
//  Appui
// ---------------------------------------------------------------------

/**
 * Le prochain code d'établissement libre.
 *
 * `uq_schools_code` est UNIQUE : c'est la base qui tranche en dernier
 * ressort. Ce calcul est fait DANS la transaction de création, et deux
 * créations simultanées verraient la seconde échouer sur la contrainte
 * plutôt que produire un doublon — le bon ordre des garde-fous.
 */
function platform_prochain_code_ecole(): string
{
    $dernier = (string) db_value(
        "SELECT code FROM schools WHERE code REGEXP '^ECO-[0-9]{6}$'
          ORDER BY code DESC LIMIT 1",
        [],
        true
    );

    $suivant = $dernier === '' ? 1 : ((int) substr($dernier, 4)) + 1;

    return sprintf('ECO-%06d', $suivant);
}

/**
 * Un slug libre, dérivé du nom.
 *
 * `uq_schools_slug` est UNIQUE, et le slug a servi à nommer des chemins :
 * deux écoles homonymes — il y en a — ne doivent pas se le disputer.
 */
function platform_slug_libre(string $nom): string
{
    $base = str_slug($nom);
    $base = $base !== '' ? mb_substr($base, 0, 90) : 'etablissement';
    $essai = $base;

    for ($n = 2; $n < 200; $n++) {
        $pris = db_value('SELECT 1 FROM schools WHERE slug = :s LIMIT 1', ['s' => $essai], true);

        if ($pris === null) {
            return $essai;
        }

        $essai = $base . '-' . $n;
    }

    return $base . '-' . bin2hex(random_bytes(3));
}

/**
 * Cette exception est-elle une collision sur le code ou le slug ?
 *
 * On ne rejoue QUE cela. Une violation de contrainte sur l'identifiant
 * de l'administrateur, par exemple, se reproduirait à l'identique au
 * rejeu : la rejouer ferait perdre du temps et masquerait la vraie
 * cause.
 */
function platform_collision_de_code(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }

    if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
        return false;
    }

    $message = $e->getMessage();

    return str_contains($message, 'uq_schools_code')
        || str_contains($message, 'uq_schools_slug');
}

/** Un texte nettoyé, tronqué, ou null s'il est vide. */
function platform_texte(mixed $valeur, int $max): ?string
{
    $texte = trim((string) ($valeur ?? ''));

    return $texte === '' ? null : mb_substr($texte, 0, $max);
}

/**
 * Un mot de passe initial, lisible et solide.
 *
 * Il sera dicté au téléphone ou recopié d'un écran : on évite les
 * caractères qui se confondent (O/0, l/1/I). Le changement est imposé à
 * la première connexion, donc sa durée de vie se compte en minutes.
 */
function platform_generer_mot_de_passe(): string
{
    $lettres = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $minus   = 'abcdefghijkmnpqrstuvwxyz';
    $chiffres = '23456789';

    $mot = $lettres[random_int(0, strlen($lettres) - 1)];

    for ($i = 0; $i < 5; $i++) {
        $mot .= $minus[random_int(0, strlen($minus) - 1)];
    }

    for ($i = 0; $i < 4; $i++) {
        $mot .= $chiffres[random_int(0, strlen($chiffres) - 1)];
    }

    return $mot . $lettres[random_int(0, strlen($lettres) - 1)];
}
