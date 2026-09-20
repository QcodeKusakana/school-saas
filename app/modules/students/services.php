<?php
/**
 * Module STUDENTS — services.
 *
 * Règles du parcours scolaire : matricule, inscription, réinscription,
 * affectation de classe, orientation.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * Modèle de matricule par défaut.
 *
 * Variables disponibles :
 *   {YY}     année d'entrée sur 2 chiffres      → 26
 *   {YYYY}   année d'entrée sur 4 chiffres      → 2026
 *   {SCHOOL} code de l'établissement            → ECO-000001
 *   {SEQ:n}  séquence sur n chiffres            → 0042
 *
 * Le défaut « 26-0042 » est court, dictable au téléphone, et porte
 * l'année d'entrée — ce qui permet de retrouver une promotion d'un
 * coup d'œil. Modifiable par école via le paramètre
 * student.matricule_format.
 */
const STUDENT_MATRICULE_DEFAULT_FORMAT = '{YY}-{SEQ:4}';

/**
 * Attribue le prochain matricule disponible.
 *
 * ------------------------------------------------------------------
 *  POURQUOI UN COMPTEUR VERROUILLÉ
 * ------------------------------------------------------------------
 * Un MAX(matricule) + 1 paraît suffisant, mais deux secrétaires
 * inscrivant un élève à la même seconde liraient le même maximum et
 * produiraient le même matricule. L'une des deux inscriptions
 * échouerait sur la contrainte d'unicité — au mieux.
 *
 * La ligne du compteur est donc verrouillée par SELECT … FOR UPDATE :
 * la seconde transaction attend la première. L'attribution devient
 * strictement séquentielle, sans trou ni doublon.
 *
 * À appeler UNIQUEMENT dans une transaction déjà ouverte.
 */
function students_service_next_matricule(int $entryYear): string
{
    $schoolId = tenant_require();
    $format   = (string) school_setting('student.matricule_format', STUDENT_MATRICULE_DEFAULT_FORMAT);

    $counterKey = students_counter_key($entryYear);

    // La ligne du compteur existe déjà : students_ensure_counter() l'a
    // créée AVANT l'ouverture de la transaction.
    //
    // Elle était créée ici, juste avant le SELECT … FOR UPDATE. Quand
    // elle existe déjà, InnoDB pose un verrou PARTAGÉ pour vérifier le
    // doublon, et le SELECT … FOR UPDATE qui suit en réclame un
    // EXCLUSIF : deux inscriptions simultanées détenaient chacune le
    // verrou partagé et attendaient l'exclusif de l'autre —
    // INTERBLOCAGE, et l'une des deux inscriptions perdue.
    //
    // Le défaut a été trouvé sur le compteur de reçus (audit 5B) en
    // lançant quatre guichets en parallèle ; ce compteur-ci portait le
    // même motif depuis la phase 3, sans avoir jamais été exercé sous
    // concurrence.
    //
    // Verrou exclusif sur la ligne, jusqu'à la fin de la transaction.
    $current = (int) db_value(
        'SELECT last_number FROM student_counters
          WHERE school_id = :school_id AND counter_key = :key
          FOR UPDATE',
        ['school_id' => $schoolId, 'key' => $counterKey]
    );

    $next = $current + 1;

    db_query(
        'UPDATE student_counters SET last_number = :next
          WHERE school_id = :school_id AND counter_key = :key',
        ['next' => $next, 'school_id' => $schoolId, 'key' => $counterKey]
    );

    return students_service_format_matricule($format, $entryYear, $next);
}

/**
 * Crée la ligne du compteur si elle n'existe pas — HORS TRANSACTION.
 *
 * Appelée avant d'ouvrir la transaction, elle valide immédiatement et
 * libère son verrou partagé. Il ne reste ensuite qu'une seule prise de
 * verrou exclusif, qui se met simplement en file d'attente au lieu de
 * provoquer un interblocage.
 */
function students_ensure_counter(int $entryYear): void
{
    db_query(
        'INSERT IGNORE INTO student_counters (school_id, counter_key, last_number)
         VALUES (:school_id, :key, 0)',
        ['school_id' => tenant_require(), 'key' => students_counter_key($entryYear)]
    );
}

/**
 * Clé de la séquence des matricules.
 *
 * Une séquence par année d'entrée si le format contient l'année, une
 * séquence unique sinon. Extraite pour que l'appelant puisse créer la
 * ligne du compteur avant d'ouvrir sa transaction.
 */
function students_counter_key(int $entryYear): string
{
    $format = (string) school_setting('student.matricule_format', STUDENT_MATRICULE_DEFAULT_FORMAT);

    return str_contains($format, '{YY}') || str_contains($format, '{YYYY}')
        ? (string) $entryYear
        : 'GLOBAL';
}

/** Applique le modèle de matricule. */
function students_service_format_matricule(string $format, int $entryYear, int $sequence): string
{
    $schoolCode = (string) db_value(
        'SELECT code FROM schools WHERE id = :id',
        ['id' => tenant_require()],
        true // table globale
    );

    $result = str_replace(
        ['{YY}', '{YYYY}', '{SCHOOL}'],
        [substr((string) $entryYear, -2), (string) $entryYear, $schoolCode],
        $format
    );

    // {SEQ:n} — n chiffres, complété par des zéros à gauche.
    $result = preg_replace_callback(
        '/\{SEQ:(\d+)\}/',
        static fn (array $m): string => str_pad((string) $sequence, (int) $m[1], '0', STR_PAD_LEFT),
        $result
    ) ?? $result;

    return str_replace('{SEQ}', (string) $sequence, $result);
}

// =====================================================================
//  INSCRIPTION D'UN NOUVEL ÉLÈVE
// =====================================================================

/**
 * Crée un dossier élève et sa première inscription, en une transaction.
 *
 * @param array $data        État civil validé
 * @param int   $yearId      Année scolaire de l'inscription
 * @param int|null $classroomId Classe, si connue dès la saisie
 * @return array{ok: bool, id: int|null, matricule: string|null, message: string}
 */
function students_service_enroll_new(array $data, int $yearId, ?int $classroomId = null): array
{
    $year = tenant_find('academic_years', $yearId);

    if ($year === null) {
        return ['ok' => false, 'id' => null, 'matricule' => null, 'message' => 'Année scolaire introuvable.'];
    }

    if (in_array($year['status'], ['closed', 'archived'], true)) {
        return [
            'ok' => false, 'id' => null, 'matricule' => null,
            'message' => 'Cette année scolaire est clôturée : aucune inscription n\'y est possible.',
        ];
    }

    // LA LIMITE DE L'ABONNEMENT (phase 7A).
    //
    // Posée AVANT toute écriture : refuser après avoir créé l'élève
    // laisserait un dossier orphelin, et l'école croirait l'inscription
    // faite. Le message nomme l'offre, le plafond et l'effectif — un
    // refus qui n'explique pas est un refus qu'on ne peut pas corriger.
    //
    // Le refus ne porte que sur la CRÉATION : les dossiers existants
    // restent entiers et consultables. Retenir les données d'une école
    // pour la contraindre à payer serait une prise d'otage.
    require_once APP_PATH . '/modules/subscriptions/services.php';

    $quota = subscription_can_add_student($yearId);

    if (!$quota['ok']) {
        return ['ok' => false, 'id' => null, 'matricule' => null, 'message' => $quota['message']];
    }

    // La classe, si fournie, doit appartenir à l'école ET à l'année.
    if ($classroomId !== null) {
        $classroom = tenant_one(
            'classrooms',
            'id = :id AND academic_year_id = :y',
            ['id' => $classroomId, 'y' => $yearId]
        );

        if ($classroom === null) {
            return [
                'ok' => false, 'id' => null, 'matricule' => null,
                'message' => 'La classe sélectionnée n\'existe pas pour cette année scolaire.',
            ];
        }

        $check = students_service_check_capacity($classroomId);

        if (!$check['ok']) {
            return ['ok' => false, 'id' => null, 'matricule' => null, 'message' => $check['message']];
        }
    }

    $entryYear = (int) date('Y', (int) strtotime((string) $year['starts_on']));

    // Hors transaction, et volontairement : à l'intérieur, le verrou
    // partagé de cet INSERT IGNORE provoquerait un interblocage avec le
    // SELECT … FOR UPDATE d'une inscription simultanée.
    students_ensure_counter($entryYear);

    try {
        return db_transaction(static function () use ($data, $yearId, $classroomId, $entryYear, $year): array {
        // Le contrôle ci-dessus donne un message immédiat dans le cas
        // courant ; celui-ci, verrou posé, est le seul qui fasse foi
        // lorsque deux inscriptions arrivent en même temps.
        if ($classroomId !== null) {
            students_service_lock_classroom($classroomId);

            $recheck = students_service_check_capacity($classroomId);

            if (!$recheck['ok']) {
                throw new RuntimeException($recheck['message']);
            }
        }

        $matricule = students_service_next_matricule($entryYear);

        $studentId = tenant_insert('students', [
            'uuid'            => str_uuid(),
            'matricule'       => $matricule,
            'last_name'       => $data['last_name'],
            'post_name'       => $data['post_name'] ?? null,
            'first_name'      => $data['first_name'],
            'gender'          => $data['gender'],
            'birth_date'      => $data['birth_date'] ?? null,
            'birth_place'     => $data['birth_place'] ?? null,
            'nationality'     => $data['nationality'] ?? 'Congolaise',
            'address'         => $data['address'] ?? null,
            'phone'           => $data['phone'] ?? null,
            'email'           => $data['email'] ?? null,
            'entry_date'      => $data['entry_date'] ?? date('Y-m-d'),
            'previous_school' => $data['previous_school'] ?? null,
            'status'          => 'active',
            'created_by'      => auth_id(),
        ]);

        $enrollmentId = tenant_insert('enrollments', [
            'student_id'        => $studentId,
            'academic_year_id'  => $yearId,
            'classroom_id'      => $classroomId,
            'enrollment_type'   => !empty($data['previous_school']) ? 'transfer' : 'new',
            'status'            => $classroomId !== null ? 'enrolled' : 'admitted',
            'pre_registered_on' => date('Y-m-d'),
            'admitted_on'       => date('Y-m-d'),
            'enrolled_on'       => $classroomId !== null ? date('Y-m-d') : null,
            'created_by'        => auth_id(),
        ]);

        students_service_log_event(
            $studentId,
            'enrollment',
            'Inscription — ' . $year['code'],
            'Premier dossier ouvert dans l\'établissement. Matricule ' . $matricule . '.',
            $yearId,
            'enrollment',
            $enrollmentId
        );

        audit_log(
            'create',
            'student',
            $studentId,
            null,
            ['matricule' => $matricule, 'name' => $data['last_name'] . ' ' . $data['first_name']],
            'Inscription d\'un nouvel élève'
        );

        return ['ok' => true, 'id' => $studentId, 'matricule' => $matricule, 'message' => ''];
        });
    } catch (RuntimeException $e) {
        return ['ok' => false, 'id' => null, 'matricule' => null, 'message' => $e->getMessage()];
    }
}

// =====================================================================
//  RÉINSCRIPTION
// =====================================================================

/**
 * Réinscrit un élève existant pour une nouvelle année scolaire.
 *
 * L'état civil n'est JAMAIS ressaisi : seule une nouvelle ligne
 * d'inscription est créée, pointant sur le même dossier élève. C'est ce
 * qui permet de conserver un parcours continu sur treize ans.
 *
 * @return array{ok: bool, id: int|null, message: string}
 */
function students_service_re_enroll(int $studentId, int $yearId, ?int $classroomId = null, bool $repeated = false): array
{
    $student = students_repo_find($studentId);

    if ($student === null) {
        return ['ok' => false, 'id' => null, 'message' => 'Élève introuvable.'];
    }

    if ($student['status'] !== 'active') {
        return [
            'ok' => false, 'id' => null,
            'message' => 'Ce dossier n\'est pas actif (' . $student['status'] . ') : réactivez-le avant de réinscrire.',
        ];
    }

    $year = tenant_find('academic_years', $yearId);

    if ($year === null) {
        return ['ok' => false, 'id' => null, 'message' => 'Année scolaire introuvable.'];
    }

    if (in_array($year['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'id' => null, 'message' => 'Cette année scolaire est clôturée.'];
    }

    // LA LIMITE VAUT AUSSI À LA RÉINSCRIPTION (phase 7A).
    //
    // Sans ce contrôle, une école de 700 élèves passée sur une offre à
    // 600 les réinscrirait tous à la rentrée suivante : la limite ne
    // s'appliquerait qu'aux nouveaux, donc quasiment jamais.
    //
    // La mesure porte sur l'année VISÉE, pas sur l'année courante : une
    // rentrée préparée en août, avant que la nouvelle année ne devienne
    // courante, échapperait sinon entièrement au plafond.
    require_once APP_PATH . '/modules/subscriptions/services.php';

    $quota = subscription_can_add_student($yearId);

    if (!$quota['ok']) {
        return ['ok' => false, 'id' => null, 'message' => $quota['message']];
    }

    // La contrainte uq_enrollment_student_year l'empêcherait de toute
    // façon, mais un message clair vaut mieux qu'une erreur SQL.
    if (students_repo_enrollment($studentId, $yearId) !== null) {
        return ['ok' => false, 'id' => null, 'message' => 'Cet élève est déjà inscrit pour cette année scolaire.'];
    }

    if ($classroomId !== null) {
        $classroom = tenant_one(
            'classrooms',
            'id = :id AND academic_year_id = :y',
            ['id' => $classroomId, 'y' => $yearId]
        );

        if ($classroom === null) {
            return ['ok' => false, 'id' => null, 'message' => 'La classe n\'existe pas pour cette année scolaire.'];
        }

        $check = students_service_check_capacity($classroomId);

        if (!$check['ok']) {
            return ['ok' => false, 'id' => null, 'message' => $check['message']];
        }
    }

    try {
        return db_transaction(static function () use ($studentId, $yearId, $classroomId, $repeated, $year, $student): array {
        if ($classroomId !== null) {
            students_service_lock_classroom($classroomId);

            $recheck = students_service_check_capacity($classroomId);

            if (!$recheck['ok']) {
                throw new RuntimeException($recheck['message']);
            }
        }

        $enrollmentId = tenant_insert('enrollments', [
            'student_id'        => $studentId,
            'academic_year_id'  => $yearId,
            'classroom_id'      => $classroomId,
            'enrollment_type'   => 're_enrollment',
            'status'            => $classroomId !== null ? 'enrolled' : 'admitted',
            'pre_registered_on' => date('Y-m-d'),
            'admitted_on'       => date('Y-m-d'),
            'enrolled_on'       => $classroomId !== null ? date('Y-m-d') : null,
            'repeated_year'     => $repeated ? 1 : 0,
            'created_by'        => auth_id(),
        ]);

        students_service_log_event(
            $studentId,
            $repeated ? 'repeat' : 'promotion',
            ($repeated ? 'Redoublement' : 'Réinscription') . ' — ' . $year['code'],
            null,
            $yearId,
            'enrollment',
            $enrollmentId
        );

        audit_log(
            'create',
            'enrollment',
            $enrollmentId,
            null,
            ['student' => $student['matricule'], 'year' => $year['code'], 'repeated' => $repeated],
            'Réinscription'
        );

        return ['ok' => true, 'id' => $enrollmentId, 'message' => ''];
        });
    } catch (RuntimeException $e) {
        return ['ok' => false, 'id' => null, 'message' => $e->getMessage()];
    }
}

// =====================================================================
//  AFFECTATION DE CLASSE
// =====================================================================

/**
 * Vérifie qu'une classe peut encore accueillir un élève.
 *
 * @return array{ok: bool, message: string, current: int, capacity: int}
 */
function students_service_check_capacity(int $classroomId): array
{
    $classroom = tenant_find('classrooms', $classroomId);

    if ($classroom === null) {
        return ['ok' => false, 'message' => 'Classe introuvable.', 'current' => 0, 'capacity' => 0];
    }

    $current = (int) db_value(
        'SELECT COUNT(*) FROM enrollments
          WHERE school_id = :school_id AND classroom_id = :classroom_id AND status <> \'cancelled\'',
        ['school_id' => tenant_require(), 'classroom_id' => $classroomId]
    );

    $capacity = (int) $classroom['capacity'];

    if ($capacity > 0 && $current >= $capacity) {
        return [
            'ok'       => false,
            'message'  => sprintf(
                'La classe « %s » est complète (%d / %d). Augmentez sa capacité ou choisissez une autre classe.',
                $classroom['name'],
                $current,
                $capacity
            ),
            'current'  => $current,
            'capacity' => $capacity,
        ];
    }

    return ['ok' => true, 'message' => '', 'current' => $current, 'capacity' => $capacity];
}

/**
 * Affecte ou change la classe d'une inscription.
 *
 * $studentId est obligatoire et vérifié : le contrôleur reçoit l'élève
 * dans l'URL et l'inscription dans le corps du formulaire. Sans ce
 * recoupement, remplacer enrollment_id dans le POST déplaçait l'élève
 * d'un CAMARADE de classe, en journalisant l'opération sous l'élève
 * affiché — une modification invisible à la relecture.
 */
function students_service_assign_classroom(int $studentId, int $enrollmentId, int $classroomId): array
{
    $enrollment = tenant_find('enrollments', $enrollmentId);

    if ($enrollment === null || (int) $enrollment['student_id'] !== $studentId) {
        return ['ok' => false, 'message' => 'Inscription introuvable pour cet élève.'];
    }

    if ($enrollment['status'] === 'cancelled') {
        return [
            'ok'      => false,
            'message' => 'Cette inscription est annulée : elle ne peut pas recevoir de classe.',
        ];
    }

    $year = tenant_find('academic_years', (int) $enrollment['academic_year_id']);

    if ($year === null || in_array($year['status'], ['closed', 'archived'], true)) {
        return [
            'ok'      => false,
            'message' => 'Cette année scolaire est clôturée : elle ne peut plus être modifiée.',
        ];
    }

    $classroom = tenant_one(
        'classrooms',
        'id = :id AND academic_year_id = :y',
        ['id' => $classroomId, 'y' => (int) $enrollment['academic_year_id']]
    );

    if ($classroom === null) {
        return ['ok' => false, 'message' => 'La classe n\'appartient pas à l\'année scolaire de cette inscription.'];
    }

    $unchanged = (int) $enrollment['classroom_id'] === $classroomId;

    try {
        db_transaction(static function () use ($enrollment, $enrollmentId, $classroomId, $classroom, $unchanged): void {
            if (!$unchanged) {
                students_service_lock_classroom($classroomId);

                $check = students_service_check_capacity($classroomId);

                if (!$check['ok']) {
                    throw new RuntimeException($check['message']);
                }
            }

            tenant_update('enrollments', [
                'classroom_id' => $classroomId,
                'status'       => 'enrolled',
                'enrolled_on'  => $enrollment['enrolled_on'] ?? date('Y-m-d'),
            ], 'id = :id', ['id' => $enrollmentId]);

            audit_log(
                'update',
                'enrollment',
                $enrollmentId,
                ['classroom_id' => $enrollment['classroom_id']],
                ['classroom_id' => $classroomId],
                'Affectation à la classe ' . $classroom['name']
            );
        });
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Verrouille la ligne d'une classe jusqu'à la fin de la transaction.
 *
 * Le contrôle de capacité est un COUNT suivi d'un INSERT. Sans verrou,
 * deux secrétaires validant une inscription à la même seconde lisent le
 * même effectif, passent tous les deux le contrôle et dépassent la
 * capacité — exactement le scénario que le matricule évite déjà par le
 * même moyen. Aucune contrainte SQL ne rattrape un effectif dépassé.
 *
 * À appeler dans une transaction ouverte, avant students_service_check_capacity().
 */
function students_service_lock_classroom(int $classroomId): void
{
    db_query(
        'SELECT id FROM classrooms
          WHERE school_id = :school_id AND id = :id FOR UPDATE',
        ['school_id' => tenant_require(), 'id' => $classroomId]
    );
}

// =====================================================================
//  TUTEURS
// =====================================================================

/**
 * Rattache un tuteur à un élève, en le créant si son téléphone est inconnu.
 *
 * La recherche par téléphone évite les doublons : dans une fratrie, le
 * même parent est saisi une fois et réutilisé pour chaque enfant.
 *
 * @return array{ok: bool, guardian_id: int|null, created: bool, message: string}
 */
function students_service_attach_guardian(int $studentId, array $data): array
{
    $student = students_repo_find($studentId);

    if ($student === null) {
        return ['ok' => false, 'guardian_id' => null, 'created' => false, 'message' => 'Élève introuvable.'];
    }

    $phone = sanitize_phone((string) ($data['phone'] ?? ''));

    return db_transaction(static function () use ($data, $phone, $studentId, $student): array {
        // La recherche est faite DANS la transaction : hors transaction,
        // deux rattachements simultanés du même parent à deux enfants
        // d'une fratrie créaient deux fiches, ce que la déduplication
        // est justement censée empêcher.
        //
        // Le critère est le téléphone ET le nom : voir
        // guardians_repo_find_same_person(). Le père et la mère donnent
        // souvent le même numéro.
        $existing = $phone !== null
            ? guardians_repo_find_same_person($phone, (string) $data['last_name'], (string) $data['first_name'])
            : null;

        $created = false;

        if ($existing !== null) {
            $guardianId = (int) $existing['id'];
        } else {
            $guardianId = tenant_insert('guardians', [
                'uuid'       => str_uuid(),
                'last_name'  => $data['last_name'],
                'post_name'  => $data['post_name'] ?? null,
                'first_name' => $data['first_name'],
                'gender'     => $data['gender'] ?? null,
                'phone'      => $phone ?? '',
                'phone_alt'  => sanitize_phone($data['phone_alt'] ?? null),
                'email'      => $data['email'] ?? null,
                'address'    => $data['address'] ?? null,
                'profession' => $data['profession'] ?? null,
            ]);
            $created = true;
        }

        $alreadyLinked = db_exists(
            'SELECT 1 FROM student_guardians
              WHERE school_id = :school_id AND student_id = :s AND guardian_id = :g LIMIT 1',
            ['school_id' => tenant_require(), 's' => $studentId, 'g' => $guardianId]
        );

        if ($alreadyLinked) {
            return [
                'ok' => false, 'guardian_id' => $guardianId, 'created' => $created,
                'message' => 'Ce tuteur est déjà rattaché à cet élève.',
            ];
        }

        // Le premier tuteur rattaché devient le contact principal.
        $isFirst = !db_exists(
            'SELECT 1 FROM student_guardians
              WHERE school_id = :school_id AND student_id = :s LIMIT 1',
            ['school_id' => tenant_require(), 's' => $studentId]
        );

        tenant_insert('student_guardians', [
            'student_id'   => $studentId,
            'guardian_id'  => $guardianId,
            'relationship' => $data['relationship'] ?? 'tuteur',
            'is_primary'   => !empty($data['is_primary']) || $isFirst ? 1 : 0,
            'is_emergency' => !empty($data['is_emergency']) ? 1 : 0,
            'can_pickup'   => !empty($data['can_pickup']) ? 1 : 0,
            'is_payer'     => !empty($data['is_payer']) || $isFirst ? 1 : 0,
        ]);

        audit_log(
            'create',
            'student_guardian',
            $studentId,
            null,
            ['guardian_id' => $guardianId, 'created' => $created],
            'Rattachement d\'un tuteur à ' . $student['matricule']
        );

        return ['ok' => true, 'guardian_id' => $guardianId, 'created' => $created, 'message' => ''];
    });
}

// =====================================================================
//  ORIENTATION
// =====================================================================

/**
 * Enregistre l'orientation d'un élève vers une filière.
 *
 * La décision est datée et conservée définitivement : c'est une pièce
 * du dossier scolaire, pas un simple champ modifiable de la fiche.
 *
 * @return array{ok: bool, message: string}
 */
function students_service_orient(
    int $studentId,
    int $yearId,
    int $sectionId,
    int $optionId,
    ?float $percentage = null,
    string $notes = ''
): array {
    $student = students_repo_find($studentId);

    if ($student === null) {
        return ['ok' => false, 'message' => 'Élève introuvable.'];
    }

    $year = tenant_find('academic_years', $yearId);

    if ($year === null) {
        return ['ok' => false, 'message' => 'Année scolaire introuvable.'];
    }

    // Une décision d'orientation datée d'aujourd'hui n'a rien à faire
    // dans une année déjà clôturée : la clôture doit valoir pour toutes
    // les écritures, pas seulement pour les inscriptions.
    if (in_array($year['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'message' => 'Cette année scolaire est clôturée : aucune orientation ne peut y être prononcée.'];
    }

    $enrollment = students_repo_enrollment($studentId, $yearId);

    if ($enrollment === null) {
        return ['ok' => false, 'message' => 'Cet élève n\'a pas d\'inscription pour cette année scolaire.'];
    }

    // Niveau achevé, déduit de la classe suivie — pas saisi à la main.
    $level = db_one(
        'SELECT l.id, l.code, l.name
           FROM enrollments e
           JOIN classrooms c   ON c.id = e.classroom_id AND c.school_id = e.school_id
           JOIN curriculums cu ON cu.id = c.curriculum_id AND cu.school_id = e.school_id
           JOIN education_levels l ON l.id = cu.education_level_id
          WHERE e.id = :id AND e.school_id = :school_id
          LIMIT 1',
        ['id' => (int) $enrollment['id'], 'school_id' => tenant_require()]
    );

    if ($level === null) {
        return [
            'ok' => false,
            'message' => 'L\'élève n\'est affecté à aucune classe : le niveau d\'origine ne peut pas être déterminé.',
        ];
    }

    $section = tenant_find('sections', $sectionId);
    $option  = tenant_find('options', $optionId);

    if ($section === null || $option === null) {
        return ['ok' => false, 'message' => 'Section ou option introuvable.'];
    }

    if ((int) $option['section_id'] !== $sectionId) {
        return ['ok' => false, 'message' => 'L\'option ne relève pas de cette section.'];
    }

    if (db_exists(
        'SELECT 1 FROM orientations
          WHERE school_id = :school_id AND student_id = :s AND academic_year_id = :y LIMIT 1',
        ['school_id' => tenant_require(), 's' => $studentId, 'y' => $yearId]
    )) {
        return ['ok' => false, 'message' => 'Une orientation est déjà enregistrée pour cet élève sur cette année.'];
    }

    db_transaction(static function () use ($studentId, $yearId, $level, $sectionId, $optionId, $percentage, $notes, $section, $option): void {
        $orientationId = tenant_insert('orientations', [
            'student_id'       => $studentId,
            'academic_year_id' => $yearId,
            'from_level_id'    => (int) $level['id'],
            // Libellés recopiés à la décision : une orientation est un
            // document historique. Renommer une option dix ans plus tard
            // ne doit pas réécrire les décisions déjà prononcées.
            'level_label'      => $level['name'],
            'section_id'       => $sectionId,
            'section_label'    => $section['name'],
            'option_id'        => $optionId,
            'option_label'     => $option['name'],
            'final_percentage' => $percentage,
            'decided_on'       => date('Y-m-d'),
            'decided_by'       => auth_id(),
            'notes'            => $notes !== '' ? $notes : null,
        ]);

        students_service_log_event(
            $studentId,
            'orientation',
            'Orientation — ' . $option['name'],
            'Décision prise à l\'issue de ' . $level['name']
                . ($percentage !== null ? sprintf(' (%.2f %%)', $percentage) : ''),
            $yearId,
            'orientation',
            $orientationId
        );

        audit_log(
            'create',
            'orientation',
            $orientationId,
            null,
            ['student_id' => $studentId, 'option' => $option['code']],
            'Orientation vers ' . $option['name']
        );
    });

    return ['ok' => true, 'message' => ''];
}

// =====================================================================
//  JOURNAL DU PARCOURS
// =====================================================================

/** Ajoute un événement au parcours de l'élève. */
function students_service_log_event(
    int $studentId,
    string $type,
    string $title,
    ?string $description = null,
    ?int $yearId = null,
    ?string $referenceType = null,
    ?int $referenceId = null
): int {
    return tenant_insert('student_history', [
        'student_id'       => $studentId,
        'academic_year_id' => $yearId,
        'event_type'       => $type,
        'event_date'       => date('Y-m-d'),
        'title'            => mb_substr($title, 0, 150),
        'description'      => $description !== null ? mb_substr($description, 0, 500) : null,
        'reference_type'   => $referenceType,
        'reference_id'     => $referenceId,
        'created_by'       => auth_id(),
    ]);
}

/**
 * Change le statut d'un dossier élève (fin d'études, transfert, abandon).
 * Le dossier n'est jamais supprimé : il passe en archive.
 */
function students_service_change_status(int $studentId, string $status, string $reason = ''): array
{
    $allowed = ['active', 'graduated', 'transferred', 'dropped', 'archived'];

    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'message' => 'Statut inconnu.'];
    }

    $student = students_repo_find($studentId);

    if ($student === null) {
        return ['ok' => false, 'message' => 'Élève introuvable.'];
    }

    // Sortir un élève de la vie scolaire n'est pas une modification de
    // dossier : la réinscription le refuse ensuite, et le dossier
    // disparaît des effectifs. Le catalogue prévoit une permission
    // distincte pour cela, « student.delete », qui n'était appliquée
    // nulle part — le secrétariat pouvait donc archiver tout le fichier
    // avec le seul droit de modification.
    $sensitive = ['graduated', 'transferred', 'dropped', 'archived'];

    if (in_array($status, $sensitive, true) && !perm_has('student.delete')) {
        return [
            'ok'      => false,
            'message' => 'Vous n\'avez pas le droit de clôturer ou d\'archiver un dossier élève.',
        ];
    }

    $labels = [
        'active'      => 'Dossier réactivé',
        'graduated'   => 'Fin des études secondaires',
        'transferred' => 'Transfert vers un autre établissement',
        'dropped'     => 'Abandon de la scolarité',
        'archived'    => 'Dossier archivé',
    ];

    db_transaction(static function () use ($studentId, $status, $reason, $student, $labels): void {
        tenant_update('students', [
            'status'            => $status,
            'status_changed_on' => date('Y-m-d'),
        ], 'id = :id', ['id' => $studentId]);

        students_service_log_event(
            $studentId,
            $status === 'graduated' ? 'graduation' : ($status === 'transferred' ? 'transfer_out' : 'status_change'),
            $labels[$status],
            $reason !== '' ? $reason : null
        );

        audit_log(
            'update',
            'student',
            $studentId,
            ['status' => $student['status']],
            ['status' => $status],
            $labels[$status] . ' — ' . $student['matricule']
        );
    });

    return ['ok' => true, 'message' => $labels[$status] . '.'];
}
