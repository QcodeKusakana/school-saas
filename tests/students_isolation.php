<?php
/**
 * Phase 3 — élèves, classes et parcours : isolation et règles métier.
 *
 * Usage : php tests/students_isolation.php
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

/**
 * Ouvre une session de test au nom d'un utilisateur portant un rôle système.
 *
 * Les tests s'exécutaient jusqu'ici sans aucun utilisateur connecté :
 * perm_all() renvoyait un tableau vide et la couche de permissions
 * n'était donc jamais réellement traversée. Tout contrôle ajouté dans
 * les services passait inaperçu.
 *
 * @return int Identifiant de l'utilisateur créé.
 */
function act_as(int $schoolId, string $roleCode): int
{
    static $counter = 0;
    $counter++;

    $userId = db_insert('users', [
        'uuid'          => str_uuid(),
        'school_id'     => $schoolId,
        'username'      => 'test.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'test' . $counter . '@example.test',
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

    $_SESSION['user_id']  = $userId;
    $_SESSION['school_id'] = $schoolId;

    // Les caches statiques sont remplis une fois par requête : sans
    // rafraîchissement, le test conserverait les droits du rôle précédent.
    // auth_user() met son résultat en cache statique : sans
    // rafraîchissement, auth_id() renverrait l'utilisateur précédent et
    // le périmètre serait calculé pour la mauvaise personne.
    auth_user(true);
    perm_all(true);
    perm_roles(true);

    return $userId;
}

/** Referme la session de test. */
function act_as_nobody(): void
{
    unset($_SESSION['user_id'], $_SESSION['school_id']);
    auth_user(true);
    perm_all(true);
    perm_roles(true);
}

/** Prépare une école complète : cycles, année, référentiel, programme, classe. */
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
        'code' => '2050-2051', 'name' => 'Test', 'starts_on' => '2050-09-01',
        'ends_on' => '2051-07-31', 'status' => 'active', 'is_current' => 1,
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
        'capacity'         => 3, // volontairement petit, pour tester la saturation
    ]);

    return ['school_id' => $schoolId, 'year_id' => $yearId, 'classroom_id' => $classroomId];
}

echo "\n  ÉLÈVES, CLASSES ET PARCOURS — ISOLATION ET RÈGLES MÉTIER\n";
echo "  ────────────────────────────────────────────────────────────\n";

db_query("DELETE FROM schools WHERE code LIKE 'STU-%'", [], true);

$a = build_school('STU-A', 'École Alpha');
$b = build_school('STU-B', 'École Beta');

// =====================================================================
//  MATRICULE
// =====================================================================
tenant_set($a['school_id']);

$first = students_service_enroll_new(
    ['last_name' => 'KABILA', 'post_name' => 'Mwamba', 'first_name' => 'Jean', 'gender' => 'M'],
    $a['year_id'],
    $a['classroom_id']
);
check('Inscription d\'un premier élève', $first['ok'], $first['matricule'] ?? $first['message']);

$second = students_service_enroll_new(
    ['last_name' => 'MUKENDI', 'first_name' => 'Marie', 'gender' => 'F'],
    $a['year_id'],
    $a['classroom_id']
);
check(
    'Matricules strictement séquentiels',
    $first['matricule'] !== $second['matricule'],
    "{$first['matricule']} puis {$second['matricule']}"
);

check(
    'Format par défaut respecté (AA-NNNN)',
    (bool) preg_match('/^\d{2}-\d{4}$/', (string) $first['matricule']),
    (string) $first['matricule']
);

// Chaque école a sa propre séquence : les deux peuvent porter 50-0001.
tenant_set($b['school_id']);
$firstB = students_service_enroll_new(
    ['last_name' => 'ILUNGA', 'first_name' => 'Paul', 'gender' => 'M'],
    $b['year_id'],
    $b['classroom_id']
);
check(
    'Les séquences de matricules sont propres à chaque école',
    $firstB['matricule'] === $first['matricule'],
    "A={$first['matricule']}, B={$firstB['matricule']}"
);

// Format configurable
tenant_set($a['school_id']);
school_setting_set('student.matricule_format', '{SCHOOL}/{YYYY}/{SEQ:5}');
$custom = students_service_enroll_new(
    ['last_name' => 'TSHALA', 'first_name' => 'Grace', 'gender' => 'F'],
    $a['year_id'],
    null
);
check(
    'Format de matricule configurable par école',
    str_starts_with((string) $custom['matricule'], 'STU-A/2050/'),
    (string) $custom['matricule']
);
school_setting_set('student.matricule_format', STUDENT_MATRICULE_DEFAULT_FORMAT);

// =====================================================================
//  ISOLATION
// =====================================================================
$studentA = (int) $first['id'];
tenant_set($b['school_id']);
check('École B ne voit pas l\'élève de l\'école A', students_repo_find($studentA) === null);

tenant_set($a['school_id']);
check('École A voit bien son élève', students_repo_find($studentA) !== null);

// Affecter un élève de A à une classe de B.
// On passe par l'affectation et non par la réinscription : l'élève est
// déjà inscrit sur cette année, le contrôle de doublon interviendrait
// en premier et le test validerait pour la mauvaise raison.
$enrollmentA = students_repo_enrollment($studentA, $a['year_id']);
$crossClassroom = students_service_assign_classroom(
    $studentA,
    (int) $enrollmentA['id'],
    $b['classroom_id']
);
check(
    'Classe d\'une AUTRE ÉCOLE refusée à l\'affectation',
    !$crossClassroom['ok'] && str_contains($crossClassroom['message'], 'n\'appartient pas'),
    $crossClassroom['message']
);

// Inscrire dans une année scolaire d'une autre école
$crossYear = students_service_re_enroll($studentA, $b['year_id'], null);
check(
    'Année scolaire d\'une AUTRE ÉCOLE refusée',
    !$crossYear['ok'] && str_contains($crossYear['message'], 'introuvable')
);

// =====================================================================
//  RÈGLES D'INSCRIPTION
// =====================================================================
$duplicate = students_service_re_enroll($studentA, $a['year_id'], null);
check(
    'Double inscription sur la même année refusée',
    !$duplicate['ok'] && str_contains($duplicate['message'], 'déjà inscrit')
);

// La classe a une capacité de 3 : 2 élèves y sont déjà.
$third = students_service_enroll_new(
    ['last_name' => 'NGOY', 'first_name' => 'Alain', 'gender' => 'M'],
    $a['year_id'],
    $a['classroom_id']
);
check('Troisième élève accepté (capacité 3)', $third['ok']);

$overflow = students_service_enroll_new(
    ['last_name' => 'LUMU', 'first_name' => 'Sarah', 'gender' => 'F'],
    $a['year_id'],
    $a['classroom_id']
);
check(
    'Quatrième élève refusé : classe complète',
    !$overflow['ok'] && str_contains($overflow['message'], 'complète'),
    $overflow['message']
);

// Année clôturée
$closedYearId = tenant_insert('academic_years', [
    'code' => '2049-2050', 'name' => 'Close', 'starts_on' => '2049-09-01',
    'ends_on' => '2050-07-31', 'status' => 'closed',
]);
$closed = students_service_re_enroll($studentA, $closedYearId, null);
check(
    'Inscription dans une année clôturée refusée',
    !$closed['ok'] && str_contains($closed['message'], 'clôturée')
);

// =====================================================================
//  TUTEURS
// =====================================================================
$guardian = students_service_attach_guardian($studentA, [
    'last_name' => 'KABILA', 'first_name' => 'Joseph',
    'phone' => '0810000001', 'relationship' => 'pere',
]);
check('Tuteur créé et rattaché', $guardian['ok'] && $guardian['created']);

$studentB = (int) $second['id'];
$sibling  = students_service_attach_guardian($studentB, [
    'last_name' => 'KABILA', 'first_name' => 'Joseph',
    'phone' => '0810000001', 'relationship' => 'pere',
]);
check(
    'Même téléphone : la fiche tuteur est réutilisée, pas dupliquée',
    $sibling['ok'] && !$sibling['created'] && $sibling['guardian_id'] === $guardian['guardian_id']
);

$again = students_service_attach_guardian($studentA, [
    'last_name' => 'KABILA', 'first_name' => 'Joseph',
    'phone' => '0810000001', 'relationship' => 'pere',
]);
check('Double rattachement au même élève refusé', !$again['ok']);

$guardians = students_repo_guardians($studentA);
check(
    'Le premier tuteur devient contact principal',
    $guardians !== [] && (int) $guardians[0]['is_primary'] === 1
);

// =====================================================================
//  ORIENTATION
// =====================================================================
$sectionSci = tenant_one('sections', 'code = :c', ['c' => 'SCIENTIFIQUE']);
$optionMath = tenant_one('options', 'code = :c', ['c' => 'MATH_PHYS']);
$optionLit  = tenant_one('options', 'code = :c', ['c' => 'LATIN_PHILO']);

$mismatch = students_service_orient(
    $studentA, $a['year_id'], (int) $sectionSci['id'], (int) $optionLit['id']
);
check(
    'Option étrangère à la section : orientation refusée',
    !$mismatch['ok'] && str_contains($mismatch['message'], 'ne relève pas')
);

$orientation = students_service_orient(
    $studentA, $a['year_id'], (int) $sectionSci['id'], (int) $optionMath['id'], 72.5
);
check('Orientation enregistrée', $orientation['ok'], $orientation['message']);

$twice = students_service_orient(
    $studentA, $a['year_id'], (int) $sectionSci['id'], (int) $optionMath['id']
);
check('Orientation en double sur la même année refusée', !$twice['ok']);

$unassigned = (int) $custom['id']; // élève sans classe
$noLevel = students_service_orient(
    $unassigned, $a['year_id'], (int) $sectionSci['id'], (int) $optionMath['id']
);
check(
    'Orientation impossible sans classe : le niveau ne peut pas être déduit',
    !$noLevel['ok'] && str_contains($noLevel['message'], 'aucune classe')
);

// =====================================================================
//  PARCOURS ET STATUT
// =====================================================================
$history = students_repo_history($studentA);
$types   = array_column($history, 'event_type');
check(
    'Le parcours enregistre inscription et orientation',
    in_array('enrollment', $types, true) && in_array('orientation', $types, true),
    implode(', ', array_unique($types))
);

// Séparation des devoirs : clôturer un dossier n'est pas le modifier.
act_as($a['school_id'], 'SECRETARIAT');
tenant_set($a['school_id']);

$refused = students_service_change_status($studentA, 'archived', 'Tentative');
check(
    'Le secrétariat ne peut pas archiver un dossier',
    !$refused['ok'] && str_contains($refused['message'], 'droit'),
    $refused['message']
);

act_as($a['school_id'], 'DIRECTION');
tenant_set($a['school_id']);

$graduated = students_service_change_status($studentA, 'graduated', 'Fin du cycle secondaire');
check('La direction clôture le dossier : fin des études', $graduated['ok'], $graduated['message']);

$student = students_repo_find($studentA);
check('Le dossier est conservé, pas supprimé', $student !== null && $student['status'] === 'graduated');

$blocked = students_service_re_enroll($studentA, $closedYearId, null);
check('Un dossier non actif ne peut pas être réinscrit', !$blocked['ok']);

// =====================================================================
//  GARDE-FOU
// =====================================================================
foreach (['students', 'enrollments', 'guardians', 'student_guardians',
          'classrooms', 'orientations', 'student_history', 'student_counters', 'rooms'] as $table) {
    $guarded = false;

    try {
        db_all('SELECT * FROM ' . $table . ' LIMIT 1');
    } catch (RuntimeException $e) {
        $guarded = str_contains($e->getMessage(), 'sans filtre school_id');
    }

    check("Garde-fou actif sur « {$table} »", $guarded);
}

// --- Nettoyage ---------------------------------------------------------
// Suppression ordonnée : plusieurs clés étrangères sont volontairement
// en RESTRICT (on ne supprime pas un programme utilisé par une classe,
// ni une branche utilisée par un programme). MySQL n'ordonne pas les
// cascades entre elles, il faut donc descendre l'arbre nous-mêmes.
// En production, une école n'est jamais supprimée : elle est suspendue.
foreach (db_all("SELECT id FROM schools WHERE code LIKE 'STU-%'", [], true) as $row) {
    $id = (int) $row['id'];

    foreach ([
        'student_history', 'orientations', 'enrollments', 'student_guardians',
        'guardians', 'students', 'student_counters',
        'classrooms', 'rooms',
        'curriculum_subjects', 'curriculums', 'grade_periods',
        'subjects', 'options', 'sections',
        'academic_years', 'school_cycles', 'school_settings',
    ] as $table) {
        db_query('DELETE FROM ' . $table . ' WHERE school_id = :id', ['id' => $id]);
    }

    db_query('DELETE FROM schools WHERE id = :id', ['id' => $id], true);
}

echo "\n  ────────────────────────────────────────────────────────────\n";
printf("  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
