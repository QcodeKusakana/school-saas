<?php
/**
 * Module PLATEFORME — dépôt (phase 7B).
 *
 * TOUTES LES LECTURES DE CE FICHIER SONT TRANSVERSALES.
 * =====================================================
 * C'est son objet : l'éditeur doit voir toutes les écoles. C'est aussi
 * ce qui en fait le fichier le plus dangereux du dépôt.
 *
 * Chaque fonction ouvre donc elle-même son périmètre par
 * `platform_scope($permission, …)`, qui vérifie l'habilitation AVANT de
 * laisser passer la requête. Aucune ne compte sur son appelant pour le
 * faire : un dépôt qui délègue sa sécurité au contrôleur la perd le jour
 * où un second contrôleur l'appelle.
 *
 * Voir app/core/platform.php et claude/securite-perimetre-plateforme.md.
 */

declare(strict_types=1);

/**
 * Les établissements, avec leur offre et leur effectif.
 *
 * LE DÉCOMPTE D'ÉLÈVES EST FAIT EN UNE REQUÊTE, PAS EN N+1.
 * --------------------------------------------------------
 * L'accueil du portail est déjà un N+1 assumé (6 enfants = 31 requêtes)
 * parce qu'il sert une famille. Cet écran sert l'éditeur et liste TOUTES
 * les écoles : le même motif y coûterait une requête par établissement.
 *
 * @param array{q?: string, status?: string} $filters
 * @return array<int, array<string, mixed>>
 */
function platform_repo_schools(array $filters = []): array
{
    return platform_scope('platform.school.view', static function () use ($filters): array {
        $where  = ['s.deleted_at IS NULL'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            // Trois paramètres distincts pour une même valeur : avec
            // ATTR_EMULATE_PREPARES = false, un paramètre nommé ne peut
            // pas être réutilisé dans une même requête.
            $where[]          = '(s.name LIKE :q1 OR s.code LIKE :q2 OR s.city LIKE :q3)';
            $params['q1']     = '%' . $q . '%';
            $params['q2']     = '%' . $q . '%';
            $params['q3']     = '%' . $q . '%';
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status !== '' && in_array($status, ['pending', 'active', 'suspended', 'cancelled'], true)) {
            $where[]            = 's.status = :status';
            $params['status']   = $status;
        }

        return db_all(
            'SELECT s.id, s.code, s.name, s.city, s.province, s.status,
                    s.school_type, s.created_at,
                    sub.id            AS subscription_id,
                    sub.status        AS subscription_status,
                    sub.ends_on       AS subscription_ends_on,
                    sub.max_students_override,
                    p.code            AS plan_code,
                    p.name            AS plan_name,
                    p.max_students    AS plan_max_students,
                    COALESCE(cnt.students, 0) AS students
               FROM schools s

               /* L\'abonnement QUI GOUVERNE, pas le plus lointain :
                  l\'audit 7A a montré qu\'un abonnement résilié peut
                  porter une échéance plus tardive que l\'offre active. */
               LEFT JOIN subscriptions sub
                      ON sub.id = (
                         SELECT s2.id FROM subscriptions s2
                          WHERE s2.school_id = s.id
                            AND s2.status IN (\'trial\', \'active\', \'past_due\')
                          ORDER BY s2.ends_on DESC, s2.id DESC
                          LIMIT 1
                      )
               LEFT JOIN plans p ON p.id = sub.plan_id

               /* Élèves inscrits sur l\'année courante de CHAQUE école. */
               LEFT JOIN (
                    SELECT e.school_id, COUNT(DISTINCT e.student_id) AS students
                      FROM enrollments e
                      JOIN academic_years y
                        ON y.id = e.academic_year_id
                       AND y.school_id = e.school_id
                       AND y.is_current = 1
                      JOIN students st
                        ON st.id = e.student_id
                       AND st.school_id = e.school_id
                       AND st.deleted_at IS NULL
                     WHERE e.status <> \'cancelled\'
                     GROUP BY e.school_id
               ) cnt ON cnt.school_id = s.id

              WHERE ' . implode(' AND ', $where) . '
              ORDER BY s.name',
            $params,
            true
        );
    });
}

/** Une école, sans son contexte — pour l'écran de détail et l'ouverture. */
function platform_repo_school(int $schoolId): ?array
{
    return platform_scope('platform.school.view', static fn (): ?array => db_one(
        'SELECT * FROM schools WHERE id = :id AND deleted_at IS NULL LIMIT 1',
        ['id' => $schoolId],
        true
    ));
}

/**
 * Tous les abonnements d'une école, du plus récent au plus ancien.
 *
 * L'HISTORIQUE COMPTE. Une école passée de Découverte à Essentiel puis
 * à Pro doit pouvoir le prouver : c'est la base de la facturation (7B2)
 * et la réponse à « depuis quand payons-nous ce tarif ? ».
 */
function platform_repo_school_subscriptions(int $schoolId): array
{
    return platform_scope('platform.subscription.manage', static fn (): array => db_all(
        'SELECT sub.*, p.code AS plan_code, p.name AS plan_name,
                p.price_monthly, p.price_yearly, p.currency,
                p.max_students, p.max_users
           FROM subscriptions sub
           JOIN plans p ON p.id = sub.plan_id
          WHERE sub.school_id = :school_id
          ORDER BY sub.starts_on DESC, sub.id DESC',
        ['school_id' => $schoolId],
        true
    ));
}

/**
 * Vue transversale des abonnements — ce que l'éditeur surveille.
 *
 * Trois questions, une requête : qui expire bientôt, qui n'a plus
 * d'abonnement actif, qui dépasse son plafond.
 */
function platform_repo_subscription_watch(int $expiringWithinDays = 30): array
{
    return platform_scope('platform.subscription.manage', static fn (): array => db_all(
        'SELECT s.id, s.code, s.name, s.status AS school_status,
                sub.id      AS subscription_id,
                sub.status  AS subscription_status,
                sub.ends_on,
                sub.billing_cycle,
                sub.max_students_override,
                p.code      AS plan_code,
                p.name      AS plan_name,
                p.max_students AS plan_max_students,
                DATEDIFF(sub.ends_on, CURDATE()) AS days_left,
                COALESCE(cnt.students, 0) AS students
           FROM schools s
           LEFT JOIN subscriptions sub
                  ON sub.id = (
                     SELECT s2.id FROM subscriptions s2
                      WHERE s2.school_id = s.id
                        AND s2.status IN (\'trial\', \'active\', \'past_due\')
                      ORDER BY s2.ends_on DESC, s2.id DESC
                      LIMIT 1
                  )
           LEFT JOIN plans p ON p.id = sub.plan_id
           LEFT JOIN (
                SELECT e.school_id, COUNT(DISTINCT e.student_id) AS students
                  FROM enrollments e
                  JOIN academic_years y
                    ON y.id = e.academic_year_id
                   AND y.school_id = e.school_id
                   AND y.is_current = 1
                  JOIN students st
                    ON st.id = e.student_id
                   AND st.school_id = e.school_id
                   AND st.deleted_at IS NULL
                 WHERE e.status <> \'cancelled\'
                 GROUP BY e.school_id
           ) cnt ON cnt.school_id = s.id
          WHERE s.deleted_at IS NULL
            AND (
                sub.id IS NULL
                OR sub.ends_on <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
                OR (
                    COALESCE(sub.max_students_override, p.max_students) IS NOT NULL
                    AND COALESCE(cnt.students, 0)
                        >= COALESCE(sub.max_students_override, p.max_students)
                )
            )
          ORDER BY sub.ends_on IS NULL DESC, sub.ends_on',
        ['days' => $expiringWithinDays],
        true
    ));
}

/**
 * Combien d'écoles portent PLUS D'UN abonnement « en cours » ?
 *
 * MySQL ne sait pas exprimer un UNIQUE conditionnel sur
 * `status IN ('trial','active','past_due')`. L'invariant n'est donc pas
 * porté par le schéma : il est porté par le SERVICE
 * (`platform_service_change_plan()`, seul créateur d'abonnements) et
 * VÉRIFIABLE ici.
 *
 * > Un invariant qu'aucune requête ne sait vérifier n'est pas un
 * > invariant, c'est une intention.
 *
 * @return array<int, array{id: int, name: string, n: int}>
 */
function platform_repo_subscription_conflicts(): array
{
    return platform_scope('platform.subscription.manage', static fn (): array => db_all(
        'SELECT s.id, s.name, COUNT(*) AS n
           FROM subscriptions sub
           JOIN schools s ON s.id = sub.school_id
          WHERE sub.status IN (\'trial\', \'active\', \'past_due\')
          GROUP BY s.id, s.name
         HAVING COUNT(*) > 1
          ORDER BY n DESC, s.name',
        [],
        true
    ));
}
