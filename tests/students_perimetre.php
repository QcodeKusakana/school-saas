<?php
/**
 * Phase 3 — périmètre de consultation du fichier élèves.
 *
 * Ce que ces tests protègent
 * --------------------------
 * La permission « student.view » était accordée aux rôles ENSEIGNANT et
 * PARENT, avec l'intention — écrite en commentaire, jamais codée — de
 * restreindre la lecture à leurs classes ou à leurs enfants. Faute de
 * cette couche, tout titulaire d'un compte lisait l'intégralité du
 * fichier : état civil de mineurs, adresses, téléphones des parents.
 *
 * Un test qui vérifie qu'un parent voit ses enfants ne prouve rien.
 * Ce qu'il faut prouver, c'est qu'il ne voit PAS les autres — par la
 * liste, par la recherche, et par accès direct à l'identifiant.
 *
 * Usage : php tests/students_perimetre.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

/** Ouvre une session de test au nom d'un utilisateur portant un rôle système. */
function act_as(int $schoolId, string $roleCode): int
{
    static $counter = 0;
    $counter++;

    $userId = db_insert('users', [
        'uuid'          => str_uuid(),
        'school_id'     => $schoolId,
        'username'      => 'perim.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'perim' . $counter . '@example.test',
        'password_hash' => password_hash('MotDePasse2026', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name'     => 'TEST',
        'first_name'    => ucfirst(strtolower($roleCode)),
        'status'        => 'active',
    ], true);

    db_query(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :user_id, id FROM roles WHERE code = :code AND school_id IS NULL',
        ['user_id' => $userId, 'code' => $roleCode],
        true
    );

    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;

    // auth_user() met son résultat en cache statique : sans
    // rafraîchissement, auth_id() renverrait l'utilisateur précédent et
    // le périmètre serait calculé pour la mauvaise personne.
    auth_user(true);
    perm_all(true);
    perm_roles(true);

    return $userId;
}

/** École minimale : année, référentiel, programme activé, classe. */
function build_school(string $code, string $name, int $capacity = 30): array
{
    $schoolId = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => $code, 'slug' => strtolower($code),
        'name' => $name, 'status' => 'active',
    ], true);

    db_query(
        'INSERT INTO school_cycles (school_id, cycle_id, is_active)
         SELECT :school_id, id, 1 FROM education_cycles',
        ['school_id' => $schoolId]
    );

    tenant_set($schoolId);

    $yearId = tenant_insert('academic_years', [
        'code' => '2060-2061', 'name' => 'Test périmètre', 'starts_on' => '2060-09-01',
        'ends_on' => '2061-07-31', 'status' => 'active', 'is_current' => 1,
    ]);

    curriculum_service_import_national();
    curriculum_service_create_standard_periods($yearId);

    $levelId = (int) db_value("SELECT id FROM education_levels WHERE code = 'PRI_5'", [], true);
    $program = curriculum_service_create_program($yearId, $levelId, null, null);
    curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    $classroomId = tenant_insert('classrooms', [
        'academic_year_id' => $yearId,
        'curriculum_id'    => (int) $program['id'],
        'code'             => '5A',
        'name'             => '5ème primaire A',
        'capacity'         => $capacity,
    ]);

    return ['school_id' => $schoolId, 'year_id' => $yearId, 'classroom_id' => $classroomId];
}

echo "\n  PÉRIMÈTRE DE CONSULTATION DU FICHIER ÉLÈVES\n";
echo "  ──────────────────────────────────────────────────────\n\n";

$createdSchools = [];

try {
    // -----------------------------------------------------------------
    //  Mise en place
    // -----------------------------------------------------------------
    $school = build_school('PERIM-A', 'École Périmètre');
    $createdSchools[] = $school['school_id'];

    act_as($school['school_id'], 'SECRETARIAT');
    tenant_set($school['school_id']);

    $enfant = students_service_enroll_new([
        'last_name' => 'KABILA', 'first_name' => 'Grace', 'gender' => 'F',
    ], $school['year_id'], $school['classroom_id']);

    $autre = students_service_enroll_new([
        'last_name' => 'MUKENDI', 'first_name' => 'Joseph', 'gender' => 'M',
    ], $school['year_id'], $school['classroom_id']);

    check('Deux élèves inscrits', $enfant['ok'] && $autre['ok'], $enfant['message'] . $autre['message']);

    $enfantId = (int) $enfant['id'];
    $autreId  = (int) $autre['id'];

    // Rattachement du tuteur à l'enfant.
    $attach = students_service_attach_guardian($enfantId, [
        'last_name'    => 'KABILA', 'first_name' => 'Marie', 'gender' => 'F',
        'phone'        => '+243810000001', 'relationship' => 'mere',
    ]);

    check('Tuteur rattaché au premier élève', $attach['ok'], $attach['message']);

    // -----------------------------------------------------------------
    //  1. Le personnel administratif voit tout l'établissement
    // -----------------------------------------------------------------
    $result = students_repo_search([], $school['year_id']);
    check(
        'SECRETARIAT (student.view.all) voit les deux élèves',
        $result['total'] === 2,
        $result['total'] . ' trouvé(s)'
    );

    check('SECRETARIAT peut ouvrir chaque fiche',
        students_can_view($enfantId) && students_can_view($autreId));

    // -----------------------------------------------------------------
    //  2. Le parent ne voit QUE son enfant
    // -----------------------------------------------------------------
    $parentUserId = act_as($school['school_id'], 'PARENT');
    tenant_set($school['school_id']);

    // Le compte parent est relié à la fiche tuteur.
    tenant_update('guardians', ['user_id' => $parentUserId],
        'id = :id', ['id' => (int) $attach['guardian_id']]);

    check('Le parent ne détient pas student.view.all', !perm_has('student.view.all'));

    $result = students_repo_search([], $school['year_id']);
    check(
        'Le parent ne voit qu\'un seul élève dans la liste',
        $result['total'] === 1,
        $result['total'] . ' trouvé(s)'
    );

    check(
        'L\'élève listé est bien son enfant',
        $result['total'] === 1 && (int) $result['rows'][0]['id'] === $enfantId
    );

    check('Le parent peut ouvrir la fiche de son enfant', students_can_view($enfantId));

    check(
        'ACCÈS DIRECT à la fiche d\'un autre élève : refusé',
        !students_can_view($autreId)
    );

    // La recherche ne doit pas être une porte dérobée.
    $search = students_repo_search(['q' => 'MUKENDI'], $school['year_id']);
    check(
        'La RECHERCHE ne contourne pas le périmètre',
        $search['total'] === 0,
        $search['total'] . ' trouvé(s)'
    );

    // Ni les filtres.
    $filtered = students_repo_search(
        ['classroom_id' => $school['classroom_id']],
        $school['year_id']
    );
    check(
        'Le FILTRE PAR CLASSE ne contourne pas le périmètre',
        $filtered['total'] === 1,
        $filtered['total'] . ' trouvé(s)'
    );

    // -----------------------------------------------------------------
    //  3. L'enseignant : refus par défaut tant que la phase 4 n'existe pas
    // -----------------------------------------------------------------
    act_as($school['school_id'], 'ENSEIGNANT');
    tenant_set($school['school_id']);

    $result = students_repo_search([], $school['year_id']);
    check(
        'ENSEIGNANT sans périmètre défini : aucun élève (le défaut se ferme)',
        $result['total'] === 0,
        $result['total'] . ' trouvé(s)'
    );

    check('ENSEIGNANT ne peut ouvrir aucune fiche',
        !students_can_view($enfantId) && !students_can_view($autreId));

    // -----------------------------------------------------------------
    //  4. Jokers LIKE échappés
    // -----------------------------------------------------------------
    act_as($school['school_id'], 'SECRETARIAT');
    tenant_set($school['school_id']);

    $wildcard = students_repo_search(['q' => '%'], $school['year_id']);
    check(
        'Un « % » saisi ne ramène pas tout le fichier',
        $wildcard['total'] === 0,
        $wildcard['total'] . ' trouvé(s)'
    );

    $underscore = students_repo_search(['q' => '_ABILA'], $school['year_id']);
    check(
        'Un « _ » saisi ne joue pas le rôle de joker',
        $underscore['total'] === 0,
        $underscore['total'] . ' trouvé(s)'
    );

    // -----------------------------------------------------------------
    //  5. Affectation : l'inscription doit appartenir à l'élève de l'URL
    // -----------------------------------------------------------------
    $enrollmentAutre = students_repo_enrollment($autreId, $school['year_id']);

    $idor = students_service_assign_classroom(
        $enfantId,                        // élève affiché
        (int) $enrollmentAutre['id'],     // inscription d'un CAMARADE
        $school['classroom_id']
    );

    check(
        'Affectation avec l\'inscription d\'un autre élève : refusée',
        !$idor['ok'] && str_contains($idor['message'], 'introuvable pour cet élève'),
        $idor['message']
    );

    $legit = students_service_assign_classroom(
        $autreId,
        (int) $enrollmentAutre['id'],
        $school['classroom_id']
    );
    check('Affectation avec le bon élève : acceptée', $legit['ok'], $legit['message']);

    // -----------------------------------------------------------------
    //  6. Année clôturée : plus aucune écriture
    // -----------------------------------------------------------------
    tenant_update('academic_years', ['status' => 'closed'],
        'id = :id', ['id' => $school['year_id']]);

    $closed = students_service_assign_classroom(
        $autreId,
        (int) $enrollmentAutre['id'],
        $school['classroom_id']
    );
    check(
        'Affectation dans une année clôturée : refusée',
        !$closed['ok'] && str_contains($closed['message'], 'clôturée'),
        $closed['message']
    );

    $closedOrient = students_service_orient(
        $autreId, $school['year_id'], 1, 1
    );
    check(
        'Orientation dans une année clôturée : refusée',
        !$closedOrient['ok'] && str_contains($closedOrient['message'], 'clôturée'),
        $closedOrient['message']
    );

    tenant_update('academic_years', ['status' => 'active'],
        'id = :id', ['id' => $school['year_id']]);

    // -----------------------------------------------------------------
    //  7. Inscription annulée : pas de résurrection par l'affectation
    // -----------------------------------------------------------------
    tenant_update('enrollments', ['status' => 'cancelled'],
        'id = :id', ['id' => (int) $enrollmentAutre['id']]);

    $resurrect = students_service_assign_classroom(
        $autreId,
        (int) $enrollmentAutre['id'],
        $school['classroom_id']
    );
    check(
        'Une inscription ANNULÉE n\'est pas réactivée par une affectation',
        !$resurrect['ok'] && str_contains($resurrect['message'], 'annulée'),
        $resurrect['message']
    );

    $state = tenant_find('enrollments', (int) $enrollmentAutre['id']);
    check(
        'Le statut « annulée » est resté intact',
        $state !== null && $state['status'] === 'cancelled',
        (string) ($state['status'] ?? 'introuvable')
    );

    tenant_update('enrollments', ['status' => 'enrolled'],
        'id = :id', ['id' => (int) $enrollmentAutre['id']]);

    // -----------------------------------------------------------------
    //  8. Tuteurs : téléphone du foyer partagé par deux parents
    // -----------------------------------------------------------------
    $pere = students_service_attach_guardian($enfantId, [
        'last_name'    => 'KABILA', 'first_name' => 'Joseph', 'gender' => 'M',
        'phone'        => '+243810000001', // MÊME numéro que la mère
        'relationship' => 'pere',
    ]);

    check(
        'Même téléphone, autre nom : une NOUVELLE fiche tuteur est créée',
        $pere['ok'] && $pere['created'] === true,
        $pere['message']
    );

    check(
        'Le père n\'a pas été confondu avec la mère',
        $pere['ok'] && (int) $pere['guardian_id'] !== (int) $attach['guardian_id']
    );

    // Fratrie : même parent, même nom, même téléphone → fiche réutilisée.
    $frere = students_service_enroll_new([
        'last_name' => 'KABILA', 'first_name' => 'Emmanuel', 'gender' => 'M',
    ], $school['year_id'], $school['classroom_id']);

    $memeMere = students_service_attach_guardian((int) $frere['id'], [
        'last_name'    => 'KABILA', 'first_name' => 'Marie', 'gender' => 'F',
        'phone'        => '+243810000001',
        'relationship' => 'mere',
    ]);

    check(
        'Fratrie : la fiche de la mère est réutilisée, pas dupliquée',
        $memeMere['ok'] && $memeMere['created'] === false
            && (int) $memeMere['guardian_id'] === (int) $attach['guardian_id'],
        $memeMere['message']
    );

    // -----------------------------------------------------------------
    //  9. Dossier supprimé : plus consultable par son identifiant
    // -----------------------------------------------------------------
    tenant_update('students', ['deleted_at' => date('Y-m-d H:i:s')],
        'id = :id', ['id' => (int) $frere['id']]);

    check(
        'Un dossier supprimé n\'est plus accessible par son identifiant',
        students_repo_find((int) $frere['id']) === null
    );
} finally {
    // -----------------------------------------------------------------
    //  Nettoyage
    // -----------------------------------------------------------------
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($createdSchools as $schoolId) {
        tenant_set($schoolId);

        foreach ([
            'student_history', 'orientations', 'student_guardians', 'enrollments',
            'guardians', 'students', 'student_counters', 'classrooms',
            'curriculum_subjects', 'curriculums', 'grade_periods', 'subjects',
            'options', 'sections', 'academic_years', 'audit_logs',
        ] as $table) {
            db_query("DELETE FROM {$table} WHERE school_id = :school_id", ['school_id' => $schoolId]);
        }

        db_query('DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE school_id = :s)',
            ['s' => $schoolId], true);
        db_query('DELETE FROM users WHERE school_id = :s', ['s' => $schoolId], true);
        db_query('DELETE FROM school_cycles WHERE school_id = :s', ['s' => $schoolId], true);
        db_query('DELETE FROM schools WHERE id = :s', ['s' => $schoolId], true);
    }
}

echo "\n  ──────────────────────────────────────────────────\n";
printf("  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
