<?php
/**
 * Module RAPPORTS — agrégations.
 *
 * TROIS RÈGLES QUI GOUVERNENT TOUT CE FICHIER
 * ============================================
 *
 * 1. UNE REQUÊTE PAR RAPPORT, PAS UNE PAR CLASSE.
 *    Un rapport qui boucle sur les classes pour compter les élèves
 *    produit autant de requêtes que l'école a de classes. À trente
 *    classes et douze rapports par jour, c'est la base qui paie.
 *
 * 2. LES TOTAUX SE CALCULENT EN PHP, À PARTIR DES LIGNES DÉJÀ LUES.
 *    Redemander à MySQL une somme qu'on peut additionner soi-même est
 *    un aller-retour pour rien — et deux requêtes séparées peuvent voir
 *    deux états différents de la base.
 *
 * 3. CE QU'ON NE SAIT PAS NE SE COMPTE PAS COMME UN ÉCHEC.
 *    `bulletins.decision` vaut NULL tant que le conseil n'a pas statué.
 *    Compter ces bulletins comme des échecs donnerait un taux de
 *    réussite faux, et bas, en plein milieu d'année.
 *
 *      > Un indicateur qui confond « pas encore décidé » et « refusé »
 *      > ne mesure pas la réussite, il mesure l'avancement du conseil.
 */

declare(strict_types=1);

/**
 * Les années scolaires de l'école, la plus récente d'abord.
 *
 * @return array<int, array<string, mixed>>
 */
function reports_repo_years(): array
{
    return tenant_all('academic_years', '1 = 1 ORDER BY starts_on DESC');
}

/** L'année courante, ou la plus récente à défaut. */
function reports_repo_default_year(): ?array
{
    $annees = reports_repo_years();

    foreach ($annees as $annee) {
        if ((int) $annee['is_current'] === 1) {
            return $annee;
        }
    }

    return $annees[0] ?? null;
}

/**
 * LE SQUELETTE DES CLASSES — identité, niveau, cycle, ordre.
 *
 * POURQUOI IL EXISTE, ET CE QU'IL A FAIT GAGNER
 * ==============================================
 * Les trois rapports joignaient `curriculums → education_levels →
 * education_cycles` DANS la requête qui agrège, pour n'en tirer qu'un
 * libellé. Mesuré sur une école ordinaire — 61 classes, 2 408
 * inscriptions, 94 720 pointages :
 *
 *     assiduité, jointures du référentiel comprises   831 ms
 *     assiduité, sans ces jointures                   110 ms
 *
 * MySQL faisait traverser le référentiel par les 95 000 lignes de
 * pointage. Les effectifs et les résultats ne le payaient pas parce
 * qu'ils agrègent dix fois moins de lignes — le défaut était le même,
 * seule la facture différait.
 *
 *   > Une jointure qui n'apporte qu'un libellé n'a rien à faire dans la
 *   > requête qui compte : on agrège d'abord, on décore ensuite.
 *
 * Ce squelette est aussi ce qui donne aux trois rapports le MÊME ordre
 * et la MÊME liste de classes — y compris celles qui n'ont ni élève, ni
 * bulletin, ni pointage, et qu'une jointure interne aurait fait
 * disparaître.
 *
 * @return array<int, array<string, mixed>> indexé par identifiant de classe
 */
function reports_repo_classrooms(int $yearId): array
{
    $lignes = db_all(
        'SELECT c.id, c.code, c.name, c.capacity, c.order_number,
                n.name          AS level_name,
                n.order_number  AS level_order,
                cy.name         AS cycle_name,
                cy.order_number AS cycle_order
           FROM classrooms c
           LEFT JOIN curriculums      cu ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           LEFT JOIN education_levels n  ON n.id  = cu.education_level_id
           LEFT JOIN education_cycles cy ON cy.id = n.cycle_id
          WHERE c.school_id = :school_id AND c.academic_year_id = :year
          ORDER BY cy.order_number, n.order_number, c.order_number, c.name',
        ['school_id' => tenant_require(), 'year' => $yearId],
        true
    );

    $parId = [];

    foreach ($lignes as $ligne) {
        $parId[(int) $ligne['id']] = $ligne;
    }

    return $parId;
}

/**
 * Recolle des agrégats au squelette, dans l'ordre du squelette.
 *
 * Une classe sans aucune ligne agrégée reste présente, avec des zéros :
 * une classe vide est une information, pas une absence.
 *
 * @param array<int, array<string, mixed>> $squelette
 * @param array<int, array<string, mixed>> $agregats    lignes portant `classroom_id`
 * @param array<int, string>               $colonnes
 * @return array<int, array<string, mixed>>
 */
function reports_repo_merge(array $squelette, array $agregats, array $colonnes): array
{
    $parClasse = [];

    foreach ($agregats as $ligne) {
        $parClasse[(int) $ligne['classroom_id']] = $ligne;
    }

    $out = [];

    foreach ($squelette as $id => $classe) {
        $agrege = $parClasse[$id] ?? [];

        foreach ($colonnes as $colonne) {
            $classe[$colonne] = $agrege[$colonne] ?? null;
        }

        $out[] = $classe;
    }

    return $out;
}

/**
 * EFFECTIFS — par classe, avec la répartition par sexe.
 *
 * Les inscriptions ANNULÉES sont comptées à part, jamais dans
 * l'effectif : une classe de 40 dont 3 sont partis en compte 37. Mais
 * les départs sont une information que la direction réclame, donc ils
 * figurent dans leur propre colonne plutôt que d'être tus.
 *
 * L'INVARIANT : garçons + filles = effectif. TOUJOURS.
 *
 * Il ne tenait pas. La jointure écarte les élèves archivés
 * (`deleted_at`), mais `effectif` ne testait que le statut de
 * l'inscription : un élève archivé disparaissait de sa colonne de sexe
 * et restait dans le total. Mesuré sur le décor de démonstration —
 * après archivage d'une élève, le rapport annonçait « 4 garçons,
 * 3 filles, effectif 8 ».
 *
 *   > Un état d'effectifs qui ne s'additionne pas est un état qu'on ne
 *   > peut remettre à personne.
 *
 * D'où le `s.id IS NOT NULL` : on compte les inscriptions dont l'élève
 * est réellement là, et le total redevient la somme de ses parts.
 *
 * @return array<int, array<string, mixed>>
 */
function reports_repo_headcount(int $yearId): array
{
    $agregats = db_all(
        'SELECT e.classroom_id,
                SUM(CASE WHEN e.status <> \'cancelled\' AND s.gender = \'M\' THEN 1 ELSE 0 END) AS garcons,
                SUM(CASE WHEN e.status <> \'cancelled\' AND s.gender = \'F\' THEN 1 ELSE 0 END) AS filles,
                SUM(CASE WHEN e.status <> \'cancelled\' AND s.id IS NOT NULL THEN 1 ELSE 0 END) AS effectif,
                SUM(CASE WHEN e.status =  \'cancelled\' AND s.id IS NOT NULL THEN 1 ELSE 0 END) AS partis,
                SUM(CASE WHEN e.status <> \'cancelled\' AND s.id IS NOT NULL
                          AND e.repeated_year = 1 THEN 1 ELSE 0 END)                          AS redoublants
           FROM enrollments e
           LEFT JOIN students s ON s.id = e.student_id AND s.school_id = e.school_id
                               AND s.deleted_at IS NULL
          WHERE e.school_id = :school_id AND e.academic_year_id = :year
            AND e.classroom_id IS NOT NULL
          GROUP BY e.classroom_id',
        ['school_id' => tenant_require(), 'year' => $yearId],
        true
    );

    return reports_repo_merge(
        reports_repo_classrooms($yearId),
        $agregats,
        ['garcons', 'filles', 'effectif', 'partis', 'redoublants']
    );
}

/**
 * Les regroupements de bulletins présents pour cette année.
 *
 * On les LIT plutôt que de les déclarer. `bulletins.period_key` n'est
 * pas un code de `grade_periods` : c'est une clé de REGROUPEMENT —
 * T1/T2/T3 pour un cycle trimestriel, S1/S2 pour un semestriel, plus
 * ANNUAL. Elle dépend donc du cycle de la classe, et une liste tenue à
 * la main finirait par mentir sur ce que la table contient.
 *
 * @return array<int, string>
 */
function reports_repo_period_keys(int $yearId): array
{
    return array_map(
        static fn (array $r): string => (string) $r['period_key'],
        db_all(
            'SELECT DISTINCT b.period_key
               FROM bulletins b
               JOIN enrollments e ON e.id = b.enrollment_id AND e.school_id = b.school_id
              WHERE b.school_id = :school_id AND e.academic_year_id = :year
              ORDER BY b.period_key',
            ['school_id' => tenant_require(), 'year' => $yearId],
            true
        )
    );
}

/**
 * RÉSULTATS — par classe, pour un regroupement de périodes.
 *
 * `decides` et `sans_decision` sont séparés à dessein : le taux de
 * réussite se calcule sur les bulletins RÉELLEMENT décidés, et l'écran
 * dit combien ne le sont pas encore.
 *
 * IL N'Y A PAS DE COLONNE « PUBLIÉS », et ce n'est pas un oubli.
 * `bulletins.published_at` est `NOT NULL` avec `CURRENT_TIMESTAMP` pour
 * défaut : une ligne n'existe qu'une fois le bulletin publié. Compter
 * les publiés rendrait toujours le nombre de bulletins.
 *
 *   > Une colonne qui ne peut pas différer d'une autre n'est pas un
 *   > indicateur, c'est une décoration.
 *
 * ATTENTION À LA JOINTURE EXTERNE.
 * `sans_decision` testait seulement `b.decision IS NULL` : les élèves
 * SANS AUCUN BULLETIN, que le LEFT JOIN rend avec toutes les colonnes à
 * NULL, y étaient comptés. Sur une classe de 8 élèves dont 5 ont un
 * bulletin, le rapport annonçait 5 bulletins dont 5 sans décision — et
 * `bulletins` ne valait plus `decides + sans_decision`.
 *
 *   > Dans une jointure externe, « la colonne est NULL » et « la ligne
 *   > n'existe pas » se ressemblent au point de se confondre.
 *
 * D'où le `b.id IS NOT NULL` : on ne compte que des bulletins réels.
 *
 * @return array<int, array<string, mixed>>
 */
function reports_repo_results(int $yearId, string $periodKey): array
{
    $agregats = db_all(
        'SELECT e.classroom_id,
                COUNT(b.id)                                                        AS bulletins,
                AVG(b.percentage)                                                   AS moyenne,
                MIN(b.percentage)                                                   AS mini,
                MAX(b.percentage)                                                   AS maxi,
                SUM(CASE WHEN b.decision IS NOT NULL THEN 1 ELSE 0 END)             AS decides,
                SUM(CASE WHEN b.id IS NOT NULL AND b.decision IS NULL
                         THEN 1 ELSE 0 END)                                         AS sans_decision,
                SUM(CASE WHEN b.decision = \'passed\'      THEN 1 ELSE 0 END)        AS reussites,
                SUM(CASE WHEN b.decision = \'failed\'      THEN 1 ELSE 0 END)        AS echecs,
                SUM(CASE WHEN b.decision = \'conditional\' THEN 1 ELSE 0 END)        AS conditionnels,
                SUM(b.missing_grades)                                               AS cotes_manquantes
           FROM enrollments e
           LEFT JOIN bulletins b ON b.enrollment_id = e.id AND b.school_id = e.school_id
                                AND b.period_key = :periode
          WHERE e.school_id = :school_id AND e.academic_year_id = :year
            AND e.classroom_id IS NOT NULL AND e.status <> \'cancelled\'
          GROUP BY e.classroom_id',
        ['school_id' => tenant_require(), 'year' => $yearId, 'periode' => $periodKey],
        true
    );

    return reports_repo_merge(
        reports_repo_classrooms($yearId),
        $agregats,
        ['bulletins', 'moyenne', 'mini', 'maxi', 'decides', 'sans_decision',
         'reussites', 'echecs', 'conditionnels', 'cotes_manquantes']
    );
}

/**
 * ASSIDUITÉ — par classe, sur une PLAGE DE DATES.
 *
 * POURQUOI PAR DATES ET NON PAR PÉRIODE
 * ======================================
 * `grade_periods.starts_on` et `ends_on` ne sont jamais remplis — dette
 * connue et inscrite dans l'état du projet. Découper l'assiduité par
 * période supposerait donc des bornes qui n'existent pas.
 * `attendance_sessions.session_date` est, elle, une donnée réelle.
 *
 *   > Mieux vaut un découpage que la donnée permet qu'un découpage que
 *   > la donnée n'a jamais porté.
 *
 * @return array<int, array<string, mixed>>
 */
function reports_repo_attendance(int $yearId, ?string $du, ?string $au): array
{
    $params = ['school_id' => tenant_require(), 'year' => $yearId];
    $bornes = '';

    if ($du !== null) {
        $bornes       .= ' AND se.session_date >= :du';
        $params['du']  = $du;
    }

    if ($au !== null) {
        $bornes       .= ' AND se.session_date <= :au';
        $params['au']  = $au;
    }

    // DEUX AGRÉGATIONS PLUTÔT QU'UNE.
    //
    // Compter les séances et compter les pointages sont deux questions
    // de granularité différente. Les poser ensemble obligeait à un
    // `COUNT(DISTINCT se.id)` sur des dizaines de milliers de lignes ;
    // séparées, chacune lit ce qu'il lui faut et rien de plus.
    $seances = db_all(
        'SELECT se.classroom_id, COUNT(*) AS seances
           FROM attendance_sessions se
           JOIN classrooms c ON c.id = se.classroom_id AND c.school_id = se.school_id
          WHERE se.school_id = :school_id AND c.academic_year_id = :year' . $bornes . '
          GROUP BY se.classroom_id',
        $params,
        true
    );

    $pointages = db_all(
        'SELECT se.classroom_id,
                SUM(CASE WHEN r.status = \'present\' THEN 1 ELSE 0 END) AS presences,
                SUM(CASE WHEN r.status = \'absent\'  THEN 1 ELSE 0 END) AS absences,
                SUM(CASE WHEN r.status = \'late\'    THEN 1 ELSE 0 END) AS retards,
                SUM(CASE WHEN r.status = \'absent\' AND r.is_justified = 1
                         THEN 1 ELSE 0 END)                              AS justifiees,
                COUNT(r.id)                                              AS pointages
           FROM attendance_records r
           JOIN attendance_sessions se ON se.id = r.session_id AND se.school_id = r.school_id
           JOIN classrooms c ON c.id = se.classroom_id AND c.school_id = se.school_id
          WHERE r.school_id = :school_id AND c.academic_year_id = :year' . $bornes . '
          GROUP BY se.classroom_id',
        $params,
        true
    );

    $lignes = reports_repo_merge(
        reports_repo_classrooms($yearId),
        $seances,
        ['seances']
    );

    $parClasse = [];

    foreach ($pointages as $ligne) {
        $parClasse[(int) $ligne['classroom_id']] = $ligne;
    }

    foreach ($lignes as $rang => $ligne) {
        $agrege = $parClasse[(int) $ligne['id']] ?? [];

        foreach (['presences', 'absences', 'retards', 'justifiees', 'pointages'] as $colonne) {
            $lignes[$rang][$colonne] = $agrege[$colonne] ?? null;
        }
    }

    return $lignes;
}

/**
 * La première et la dernière séance de pointage de l'année.
 *
 * Sert à proposer une plage par défaut qui a du sens, plutôt que des
 * champs vides que l'utilisateur doit deviner.
 *
 * @return array{first: string|null, last: string|null}
 */
function reports_repo_attendance_span(int $yearId): array
{
    $r = db_one(
        'SELECT MIN(se.session_date) AS first, MAX(se.session_date) AS last
           FROM attendance_sessions se
           JOIN classrooms c ON c.id = se.classroom_id AND c.school_id = se.school_id
          WHERE se.school_id = :school_id AND c.academic_year_id = :year',
        ['school_id' => tenant_require(), 'year' => $yearId],
        true
    );

    return [
        'first' => $r['first'] !== null ? (string) $r['first'] : null,
        'last'  => $r['last'] !== null ? (string) $r['last'] : null,
    ];
}
