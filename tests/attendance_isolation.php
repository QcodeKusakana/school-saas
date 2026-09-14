<?php
/**
 * Phase 4D — présences : appel, justification, périmètre.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · une journée SANS APPEL se distingue d'une journée sans absent —
 *    faute de quoi l'école ne peut pas savoir qui n'a pas appelé ;
 *  · une absence justifiée reste une absence dans les statistiques ;
 *  · un enseignant n'appelle que SES classes, alors que la permission
 *    attendance.record ne dit rien de la classe ;
 *  · un registre verrouillé ne se corrige plus qu'en direction ;
 *  · aucune école ne voit le registre d'une autre.
 *
 * Usage : php tests/attendance_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/attendance/services.php';
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
        'username'      => 'att.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'att' . $counter . '@example.test',
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

echo "\n  PRÉSENCES — APPEL, JUSTIFICATION ET PÉRIMÈTRE\n";
echo "  ──────────────────────────────────────────────────────\n\n";

$createdSchools = [];

try {
    $schoolId = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'ATT-A', 'slug' => 'att-a',
        'name' => 'École des Présences', 'status' => 'active',
    ], true);
    $createdSchools[] = $schoolId;

    db_query(
        'INSERT INTO school_cycles (school_id, cycle_id, is_active)
         SELECT :s, id, 1 FROM education_cycles',
        ['s' => $schoolId]
    );

    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    $yearId = tenant_insert('academic_years', [
        'code' => '2095-2096', 'name' => 'Test présences', 'starts_on' => '2095-09-01',
        'ends_on' => '2096-07-31', 'status' => 'active', 'is_current' => 1,
    ]);

    curriculum_service_import_national();
    curriculum_service_create_standard_periods($yearId);

    $levelId = (int) db_value("SELECT id FROM education_levels WHERE code = 'CTEB_7'", [], true);
    $program = curriculum_service_create_program($yearId, $levelId, null, null);
    curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    $classA = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '7A', 'name' => '7ème A', 'capacity' => 40,
    ]);
    $classB = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '7B', 'name' => '7ème B', 'capacity' => 40,
    ]);

    $enroll = [];

    foreach ([['MOSI', 'Un'], ['MBILA', 'Deux'], ['NKOSI', 'Trois']] as $s) {
        $r = students_service_enroll_new(
            ['last_name' => $s[0], 'first_name' => $s[1], 'gender' => 'M'],
            $yearId,
            $classA
        );
        $enroll[] = (int) students_repo_enrollment((int) $r['id'], $yearId)['id'];
    }

    $other = students_service_enroll_new(
        ['last_name' => 'ETRANGER', 'first_name' => 'Quatre', 'gender' => 'F'],
        $yearId,
        $classB
    );
    $otherEnrollment = (int) students_repo_enrollment((int) $other['id'], $yearId)['id'];

    check('Trois élèves en 7ème A, un en 7ème B', count($enroll) === 3);

    $date = '2095-10-03';

    // =================================================================
    //  UNE JOURNÉE SANS APPEL N'EST PAS UNE JOURNÉE SANS ABSENT
    //
    //  C'est la raison d'être de la table des sessions. Le même piège a
    //  déjà coûté cher en phase 4B : compter les lignes de cotes faisait
    //  passer une colonne vierge pour « complète ».
    // =================================================================
    check(
        'Avant tout appel, aucune session n\'existe',
        attendance_repo_session($classA, $date) === null
    );

    $overview = attendance_repo_day_overview($yearId, $date);
    $notTaken = array_filter($overview, static fn (array $c): bool => $c['session_id'] === null);

    check(
        'Le tableau du jour signale les classes SANS appel',
        count($notTaken) === 2,
        count($notTaken) . ' classe(s) sans appel sur ' . count($overview)
    );

    // =================================================================
    //  L'APPEL
    // =================================================================
    $taken = attendance_service_take($classA, $date, [
        $enroll[0] => ['status' => 'present'],
        $enroll[1] => ['status' => 'absent'],
        $enroll[2] => ['status' => 'late', 'minutes_late' => 25],
    ]);

    check('L\'appel s\'enregistre', $taken['ok'] && $taken['saved'] === 3, $taken['message']);

    $session = attendance_repo_session($classA, $date);

    check(
        'Une session est créée, avec son auteur',
        $session !== null && $session['taken_by'] !== null
    );

    $overview = attendance_repo_day_overview($yearId, $date);
    $rowA     = null;

    foreach ($overview as $row) {
        if ((int) $row['classroom_id'] === $classA) {
            $rowA = $row;
        }
    }

    check(
        'Le tableau du jour compte 1 absent et 1 retard',
        (int) $rowA['absents'] === 1 && (int) $rowA['lates'] === 1,
        $rowA['absents'] . ' absent(s), ' . $rowA['lates'] . ' retard(s)'
    );

    // Une classe où TOUS sont présents doit rester distinguable d'une
    // classe sans appel : zéro absent, mais une session.
    attendance_service_take($classB, $date, [$otherEnrollment => ['status' => 'present']]);
    $overview = attendance_repo_day_overview($yearId, $date);
    $rowB     = null;

    foreach ($overview as $row) {
        if ((int) $row['classroom_id'] === $classB) {
            $rowB = $row;
        }
    }

    check(
        'Une classe sans absent reste distincte d\'une classe sans appel',
        $rowB['session_id'] !== null && (int) $rowB['absents'] === 0,
        'session ' . ($rowB['session_id'] !== null ? 'présente' : 'absente')
            . ', ' . $rowB['absents'] . ' absent'
    );

    // =================================================================
    //  IDEMPOTENCE ET DURÉE DE RETARD
    // =================================================================
    $again = attendance_service_take($classA, $date, [
        $enroll[0] => ['status' => 'absent'],
        $enroll[1] => ['status' => 'present'],
        $enroll[2] => ['status' => 'present'],
    ]);

    check('L\'appel rejoué corrige au lieu de dupliquer', $again['ok'], $again['message']);

    check(
        'Une seule session pour cette classe et cette date',
        (int) db_value(
            'SELECT COUNT(*) FROM attendance_sessions
              WHERE school_id = :s AND classroom_id = :c AND session_date = :d',
            ['s' => $schoolId, 'c' => $classA, 'd' => $date],
            true
        ) === 1
    );

    $corrected = attendance_repo_register($classA, (int) $session['id']);
    $third     = null;

    foreach ($corrected as $row) {
        if ((int) $row['enrollment_id'] === $enroll[2]) {
            $third = $row;
        }
    }

    check(
        'Repassé « présent », le retard perd sa durée',
        $third['status'] === 'present' && $third['minutes_late'] === null,
        $third['status'] . ' / ' . var_export($third['minutes_late'], true)
    );

    // =================================================================
    //  UNE ABSENCE JUSTIFIÉE RESTE UNE ABSENCE
    // =================================================================
    attendance_service_take($classA, $date, [
        $enroll[0] => ['status' => 'absent'],
        $enroll[1] => ['status' => 'present'],
        $enroll[2] => ['status' => 'present'],
    ]);

    $absentRecord = tenant_one(
        'attendance_records',
        'session_id = :s AND enrollment_id = :e',
        ['s' => (int) $session['id'], 'e' => $enroll[0]]
    );

    $justified = attendance_service_justify((int) $absentRecord['id'], true, 'Certificat médical');
    check('Une absence se justifie', $justified['ok'], $justified['message']);

    $summary = attendance_repo_summary($enroll[0], '2095-09-01', '2096-07-31');

    check(
        'Justifiée, elle compte TOUJOURS comme absence',
        $summary['absent'] === 1 && $summary['justified'] === 1,
        $summary['absent'] . ' absence(s) dont ' . $summary['justified'] . ' justifiée(s)'
    );

    $reloaded = tenant_find('attendance_records', (int) $absentRecord['id']);

    check(
        'Le statut n\'a pas changé : l\'élève reste absent',
        $reloaded['status'] === 'absent',
        $reloaded['status']
    );

    // Refaire l'appel ne doit pas effacer le motif saisi au secrétariat.
    attendance_service_take($classA, $date, [$enroll[0] => ['status' => 'absent']]);
    $afterRetake = tenant_find('attendance_records', (int) $absentRecord['id']);

    check(
        'Refaire l\'appel n\'efface pas la justification',
        (int) $afterRetake['is_justified'] === 1
            && $afterRetake['justification'] === 'Certificat médical',
        (string) ($afterRetake['justification'] ?? 'effacée')
    );

    check(
        'Une présence ne se justifie pas',
        !attendance_service_justify(
            (int) tenant_one('attendance_records', 'session_id = :s AND enrollment_id = :e',
                ['s' => (int) $session['id'], 'e' => $enroll[1]])['id'],
            true,
            'motif'
        )['ok']
    );

    // =================================================================
    //  UN APPEL PARTIEL NE PASSE PAS POUR COMPLET
    //
    //  Une session existe dès le premier élève enregistré. Sans compter
    //  la COUVERTURE, un appel portant sur deux élèves sur quarante
    //  s'affichait « fait » : les autres n'étaient ni présents ni absents,
    //  et personne ne pouvait le savoir. C'est exactement le piège que ce
    //  module devait fermer — reproduit un niveau plus bas.
    // =================================================================
    $partialDate = '2095-10-10';
    $partial     = attendance_service_take($classA, $partialDate, [
        $enroll[0] => ['status' => 'present'],
    ]);

    check(
        'Un appel partiel est accepté — un élève peut arriver plus tard',
        $partial['ok'],
        $partial['message']
    );

    check(
        'Mais il annonce les élèves non couverts',
        ($partial['not_covered'] ?? 0) === 2,
        ($partial['not_covered'] ?? 0) . ' non couvert(s) sur 3 inscrits'
    );

    $partialOverview = attendance_repo_day_overview($yearId, $partialDate);
    $partialRow      = null;

    foreach ($partialOverview as $row) {
        if ((int) $row['classroom_id'] === $classA) {
            $partialRow = $row;
        }
    }

    check(
        'Le tableau du jour distingue « incomplet » de « fait »',
        (int) $partialRow['recorded'] === 1 && (int) $partialRow['student_count'] === 3,
        $partialRow['recorded'] . ' appelé(s) sur ' . $partialRow['student_count']
    );

    // Complété, l'appel redevient complet.
    attendance_service_take($classA, $partialDate, [
        $enroll[1] => ['status' => 'absent'],
        $enroll[2] => ['status' => 'present'],
    ]);

    $completed = attendance_repo_day_overview($yearId, $partialDate);

    foreach ($completed as $row) {
        if ((int) $row['classroom_id'] === $classA) {
            check(
                'Complété, il n\'est plus signalé incomplet',
                (int) $row['recorded'] === (int) $row['student_count'],
                $row['recorded'] . '/' . $row['student_count']
            );
        }
    }

    // =================================================================
    //  « JOURNÉE » ET « DEMI-JOURNÉE » NE COHABITENT PAS
    //
    //  Deux sessions pour un même jour comptaient chaque absence DEUX
    //  fois dans les cumuls.
    // =================================================================
    $mixed = attendance_service_take(
        $classA,
        $partialDate,
        [$enroll[0] => ['status' => 'absent']],
        'morning'
    );

    check(
        'Le matin est refusé quand la journée entière est déjà appelée',
        !$mixed['ok'] && str_contains($mixed['message'], 'demi-journée'),
        $mixed['message']
    );

    check(
        'Une seule session subsiste pour cette date',
        (int) db_value(
            'SELECT COUNT(*) FROM attendance_sessions
              WHERE school_id = :s AND classroom_id = :c AND session_date = :d',
            ['s' => $schoolId, 'c' => $classA, 'd' => $partialDate],
            true
        ) === 1
    );

    // =================================================================
    //  DATES
    // =================================================================
    $outside = attendance_service_take($classA, '2099-01-15', [$enroll[0] => ['status' => 'present']]);

    check(
        'Une date hors année scolaire est refusée',
        !$outside['ok'] && str_contains($outside['message'], 'hors de l\'année'),
        $outside['message']
    );

    $badDate = attendance_service_take($classA, '2095-02-30', [$enroll[0] => ['status' => 'present']]);

    check(
        'Une date qui n\'existe pas est refusée',
        !$badDate['ok'] && str_contains($badDate['message'], 'invalide'),
        $badDate['message']
    );

    // =================================================================
    //  LISTE BLANCHE DES ÉLÈVES
    // =================================================================
    $forged = attendance_service_take($classA, $date, [
        $otherEnrollment => ['status' => 'absent'],
    ]);

    check(
        'Un élève d\'une AUTRE classe glissé dans l\'envoi est rejeté',
        !$forged['ok'] && isset($forged['errors'][$otherEnrollment]),
        $forged['errors'][$otherEnrollment] ?? $forged['message']
    );

    // =================================================================
    //  PÉRIMÈTRE — LA PERMISSION NE DÉSIGNE PAS LA CLASSE
    //
    //  attendance.record est accordée à ENSEIGNANT depuis la phase 1.
    //  Sans périmètre, tout enseignant appellerait toutes les classes de
    //  l'établissement. C'est la faute exacte des audits 3 et 4B.
    // =================================================================
    $teacherUser = act_as($schoolId, 'ENSEIGNANT');
    tenant_set($schoolId);

    check('L\'enseignant détient bien attendance.record', perm_has('attendance.record'));

    $noScope = attendance_service_take($classA, $date, [$enroll[0] => ['status' => 'absent']]);

    check(
        'Sans classe attribuée, il n\'appelle AUCUNE classe',
        !$noScope['ok'] && str_contains($noScope['message'], 'périmètre'),
        $noScope['message']
    );

    // On lui confie la 7ème A.
    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    $teacherId = tenant_insert('teachers', [
        'uuid' => str_uuid(), 'user_id' => $teacherUser, 'matricule' => 'ENS-ATT',
        'last_name' => 'KALALA', 'first_name' => 'Paul', 'gender' => 'M',
        'status' => 'active', 'hire_date' => '2095-09-01',
    ]);

    tenant_update('classrooms', ['main_teacher_id' => $teacherId], 'id = :id', ['id' => $classA]);

    $_SESSION['user_id'] = $teacherUser;
    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($schoolId);

    $ownClass = attendance_service_take($classA, '2095-10-04', [$enroll[0] => ['status' => 'present']]);
    check('Titulaire de la 7ème A, il l\'appelle', $ownClass['ok'], $ownClass['message']);

    $foreignClass = attendance_service_take($classB, '2095-10-04', [$otherEnrollment => ['status' => 'present']]);

    check(
        'Mais pas la 7ème B, qui ne lui est pas confiée',
        !$foreignClass['ok'] && str_contains($foreignClass['message'], 'périmètre'),
        $foreignClass['message']
    );

    check(
        'Et il ne justifie pas : ce droit ne lui est pas accordé',
        !attendance_service_justify((int) $absentRecord['id'], false)['ok']
    );

    // =================================================================
    //  VERROUILLAGE
    // =================================================================
    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    $locked = attendance_service_lock((int) $session['id'], true);
    check('La direction verrouille un registre', $locked['ok'], $locked['message']);

    $_SESSION['user_id'] = $teacherUser;
    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($schoolId);

    $afterLock = attendance_service_take($classA, $date, [$enroll[0] => ['status' => 'present']]);

    check(
        'Registre verrouillé : l\'enseignant ne corrige plus',
        !$afterLock['ok'] && str_contains($afterLock['message'], 'verrouillé'),
        $afterLock['message']
    );

    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    $directionFix = attendance_service_take($classA, $date, [$enroll[0] => ['status' => 'present']]);
    check('Mais la direction, si', $directionFix['ok'], $directionFix['message']);

    // =================================================================
    //  ANNÉE CLÔTURÉE
    // =================================================================
    tenant_update('academic_years', ['status' => 'closed'], 'id = :id', ['id' => $yearId]);

    $closed = attendance_service_take($classA, $date, [$enroll[0] => ['status' => 'present']]);
    check(
        'Année clôturée : plus aucun appel',
        !$closed['ok'] && str_contains($closed['message'], 'clôturée'),
        $closed['message']
    );

    tenant_update('academic_years', ['status' => 'active'], 'id = :id', ['id' => $yearId]);

    // =================================================================
    //  ISOLATION ENTRE ÉCOLES
    // =================================================================
    foreach (['attendance_sessions', 'attendance_records'] as $table) {
        $blocked = false;

        try {
            db_all('SELECT * FROM ' . $table . ' LIMIT 1');
        } catch (Throwable $e) {
            $blocked = str_contains($e->getMessage(), 'school_id');
        }

        check("Garde-fou actif sur « {$table} »", $blocked);
    }

    $otherSchool = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'ATT-B', 'slug' => 'att-b',
        'name' => 'École voisine', 'status' => 'active',
    ], true);
    $createdSchools[] = $otherSchool;

    act_as($otherSchool, 'DIRECTION');
    tenant_set($otherSchool);

    check(
        'L\'école voisine ne voit aucun registre de la première',
        attendance_repo_session($classA, $date) === null
            && attendance_repo_register($classA, (int) $session['id']) === []
    );

    $crossTake = attendance_service_take($classA, '2095-10-05', [$enroll[0] => ['status' => 'absent']]);

    check(
        'Et ne peut pas faire l\'appel dans une classe étrangère',
        !$crossTake['ok'] && str_contains($crossTake['message'], 'introuvable'),
        $crossTake['message']
    );
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($createdSchools as $schoolId) {
        tenant_set($schoolId);
        db_query('UPDATE classrooms SET main_teacher_id = NULL WHERE school_id = :s', ['s' => $schoolId], true);

        foreach ([
            'attendance_records', 'attendance_sessions', 'bulletins', 'grades',
            'student_history', 'orientations', 'student_guardians', 'enrollments',
            'guardians', 'students', 'student_counters', 'teacher_subjects',
            'teachers', 'classrooms', 'curriculum_subjects', 'curriculums',
            'grade_periods', 'subjects', 'options', 'sections', 'academic_years',
            'audit_logs',
        ] as $table) {
            db_query("DELETE FROM {$table} WHERE school_id = :s", ['s' => $schoolId], true);
        }

        db_query('DELETE FROM school_settings WHERE school_id = :s', ['s' => $schoolId], true);
        db_query('DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE school_id = :s)', ['s' => $schoolId], true);
        db_query('DELETE FROM users WHERE school_id = :s', ['s' => $schoolId], true);
        db_query('DELETE FROM school_cycles WHERE school_id = :s', ['s' => $schoolId], true);
        db_query('DELETE FROM schools WHERE id = :s', ['s' => $schoolId], true);
    }
}

echo "\n  ──────────────────────────────────────────────────\n";
printf("  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
