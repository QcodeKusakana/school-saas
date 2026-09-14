<?php
/**
 * Module TEACHERS — règles métier.
 *
 * Toutes les écritures du module passent par ici. Les contrôleurs
 * valident la forme des données ; ce fichier vérifie la cohérence
 * métier et l'appartenance à l'établissement.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/** Statuts d'un enseignant, dans l'ordre d'affichage. */
const TEACHER_STATUSES = [
    'active'    => 'En activité',
    'suspended' => 'Suspendu',
    'left'      => 'Parti de l\'établissement',
];

const TEACHER_EMPLOYMENT_TYPES = [
    'permanent' => 'Permanent',
    'contract'  => 'Sous contrat',
    'volunteer' => 'Bénévole / vacataire',
];

// =====================================================================
//  FICHE ENSEIGNANT
// =====================================================================

/**
 * Crée une fiche enseignant.
 *
 * @return array{ok: bool, id: int|null, message: string}
 */
function teachers_service_create(array $data): array
{
    $userCheck = teachers_service_check_user_link($data['user_id'] ?? null, null);

    if (!$userCheck['ok']) {
        return ['ok' => false, 'id' => null, 'message' => $userCheck['message']];
    }

    $matriculeCheck = teachers_service_check_matricule($data['matricule'] ?? null, null);

    if (!$matriculeCheck['ok']) {
        return ['ok' => false, 'id' => null, 'message' => $matriculeCheck['message']];
    }

    try {
        $teacherId = db_transaction(static function () use ($data): int {
        $id = tenant_insert('teachers', [
            'uuid'            => str_uuid(),
            'user_id'         => $data['user_id'] ?? null,
            'matricule'       => $data['matricule'] ?? null,
            'last_name'       => $data['last_name'],
            'post_name'       => $data['post_name'] ?? null,
            'first_name'      => $data['first_name'],
            'gender'          => $data['gender'] ?? null,
            'birth_date'      => $data['birth_date'] ?? null,
            'phone'           => $data['phone'] ?? null,
            'phone_alt'       => $data['phone_alt'] ?? null,
            'email'           => $data['email'] ?? null,
            'address'         => $data['address'] ?? null,
            'hire_date'       => $data['hire_date'] ?? null,
            'employment_type' => $data['employment_type'] ?? 'permanent',
            'qualification'   => $data['qualification'] ?? null,
            'specialty'       => $data['specialty'] ?? null,
            'status'          => 'active',
            'notes'           => $data['notes'] ?? null,
            'created_by'      => auth_id(),
        ]);

        audit_log(
            'create',
            'teacher',
            $id,
            null,
            ['name' => $data['last_name'] . ' ' . $data['first_name']],
            'Création d\'une fiche enseignant'
        );

        return $id;
        });
    } catch (PDOException $e) {
        // Matricule ou compte attribué entre la vérification et
        // l'écriture : la contrainte d'unicité tranche, le message reste
        // compréhensible.
        if ($e->getCode() === '23000') {
            return [
                'ok' => false, 'id' => null,
                'message' => 'Ce matricule ou ce compte vient d\'être attribué à un autre enseignant.',
            ];
        }

        throw $e;
    }

    return ['ok' => true, 'id' => $teacherId, 'message' => ''];
}

/**
 * Met à jour une fiche enseignant.
 *
 * Seules les clés présentes dans $data sont écrites — même règle que
 * pour le dossier élève : une requête partielle ne doit jamais vider les
 * colonnes qu'elle ne mentionne pas.
 *
 * @return array{ok: bool, message: string}
 */
function teachers_service_update(int $teacherId, array $data): array
{
    $teacher = teachers_repo_find($teacherId);

    if ($teacher === null) {
        return ['ok' => false, 'message' => 'Enseignant introuvable.'];
    }

    if (array_key_exists('user_id', $data)) {
        $userCheck = teachers_service_check_user_link($data['user_id'], $teacherId);

        if (!$userCheck['ok']) {
            return ['ok' => false, 'message' => $userCheck['message']];
        }
    }

    if (array_key_exists('matricule', $data)) {
        $matriculeCheck = teachers_service_check_matricule($data['matricule'], $teacherId);

        if (!$matriculeCheck['ok']) {
            return ['ok' => false, 'message' => $matriculeCheck['message']];
        }
    }

    if ($data === []) {
        return ['ok' => false, 'message' => 'Aucune donnée à enregistrer.'];
    }

    tenant_update('teachers', $data, 'id = :id', ['id' => $teacherId]);
    audit_update('teacher', $teacherId, $teacher, $data, 'Modification d\'une fiche enseignant');

    return ['ok' => true, 'message' => ''];
}

/**
 * Vérifie qu'un compte utilisateur peut être rattaché à cette fiche.
 *
 * Trois refus possibles, et chacun protège une chose différente :
 *  · compte inexistant ou d'une autre école → fuite inter-école ;
 *  · compte déjà lié à un autre enseignant → périmètres cumulés ;
 *  · compte désactivé → accès rouvert par une fiche.
 */
function teachers_service_check_user_link(?int $userId, ?int $currentTeacherId): array
{
    if ($userId === null) {
        return ['ok' => true, 'message' => ''];
    }

    // users est une table globale : le filtre par école est explicite.
    $user = db_one(
        'SELECT id, status FROM users
          WHERE id = :id AND school_id = :school_id LIMIT 1',
        ['id' => $userId, 'school_id' => tenant_require()],
        true
    );

    if ($user === null) {
        return ['ok' => false, 'message' => 'Ce compte utilisateur n\'appartient pas à l\'établissement.'];
    }

    if ($user['status'] !== 'active') {
        return ['ok' => false, 'message' => 'Ce compte utilisateur n\'est pas actif.'];
    }

    $existing = teachers_repo_find_by_user($userId);

    if ($existing !== null && (int) $existing['id'] !== $currentTeacherId) {
        return [
            'ok'      => false,
            'message' => 'Ce compte est déjà rattaché à ' . full_name(
                $existing['last_name'],
                $existing['post_name'],
                $existing['first_name']
            ) . '.',
        ];
    }

    return ['ok' => true, 'message' => ''];
}

/** Vérifie l'unicité du matricule dans l'établissement. */
function teachers_service_check_matricule(?string $matricule, ?int $currentTeacherId): array
{
    if ($matricule === null || trim($matricule) === '') {
        return ['ok' => true, 'message' => ''];
    }

    $existing = tenant_one(
        'teachers',
        'matricule = :m AND deleted_at IS NULL',
        ['m' => trim($matricule)]
    );

    if ($existing !== null && (int) $existing['id'] !== $currentTeacherId) {
        return ['ok' => false, 'message' => 'Ce matricule est déjà attribué à un autre enseignant.'];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Change le statut d'un enseignant.
 *
 * Un départ ne supprime rien : les affectations passées restent, sans
 * quoi les bulletins déjà produits perdraient le nom du professeur qui
 * a enseigné la branche. Elles cessent simplement d'ouvrir un périmètre,
 * puisque teachers_scope_classroom_ids() exige le statut « active ».
 */
function teachers_service_change_status(int $teacherId, string $status, string $reason = ''): array
{
    if (!array_key_exists($status, TEACHER_STATUSES)) {
        return ['ok' => false, 'message' => 'Statut inconnu.'];
    }

    $teacher = teachers_repo_find($teacherId);

    if ($teacher === null) {
        return ['ok' => false, 'message' => 'Enseignant introuvable.'];
    }

    // Le motif va dans status_reason, jamais dans notes : notes est le
    // champ libre de la fiche, saisi par le secrétariat. L'y écrire
    // effaçait son contenu sans retour possible.
    $changes = [
        'status'        => $status,
        'left_on'       => $status === 'left' ? date('Y-m-d') : null,
        'status_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
    ];

    tenant_update('teachers', $changes, 'id = :id', ['id' => $teacherId]);

    audit_log(
        'update',
        'teacher',
        $teacherId,
        ['status' => $teacher['status'], 'status_reason' => $teacher['status_reason'] ?? null],
        ['status' => $status, 'status_reason' => $changes['status_reason']],
        TEACHER_STATUSES[$status] . ($reason !== '' ? ' — ' . $reason : '')
    );

    return ['ok' => true, 'message' => ''];
}

// =====================================================================
//  AFFECTATIONS
// =====================================================================

/**
 * Affecte un enseignant à une branche d'une classe.
 *
 * Toutes les vérifications d'appartenance sont faites ici, et aucune ne
 * peut être contournée par le formulaire :
 *
 *  1. l'enseignant appartient à l'école et est en activité ;
 *  2. la classe appartient à l'école ;
 *  3. l'année de la classe n'est pas clôturée ;
 *  4. la branche appartient au PROGRAMME DE CETTE CLASSE — sans quoi on
 *     pourrait affecter du latin dans une classe de primaire ;
 *  5. la branche n'est pas déjà pourvue par quelqu'un d'autre.
 *
 * @return array{ok: bool, id: int|null, message: string}
 */
function teachers_service_assign(int $teacherId, int $classroomId, int $curriculumSubjectId, ?float $weeklyHours = null): array
{
    $teacher = teachers_repo_find($teacherId);

    if ($teacher === null) {
        return ['ok' => false, 'id' => null, 'message' => 'Enseignant introuvable.'];
    }

    if ($teacher['status'] !== 'active') {
        return [
            'ok' => false, 'id' => null,
            'message' => 'Cet enseignant n\'est pas en activité : réactivez sa fiche avant de lui confier une branche.',
        ];
    }

    $classroom = tenant_find('classrooms', $classroomId);

    if ($classroom === null) {
        return ['ok' => false, 'id' => null, 'message' => 'Classe introuvable.'];
    }

    $year = tenant_find('academic_years', (int) $classroom['academic_year_id']);

    if ($year === null || in_array($year['status'], ['closed', 'archived'], true)) {
        return [
            'ok' => false, 'id' => null,
            'message' => 'Cette année scolaire est clôturée : la répartition ne peut plus être modifiée.',
        ];
    }

    // La branche doit appartenir au programme de la classe. Le contrôle
    // porte sur curriculum_id, pas seulement sur l'école : une branche
    // d'un autre programme de la même école serait sinon acceptée.
    $subject = tenant_one(
        'curriculum_subjects',
        'id = :id AND curriculum_id = :curriculum_id',
        ['id' => $curriculumSubjectId, 'curriculum_id' => (int) $classroom['curriculum_id']]
    );

    if ($subject === null) {
        return [
            'ok' => false, 'id' => null,
            'message' => 'Cette branche ne figure pas au programme de la classe.',
        ];
    }

    $existing = tenant_one(
        'teacher_subjects',
        'classroom_id = :c AND curriculum_subject_id = :s',
        ['c' => $classroomId, 's' => $curriculumSubjectId]
    );

    if ($existing !== null) {
        if ((int) $existing['teacher_id'] === $teacherId) {
            return ['ok' => false, 'id' => (int) $existing['id'], 'message' => 'Cet enseignant assure déjà cette branche dans cette classe.'];
        }

        $current = teachers_repo_find((int) $existing['teacher_id']);

        return [
            'ok' => false, 'id' => null,
            'message' => 'Cette branche est déjà assurée par '
                . ($current !== null
                    ? full_name($current['last_name'], $current['post_name'], $current['first_name'])
                    : 'un autre enseignant')
                . '. Retirez l\'affectation existante avant d\'en créer une nouvelle.',
        ];
    }

    // Les contrôles ci-dessus donnent un message clair dans le cas
    // courant. Ils ne suffisent pas : entre la lecture et l'écriture,
    // un autre préfet peut avoir attribué la même branche. C'est alors
    // la contrainte uq_assignment_slot qui tranche — il faut traduire sa
    // violation en message, et non laisser remonter une page d'erreur.
    try {
        $assignmentId = db_transaction(static function () use ($teacherId, $classroom, $classroomId, $curriculumSubjectId, $weeklyHours, $teacher): int {
        $id = tenant_insert('teacher_subjects', [
            'teacher_id'            => $teacherId,
            'academic_year_id'      => (int) $classroom['academic_year_id'],
            'classroom_id'          => $classroomId,
            'curriculum_subject_id' => $curriculumSubjectId,
            'weekly_hours'          => $weeklyHours,
            'assigned_on'           => date('Y-m-d'),
            'created_by'            => auth_id(),
        ]);

        audit_log(
            'create',
            'teacher_subject',
            $id,
            null,
            ['teacher_id' => $teacherId, 'classroom' => $classroom['code']],
            'Affectation de ' . full_name($teacher['last_name'], $teacher['post_name'], $teacher['first_name'])
                . ' à une branche de ' . $classroom['code']
        );

        return $id;
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return [
                'ok' => false, 'id' => null,
                'message' => 'Cette branche vient d\'être attribuée par un autre utilisateur. Rechargez la page.',
            ];
        }

        throw $e;
    }

    return ['ok' => true, 'id' => $assignmentId, 'message' => ''];
}

/**
 * Retire une affectation.
 *
 * En phase 4B, ce retrait devra refuser de s'exécuter si des notes ont
 * déjà été saisies pour cette branche : l'auteur d'une note doit rester
 * identifiable. Le point est signalé ici pour ne pas être oublié.
 */
function teachers_service_unassign(int $classroomId, int $assignmentId, bool $confirmed = false): array
{
    $assignment = tenant_find('teacher_subjects', $assignmentId);

    // L'affectation doit appartenir à la classe de l'URL. Sans ce
    // recoupement, un envoi sur /classes/12/repartition/999/retirer
    // retirait l'affectation 999 même si elle relevait de la classe 40,
    // et le journal d'audit désignait la mauvaise classe. Même motif
    // d'IDOR que celui corrigé sur l'affectation des élèves en phase 3.
    if ($assignment === null || (int) $assignment['classroom_id'] !== $classroomId) {
        return ['ok' => false, 'message' => 'Affectation introuvable pour cette classe.'];
    }

    $year = tenant_find('academic_years', (int) $assignment['academic_year_id']);

    if ($year === null || in_array($year['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'message' => 'Cette année scolaire est clôturée.'];
    }

    // Des cotes ont-elles déjà été saisies pour cette branche ?
    //
    // Retirer l'affectation ne supprimerait pas les notes — elles sont
    // rattachées à l'inscription, pas à l'enseignant — mais plus rien ne
    // relierait une note à celui qui l'a posée. L'auteur d'une note doit
    // rester identifiable, y compris des années plus tard.
    //
    // Le changement d'enseignant en cours d'année passe donc par la
    // direction : c'est une décision, pas un clic.
    require_once __DIR__ . '/../grades/repositories.php';

    $existingGrades = grades_repo_count_for_assignment(
        (int) $assignment['classroom_id'],
        (int) $assignment['curriculum_subject_id']
    );

    // La garde repose sur une CONFIRMATION EXPLICITE, pas sur une
    // permission.
    //
    // Elle était écrite « && !perm_has('grade.validate') ». Or les quatre
    // seuls rôles détenant teacher.assign — SUPER_ADMIN, SCHOOL_ADMIN,
    // DIRECTION, PREFET — détiennent tous grade.validate : la condition
    // était structurellement toujours fausse, et la protection annoncée
    // n'existait dans aucun cas.
    //
    // Une protection qui ne peut jamais se déclencher est pire qu'aucune
    // protection : elle donne l'illusion d'une garantie.
    if ($existingGrades > 0 && !$confirmed) {
        return [
            'ok'      => false,
            'needs_confirmation' => true,
            'grade_count'        => $existingGrades,
            'message' => $existingGrades . ' cote(s) ont déjà été saisies dans cette branche. '
                . 'Les notes seront conservées, mais plus rien ne reliera '
                . 'l\'enseignant à ce qu\'il a corrigé. Confirmez pour retirer l\'affectation.',
        ];
    }

    tenant_delete('teacher_subjects', 'id = :id', ['id' => $assignmentId]);

    audit_log(
        'delete',
        'teacher_subject',
        $assignmentId,
        ['teacher_id' => $assignment['teacher_id'], 'classroom_id' => $assignment['classroom_id']],
        null,
        'Retrait d\'une affectation'
    );

    return ['ok' => true, 'message' => ''];
}

/**
 * Désigne le titulaire d'une classe.
 *
 * Le titulaire n'a pas besoin d'enseigner dans la classe : en primaire,
 * il enseigne tout ; dans les humanités, il peut n'y assurer aucune
 * branche et rester responsable de la discipline et du bulletin.
 */
function teachers_service_set_main_teacher(int $classroomId, ?int $teacherId): array
{
    $classroom = tenant_find('classrooms', $classroomId);

    if ($classroom === null) {
        return ['ok' => false, 'message' => 'Classe introuvable.'];
    }

    // Masquer le formulaire dans la vue ne protège rien : un envoi direct
    // sur /classes/{id}/titulaire réécrirait le titulaire d'une année
    // archivée, donc le nom porté par des bulletins déjà produits.
    $year = tenant_find('academic_years', (int) $classroom['academic_year_id']);

    if ($year === null || in_array($year['status'], ['closed', 'archived'], true)) {
        return [
            'ok'      => false,
            'message' => 'Cette année scolaire est clôturée : le titulaire ne peut plus être modifié.',
        ];
    }

    if ($teacherId !== null) {
        $teacher = teachers_repo_find($teacherId);

        if ($teacher === null) {
            return ['ok' => false, 'message' => 'Enseignant introuvable.'];
        }

        if ($teacher['status'] !== 'active') {
            return ['ok' => false, 'message' => 'Cet enseignant n\'est pas en activité.'];
        }
    }

    tenant_update('classrooms', ['main_teacher_id' => $teacherId], 'id = :id', ['id' => $classroomId]);

    audit_log(
        'update',
        'classroom',
        $classroomId,
        ['main_teacher_id' => $classroom['main_teacher_id']],
        ['main_teacher_id' => $teacherId],
        $teacherId !== null ? 'Désignation du titulaire' : 'Retrait du titulaire'
    );

    return ['ok' => true, 'message' => ''];
}

// =====================================================================
//  PARAMÈTRE PÉDAGOGIQUE
// =====================================================================

/** Seuil de réussite de l'établissement, en pourcentage. */
const PASSING_THRESHOLD_DEFAULT = 50.0;

/**
 * Seuil de réussite applicable.
 *
 * La règle nationale est 50 %. Elle est lue depuis les paramètres de
 * l'établissement plutôt qu'écrite dans le code : une école
 * conventionnée peut retenir un autre seuil, et un changement
 * réglementaire ne doit pas imposer une nouvelle version du logiciel.
 *
 * Les bornes protègent d'une saisie absurde : un seuil de 0 ferait
 * réussir tout le monde, un seuil de 100 ne laisserait passer personne.
 */
function teachers_passing_threshold(): float
{
    $value = (float) school_setting('grading.passing_threshold', PASSING_THRESHOLD_DEFAULT);

    if ($value < 1.0 || $value > 99.0) {
        return PASSING_THRESHOLD_DEFAULT;
    }

    return $value;
}
