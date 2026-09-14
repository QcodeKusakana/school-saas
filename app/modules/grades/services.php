<?php
/**
 * Module GRADES — règles métier.
 *
 * Une cote est la donnée la plus sensible du produit après l'état civil :
 * elle décide du passage de classe. Trois principes sont tenus ici.
 *
 *  1. AUTORISATION EN DEUX TEMPS
 *     « grade.enter » dit qu'on a le droit de saisir des notes. Il ne dit
 *     pas lesquelles. Le second temps — teachers_can_teach() — dit dans
 *     quelle classe et quelle branche. Aucune saisie n'est acceptée sans
 *     les deux.
 *
 *  2. LE MAXIMUM EST FIGÉ
 *     Il est calculé une fois, à la première saisie, et enregistré sur la
 *     ligne. Modifier plus tard le programme ne réécrit aucune cote déjà
 *     posée.
 *
 *  3. LE VERROU EST UNE DÉCISION, PAS UN OBSTACLE
 *     Une période verrouillée refuse la saisie. « grade.edit_locked »
 *     permet l'exception, et chaque exception est journalisée nommément.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/../teachers/repositories.php';

// =====================================================================
//  AUTORISATION
// =====================================================================

/**
 * L'utilisateur courant peut-il saisir dans cette branche de cette classe ?
 *
 * @return array{ok: bool, message: string}
 */
function grades_service_can_enter(int $classroomId, int $curriculumSubjectId): array
{
    if (!perm_has('grade.enter')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de saisir des notes.'];
    }

    // La direction et le préfet valident les notes de l'établissement :
    // ils saisissent partout, y compris pour remplacer un enseignant
    // absent. C'est un droit distinct, et il est explicite.
    if (perm_has('grade.validate')) {
        return ['ok' => true, 'message' => ''];
    }

    if (teachers_can_teach($classroomId, $curriculumSubjectId)) {
        return ['ok' => true, 'message' => ''];
    }

    return [
        'ok'      => false,
        'message' => 'Cette branche ne vous est pas confiée dans cette classe.',
    ];
}

/**
 * L'état d'une période pour la saisie.
 *
 * @return array{ok: bool, message: string, forced: bool}
 */
function grades_service_period_state(array $period, ?array $year): array
{
    // Le paramètre était typé array et appelé avec « $year ?? [] » : sur
    // un tableau vide, l'accès à la clé absente produisait une erreur
    // 500 au lieu d'un refus. Le cas est traité ici, explicitement.
    if ($year === null) {
        return ['ok' => false, 'forced' => false, 'message' => 'Année scolaire introuvable.'];
    }

    if (in_array($year['status'], ['closed', 'archived'], true)) {
        return [
            'ok'      => false,
            'forced'  => false,
            'message' => 'Cette année scolaire est clôturée : les notes ne peuvent plus être modifiées.',
        ];
    }

    if ((int) $period['is_locked'] === 1) {
        if (perm_has('grade.edit_locked')) {
            // Autorisé, mais signalé : l'appelant doit journaliser.
            return ['ok' => true, 'forced' => true, 'message' => ''];
        }

        return [
            'ok'      => false,
            'forced'  => false,
            'message' => 'La période « ' . $period['name'] . ' » est verrouillée.',
        ];
    }

    return ['ok' => true, 'forced' => false, 'message' => ''];
}

// =====================================================================
//  SAISIE
// =====================================================================

/**
 * Enregistre les cotes d'une classe pour une branche et une période.
 *
 * La saisie est faite en lot : c'est le geste réel de l'enseignant, qui
 * remplit une colonne entière. Traiter les cotes une par une
 * multiplierait les contrôles d'autorisation sans rien sécuriser de plus,
 * et rendrait une correction partielle possible en cas d'erreur.
 *
 * @param array<int, array{points: ?float, is_absent: bool}> $entries
 *        Indexé par enrollment_id.
 *
 * @return array{ok: bool, saved: int, message: string, errors: array<int, string>}
 */
function grades_service_save_sheet(
    int $classroomId,
    int $curriculumSubjectId,
    int $periodId,
    array $entries
): array {
    $classroom = tenant_find('classrooms', $classroomId);

    if ($classroom === null) {
        return ['ok' => false, 'saved' => 0, 'message' => 'Classe introuvable.', 'errors' => []];
    }

    $auth = grades_service_can_enter($classroomId, $curriculumSubjectId);

    if (!$auth['ok']) {
        return ['ok' => false, 'saved' => 0, 'message' => $auth['message'], 'errors' => []];
    }

    // La branche doit appartenir au programme de CETTE classe. Contrôler
    // seulement l'école laisserait passer une branche d'un autre
    // programme, et la cote atterrirait sur un bulletin qui ne la
    // comporte pas.
    $subject = tenant_one(
        'curriculum_subjects',
        'id = :id AND curriculum_id = :curriculum_id',
        ['id' => $curriculumSubjectId, 'curriculum_id' => (int) $classroom['curriculum_id']]
    );

    if ($subject === null) {
        return ['ok' => false, 'saved' => 0, 'message' => 'Cette branche ne figure pas au programme de la classe.', 'errors' => []];
    }

    $period = tenant_one(
        'grade_periods',
        'id = :id AND academic_year_id = :y',
        ['id' => $periodId, 'y' => (int) $classroom['academic_year_id']]
    );

    if ($period === null) {
        return ['ok' => false, 'saved' => 0, 'message' => 'Cette période n\'appartient pas à l\'année de la classe.', 'errors' => []];
    }

    $year  = tenant_find('academic_years', (int) $classroom['academic_year_id']);
    $state = grades_service_period_state($period, $year);

    if (!$state['ok']) {
        return ['ok' => false, 'saved' => 0, 'message' => $state['message'], 'errors' => []];
    }

    // Le maximum applicable : le maximum de la branche multiplié par le
    // poids de la période. Calculé une fois, figé sur chaque ligne.
    $maxPoints = grades_max_for($subject, $period);

    // Les inscriptions réellement présentes dans la classe. Une cote ne
    // peut être posée que pour un élève de CETTE classe : sans ce
    // filtrage, un identifiant forgé dans le formulaire noterait
    // l'élève d'une autre classe, voire d'une autre année.
    $allowed = [];

    foreach (grades_repo_classroom_enrollments($classroomId) as $row) {
        $allowed[(int) $row['id']] = $row;
    }

    // Barèmes DÉJÀ FIGÉS sur cette colonne, s'il en existe.
    //
    // Le maximum calculé ci-dessus vient du programme COURANT ; celui
    // d'une ligne existante a été figé à sa première saisie. Valider
    // contre le premier alors qu'on stocke sur le second laissait
    // enregistrer 95 sur une ligne figée à 40 — soit 237 % du maximum.
    //
    // Le changement de barème est désormais bloqué à sa source (voir
    // curriculum_service_update_subjects), mais les bases déjà
    // divergentes existent : chaque cote est donc validée contre SON
    // propre maximum.
    $frozen = grades_repo_frozen_maxima($classroomId, $curriculumSubjectId, $periodId);

    $errors = [];
    $valid  = [];
    $erase  = [];

    foreach ($entries as $enrollmentId => $entry) {
        $enrollmentId = (int) $enrollmentId;

        if (!isset($allowed[$enrollmentId])) {
            $errors[$enrollmentId] = 'Élève absent de cette classe.';
            continue;
        }

        $isAbsent = !empty($entry['is_absent']);
        $points   = $entry['points'];

        if ($isAbsent) {
            // L'absence efface la cote : les deux ne peuvent coexister.
            $valid[$enrollmentId] = ['points' => null, 'is_absent' => true];
            continue;
        }

        if ($points === null || $points === '') {
            // Case laissée vide.
            //
            // Une case vide n'est PAS une cote : elle ne doit créer
            // aucune ligne. Auparavant, enregistrer une colonne encore
            // vierge créait une ligne par élève — et le tableau de bord
            // du préfet, qui comptait les lignes, affichait la colonne
            // en vert. Il annonçait « complet » sur une classe sans une
            // seule note.
            //
            // Si une cote existait, elle est effacée ; sinon on ne fait
            // rien.
            if (isset($frozen[$enrollmentId])) {
                $erase[] = $enrollmentId;
            }

            continue;
        }

        if (!is_numeric($points)) {
            $errors[$enrollmentId] = 'Cote non numérique.';
            continue;
        }

        $points = round((float) $points, 2);

        if ($points < 0) {
            $errors[$enrollmentId] = 'Une cote ne peut pas être négative.';
            continue;
        }

        $rowMax = $frozen[$enrollmentId] ?? $maxPoints;

        if ($points > $rowMax) {
            $errors[$enrollmentId] = 'Cote supérieure au maximum (' . grades_format($rowMax) . ').';
            continue;
        }

        $valid[$enrollmentId] = ['points' => $points, 'is_absent' => false];
    }

    if ($valid === [] && $erase === []) {
        return [
            'ok' => false, 'saved' => 0,
            'message' => $errors === []
                ? 'Aucune cote à enregistrer.'
                : 'Aucune cote valide à enregistrer.',
            'errors' => $errors,
        ];
    }

    $saved = db_transaction(static function () use (
        $valid, $erase, $curriculumSubjectId, $periodId, $maxPoints
    ): int {
        $count  = 0;
        $userId = auth_id();
        $now    = date('Y-m-d H:i:s');

        // Cases vidées : la ligne disparaît, elle ne devient pas une cote
        // « nulle ». Une cote effacée doit redevenir « non saisie » dans
        // le décompte d'avancement.
        foreach ($erase as $enrollmentId) {
            db_query(
                'DELETE FROM grades
                  WHERE school_id = :school_id
                    AND enrollment_id = :enrollment_id
                    AND curriculum_subject_id = :subject_id
                    AND grade_period_id = :period_id',
                [
                    'school_id'     => tenant_require(),
                    'enrollment_id' => $enrollmentId,
                    'subject_id'    => $curriculumSubjectId,
                    'period_id'     => $periodId,
                ]
            );
        }

        foreach ($valid as $enrollmentId => $entry) {
            // INSERT ... ON DUPLICATE KEY UPDATE : l'écriture est
            // idempotente et sans lecture préalable. Deux enseignants
            // enregistrant la même grille au même instant ne peuvent pas
            // créer de doublon — la contrainte uq_grade_slot tranche.
            //
            // max_points n'est écrit qu'à la CRÉATION : la clause de mise
            // à jour ne le touche pas, une cote déjà posée conserve son
            // barème d'origine.
            db_query(
                'INSERT INTO grades
                    (school_id, enrollment_id, curriculum_subject_id, grade_period_id,
                     points, max_points, is_absent, entered_by, entered_at, updated_by)
                 VALUES
                    (:school_id, :enrollment_id, :subject_id, :period_id,
                     :points, :max_points, :is_absent, :entered_by, :entered_at, :updated_by)
                 ON DUPLICATE KEY UPDATE
                    points     = VALUES(points),
                    is_absent  = VALUES(is_absent),
                    updated_by = VALUES(updated_by)',
                [
                    'school_id'     => tenant_require(),
                    'enrollment_id' => $enrollmentId,
                    'subject_id'    => $curriculumSubjectId,
                    'period_id'     => $periodId,
                    'points'        => $entry['points'],
                    'max_points'    => $maxPoints,
                    'is_absent'     => $entry['is_absent'] ? 1 : 0,
                    'entered_by'    => $userId,
                    'entered_at'    => $now,
                    'updated_by'    => $userId,
                ]
            );

            $count++;
        }

        return $count;
    });

    audit_log(
        'update',
        'grade_sheet',
        $classroomId,
        null,
        [
            'subject_id' => $curriculumSubjectId,
            'period_id'  => $periodId,
            'count'      => $saved,
            'forced'     => $state['forced'],
        ],
        ($state['forced'] ? 'SAISIE SUR PÉRIODE VERROUILLÉE — ' : '')
            . 'Enregistrement de ' . $saved . ' cote(s)'
    );

    return [
        'ok'      => true,
        'saved'   => $saved,
        'message' => $errors === [] ? '' : count($errors) . ' cote(s) rejetée(s).',
        'errors'  => $errors,
    ];
}

/**
 * Maximum applicable à une branche pour une période.
 *
 * Le maximum EST la pondération : pas de coefficient séparé. Une période
 * d'examen pèse deux fois une période ordinaire par son multiplicateur.
 */
function grades_max_for(array $curriculumSubject, array $period): float
{
    return round(
        (float) $curriculumSubject['max_points'] * (float) $period['max_multiplier'],
        2
    );
}

/** Affiche une cote sans décimales inutiles : 40, 12,5. */
function grades_format(?float $value): string
{
    if ($value === null) {
        return '—';
    }

    return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
}

// =====================================================================
//  VERROUILLAGE
// =====================================================================

/**
 * Verrouille ou déverrouille une période.
 *
 * Le verrou fige les cotes d'un trimestre entier : c'est un acte de
 * direction, il doit être attribuable. D'où locked_by et locked_at.
 *
 * @return array{ok: bool, message: string}
 */
function grades_service_set_period_lock(int $periodId, bool $locked): array
{
    if (!perm_has('grade.validate')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de verrouiller une période.'];
    }

    $period = tenant_find('grade_periods', $periodId);

    if ($period === null) {
        return ['ok' => false, 'message' => 'Période introuvable.'];
    }

    $year = tenant_find('academic_years', (int) $period['academic_year_id']);

    if ($year === null || in_array($year['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'message' => 'Cette année scolaire est clôturée.'];
    }

    tenant_update('grade_periods', [
        'is_locked' => $locked ? 1 : 0,
        'locked_at' => $locked ? date('Y-m-d H:i:s') : null,
        'locked_by' => $locked ? auth_id() : null,
    ], 'id = :id', ['id' => $periodId]);

    audit_log(
        'update',
        'grade_period',
        $periodId,
        ['is_locked' => (int) $period['is_locked']],
        ['is_locked' => $locked ? 1 : 0],
        ($locked ? 'Verrouillage' : 'Déverrouillage') . ' de la période ' . $period['name']
    );

    return ['ok' => true, 'message' => ''];
}
