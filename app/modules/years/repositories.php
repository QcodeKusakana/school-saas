<?php
/**
 * Module ANNÉE SCOLAIRE — lectures.
 */

declare(strict_types=1);

/**
 * Toutes les années de l'école, la plus récente d'abord.
 *
 * @return array<int, array<string, mixed>>
 */
function years_repo_all(): array
{
    return tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC');
}

/** Une année de l'école, ou null. */
function years_repo_find(int $id): ?array
{
    return tenant_one('academic_years', 'id = :id', ['id' => $id]);
}

/** L'année courante, ou null si l'école n'en a désigné aucune. */
function years_repo_current(): ?array
{
    return tenant_one('academic_years', 'is_current = 1');
}

/**
 * CE QUE CONTIENT UNE ANNÉE — le compte rendu de clôture.
 *
 * POURQUOI CET ÉTAT EXISTE
 * =========================
 * Clôturer, c'est interdire toute écriture sur une année. Le faire sans
 * dire ce qu'elle contient d'inachevé reviendrait à demander une
 * signature les yeux fermés : des bulletins non calculés, des cotes
 * manquantes ou des frais impayés deviendraient irrattrapables sans
 * réouverture.
 *
 *   > Une décision irréversible qu'on prend sans voir ce qu'elle fige
 *   > n'est pas une décision, c'est un pari.
 *
 * L'écran MONTRE ces chiffres ; il ne refuse pas la clôture pour autant.
 * Une école peut avoir de bonnes raisons de clôturer avec des impayés —
 * ce n'est pas au logiciel d'en juger. Il doit seulement s'assurer que
 * personne ne signe sans savoir.
 *
 * @return array<string, int>
 */
function years_repo_contents(int $yearId): array
{
    $params = ['school_id' => tenant_require(), 'year' => $yearId];

    $eleves = db_one(
        'SELECT COUNT(*) AS inscrits,
                SUM(CASE WHEN e.classroom_id IS NULL THEN 1 ELSE 0 END) AS sans_classe
           FROM enrollments e
          WHERE e.school_id = :school_id AND e.academic_year_id = :year
            AND e.status <> \'cancelled\'',
        $params,
        true
    );

    $classes = (int) db_value(
        'SELECT COUNT(*) FROM classrooms
          WHERE school_id = :school_id AND academic_year_id = :year',
        $params,
        true
    );

    $bulletins = (int) db_value(
        'SELECT COUNT(*) FROM bulletins b
           JOIN enrollments e ON e.id = b.enrollment_id AND e.school_id = b.school_id
          WHERE b.school_id = :school_id AND e.academic_year_id = :year',
        $params,
        true
    );

    $sansDecision = (int) db_value(
        'SELECT COUNT(*) FROM bulletins b
           JOIN enrollments e ON e.id = b.enrollment_id AND e.school_id = b.school_id
          WHERE b.school_id = :school_id AND e.academic_year_id = :year
            AND b.decision IS NULL',
        $params,
        true
    );

    $cotesManquantes = (int) db_value(
        'SELECT COALESCE(SUM(b.missing_grades), 0) FROM bulletins b
           JOIN enrollments e ON e.id = b.enrollment_id AND e.school_id = b.school_id
          WHERE b.school_id = :school_id AND e.academic_year_id = :year',
        $params,
        true
    );

    // LES IMPAYÉS SE LISENT SUR LA MÊME ASSIETTE QUE L'ÉCRAN DÉDIÉ.
    //
    // `student_fees` ne porte PAS de colonne « montant payé » : ce qui a
    // été réglé vit dans `payment_allocations`, et le net dû vaut
    // `amount_due - discount_amount`. J'avais d'abord écrit
    // `sf.amount_paid > …` et `sf.status` — deux colonnes qui n'existent
    // pas. Reprendre la formule de `finance_repo_outstanding()` est la
    // seule façon que les deux écrans ne racontent pas deux histoires.
    //
    // `school_alloc` double `school_id` à dessein : avec
    // ATTR_EMULATE_PREPARES = false, un paramètre nommé ne peut pas
    // apparaître deux fois dans la même requête.
    $impayes = (int) db_value(
        'SELECT COUNT(DISTINCT sf.enrollment_id)
           FROM student_fees sf
           JOIN enrollments e ON e.id = sf.enrollment_id AND e.school_id = sf.school_id
           LEFT JOIN (
                 SELECT pa.student_fee_id, SUM(pa.amount) AS paid
                   FROM payment_allocations pa
                   JOIN payments pm ON pm.id = pa.payment_id AND pm.school_id = pa.school_id
                  WHERE pa.school_id = :school_alloc AND pm.is_cancelled = 0
                  GROUP BY pa.student_fee_id
           ) a ON a.student_fee_id = sf.id
          WHERE sf.school_id = :school_id AND e.academic_year_id = :year
            AND sf.is_cancelled = 0
            AND (sf.amount_due - sf.discount_amount - COALESCE(a.paid, 0)) > 0.005',
        $params + ['school_alloc' => tenant_require()],
        true
    );

    return [
        'inscrits'         => (int) ($eleves['inscrits'] ?? 0),
        'sans_classe'      => (int) ($eleves['sans_classe'] ?? 0),
        'classes'          => $classes,
        'bulletins'        => $bulletins,
        'sans_decision'    => $sansDecision,
        'cotes_manquantes' => $cotesManquantes,
        'impayes'          => $impayes,
    ];
}

/**
 * Ce qui empêche une année d'être supprimée, table par table.
 *
 * On ne se contente pas de savoir QUE l'année n'est pas vide : l'écran
 * doit pouvoir dire ce qu'elle porte. « Impossible de supprimer » sans
 * raison oblige l'utilisateur à deviner.
 *
 * @return array<string, int> table => nombre de lignes, tables vides omises
 */
function years_repo_blocking(int $yearId): array
{
    $params  = ['school_id' => tenant_require(), 'year' => $yearId];
    $occupee = [];

    foreach (array_keys(YEAR_BLOCKING_TABLES) as $table) {
        // Le nom de table vient d'une CONSTANTE du produit, jamais d'une
        // saisie : c'est ce qui autorise l'interpolation ici.
        $nombre = (int) db_value(
            'SELECT COUNT(*) FROM ' . $table
            . ' WHERE school_id = :school_id AND academic_year_id = :year',
            $params,
            true
        );

        if ($nombre > 0) {
            $occupee[$table] = $nombre;
        }
    }

    return $occupee;
}
