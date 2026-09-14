<?php
/**
 * Phase 4A — enseignants, affectations et périmètre pédagogique.
 *
 * Ce que ces tests protègent
 * --------------------------
 * L'affectation d'un enseignant à une branche est la pièce sur laquelle
 * s'appuiera toute l'autorisation pédagogique : saisie des notes, appel
 * des présences, consultation des élèves. Si elle peut être forgée —
 * une classe d'une autre école, une branche absente du programme, un
 * enseignant parti — alors toute la phase 4 repose sur du sable.
 *
 * Usage : php tests/teachers_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/teachers/services.php';

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
        'username'      => 'ens.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'ens' . $counter . '@example.test',
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

    auth_user(true);
    perm_all(true);
    perm_roles(true);

    return $userId;
}

/** École complète : année, référentiel, programme activé, deux classes. */
function build_school(string $code, string $name): array
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
        'code' => '2070-2071', 'name' => 'Test phase 4', 'starts_on' => '2070-09-01',
        'ends_on' => '2071-07-31', 'status' => 'active', 'is_current' => 1,
    ]);

    curriculum_service_import_national();
    curriculum_service_create_standard_periods($yearId);

    $levelId = (int) db_value("SELECT id FROM education_levels WHERE code = 'PRI_5'", [], true);
    $program = curriculum_service_create_program($yearId, $levelId, null, null);
    curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    $classA = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '5A', 'name' => '5ème primaire A', 'capacity' => 40,
    ]);

    $classB = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '5B', 'name' => '5ème primaire B', 'capacity' => 40,
    ]);

    $subjects = tenant_all(
        'curriculum_subjects',
        'curriculum_id = :c ORDER BY order_number LIMIT 3',
        ['c' => (int) $program['id']]
    );

    return [
        'school_id'   => $schoolId,
        'year_id'     => $yearId,
        'program_id'  => (int) $program['id'],
        'classroom_a' => $classA,
        'classroom_b' => $classB,
        'subjects'    => array_map(static fn (array $s): int => (int) $s['id'], $subjects),
    ];
}

echo "\n  ENSEIGNANTS, AFFECTATIONS ET PÉRIMÈTRE PÉDAGOGIQUE\n";
echo "  ──────────────────────────────────────────────────────────\n\n";

$createdSchools = [];

try {
    $a = build_school('ENS-A', 'École Alpha');
    $b = build_school('ENS-B', 'École Beta');
    $createdSchools = [$a['school_id'], $b['school_id']];

    // =================================================================
    //  FICHE ENSEIGNANT
    // =================================================================
    act_as($a['school_id'], 'DIRECTION');
    tenant_set($a['school_id']);

    $created = teachers_service_create([
        'last_name' => 'MULUMBA', 'post_name' => 'Kalala', 'first_name' => 'Pierre',
        'gender' => 'M', 'matricule' => 'ENS-001', 'specialty' => 'Mathématiques',
    ]);
    check('Création d\'une fiche enseignant', $created['ok'], $created['message']);

    $teacherA = (int) $created['id'];

    $duplicate = teachers_service_create([
        'last_name' => 'NGOY', 'first_name' => 'Jean', 'matricule' => 'ENS-001',
    ]);
    check(
        'Matricule déjà attribué : refusé',
        !$duplicate['ok'] && str_contains($duplicate['message'], 'déjà attribué'),
        $duplicate['message']
    );

    // =================================================================
    //  LISTE
    //
    //  Ces quatre contrôles existent parce qu'un défaut réel leur a
    //  échappé : la requête de liste sélectionnait une colonne d'une
    //  table qu'elle ne joignait pas. Les services étaient testés, pas
    //  l'écran qui les affiche. Un dépôt non exécuté est un dépôt non
    //  testé.
    // =================================================================
    $list = teachers_repo_search([]);
    check('La liste s\'exécute et renvoie l\'enseignant', $list['total'] === 1, $list['total'] . ' trouvé(s)');

    $listQ = teachers_repo_search(['q' => 'MULU']);
    check('Recherche par préfixe du nom', $listQ['total'] === 1, $listQ['total'] . ' trouvé(s)');

    $listWild = teachers_repo_search(['q' => '%']);
    check('Un « % » saisi ne ramène pas tout le fichier', $listWild['total'] === 0);

    $listStatus = teachers_repo_search(['status' => 'left']);
    check('Filtre par statut', $listStatus['total'] === 0, $listStatus['total'] . ' trouvé(s)');

    // =================================================================
    //  ISOLATION MULTI-ÉCOLE
    // =================================================================
    tenant_set($b['school_id']);

    $listB = teachers_repo_search([]);
    check('La liste de l\'école B est vide', $listB['total'] === 0, $listB['total'] . ' trouvé(s)');

    check('École B ne voit pas l\'enseignant de A', teachers_repo_find($teacherA) === null);

    tenant_set($a['school_id']);
    check('École A voit bien son enseignant', teachers_repo_find($teacherA) !== null);

    // Affecter l'enseignant de A à une classe de B.
    $crossClass = teachers_service_assign($teacherA, $b['classroom_a'], $a['subjects'][0]);
    check(
        'Classe d\'une AUTRE ÉCOLE refusée à l\'affectation',
        !$crossClass['ok'] && str_contains($crossClass['message'], 'introuvable'),
        $crossClass['message']
    );

    // Affecter une branche d'une autre école.
    $crossSubject = teachers_service_assign($teacherA, $a['classroom_a'], $b['subjects'][0]);
    check(
        'Branche d\'une AUTRE ÉCOLE refusée',
        !$crossSubject['ok'] && str_contains($crossSubject['message'], 'ne figure pas au programme'),
        $crossSubject['message']
    );

    // =================================================================
    //  AFFECTATION
    // =================================================================
    $assign = teachers_service_assign($teacherA, $a['classroom_a'], $a['subjects'][0], 6.0);
    check('Affectation d\'une branche', $assign['ok'], $assign['message']);

    $again = teachers_service_assign($teacherA, $a['classroom_a'], $a['subjects'][0]);
    check(
        'Deux fois la même branche au même enseignant : refusé',
        !$again['ok'] && str_contains($again['message'], 'assure déjà'),
        $again['message']
    );

    $other = teachers_service_create(['last_name' => 'KASONGO', 'first_name' => 'Alice']);
    $teacherB = (int) $other['id'];

    $taken = teachers_service_assign($teacherB, $a['classroom_a'], $a['subjects'][0]);
    check(
        'Branche déjà pourvue : le second enseignant est refusé',
        !$taken['ok'] && str_contains($taken['message'], 'déjà assurée'),
        $taken['message']
    );

    // Une branche d'un AUTRE programme de la MÊME école serait acceptée
    // si le contrôle ne portait que sur school_id.
    $foreignProgram = curriculum_service_create_program(
        $a['year_id'],
        (int) db_value("SELECT id FROM education_levels WHERE code = 'PRI_3'", [], true),
        null,
        null
    );
    curriculum_service_fill_program((int) $foreignProgram['id']);

    $foreignSubject = tenant_one(
        'curriculum_subjects',
        'curriculum_id = :c ORDER BY order_number',
        ['c' => (int) $foreignProgram['id']]
    );

    $wrongProgram = teachers_service_assign(
        $teacherA,
        $a['classroom_a'],
        (int) $foreignSubject['id']
    );
    check(
        'Branche d\'un AUTRE PROGRAMME de la même école : refusée',
        !$wrongProgram['ok'] && str_contains($wrongProgram['message'], 'ne figure pas au programme'),
        $wrongProgram['message']
    );

    // =================================================================
    //  ENSEIGNANT PARTI
    // =================================================================
    teachers_service_change_status($teacherB, 'left', 'Fin de contrat');

    $goneAssign = teachers_service_assign($teacherB, $a['classroom_b'], $a['subjects'][1]);
    check(
        'Enseignant parti : aucune nouvelle affectation',
        !$goneAssign['ok'] && str_contains($goneAssign['message'], 'pas en activité'),
        $goneAssign['message']
    );

    $goneMain = teachers_service_set_main_teacher($a['classroom_b'], $teacherB);
    check(
        'Enseignant parti : ne peut pas devenir titulaire',
        !$goneMain['ok'] && str_contains($goneMain['message'], 'pas en activité'),
        $goneMain['message']
    );

    teachers_service_change_status($teacherB, 'active');

    // =================================================================
    //  PÉRIMÈTRE DE L'ENSEIGNANT
    // =================================================================
    // Deux élèves : un en 5A (classe de l'enseignant), un en 5B.
    $eleveA = students_service_enroll_new(
        ['last_name' => 'KABILA', 'first_name' => 'Grace', 'gender' => 'F'],
        $a['year_id'],
        $a['classroom_a']
    );
    $eleveB = students_service_enroll_new(
        ['last_name' => 'MUKENDI', 'first_name' => 'Joseph', 'gender' => 'M'],
        $a['year_id'],
        $a['classroom_b']
    );
    check('Deux élèves inscrits dans deux classes', $eleveA['ok'] && $eleveB['ok']);

    // Compte de connexion pour l'enseignant.
    $teacherUserId = act_as($a['school_id'], 'ENSEIGNANT');
    tenant_set($a['school_id']);

    check(
        'L\'enseignant ne détient pas student.view.all',
        !perm_has('student.view.all')
    );

    // Sans rattachement, il ne voit rien.
    $before = students_repo_search([], $a['year_id']);
    check(
        'Compte non rattaché à une fiche : aucun élève',
        $before['total'] === 0,
        $before['total'] . ' trouvé(s)'
    );

    // Rattachement du compte à la fiche enseignant.
    tenant_update('teachers', ['user_id' => $teacherUserId], 'id = :id', ['id' => $teacherA]);

    $after = students_repo_search([], $a['year_id']);
    check(
        'Rattaché et affecté en 5A : il voit un seul élève',
        $after['total'] === 1,
        $after['total'] . ' trouvé(s)'
    );

    check(
        'L\'élève vu est bien celui de SA classe',
        $after['total'] === 1 && (int) $after['rows'][0]['id'] === (int) $eleveA['id']
    );

    check(
        'ACCÈS DIRECT à l\'élève d\'une autre classe : refusé',
        !students_can_view((int) $eleveB['id'])
    );

    check(
        'Accès direct à l\'élève de sa classe : autorisé',
        students_can_view((int) $eleveA['id'])
    );

    // La recherche ne doit pas élargir le périmètre.
    $search = students_repo_search(['q' => 'MUKENDI'], $a['year_id']);
    check(
        'La RECHERCHE ne contourne pas le périmètre enseignant',
        $search['total'] === 0,
        $search['total'] . ' trouvé(s)'
    );

    // =================================================================
    //  AUTORISATION DE SAISIE (socle de la phase 4B)
    // =================================================================
    check(
        'Il peut enseigner la branche qui lui est confiée',
        teachers_can_teach($a['classroom_a'], $a['subjects'][0])
    );

    check(
        'Il ne peut PAS enseigner une branche qu\'on ne lui a pas confiée',
        !teachers_can_teach($a['classroom_a'], $a['subjects'][1])
    );

    check(
        'Il ne peut PAS enseigner dans une classe qui n\'est pas la sienne',
        !teachers_can_teach($a['classroom_b'], $a['subjects'][0])
    );

    // Titulaire sans branche : il voit les élèves, il ne note pas.
    act_as($a['school_id'], 'DIRECTION');
    tenant_set($a['school_id']);
    teachers_service_set_main_teacher($a['classroom_b'], $teacherA);

    $_SESSION['user_id'] = $teacherUserId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);

    $asMain = students_repo_search([], $a['year_id']);
    check(
        'Titulaire d\'une seconde classe : il voit les deux élèves',
        $asMain['total'] === 2,
        $asMain['total'] . ' trouvé(s)'
    );

    check(
        'Titulaire mais sans la branche : la saisie reste refusée',
        !teachers_can_teach($a['classroom_b'], $a['subjects'][0])
    );

    // =================================================================
    //  LA CLASSE EST UNE PORTE, ELLE AUSSI
    //
    //  Ces contrôles existent parce qu'une faille réelle leur avait
    //  échappé : /eleves et /eleves/{id} étaient protégés, mais
    //  /classes/{id} affichait la liste nominative de N'IMPORTE QUELLE
    //  classe — matricule, nom, sexe, date de naissance — au moindre
    //  compte détenant « classroom.view », que le rôle ENSEIGNANT
    //  possède.
    //
    //  Une restriction d'accès ne vaut que si TOUTES les portes la
    //  portent.
    // =================================================================
    // Il avait été fait titulaire de 5B au test précédent : on lui
    // retire ce titre pour que 5B redevienne une classe étrangère.
    act_as($a['school_id'], 'DIRECTION');
    tenant_set($a['school_id']);
    teachers_service_set_main_teacher($a['classroom_b'], null);

    $_SESSION['user_id'] = $teacherUserId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($a['school_id']);

    check(
        'La classe qui lui est confiée est consultable',
        students_can_view_classroom($a['classroom_a'])
    );

    check(
        'La liste nominative d\'une AUTRE classe est vide',
        students_repo_classroom_students($a['classroom_b']) === [],
        count(students_repo_classroom_students($a['classroom_b'])) . ' élève(s)'
    );

    check(
        'L\'écran d\'une autre classe lui est refusé',
        !students_can_view_classroom($a['classroom_b'])
    );

    // Le personnel administratif, lui, voit tout.
    act_as($a['school_id'], 'SECRETARIAT');
    tenant_set($a['school_id']);

    check(
        'Le secrétariat voit la liste nominative de chaque classe',
        students_repo_classroom_students($a['classroom_b']) !== []
    );

    // =================================================================
    //  LE PÉRIMÈTRE EST BORNÉ À L'ANNÉE
    //
    //  Sans cette borne, les classes s'accumulent d'année en année et un
    //  professeur conserve à vie l'accès au dossier COURANT de tout
    //  élève qu'il a eu une seule fois.
    // =================================================================
    act_as($a['school_id'], 'DIRECTION');
    tenant_set($a['school_id']);

    $nextYear = tenant_insert('academic_years', [
        'code' => '2071-2072', 'name' => 'Année suivante', 'starts_on' => '2071-09-01',
        'ends_on' => '2072-07-31', 'status' => 'active',
    ]);

    $nextProgram = curriculum_service_create_program(
        $nextYear,
        (int) db_value("SELECT id FROM education_levels WHERE code = 'PRI_6'", [], true),
        null,
        null
    );
    curriculum_service_fill_program((int) $nextProgram['id']);
    curriculum_service_activate((int) $nextProgram['id']);

    $nextClassroom = tenant_insert('classrooms', [
        'academic_year_id' => $nextYear, 'curriculum_id' => (int) $nextProgram['id'],
        'code' => '6A', 'name' => '6ème primaire A', 'capacity' => 40,
    ]);

    // Le MÊME élève est réinscrit l'année suivante, dans une classe que
    // l'enseignant n'assure pas.
    $reEnroll = students_service_re_enroll((int) $eleveA['id'], $nextYear, $nextClassroom);
    check('L\'élève est réinscrit l\'année suivante', $reEnroll['ok'], $reEnroll['message']);

    $_SESSION['user_id'] = $teacherUserId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($a['school_id']);

    check(
        'Son périmètre de l\'année passée reste intact',
        students_repo_search([], $a['year_id'])['total'] > 0
    );

    check(
        'Il ne voit AUCUN élève dans l\'année où il n\'enseigne pas',
        students_repo_search([], $nextYear)['total'] === 0,
        students_repo_search([], $nextYear)['total'] . ' trouvé(s)'
    );

    check(
        'Son ancien élève ne lui est plus accessible sur l\'année en cours',
        !students_can_view((int) $eleveA['id'], $nextYear)
    );

    // =================================================================
    //  ANNÉE CLÔTURÉE
    // =================================================================
    act_as($a['school_id'], 'DIRECTION');
    tenant_set($a['school_id']);

    tenant_update('academic_years', ['status' => 'closed'], 'id = :id', ['id' => $a['year_id']]);

    $closed = teachers_service_assign($teacherB, $a['classroom_b'], $a['subjects'][1]);
    check(
        'Année clôturée : plus aucune affectation',
        !$closed['ok'] && str_contains($closed['message'], 'clôturée'),
        $closed['message']
    );

    $closedRemove = teachers_service_unassign($a['classroom_a'], (int) $assign['id']);
    check(
        'Année clôturée : plus aucun retrait',
        !$closedRemove['ok'] && str_contains($closedRemove['message'], 'clôturée'),
        $closedRemove['message']
    );

    // Masquer le formulaire ne protège rien : l'envoi direct doit être
    // refusé par le service.
    $closedMain = teachers_service_set_main_teacher($a['classroom_a'], $teacherA);
    check(
        'Année clôturée : le titulaire ne peut plus être changé',
        !$closedMain['ok'] && str_contains($closedMain['message'], 'clôturée'),
        $closedMain['message']
    );

    tenant_update('academic_years', ['status' => 'active'], 'id = :id', ['id' => $a['year_id']]);

    // =================================================================
    //  RETRAIT D'AFFECTATION : LA CLASSE DE L'URL DOIT CORRESPONDRE
    // =================================================================
    $wrongClassroom = teachers_service_unassign($a['classroom_b'], (int) $assign['id']);
    check(
        'Retrait depuis une AUTRE classe que la sienne : refusé',
        !$wrongClassroom['ok'] && str_contains($wrongClassroom['message'], 'pour cette classe'),
        $wrongClassroom['message']
    );

    // =================================================================
    //  LE MOTIF DE STATUT N'ÉCRASE PAS LES NOTES DE LA FICHE
    // =================================================================
    teachers_service_update($teacherA, ['notes' => 'Responsable du club de mathématiques']);
    teachers_service_change_status($teacherA, 'suspended', 'Procédure disciplinaire');

    $afterStatus = teachers_repo_find($teacherA);
    check(
        'Le motif de suspension n\'écrase pas les notes de la fiche',
        $afterStatus['notes'] === 'Responsable du club de mathématiques',
        (string) $afterStatus['notes']
    );

    check(
        'Le motif est conservé dans sa propre colonne',
        $afterStatus['status_reason'] === 'Procédure disciplinaire',
        (string) $afterStatus['status_reason']
    );

    teachers_service_change_status($teacherA, 'active');

    // =================================================================
    //  COMPTE DE CONNEXION
    // =================================================================
    $sharedAccount = teachers_service_update($teacherB, ['user_id' => $teacherUserId]);
    check(
        'Un compte ne peut pas être rattaché à deux enseignants',
        !$sharedAccount['ok'] && str_contains($sharedAccount['message'], 'déjà rattaché'),
        $sharedAccount['message']
    );

    // Un compte d'une autre école.
    $foreignUser = db_insert('users', [
        'uuid' => str_uuid(), 'school_id' => $b['school_id'], 'username' => 'etranger',
        'email' => 'etranger@example.test',
        'password_hash' => password_hash('MotDePasse2026', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name' => 'ETRANGER', 'first_name' => 'Compte', 'status' => 'active',
    ], true);

    $crossUser = teachers_service_update($teacherB, ['user_id' => $foreignUser]);
    check(
        'Compte d\'une AUTRE ÉCOLE refusé',
        !$crossUser['ok'] && str_contains($crossUser['message'], 'n\'appartient pas'),
        $crossUser['message']
    );

    // =================================================================
    //  SEUIL DE RÉUSSITE
    // =================================================================
    check(
        'Seuil de réussite par défaut : 50 %',
        abs(teachers_passing_threshold() - 50.0) < 0.001,
        (string) teachers_passing_threshold()
    );

    school_setting_set('grading.passing_threshold', 60);
    check(
        'Seuil paramétrable par école',
        abs(teachers_passing_threshold() - 60.0) < 0.001,
        (string) teachers_passing_threshold()
    );

    school_setting_set('grading.passing_threshold', 0);
    check(
        'Seuil absurde ignoré : retour à 50 %',
        abs(teachers_passing_threshold() - 50.0) < 0.001,
        (string) teachers_passing_threshold()
    );

    school_setting_set('grading.passing_threshold', PASSING_THRESHOLD_DEFAULT);

    // =================================================================
    //  GARDE-FOU MULTI-ÉCOLE
    // =================================================================
    foreach (['teachers', 'teacher_subjects'] as $table) {
        $blocked = false;

        try {
            db_query("SELECT * FROM {$table} LIMIT 1");
        } catch (Throwable) {
            $blocked = true;
        }

        check("Garde-fou actif sur « {$table} »", $blocked);
    }
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($createdSchools as $schoolId) {
        tenant_set($schoolId);

        db_query('UPDATE classrooms SET main_teacher_id = NULL WHERE school_id = :s', ['s' => $schoolId]);

        foreach ([
            'student_history', 'orientations', 'student_guardians', 'enrollments',
            'guardians', 'students', 'student_counters',
            'teacher_subjects', 'teachers',
            'classrooms', 'curriculum_subjects', 'curriculums', 'grade_periods',
            'subjects', 'options', 'sections', 'academic_years', 'audit_logs',
        ] as $table) {
            db_query("DELETE FROM {$table} WHERE school_id = :school_id", ['school_id' => $schoolId]);
        }

        db_query('DELETE FROM school_settings WHERE school_id = :s', ['s' => $schoolId]);
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
