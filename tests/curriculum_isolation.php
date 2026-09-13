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
check('Six périodes créées (4 périodes + 2 examens)', $createdA === 6, (string) $createdA);

$multiplier = 0.0;
foreach (curriculum_repo_periods($yearA) as $period) {
    $multiplier += (float) $period['max_multiplier'];
}
check('Multiplicateur annuel cumulé = 8', abs($multiplier - 8.0) < 0.001, (string) $multiplier);

$again = curriculum_service_create_standard_periods($yearA);
check('Création rejouée : aucune période en double', $again === 0);

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

// --- Nettoyage ---------------------------------------------------------
db_query("DELETE FROM schools WHERE code LIKE 'CUR-%'", [], true);

echo "\n  ──────────────────────────────────────────────────────\n";
printf("  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
