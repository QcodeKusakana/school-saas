<?php
/**
 * Vérification de l'isolation multi-établissements.
 * Script de test.
 *
 * Usage : php tests/tenant_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

echo "\n  ISOLATION MULTI-ÉTABLISSEMENTS\n";
echo "  ──────────────────────────────────────────────────\n";

// Deux écoles de test, chacune avec son année scolaire.
db_query("DELETE FROM schools WHERE code LIKE 'TST-%'", [], true);

$schoolA = db_insert('schools', [
    'uuid' => str_uuid(), 'code' => 'TST-A', 'slug' => 'tst-a',
    'name' => 'École Alpha', 'status' => 'active',
], true);

$schoolB = db_insert('schools', [
    'uuid' => str_uuid(), 'code' => 'TST-B', 'slug' => 'tst-b',
    'name' => 'École Beta', 'status' => 'active',
], true);

tenant_set($schoolA);
$yearA = tenant_insert('academic_years', [
    'code' => '2030-2031', 'name' => 'Alpha', 'starts_on' => '2030-09-01',
    'ends_on' => '2031-07-31', 'status' => 'active',
]);

tenant_set($schoolB);
$yearB = tenant_insert('academic_years', [
    'code' => '2030-2031', 'name' => 'Beta', 'starts_on' => '2030-09-01',
    'ends_on' => '2031-07-31', 'status' => 'active',
]);

// --- 1. tenant_insert renseigne bien school_id -----------------------
tenant_set($schoolA);
$row = db_one('SELECT school_id FROM academic_years WHERE id = :id AND school_id = :sid',
    ['id' => $yearA, 'sid' => $schoolA]);
check('tenant_insert rattache l\'enregistrement à l\'école courante',
    $row !== null && (int) $row['school_id'] === $schoolA);

// --- 2. tenant_find ne traverse pas la frontière ----------------------
tenant_set($schoolA);
check('École A voit sa propre année scolaire', tenant_find('academic_years', $yearA) !== null);
check('École A NE VOIT PAS l\'année scolaire de l\'école B', tenant_find('academic_years', $yearB) === null);

tenant_set($schoolB);
check('École B voit sa propre année scolaire', tenant_find('academic_years', $yearB) !== null);
check('École B NE VOIT PAS l\'année scolaire de l\'école A', tenant_find('academic_years', $yearA) === null);

// --- 3. tenant_update ne modifie pas l'autre école --------------------
tenant_set($schoolA);
$affected = tenant_update('academic_years', ['name' => 'PIRATÉ'], 'id = :id', ['id' => $yearB]);
$stillOk  = db_one('SELECT name FROM academic_years WHERE id = :id AND school_id = :sid',
    ['id' => $yearB, 'sid' => $schoolB]);
check('tenant_update ne touche pas l\'enregistrement d\'une autre école',
    $affected === 0 && $stillOk['name'] === 'Beta', "{$affected} ligne(s) affectée(s)");

// --- 4. tenant_count est bien cloisonné -------------------------------
tenant_set($schoolA);
$countA = tenant_count('academic_years');
tenant_set($schoolB);
$countB = tenant_count('academic_years');
check('tenant_count est cloisonné par école', $countA === 1 && $countB === 1, "A={$countA}, B={$countB}");

echo "\n  GARDE-FOU SUR LES REQUÊTES ÉCRITES À LA MAIN\n";
echo "  ──────────────────────────────────────────────────\n";

// --- 5. Le garde-fou bloque une requête sans school_id ----------------
$blocked = false;
try {
    db_all('SELECT * FROM academic_years WHERE status = :s', ['s' => 'active']);
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'sans filtre school_id');
}
check('Requête sur table multi-école SANS school_id : bloquée', $blocked);

// --- 6. Une requête correcte passe ------------------------------------
$allowed = true;
try {
    db_all('SELECT * FROM academic_years WHERE school_id = :sid AND status = :s',
        ['sid' => $schoolB, 's' => 'active']);
} catch (RuntimeException) {
    $allowed = false;
}
check('Requête AVEC school_id : autorisée', $allowed);

// --- 7. Le contournement explicite reste possible ---------------------
$bypass = true;
try {
    db_all('SELECT COUNT(*) FROM academic_years', [], true);
} catch (RuntimeException) {
    $bypass = false;
}
check('Contournement explicite (3e argument) : autorisé', $bypass);

// --- 8. Une table globale n'est pas concernée -------------------------
$global = true;
try {
    db_all('SELECT * FROM education_levels WHERE is_active = 1');
} catch (RuntimeException) {
    $global = false;
}
check('Table globale (education_levels) : non soumise au garde-fou', $global);

// --- 9. Une table non déclarée est refusée ----------------------------
$undeclared = false;
try {
    tenant_all('schools');
} catch (InvalidArgumentException $e) {
    $undeclared = str_contains($e->getMessage(), 'TENANT_TABLES');
}
check('Table non déclarée dans TENANT_TABLES : refusée', $undeclared);

// --- 10. Jointure : la détection porte sur toutes les tables ----------
$joinBlocked = false;
try {
    db_all('SELECT y.* FROM education_levels l JOIN academic_years y ON 1 = 1');
} catch (RuntimeException) {
    $joinBlocked = true;
}
check('Jointure atteignant une table multi-école sans filtre : bloquée', $joinBlocked);

echo "\n  POLITIQUE DE MOT DE PASSE\n";
echo "  ──────────────────────────────────────────────────\n";

foreach ([
    ['court', 'Ab1', false],
    ['sans majuscule', 'motdepasse123', false],
    ['sans chiffre', 'MotDePasseLong', false],
    ['conforme', 'MotDePasse2026', true],
] as [$label, $value, $expected]) {
    $result = validate(['p' => $value], ['p' => 'required|password']);
    check("Mot de passe {$label} : " . ($expected ? 'accepté' : 'refusé'),
        validator_passes($result) === $expected);
}

// --- Nettoyage --------------------------------------------------------
db_query("DELETE FROM schools WHERE code LIKE 'TST-%'", [], true);

echo "\n  ──────────────────────────────────────────────────\n";
printf("  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
