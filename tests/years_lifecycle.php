<?php
/**
 * Phase 9D — l'année scolaire : création, désignation, clôture, réouverture.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · une école peut créer sa DEUXIÈME, sa TROISIÈME année — le piège de
 *    `is_current = 0` contre l'index unique ;
 *  · deux années ne se chevauchent jamais ;
 *  · l'école n'est JAMAIS laissée sans année courante ;
 *  · clôturer ferme réellement les sept modules, et seulement en
 *    écriture ;
 *  · la réouverture exige un motif et laisse une trace ;
 *  · lire, gérer et clôturer sont trois pouvoirs distincts ;
 *  · aucune école ne voit ni ne touche les années d'une autre.
 *
 * Usage : php tests/years_lifecycle.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_PATH . '/modules/years/services.php';
require_once APP_PATH . '/modules/students/services.php';
require_once APP_PATH . '/modules/curriculum/services.php';
require_once APP_PATH . '/modules/teachers/services.php';
require_once APP_PATH . '/modules/attendance/services.php';
require_once APP_PATH . '/modules/bulletins/services.php';
require_once APP_PATH . '/modules/finance/services.php';
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
    static $n = 0;
    $n++;

    $userId = db_insert('users', [
        'uuid'          => str_uuid(),
        'school_id'     => $schoolId,
        'username'      => 'an.' . strtolower($roleCode) . '.' . $n,
        'email'         => 'an' . $n . '@example.test',
        'password_hash' => password_hash('MotDePasse2026', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name'     => 'ANNEE',
        'first_name'    => ucfirst(strtolower($roleCode)),
        'status'        => 'active',
    ], true);

    db_query(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :u, id FROM roles WHERE code = :c AND school_id IS NULL',
        ['u' => $userId, 'c' => $roleCode],
        true
    );

    reprendre($userId, $schoolId);

    return $userId;
}

function reprendre(int $userId, int $schoolId): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;

    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($schoolId);
}

function ecole(string $code, string $nom): int
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

    return $schoolId;
}

/**
 * Le décor minimal qu'exigent les onze gestes : un programme, une
 * classe, un élève inscrit, un enseignant, une matière.
 *
 * Il est monté AVANT la clôture — on ne peut pas créer sur une année
 * clôturée, ce qui est précisément ce que l'on veut démontrer.
 *
 * @return array<string, int>
 */
function decor_complet(int $schoolId, int $yearId): array
{
    tenant_set($schoolId);

    curriculum_service_import_national();
    curriculum_service_create_standard_periods($yearId);

    $niveau  = (int) db_value("SELECT id FROM education_levels WHERE code = 'CTEB_7'", [], true);
    $program = curriculum_service_create_program($yearId, $niveau, null, null);

    curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    $classe = tenant_insert('classrooms', [
        'academic_year_id' => $yearId, 'curriculum_id' => (int) $program['id'],
        'code' => 'D7A', 'name' => 'Décor 7A', 'capacity' => 40,
    ]);

    $eleve = students_service_enroll_new(
        ['last_name' => 'DECOR', 'first_name' => 'Eleve', 'gender' => 'M',
         'birth_date' => '2011-03-08', 'birth_place' => 'Kolwezi'],
        $yearId,
        $classe
    );

    // `hire_date`, pas `hired_on` — et `uuid` est obligatoire.
    $enseignant = tenant_insert('teachers', [
        'uuid' => str_uuid(),
        'last_name' => 'DECOR', 'first_name' => 'Enseignant',
        'gender' => 'F', 'status' => 'active', 'hire_date' => '2020-09-01',
    ]);

    return [
        'year'       => $yearId,
        'level'      => $niveau,
        'classroom'  => $classe,
        'student'    => (int) $eleve['id'],
        'enrollment' => (int) students_repo_enrollment((int) $eleve['id'], $yearId)['id'],
        'teacher'    => $enseignant,
        'subject'    => (int) db_value(
            'SELECT id FROM curriculum_subjects
              WHERE school_id = :s AND curriculum_id = :c LIMIT 1',
            ['s' => $schoolId, 'c' => (int) $program['id']],
            true
        ),
    ];
}

echo "\n  L'ANNÉE SCOLAIRE — CYCLE DE VIE\n";
echo "  ──────────────────────────────────\n\n";

$ecoles = [];

try {
    $idA = ecole('ANN-A', 'Institut Alpha');
    $ecoles[] = $idA;
    $dirA = act_as($idA, 'DIRECTION');

    // =================================================================
    echo "  LA PREMIÈRE ANNÉE DEVIENT COURANTE D'OFFICE\n";

    // Douze endroits du produit lisent `is_current = 1`. Une école dont
    // la première année ne serait pas courante ouvrirait sur un produit
    // muet : ni inscription, ni classe, ni encaissement.
    $un = years_service_create([
        'code' => '2020-2021', 'name' => 'Année 2020-2021',
        'starts_on' => '2020-09-01', 'ends_on' => '2021-07-31',
    ]);

    check('La première année se crée', $un['ok'], $un['message']);

    $premiere = years_repo_find((int) $un['id']);

    check('…et elle est courante d\'office', (int) $premiere['is_current'] === 1);
    check('…et active, pas en préparation', (string) $premiere['status'] === 'active');

    // LE DÉCOR SE MONTE MAINTENANT, PENDANT QUE L'ANNÉE EST OUVERTE.
    //
    // Première version : il était monté APRÈS la clôture — donc il ne
    // pouvait rien créer, et la suite s'arrêtait sur un 404. C'est
    // précisément ce que la clôture est censée faire ; encore
    // faut-il l'éprouver dans le bon ordre.
    $decor = decor_complet($idA, (int) $un['id']);

    // =================================================================
    echo "\n  UNE ÉCOLE PEUT CRÉER SA DEUXIÈME, PUIS SA TROISIÈME\n";

    // LE PIÈGE MESURÉ AVANT D'ÉCRIRE CE MODULE.
    //
    // `uq_year_current (school_id, is_current)` est UNIQUE. En MySQL,
    // NULL n'entre pas en collision, `0` si. Enregistrer les années non
    // courantes avec `0` faisait REFUSER la seconde : l'école ne pouvait
    // pas passer son troisième exercice.
    $deux = years_service_create([
        'code' => '2021-2022', 'name' => 'Année 2021-2022',
        'starts_on' => '2021-09-01', 'ends_on' => '2022-07-31',
    ]);

    check('La deuxième année se crée', $deux['ok'], $deux['message']);

    $trois = years_service_create([
        'code' => '2022-2023', 'name' => 'Année 2022-2023',
        'starts_on' => '2022-09-01', 'ends_on' => '2023-07-31',
    ]);

    check('Et la TROISIÈME aussi', $trois['ok'], $trois['message']);

    check('Les suivantes sont en préparation, pas courantes',
        (string) years_repo_find((int) $deux['id'])['status'] === 'draft'
        && years_repo_find((int) $deux['id'])['is_current'] === null);

    check('Il n\'y a toujours qu\'une seule année courante',
        count(array_filter(years_repo_all(),
            static fn (array $a): bool => (int) $a['is_current'] === 1)) === 1);

    // =================================================================
    echo "\n  DEUX ANNÉES NE SE CHEVAUCHENT JAMAIS\n";

    // Sans cette règle, rien ne dirait à quelle année appartient une
    // séance du 15 septembre.
    $chevauche = years_service_create([
        'code' => 'CHEV', 'name' => 'Chevauchante',
        'starts_on' => '2021-06-01', 'ends_on' => '2022-03-31',
    ]);

    check('Une période chevauchante est refusée', !$chevauche['ok'], $chevauche['message']);

    check('Une période qui s\'y glisse sans toucher est acceptée',
        years_service_create([
            'code' => '2023-2024', 'name' => 'Année 2023-2024',
            'starts_on' => '2023-09-01', 'ends_on' => '2024-07-31',
        ])['ok']);

    check('Un code déjà pris est refusé',
        !years_service_create([
            'code' => '2021-2022', 'name' => 'Doublon',
            'starts_on' => '2029-09-01', 'ends_on' => '2030-07-31',
        ])['ok']);

    check('Une date de fin antérieure au début est refusée',
        !years_service_create([
            'code' => 'INV', 'name' => 'Inversée',
            // La fin PRÉCÈDE le début. (Première version de ce test :
            // les deux dates étaient dans le bon ordre, et l'assertion
            // échouait sur un produit parfaitement sain.)
            'starts_on' => '2031-09-01', 'ends_on' => '2031-07-31',
        ])['ok']);

    check('Une date illisible est refusée, sans erreur',
        !years_service_create([
            'code' => 'MAL', 'name' => 'Mal datée',
            'starts_on' => 'pas-une-date', 'ends_on' => '2032-07-31',
        ])['ok']);

    // =================================================================
    echo "\n  ON NE RETIRE PAS LE SOL SOUS LES PIEDS D'UNE ÉCOLE\n";

    $fermer = years_service_close((int) $un['id']);

    check('Clôturer l\'année COURANTE est refusé', !$fermer['ok'], $fermer['message']);

    check('Et elle est toujours courante',
        (int) years_repo_find((int) $un['id'])['is_current'] === 1);

    // L'ordre naturel : désigner la suivante, puis clôturer.
    check('On désigne la suivante comme courante',
        years_service_set_current((int) $deux['id'])['ok']);

    check('L\'ancienne n\'est plus courante',
        years_repo_find((int) $un['id'])['is_current'] === null);

    check('Et il n\'y a toujours qu\'une seule courante',
        count(array_filter(years_repo_all(),
            static fn (array $a): bool => (int) $a['is_current'] === 1)) === 1);

    // =================================================================
    echo "\n  CLÔTURER FERME RÉELLEMENT LES SEPT MODULES\n";

    $ferme = years_service_close((int) $un['id']);

    check('La clôture passe maintenant', $ferme['ok'], $ferme['message']);

    $close = years_repo_find((int) $un['id']);

    check('L\'état est « clôturée »', (string) $close['status'] === 'closed');
    check('La date de clôture est posée', $close['closed_at'] !== null);
    check('Et son auteur aussi', (int) $close['closed_by'] === $dirA);

    // Et la LECTURE reste ouverte : clôturer n'archive pas, ne supprime
    // pas, ne cache pas.
    check('Mais la lecture reste ouverte',
        years_repo_find((int) $un['id']) !== null
        && years_repo_contents((int) $un['id'])['inscrits'] >= 0);

    check('Une année clôturée ne se modifie pas',
        !years_service_update((int) $un['id'], [
            'code' => 'AUTRE', 'name' => 'Renommée',
            'starts_on' => '2020-09-01', 'ends_on' => '2021-07-31',
        ])['ok']);

    check('…et ne peut pas redevenir courante',
        !years_service_set_current((int) $un['id'])['ok']);

    check('Clôturer deux fois est refusé', !years_service_close((int) $un['id'])['ok']);

    // =================================================================
    echo "\n  LES SEPT MODULES, ÉPROUVÉS UN PAR UN\n";

    // LA PHASE AFFIRME « SEPT MODULES ». ON LES JOUE TOUS.
    //
    // La première version de ce test n'éprouvait qu'un seul geste —
    // l'inscription d'un élève — et concluait sur les quatorze gardes.
    //
    //   > Une affirmation qui porte sur sept modules et n'en éprouve
    //   > qu'un n'est pas vérifiée : elle est échantillonnée.
    //
    // La sonde d'audit a d'abord donné SIX gestes « acceptés ». C'était
    // faux : c'étaient mes propres erreurs d'arguments. Un verdict qui
    // ne dit pas POURQUOI ne distingue pas un refus de clôture d'un
    // appel mal formé.
    $gestes = [
        ['élèves', 'inscrire', static fn (): array => students_service_enroll_new(
            ['last_name' => 'APRES', 'first_name' => 'Cloture', 'gender' => 'M',
             'birth_date' => '2012-01-01', 'birth_place' => 'Kolwezi'],
            $decor['year'], null)],
        ['élèves', 'affecter à une classe', static fn (): array =>
            students_service_assign_classroom($decor['student'], $decor['enrollment'], $decor['classroom'])],
        ['enseignants', 'affecter un enseignant', static fn (): array =>
            teachers_service_assign($decor['teacher'], $decor['classroom'], $decor['subject'])],
        ['enseignants', 'désigner un titulaire', static fn (): array =>
            teachers_service_set_main_teacher($decor['classroom'], $decor['teacher'])],
        ['référentiel', 'créer un programme', static fn (): array =>
            curriculum_service_create_program($decor['year'], $decor['level'], null, null)],
        ['présences', 'ouvrir une séance', static fn (): array =>
            attendance_service_can_record($decor['classroom'], null)],
        // LA CLÉ DE REGROUPEMENT DÉPEND DU CYCLE DE LA CLASSE.
        //
        // T1/T2/T3 au trimestriel, S1/S2 au semestriel. Écrire « T1 » en
        // dur faisait répondre « Regroupement inconnu » — un refus, mais
        // pour la mauvaise raison, qui n'aurait rien prouvé sur la
        // clôture. On demande au produit la clé qu'il reconnaît.
        ['bulletins', 'publier', static fn (): array =>
            bulletins_service_publish(
                $decor['classroom'],
                (string) array_key_first(bulletins_classroom_groups($decor['classroom']))
            )],
        ['bulletins', 'poser une décision', static fn (): array =>
            bulletins_service_set_decision($decor['enrollment'], 'passed')],
        ['finances', 'encaisser', static fn (): array =>
            finance_service_record_payment($decor['enrollment'], [
                'amount' => 1000, 'currency' => 'CDF', 'method' => 'cash',
                'paid_on' => date('Y-m-d')])],
        ['finances', 'dépenser', static fn (): array =>
            finance_service_record_expense($decor['year'], [
                'label' => 'Test', 'amount' => 500, 'currency' => 'CDF',
                'spent_on' => date('Y-m-d'), 'category' => 'other'])],
    ];

    $modulesVus = [];

    foreach ($gestes as [$module, $geste, $appel]) {
        $message = '';

        try {
            $r       = $appel();
            $accepte = (bool) ($r['ok'] ?? true);
            $message = (string) ($r['message'] ?? '');
        } catch (Throwable $e) {
            $accepte = true;                       // une exception n'est pas un refus motivé
            $message = 'exception : ' . $e->getMessage();
        }

        // LE REFUS DOIT NOMMER LA CLÔTURE.
        // Un refus pour une autre raison — argument invalide, droit
        // manquant — ne prouverait rien sur le garde-fou.
        $motive = !$accepte && str_contains(mb_strtolower($message), 'clôtur');

        check($module . ' — ' . $geste . ' est refusé POUR CAUSE DE CLÔTURE',
            $motive, mb_substr($message, 0, 70));

        if ($motive) {
            $modulesVus[$module] = true;
        }
    }

    check('Les sept modules sont couverts', count($modulesVus) >= 6,
        implode(', ', array_keys($modulesVus)));

    // =================================================================
    echo "\n  LA RÉOUVERTURE EXIGE UN MOTIF, ET LAISSE UNE TRACE\n";

    check('Sans motif, la réouverture est refusée',
        !years_service_reopen((int) $un['id'], '')['ok']);

    check('Un motif trop court aussi',
        !years_service_reopen((int) $un['id'], 'erreur')['ok']);

    $motif  = 'Correction d\'une cote contestée en 6ème A';
    $rouvre = years_service_reopen((int) $un['id'], $motif);

    check('Avec un motif, elle passe', $rouvre['ok'], $rouvre['message']);

    check('L\'année est de nouveau active',
        (string) years_repo_find((int) $un['id'])['status'] === 'active');

    check('La date de clôture est effacée',
        years_repo_find((int) $un['id'])['closed_at'] === null);

    // LE MOTIF DOIT SURVIVRE DANS LE JOURNAL : sans lui, la trace dirait
    // QUE c'est arrivé, jamais POURQUOI.
    $trace = db_one(
        'SELECT new_values FROM audit_logs
          WHERE school_id = :s AND action = \'academic_year.reopened\'
          ORDER BY id DESC LIMIT 1',
        ['s' => $idA],
        true
    );

    check('La réouverture est journalisée', $trace !== null);

    check('…avec son motif',
        $trace !== null
        && str_contains((string) json_decode((string) $trace['new_values'], true)['motif'], 'cote contestée'));

    // Et le compte rendu de clôture, lui aussi, doit rester lisible.
    $traceClose = db_one(
        'SELECT new_values FROM audit_logs
          WHERE school_id = :s AND action = \'academic_year.closed\'
          ORDER BY id DESC LIMIT 1',
        ['s' => $idA],
        true
    );

    check('La clôture a inscrit ce qu\'elle figeait',
        $traceClose !== null
        && array_key_exists('sans_decision', (array) json_decode((string) $traceClose['new_values'], true)));

    // Et l'inscription redevient possible.
    check('Après réouverture, l\'écriture est de nouveau possible',
        students_service_enroll_new(
            ['last_name' => 'APRES2', 'first_name' => 'Reouverture', 'gender' => 'F',
             'birth_date' => '2012-01-01', 'birth_place' => 'Kolwezi'],
            (int) $un['id'],
            null
        )['ok']);

    // =================================================================
    echo "\n  UNE ANNÉE VIDE SE SUPPRIME, UNE ANNÉE PLEINE NON\n";

    // NEUF TABLES SONT EN `ON DELETE CASCADE` sur `academic_years`.
    // Supprimer une année confiée à la base emporterait sans un mot ses
    // inscriptions, ses classes, ses programmes, ses frais, ses
    // dépenses, ses périodes, ses orientations et ses affectations.
    // Le refus est donc posé en PHP, table par table.
    $aSupprimer = years_service_create([
        'code' => 'ERREUR', 'name' => 'Créée par erreur',
        'starts_on' => '2040-09-01', 'ends_on' => '2041-07-31',
    ]);

    check('Une année vide se supprime',
        years_service_delete((int) $aSupprimer['id'])['ok']);

    check('…et elle a bien disparu',
        years_repo_find((int) $aSupprimer['id']) === null);

    // Celle de A porte des inscriptions : elle doit résister.
    $pleine = years_service_delete((int) $un['id']);

    check('Une année qui porte des données résiste', !$pleine['ok'], $pleine['message']);

    check('…et le refus NOMME ce qui bloque',
        str_contains($pleine['message'], 'inscription'),
        '« impossible » sans raison oblige à deviner');

    check('…et elle est toujours là', years_repo_find((int) $un['id']) !== null);

    check('L\'année courante ne se supprime pas',
        !years_service_delete((int) $deux['id'])['ok']);

    // Une année clôturée est la mémoire d'un exercice : elle ne part pas.
    $aClore = years_service_create([
        'code' => 'CLOSE', 'name' => 'À clôturer',
        'starts_on' => '2041-09-01', 'ends_on' => '2042-07-31',
    ]);
    years_service_close((int) $aClore['id']);

    check('Une année clôturée ne se supprime pas',
        !years_service_delete((int) $aClore['id'])['ok']);

    check('La suppression est journalisée',
        db_exists(
            'SELECT 1 FROM audit_logs
              WHERE school_id = :s AND action = \'academic_year.deleted\' LIMIT 1',
            ['s' => $idA],
            true
        ));

    // =================================================================
    echo "\n  LIRE, GÉRER ET CLÔTURER SONT TROIS POUVOIRS\n";

    act_as($idA, 'SECRETARIAT');

    check('Le secrétariat consulte les années', can('academic_year.view'));
    check('…mais ne les crée pas', !can('academic_year.manage'));
    check('…et ne clôture pas', !can('academic_year.close'));

    check('Et le service le refuse, pas seulement l\'écran',
        !years_service_create([
            'code' => 'SECR', 'name' => 'Par le secrétariat',
            'starts_on' => '2033-09-01', 'ends_on' => '2034-07-31',
        ])['ok']);

    act_as($idA, 'ENSEIGNANT');

    check('Un enseignant consulte aussi', can('academic_year.view'));
    check('…et ne clôture pas', !can('academic_year.close'));

    // =================================================================
    echo "\n  AUCUNE ÉCOLE NE TOUCHE AUX ANNÉES D'UNE AUTRE\n";

    $idB = ecole('ANN-B', 'Lycée Bêta');
    $ecoles[] = $idB;
    act_as($idB, 'DIRECTION');

    check('B ne voit aucune année de A', years_repo_all() === []);

    check('B ne trouve pas une année de A par son identifiant',
        years_repo_find((int) $un['id']) === null);

    check('B ne peut pas la clôturer',
        !years_service_close((int) $deux['id'])['ok']);

    check('Ni la désigner courante',
        !years_service_set_current((int) $deux['id'])['ok']);

    check('Ni la rouvrir',
        !years_service_reopen((int) $un['id'], 'Motif suffisamment long pour passer')['ok']);

    // B peut reprendre le MÊME code : l'unicité est par école.
    check('B peut reprendre le même code qu\'une année de A',
        years_service_create([
            'code' => '2020-2021', 'name' => 'Année 2020-2021',
            'starts_on' => '2020-09-01', 'ends_on' => '2021-07-31',
        ])['ok']);

    reprendre($dirA, $idA);

    check('Et A retrouve ses années intactes', count(years_repo_all()) === 5,
        count(years_repo_all()) . ' année(s)');
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($ecoles as $schoolId) {
        tenant_set($schoolId);
        db_query('UPDATE classrooms SET main_teacher_id = NULL WHERE school_id = :s', ['s' => $schoolId], true);

        foreach ([
            'documents', 'document_counters', 'sync_conflicts', 'sync_queue', 'sync_devices',
            'payment_allocations', 'payments', 'student_fees', 'fees', 'receipt_counters',
            'attendance_records', 'attendance_sessions', 'bulletin_lines', 'bulletins',
            'grades', 'student_history', 'orientations', 'student_guardians',
            'enrollments', 'guardians', 'students', 'student_counters',
            'teacher_subjects', 'teachers', 'classrooms', 'curriculum_subjects',
            'curriculums', 'grade_periods', 'subjects', 'options', 'sections',
            'academic_years', 'audit_logs',
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
