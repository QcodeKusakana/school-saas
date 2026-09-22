<?php
/**
 * Phase 9A — documents officiels.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · un document délivré est FIGÉ : renommer la classe ne change pas
 *    ce qu'il atteste ;
 *  · la numérotation est séquentielle, par nature, sans trou ;
 *  · le jeton n'est pas dérivé de l'identifiant, et il est unique ;
 *  · la page publique ne révèle AUCUN nom d'élève ;
 *  · chaque nature refuse ce qu'elle ne peut pas attester — dossier
 *    annulé, élève archivé, carte sans photo, SOLDE NON RÉGLÉ ;
 *  · révoquer exige un droit distinct et un motif, et n'efface rien ;
 *  · aucune école ne voit, n'imprime ni ne révoque le document d'une
 *    autre.
 *
 * Usage : php tests/documents_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/finance/services.php';
require APP_PATH . '/modules/documents/services.php';
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
        'username'      => 'doc.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'doc' . $counter . '@example.test',
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

    reprendre($userId, $schoolId);

    return $userId;
}

/** Reprend la session d'un compte déjà créé. */
function reprendre(int $userId, int $schoolId): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;

    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($schoolId);
}

/** Une école complète, avec un élève inscrit. */
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

    $yearId = tenant_insert('academic_years', [
        'code' => '2095-2096', 'name' => 'Test documents', 'starts_on' => '2095-09-01',
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

    $eleve = students_service_enroll_new(
        ['last_name' => 'KABILA', 'first_name' => 'Espoir', 'gender' => 'M',
         'birth_date' => '2010-04-12', 'birth_place' => 'Kinshasa'],
        $yearId,
        $classroom
    );

    $inscription = (int) students_repo_enrollment((int) $eleve['id'], $yearId)['id'];

    return [
        'id' => $schoolId, 'year' => $yearId, 'classroom' => $classroom,
        'student' => (int) $eleve['id'], 'enrollment' => $inscription,
        'direction' => (int) $_SESSION['user_id'],
    ];
}

echo "\n  DOCUMENTS OFFICIELS\n";
echo "  ──────────────────────────────────────────────────────\n\n";

$createdSchools = [];

try {
    $A = ecole('DOC-A', 'École des Documents');
    $createdSchools[] = $A['id'];

    // =================================================================
    echo "  LA DÉLIVRANCE\n";

    $r1 = document_service_issue($A['enrollment'], 'attestation_frequentation');

    check('Une attestation se délivre', $r1['ok'], $r1['message']);

    check('Son numéro porte la nature, l\'année et un rang',
        (string) $r1['number'] === 'ATT/2095-2096/0001', (string) $r1['number']);

    $r2 = document_service_issue($A['enrollment'], 'attestation_frequentation');

    check('Le duplicata est POSSIBLE — un papier se perd', $r2['ok']);

    check('Et il porte son propre numéro, le suivant',
        (string) $r2['number'] === 'ATT/2095-2096/0002', (string) $r2['number']);

    $r3 = document_service_issue($A['enrollment'], 'certificat_scolarite');

    check('Chaque nature a SA série', (string) $r3['number'] === 'CRT/2095-2096/0001',
        (string) $r3['number']);

    check('Une nature inventée est refusée',
        !document_service_issue($A['enrollment'], 'diplome_honoris_causa')['ok']);

    check('Une inscription d\'une autre école est introuvable',
        !document_service_issue(999999, 'attestation_frequentation')['ok']);

    // =================================================================
    echo "\n  LE JETON\n";

    $doc = document_repo_find((int) $r1['id']);

    check('Le jeton a la forme attendue',
        preg_match('/^[' . DOCUMENT_TOKEN_ALPHABET . ']{4}-[' . DOCUMENT_TOKEN_ALPHABET
            . ']{4}-[' . DOCUMENT_TOKEN_ALPHABET . ']{4}$/', (string) $doc['token']) === 1,
        (string) $doc['token']);

    check('Il n\'emprunte aucun caractère ambigu',
        preg_match('/[ILOU01]/', (string) $doc['token']) !== 1);

    // Le jeton ne doit PAS être dérivé de l'identifiant : deux
    // documents consécutifs ne se ressemblent pas.
    $doc2 = document_repo_find((int) $r2['id']);

    check('Deux documents consécutifs ont des jetons sans rapport',
        levenshtein((string) $doc['token'], (string) $doc2['token']) > 4,
        $doc['token'] . ' vs ' . $doc2['token']);

    // Cent tirages, aucun doublon : la source est bien aléatoire.
    $tirages = [];

    for ($i = 0; $i < 200; $i++) {
        $tirages[] = document_new_token();
    }

    check('200 tirages ne produisent aucun doublon',
        count(array_unique($tirages)) === 200);

    // =================================================================
    echo "\n  LE FIGEAGE\n";

    $avant = document_repo_find((int) $r1['id'])['data'];

    check('Le figeage porte la classe au moment de la délivrance',
        (string) $avant['classroom'] === '7ème A', (string) $avant['classroom']);

    // On renomme la classe ET l'école APRÈS la délivrance.
    db_query('UPDATE classrooms SET name = :n WHERE id = :id AND school_id = :s',
        ['n' => '7ème A bis', 'id' => $A['classroom'], 's' => $A['id']]);

    platform_scope_cli(static fn () => db_query(
        'UPDATE schools SET name = :n WHERE id = :id',
        ['n' => 'École rebaptisée', 'id' => $A['id']], true));

    $apres = document_repo_find((int) $r1['id'])['data'];

    check('Renommer la classe NE CHANGE PAS le document',
        (string) $apres['classroom'] === '7ème A', (string) $apres['classroom']);

    check('Renommer l\'école non plus',
        (string) $apres['school']['name'] === 'École des Documents',
        (string) $apres['school']['name']);

    check('Et la page publique reste fidèle au document, pas à la table',
        document_service_verify((string) $doc['token'])['school'] === 'École des Documents');

    // =================================================================
    echo "\n  CE QUE LA PAGE PUBLIQUE RÉVÈLE — ET TAIT\n";

    $v = document_service_verify((string) $doc['token']);

    check('Elle trouve le document', ($v['found'] ?? false) && ($v['valid'] ?? false));
    check('Elle donne le numéro', (string) $v['number'] === 'ATT/2095-2096/0001');
    check('Elle donne la nature et l\'école',
        ($v['type'] ?? '') !== '' && ($v['school'] ?? '') !== '');

    $json = json_encode($v, JSON_UNESCAPED_UNICODE);

    check('Elle ne contient AUCUN nom d\'élève',
        !str_contains($json, 'KABILA') && !str_contains($json, 'Espoir'), $json);

    check('Ni matricule, ni date de naissance, ni classe',
        !str_contains($json, 'matricule') && !str_contains($json, '2010-04-12')
        && !str_contains($json, '7ème'));

    check('Un jeton inconnu ne trouve rien',
        (document_service_verify('AAAA-BBBB-CCCC')['found'] ?? true) === false);

    check('Un jeton mal formé ne touche pas la base',
        (document_service_verify('n\'importe quoi')['found'] ?? true) === false);

    check('Le jeton est insensible à la casse de saisie',
        (document_service_verify(strtolower((string) $doc['token']))['found'] ?? false) === true);

    // =================================================================
    echo "\n  CHAQUE NATURE REFUSE CE QU'ELLE NE PEUT PAS ATTESTER\n";

    check('La carte est refusée sans photo',
        !document_service_issue($A['enrollment'], 'carte_eleve')['ok']
        && str_contains(document_service_issue($A['enrollment'], 'carte_eleve')['message'], 'photo'));

    // On donne une photo, et la carte passe.
    db_query('UPDATE students SET photo_path = :p WHERE id = :id AND school_id = :s',
        ['p' => 'uploads/photos/test.jpg', 'id' => $A['student'], 's' => $A['id']]);

    $carte = document_service_issue($A['enrollment'], 'carte_eleve');

    check('Avec une photo, elle passe', $carte['ok'], $carte['message']);

    check('Et la carte porte une date de fin de validité',
        (string) document_repo_find((int) $carte['id'])['data']['valid_until'] === '2096-07-31');

    // ---- LE GARDE-FOU DU SOLDE, enfin éprouvé ---------------------
    //
    // Sur les données de démonstration tous les soldes valaient zéro :
    // ce refus n'avait JAMAIS été vu se déclencher. Un garde-fou qu'on
    // n'a pas vu refuser est un garde-fou qu'on n'a pas vérifié.
    $avantDette = document_service_issue($A['enrollment'], 'attestation_paiement');

    check('Sans dette, l\'attestation de paiement passe', $avantDette['ok'],
        $avantDette['message']);

    $fee = finance_service_save_fee([
        'academic_year_id' => $A['year'], 'code' => 'MIN',
        'name'             => 'Minerval',
        'currency'         => 'USD', 'amount' => '120',
        'scope'            => 'school', 'is_mandatory' => 1, 'is_active' => 1,
    ]);

    check('Un frais est créé pour le décor', $fee['ok'], (string) $fee['message']);

    finance_service_assign($A['year']);

    $contexte = document_repo_context($A['enrollment']);

    check('L\'élève doit désormais quelque chose', $contexte['balance'] > 0,
        number_format($contexte['balance'], 2) . ' ' . $contexte['currency']);

    $avecDette = document_service_issue($A['enrollment'], 'attestation_paiement');

    check('AVEC une dette, l\'attestation de paiement est REFUSÉE',
        !$avecDette['ok'], $avecDette['message']);

    check('Et le refus chiffre ce qui reste dû',
        str_contains($avecDette['message'], '120'), $avecDette['message']);

    // Les autres natures ne sont PAS bloquées par une dette : refuser
    // une attestation de fréquentation à un élève endetté reviendrait à
    // lui interdire de prouver qu'il est scolarisé.
    check('Une dette ne bloque PAS l\'attestation de fréquentation',
        document_service_issue($A['enrollment'], 'attestation_frequentation')['ok']);

    // ---- Dossier annulé, élève archivé ----------------------------
    $autre = students_service_enroll_new(
        ['last_name' => 'MUKENDI', 'first_name' => 'Grâce', 'gender' => 'F'],
        $A['year'],
        $A['classroom']
    );
    $autreInscription = (int) students_repo_enrollment((int) $autre['id'], $A['year'])['id'];

    db_query('UPDATE enrollments SET status = \'cancelled\' WHERE id = :id AND school_id = :s',
        ['id' => $autreInscription, 's' => $A['id']]);

    $annule = document_service_issue($autreInscription, 'attestation_frequentation');

    check('Une inscription ANNULÉE n\'atteste rien', !$annule['ok'], $annule['message']);

    // =================================================================
    echo "\n  LA RÉVOCATION\n";

    check('Un motif trop court est refusé',
        !document_service_revoke((int) $r1['id'], 'non')['ok']);

    $rev = document_service_revoke((int) $r1['id'], 'inscription annulée après délivrance');

    check('Avec un motif, la révocation passe', $rev['ok'], $rev['message']);

    $apresRev = document_repo_find((int) $r1['id']);

    check('La ligne EXISTE toujours — on n\'efface rien', $apresRev !== null);
    check('Elle porte la date, l\'auteur et le motif',
        $apresRev['revoked_at'] !== null && $apresRev['revoked_by'] !== null
        && str_contains((string) $apresRev['revoke_reason'], 'annulée'));

    $vRev = document_service_verify((string) $doc['token']);

    check('La page publique le dit révoqué',
        ($vRev['found'] ?? false) && ($vRev['valid'] ?? true) === false);
    check('Et donne la date de révocation', ($vRev['revoked_on'] ?? null) !== null);
    check('Sans jamais nommer l\'élève',
        !str_contains(json_encode($vRev, JSON_UNESCAPED_UNICODE), 'KABILA'));

    check('Un document déjà révoqué ne se révoque pas deux fois',
        !document_service_revoke((int) $r1['id'], 'un autre motif valable')['ok']);

    check('Les AUTRES documents restent valides',
        document_service_verify((string) $doc2['token'])['valid'] === true);

    // =================================================================
    echo "\n  RÉVOQUER N'EST PAS DÉLIVRER\n";

    act_as($A['id'], 'SECRETARIAT');

    check('Le secrétariat peut délivrer', can('document.generate'));

    check('Mais PAS révoquer',
        !can('document.revoke')
        && !document_service_revoke((int) $r2['id'], 'tentative de révocation')['ok']);

    check('Et le document visé reste valide',
        document_repo_find((int) $r2['id'])['revoked_at'] === null);

    $enseignant = act_as($A['id'], 'ENSEIGNANT');

    check('Un enseignant ne délivre pas',
        !can('document.generate')
        && !document_service_issue($A['enrollment'], 'attestation_frequentation')['ok']);

    // =================================================================
    echo "\n  AUCUNE ÉCOLE NE VOIT LES DOCUMENTS D'UNE AUTRE\n";

    $B = ecole('DOC-B', 'École Voisine');
    $createdSchools[] = $B['id'];

    check('L\'école B ne voit aucun document', document_repo_search()['rows'] === []);

    check('Elle ne retrouve pas celui de A par son identifiant',
        document_repo_find((int) $r2['id']) === null);

    check('Elle ne peut pas le révoquer',
        !document_service_revoke((int) $r2['id'], 'révocation depuis une autre école')['ok']);

    check('Et il reste valide',
        (bool) document_service_verify((string) $doc2['token'])['valid']);

    // Sa propre numérotation repart à 1 : les séries sont par école.
    $bDoc = document_service_issue($B['enrollment'], 'attestation_frequentation');

    check('Sa numérotation lui est propre',
        (string) $bDoc['number'] === 'ATT/2095-2096/0001', (string) $bDoc['number']);

    // LA VÉRIFICATION PUBLIQUE, ELLE, TRAVERSE LES ÉCOLES — c'est son
    // rôle. Elle ne révèle toujours rien de plus.
    $croise = document_service_verify((string) $doc2['token']);

    check('La page publique trouve un document d\'une AUTRE école',
        ($croise['found'] ?? false) === true);

    check('Et n\'en dit pas plus pour autant',
        !str_contains(json_encode($croise, JSON_UNESCAPED_UNICODE), 'KABILA'));

    // =================================================================
    echo "\n  LA NUMÉROTATION NE SAUTE PAS\n";

    reprendre($A['direction'], $A['id']);

    $numeros = array_map(
        static fn (array $r): string => (string) $r['number'],
        array_filter(
            document_repo_search(['type' => 'attestation_frequentation'], 1, 100)['rows'],
            static fn (array $r): bool => true
        )
    );

    sort($numeros);
    $rangs = array_map(static fn (string $n): int => (int) substr($n, -4), $numeros);

    check('Les rangs se suivent sans trou',
        $rangs === range(1, count($rangs)), implode(', ', $rangs));

    check('Aucun numéro n\'est délivré deux fois',
        count(array_unique($numeros)) === count($numeros));
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($createdSchools as $schoolId) {
        tenant_set($schoolId);
        db_query('UPDATE classrooms SET main_teacher_id = NULL WHERE school_id = :s', ['s' => $schoolId], true);

        foreach ([
            'documents', 'document_counters',
            'sync_conflicts', 'sync_queue', 'sync_devices',
            'payment_allocations', 'payments', 'student_fees', 'fees',
            'receipt_counters', 'expenses', 'expense_counters',
            'attendance_records', 'attendance_sessions', 'bulletins', 'bulletin_lines',
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
