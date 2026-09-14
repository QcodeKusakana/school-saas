<?php
/**
 * Isolation multi-établissements — tables de la phase 2.
 *
 * Usage : php tests/curriculum_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/curriculum/services.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

echo "\n  RÉFÉRENTIEL SCOLAIRE — ISOLATION ET RÈGLES MÉTIER\n";
echo "  ──────────────────────────────────────────────────────\n";

db_query("DELETE FROM schools WHERE code LIKE 'CUR-%'", [], true);

$schoolA = db_insert('schools', [
    'uuid' => str_uuid(), 'code' => 'CUR-A', 'slug' => 'cur-a',
    'name' => 'École Alpha', 'status' => 'active',
], true);

$schoolB = db_insert('schools', [
    'uuid' => str_uuid(), 'code' => 'CUR-B', 'slug' => 'cur-b',
    'name' => 'École Beta', 'status' => 'active',
], true);

// Chaque école active tous les cycles et crée son année scolaire.
foreach ([$schoolA, $schoolB] as $schoolId) {
    db_query(
        'INSERT INTO school_cycles (school_id, cycle_id, is_active)
         SELECT :school_id, id, 1 FROM education_cycles',
        ['school_id' => $schoolId]
    );
}

tenant_set($schoolA);
$yearA = tenant_insert('academic_years', [
    'code' => '2040-2041', 'name' => 'A', 'starts_on' => '2040-09-01',
    'ends_on' => '2041-07-31', 'status' => 'active',
]);

tenant_set($schoolB);
$yearB = tenant_insert('academic_years', [
    'code' => '2040-2041', 'name' => 'B', 'starts_on' => '2040-09-01',
    'ends_on' => '2041-07-31', 'status' => 'active',
]);

// --- 1. Import du référentiel national dans chaque école --------------
tenant_set($schoolA);
$importA = curriculum_service_import_national();

tenant_set($schoolB);
$importB = curriculum_service_import_national();

check(
    'Chaque école reçoit sa propre copie du référentiel national',
    $importA['sections'] > 0 && $importA['sections'] === $importB['sections'],
    "A={$importA['sections']} sections, B={$importB['sections']}"
);

// --- 2. Import rejoué : idempotent -------------------------------------
tenant_set($schoolA);
$again = curriculum_service_import_national();
check('Import rejoué : aucune duplication', array_sum($again) === 0);

// --- 3. Les branches d'une école sont invisibles depuis l'autre --------
tenant_set($schoolA);
$subjectA = tenant_one('subjects', 'code = :code', ['code' => 'MATH']);
tenant_set($schoolB);
$subjectB = tenant_one('subjects', 'code = :code', ['code' => 'MATH']);

check(
    'Chaque école a sa propre ligne « MATH »',
    $subjectA !== null && $subjectB !== null && (int) $subjectA['id'] !== (int) $subjectB['id']
);

tenant_set($schoolA);
check(
    'École A ne voit pas la branche de l\'école B',
    tenant_find('subjects', (int) $subjectB['id']) === null
);

// --- 4. Renommer chez A ne touche pas B --------------------------------
tenant_set($schoolA);
tenant_update('subjects', ['name' => 'Maths (Alpha)'], 'id = :id', ['id' => (int) $subjectA['id']]);

tenant_set($schoolB);
$stillB = tenant_find('subjects', (int) $subjectB['id']);
check('Un renommage chez A laisse B intact', $stillB['name'] === 'Mathématiques');

// --- 5. Périodes ------------------------------------------------------
tenant_set($schoolA);
$createdA = curriculum_service_create_standard_periods($yearA);

// UN JEU DE PÉRIODES PAR CYCLE DISPENSÉ.
//
// Les dix bulletins officiels montrent deux découpages : trois
// trimestres au primaire (neuf périodes), deux semestres au CTEB et aux
// humanités (six périodes). Une école qui dispense les deux a besoin des
// deux jeux dans la même année.
$byCycle = [];

foreach (db_all(
    'SELECT c.code, c.period_structure, gp.code AS period_code, gp.semester, gp.max_multiplier
       FROM grade_periods gp
       JOIN education_cycles c ON c.id = gp.cycle_id
      WHERE gp.school_id = :s AND gp.academic_year_id = :y
      ORDER BY c.id, gp.order_number',
    ['s' => $schoolA, 'y' => $yearA]
) as $row) {
    $byCycle[$row['code']][] = $row;
}

check(
    'Un jeu de périodes par cycle dispensé',
    count($byCycle) >= 2,
    implode(', ', array_keys($byCycle))
);

check(
    'Le primaire reçoit NEUF périodes — trois trimestres',
    isset($byCycle['PRIMAIRE']) && count($byCycle['PRIMAIRE']) === 9,
    isset($byCycle['PRIMAIRE']) ? count($byCycle['PRIMAIRE']) . ' périodes' : 'aucune'
);

check(
    'Réparties en trois regroupements',
    isset($byCycle['PRIMAIRE'])
        && count(array_unique(array_column($byCycle['PRIMAIRE'], 'semester'))) === 3
);

check(
    'Les humanités reçoivent SIX périodes — deux semestres',
    isset($byCycle['HUMANITES']) && count($byCycle['HUMANITES']) === 6,
    isset($byCycle['HUMANITES']) ? count($byCycle['HUMANITES']) . ' périodes' : 'aucune'
);

// Le multiplicateur cumulé donne le rapport entre le maximum d'une
// branche et son total annuel. Vérifié sur le bulletin du degré moyen :
// MAX per 300, TOTAL 3600, soit exactement douze fois.
$sum = static fn (array $rows): float => array_sum(array_map(
    static fn (array $r): float => (float) $r['max_multiplier'],
    $rows
));

check(
    'Primaire : multiplicateur annuel cumulé = 12 (3 × [1 + 1 + 2])',
    isset($byCycle['PRIMAIRE']) && abs($sum($byCycle['PRIMAIRE']) - 12.0) < 0.001,
    isset($byCycle['PRIMAIRE']) ? (string) $sum($byCycle['PRIMAIRE']) : '—'
);

check(
    'Humanités : multiplicateur annuel cumulé = 8 (2 × [1 + 1 + 2])',
    isset($byCycle['HUMANITES']) && abs($sum($byCycle['HUMANITES']) - 8.0) < 0.001,
    isset($byCycle['HUMANITES']) ? (string) $sum($byCycle['HUMANITES']) : '—'
);

// Les regroupements déduits doivent suivre la structure du cycle.
require_once APP_PATH . '/modules/bulletins/services.php';

$indexed = static function (array $rows): array {
    $out = [];

    foreach ($rows as $r) {
        $out[$r['period_code']] = ['semester' => (int) $r['semester']];
    }

    return $out;
};

check(
    'Le primaire produit T1, T2, T3 et le total général',
    array_keys(bulletins_groups($indexed($byCycle['PRIMAIRE'] ?? []))) === ['T1', 'T2', 'T3', 'ANNUAL'],
    implode(', ', array_keys(bulletins_groups($indexed($byCycle['PRIMAIRE'] ?? []))))
);

check(
    'Les humanités produisent S1, S2 et le total général',
    array_keys(bulletins_groups($indexed($byCycle['HUMANITES'] ?? []))) === ['S1', 'S2', 'ANNUAL'],
    implode(', ', array_keys(bulletins_groups($indexed($byCycle['HUMANITES'] ?? []))))
);

$again = curriculum_service_create_standard_periods($yearA);
check('Création rejouée : aucune période en double', $again === 0);

// =====================================================================
//  CHEMIN DE MISE À JOUR — UNE BASE ANTÉRIEURE À LA MIGRATION 011
//
//  Ces bases portent un jeu HÉRITÉ, sans cycle. Le service refusait
//  alors de créer quoi que ce soit : leur primaire serait resté en DEUX
//  SEMESTRES pour toujours, et le troisième trimestre n'aurait jamais
//  existé. C'est le cas de toute installation déjà en service.
// =====================================================================
tenant_set($schoolB);

foreach ([['P1', 1, 1, 1.0], ['P2', 1, 2, 1.0], ['EX1', 1, 3, 2.0],
          ['P3', 2, 4, 1.0], ['P4', 2, 5, 1.0], ['EX2', 2, 6, 2.0]] as $legacy) {
    db_query(
        'INSERT INTO grade_periods
            (school_id, academic_year_id, cycle_id, code, name,
             period_type, semester, order_number, max_multiplier)
         VALUES (:s, :y, NULL, :code, :label, :t, :sem, :o, :m)',
        [
            's'   => $schoolB, 'y' => $yearB,
            // Deux paramètres distincts pour la même valeur : avec
            // ATTR_EMULATE_PREPARES = false, réutiliser « :c » lève
            // HY093. C'est la quatrième fois que ce piège se referme
            // dans ce projet.
            'code' => $legacy[0], 'label' => $legacy[0],
            't'   => str_starts_with($legacy[0], 'EX') ? 'exam' : 'period',
            'sem' => $legacy[1], 'o' => $legacy[2], 'm' => $legacy[3],
        ],
        true
    );
}

$upgraded = curriculum_service_create_standard_periods($yearB);

check(
    'Un jeu hérité n\'empêche plus la création du jeu du primaire',
    $upgraded === 9,
    $upgraded . ' période(s) créée(s)'
);

$primaryB = db_all(
    'SELECT gp.code, gp.semester FROM grade_periods gp
       JOIN education_cycles c ON c.id = gp.cycle_id
      WHERE gp.school_id = :s AND gp.academic_year_id = :y AND c.code = \'PRIMAIRE\'
      ORDER BY gp.order_number',
    ['s' => $schoolB, 'y' => $yearB],
    true
);

check(
    'Le primaire obtient ses NEUF périodes trimestrielles',
    count($primaryB) === 9,
    count($primaryB) . ' période(s)'
);

// Le jeu hérité est en semestres : il convient déjà au CTEB et aux
// humanités. En recréer un pour eux ferait doublon.
$othersB = (int) db_value(
    'SELECT COUNT(*) FROM grade_periods gp
       JOIN education_cycles c ON c.id = gp.cycle_id
      WHERE gp.school_id = :s AND gp.academic_year_id = :y AND c.code <> \'PRIMAIRE\'',
    ['s' => $schoolB, 'y' => $yearB],
    true
);

check(
    'Les cycles déjà servis par le jeu hérité n\'en reçoivent pas un second',
    $othersB === 0,
    $othersB . ' période(s) en trop'
);

tenant_set($schoolA);

// --- 6. Programme : règles métier --------------------------------------
$level5 = (int) db_value("SELECT id FROM education_levels WHERE code = 'PRI_5'", [], true);
$hum1   = (int) db_value("SELECT id FROM education_levels WHERE code = 'HUM_1'", [], true);

tenant_set($schoolA);
$program = curriculum_service_create_program($yearA, $level5, null, null);
check('Programme de primaire créé sans section ni option', $program['ok']);

$duplicate = curriculum_service_create_program($yearA, $level5, null, null);
check(
    'Programme en double refusé (uniq_key protège les NULL)',
    !$duplicate['ok'] && str_contains($duplicate['message'], 'existe déjà')
);

$missing = curriculum_service_create_program($yearA, $hum1, null, null);
check(
    'Humanités sans section ni option : refusé',
    !$missing['ok'] && str_contains($missing['message'], 'exigent')
);

// --- 7. Option d'une autre section ------------------------------------
$sectionSci = tenant_one('sections', 'code = :c', ['c' => 'SCIENTIFIQUE']);
$optionLit  = tenant_one('options', 'code = :c', ['c' => 'LATIN_PHILO']); // section littéraire

$mismatch = curriculum_service_create_program(
    $yearA,
    $hum1,
    (int) $sectionSci['id'],
    (int) $optionLit['id']
);
check(
    'Option n\'appartenant pas à la section : refusé',
    !$mismatch['ok'] && str_contains($mismatch['message'], 'n\'appartient pas')
);

// --- 8. Option appartenant à une AUTRE ÉCOLE ---------------------------
tenant_set($schoolB);
$optionOfB = tenant_one('options', 'code = :c', ['c' => 'MATH_PHYS']);

tenant_set($schoolA);
$sectionOfA = tenant_one('sections', 'code = :c', ['c' => 'SCIENTIFIQUE']);

$crossSchool = curriculum_service_create_program(
    $yearA,
    $hum1,
    (int) $sectionOfA['id'],
    (int) $optionOfB['id'] // identifiant forgé, appartenant à l'école B
);
check(
    'Option d\'une AUTRE ÉCOLE : refusée',
    !$crossSchool['ok'],
    $crossSchool['message']
);

// --- 9. Année scolaire d'une autre école ------------------------------
$crossYear = curriculum_service_create_program($yearB, $level5, null, null);
check(
    'Année scolaire d\'une AUTRE ÉCOLE : refusée',
    !$crossYear['ok'] && str_contains($crossYear['message'], 'introuvable')
);

// --- 10. Remplissage et totaux -----------------------------------------
$programId = (int) $program['id'];
$added     = curriculum_service_fill_program($programId);
check('Remplissage automatique du programme', $added > 0, "{$added} branches");

$rows  = curriculum_repo_program_subjects($programId);
$total = 0;
foreach ($rows as $row) {
    if ((int) $row['is_optional'] === 0) {
        $total += (int) $row['max_points'];
    }
}
check('Total des maxima cohérent', $total > 0, "{$total} points par période, " . ($total * 8) . ' sur l\'année');

// --- 11. La conduite est exclue du classement --------------------------
$conduct = null;
foreach ($rows as $row) {
    if ($row['subject_code'] === 'CONDUITE') {
        $conduct = $row;
    }
}
check(
    'La conduite ne compte pas pour le classement',
    $conduct !== null && (int) $conduct['counts_for_ranking'] === 0
);

// --- 12. Activation ----------------------------------------------------
$activation = curriculum_service_activate($programId);
check('Programme complet : activation acceptée', $activation['ok'], $activation['message']);

$empty = curriculum_service_create_program(
    $yearA,
    (int) db_value("SELECT id FROM education_levels WHERE code = 'PRI_6'", [], true),
    null,
    null
);
$refused = curriculum_service_activate((int) $empty['id']);
check(
    'Programme vide : activation refusée',
    !$refused['ok'] && str_contains($refused['message'], 'aucune branche')
);

// --- 13. Garde-fou sur les nouvelles tables ----------------------------
foreach (['subjects', 'sections', 'options', 'curriculums', 'curriculum_subjects', 'grade_periods'] as $table) {
    $blocked = false;

    try {
        db_all('SELECT * FROM ' . $table . ' LIMIT 1');
    } catch (RuntimeException $e) {
        $blocked = str_contains($e->getMessage(), 'sans filtre school_id');
    }

    check("Garde-fou actif sur « {$table} »", $blocked);
}

// --- 14. Référentiel officiel des domaines d'apprentissage -------------
//
// Le projet a longtemps porté HUIT domaines inventés. Les six bulletins
// officiels du ministère en comptent CINQ, et « Conduite » n'y est pas un
// domaine mais une ligne d'appréciation en bas de page. Ces contrôles
// empêchent la réapparition de la taxonomie inventée.
$domains = db_all('SELECT id, code, name FROM learning_domains ORDER BY order_number', [], true);

check(
    'Cinq domaines d\'apprentissage, ni plus ni moins',
    count($domains) === 5,
    count($domains) . ' domaine(s)'
);

check(
    'Ce sont les cinq domaines officiels',
    array_column($domains, 'code') === ['LANGUES', 'SCIENCES', 'UNIVERS', 'ARTS', 'DEV_PERS'],
    implode(', ', array_column($domains, 'code'))
);

check(
    '« Conduite » n\'est plus un domaine',
    !in_array('CONDUITE', array_column($domains, 'code'), true)
);

// Le rerattachement ne devait perdre aucune matière : une migration qui
// déplace des lignes de référence doit se vérifier par le nombre.
check(
    'Aucune matière de référence n\'est orpheline après rerattachement',
    (int) db_value(
        'SELECT COUNT(*) FROM reference_subjects rs
           LEFT JOIN learning_domains d ON d.id = rs.domain_id
          WHERE d.id IS NULL',
        [],
        true
    ) === 0
);

check(
    'Aucune matière d\'école n\'est orpheline non plus',
    (int) db_value(
        'SELECT COUNT(*) FROM subjects s
           LEFT JOIN learning_domains d ON d.id = s.domain_id
          WHERE s.domain_id IS NOT NULL AND d.id IS NULL',
        [],
        true
    ) === 0
);

// Contrôles ponctuels tirés des modèles officiels.
$domainOf = static function (string $code): ?string {
    return db_value(
        'SELECT d.code FROM reference_subjects rs
           JOIN learning_domains d ON d.id = rs.domain_id
          WHERE rs.code = :c',
        ['c' => $code],
        true
    );
};

check(
    'Technologie et TIC relèvent du domaine des sciences (modèle 7e CTEB)',
    $domainOf('TECHNOLOGIE') === 'SCIENCES' && $domainOf('TIC') === 'SCIENCES'
);

check(
    'Religion, ECM et Éducation à la vie relèvent de l\'univers social (modèles 7e et 8e CTEB)',
    $domainOf('RELIGION') === 'UNIVERS'
        && $domainOf('EDUC_CIVIQUE') === 'UNIVERS'
        && $domainOf('EDUC_VIE') === 'UNIVERS'
);

check(
    'Travaux pratiques et EPS relèvent du développement personnel (modèle degré élémentaire)',
    $domainOf('TRAV_PRAT') === 'DEV_PERS' && $domainOf('EPS') === 'DEV_PERS'
);

// --- Nettoyage ---------------------------------------------------------
db_query("DELETE FROM schools WHERE code LIKE 'CUR-%'", [], true);

echo "\n  ──────────────────────────────────────────────────────\n";
printf("  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
