<?php
/**
 * Module ABONNEMENTS — dépôt (phase 7A).
 *
 * Ce que ce module mesure, et ce qu'il ne mesure pas
 * =================================================
 * Il répond à trois questions, et à elles seules :
 *   · quelle offre cette école a-t-elle ?
 *   · où en est-elle de ses limites ?
 *   · peut-elle inscrire un élève de plus ?
 *
 * Il ne facture pas et n'encaisse pas : c'est la phase 7B.
 */

declare(strict_types=1);

/**
 * L'abonnement en cours d'une école, avec son offre.
 *
 * TOUTE ÉCOLE EN A UN.
 * La migration 026 en a créé un pour chaque école existante, et
 * `subscription_ensure()` en crée un pour toute école nouvelle. Un
 * abonnement absent n'est pas « pas de limite » : c'est une situation
 * que le code devrait interpréter, et toute interprétation finit par
 * diverger entre deux écrans.
 *
 * Les statuts retenus sont ceux qui donnent un service :
 * `trial`, `active`, `past_due`. Un abonnement `suspended` ou
 * `cancelled` n'en donne plus — mais il est lu quand même, par
 * `subscription_any()`, pour que l'écran puisse le DIRE.
 */
function subscription_current(): ?array
{
    return db_one(
        'SELECT s.*, p.code AS plan_code, p.name AS plan_name,
                p.price_monthly, p.price_yearly, p.currency,
                p.max_students, p.max_users, p.max_storage_mb, p.features
           FROM subscriptions s
           JOIN plans p ON p.id = s.plan_id
          WHERE s.school_id = :school_id
            AND s.status IN (\'trial\', \'active\', \'past_due\')
          ORDER BY s.ends_on DESC
          LIMIT 1',
        ['school_id' => tenant_require()]
    );
}

/** Le dernier abonnement, quel que soit son statut — pour l'afficher. */
function subscription_any(): ?array
{
    return db_one(
        'SELECT s.*, p.code AS plan_code, p.name AS plan_name,
                p.price_monthly, p.price_yearly, p.currency,
                p.max_students, p.max_users, p.max_storage_mb, p.features
           FROM subscriptions s
           JOIN plans p ON p.id = s.plan_id
          WHERE s.school_id = :school_id
          ORDER BY s.ends_on DESC, s.id DESC
          LIMIT 1',
        ['school_id' => tenant_require()]
    );
}

/**
 * Nombre d'élèves qui COMPTENT pour la limite.
 *
 * LES INSCRITS DE L'ANNÉE EN COURS, PAS LES DOSSIERS.
 * -------------------------------------------------
 * Une école de dix ans porte des milliers de dossiers d'anciens élèves.
 * Les compter reviendrait à faire payer l'archivage — exactement ce que
 * le produit promet de rendre facile. On compte donc les inscriptions
 * NON ANNULÉES de l'année courante.
 *
 * Un élève réinscrit d'une année sur l'autre compte une fois : la
 * jointure porte sur l'élève distinct.
 */
function subscription_student_count(?int $yearId = null): int
{
    // L'ANNÉE MESURÉE EST CELLE OÙ L'ON INSCRIT.
    //
    // Compter toujours l'année courante laisserait passer une rentrée
    // entière préparée en avance : à la veille du 1er septembre, la
    // nouvelle année n'est pas encore `is_current`, et l'école pourrait
    // y inscrire sans limite.
    $params = ['school_id' => tenant_require()];
    $filter = 'y.is_current = 1';

    if ($yearId !== null) {
        $filter            = 'e.academic_year_id = :year_id';
        $params['year_id'] = $yearId;
    }

    return (int) db_value(
        'SELECT COUNT(DISTINCT e.student_id)
           FROM enrollments e
           JOIN students st ON st.id = e.student_id AND st.school_id = e.school_id
           JOIN academic_years y ON y.id = e.academic_year_id AND y.school_id = e.school_id
          WHERE e.school_id = :school_id
            AND ' . $filter . '
            AND e.status <> \'cancelled\'
            AND st.deleted_at IS NULL',
        $params
    );
}

/**
 * Nombre de comptes du PERSONNEL.
 *
 * LES COMPTES DE FAMILLES NE COMPTENT PAS.
 * ----------------------------------------
 * L'offre ESSENTIEL autorise 600 élèves et 50 utilisateurs. Avec les
 * espaces familles de la phase 6, 600 élèves produisent jusqu'à 600
 * comptes élèves et autant de comptes tuteurs : la limite serait
 * dépassée dès la première classe.
 *
 * Un plan se vend au nombre d'élèves ; ouvrir le portail aux familles ne
 * doit pas coûter une montée de gamme. `max_users` compte donc les
 * comptes qui ne sont rattachés NI à une fiche tuteur NI à une fiche
 * élève.
 *
 * La distinction se lit dans `guardians.user_id` et `students.user_id` —
 * déjà la source de vérité du portail. Un drapeau `is_family` sur
 * `users` créerait une seconde vérité, qui finirait par contredire la
 * première.
 */
function subscription_staff_user_count(): int
{
    return (int) db_value(
        'SELECT COUNT(*)
           FROM users u
          WHERE u.school_id = :school_id
            AND u.deleted_at IS NULL
            AND u.status <> \'inactive\'
            AND NOT EXISTS (
                SELECT 1 FROM guardians g
                 WHERE g.user_id = u.id AND g.school_id = u.school_id AND g.deleted_at IS NULL
            )
            AND NOT EXISTS (
                SELECT 1 FROM students s
                 WHERE s.user_id = u.id AND s.school_id = u.school_id AND s.deleted_at IS NULL
            )',
        ['school_id' => tenant_require()],
        true // `users` n'est pas une table TENANT_TABLES : le filtre est explicite ci-dessus.
    );
}

/** Nombre de comptes de familles — affiché, jamais limité. */
function subscription_family_user_count(): int
{
    return (int) db_value(
        'SELECT COUNT(*) FROM users u
          WHERE u.school_id = :school_id AND u.deleted_at IS NULL
            AND (
                EXISTS (SELECT 1 FROM guardians g
                         WHERE g.user_id = u.id AND g.school_id = u.school_id AND g.deleted_at IS NULL)
                OR EXISTS (SELECT 1 FROM students s
                            WHERE s.user_id = u.id AND s.school_id = u.school_id AND s.deleted_at IS NULL)
            )',
        ['school_id' => tenant_require()],
        true
    );
}

/**
 * Les années dont le décompte peut REFUSER une inscription aujourd'hui.
 *
 * POURQUOI PLUSIEURS ANNÉES.
 * -------------------------
 * La limite se mesure sur l'année VISÉE (voir
 * `subscription_student_count()`). Une école qui prépare sa rentrée en
 * août porte donc deux décomptes vivants : l'année en cours, et celle
 * qu'elle remplit. N'afficher que la première fait dire à l'écran
 * « il reste 2 places » alors que l'inscription refuse — l'audit 7A l'a
 * démontré par exécution.
 *
 * On retient donc l'année courante ET toute année non clôturée qui
 * commence dans le futur. Les années passées sont exclues : y inscrire
 * quelqu'un est une régularisation rare, et les faire figurer
 * donnerait une page de jauges à une école de dix ans.
 *
 * Si aucune année n'est courante, on prend la dernière année non
 * clôturée : mieux vaut un décompte daté qu'un écran vide.
 *
 * @return array<int, array{id: int, name: string, is_current: bool}>
 */
function subscription_countable_years(): array
{
    $rows = db_all(
        'SELECT id, name, starts_on, is_current
           FROM academic_years
          WHERE school_id = :school_id
            AND status IN (\'draft\', \'active\')
            AND (is_current = 1 OR starts_on > CURDATE())
          ORDER BY starts_on',
        ['school_id' => tenant_require()]
    );

    if ($rows === []) {
        $rows = db_all(
            'SELECT id, name, starts_on, is_current
               FROM academic_years
              WHERE school_id = :school_id
                AND status IN (\'draft\', \'active\')
              ORDER BY starts_on DESC
              LIMIT 1',
            ['school_id' => tenant_require()]
        );
    }

    return array_map(
        static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'is_current' => (int) ($r['is_current'] ?? 0) === 1,
        ],
        $rows
    );
}

/** Toutes les offres proposables, dans l'ordre d'affichage. */
function subscription_plans(): array
{
    return db_all(
        'SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_yearly',
        [],
        true // table globale
    );
}
