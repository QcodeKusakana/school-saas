<?php
/**
 * Module DASHBOARD — contrôleurs.
 *
 * En phase 1, le tableau de bord affiche ce qui existe réellement :
 * l'établissement, l'année scolaire active, les comptes et l'abonnement.
 * Les compteurs d'élèves, de présences et de recettes seront ajoutés
 * au fur et à mesure des phases — aucun chiffre fictif n'est affiché.
 */

declare(strict_types=1);

function ctrl_dashboard_index(): void
{
    $user = auth_user();

    // Un super administrateur plateforme n'a pas de tableau de bord école.
    if ($user['school_id'] === null) {
        view('dashboard/platform', [
            'schools_total'  => (int) db_value('SELECT COUNT(*) FROM schools WHERE deleted_at IS NULL', [], true),
            'schools_active' => (int) db_value(
                'SELECT COUNT(*) FROM schools WHERE status = :s AND deleted_at IS NULL',
                ['s' => 'active'],
                true
            ),
            'users_total'    => (int) db_value('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL', [], true),
        ], 'app');

        return;
    }

    $school = db_one(
        'SELECT * FROM schools WHERE id = :id LIMIT 1',
        ['id' => tenant_require()],
        true // table globale : schools ne porte pas de colonne school_id
    );

    view('dashboard/index', [
        'school'       => $school,
        'currentYear'  => dashboard_current_year(),
        'usersCount'   => tenant_count('users', 'deleted_at IS NULL'),
        'activeCycles' => dashboard_active_cycles(),
        'subscription' => dashboard_subscription(),
        'setupSteps'   => dashboard_setup_steps(),
    ], 'app');
}

/** Année scolaire courante de l'établissement, ou null. */
function dashboard_current_year(): ?array
{
    return tenant_one('academic_years', 'is_current = 1');
}

/** Cycles activés par l'établissement. */
function dashboard_active_cycles(): array
{
    return db_all(
        'SELECT c.code, c.name, c.short_name
           FROM school_cycles sc
           JOIN education_cycles c ON c.id = sc.cycle_id
          WHERE sc.school_id = :school_id AND sc.is_active = 1
          ORDER BY c.order_number',
        ['school_id' => tenant_require()]
    );
}

/** Abonnement en cours, avec le nom de l'offre. */
function dashboard_subscription(): ?array
{
    return db_one(
        'SELECT s.*, p.name AS plan_name, p.max_students, p.max_users
           FROM subscriptions s
           JOIN plans p ON p.id = s.plan_id
          WHERE s.school_id = :school_id
            AND s.status IN (\'trial\', \'active\', \'past_due\')
          ORDER BY s.ends_on DESC
          LIMIT 1',
        ['school_id' => tenant_require()]
    );
}

/**
 * Étapes de configuration restantes.
 *
 * Guide l'administrateur d'une nouvelle école plutôt que de le laisser
 * devant un tableau de bord vide. Chaque entrée : libellé, état, lien.
 */
function dashboard_setup_steps(): array
{
    $schoolId = tenant_require();

    $hasLogo   = (bool) db_value(
        'SELECT logo_path FROM schools WHERE id = :id',
        ['id' => $schoolId],
        true
    );
    $hasYear   = tenant_count('academic_years') > 0;
    $hasCycles = (int) db_value(
        'SELECT COUNT(*) FROM school_cycles WHERE school_id = :school_id AND is_active = 1',
        ['school_id' => $schoolId]
    ) > 0;
    $hasUsers  = tenant_count('users', 'deleted_at IS NULL') > 1;

    return [
        [
            'label' => 'Compléter la fiche de l\'établissement',
            'done'  => $hasLogo,
            'url'   => '/ecole/parametres',
            'hint'  => 'Logo, coordonnées, numéro SERNIE — repris sur les bulletins et attestations.',
        ],
        [
            'label' => 'Activer les cycles enseignés',
            'done'  => $hasCycles,
            'url'   => '/ecole/cycles',
            'hint'  => 'Maternelle, primaire, CTEB, humanités : n\'activez que ceux que vous dispensez.',
        ],
        [
            'label' => 'Créer l\'année scolaire',
            'done'  => $hasYear,
            'url'   => '/annees-scolaires',
            'hint'  => 'Toute donnée pédagogique est rattachée à une année scolaire.',
        ],
        [
            'label' => 'Créer les comptes du personnel',
            'done'  => $hasUsers,
            'url'   => '/utilisateurs',
            'hint'  => 'Direction, secrétariat, comptable, enseignants.',
        ],
    ];
}
