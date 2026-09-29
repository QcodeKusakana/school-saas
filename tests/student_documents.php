<?php
/**
 * Phase 11D — les pièces du dossier de l'élève.
 *
 * CE QUE CES TESTS PROTÈGENT
 * --------------------------
 *  · le PÉRIMÈTRE avant la permission : `student.document` dit qu'on
 *    peut gérer des pièces, jamais SUR QUI — la leçon de l'audit de la
 *    phase 3, où `student.view` ouvrait le dossier de n'importe quel
 *    mineur de l'établissement ;
 *  · l'isolation entre écoles, sur la ligne ET sur le fichier ;
 *  · le contenu décide, pas l'extension : un script renommé `.pdf` est
 *    refusé par `upload_store()`, et aucun second chemin ne le contourne ;
 *  · le retrait EFFACE le fichier — une ligne supprimée qui laisserait
 *    l'acte de naissance sur le disque serait un masquage, pas un
 *    retrait ;
 *  · l'effacement RGPD d'une école (10C) emporte ces pièces, parce
 *    qu'elles sont rangées dans un dossier qu'il connaît déjà.
 *
 * Usage : php tests/student_documents.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/students/documents.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

function devenir(int $userId, int $ecole): void
{
    session_unset();
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $ecole;
    tenant_set($ecole);
    auth_user(true);
    perm_all(true);
    perm_roles(true);
}

/** Un PDF minimal mais VALIDE — le produit lit le contenu, pas le nom. */
function pdf_jetable(string $chemin): void
{
    file_put_contents($chemin,
        "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
        . "trailer<</Root 1 0 R>>\n%%EOF\n");
}

echo "\n  PHASE 11D — LES PIÈCES DU DOSSIER\n";
echo "  ══════════════════════════════════════════════════════════\n";

// ---------------------------------------------------------------------
//  Ce que la migration a posé
// ---------------------------------------------------------------------
echo "\n  Le schéma\n";

$colonnes = array_column(db_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_documents'",
    [], true), 'COLUMN_NAME');

check('La table existe', $colonnes !== []);
check('Elle porte school_id ET student_id',
    in_array('school_id', $colonnes, true) && in_array('student_id', $colonnes, true));

check('Elle est déclarée au garde multi-école',
    in_array('student_documents', TENANT_TABLES, true),
    'sans quoi une requête sans filtre passerait sans un mot');

$fk = db_all(
    "SELECT rc.TABLE_NAME, rc.REFERENCED_TABLE_NAME, rc.DELETE_RULE
       FROM information_schema.REFERENTIAL_CONSTRAINTS rc
      WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = 'student_documents'",
    [], true);

$regle = [];

foreach ($fk as $c) {
    $regle[(string) $c['REFERENCED_TABLE_NAME']] = (string) $c['DELETE_RULE'];
}

check('Effacer l\'école emporte ses pièces', ($regle['schools'] ?? '') === 'CASCADE');
check('Effacer un élève emporte les siennes', ($regle['students'] ?? '') === 'CASCADE');
check('Mais fermer un COMPTE ne les emporte pas', ($regle['users'] ?? '') === 'SET NULL',
    'un compte fermé ne doit pas emporter l\'acte de naissance d\'un élève');

$unique = db_value(
    "SELECT NON_UNIQUE FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_documents'
        AND INDEX_NAME = 'uq_studoc_path' LIMIT 1",
    [], true);

check('Deux lignes ne peuvent pas désigner le même fichier',
    $unique !== null && (int) $unique === 0,
    'en supprimer une rendrait l\'autre morte');

// ---------------------------------------------------------------------
//  Le décor
// ---------------------------------------------------------------------
$ecole = (int) platform_scope_cli(static fn (): int => (int) db_value(
    "SELECT id FROM schools WHERE code = 'ECO-000001'", [], true));

$admin = (int) platform_scope_cli(static fn (): int => (int) db_value(
    "SELECT id FROM users WHERE username = 'admin.demo'", [], true));

$enseignant = (int) platform_scope_cli(static fn (): int => (int) db_value(
    "SELECT id FROM users WHERE username = 'enseignant.demo'", [], true));

check('Le jeu de démonstration est en place', $ecole > 0 && $admin > 0 && $enseignant > 0,
    'php database/seed_demo.php');

if ($ecole === 0 || $admin === 0) {
    printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);
    exit(1);
}

devenir($admin, $ecole);

$eleve = (int) db_value('SELECT id FROM students WHERE school_id = :s ORDER BY id LIMIT 1',
    ['s' => $ecole]);

check('Un élève existe', $eleve > 0);

// ---------------------------------------------------------------------
//  Ranger une pièce
// ---------------------------------------------------------------------
echo "\n  Ranger une pièce\n";

// `upload_store()` exige `is_uploaded_file()`, qu'un test ne peut pas
// satisfaire. On écrit donc la ligne comme le service l'écrit, APRÈS
// avoir éprouvé séparément la validation du contenu — qui est, elle, le
// vrai garde.
$dossier = storage_path('uploads/' . student_documents_dossier());

if (!is_dir($dossier)) {
    mkdir($dossier, 0o775, true);
}

$nomFichier = 'test-11d-' . bin2hex(random_bytes(6)) . '.pdf';
$chemin     = student_documents_dossier() . '/' . $nomFichier;
$absolu     = storage_path('uploads/' . $chemin);

pdf_jetable($absolu);

$pieceId = db_insert('student_documents', [
    'school_id'   => $ecole,
    'student_id'  => $eleve,
    'type'        => 'acte_naissance',
    'label'       => 'Acte n° 1234/2018',
    'file_path'   => $chemin,
    'mime'        => 'application/pdf',
    'size_bytes'  => (int) filesize($absolu),
    'uploaded_by' => $admin,
]);

check('Une pièce est rangée', $pieceId > 0);

$liste = student_documents_all($eleve);

check('Elle apparaît au dossier de l\'élève',
    count(array_filter($liste, static fn ($p) => (int) $p['id'] === $pieceId)) === 1);

check('… avec le nom de qui l\'a déposée',
    ($liste[0]['par_nom'] ?? null) !== null);

// LA VALIDATION DU CONTENU — le vrai garde, éprouvé pour lui-même.
$piege = sys_get_temp_dir() . '/piege-11d.pdf';
file_put_contents($piege, '<?php system($_GET["c"]); ?>');

$verdict = upload_inspect($piege, 'acte.pdf', 'document');

check('Un script PHP renommé « .pdf » est refusé',
    ($verdict['ok'] ?? true) === false,
    (string) ($verdict['error'] ?? ''));

$vrai = sys_get_temp_dir() . '/vrai-11d.pdf';
pdf_jetable($vrai);

check('… tandis qu\'un vrai PDF passe',
    (upload_inspect($vrai, 'acte.pdf', 'document')['ok'] ?? false) === true);

check('… et qu\'un PDF présenté comme une image est refusé',
    (upload_inspect($vrai, 'acte.jpg', 'image')['ok'] ?? true) === false);

@unlink($piege);
@unlink($vrai);

// ---------------------------------------------------------------------
//  Le périmètre
// ---------------------------------------------------------------------
echo "\n  Le périmètre, avant la permission\n";

devenir($enseignant, $ecole);

check('Un enseignant ne détient pas `student.document`', !can('student.document'));
check('… et le service le lui refuse',
    student_documents_refus($eleve) !== null,
    (string) student_documents_refus($eleve));

check('… y compris pour lire le fichier',
    (student_documents_file($pieceId)['ok'] ?? true) === false);

check('… et pour retirer la pièce',
    (student_documents_delete($pieceId, 'tentative depuis un compte enseignant')['ok'] ?? true) === false);

devenir($admin, $ecole);

check('L\'administration, elle, y accède',
    student_documents_refus($eleve) === null);

// UN ÉLÈVE D'UNE AUTRE ÉCOLE.
$autreEleve = (int) platform_scope_cli(static fn (): int => (int) db_value(
    'SELECT id FROM students WHERE school_id <> :s LIMIT 1', ['s' => $ecole], true) ?? 0);

if ($autreEleve > 0) {
    check('Un élève d\'une autre école est introuvable',
        student_documents_refus($autreEleve) !== null);
} else {
    check('Aucune autre école dans la base : isolation éprouvée par le garde', true,
        'la table est dans TENANT_TABLES, toute requête sans filtre lèverait');
}

// ---------------------------------------------------------------------
//  Lire le fichier
// ---------------------------------------------------------------------
echo "\n  Lire le fichier\n";

$fichier = student_documents_file($pieceId);

check('Le fichier est servi', (bool) ($fichier['ok'] ?? false));
check('… depuis storage/uploads, jamais depuis public/',
    str_starts_with((string) ($fichier['path'] ?? ''), (string) realpath(storage_path('uploads'))));
check('… avec un nom RECONSTRUIT, pas celui du client',
    ($fichier['nom'] ?? '') === 'acte_naissance.pdf',
    'le nom choisi par le client n\'entre jamais dans un chemin');

// Un chemin forgé ne doit pas sortir du dossier.
db_query('UPDATE student_documents SET file_path = :p WHERE id = :i AND school_id = :s',
    ['p' => '../../../app/config/config.php', 'i' => $pieceId, 's' => $ecole]);

check('Un chemin qui remonte l\'arborescence est refusé',
    (student_documents_file($pieceId)['ok'] ?? true) === false,
    'même venu de la base : une reprise SQL ou une restauration pourrait en glisser un');

db_query('UPDATE student_documents SET file_path = :p WHERE id = :i AND school_id = :s',
    ['p' => $chemin, 'i' => $pieceId, 's' => $ecole]);

// ---------------------------------------------------------------------
//  Retirer une pièce
// ---------------------------------------------------------------------
echo "\n  Retirer une pièce\n";

$sansMotif = student_documents_delete($pieceId, 'ok');

check('Un retrait sans motif est refusé', ($sansMotif['ok'] ?? true) === false,
    (string) $sansMotif['message']);
check('… et la pièce est toujours là', student_documents_find($pieceId) !== null);
check('… ainsi que son fichier', is_file($absolu));

$retrait = student_documents_delete($pieceId, 'Piece deposee sur le mauvais dossier');

check('Avec un motif, le retrait passe', (bool) ($retrait['ok'] ?? false),
    (string) $retrait['message']);
check('La ligne a disparu', student_documents_find($pieceId) === null);

// LE CONTRÔLE QUI COMPTE : le fichier aussi.
check('LE FICHIER A DISPARU DU DISQUE', !is_file($absolu),
    'une ligne supprimée qui laisse l\'acte de naissance sur le disque n\'est pas un retrait');

$trace = (int) platform_scope_cli(static fn (): int => (int) db_value(
    "SELECT COUNT(*) FROM audit_logs
      WHERE action = 'student.document.remove' AND entity_id = :i
        AND new_values LIKE '%mauvais dossier%'",
    ['i' => $pieceId], true));

check('Le motif est au journal', $trace === 1);

// ---------------------------------------------------------------------
//  Ce que l'effacement RGPD emporte
// ---------------------------------------------------------------------
echo "\n  L'effacement d'une école (10C)\n";

$source = file_get_contents(BASE_PATH . '/database/erase_school.php');

check('La procédure connaît le dossier des pièces',
    str_contains((string) $source, "documents/' . \$ecoleId"),
    'storage/uploads/documents/{école} est déjà dans erase_dossiers()');

check('Les pièces sont rangées sous ce dossier',
    str_starts_with(student_documents_dossier(), 'documents/' . $ecole),
    student_documents_dossier());

// ---------------------------------------------------------------------
//  Nettoyage — hors `finally` (leçon de la 9D).
// ---------------------------------------------------------------------
@unlink($absolu);

platform_scope_cli(static function (): void {
    db_query("DELETE FROM audit_logs WHERE entity_type = 'student_documents'", [], true);
});

check('Le décor jetable est nettoyé', !is_file($absolu));

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
