<?php
/**
 * Phase 6A — le portail des familles.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · le portail se fonde sur le LIEN DE TUTELLE, jamais sur un rôle :
 *    aucune permission mal accordée ne peut ouvrir l'espace d'un autre ;
 *  · un enseignant, même titulaire de la classe, n'entre pas dans
 *    l'espace familial d'un de ses élèves ;
 *  · un tuteur ne voit QUE ses pupilles, y compris par accès direct ;
 *  · aucune école ne voit le portail d'une autre ;
 *  · le détail d'un bulletin publié est FIGÉ : une cote corrigée ou une
 *    branche renommée après coup ne modifient pas le document remis ;
 *  · republier REMPLACE le document, il ne l'empile pas ;
 *  · le rattachement d'un compte tuteur impose le changement de mot de
 *    passe et refuse un tuteur sans pupille.
 *
 * Usage : php tests/portal_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/teachers/services.php';
require APP_PATH . '/modules/grades/services.php';
require APP_PATH . '/modules/bulletins/services.php';
require APP_PATH . '/modules/portal/services.php';
require_once APP_PATH . '/modules/bulletins/repositories.php';
require_once APP_PATH . '/modules/students/repositories.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

function act_as(int $schoolId, string $roleCode): int
{
    static $counter = 0;
    $counter++;

    $userId = db_insert('users', [
        'uuid'          => str_uuid(),
        'school_id'     => $schoolId,
        'username'      => 'portal.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'portal' . $counter . '@example.test',
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

/**
 * Ménage complet d'une école.
 *
 * L'ordre est imposé : `DELETE FROM schools` seul ÉCHOUE dès qu'une cote
 * existe, `grades.curriculum_subject_id` étant en RESTRICT. C'est le
 * signe que la procédure d'effacement d'une école reste à écrire — elle
 * est consignée comme dette dans claude/etat-du-projet.md.
 */
function purge_school(int $schoolId): void
{
    foreach ([
        'bulletin_lines', 'bulletins', 'attendance_records', 'attendance_sessions',
        'grades', 'payment_allocations', 'payments', 'student_fees', 'fees',
        'receipt_counters', 'expenses', 'expense_counters',
        'student_history', 'orientations', 'student_guardians', 'enrollments',
        'guardians', 'students', 'student_counters',
        'teacher_subjects', 'teachers', 'classrooms',
        'curriculum_subjects', 'curriculums', 'grade_periods',
    ] as $table) {
        db_query("DELETE FROM {$table} WHERE school_id = :s", ['s' => $schoolId], true);
    }

    db_query('DELETE FROM schools WHERE id = :id', ['id' => $schoolId], true);
    db_query('DELETE FROM users WHERE school_id = :id', ['id' => $schoolId], true);
}

$createdSchools = [];

try {
    // =================================================================
    //  MONTAGE
    // =================================================================
    $schoolId = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'PORT-A', 'slug' => 'port-a',
        'name' => 'École du Portail', 'status' => 'active',
    ], true);
    $createdSchools[] = $schoolId;

    db_query(
        'INSERT INTO school_cycles (school_id, cycle_id, is_active)
         SELECT :s, id, 1 FROM education_cycles',
        ['s' => $schoolId]
    );

    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);
    school_settings_all(true);

    $yearId = tenant_insert('academic_years', [
        'code' => 'PT-' . date('Y'), 'name' => 'Test portail',
        'starts_on' => date('Y-m-d', strtotime('-30 days')),
        'ends_on' => date('Y-m-d', strtotime('+300 days')),
        'status' => 'active', 'is_current' => 1,
    ]);

    curriculum_service_import_national();
    curriculum_service_create_standard_periods($yearId);

    $levelId = (int) db_value("SELECT id FROM education_levels WHERE code = 'CTEB_7'", [], true);
    $program = curriculum_service_create_program($yearId, $levelId, null, null);
    curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    $classId = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '7A', 'name' => '7ème A', 'capacity' => 40,
    ]);

    $mine = students_service_enroll_new(
        ['last_name' => 'MPOYI', 'first_name' => 'Grace', 'gender' => 'F'],
        $yearId,
        $classId
    );

    $other = students_service_enroll_new(
        ['last_name' => 'KALALA', 'first_name' => 'Didier', 'gender' => 'M'],
        $yearId,
        $classId
    );

    $mineId  = (int) $mine['id'];
    $otherId = (int) $other['id'];

    $mineEnrollment = (int) students_repo_enrollment($mineId, $yearId)['id'];

    // =================================================================
    //  LE LIEN DE TUTELLE EST LA SEULE CLÉ
    // =================================================================
    echo "\n  LE PORTAIL REPOSE SUR LA TUTELLE, PAS SUR UN RÔLE\n";

    $guardianId = tenant_insert('guardians', [
        'uuid' => str_uuid(), 'last_name' => 'MPOYI', 'first_name' => 'Albert',
        'phone' => '+243900000777',
    ]);

    db_query(
        'INSERT INTO student_guardians (school_id, student_id, guardian_id, relationship, is_primary)
         VALUES (:s, :st, :g, \'pere\', 1)',
        ['s' => $schoolId, 'st' => $mineId, 'g' => $guardianId]
    );

    // Le tuteur n'a pas encore de compte : personne ne voit rien.
    check(
        'Un tuteur sans compte de connexion n\'ouvre aucun espace',
        portal_children() === []
    );

    // ----- LE RATTACHEMENT D'UN COMPTE ------------------------------
    $orphan = tenant_insert('guardians', [
        'uuid' => str_uuid(), 'last_name' => 'SANS', 'first_name' => 'Pupille',
        'phone' => '+243900000778',
    ]);

    $refus = portal_service_create_guardian_access($orphan);

    check(
        'Un tuteur rattaché à AUCUN élève ne reçoit pas d\'accès',
        !$refus['ok'],
        $refus['message']
    );

    $access = portal_service_create_guardian_access($guardianId);

    check('L\'accès se crée pour un tuteur rattaché', $access['ok'], $access['message']);

    $parentUserId = (int) db_value(
        'SELECT user_id FROM guardians WHERE id = :id AND school_id = :s',
        ['id' => $guardianId, 's' => $schoolId]
    );

    check('La fiche tuteur porte désormais son compte', $parentUserId > 0);

    $account = db_one('SELECT * FROM users WHERE id = :id AND school_id = :s',
        ['id' => $parentUserId, 's' => $schoolId], true);

    check(
        'Le changement de mot de passe est imposé à la première connexion',
        (int) $account['must_change_password'] === 1
    );

    check(
        'Le mot de passe initial n\'est jamais stocké en clair',
        !str_contains((string) $account['password_hash'], (string) $access['password'])
            && password_verify((string) $access['password'], (string) $account['password_hash'])
    );

    check(
        'Le mot de passe n\'apparaît pas au journal d\'audit',
        !platform_scope_cli(static fn (): bool => db_exists(
                'SELECT 1 FROM audit_logs
                  WHERE action = :a AND entity_id = :id AND new_values LIKE :pwd',
                ['a' => 'guardian.access', 'id' => $guardianId, 'pwd' => '%' . $access['password'] . '%'],
                true
            ))
    );

    $again = portal_service_create_guardian_access($guardianId);

    check('Un second accès sur la même fiche est refusé', !$again['ok'], $again['message']);

    check(
        'Le compte reçoit bien le rôle PARENT',
        db_exists(
            'SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :u AND r.code = \'PARENT\' AND r.school_id IS NULL',
            ['u' => $parentUserId],
            true
        )
    );

    // =================================================================
    //  LE PÉRIMÈTRE DU PORTAIL
    // =================================================================
    echo "\n  UN TUTEUR NE VOIT QUE SES PUPILLES\n";

    $_SESSION['user_id']   = $parentUserId;
    $_SESSION['school_id'] = $schoolId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);

    $children = portal_children();

    check('Le tuteur voit son enfant', count($children) === 1
        && (int) $children[0]['id'] === $mineId);

    check('Il est reconnu comme tuteur de son enfant', portal_is_guardian_of($mineId));
    check('Il n\'est PAS reconnu tuteur de l\'autre élève', !portal_is_guardian_of($otherId));
    check('Le menu lui propose l\'espace', portal_has_children());

    // =================================================================
    //  L'ENSEIGNANT N'ENTRE PAS DANS L'ESPACE FAMILIAL
    // =================================================================
    echo "\n  L'ENSEIGNANT A UN PÉRIMÈTRE, PAS UNE TUTELLE\n";

    act_as($schoolId, 'DIRECTION');

    $teacher = teachers_service_create([
        'last_name' => 'MUKENDI', 'first_name' => 'Joseph', 'gender' => 'M',
        'phone' => '+243900000779', 'hired_on' => date('Y-m-d'),
    ]);

    tenant_update(
        'classrooms',
        ['main_teacher_id' => (int) $teacher['id']],
        'id = :id',
        ['id' => $classId]
    );

    $teacherUserId = act_as($schoolId, 'ENSEIGNANT');

    tenant_update(
        'teachers',
        ['user_id' => $teacherUserId],
        'id = :id',
        ['id' => (int) $teacher['id']]
    );

    auth_user(true);
    perm_all(true);
    perm_roles(true);

    // Le titulaire VOIT l'élève dans la scolarité…
    check(
        'Le titulaire voit bien son élève côté scolarité',
        students_can_view($mineId, $yearId)
    );

    // …mais n'entre PAS dans son espace familial.
    check(
        'Mais il n\'est PAS tuteur : le portail lui est fermé',
        !portal_is_guardian_of($mineId)
    );

    check('Et son espace est vide', portal_children() === []);
    check('Le menu ne lui propose pas l\'espace', !portal_has_children());

    // =================================================================
    //  LE BULLETIN PUBLIÉ EST UNE PIÈCE
    // =================================================================
    echo "\n  LE DÉTAIL D'UN BULLETIN PUBLIÉ EST FIGÉ\n";

    act_as($schoolId, 'DIRECTION');

    $subject = db_one(
        'SELECT cs.id FROM curriculum_subjects cs
          WHERE cs.school_id = :s AND cs.curriculum_id = :c
          ORDER BY cs.id LIMIT 1',
        ['s' => $schoolId, 'c' => (int) $program['id']],
        true
    );

    $groups = bulletins_classroom_groups($classId);
    $target = null;
    $codes  = [];

    foreach ($groups as $key => $g) {
        if ($key !== 'ANNUAL' && $g['periods'] !== []) {
            $target = $key;
            $codes  = $g['periods'];
            break;
        }
    }

    $cycleId = curriculum_classroom_cycle_id($classId);

    $period = db_one(
        'SELECT id, code FROM grade_periods
          WHERE school_id = :s AND academic_year_id = :y AND code = :c
            AND (cycle_id IS NULL OR cycle_id = :cy) LIMIT 1',
        ['s' => $schoolId, 'y' => $yearId, 'c' => (string) $codes[0], 'cy' => $cycleId]
    );

    grades_service_save_sheet($classId, (int) $subject['id'], (int) $period['id'], [
        $mineEnrollment => ['points' => '15', 'is_absent' => false],
    ]);

    $publication = bulletins_service_publish($classId, (string) $target);

    check('La publication réussit', $publication['ok'], $publication['message']);

    $bulletinId = (int) db_value(
        'SELECT id FROM bulletins WHERE school_id = :s AND enrollment_id = :e AND period_key = :k',
        ['s' => $schoolId, 'e' => $mineEnrollment, 'k' => (string) $target]
    );

    $lineCount = (int) db_value(
        'SELECT COUNT(*) FROM bulletin_lines WHERE school_id = :s AND bulletin_id = :b',
        ['s' => $schoolId, 'b' => $bulletinId]
    );

    check('Le détail est archivé à la publication', $lineCount > 0, $lineCount . ' ligne(s)');

    // L'ÉPREUVE : on corrige la cote et on renomme la branche APRÈS.
    grades_service_save_sheet($classId, (int) $subject['id'], (int) $period['id'], [
        $mineEnrollment => ['points' => '3', 'is_absent' => false],
    ]);

    $originalName = (string) db_value(
        'SELECT s.name FROM subjects s
           JOIN curriculum_subjects cs ON cs.subject_id = s.id AND cs.school_id = s.school_id
          WHERE s.school_id = :s AND cs.id = :cs',
        ['s' => $schoolId, 'cs' => (int) $subject['id']],
        true
    );

    db_query(
        'UPDATE subjects SET name = :n
          WHERE school_id = :s
            AND id = (SELECT subject_id FROM curriculum_subjects WHERE id = :cs)',
        ['n' => 'BRANCHE RENOMMEE APRES COUP', 's' => $schoolId, 'cs' => (int) $subject['id']],
        true
    );

    $frozen = db_one(
        'SELECT subject_name, points FROM bulletin_lines
          WHERE school_id = :s AND bulletin_id = :b AND curriculum_subject_id = :cs
            AND period_code = :p',
        [
            's'  => $schoolId, 'b' => $bulletinId,
            'cs' => (int) $subject['id'], 'p' => (string) $period['code'],
        ]
    );

    check(
        'Une cote corrigée après coup ne modifie pas le document remis',
        abs((float) $frozen['points'] - 15.0) < 0.001,
        $frozen['points']
    );

    check(
        'Une branche renommée après coup non plus',
        (string) $frozen['subject_name'] !== 'BRANCHE RENOMMEE APRES COUP',
        (string) $frozen['subject_name']
    );

    // Republier REMPLACE le document.
    bulletins_service_publish($classId, (string) $target);

    $afterCount = (int) db_value(
        'SELECT COUNT(*) FROM bulletin_lines WHERE school_id = :s AND bulletin_id = :b',
        ['s' => $schoolId, 'b' => $bulletinId]
    );

    check('Republier REMPLACE les lignes, ne les empile pas', $afterCount === $lineCount);

    $renewed = db_one(
        'SELECT points FROM bulletin_lines
          WHERE school_id = :s AND bulletin_id = :b AND curriculum_subject_id = :cs
            AND period_code = :p',
        [
            's'  => $schoolId, 'b' => $bulletinId,
            'cs' => (int) $subject['id'], 'p' => (string) $period['code'],
        ]
    );

    check(
        'Et met bien à jour la nouvelle pièce',
        abs((float) $renewed['points'] - 3.0) < 0.001,
        $renewed['points']
    );

    db_query(
        'UPDATE subjects SET name = :n WHERE school_id = :s AND name = :old',
        ['n' => $originalName, 's' => $schoolId, 'old' => 'BRANCHE RENOMMEE APRES COUP'],
        true
    );

    // Le relevé figé garde la forme d'un relevé calculé.
    $report = bulletins_repo_lines($bulletinId);

    check(
        'Le relevé figé a la même forme qu\'un relevé calculé',
        isset($report['periods'], $report['subjects']) && $report['subjects'] !== []
    );

    $published = bulletins_repo_published($bulletinId);

    check(
        'Le bulletin publié se relit avec son élève et sa classe',
        $published !== null && (int) $published['student_id'] === $mineId
    );

    // =================================================================
    //  AUDIT 6A — CE QUE LA SONDE A TROUVÉ
    // =================================================================
    echo "\n  AUDIT 6A — LE LIEN SE COUPE, ET L'ÉCRAN NE TRONQUE PAS EN SILENCE\n";

    // ----- UN ÉCRAN QUI TRONQUE DOIT LE DIRE -------------------------
    //
    // Le plafond de la liste d'absences était de 30, silencieusement.
    // Un élève à 45 absences affichait 45 au bandeau et 30 lignes en
    // dessous : le parent comptait les lignes et concluait que le
    // décompte était faux.
    $classOfMine = (int) db_value(
        'SELECT classroom_id FROM enrollments WHERE id = :e AND school_id = :s',
        ['e' => $mineEnrollment, 's' => $schoolId]
    );

    for ($d = 1; $d <= 45; $d++) {
        $sessionId = tenant_insert('attendance_sessions', [
            'classroom_id' => $classOfMine,
            'session_date' => date('Y-m-d', strtotime("-{$d} days")),
            'slot'         => 'day',
        ]);

        db_query(
            'INSERT INTO attendance_records (school_id, session_id, enrollment_id, status)
             VALUES (:s, :se, :e, \'absent\')',
            ['s' => $schoolId, 'se' => $sessionId, 'e' => $mineEnrollment]
        );
    }

    $absenceCounts = portal_absence_counts($mineEnrollment);
    $absenceList   = portal_absences($mineEnrollment);

    check(
        'Le décompte des absences est exact',
        $absenceCounts['absences'] === 45,
        (string) $absenceCounts['absences']
    );

    check(
        'La liste n\'est plus tronquée à 30 en silence',
        count($absenceList) === 45,
        count($absenceList) . ' ligne(s) affichée(s)'
    );

    check(
        'Un plafond existe malgré tout, et il est nommé',
        defined('PORTAL_ABSENCE_LIMIT') && PORTAL_ABSENCE_LIMIT >= 200
    );

    // ----- LE LIEN DE TUTELLE SE COUPE IMMÉDIATEMENT -----------------
    $_SESSION['user_id'] = $parentUserId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);

    check('Avant : le tuteur voit son enfant', portal_is_guardian_of($mineId));

    $linkId = (int) db_value(
        'SELECT id FROM student_guardians
          WHERE school_id = :s AND student_id = :st AND guardian_id = :g',
        ['s' => $schoolId, 'st' => $mineId, 'g' => $guardianId]
    );

    db_query(
        'DELETE FROM student_guardians WHERE id = :id AND school_id = :s',
        ['id' => $linkId, 's' => $schoolId]
    );

    check('Détacher le tuteur ferme l\'espace aussitôt', !portal_is_guardian_of($mineId));
    check('Et sa liste d\'enfants devient vide', portal_children() === []);

    db_query(
        'INSERT INTO student_guardians (school_id, student_id, guardian_id, relationship, is_primary)
         VALUES (:s, :st, :g, \'pere\', 1)',
        ['s' => $schoolId, 'st' => $mineId, 'g' => $guardianId]
    );

    // ----- LA FICHE ARCHIVÉE FERME AUSSI ----------------------------
    db_query(
        'UPDATE guardians SET deleted_at = NOW() WHERE id = :g AND school_id = :s',
        ['g' => $guardianId, 's' => $schoolId]
    );

    check('Archiver la fiche tuteur ferme l\'espace', !portal_is_guardian_of($mineId));

    // Le COMPTE, lui, survit : il ne donne plus accès à rien, mais il
    // reste un identifiant valide. Consigné comme dette.
    check(
        'Le compte de connexion survit à l\'archivage (dette connue)',
        (string) db_value('SELECT status FROM users WHERE id = :id AND school_id = :s',
            ['id' => $parentUserId, 's' => $schoolId], true) === 'active'
    );

    db_query(
        'UPDATE guardians SET deleted_at = NULL WHERE id = :g AND school_id = :s',
        ['g' => $guardianId, 's' => $schoolId]
    );

    act_as($schoolId, 'DIRECTION');

    // =================================================================
    //  PHASE 6B — L'ESPACE ÉLÈVE
    //
    //  Le rôle ELEVE existait depuis la phase 1 sans qu'aucun périmètre
    //  ne lui corresponde : un compte élève voyait `1 = 0`. La clé est
    //  désormais `students.user_id` — un LIEN, jamais une permission,
    //  exactement comme la tutelle.
    // =================================================================
    echo "\n  L'ESPACE ÉLÈVE — LUI-MÊME, ET PERSONNE D'AUTRE\n";

    act_as($schoolId, 'DIRECTION');

    // Un élève sans inscription n'a pas d'espace à ouvrir.
    $noEnrolment = db_insert('students', [
        'school_id' => $schoolId, 'uuid' => str_uuid(), 'matricule' => 'PT-SANS-INSC',
        'last_name' => 'SANS', 'first_name' => 'Inscription', 'gender' => 'M',
        'status' => 'active',
    ]);

    check(
        'Un élève sans inscription ne reçoit pas d\'accès',
        !portal_service_create_student_access($noEnrolment)['ok']
    );

    $studentAccess = portal_service_create_student_access($mineId);

    check('L\'accès élève se crée', $studentAccess['ok'], $studentAccess['message']);

    check(
        'Un second accès sur le même élève est refusé',
        !portal_service_create_student_access($mineId)['ok']
    );

    $studentUserId = (int) db_value(
        'SELECT user_id FROM students WHERE id = :id AND school_id = :s',
        ['id' => $mineId, 's' => $schoolId]
    );

    $studentAccount = db_one('SELECT * FROM users WHERE id = :id AND school_id = :s',
        ['id' => $studentUserId, 's' => $schoolId], true);

    check(
        'Le changement de mot de passe est imposé',
        (int) $studentAccount['must_change_password'] === 1
    );

    check(
        'Aucun courriel n\'est inventé pour l\'élève',
        $studentAccount['email'] === null
    );

    check(
        'L\'identifiant est construit sur le MATRICULE',
        str_contains((string) $studentAccount['username'], strtolower(str_slug(
            (string) db_value('SELECT matricule FROM students WHERE id = :id AND school_id = :s',
                ['id' => $mineId, 's' => $schoolId]), '.'
        )))
    );

    check(
        'Le compte reçoit le rôle ELEVE',
        db_exists(
            'SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :u AND r.code = \'ELEVE\' AND r.school_id IS NULL',
            ['u' => $studentUserId],
            true
        )
    );

    // ----- CE QUE L'ÉLÈVE VOIT --------------------------------------
    $_SESSION['user_id'] = $studentUserId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);

    $ownSpace = portal_students();

    check('Son espace contient un seul dossier', count($ownSpace) === 1);
    check('Et c\'est le sien', $ownSpace !== [] && (int) $ownSpace[0]['id'] === $mineId);
    check('Marqué comme « lui-même »', $ownSpace !== [] && (int) $ownSpace[0]['is_self'] === 1);

    check('Il ouvre SON dossier', portal_can_view_student($mineId));
    check('Il n\'ouvre PAS celui d\'un camarade', !portal_can_view_student($otherId));
    check('Il n\'est pas « tuteur » de lui-même', !portal_is_guardian_of($mineId));
    check('Le menu lui propose l\'espace', portal_has_children());

    // Le périmètre de la SCOLARITÉ le suit aussi.
    check('La scolarité le laisse voir son dossier', students_can_view($mineId, $yearId));
    check('Mais pas celui du camarade', !students_can_view($otherId, $yearId));

    // ----- LA DETTE EST UNE AFFAIRE DE PARENTS ----------------------
    check(
        'Par défaut, l\'élève ne voit PAS le solde de ses frais',
        portal_student_sees_fees() === false
    );

    act_as($schoolId, 'DIRECTION');
    school_setting_set('portal.student_sees_fees', true, 'bool');

    check(
        'L\'établissement peut l\'autoriser',
        portal_student_sees_fees() === true
    );

    school_setting_set('portal.student_sees_fees', false, 'bool');

    // ----- ÉLÈVE ET TUTEUR À LA FOIS --------------------------------
    //
    // Un grand frère majeur, tuteur de sa cadette. Aucune règle
    // particulière n'a été écrite pour ce cas : les deux liens se
    // réunissent d'eux-mêmes.
    $brotherGuardian = tenant_insert('guardians', [
        'uuid' => str_uuid(), 'last_name' => 'MPOYI', 'first_name' => 'Grace',
        'phone' => '+243900000790', 'user_id' => $studentUserId,
    ]);

    db_query(
        'INSERT INTO student_guardians (school_id, student_id, guardian_id, relationship)
         VALUES (:s, :st, :g, \'frere\')',
        ['s' => $schoolId, 'st' => $otherId, 'g' => $brotherGuardian]
    );

    $_SESSION['user_id'] = $studentUserId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);

    $mixed = portal_students();

    check('Élève ET tuteur : deux dossiers visibles', count($mixed) === 2);

    check(
        'Sans aucun doublon',
        count($mixed) === count(array_unique(array_column($mixed, 'id')))
    );

    check('Il ouvre maintenant le dossier de sa pupille', portal_can_view_student($otherId));

    // Mais la SCOLARITÉ ne lui ouvre que ce que son double lien autorise.
    check(
        'Et la scolarité suit le même élargissement',
        students_can_view($otherId, $yearId)
    );

    // ----- MOINDRE PRIVILÈGE POUR LES FAMILLES ----------------------
    //
    // `attendance.view` avait été accordée à PARENT et ELEVE en phase 1,
    // quand aucun écran familial n'existait : elle tenait lieu de
    // promesse. La phase 6 l'a tenue AUTREMENT — le portail ne se fonde
    // sur aucune permission. La garder n'ouvrait plus qu'une porte vers
    // l'écran d'appel du personnel, et une entrée de menu sans issue.
    foreach (['PARENT', 'ELEVE'] as $familyRole) {
        check(
            $familyRole . ' ne détient plus attendance.view',
            !db_exists(
                'SELECT 1 FROM role_permissions rp
                   JOIN roles r ON r.id = rp.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE r.code = :role AND r.school_id IS NULL AND p.code = :perm',
                ['role' => $familyRole, 'perm' => 'attendance.view'],
                true
            )
        );
    }

    check(
        'Le personnel, lui, la détient toujours',
        db_exists(
            'SELECT 1 FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.code = :role AND r.school_id IS NULL AND p.code = :perm',
            ['role' => 'ENSEIGNANT', 'perm' => 'attendance.view'],
            true
        )
    );

    // Les familles gardent ce dont le portail se sert.
    check(
        'PARENT garde finance.view : l\'avis de paiement en dépend',
        db_exists(
            'SELECT 1 FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.code = :role AND r.school_id IS NULL AND p.code = :perm',
            ['role' => 'PARENT', 'perm' => 'finance.view'],
            true
        )
    );

    act_as($schoolId, 'DIRECTION');

    // =================================================================
    //  AUDIT 6B — UN PRODUIT QUI DONNE UN ACCÈS DOIT SAVOIR LE RENDRE
    //
    //  Les phases 6A et 6B créaient des comptes dont le mot de passe est
    //  remis UNE SEULE FOIS. Leurs messages renvoyaient ensuite « à la
    //  gestion des utilisateurs » — un écran qui n'existe pas. Au premier
    //  mot de passe perdu, le compte était mort : « mot de passe oublié »
    //  exige un courriel qu'un élève n'a pas.
    // =================================================================
    echo "\n  RÉGÉNÉRER UN ACCÈS PERDU\n";

    act_as($schoolId, 'DIRECTION');

    $resetTargetId = (int) db_value(
        'SELECT user_id FROM students WHERE id = :id AND school_id = :s',
        ['id' => $mineId, 's' => $schoolId]
    );

    $oldHash = (string) db_value(
        'SELECT password_hash FROM users WHERE id = :id AND school_id = :s',
        ['id' => $resetTargetId, 's' => $schoolId],
        true
    );

    $reset = portal_service_reset_access($resetTargetId);

    check('Un accès élève se régénère', $reset['ok'], $reset['message']);

    $newHash = (string) db_value(
        'SELECT password_hash FROM users WHERE id = :id AND school_id = :s',
        ['id' => $resetTargetId, 's' => $schoolId],
        true
    );

    check('L\'ancien mot de passe cesse de fonctionner', $oldHash !== $newHash);

    check(
        'Le nouveau fonctionne',
        password_verify((string) $reset['password'], $newHash)
    );

    check(
        'Le changement est imposé à nouveau',
        (int) db_value(
            'SELECT must_change_password FROM users WHERE id = :id AND school_id = :s',
            ['id' => $resetTargetId, 's' => $schoolId],
            true
        ) === 1
    );

    check(
        'Le mot de passe n\'apparaît pas au journal',
        !platform_scope_cli(static fn (): bool => db_exists(
                'SELECT 1 FROM audit_logs WHERE action = :a AND new_values LIKE :p',
                ['a' => 'portal.reset_access', 'p' => '%' . $reset['password'] . '%'],
                true
            ))
    );

    // LA PORTE DÉROBÉE QU'IL NE FAUT PAS OUVRIR.
    //
    // Cette fonction ne doit jamais servir à réinitialiser le mot de
    // passe d'un directeur ou d'un comptable : cela relèvera du module
    // utilisateurs, avec ses propres garanties.
    $staffUserId = act_as($schoolId, 'COMPTABLE');
    act_as($schoolId, 'DIRECTION');

    $staffReset = portal_service_reset_access($staffUserId);

    check(
        'Un compte de PERSONNEL est refusé',
        !$staffReset['ok'],
        $staffReset['message']
    );

    // Et le tuteur aussi se régénère.
    $guardianUserId = (int) db_value(
        'SELECT user_id FROM guardians WHERE id = :id AND school_id = :s',
        ['id' => $guardianId, 's' => $schoolId]
    );

    check(
        'Un accès tuteur se régénère aussi',
        portal_service_reset_access($guardianUserId)['ok']
    );

    // =================================================================
    //  ISOLATION ENTRE ÉTABLISSEMENTS
    // =================================================================
    echo "\n  AUCUNE ÉCOLE NE VOIT LE PORTAIL D'UNE AUTRE\n";

    $otherSchool = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'PORT-B', 'slug' => 'port-b',
        'name' => 'Autre école', 'status' => 'active',
    ], true);
    $createdSchools[] = $otherSchool;

    act_as($otherSchool, 'DIRECTION');
    tenant_set($otherSchool);

    check('Une autre école ne voit aucun pupille', portal_children() === []);
    check('Ni ne reconnaît la tutelle de la première', !portal_is_guardian_of($mineId));
    check('Ni ne lit son bulletin publié', bulletins_repo_published($bulletinId) === null);
    check('Ni le détail de ce bulletin', bulletins_repo_lines($bulletinId) === ['periods' => [], 'subjects' => []]);

    check(
        'Ni ne crée un accès sur un de ses tuteurs',
        !portal_service_create_guardian_access($guardianId)['ok']
    );
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($createdSchools as $id) {
        tenant_set($id);
        db_query('UPDATE classrooms SET main_teacher_id = NULL WHERE school_id = :s', ['s' => $id]);
        purge_school($id);
    }
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
