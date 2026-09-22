<?php
/**
 * Phase 8B1 — synchronisation hors connexion.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · la synchronisation REJOUE le service métier — elle n'est pas une
 *    porte de service qui contournerait ses règles ;
 *  · une opération déjà reçue ne se rejoue jamais (idempotence) ;
 *  · une modification survenue pendant la coupure produit un CONFLIT,
 *    jamais un écrasement silencieux ;
 *  · l'horodatage de l'appareil n'arbitre rien ;
 *  · un arbitrage « l'appareil a raison » repasse par le service, donc
 *    respecte le verrou du registre ;
 *  · aucune école ne voit ni n'arbitre le conflit d'une autre ;
 *  · un appareil ne se greffe pas sur le compte d'un autre.
 *
 * Usage : php tests/sync_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/attendance/services.php';
require APP_PATH . '/modules/sync/services.php';
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
        'username'      => 'sync.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'sync' . $counter . '@example.test',
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
 * Reprend la session d'un compte DÉJÀ créé.
 *
 * `act_as()` fabrique un compte neuf à chaque appel. Un appareil est
 * rattaché à UN compte : y revenir exige de reprendre le même, pas
 * d'en créer un autre qui lui ressemble.
 */
function reprendre(int $userId, int $schoolId): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;

    auth_user(true);
    perm_all(true);
    perm_roles(true);

    tenant_set($schoolId);
}

/** Une école complète, prête à faire l'appel. */
function ecole(string $code, string $nom): array
{
    $schoolId = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => $code, 'slug' => strtolower($code),
        'name' => $nom, 'status' => 'active',
    ], true);

    db_query(
        'INSERT INTO school_cycles (school_id, cycle_id, is_active)
         SELECT :s, id, 1 FROM education_cycles',
        ['s' => $schoolId]
    );

    act_as($schoolId, 'DIRECTION');
    tenant_set($schoolId);

    $yearId = tenant_insert('academic_years', [
        'code' => '2095-2096', 'name' => 'Test sync', 'starts_on' => '2095-09-01',
        'ends_on' => '2096-07-31', 'status' => 'active', 'is_current' => 1,
    ]);

    curriculum_service_import_national();
    curriculum_service_create_standard_periods($yearId);

    $levelId = (int) db_value("SELECT id FROM education_levels WHERE code = 'CTEB_7'", [], true);
    $program = curriculum_service_create_program($yearId, $levelId, null, null);
    curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    $classroom = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => '7A', 'name' => '7ème A', 'capacity' => 40,
    ]);

    $enroll = [];

    foreach ([['MOSI', 'Un'], ['MBILA', 'Deux']] as $s) {
        $r = students_service_enroll_new(
            ['last_name' => $s[0], 'first_name' => $s[1], 'gender' => 'M'],
            $yearId,
            $classroom
        );
        $enroll[] = (int) students_repo_enrollment((int) $r['id'], $yearId)['id'];
    }

    return [
        'id' => $schoolId, 'year' => $yearId, 'classroom' => $classroom,
        'enroll' => $enroll, 'direction' => (int) $_SESSION['user_id'],
    ];
}

function op(string $uuid, array $payload): array
{
    return [
        'client_uuid'    => $uuid,
        'entity_type'    => 'attendance_session',
        'operation'      => 'update',
        'payload'        => $payload,
        'client_version' => 1,
        'client_time'    => '2095-10-03 08:00:00',
    ];
}

echo "\n  SYNCHRONISATION HORS CONNEXION\n";
echo "  ──────────────────────────────────────────────────────\n\n";

$createdSchools = [];
$DATE           = '2095-10-03';

try {
    $A = ecole('SYNC-A', 'École A');
    $createdSchools[] = $A['id'];

    // =================================================================
    echo "  L'APPAREIL S'ANNONCE, IL NE S'AUTHENTIFIE PAS\n";

    $uuidA   = str_uuid();
    $regA    = sync_service_register_device($uuidA, 'Téléphone A');

    check('Un appareil s\'enregistre', $regA['ok'], $regA['message']);

    check('Un identifiant malformé est refusé',
        !sync_service_register_device('pas-un-uuid')['ok']);

    check('Le même identifiant, rejoué, rend le MÊME appareil',
        sync_service_register_device($uuidA)['device_id'] === $regA['device_id']);

    $deviceA = (int) $regA['device_id'];

    // Un SECOND compte de la même école ne récupère pas cet appareil :
    // l'identifiant est une étiquette, il ne confère aucun droit.
    $autreUser = act_as($A['id'], 'ENSEIGNANT');
    tenant_set($A['id']);

    $vol = sync_service_register_device($uuidA, 'tentative');

    check('Un AUTRE compte ne se greffe pas sur cet appareil',
        !$vol['ok'] && str_contains($vol['message'], 'autre compte'),
        $vol['message']);

    reprendre($A['direction'], $A['id']);

    // =================================================================
    echo "\n  CE QUE LA SYNCHRONISATION ACCEPTE DE REJOUER\n";

    $bidon = sync_service_receive($deviceA, [
        array_merge(op(str_uuid(), []), ['entity_type' => 'bulletin']),
    ]);

    check('Un type d\'opération inconnu est refusé',
        $bidon['results'][0]['status'] === 'rejected'
        && str_contains($bidon['results'][0]['message'], 'non pris en charge'),
        $bidon['results'][0]['message']);

    $trop = sync_service_receive($deviceA, array_fill(0, SYNC_MAX_BATCH + 1, op(str_uuid(), [])));

    check('Un lot au-delà de ' . SYNC_MAX_BATCH . ' opérations est refusé',
        !$trop['ok'] && str_contains($trop['message'], 'volumineux'));

    check('Un appareil inconnu est refusé',
        !sync_service_receive(999999, [op(str_uuid(), [])])['ok']);

    // =================================================================
    echo "\n  LE PREMIER APPEL, VENU DE L'APPAREIL\n";

    $u1 = str_uuid();

    $r1 = sync_service_receive($deviceA, [op($u1, [
        'classroom_id'    => $A['classroom'],
        'date'            => $DATE,
        'slot'            => 'day',
        // Rien n'existait quand l'appareil est parti hors ligne.
        'seen_updated_at' => null,
        'entries'         => [
            $A['enroll'][0] => ['status' => 'present'],
            $A['enroll'][1] => ['status' => 'absent'],
        ],
    ])]);

    check('L\'appel hors connexion s\'applique',
        $r1['results'][0]['status'] === 'applied', $r1['results'][0]['message']);

    $session = attendance_repo_session($A['classroom'], $DATE);

    check('La session existe côté serveur', $session !== null);

    check('Et elle porte bien 1 absent',
        (int) db_value(
            'SELECT COUNT(*) FROM attendance_records
              WHERE school_id = :s AND session_id = :id AND status = \'absent\'',
            ['s' => $A['id'], 'id' => (int) $session['id']],
            true
        ) === 1);

    // =================================================================
    echo "\n  L'IDEMPOTENCE\n";

    $avant = (int) db_value('SELECT COUNT(*) FROM sync_queue WHERE school_id = :s',
        ['s' => $A['id']], true);

    $r1bis = sync_service_receive($deviceA, [op($u1, [
        'classroom_id' => $A['classroom'], 'date' => $DATE, 'slot' => 'day',
        'seen_updated_at' => null,
        'entries' => [$A['enroll'][0] => ['status' => 'absent']],
    ])]);

    check('Le rejeu rend le résultat D\'ORIGINE',
        $r1bis['results'][0]['status'] === 'applied');

    check('Il ne crée aucune ligne de file supplémentaire',
        (int) db_value('SELECT COUNT(*) FROM sync_queue WHERE school_id = :s',
            ['s' => $A['id']], true) === $avant);

    // Le rejeu portait une charge DIFFÉRENTE (le premier élève passait
    // absent). Il ne doit RIEN avoir changé : un rejeu est sans effet,
    // pas « sans dégât ».
    check('Et surtout, il n\'a rien réécrit',
        (int) db_value(
            'SELECT COUNT(*) FROM attendance_records
              WHERE school_id = :s AND session_id = :id AND status = \'absent\'',
            ['s' => $A['id'], 'id' => (int) $session['id']],
            true
        ) === 1);

    // =================================================================
    echo "\n  LA CONCURRENCE OPTIMISTE\n";

    // Quelqu'un corrige l'appel pendant que l'appareil est hors ligne.
    sleep(1);
    attendance_service_take($A['classroom'], $DATE, [
        $A['enroll'][0] => ['status' => 'late', 'minutes_late' => 10],
        $A['enroll'][1] => ['status' => 'present'],
    ]);

    $perime = (string) $session['updated_at'];
    $u2     = str_uuid();

    $r2 = sync_service_receive($deviceA, [op($u2, [
        'classroom_id' => $A['classroom'], 'date' => $DATE, 'slot' => 'day',
        // L'appareil rend ce qu'il avait VU — désormais périmé.
        'seen_updated_at' => $perime,
        'entries' => [
            $A['enroll'][0] => ['status' => 'absent'],
            $A['enroll'][1] => ['status' => 'absent'],
        ],
    ])]);

    check('Une modification survenue pendant la coupure produit un CONFLIT',
        $r2['results'][0]['status'] === 'conflict', $r2['results'][0]['message']);

    check('Le serveur n\'a PAS été écrasé',
        (int) db_value(
            'SELECT COUNT(*) FROM attendance_records
              WHERE school_id = :s AND session_id = :id AND status = \'absent\'',
            ['s' => $A['id'], 'id' => (int) $session['id']],
            true
        ) === 0);

    $conflitId = (int) db_value(
        'SELECT id FROM sync_conflicts WHERE school_id = :s ORDER BY id DESC LIMIT 1',
        ['s' => $A['id']], true);

    check('Un conflit est ouvert et attend un arbitrage', $conflitId > 0);

    check('Et la version de l\'appareil est CONSERVÉE, pas jetée',
        str_contains(
            (string) db_value(
                'SELECT client_values FROM sync_conflicts WHERE id = :id AND school_id = :s',
                ['id' => $conflitId, 's' => $A['id']], true),
            'seen_updated_at'
        ));

    check('Le décompte des conflits en attente le voit',
        (int) sync_repo_counts()['a_arbitrer'] >= 1);

    // =================================================================
    echo "\n  L'HEURE DE L'APPAREIL N'ARBITRE RIEN\n";

    $u3 = str_uuid();

    $r3 = sync_service_receive($deviceA, [[
        'client_uuid' => $u3, 'entity_type' => 'attendance_session',
        'operation' => 'update', 'client_version' => 1,
        // Une horloge avancée de trois ans. Elle ne doit donner
        // AUCUN avantage : le conflit tient au `seen_updated_at`.
        'client_time' => '2099-01-01 00:00:00',
        'payload' => [
            'classroom_id' => $A['classroom'], 'date' => $DATE, 'slot' => 'day',
            'seen_updated_at' => $perime,
            'entries' => [$A['enroll'][0] => ['status' => 'absent']],
        ],
    ]]);

    check('Une horloge avancée ne fait pas gagner la divergence',
        $r3['results'][0]['status'] === 'conflict', $r3['results'][0]['message']);

    check('Et l\'heure absurde est bornée, pas stockée telle quelle',
        (string) db_value(
            'SELECT client_time FROM sync_queue WHERE client_uuid = :u AND school_id = :s',
            ['u' => $u3, 's' => $A['id']], true) < '2097-01-01 00:00:00');

    // =================================================================
    echo "\n  LE SERVICE MÉTIER GARDE LE DERNIER MOT\n";

    // Un élève qui n'est pas dans cette classe.
    $B = ecole('SYNC-B', 'École B');
    $createdSchools[] = $B['id'];

    reprendre($A['direction'], $A['id']);

    $frontiere = sync_service_receive($deviceA, [op(str_uuid(), [
        'classroom_id' => $A['classroom'], 'date' => $DATE, 'slot' => 'day',
        'seen_updated_at' => (string) attendance_repo_session($A['classroom'], $DATE)['updated_at'],
        'entries' => [$B['enroll'][0] => ['status' => 'present']],
    ])]);

    check('Un élève d\'une AUTRE école ne passe pas par la synchronisation',
        $frontiere['results'][0]['status'] === 'rejected',
        $frontiere['results'][0]['message']);

    $classeEtrangere = sync_service_receive($deviceA, [op(str_uuid(), [
        'classroom_id' => $B['classroom'], 'date' => $DATE, 'slot' => 'day',
        'seen_updated_at' => null,
        'entries' => [$B['enroll'][0] => ['status' => 'present']],
    ])]);

    check('Une classe d\'une AUTRE école est introuvable',
        $classeEtrangere['results'][0]['status'] === 'rejected'
        && str_contains($classeEtrangere['results'][0]['message'], 'introuvable'),
        $classeEtrangere['results'][0]['message']);

    check('Et rien n\'a été écrit chez elle',
        (int) db_value('SELECT COUNT(*) FROM attendance_sessions WHERE school_id = :s',
            ['s' => $B['id']], true) === 0);

    // =================================================================
    echo "\n  L'ARBITRAGE REPASSE PAR LE SERVICE\n";

    // CE QUE CE BLOC ÉTABLIT, ET CE QU'IL A CORRIGÉ.
    //
    // La première version de ce test affirmait qu'un arbitrage
    // « l'appareil a raison » se heurterait au verrou du registre. Il
    // n'en est rien : `attendance_service_take()` laisse passer le
    // détenteur de `attendance.justify`, et TOUS les rôles portant
    // `sync.resolve` le portent aussi. Le commentaire du service
    // promettait donc un refus que personne ne pouvait déclencher.
    //
    // La garantie réelle — et elle suffit — est que l'arbitrage
    // n'écrit pas en direct : il emprunte le service, donc ses
    // contrôles. C'est cela qu'on verrouille ici.

    $sessionAv = attendance_repo_session($A['classroom'], $DATE);

    attendance_service_lock((int) $sessionAv['id'], true);

    check('Le registre est verrouillé',
        (int) attendance_repo_session($A['classroom'], $DATE)['is_locked'] === 1);

    check('L\'arbitre en fonction porte bien le droit de corriger un registre clos',
        perm_has('attendance.justify'),
        'c\'est pourquoi le verrou ne l\'arrête pas — voir l\'en-tête du service');

    // Un conflit dont la charge désigne un élève ÉTRANGER à la classe :
    // si l'arbitrage écrivait en direct, il passerait.
    $uX = str_uuid();

    sync_service_receive($deviceA, [op($uX, [
        'classroom_id' => $A['classroom'], 'date' => $DATE, 'slot' => 'day',
        'seen_updated_at' => $perime,
        'entries' => [$B['enroll'][0] => ['status' => 'present']],
    ])]);

    $conflitEtranger = (int) db_value(
        'SELECT c.id FROM sync_conflicts c
           JOIN sync_queue q ON q.id = c.sync_queue_id AND q.school_id = c.school_id
          WHERE c.school_id = :s AND q.client_uuid = :u LIMIT 1',
        ['s' => $A['id'], 'u' => $uX], true);

    check('Ce cas produit bien un conflit à arbitrer', $conflitEtranger > 0);

    $forcer = sync_service_resolve($conflitEtranger, 'client_wins');

    check('« L\'appareil a raison » N\'ÉCRIT PAS un élève étranger à la classe',
        !$forcer['ok'], $forcer['message']);

    check('Le service parle en son nom propre dans le refus',
        str_contains($forcer['message'], 'n\'a pas pu être appliquée'));

    check('Et ce conflit-là reste ouvert',
        (string) db_value(
            'SELECT resolution FROM sync_conflicts WHERE id = :id AND school_id = :s',
            ['id' => $conflitEtranger, 's' => $A['id']], true) === 'pending');

    // L'arbitrage légitime, lui, passe — y compris sur registre clos,
    // parce qu'arbitrer EST un acte de direction. Et il est journalisé.
    $garder = sync_service_resolve($conflitId, 'server_wins');

    check('« Le serveur a raison » se décide', $garder['ok'], $garder['message']);

    check('Le conflit est clos, et par qui',
        (string) db_value(
            'SELECT resolution FROM sync_conflicts WHERE id = :id AND school_id = :s',
            ['id' => $conflitId, 's' => $A['id']], true) === 'server_wins'
        && db_value(
            'SELECT resolved_by FROM sync_conflicts WHERE id = :id AND school_id = :s',
            ['id' => $conflitId, 's' => $A['id']], true) !== null);

    check('L\'arbitrage laisse une trace au journal',
        (int) db_value(
            'SELECT COUNT(*) FROM audit_logs
              WHERE school_id = :s AND action = \'sync.conflict_resolved\'',
            ['s' => $A['id']], true) >= 1);

    check('Un conflit déjà arbitré ne se rejuge pas',
        !sync_service_resolve($conflitId, 'client_wins')['ok']);

    check('Une décision inventée est refusée',
        !sync_service_resolve($conflitId, 'peu_importe')['ok']);

    attendance_service_lock((int) $sessionAv['id'], false);

    // =================================================================
    echo "\n  ARBITRER RELÈVE DE LA DIRECTION\n";

    $conflitOuvert = (int) db_value(
        'SELECT id FROM sync_conflicts WHERE school_id = :s AND resolution = \'pending\'
         ORDER BY id DESC LIMIT 1',
        ['s' => $A['id']], true);

    act_as($A['id'], 'ENSEIGNANT');
    tenant_set($A['id']);

    check('Un enseignant n\'arbitre pas',
        !can('sync.resolve') && !sync_service_resolve($conflitOuvert, 'server_wins')['ok']);

    // =================================================================
    echo "\n  AUCUNE ÉCOLE NE VOIT LES CONFLITS D'UNE AUTRE\n";

    reprendre($B['direction'], $B['id']);

    check('L\'école B ne voit aucun conflit', sync_repo_conflicts() === []);

    check('Et ne peut pas arbitrer celui de l\'école A',
        !sync_service_resolve($conflitOuvert, 'client_wins')['ok']);

    check('Le conflit de A est intact',
        (string) db_value(
            'SELECT resolution FROM sync_conflicts WHERE id = :id AND school_id = :s',
            ['id' => $conflitOuvert, 's' => $A['id']], true) === 'pending');

    check('Et B ne voit pas l\'appareil de A', sync_repo_devices() === []);

    // =================================================================
    echo "\n  AUDIT 8B1 — L'INDEX TRANCHE L'UNICITÉ\n";

    reprendre($A['direction'], $A['id']);

    // La course réelle est mesurée à deux processus (voir
    // tests/sonde_sync_course.sh). Ici on verrouille la SÉMANTIQUE que
    // cette course exige : une lecture d'issue qui distingue « déjà
    // traitée » de « encore en cours », et « inconnue ».
    check('Une opération inconnue rend null',
        sync_outcome_existante(str_uuid(), $A['id']) === null);

    $connue = sync_outcome_existante($u1, $A['id']);

    check('Une opération connue rend son issue', $connue !== null && $connue['status'] === 'applied');

    db_query('UPDATE sync_queue SET status = \'pending\'
               WHERE client_uuid = :u AND school_id = :s',
        ['u' => $u1, 's' => $A['id']], true);

    $enCours = sync_outcome_existante($u1, $A['id']);

    check('Une opération ENCORE EN COURS ne se présente pas comme un verdict',
        $enCours['status'] === 'pending' && str_contains($enCours['message'], 'conservez'),
        $enCours['message']);

    db_query('UPDATE sync_queue SET status = \'applied\'
               WHERE client_uuid = :u AND school_id = :s',
        ['u' => $u1, 's' => $A['id']], true);

    check('Et une opération d\'une AUTRE école reste invisible',
        sync_outcome_existante($u1, $B['id']) === null);
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($createdSchools as $schoolId) {
        tenant_set($schoolId);
        db_query('UPDATE classrooms SET main_teacher_id = NULL WHERE school_id = :s', ['s' => $schoolId], true);

        foreach ([
            'sync_conflicts', 'sync_queue', 'sync_devices',
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
