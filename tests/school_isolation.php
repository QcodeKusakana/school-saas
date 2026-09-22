<?php
/**
 * Phase 9A — établissement : logo, mentions imprimées, isolation.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · le téléversement REFUSE ce qui n'est pas une image, quel que soit
 *    le nom du fichier — un script PHP renommé `.jpg` est refusé sur
 *    son CONTENU ;
 *  · aucune école ne modifie ni ne lit le logo ou les réglages d'une
 *    autre ;
 *  · une photo d'élève et un logo ne sortent jamais du dossier de
 *    téléversement, même avec un chemin forgé en base ;
 *  · les documents déjà délivrés ne changent pas quand l'école change
 *    d'adresse ou de logo ;
 *  · `school.branding` est exigée, et distincte de `school.edit`.
 *
 * Usage : php tests/school_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_PATH . '/modules/curriculum/services.php';
require_once APP_PATH . '/modules/students/services.php';
require_once APP_PATH . '/modules/documents/services.php';
require_once APP_PATH . '/modules/school/services.php';
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
        'username'      => 'eco.' . strtolower($roleCode) . '.' . $counter,
        'email'         => 'eco' . $counter . '@example.test',
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
 * Soumet un contenu au VRAI contrôle du produit.
 *
 * `upload_store()` complet exige `is_uploaded_file()`, qui ne vaut que
 * pendant une requête HTTP : on ne peut donc pas l'appeler ici. Mais
 * tout ce qui décide d'accepter ou de refuser — taille, type réel,
 * cohérence extension/contenu, image décodable — vit dans
 * `upload_inspect()`, et c'est CETTE fonction, celle du produit, que
 * l'on exécute. Pas une copie écrite pour le test.
 *
 * Le parcours HTTP complet est éprouvé en navigateur
 * (tests/photo_carte_browser.js).
 *
 * @return array{ok: bool, mime?: string, extension?: string, error?: string}
 */
function soumettre(string $octets, string $nomClient, string $kind = 'image'): array
{
    $chemin = tempnam(sys_get_temp_dir(), 'epr');
    file_put_contents($chemin, $octets);

    $verdict = upload_inspect($chemin, $nomClient, $kind);

    @unlink($chemin);

    return $verdict;
}

/** Un PNG minimal mais authentique. */
function png_valide(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAF0lEQVQI12P8//8/'
        . 'AzbAxMDAwMDAwAAAIAUDAUn6iHAAAAAASUVORK5CYII='
    );
}

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
        'code' => '2095-2096', 'name' => 'Test école', 'starts_on' => '2095-09-01',
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
        ['last_name' => 'ILUNGA', 'first_name' => 'Merveille', 'gender' => 'F',
         'birth_date' => '2011-03-08', 'birth_place' => 'Lubumbashi'],
        $yearId,
        $classroom
    );

    return [
        'id' => $schoolId, 'year' => $yearId, 'classroom' => $classroom,
        'student' => (int) $eleve['id'],
        'enrollment' => (int) students_repo_enrollment((int) $eleve['id'], $yearId)['id'],
        'direction' => (int) $_SESSION['user_id'],
    ];
}

echo "\n  ÉTABLISSEMENT — LOGO, MENTIONS IMPRIMÉES, ISOLATION\n";
echo "  ──────────────────────────────────────────────────────\n\n";

$createdSchools = [];

try {
    $A = ecole('ECO-A', 'Institut Alpha');
    $createdSchools[] = $A['id'];

    // =================================================================
    echo "  CE QUE LE TÉLÉVERSEMENT ACCEPTE\n";

    check('Un PNG authentique est accepté', soumettre(png_valide(), 'logo.png')['ok']);

    // LE CAS QUI COMPTE : un script renommé en image.
    //
    // Le contrôle porte sur le CONTENU, jamais sur l'extension ni sur
    // `$_FILES['type']`, que le client fournit et peut mentir.
    $php = soumettre('<?php system($_GET["c"]); ?>', 'photo.jpg');

    check('Un script PHP renommé « .jpg » est REFUSÉ', !$php['ok'], $php['error'] ?? '');

    check('Un script renommé « .png » l\'est aussi',
        !soumettre("<?php echo 'x';", 'logo.png')['ok']);

    // LE POLYGLOTTE : un PNG authentique auquel on a ajouté du PHP.
    // Le type reste image/png, l'extension concorde, et `getimagesize`
    // lit bien l'en-tête. Le fichier EST donc accepté.
    //
    // Ce n'est pas une faille tant que trois choses tiennent, et ce sont
    // elles que l'on vérifie ensuite : le dépôt est hors racine web, le
    // nom est régénéré, et le fichier n'est jamais inclus — seulement
    // lu et renvoyé avec un type d'image.
    $hybride = soumettre(png_valide() . '<?php system($_GET["c"]); ?>', 'photo.png');

    check('Un PNG porteur de code passe le contrôle de type', $hybride['ok'],
        'accepté — ce qui le rend inoffensif est vérifié ci-dessous');

    check('…mais le dépôt est HORS de la racine web',
        !str_starts_with(realpath(storage_path('uploads')) ?: '/x',
            realpath(BASE_PATH . '/public') ?: '/y'),
        storage_path('uploads'));

    check('…et aucune route n\'inclut un fichier du dépôt',
        preg_grep('/\b(include|require)(_once)?\s*\(?\s*\$?\w*storage_path/',
            array_map('file_get_contents',
                glob(APP_PATH . '/modules/*/*.php') ?: [])) === []);

    check('Un SVG est refusé — il peut porter du script',
        !soumettre('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg')['ok']);

    check('Un PDF est refusé là où une image est attendue',
        !soumettre("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>", 'doc.pdf', 'image')['ok']);

    // L'extension, elle, ne suffit jamais : un PNG authentique renommé
    // « .jpg » est refusé, car le contenu et l'extension divergent.
    check('Un PNG renommé « .jpg » est refusé (incohérence)',
        !soumettre(png_valide(), 'photo.jpg')['ok']);

    check('Un fichier trop lourd est refusé',
        !soumettre(png_valide() . str_repeat("\0", (int) config('uploads.max_size') + 1),
            'lourd.png')['ok']);

    check('Un fichier vide est refusé', !soumettre('', 'vide.png')['ok']);

    // =================================================================
    echo "\n  LES MENTIONS IMPRIMÉES\n";

    $ok = school_service_save_settings([
        'school_city'      => 'Lubumbashi',
        'school_address'   => '45, avenue Kasaï, C. Lubumbashi',
        'school_phone'     => '+243 990 000 000',
        'school_email'     => 'contact@alpha.cd',
        'school_authority' => 'RDC · Ministère de l\'EPST',
        'school_motto'     => 'Savoir et Service',
    ]);

    check('Les mentions s\'enregistrent', $ok['ok'], $ok['message']);

    check('Et se relisent', school_repo_print_settings()['school.city'] === 'Lubumbashi');

    check('Un courriel invalide est refusé',
        !school_service_save_settings(['school_email' => 'pas-une-adresse'])['ok']);

    check('Une valeur trop longue est refusée',
        !school_service_save_settings(['school_city' => str_repeat('x', 200)])['ok']);

    // CE QUE DEUX REFUS NE DOIVENT PAS AVOIR FAIT.
    //
    // La première version écrivait dans la boucle de validation : ces
    // deux refus avaient vidé la ville, l'adresse et la tutelle avant
    // d'annoncer leur échec.
    check('Un refus n\'a RIEN écrit',
        school_repo_print_settings()['school.city'] === 'Lubumbashi'
        && school_repo_print_settings()['school.authority'] !== '',
        school_repo_print_settings()['school.city']);

    // Un champ ABSENT n'est pas un champ vidé.
    check('Enregistrer un seul champ n\'efface pas les autres',
        school_service_save_settings(['school_phone' => '+243 810 000 000'])['ok']
        && school_repo_print_settings()['school.city'] === 'Lubumbashi'
        && school_repo_print_settings()['school.address'] !== '',
        school_repo_print_settings()['school.address']);

    // Mais un champ ENVOYÉ VIDE doit bien vider.
    check('Un champ envoyé vide est bien vidé',
        school_service_save_settings(['school_motto' => ''])['ok']
        && school_repo_print_settings()['school.motto'] === '');

    // =================================================================
    echo "\n  LE FIGEAGE RÉSISTE AU CHANGEMENT D'IDENTITÉ\n";

    $doc = document_service_issue($A['enrollment'], 'attestation_frequentation');

    check('Une attestation se délivre', $doc['ok'], $doc['message']);

    $avant = document_repo_find((int) $doc['id'])['data'];

    check('Elle porte la ville du jour', (string) $avant['school']['city'] === 'Lubumbashi');
    check('Et la tutelle du jour', str_contains((string) $avant['school']['authority'], 'EPST'));

    // L'école déménage et change de tutelle APRÈS la délivrance.
    school_service_save_settings([
        'school_city'      => 'Kolwezi',
        'school_address'   => 'Nouvelle adresse',
        'school_authority' => 'Autre tutelle',
    ]);

    $apres = document_repo_find((int) $doc['id'])['data'];

    check('Déménager NE CHANGE PAS l\'attestation déjà délivrée',
        (string) $apres['school']['city'] === 'Lubumbashi',
        (string) $apres['school']['city']);

    check('Changer de tutelle non plus',
        str_contains((string) $apres['school']['authority'], 'EPST'));

    // =================================================================
    echo "\n  LE CHEMIN DU LOGO NE SORT PAS DU DÉPÔT\n";

    // On force un chemin forgé en base — ce qu'aucun écran ne permet,
    // mais qu'une restauration hasardeuse ou une main malveillante
    // pourrait produire.
    db_query('UPDATE schools SET logo_path = :p WHERE id = :id',
        ['p' => '../../app/config/config.local.php', 'id' => $A['id']], true);

    check('Un chemin remontant est refusé, pas servi',
        (school_service_logo_file()['ok'] ?? true) === false);

    db_query('UPDATE schools SET logo_path = NULL WHERE id = :id', ['id' => $A['id']], true);

    check('Sans logo, la route ne rend rien',
        (school_service_logo_file()['ok'] ?? true) === false);

    // Même contrôle côté photo d'élève.
    db_query('UPDATE students SET photo_path = :p WHERE id = :id AND school_id = :s',
        ['p' => '../../../etc/passwd', 'id' => $A['student'], 's' => $A['id']], true);

    check('Une photo au chemin remontant est refusée',
        (students_service_photo_file($A['student'])['ok'] ?? true) === false);

    db_query('UPDATE students SET photo_path = NULL WHERE id = :id AND school_id = :s',
        ['id' => $A['student'], 's' => $A['id']], true);

    // =================================================================
    echo "\n  LES DROITS\n";

    act_as($A['id'], 'SECRETARIAT');

    check('Le secrétariat ne modifie pas l\'identité de l\'école',
        !can('school.branding')
        && !school_service_save_settings(['school_city' => 'Tentative'])['ok']);

    check('Ni le logo', !school_service_set_logo([])['ok']);

    check('Et la ville n\'a pas bougé',
        (string) school_repo_print_settings()['school.city'] === 'Kolwezi');

    act_as($A['id'], 'ENSEIGNANT');

    check('Un enseignant non plus', !can('school.branding'));

    // =================================================================
    echo "\n  AUCUNE ÉCOLE NE TOUCHE À L'IDENTITÉ D'UNE AUTRE\n";

    $B = ecole('ECO-B', 'Lycée Bêta');
    $createdSchools[] = $B['id'];

    check('B ne voit pas les mentions de A',
        school_repo_print_settings()['school.city'] === '',
        school_repo_print_settings()['school.city']);

    school_service_save_settings(['school_city' => 'Goma']);

    reprendre($A['direction'], $A['id']);

    check('Et enregistrer chez B ne touche pas A',
        (string) school_repo_print_settings()['school.city'] === 'Kolwezi',
        (string) school_repo_print_settings()['school.city']);

    // Le logo : chacun le sien.
    db_query('UPDATE schools SET logo_path = :p WHERE id = :id',
        ['p' => 'logos/' . $A['id'] . '/a.png', 'id' => $A['id']], true);

    reprendre($B['direction'], $B['id']);

    check('B ne lit pas le chemin du logo de A',
        school_repo_logo_path() === null);

    check('Et son établissement courant est bien le sien',
        (int) school_repo_current()['id'] === $B['id']);

    // Retirer son logo depuis B ne doit pas effacer celui de A.
    school_service_remove_logo();

    check('Retirer son logo chez B laisse celui de A intact',
        (string) platform_scope_cli(static fn (): string => (string) db_value(
            'SELECT logo_path FROM schools WHERE id = :id', ['id' => $A['id']], true
        )) === 'logos/' . $A['id'] . '/a.png');

    db_query('UPDATE schools SET logo_path = NULL WHERE id = :id', ['id' => $A['id']], true);

    // Une photo d'élève de A, demandée depuis B.
    check('B ne sert pas la photo d\'un élève de A',
        (students_service_photo_file($A['student'])['ok'] ?? true) === false);
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($createdSchools as $schoolId) {
        tenant_set($schoolId);
        db_query('UPDATE classrooms SET main_teacher_id = NULL WHERE school_id = :s', ['s' => $schoolId], true);

        foreach ([
            'documents', 'document_counters',
            'sync_conflicts', 'sync_queue', 'sync_devices',
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
