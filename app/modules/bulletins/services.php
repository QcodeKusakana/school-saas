<?php
/**
 * Module BULLETINS — calcul et publication.
 *
 * Le bulletin est le document qui décide du passage de classe. Trois
 * principes le gouvernent.
 *
 *  1. LE MAXIMUM VIENT DE LA COTE, JAMAIS DU PROGRAMME
 *     grades.max_points est figé à la saisie. Le bulletin l'additionne
 *     tel quel. Recalculer depuis curriculum_subjects rendrait un
 *     bulletin déjà remis non reproductible.
 *
 *  2. LE RANG SE FIGE À LA PUBLICATION
 *     Le rang dépend de toute la classe. Tant que le bulletin n'est pas
 *     publié, il est recalculé à chaque affichage — c'est l'état de
 *     travail. Une fois publié, il ne bouge plus.
 *
 *  3. UNE ABSENCE N'EST PAS UN ZÉRO, ET LE TRAITEMENT EST UN CHOIX
 *     Exclure du total ou compter zéro : les deux se défendent, aucun
 *     n'est universel en RDC. C'est un paramètre d'établissement.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/../grades/repositories.php';
require_once __DIR__ . '/../students/repositories.php';

/**
 * Regroupements du bulletin, dans l'ordre d'affichage.
 *
 * Les clés des semestres et de l'année ne sont pas des périodes : ce
 * sont des totaux, calculés à partir des périodes qu'elles listent.
 */
const BULLETIN_GROUPS = [
    'S1'     => ['label' => 'Premier semestre', 'periods' => ['P1', 'P2', 'EX1']],
    'S2'     => ['label' => 'Second semestre',  'periods' => ['P3', 'P4', 'EX2']],
    'ANNUAL' => ['label' => 'Total général',    'periods' => ['P1', 'P2', 'EX1', 'P3', 'P4', 'EX2']],
];

/** Traitement des absences, par défaut. */
const BULLETIN_ABSENCE_DEFAULT = 'excluded';

const BULLETIN_DECISIONS = [
    'passed'      => 'Réussite',
    'failed'      => 'Échec',
    'conditional' => 'Passage conditionnel',
    'excluded'    => 'Exclusion',
    'pending'     => 'En délibération',
];

/**
 * Mode de traitement des absences retenu par l'établissement.
 *
 * 'excluded' — la période absente sort du total ET du maximum ;
 * 'zero'     — l'absence vaut zéro, maximum plein.
 */
function bulletins_absence_mode(): string
{
    $mode = (string) school_setting('grading.absence_mode', BULLETIN_ABSENCE_DEFAULT);

    return in_array($mode, ['excluded', 'zero'], true) ? $mode : BULLETIN_ABSENCE_DEFAULT;
}

// =====================================================================
//  CALCUL
// =====================================================================

/**
 * Calcule le bulletin d'une inscription.
 *
 * Renvoie une structure prête à afficher : une ligne par branche, avec
 * la cote de chaque période, les totaux par semestre et le total
 * général ; puis les totaux de la classe entière.
 *
 * Aucune écriture. Cette fonction est appelée aussi bien pour l'état de
 * travail que pour la publication : le même calcul dans les deux cas,
 * sans quoi le document publié pourrait différer de l'aperçu.
 *
 * @return array{
 *   subjects: array, periods: array, totals: array,
 *   missing: int, absent: int, mode: string
 * }
 */
function bulletins_service_compute(int $enrollmentId): array
{
    $rows = grades_repo_report($enrollmentId);

    if ($rows === []) {
        return [
            'subjects' => [], 'periods' => [], 'totals' => [],
            'missing'  => 0,  'absent'  => 0, 'mode' => bulletins_absence_mode(),
        ];
    }

    $mode     = bulletins_absence_mode();
    $subjects = [];
    $periods  = [];
    $missing  = 0;
    $absent   = 0;

    foreach ($rows as $row) {
        $subjectId = (int) $row['curriculum_subject_id'];
        $code      = (string) $row['period_code'];

        $periods[$code] = [
            'code'       => $code,
            'name'       => $row['period_name'],
            'type'       => $row['period_type'],
            'semester'   => (int) $row['semester'],
            'order'      => (int) $row['period_order'],
            'multiplier' => (float) $row['max_multiplier'],
        ];

        if (!isset($subjects[$subjectId])) {
            $subjects[$subjectId] = [
                'id'       => $subjectId,
                'name'     => $row['subject_name'],
                'short'    => $row['subject_short'],
                'order'    => (int) $row['order_number'],
                'ranking'  => (int) $row['counts_for_ranking'] === 1,
                'cells'    => [],
                'groups'   => [],
            ];
        }

        // Le maximum vient de la cote figée ; grades_repo_report()
        // retombe sur le programme courant uniquement quand la cote
        // n'existe pas encore.
        $max      = (float) $row['max_points'];
        $isAbsent = (int) $row['is_absent'] === 1;
        $points   = $row['points'] !== null ? (float) $row['points'] : null;

        if ($isAbsent) {
            $absent++;
        } elseif ($points === null) {
            $missing++;
        }

        $subjects[$subjectId]['cells'][$code] = [
            'points'    => $points,
            'max'       => $max,
            'is_absent' => $isAbsent,
            'counted'   => bulletins_cell_counts($points, $isAbsent, $mode),
        ];
    }

    uasort($periods, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
    uasort($subjects, static fn (array $a, array $b): int => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);

    // --- Totaux par branche et par regroupement -----------------------
    $totals = [];

    foreach (BULLETIN_GROUPS as $key => $group) {
        $totals[$key] = ['points' => 0.0, 'max' => 0.0, 'ranking_points' => 0.0, 'ranking_max' => 0.0];
    }

    foreach ($subjects as $subjectId => $subject) {
        foreach (BULLETIN_GROUPS as $key => $group) {
            $sum = 0.0;
            $max = 0.0;

            foreach ($group['periods'] as $code) {
                $cell = $subject['cells'][$code] ?? null;

                if ($cell === null || !$cell['counted']) {
                    continue;
                }

                $sum += $cell['points'] ?? 0.0;
                $max += $cell['max'];
            }

            $subjects[$subjectId]['groups'][$key] = [
                'points'     => $sum,
                'max'        => $max,
                'percentage' => $max > 0 ? round($sum / $max * 100, 2) : null,
            ];

            $totals[$key]['points'] += $sum;
            $totals[$key]['max']    += $max;

            // Le classement ignore les branches marquées
            // counts_for_ranking = 0 — conduite, religion par
            // convention. Elles figurent au bulletin mais ne pèsent pas
            // sur le rang.
            if ($subject['ranking']) {
                $totals[$key]['ranking_points'] += $sum;
                $totals[$key]['ranking_max']    += $max;
            }
        }
    }

    foreach ($totals as $key => $total) {
        $totals[$key]['percentage'] = $total['max'] > 0
            ? round($total['points'] / $total['max'] * 100, 2)
            : null;

        // Repli documenté : si AUCUNE branche notée ne compte pour le
        // classement — programme entièrement marqué counts_for_ranking = 0,
        // ou seules des branches exclues ont été corrigées — le classement
        // porte sur le total. Sans ce repli, toute la classe serait « non
        // classée » sans qu'aucun écran ne l'explique.
        $rankingMax    = $total['ranking_max'] > 0 ? $total['ranking_max'] : $total['max'];
        $rankingPoints = $total['ranking_max'] > 0 ? $total['ranking_points'] : $total['points'];

        $totals[$key]['ranking_percentage'] = $rankingMax > 0
            ? round($rankingPoints / $rankingMax * 100, 2)
            : null;

        $totals[$key]['label'] = BULLETIN_GROUPS[$key]['label'];
    }

    return [
        'subjects' => $subjects,
        'periods'  => $periods,
        'totals'   => $totals,
        'missing'  => $missing,
        'absent'   => $absent,
        'mode'     => $mode,
    ];
}

/**
 * Une cellule entre-t-elle dans le total ?
 *
 *  · cote saisie            → oui ;
 *  · absence, mode 'zero'   → oui, comptée zéro sur maximum plein ;
 *  · absence, mode exclusion→ non, ni au total ni au maximum ;
 *  · cote non saisie        → non. Une branche non encore corrigée ne
 *    doit pas peser comme un échec : le bulletin signale le manque
 *    séparément.
 */
function bulletins_cell_counts(?float $points, bool $isAbsent, string $mode): bool
{
    if ($isAbsent) {
        return $mode === 'zero';
    }

    return $points !== null;
}

// =====================================================================
//  CLASSEMENT
// =====================================================================

/**
 * Calcule le classement d'une classe pour un regroupement donné.
 *
 * Le rang s'appuie sur le pourcentage des branches comptant pour le
 * classement. Deux élèves à égalité reçoivent le MÊME rang, et le rang
 * suivant est décalé d'autant — c'est la règle d'usage des bulletins
 * scolaires (1, 2, 2, 4), et non un rang dense.
 *
 * @return array<int, array{rank: int|null, percentage: float|null, points: float, max: float}>
 *         Indexé par enrollment_id.
 */
function bulletins_service_rank_classroom(int $classroomId, string $periodKey): array
{
    if (!isset(BULLETIN_GROUPS[$periodKey])) {
        return [];
    }

    $results = [];

    foreach (grades_repo_classroom_enrollments($classroomId) as $enrollment) {
        $enrollmentId = (int) $enrollment['id'];
        $computed     = bulletins_service_compute($enrollmentId);
        $total        = $computed['totals'][$periodKey] ?? null;

        // Deux pourcentages, deux usages :
        //  · 'percentage'         — celui du document, toutes branches ;
        //  · 'ranking_percentage' — celui qui produit le rang.
        // Les confondre reviendrait à imprimer un chiffre et à en
        // archiver un autre.
        $results[$enrollmentId] = [
            'rank'               => null,
            'percentage'         => $total['percentage'] ?? null,
            'ranking_percentage' => $total['ranking_percentage'] ?? null,
            'points'             => $total['points'] ?? 0.0,
            'max'                => $total['max'] ?? 0.0,
            'missing'            => $computed['missing'],
            'absent'             => $computed['absent'],
            'student'            => $enrollment,
        ];
    }

    // Les élèves sans aucune cote ne sont pas classés : leur attribuer
    // le dernier rang les présenterait comme les plus faibles alors que
    // rien n'a été corrigé pour eux.
    $ranked = array_filter(
        $results,
        static fn (array $r): bool => $r['ranking_percentage'] !== null
    );

    uasort(
        $ranked,
        static fn (array $a, array $b): int => $b['ranking_percentage'] <=> $a['ranking_percentage']
    );

    $position = 0;
    $rank     = 0;
    $previous = null;

    foreach ($ranked as $enrollmentId => $row) {
        $position++;

        if ($previous === null || abs($row['ranking_percentage'] - $previous) > 0.001) {
            $rank     = $position;
            $previous = $row['ranking_percentage'];
        }

        $results[$enrollmentId]['rank'] = $rank;
    }

    return $results;
}

// =====================================================================
//  PUBLICATION
// =====================================================================

/**
 * Publie les bulletins d'une classe pour un regroupement.
 *
 * La publication FIGE le résultat : total, maximum, pourcentage, rang,
 * effectif. À partir de là, ajouter une cote ou inscrire un élève ne
 * modifie plus le document remis aux parents.
 *
 * @return array{ok: bool, published: int, message: string, warnings: array}
 */
function bulletins_service_publish(int $classroomId, string $periodKey): array
{
    if (!perm_has('bulletin.publish')) {
        return ['ok' => false, 'published' => 0, 'message' => 'Vous n\'avez pas le droit de publier les bulletins.', 'warnings' => []];
    }

    if (!isset(BULLETIN_GROUPS[$periodKey])) {
        return ['ok' => false, 'published' => 0, 'message' => 'Regroupement inconnu.', 'warnings' => []];
    }

    $classroom = tenant_find('classrooms', $classroomId);

    if ($classroom === null) {
        return ['ok' => false, 'published' => 0, 'message' => 'Classe introuvable.', 'warnings' => []];
    }

    // La permission dit ce qu'on a le droit de FAIRE, jamais SUR QUI.
    //
    // L'écran de classe porte déjà le périmètre ; l'action de publication
    // doit le porter aussi. Sans cette ligne, une école qui accorde
    // bulletin.publish à ses titulaires — configuration parfaitement
    // plausible, les rôles étant modifiables — laissait chacun figer les
    // rangs de TOUTES les classes de l'établissement, y compris celles
    // qu'il n'enseigne pas.
    if (!students_can_view_classroom($classroomId)) {
        return ['ok' => false, 'published' => 0, 'message' => 'Cette classe ne relève pas de votre périmètre.', 'warnings' => []];
    }

    $year = tenant_find('academic_years', (int) $classroom['academic_year_id']);

    if ($year === null || in_array($year['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'published' => 0, 'message' => 'Cette année scolaire est clôturée.', 'warnings' => []];
    }

    $results = bulletins_service_rank_classroom($classroomId, $periodKey);

    // L'effectif imprimé est celui du CLASSEMENT : « 7e sur 42 » doit
    // compter 42 élèves classés, pas 45 inscrits dont trois sans aucune
    // cote. Le dénominateur du rang et le rang lui-même viennent de la
    // même population, sans quoi le dernier classé n'est pas « sur 45 ».
    $classSize = count(array_filter(
        $results,
        static fn (array $r): bool => $r['ranking_percentage'] !== null
    ));

    if ($results === []) {
        return ['ok' => false, 'published' => 0, 'message' => 'Aucun élève inscrit dans cette classe.', 'warnings' => []];
    }

    $warnings = [];
    $totalMissing = 0;

    foreach ($results as $row) {
        $totalMissing += $row['missing'];
    }

    if ($totalMissing > 0) {
        $warnings[] = $totalMissing . ' cote(s) manquante(s) : les bulletins concernés le mentionnent.';
    }

    $published = db_transaction(static function () use ($results, $periodKey, $classSize): int {
        $count  = 0;
        $userId = auth_id();

        foreach ($results as $enrollmentId => $row) {
            // La décision de fin d'année n'est pas prononcée ici : elle
            // relève du conseil de classe. Le bulletin annuel est publié
            // avec « en délibération », et la décision est enregistrée
            // ensuite, élève par élève.
            $decision = $periodKey === 'ANNUAL' ? 'pending' : null;

            db_query(
                'INSERT INTO bulletins
                    (school_id, enrollment_id, period_key, total_points, max_points,
                     percentage, ranking_percentage, class_rank, class_size,
                     missing_grades, absent_count,
                     decision, published_at, published_by)
                 VALUES
                    (:school_id, :enrollment_id, :period_key, :total, :max,
                     :percentage, :ranking_percentage, :rank, :size,
                     :missing, :absent,
                     :decision, NOW(), :publisher)
                 ON DUPLICATE KEY UPDATE
                    total_points       = VALUES(total_points),
                    max_points         = VALUES(max_points),
                    percentage         = VALUES(percentage),
                    ranking_percentage = VALUES(ranking_percentage),
                    class_rank         = VALUES(class_rank),
                    class_size         = VALUES(class_size),
                    missing_grades     = VALUES(missing_grades),
                    absent_count       = VALUES(absent_count),
                    published_at       = NOW(),
                    published_by       = VALUES(published_by)',
                [
                    'school_id'          => tenant_require(),
                    'enrollment_id'      => $enrollmentId,
                    'period_key'         => $periodKey,
                    'total'              => round($row['points'], 2),
                    'max'                => round($row['max'], 2),
                    'percentage'         => $row['percentage'] ?? 0.0,
                    'ranking_percentage' => $row['ranking_percentage'],
                    'rank'               => $row['rank'],
                    'size'               => $classSize,
                    'missing'            => $row['missing'],
                    'absent'             => $row['absent'],
                    'decision'           => $decision,
                    'publisher'          => $userId,
                ]
            );

            $count++;
        }

        return $count;
    });

    audit_log(
        'publish',
        'bulletin',
        $classroomId,
        null,
        ['period_key' => $periodKey, 'count' => $published, 'missing' => $totalMissing],
        'Publication des bulletins — ' . BULLETIN_GROUPS[$periodKey]['label']
    );

    return ['ok' => true, 'published' => $published, 'message' => '', 'warnings' => $warnings];
}

/**
 * Enregistre la décision de fin d'année d'un élève.
 *
 * Elle est portée à la fois par le bulletin annuel et par l'inscription :
 * le bulletin parce que c'est le document, l'inscription parce que la
 * réinscription de l'année suivante la consulte.
 */
function bulletins_service_set_decision(int $enrollmentId, string $decision): array
{
    if (!perm_has('bulletin.publish')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de prononcer une décision.'];
    }

    if (!array_key_exists($decision, BULLETIN_DECISIONS)) {
        return ['ok' => false, 'message' => 'Décision inconnue.'];
    }

    $enrollment = tenant_find('enrollments', $enrollmentId);

    if ($enrollment === null) {
        return ['ok' => false, 'message' => 'Inscription introuvable.'];
    }

    // Même règle que pour la publication : la permission ne désigne pas
    // l'élève. Une décision de fin d'année prononcée hors périmètre
    // modifierait l'inscription d'un élève dont on n'a pas la charge.
    if (!students_can_view((int) $enrollment['student_id'], (int) $enrollment['academic_year_id'])) {
        return ['ok' => false, 'message' => 'Cet élève ne relève pas de votre périmètre.'];
    }

    $year = tenant_find('academic_years', (int) $enrollment['academic_year_id']);

    if ($year === null || in_array($year['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'message' => 'Cette année scolaire est clôturée.'];
    }

    $bulletin = bulletins_repo_find($enrollmentId, 'ANNUAL');

    if ($bulletin === null) {
        return [
            'ok'      => false,
            'message' => 'Le bulletin annuel doit être publié avant de prononcer une décision.',
        ];
    }

    db_transaction(static function () use ($enrollmentId, $decision, $bulletin): void {
        tenant_update('bulletins', ['decision' => $decision], 'id = :id', ['id' => (int) $bulletin['id']]);

        // final_percentage et class_rank sont recopiés du bulletin, pas
        // recalculés : l'inscription doit porter exactement ce que le
        // document remis à la famille indique.
        tenant_update('enrollments', [
            'decision'         => $decision,
            'decision_date'    => date('Y-m-d'),
            'final_percentage' => $bulletin['percentage'],
            'class_rank'       => $bulletin['class_rank'],
        ], 'id = :id', ['id' => $enrollmentId]);

        audit_log(
            'update',
            'enrollment',
            $enrollmentId,
            null,
            ['decision' => $decision, 'percentage' => $bulletin['percentage']],
            'Décision de fin d\'année : ' . BULLETIN_DECISIONS[$decision]
        );
    });

    return ['ok' => true, 'message' => ''];
}

/**
 * Décision proposée d'après le seuil de réussite de l'établissement.
 *
 * Une PROPOSITION, jamais une décision : le conseil de classe tranche.
 * Le logiciel calcule, il ne délibère pas.
 */
function bulletins_service_suggest_decision(?float $percentage): string
{
    if ($percentage === null) {
        return 'pending';
    }

    require_once APP_PATH . '/modules/teachers/services.php';

    return $percentage >= teachers_passing_threshold() ? 'passed' : 'failed';
}
