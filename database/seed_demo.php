<?php
/**
 * Jeu de démonstration — OUTIL DE DÉVELOPPEMENT, à supprimer avant la mise
 * en production.
 *
 *   php database/seed_demo.php
 *   php database/seed_demo.php --reset   (efface le jeu et le recrée)
 *
 * POURQUOI CE SCRIPT
 * ------------------
 * Les tests automatisés prouvent que le code est juste, mais ils nettoient
 * tout derrière eux : après `php tests/...`, la base est vide et il n'y a
 * rien à regarder dans le navigateur. Recréer à la main une année, un
 * programme, une classe, des élèves et trois périodes de cotes prend une
 * demi-heure et se refait à chaque phase.
 *
 * Ce script produit une école complète et cohérente en une commande, de
 * quoi parcourir réellement l'application. Il est IDEMPOTENT : relancé, il
 * complète ce qui manque sans rien dupliquer.
 *
 * Il n'invente aucune donnée métier : il s'appuie sur les mêmes services
 * que l'application (inscription, programme, saisie des cotes, publication).
 * Une régression dans un service fait donc échouer ce script — c'est
 * volontaire.
 *
 * TROIS GARDE-FOUS
 * ----------------
 *  1. ligne de commande uniquement ;
 *  2. refus si app.debug est à false — un jeu de démonstration créé sur une
 *     base de production y mélangerait de faux élèves à de vrais ;
 *  3. --reset demande une confirmation explicite avant d'effacer.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script ne s'exécute qu'en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';
require APP_PATH . '/modules/students/services.php';
require APP_PATH . '/modules/teachers/services.php';
require APP_PATH . '/modules/grades/services.php';
require APP_PATH . '/modules/bulletins/services.php';

if (!config('app.debug')) {
    exit("\n  ✗ Refusé : app.debug est à false.\n"
        . "    Ce script ne doit jamais peupler une base de production.\n\n");
}

/**
 * UN SEUL ÉTABLISSEMENT DE DÉMONSTRATION, PARTAGÉ AVEC install.php --demo.
 *
 * Ce script utilisait au départ son propre code, « DEMO-001 ». Il ne
 * trouvait donc pas l'école créée par `install.php --demo` et tentait d'en
 * insérer une seconde — avec le MÊME slug, puisque les deux scripts avaient
 * choisi le même libellé. Résultat sur une base où l'installateur était
 * passé avec --demo : violation de la contrainte d'unicité du slug, et un
 * outil censé faire gagner du temps qui plantait à la première ligne.
 *
 * Le code et le slug sont désormais exactement ceux de l'installateur, et
 * l'école est retrouvée par SLUG autant que par code : c'est le slug qui
 * porte la contrainte d'unicité, donc lui qui décide de l'identité.
 */
const DEMO_SCHOOL_CODE = 'ECO-000001';
const DEMO_SCHOOL_SLUG = 'complexe-scolaire-demo';
const DEMO_PASSWORD    = 'Demo2026Ecole';

$options = getopt('', ['reset', 'help']);

if (isset($options['help'])) {
    echo <<<TXT

    Jeu de démonstration — School SaaS RDC
    --------------------------------------
      php database/seed_demo.php           Crée ou complète le jeu
      php database/seed_demo.php --reset   Efface le jeu puis le recrée

    TXT;
    exit(0);
}

echo "\n";
echo "  Jeu de démonstration — School SaaS RDC\n";
echo "  ──────────────────────────────────────────────\n\n";

// =====================================================================
//  RÉINITIALISATION
// =====================================================================
if (isset($options['reset'])) {
    $existing = demo_school();

    if ($existing === null) {
        echo "  → Aucun jeu de démonstration à effacer.\n\n";
    } else {
        echo "  ⚠  L'école « {$existing['name']} » et TOUTES ses données vont être effacées.\n";
        echo "     Les autres écoles ne sont pas touchées.\n";
        echo "     Confirmez en tapant DEMO : ";

        if (trim((string) fgets(STDIN)) !== 'DEMO') {
            exit("\n  ✗ Confirmation incorrecte. Aucune modification effectuée.\n\n");
        }

        demo_erase((int) $existing['id']);
        echo "\n  ✓ Jeu de démonstration effacé\n\n";
    }
}

// =====================================================================
//  ÉCOLE
// =====================================================================
$school = demo_school();

if ($school === null) {
    $schoolId = db_insert('schools', [
        'uuid'          => str_uuid(),
        'code'          => DEMO_SCHOOL_CODE,
        'slug'          => DEMO_SCHOOL_SLUG,
        'name'          => 'Complexe Scolaire de Démonstration',
        'short_name'    => 'CS Démo',
        'school_type'   => 'prive',
        'province'      => 'Kinshasa',
        'city'          => 'Kinshasa',
        'commune'       => 'Gombe',
        'address'       => 'Avenue de la Démonstration n°1',
        'phone'         => '+243810000000',
        'email'         => 'contact@demo.cd',
        'director_name' => 'MUKENDI Jean Pierre',
        'status'        => 'active',
    ], true);

    echo "  ✓ École créée : Complexe Scolaire de Démonstration\n";
} else {
    $schoolId = (int) $school['id'];
    echo "  → École de démonstration réutilisée : {$school['name']} (id {$schoolId})\n";
}

// Les cycles ne sont posés que si l'école n'en a aucun. Un INSERT IGNORE
// sur tout le catalogue ajouterait la maternelle à une école que
// l'installateur avait délibérément limitée au primaire, au CTEB et aux
// humanités : compléter un jeu de test ne doit pas modifier sa définition.
$hasCycles = (int) db_value(
    'SELECT COUNT(*) FROM school_cycles WHERE school_id = :s',
    ['s' => $schoolId],
    true
);

if ($hasCycles === 0) {
    db_query(
        'INSERT INTO school_cycles (school_id, cycle_id, is_active)
         SELECT :s, id, 1 FROM education_cycles
          WHERE code IN (\'PRIMAIRE\', \'CTEB\', \'HUMANITES\')',
        ['s' => $schoolId],
        true
    );

    echo "  ✓ Cycles activés : primaire, CTEB, humanités\n";
}

// =====================================================================
//  COMPTES
// =====================================================================
$adminId   = demo_user($schoolId, 'directeur.demo', 'KABAMBA', 'Thérèse', 'F', 'DIRECTION');
$teacherId = demo_user($schoolId, 'enseignant.demo', 'MUKENDI', 'Joseph', 'M', 'ENSEIGNANT');

// UN COMPTE ÉDITEUR — `school_id = NULL`.
//
// Il n'y en avait aucun, et toute la console de l'éditeur (parc,
// abonnements, soldes, journal global) était donc INVÉRIFIABLE en
// navigateur : les suites PHP la couvraient, aucune recette ne l'ouvrait.
//
//   > Un écran qu'aucun compte ne peut ouvrir n'est pas un écran testé,
//   > c'est un écran supposé.
//
// Comme le reste de ce fichier, ce compte est une donnée de DÉMONSTRATION :
// `seed_demo.php` est à supprimer avant la mise en service (voir la liste
// « Avant mise en production »).
demo_user(null, 'editeur.demo', 'NGOY', 'Patrick', 'M', 'SUPER_ADMIN');

// Toute la suite s'exécute AU NOM du directeur : les services vérifient les
// permissions, les exécuter sans identité échouerait.
$_SESSION['user_id']   = $adminId;
$_SESSION['school_id'] = $schoolId;
auth_user(true);
perm_all(true);
perm_roles(true);
tenant_set($schoolId);

// =====================================================================
//  ANNÉE SCOLAIRE ET RÉFÉRENTIEL
// =====================================================================
$start = (int) date('Y');
$yearCode = $start . '-' . ($start + 1);

// Par l'année COURANTE d'abord, puis par son code : une base où l'année
// existe sans être marquée courante ferait sinon échouer l'insertion sur
// l'unicité du code.
$year = tenant_one('academic_years', 'is_current = 1')
    ?? tenant_one('academic_years', 'code = :c', ['c' => $yearCode]);

if ($year === null) {
    $yearId = tenant_insert('academic_years', [
        'code'       => $yearCode,
        'name'       => 'Année ' . $yearCode,
        'starts_on'  => $start . '-09-01',
        'ends_on'    => ($start + 1) . '-07-31',
        'status'     => 'active',
        'is_current' => 1,
    ]);

    echo "  ✓ Année scolaire créée : {$yearCode}\n";
} else {
    $yearId = (int) $year['id'];
    echo "  → Année scolaire déjà présente : {$year['code']}\n";
}

curriculum_service_import_national();
$periods = curriculum_service_create_standard_periods($yearId);

echo "  ✓ Référentiel national importé, " . ($periods > 0 ? $periods . ' période(s) créée(s)' : 'périodes déjà en place') . "\n";

$levelId = (int) db_value("SELECT id FROM education_levels WHERE code = 'PRI_5'", [], true);

$program = tenant_one(
    'curriculums',
    'academic_year_id = :y AND education_level_id = :l',
    ['y' => $yearId, 'l' => $levelId]
);

if ($program === null) {
    $program = curriculum_service_create_program($yearId, $levelId, null, null);
    $filled  = curriculum_service_fill_program((int) $program['id']);
    curriculum_service_activate((int) $program['id']);

    echo "  ✓ Programme de 5ème primaire créé et activé ({$filled} branches)\n";
} else {
    echo "  → Programme de 5ème primaire déjà présent\n";
}

$programId = (int) $program['id'];

// =====================================================================
//  ENSEIGNANT ET CLASSE
// =====================================================================
$teacher = tenant_one('teachers', 'user_id = :u', ['u' => $teacherId]);

if ($teacher === null) {
    // Matricule choisi libre : l'école peut déjà en avoir enregistré par
    // l'application, et le matricule est unique par établissement.
    $matricule = 'ENS-DEMO';
    $suffix    = 0;

    while (tenant_one('teachers', 'matricule = :m', ['m' => $matricule]) !== null) {
        $matricule = 'ENS-DEMO-' . (++$suffix);
    }

    $teacherRecordId = tenant_insert('teachers', [
        'uuid'            => str_uuid(),
        'user_id'         => $teacherId,
        'matricule'       => $matricule,
        'last_name'       => 'MUKENDI',
        'first_name'      => 'Joseph',
        'gender'          => 'M',
        'phone'           => '+243810000001',
        'employment_type' => 'permanent',
        'status'          => 'active',
        'hire_date'       => date('Y') . '-09-01',
    ]);

    echo "  ✓ Enseignant créé : MUKENDI Joseph\n";
} else {
    $teacherRecordId = (int) $teacher['id'];
    echo "  → Enseignant déjà présent\n";
}

$classroom = tenant_one('classrooms', 'code = :c AND academic_year_id = :y', ['c' => '5A', 'y' => $yearId]);

if ($classroom === null) {
    $classroomId = tenant_insert('classrooms', [
        'academic_year_id' => $yearId,
        'curriculum_id'    => $programId,
        'main_teacher_id'  => $teacherRecordId,
        'code'             => '5A',
        'name'             => '5ème primaire A',
        'capacity'         => 40,
    ]);

    echo "  ✓ Classe créée : 5ème primaire A (titulaire MUKENDI)\n";
} else {
    $classroomId = (int) $classroom['id'];
    echo "  → Classe 5A déjà présente\n";
}

// =====================================================================
//  ÉLÈVES
// =====================================================================
$enrollments = grades_repo_classroom_enrollments($classroomId);

if ($enrollments === []) {
    $roster = [
        ['KABILA',   'Ngoy',    'Marie',   'F'],
        ['LUMU',     'Kasongo', 'Jean',    'M'],
        ['TSHALA',   'Mbuyi',   'Grâce',   'F'],
        ['NGALULA',  'Kalala',  'Patrick', 'M'],
        ['MWAMBA',   'Ilunga',  'Sarah',   'F'],
        ['BOKUNGU',  'Mopepe',  'David',   'M'],
        ['KASENDE',  'Tshiba',  'Esther',  'F'],
        ['MBALA',    'Lokombe', 'Christian', 'M'],
    ];

    foreach ($roster as $i => $s) {
        students_service_enroll_new([
            'last_name'   => $s[0],
            'post_name'   => $s[1],
            'first_name'  => $s[2],
            'gender'      => $s[3],
            'birth_date'  => (date('Y') - 11) . '-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '-15',
            'birth_place' => 'Kinshasa',
        ], $yearId, $classroomId);
    }

    $enrollments = grades_repo_classroom_enrollments($classroomId);
    echo "  ✓ " . count($enrollments) . " élèves inscrits\n";
} else {
    echo "  → " . count($enrollments) . " élèves déjà inscrits\n";
}

// =====================================================================
//  COTES DU PREMIER SEMESTRE
// =====================================================================
$subjects = tenant_all(
    'curriculum_subjects',
    'curriculum_id = :c ORDER BY order_number, id LIMIT 6',
    ['c' => $programId]
);

$saved = 0;

foreach (['P1', 'P2', 'EX1'] as $code) {
    $period = tenant_one('grade_periods', 'academic_year_id = :y AND code = :c', ['y' => $yearId, 'c' => $code]);

    if ($period === null) {
        continue;
    }

    foreach ($subjects as $subject) {
        $max     = grades_max_for($subject, $period);
        $entries = [];

        foreach ($enrollments as $index => $enrollment) {
            // Profils volontairement contrastés pour que le classement, le
            // seuil de réussite et le traitement des absences soient
            // visibles à l'écran plutôt que théoriques.
            $ratio = 0.42 + 0.07 * ($index % 8);

            // Un élève absent à une évaluation : c'est le cas qui rend le
            // paramètre « absences » observable.
            $absent = ($index === 4 && $code === 'P2');

            $entries[(int) $enrollment['id']] = [
                'points'    => $absent ? null : round($max * $ratio, 1),
                'is_absent' => $absent,
            ];
        }

        $outcome = grades_service_save_sheet($classroomId, (int) $subject['id'], (int) $period['id'], $entries);
        $saved  += $outcome['saved'] ?? 0;
    }
}

echo "  ✓ {$saved} cote(s) enregistrée(s) sur P1, P2 et EX1 (" . count($subjects) . " branches)\n";

// =====================================================================
//  PUBLICATION DU PREMIER SEMESTRE
// =====================================================================
// Le premier regroupement de la classe : « S1 » aux humanités et au
// CTEB, « T1 » au primaire. Publier « S1 » en dur échouait sur toute
// classe de primaire depuis le passage aux trimestres.
$classroomGroups = bulletins_classroom_groups($classroomId);
$firstGroup      = (string) array_key_first($classroomGroups);

$publication = bulletins_service_publish($classroomId, $firstGroup);

echo $publication['ok']
    ? "  ✓ {$publication['published']} bulletin(s) publié(s) — "
        . $classroomGroups[$firstGroup]['label'] . "\n"
    : "  ✗ Publication refusée : {$publication['message']}\n";

// =====================================================================
//  RÉCAPITULATIF
// =====================================================================
$firstEnrollment = (int) $enrollments[0]['id'];

echo "\n  ──────────────────────────────────────────────\n";
echo "  Comptes de test (mot de passe : " . DEMO_PASSWORD . ")\n\n";
echo "    directeur.demo    voit tout, publie les bulletins\n";
echo "    enseignant.demo   ne voit que sa classe — c'est le compte\n";
echo "                      qui prouve l'isolation du périmètre\n";

echo "\n  À ouvrir dans le navigateur\n\n";
echo "    /notes/classe/{$classroomId}                 avancement de la saisie\n";
echo "    /bulletins/classe/{$classroomId}             classement et publication\n";
echo "    /bulletins/{$firstEnrollment}                     bulletin imprimable (Ctrl+P)\n";
echo "    /bulletins/parametres            absences et seuil de réussite\n";

echo "\n  Essai à faire pour vérifier le figeage\n\n";
echo "    1. Noter le rang d'un élève sur /bulletins/classe/{$classroomId}\n";
echo "    2. Modifier une de ses cotes dans /notes\n";
echo "    3. Revenir : le tableau publié n'a pas bougé — c'est voulu.\n";
echo "       Republier le met à jour.\n\n";

// =====================================================================
//  FONCTIONS
// =====================================================================

/**
 * L'école de démonstration, quel que soit le script qui l'a créée.
 *
 * Recherche par slug ET par code : le slug porte la contrainte d'unicité,
 * mais une base antérieure peut avoir l'un sans l'autre.
 */
function demo_school(): ?array
{
    return db_one(
        'SELECT * FROM schools WHERE slug = :slug OR code = :code LIMIT 1',
        ['slug' => DEMO_SCHOOL_SLUG, 'code' => DEMO_SCHOOL_CODE],
        true
    );
}

/**
 * Crée un compte s'il n'existe pas, et lui attache un rôle système.
 *
 * `must_change_password` est laissé à 0 : un changement imposé à chaque
 * réinitialisation du jeu de test ne prouverait rien et ferait perdre du
 * temps. Le vrai parcours de première connexion se teste avec un compte
 * créé par l'application.
 */
function demo_user(
    ?int $schoolId,
    string $username,
    string $lastName,
    string $firstName,
    string $gender,
    string $roleCode
): int {
    // `users.username` est unique pour tout le produit : la recherche
    // est nécessairement transversale. Lecture d'identité.
    $existing = tenant_scope_identity(static fn (): ?array => db_one(
        'SELECT id FROM users WHERE username = :u', ['u' => $username], true
    ));

    if ($existing !== null) {
        echo "  → Compte déjà présent : {$username}\n";

        return (int) $existing['id'];
    }

    $userId = db_insert('users', [
        'uuid'                 => str_uuid(),
        'school_id'            => $schoolId,
        'username'             => $username,
        'email'                => $username . '@demo.cd',
        'password_hash'        => password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]),
        'last_name'            => $lastName,
        'first_name'           => $firstName,
        'gender'               => $gender,
        'status'               => 'active',
        'must_change_password' => 0,
        'password_changed_at'  => date('Y-m-d H:i:s'),
    ], true);

    db_query(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :user_id, id FROM roles WHERE code = :code AND school_id IS NULL',
        ['user_id' => $userId, 'code' => $roleCode],
        true
    );

    echo "  ✓ Compte créé : {$username} ({$roleCode})\n";

    return $userId;
}

/**
 * Efface l'école de démonstration et tout ce qui en dépend.
 *
 * L'ordre suit les dépendances : on ne s'appuie pas sur les CASCADE, qui
 * masqueraient une clé étrangère manquante au lieu de la signaler.
 */
function demo_erase(int $schoolId): void
{
    db_query('UPDATE classrooms SET main_teacher_id = NULL WHERE school_id = :s', ['s' => $schoolId], true);

    foreach ([
        'bulletins', 'grades', 'student_history', 'orientations',
        'student_guardians', 'enrollments', 'guardians', 'students',
        'student_counters', 'teacher_subjects', 'teachers', 'classrooms',
        'curriculum_subjects', 'curriculums', 'grade_periods',
        'subjects', 'options', 'sections', 'academic_years', 'audit_logs',
        'school_settings',
    ] as $table) {
        db_query("DELETE FROM {$table} WHERE school_id = :s", ['s' => $schoolId], true);
    }

    db_query(
        'DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE school_id = :s)',
        ['s' => $schoolId],
        true
    );

    db_query('DELETE FROM users WHERE school_id = :s', ['s' => $schoolId], true);
    db_query('DELETE FROM school_cycles WHERE school_id = :s', ['s' => $schoolId], true);
    db_query('DELETE FROM schools WHERE id = :s', ['s' => $schoolId], true);
}
