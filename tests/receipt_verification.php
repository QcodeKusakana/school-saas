<?php
/**
 * Phase 11C — un reçu vérifiable.
 *
 * CE QUE CES TESTS PROTÈGENT
 * --------------------------
 *  · tout encaissement naît avec un jeton, sans quoi le reçu imprimé
 *    porterait un code mort ;
 *  · le jeton est unique, y compris FACE AUX DOCUMENTS de la 9A — la
 *    page publique est commune, et une ambiguïté sur une preuve de
 *    paiement est pire qu'une absence de preuve ;
 *  · la page publique ne publie NI le nom de l'élève, NI celui du
 *    payeur, NI la classe : ce sont des mineurs ;
 *  · elle publie en revanche le MONTANT — c'est ce qu'un fraudeur
 *    retoucherait ;
 *  · un reçu ANNULÉ le dit, avec son motif ;
 *  · la vérification traverse les écoles sans jamais ouvrir une brèche
 *    multi-école ailleurs.
 *
 * Usage : php tests/receipt_verification.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/finance/services.php';
require APP_PATH . '/modules/finance/verification.php';

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

echo "\n  PHASE 11C — UN REÇU VÉRIFIABLE\n";
echo "  ══════════════════════════════════════════════════════════\n";

// ---------------------------------------------------------------------
//  Le schéma et la permission
// ---------------------------------------------------------------------
echo "\n  Ce que la migration a posé\n";

$colonne = db_one(
    "SELECT COLUMN_NAME, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
        AND COLUMN_NAME = 'verify_token'",
    [],
    true
);

check('`payments.verify_token` existe', $colonne !== null);
check('… nullable, pour les reçus antérieurs',
    $colonne !== null && $colonne['IS_NULLABLE'] === 'YES');
check('… assez longue pour un jeton groupé',
    $colonne !== null && (int) $colonne['CHARACTER_MAXIMUM_LENGTH'] >= 14);

$index = db_value(
    "SELECT NON_UNIQUE FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
        AND INDEX_NAME = 'uq_payments_verify_token' LIMIT 1",
    [],
    true
);

check('L\'unicité est portée par un INDEX, pas par une lecture',
    $index !== null && (int) $index === 0);

// `receipt.print` : le contrôle que la migration ne pouvait pas faire.
$recu = db_one("SELECT code, description FROM permissions WHERE code = 'receipt.print'", [], true);

check('La permission `receipt.print` est toujours au catalogue', $recu !== null);
check('… et sa description dit qu\'elle est sans effet',
    $recu !== null && str_contains(mb_strtolower((string) $recu['description']), 'sans effet'),
    'une case sans effet dans l\'écran des rôles trompe l\'école qui la décoche');

// ---------------------------------------------------------------------
//  Le jeton
// ---------------------------------------------------------------------
echo "\n  Le jeton\n";

$ecole = (int) platform_scope_cli(static fn (): int => (int) db_value(
    "SELECT id FROM schools WHERE code = 'ECO-000001'", [], true));

$admin = (int) platform_scope_cli(static fn (): int => (int) db_value(
    "SELECT id FROM users WHERE username = 'admin.demo'", [], true));

check('Le jeu de démonstration est en place', $ecole > 0 && $admin > 0,
    'php database/seed_demo.php');

if ($ecole === 0 || $admin === 0) {
    printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);
    exit(1);
}

devenir($admin, $ecole);

$jeton = receipt_new_token();

check('Un jeton est tiré', $jeton !== '');
check('… dans la forme attendue, groupée par quatre',
    document_token_is_wellformed($jeton), $jeton);
check('… sans caractère ambigu (ni I, L, O, U, ni 0 ni 1)',
    preg_match('/[ILOU01]/', $jeton) !== 1);

$deux = [];

for ($i = 0; $i < 40; $i++) {
    $deux[] = receipt_new_token();
}

check('Quarante tirages donnent quarante jetons distincts',
    count(array_unique($deux)) === 40);

// ---------------------------------------------------------------------
//  Un encaissement réel
// ---------------------------------------------------------------------
echo "\n  Un encaissement réel\n";

$dette = db_one(
    'SELECT sf.id, sf.enrollment_id, sf.amount_due, sf.currency
       FROM student_fees sf
      WHERE sf.school_id = :s AND sf.is_cancelled = 0 AND sf.amount_due > 0
        AND sf.currency = :d
      ORDER BY sf.id DESC LIMIT 1',
    ['s' => $ecole, 'd' => 'CDF']
);

check('Une dette reste à encaisser dans la démonstration', $dette !== null);

if ($dette === null) {
    printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);
    exit(1);
}

$montant = (float) $dette['amount_due'];

$paiement = finance_service_record_payment((int) $dette['enrollment_id'], [
    'paid_on'           => date('Y-m-d'),
    'tendered_currency' => 'CDF',
    'tendered_amount'   => $montant,
    'credited_currency' => 'CDF',
    'method'            => 'cash',
    'payer_name'        => 'Recette 11C',
    'note'              => 'RECETTE-11C',
], [(int) $dette['id'] => $montant]);

check('L\'encaissement passe', (bool) ($paiement['ok'] ?? false),
    (string) ($paiement['message'] ?? ''));

if (!($paiement['ok'] ?? false)) {
    printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);
    exit(1);
}

$paiementId = (int) $paiement['id'];

$ligne = db_one('SELECT * FROM payments WHERE id = :i AND school_id = :s',
    ['i' => $paiementId, 's' => $ecole]);

check('Le reçu naît AVEC son jeton', (string) ($ligne['verify_token'] ?? '') !== '',
    'sans lui, le papier porterait un code mort');

$jetonRecu = (string) $ligne['verify_token'];

// ---------------------------------------------------------------------
//  La page publique
// ---------------------------------------------------------------------
echo "\n  Ce que la page publique répond\n";

$vu = receipt_service_verify($jetonRecu);

check('Le reçu est reconnu', (bool) ($vu['found'] ?? false));
check('… et déclaré valide', (bool) ($vu['valid'] ?? false));
check('… avec son numéro', ($vu['number'] ?? '') === (string) $ligne['receipt_no']);
check('… avec le montant REMIS', abs((float) ($vu['amount'] ?? 0) - $montant) < 0.005,
    'c\'est ce qu\'un fraudeur retoucherait');
check('… avec la devise remise', ($vu['currency'] ?? '') === 'CDF');
check('… avec le nom de l\'établissement', ($vu['school'] ?? '') !== '');

// CE QU'ELLE NE DIT PAS — le contrôle qui compte.
$fuite = json_encode($vu, JSON_UNESCAPED_UNICODE);

$eleve = db_one(
    'SELECT st.last_name, st.first_name, st.matricule
       FROM enrollments e JOIN students st ON st.id = e.student_id
      WHERE e.id = :e AND e.school_id = :s',
    ['e' => (int) $dette['enrollment_id'], 's' => $ecole]
);

check('Elle ne publie PAS le nom de l\'élève',
    $eleve !== null && !str_contains($fuite, (string) $eleve['last_name']),
    'ce sont des mineurs');
check('… ni son prénom',
    $eleve !== null && !str_contains($fuite, (string) $eleve['first_name']));
check('… ni son matricule',
    $eleve !== null && !str_contains($fuite, (string) $eleve['matricule']));
check('… ni le nom du payeur', !str_contains($fuite, 'Recette 11C'));
check('… ni la note interne du guichet', !str_contains($fuite, 'RECETTE-11C'));

// Un jeton inexistant, et un jeton mal formé.
check('Un jeton inconnu ne trouve rien',
    (receipt_service_verify('2345-6789-ABCD')['found'] ?? true) === false);
check('Un jeton mal formé ne touche même pas la base',
    (receipt_service_verify('bonjour')['found'] ?? true) === false);
check('Un jeton contenant une lettre ambiguë est écarté',
    (receipt_service_verify('IIII-LLLL-OOOO')['found'] ?? true) === false);

// ---------------------------------------------------------------------
//  L'annulation
// ---------------------------------------------------------------------
echo "\n  Un reçu annulé\n";

$motif = 'Cheque impaye par la banque';
$annulation = finance_service_cancel_payment($paiementId, $motif);

check('Le reçu est annulé', (bool) ($annulation['ok'] ?? false),
    (string) ($annulation['message'] ?? ''));

$apres = receipt_service_verify($jetonRecu);

check('La page publique le trouve toujours', (bool) ($apres['found'] ?? false),
    'un reçu annulé ne disparaît pas : il se déclare');
check('… et le déclare INVALIDE', ($apres['valid'] ?? true) === false);
check('… en donnant le motif', ($apres['cancelled_reason'] ?? '') === $motif,
    'une page rouge sans explication ne se conteste pas');
check('… et la date de l\'annulation', ($apres['cancelled_on'] ?? null) !== null);

// ---------------------------------------------------------------------
//  L'unicité face aux documents
// ---------------------------------------------------------------------
echo "\n  L'unicité face aux documents de la 9A\n";

$doublons = (int) platform_scope_cli(static fn (): int => (int) db_value(
    'SELECT COUNT(*) FROM payments p JOIN documents d ON d.token = p.verify_token',
    [],
    true
));

check('Aucun jeton n\'est porté à la fois par un reçu et un document',
    $doublons === 0,
    'une ambiguïté sur une preuve de paiement est pire qu\'une absence de preuve');

// La page publique commune sait distinguer les deux.
$unDocument = platform_scope_cli(static fn () => db_one(
    'SELECT token FROM documents WHERE token IS NOT NULL LIMIT 1', [], true));

if ($unDocument !== null) {
    check('Le jeton d\'un document n\'est pas lu comme un reçu',
        (receipt_service_verify((string) $unDocument['token'])['found'] ?? true) === false);
    check('… et celui d\'un reçu n\'est pas lu comme un document',
        (document_service_verify($jetonRecu)['found'] ?? true) === false);
} else {
    check('Un document existe pour éprouver la séparation', false,
        'aucun document délivré dans la démonstration');
}

// ---------------------------------------------------------------------
//  LA LISIBILITÉ DU CODE IMPRIMÉ
// ---------------------------------------------------------------------
// Produire un code n'est pas le rendre lisible. La longueur de `app.url`
// décide du nombre de modules, donc de leur taille sur le papier — et
// l'adresse d'un poste de développement est la plus courte qu'on puisse
// avoir, ce qui masque le problème jusqu'à l'impression chez le client.
echo "\n  La lisibilité du code imprimé\n";

require_once APP_PATH . '/core/qrcode.php';

$court = qr_taille_impression_mm('https://ecole.cd/v/ABCD-EFGH-JKMN', 2);
$long  = qr_taille_impression_mm(
    'https://scolarite.complexe-scolaire-saint-joseph.gombe.cd/v/ABCD-EFGH-JKMN', 2);

check('Une adresse plus longue exige un code plus grand', $long > $court,
    sprintf('%.1f mm contre %.1f mm', $long, $court));

check('… et le produit le sait AVANT d\'imprimer', $long > 17.0,
    'à 17 mm, ce code serait produit mais illisible pour un téléphone d\'entrée de gamme');

// La taille servie au reçu suit la mesure, jamais un chiffre rond.
$jetonType = 'ABCD-EFGH-JKMN';
$attendue  = max(17.0, qr_taille_impression_mm(receipt_verify_short_url($jetonType), 2));

check('Le reçu prend la taille que le code exige', $attendue >= 17.0,
    sprintf('%.1f mm pour l\'adresse configurée', $attendue));

check('Le seuil retenu est celui d\'un téléphone d\'entrée de gamme',
    abs(QR_MODULE_MIN_MM - 0.40) < 0.001, QR_MODULE_MIN_MM . ' mm par module');

// ---------------------------------------------------------------------
//  Nettoyage — hors `finally` (leçon de la 9D : abort() saute finally).
// ---------------------------------------------------------------------
platform_scope_cli(static function () use ($paiementId): void {
    db_query('DELETE FROM payment_allocations WHERE payment_id = :p', ['p' => $paiementId], true);
    db_query('DELETE FROM payments WHERE id = :p', ['p' => $paiementId], true);
    db_query("DELETE FROM audit_logs WHERE entity_type = 'payments' AND entity_id = :p",
        ['p' => $paiementId], true);
});

check('Le décor jetable est nettoyé',
    (int) platform_scope_cli(static fn (): int => (int) db_value(
        'SELECT COUNT(*) FROM payments WHERE id = :p', ['p' => $paiementId], true
    )) === 0);

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
