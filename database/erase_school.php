<?php
/**
 * EFFACEMENT D'UN ÉTABLISSEMENT — en ligne de commande uniquement.
 *
 *   php database/erase_school.php ECO-000007 --simuler
 *   php database/erase_school.php ECO-000007
 *
 * ────────────────────────────────────────────────────────────────────
 *  POURQUOI CE SCRIPT N'EST PAS UN BOUTON
 * ────────────────────────────────────────────────────────────────────
 * Un bouton qui détruit un établissement est un bouton qu'on clique par
 * erreur, et qu'une session volée clique très volontiers. Ce geste est
 * rare — quelques fois par an —, irréversible, et il porte sur les
 * dossiers scolaires de centaines de mineurs.
 *
 *   > Ce qui ne se rattrape pas ne s'expose pas à un clic.
 *
 * Il exige donc un accès au serveur, le code de l'établissement tapé à
 * la main, un motif, et une sauvegarde VÉRIFIÉE.
 *
 * ────────────────────────────────────────────────────────────────────
 *  CE QUE LE SCHÉMA IMPOSE, ET QU'UN « DELETE FROM schools » IGNORE
 * ────────────────────────────────────────────────────────────────────
 * Mesuré : 42 tables sont en ON DELETE CASCADE depuis `schools`. Un
 * simple DELETE emporterait donc aussi `subscriptions` et
 * `subscription_payments` — la comptabilité de l'ÉDITEUR, qu'aucun
 * droit à l'effacement ne concerne et qu'une obligation comptable
 * impose au contraire de garder.
 *
 *   > Le droit à l'effacement d'un client n'efface pas les écritures
 *   > comptables de son fournisseur.
 *
 * À l'inverse, `audit_logs` n'a AUCUNE clé étrangère vers `schools` :
 * ses lignes SURVIVRAIENT au CASCADE. Or `audit_log('user.update', …)`
 * y écrit nom, prénom et adresse e-mail en clair — la liste
 * `AUDIT_REDACTED_FIELDS` le prouve : on ne masque que ce qui serait
 * stocké autrement.
 *
 *   > Un effacement qui épargne le journal n'efface rien.
 *
 * Trois traitements, donc :
 *   1. les données scolaires   → effacées ;
 *   2. la comptabilité éditeur → archivée AVANT, sans donnée personnelle ;
 *   3. le journal de l'école   → effacé, remplacé par UNE trace de
 *      plateforme disant qu'un effacement a eu lieu, sans dire sur qui.
 *
 * Les fichiers déposés — photos d'élèves, logos, cachets — partent
 * aussi : une base effacée qui laisse les visages sur le disque n'efface
 * rien non plus.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script ne s'exécute qu'en ligne de commande.\n");
}

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

require APP_PATH . '/core/helpers.php';
require __DIR__ . '/dump.php';

// LES CONSTANTES SE DÉCLARENT ICI, ET PAS EN BAS DE FICHIER.
//
// PHP hisse les DÉCLARATIONS DE FONCTION, jamais les `const` : posées
// après le `exit()` final, elles n'étaient jamais définies, et le
// premier appel mourait sur « Undefined constant ». Le script ne
// s'exécutait pas du tout — trouvé par la recette, pas par la relecture.

/** Les tables qui relèvent de la comptabilité de l'éditeur. */
const ERASE_TABLES_COMPTABLES = ['subscriptions', 'subscription_payments'];

/** Au-delà, une sauvegarde n'est plus un retour crédible. */
const ERASE_SAUVEGARDE_MAX_HEURES = 24;

// `getopt()` cesse d'analyser au premier argument positionnel — et le
// code de l'école en est un. Leçon de la 10B : on lit `$argv`.
$arguments = [];
$drapeaux  = [];

foreach (array_slice($argv, 1) as $argument) {
    str_starts_with($argument, '--')
        ? $drapeaux[ltrim($argument, '-')] = true
        : $arguments[] = $argument;
}

if (isset($drapeaux['help']) || $arguments === []) {
    echo <<<TXT

    Effacement d'un établissement — School SaaS RDC
    -----------------------------------------------
      php database/erase_school.php <CODE>            Efface, sur confirmation
      php database/erase_school.php <CODE> --simuler  Montre, n'écrit rien

    Conditions, toutes vérifiées avant la moindre écriture :
      · l'établissement est en statut « cancelled » (résilié) ;
      · une sauvegarde existe ET se vérifie ;
      · le code est retapé à la main ;
      · un motif d'au moins 10 caractères est donné.

    Ce qui est gardé : les écritures comptables de l'éditeur, sans
    aucune donnée personnelle, et une trace de l'effacement.

    TXT;
    exit($arguments === [] ? 1 : 0);
}

$code    = strtoupper(trim($arguments[0]));
$simuler = isset($drapeaux['simuler']);

echo "\n";
echo "  School SaaS RDC — effacement d'un établissement\n";
echo "  ══════════════════════════════════════════════════════\n";
echo "  Établissement : {$code}\n";
echo $simuler ? "  Mode          : SIMULATION (rien ne sera écrit)\n\n" : "\n";

try {
    $pdo = backup_connexion();
} catch (PDOException $e) {
    fwrite(STDERR, "  ✗ Connexion impossible : {$e->getMessage()}\n\n");
    exit(1);
}

// =====================================================================
//  1. L'ÉTABLISSEMENT EXISTE-T-IL, ET EST-IL RÉSILIÉ ?
// =====================================================================

$requete = $pdo->prepare('SELECT * FROM schools WHERE code = :code');
$requete->execute(['code' => $code]);
$ecole = $requete->fetch();

if ($ecole === false) {
    fwrite(STDERR, "  ✗ Aucun établissement ne porte le code « {$code} ».\n\n");
    exit(1);
}

printf("  %s (%s)\n", $ecole['name'], $ecole['slug']);
printf("  Statut : %s\n\n", $ecole['status']);

// ON N'EFFACE PAS UNE ÉCOLE QUI TRAVAILLE.
// La résiliation est une décision commerciale, réversible tant qu'elle
// n'a pas été suivie d'un effacement. L'exiger d'abord met un palier
// entre « nous arrêtons » et « tout est perdu ».
if ($ecole['status'] !== 'cancelled') {
    fwrite(STDERR, "  ✗ Cet établissement n'est pas résilié (statut « {$ecole['status']} »).\n\n");
    fwrite(STDERR, "    L'effacement ne suit une résiliation, jamais une suspension ni\n");
    fwrite(STDERR, "    une activité en cours. Résiliez d'abord depuis la console de\n");
    fwrite(STDERR, "    l'éditeur, puis revenez.\n\n");
    exit(1);
}

$ecoleId = (int) $ecole['id'];

// =====================================================================
//  2. L'INVENTAIRE — ON MONTRE AVANT DE DEMANDER
// =====================================================================
//
// Une décision irréversible qu'on prend sans voir ce qu'elle emporte
// n'est pas une décision, c'est un pari. La 9D l'a établi pour la
// clôture d'une année ; cela vaut mille fois plus ici.

$inventaire = erase_inventaire($pdo, $ecoleId);

echo "  CE QUI SERA EFFACÉ\n";
echo "  ──────────────────────────────────────────────────────\n";

foreach ($inventaire['scolaire'] as $table => $n) {
    if ($n > 0) {
        printf("    %-26s %6d\n", $table, $n);
    }
}

printf("\n    %-26s %6d\n", 'entrées de journal', $inventaire['journal']);
printf("    %-26s %6d\n", 'tentatives de connexion', $inventaire['tentatives']);
printf("    %-26s %6d\n", 'fichiers déposés', $inventaire['fichiers']);

echo "\n  CE QUI SERA GARDÉ\n";
echo "  ──────────────────────────────────────────────────────\n";
printf("    %-26s %6d  (archivées sans donnée personnelle)\n",
    'écritures comptables', $inventaire['comptable']);
printf("    %-26s %6d\n", 'trace de l\'effacement', 1);

// =====================================================================
//  3. UNE SAUVEGARDE, ET ELLE DOIT SE VÉRIFIER
// =====================================================================
//
// La phase 10B existe pour ce moment précis. Une sauvegarde qu'on n'a
// jamais restaurée est une croyance ; on ne détruit pas un dossier
// scolaire sur une croyance.

echo "\n  LA SAUVEGARDE\n";
echo "  ──────────────────────────────────────────────────────\n";

$archive = erase_derniere_sauvegarde();

if ($archive === null) {
    fwrite(STDERR, "\n  ✗ Aucune sauvegarde dans storage/backups/.\n\n");
    fwrite(STDERR, "    Prenez-en une : php database/backup.php\n");
    fwrite(STDERR, "    On ne supprime pas ce qu'on ne sait pas rendre.\n\n");
    exit(1);
}

printf("    %s (%s)\n", basename($archive), date('d/m/Y H:i', (int) filemtime($archive)));

$verification = erase_verifier_sauvegarde($archive, (string) $ecole['uuid']);

if (!$verification['ok']) {
    fwrite(STDERR, "\n  ✗ Cette sauvegarde NE SE VÉRIFIE PAS : {$verification['message']}\n\n");
    fwrite(STDERR, "    Prenez-en une fraîche :\n");
    fwrite(STDERR, "      php database/backup.php\n\n");
    fwrite(STDERR, "    Et si vous voulez en outre l'éprouver de bout en bout :\n");
    fwrite(STDERR, "      php database/restore.php <archive> --verifier\n\n");
    exit(1);
}

echo "    ✓ vérifiée : " . $verification['message'] . "\n";

if ($simuler) {
    echo "\n  ──────────────────────────────────────────────────────\n";
    echo "  SIMULATION — rien n'a été écrit.\n";
    echo "  Relancez sans --simuler pour effacer réellement.\n\n";
    exit(0);
}

// =====================================================================
//  4. LA CONFIRMATION
// =====================================================================

echo "\n  ⚠  CET EFFACEMENT EST DÉFINITIF.\n";
echo "     Les dossiers scolaires, les bulletins, les paiements et les\n";
echo "     photos de cet établissement seront détruits. Seule la\n";
echo "     sauvegarde ci-dessus permettrait de revenir en arrière.\n\n";
echo "     Retapez le code de l'établissement : ";

$saisi = trim((string) fgets(STDIN));

if ($saisi !== $code) {
    echo "\n  ✗ Code incorrect. Aucune modification effectuée.\n\n";
    exit(1);
}

// UN MOTIF, comme pour la réouverture d'une année scolaire.
// Sans lui, la trace dirait QUE c'est arrivé, jamais POURQUOI.
echo "     Motif (10 caractères minimum) : ";
$motif = trim((string) fgets(STDIN));

if (mb_strlen($motif) < 10) {
    echo "\n  ✗ Motif trop court. Aucune modification effectuée.\n\n";
    exit(1);
}

// L'OPÉRATEUR DOIT EXISTER, ET ÊTRE UN COMPTE DE PLATEFORME.
//
// Première version : n'importe quelle chaîne était acceptée et recopiée
// dans la trace. Or cette trace est ce qui répondra, des années plus
// tard, à « qui a effacé cet établissement ». Une trace qui accepte un
// nom inventé ne désigne personne.
//
//   > Une responsabilité qu'on saisit au clavier sans la vérifier n'est
//   > pas une responsabilité, c'est une mention.
//
// L'objection « il a déjà un accès au serveur, il pourrait écrire en
// base » est vraie et ne change rien : rendre le contournement explicite
// vaut mieux que l'offrir dans le formulaire.
echo "     Votre identifiant d'opérateur (compte de plateforme) : ";
$operateur = trim((string) fgets(STDIN));

if ($operateur === '') {
    echo "\n  ✗ Identifiant requis. Aucune modification effectuée.\n\n";
    exit(1);
}

$operateurId = erase_identifiant_operateur($pdo, $operateur);

if ($operateurId === null) {
    echo "\n  ✗ « {$operateur} » n'est pas un compte de plateforme actif.\n\n";
    echo "    La trace de cet effacement doit désigner quelqu'un qui existe.\n";
    echo "    Aucune modification effectuée.\n\n";
    exit(1);
}

// =====================================================================
//  5. L'EFFACEMENT
// =====================================================================

echo "\n";

try {
    $resultat = erase_executer(
        $pdo,
        $ecole,
        $inventaire,
        $motif,
        $operateur,
        $operateurId,
        basename($archive),
        $verification['date']
    );
} catch (Throwable $e) {
    fwrite(STDERR, "  ✗ Échec : {$e->getMessage()}\n\n");
    fwrite(STDERR, "    La transaction a été annulée : la base est dans l'état où\n");
    fwrite(STDERR, "    elle était avant l'appel. Les fichiers, eux, n'ont pas été\n");
    fwrite(STDERR, "    touchés — ils ne partent qu'après la validation.\n\n");
    exit(1);
}

printf("  ✓ %d écriture(s) comptable(s) archivée(s)\n", $resultat['comptable']);
printf("  ✓ %d entrée(s) de journal effacée(s)\n", $resultat['journal']);
printf("  ✓ %d tentative(s) de connexion effacée(s)\n", $resultat['tentatives']);
printf("  ✓ établissement effacé (%d ligne(s) au total)\n", $resultat['lignes']);

// LES FICHIERS PARTENT APRÈS LA VALIDATION, JAMAIS AVANT.
// Une transaction annulée rend les lignes ; elle ne rend pas les
// fichiers. L'ordre inverse laisserait une base intacte pointant vers
// des photos disparues.
$fichiers = erase_fichiers($ecoleId);
printf("  ✓ %d fichier(s) déposé(s) supprimé(s)\n", $fichiers);

echo "\n  ──────────────────────────────────────────────────────\n";
echo "  Effacement terminé.\n";
printf("  Trace conservée : school_erasures #%d\n", $resultat['trace']);
echo "  La sauvegarde reste le seul retour possible.\n\n";

exit(0);

// =====================================================================
//  FONCTIONS
// =====================================================================

/**
 * Ce que porte cet établissement, table par table.
 *
 * @return array{scolaire: array<string, int>, journal: int, tentatives: int, comptable: int, fichiers: int}
 */
function erase_inventaire(PDO $pdo, int $ecoleId): array
{
    $dbName = (string) config('database.name');

    // Les tables portant `school_id`, lues dans le schéma : on ne les
    // énumère pas à la main, sans quoi la liste vieillirait à la
    // première migration.
    $requete = $pdo->prepare(
        "SELECT table_name FROM information_schema.columns
          WHERE table_schema = :base AND column_name = 'school_id'
          ORDER BY table_name"
    );
    $requete->execute(['base' => $dbName]);

    $scolaire  = [];
    $journal   = 0;
    $comptable = 0;

    foreach ($requete->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $compte = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE school_id = :s');
        $compte->execute(['s' => $ecoleId]);
        $n = (int) $compte->fetchColumn();

        if ($table === 'audit_logs') {
            $journal = $n;
            continue;
        }

        if (in_array($table, ERASE_TABLES_COMPTABLES, true)) {
            continue;   // compté plus bas, à l'identique de l'archivage
        }

        $scolaire[$table] = $n;
    }

    // LE COMPTE ANNONCÉ EST CELUI QUI SERA ÉCRIT.
    //
    // Première version : l'inventaire additionnait les lignes de
    // `subscriptions` ET de `subscription_payments`. L'archivage, lui,
    // ne recopiait que les secondes. Une école en essai gratuit voyait
    // donc « écritures comptables : 1, archivées » puis, à l'exécution,
    // « 0 archivée(s) » — sans que rien ne signale la contradiction.
    //
    //   > Un inventaire qui ne compte pas ce que l'exécution écrira
    //   > n'est pas un inventaire, c'est une estimation.
    //
    // La requête ci-dessous reproduit EXACTEMENT la règle de
    // `erase_archiver_comptabilite()`. Les deux se lisent ensemble ;
    // modifier l'une sans l'autre se verra à la première simulation.
    $ecritures = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM subscription_payments WHERE school_id = :s1)
          + (SELECT COUNT(*) FROM subscriptions s
              WHERE s.school_id = :s2
                AND s.price_amount IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM subscription_payments p
                     WHERE p.subscription_id = s.id
                       AND p.status <> \'cancelled\'
                ))'
    );
    $ecritures->execute(['s1' => $ecoleId, 's2' => $ecoleId]);
    $comptable = (int) $ecritures->fetchColumn();

    // Les tentatives de connexion : hors de toute cascade, il faut aller
    // les chercher par l'identifiant des comptes.
    $tentatives = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts la
           JOIN users u ON la.identifier = u.username OR la.identifier = u.email
          WHERE u.school_id = :s'
    );
    $tentatives->execute(['s' => $ecoleId]);

    return [
        'scolaire'   => $scolaire,
        'journal'    => $journal,
        'tentatives' => (int) $tentatives->fetchColumn(),
        'comptable'  => $comptable,
        'fichiers'   => erase_compter_fichiers($ecoleId),
    ];
}

/** Les dossiers de fichiers d'un établissement. @return array<int, string> */
function erase_dossiers(int $ecoleId): array
{
    $racine = BASE_PATH . '/storage/uploads';

    return [
        $racine . '/photos/' . $ecoleId,
        $racine . '/logos/' . $ecoleId,
        $racine . '/documents/' . $ecoleId,
    ];
}

function erase_compter_fichiers(int $ecoleId): int
{
    $n = 0;

    foreach (erase_dossiers($ecoleId) as $dossier) {
        if (!is_dir($dossier)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS)
        ) as $entree) {
            if ($entree->isFile()) {
                $n++;
            }
        }
    }

    return $n;
}

/** La sauvegarde la plus récente, ou null. */
function erase_derniere_sauvegarde(): ?string
{
    $fichiers = glob(BASE_PATH . '/storage/backups/*.{zip,sql}', GLOB_BRACE) ?: [];

    if ($fichiers === []) {
        return null;
    }

    usort($fichiers, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    return $fichiers[0];
}

/**
 * Vérifie que cette sauvegarde est un VRAI chemin de retour pour CETTE
 * école.
 *
 * CE QU'IL NE FAUT PAS EXIGER
 * ---------------------------
 * La première version rejouait `restore.php --verifier`, qui compare la
 * base entière aux empreintes du manifeste. Sur une plateforme vivante
 * c'est inapplicable : une simple connexion d'une AUTRE école, entre la
 * sauvegarde et l'effacement, fait diverger une empreinte et bloque
 * l'opération. L'éditeur tournerait en rond, et finirait par contourner
 * le contrôle.
 *
 *   > Un garde-fou qu'on ne peut pas satisfaire n'est pas franchi : il
 *   > est contourné.
 *
 * CE QU'IL FAUT EXIGER
 * --------------------
 * Que l'archive soit lisible, qu'elle vienne de la même version du
 * produit, qu'elle soit récente, et surtout qu'elle CONTIENNE
 * l'établissement qu'on s'apprête à détruire — c'est cela, un chemin de
 * retour. Une archive parfaite d'une base où cette école n'était pas
 * encore inscrite n'en est pas un.
 *
 * @return array{ok: bool, message: string, date: string}
 */
function erase_verifier_sauvegarde(string $archive, string $uuidEcole): array
{
    $heures = (time() - (int) filemtime($archive)) / 3600;

    if ($heures > ERASE_SAUVEGARDE_MAX_HEURES) {
        return ['ok' => false, 'date' => '', 'message' => sprintf(
            'elle date de %d heure(s) ; %d au maximum',
            (int) $heures,
            ERASE_SAUVEGARDE_MAX_HEURES
        )];
    }

    if (!str_ends_with(strtolower($archive), '.zip')) {
        return ['ok' => false, 'date' => '', 'message' =>
            'seule une archive .zip porte les fichiers déposés ; un .sql seul '
            . 'ne suffit pas comme chemin de retour'];
    }

    if (!extension_loaded('zip')) {
        return ['ok' => false, 'date' => '', 'message' => 'extension zip absente'];
    }

    $zip = new ZipArchive();

    if ($zip->open($archive) !== true) {
        return ['ok' => false, 'date' => '', 'message' => 'archive illisible'];
    }

    $manifeste = json_decode((string) $zip->getFromName('manifeste.json'), true);

    if (!is_array($manifeste) || !isset($manifeste['tables'])) {
        $zip->close();

        return ['ok' => false, 'date' => '', 'message' => 'manifeste absent ou illisible'];
    }

    // La même version du produit : les migrations de l'archive doivent
    // toutes être connues d'ici. C'est le contrôle que `restore.php`
    // applique déjà, et pour la même raison.
    $connues = [];

    foreach (glob(BASE_PATH . '/database/migrations/*.sql') ?: [] as $fichier) {
        $connues[basename($fichier)] = true;
    }

    $connues['schema.sql'] = true;

    foreach (glob(BASE_PATH . '/database/seeds/*.sql') ?: [] as $fichier) {
        $connues['seeds/' . basename($fichier)] = true;
    }

    foreach (array_keys((array) ($manifeste['migrations'] ?? [])) as $nom) {
        if (!isset($connues[$nom])) {
            $zip->close();

            return ['ok' => false, 'date' => '', 'message' =>
                'elle vient d\'une version plus récente du produit (' . $nom . ')'];
        }
    }

    // LE CONTRÔLE QUI COMPTE : cette école est-elle DEDANS ?
    // Une archive impeccable d'une base où l'établissement n'était pas
    // encore inscrit n'est pas un chemin de retour pour lui.
    $sql = (string) $zip->getFromName('base.sql');
    $zip->close();

    if ($sql === '') {
        return ['ok' => false, 'date' => '', 'message' => 'base.sql absent de l\'archive'];
    }

    if (!str_contains($sql, $uuidEcole)) {
        return ['ok' => false, 'date' => '', 'message' =>
            'cet établissement n\'y figure pas : l\'archive est antérieure à son '
            . 'inscription, ou vient d\'une autre base'];
    }

    return [
        'ok'      => true,
        'date'    => date('Y-m-d H:i:s', (int) filemtime($archive)),
        'message' => sprintf(
            'lisible, %d table(s), cet établissement y figure, prise il y a %s',
            count($manifeste['tables']),
            $heures < 1 ? 'moins d\'une heure' : (int) $heures . ' heure(s)'
        ),
    ];
}

/**
 * Archive la comptabilité, efface le reste, écrit la trace.
 *
 * TOUT EN UNE TRANSACTION. Un effacement interrompu au milieu laisserait
 * une école à moitié détruite — des bulletins sans élèves, des paiements
 * sans inscriptions — c'est-à-dire pire que l'état de départ ET pire que
 * l'état d'arrivée.
 *
 * @param array<string, mixed> $ecole
 * @param array{scolaire: array<string, int>, journal: int, tentatives: int, comptable: int, fichiers: int} $inventaire
 * @return array{comptable: int, journal: int, tentatives: int, lignes: int, trace: int}
 */
function erase_executer(
    PDO $pdo,
    array $ecole,
    array $inventaire,
    string $motif,
    string $operateur,
    int $operateurId,
    string $archive,
    string $verifieeLe
): array {
    $ecoleId = (int) $ecole['id'];

    $pdo->beginTransaction();

    try {
        // --- 1. La comptabilité, AVANT que le CASCADE ne l'emporte ----
        $archivees = erase_archiver_comptabilite($pdo, $ecole);

        // --- 2. Les tentatives de connexion --------------------------
        //
        // `login_attempts` n'a NI `school_id` NI clé étrangère : aucun
        // CASCADE ne l'emporte. Elle garde pourtant l'identifiant saisi
        // et l'adresse IP — deux données personnelles. Mesuré : après
        // un effacement, trois tentatives survivaient, nommant le
        // directeur et son adresse.
        //
        //   > Un inventaire qui n'énumère que ce qu'il sait compter ne
        //   > mesure pas ce qui reste.
        //
        // Les identifiants sont uniques SUR TOUTE LA PLATEFORME
        // (uq_users_username, uq_users_email) : effacer par identifiant
        // ne peut donc pas emporter les tentatives d'une autre école.
        // Et cela DOIT précéder la suppression des comptes.
        $effaceTentatives = $pdo->prepare(
            'DELETE la FROM login_attempts la
               JOIN users u ON la.identifier = u.username OR la.identifier = u.email
              WHERE u.school_id = :s'
        );
        $effaceTentatives->execute(['s' => $ecoleId]);
        $tentatives = $effaceTentatives->rowCount();

        // --- 3. Le journal de l'école --------------------------------
        //
        // Il n'a pas de clé étrangère vers `schools` : sans cet ordre
        // explicite, ses lignes survivraient au CASCADE, avec les noms
        // et les adresses qu'elles portent.
        $effaceJournal = $pdo->prepare('DELETE FROM audit_logs WHERE school_id = :s');
        $effaceJournal->execute(['s' => $ecoleId]);
        $journal = $effaceJournal->rowCount();

        // --- 4. L'établissement, et les 42 tables en cascade ----------
        $lignesAvant = array_sum($inventaire['scolaire']);

        $effaceEcole = $pdo->prepare('DELETE FROM schools WHERE id = :id');
        $effaceEcole->execute(['id' => $ecoleId]);

        // --- 5. La trace, qui survit à tout --------------------------
        $trace = $pdo->prepare(
            'INSERT INTO school_erasures
                 (uuid, school_code, school_name, school_slug,
                  erased_by, erased_by_username, reason,
                  backup_archive, backup_verified_at, counts)
             VALUES (:uuid, :code, :nom, :slug, :par_id, :par_nom, :motif,
                     :archive, :verifiee, :comptes)'
        );

        $trace->execute([
            'uuid'     => erase_uuid(),
            'code'     => (string) $ecole['code'],
            'nom'      => (string) $ecole['name'],
            'slug'     => (string) $ecole['slug'],
            'par_id'   => $operateurId,
            'par_nom'  => $operateur,
            'motif'    => mb_substr($motif, 0, 255),
            'archive'  => $archive,
            'verifiee' => $verifieeLe,
            'comptes'  => json_encode([
                'scolaire'   => $lignesAvant,
                'journal'    => $journal,
                'tentatives' => $tentatives,
                'comptable'  => $archivees,
                'fichiers'   => $inventaire['fichiers'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $traceId = (int) $pdo->lastInsertId();

        // --- 6. La trace au journal de la PLATEFORME -----------------
        //
        // `school_id` vaut NULL : l'entrée n'appartient plus à personne,
        // et elle ne nomme aucun élève. Elle dit qu'un effacement a eu
        // lieu, pas sur qui.
        $pdo->prepare(
            "INSERT INTO audit_logs
                 (school_id, user_id, action, entity_type, entity_id,
                  new_values, description, created_at)
             VALUES (NULL, :uid, 'school.erase', 'school_erasures', :trace,
                     :valeurs, :description, NOW())"
        )->execute([
            'uid'         => $operateurId,
            'trace'       => $traceId,
            'valeurs'     => json_encode([
                'code'    => (string) $ecole['code'],
                'archive' => $archive,
                'lignes'  => $lignesAvant,
            ], JSON_UNESCAPED_UNICODE),
            'description' => 'Effacement définitif de l\'établissement '
                . (string) $ecole['code'] . ' — ' . mb_substr($motif, 0, 120),
        ]);

        $pdo->commit();

        return [
            'comptable'  => $archivees,
            'journal'    => $journal,
            'tentatives' => $tentatives,
            'lignes'     => $lignesAvant,
            'trace'      => $traceId,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Copie les écritures comptables dans `billing_archive`.
 *
 * Aucune donnée personnelle n'est recopiée : un montant, une date, un
 * moyen de paiement, une référence — et le nom de l'établissement, qui
 * est celui d'une personne morale.
 *
 * @param array<string, mixed> $ecole
 */
function erase_archiver_comptabilite(PDO $pdo, array $ecole): int
{
    // UNE CRÉANCE IMPAYÉE EST UNE ÉCRITURE COMPTABLE.
    //
    // Première version : cette fonction ne lisait que
    // `subscription_payments`. Un abonnement facturé dont AUCUN
    // versement n'a été enregistré — l'école est partie sans payer, ou
    // le versement est resté « en attente » chez l'opérateur — était
    // détruit sans trace. L'effacement du client effaçait la dette du
    // client, à son bénéfice.
    //
    //   > Le droit à l'effacement d'un client n'efface pas les
    //   > écritures comptables de son fournisseur — la créance en est
    //   > une, et c'est même la seule qui coûte quelque chose.
    //
    // Un abonnement SANS tarif figé (`price_amount IS NULL`) n'est pas
    // archivé : il n'engage rien. C'est la décision de la phase 7B2 —
    // inventer une dette est pire que l'avouer — et `billing_archive`
    // exige d'ailleurs un montant.
    $lecture = $pdo->prepare(
        'SELECT p.*, s.plan_id, s.billing_cycle, s.status AS sub_status,
                s.starts_on, s.ends_on
           FROM subscription_payments p
           LEFT JOIN subscriptions s ON s.id = p.subscription_id
          WHERE p.school_id = :s
          ORDER BY p.id'
    );
    $lecture->execute(['s' => (int) $ecole['id']]);

    $ecriture = $pdo->prepare(
        'INSERT INTO billing_archive
             (school_code, school_name, plan_id, billing_cycle, subscription_status,
              subscription_starts_on, subscription_ends_on,
              amount, currency, method, provider, reference, payment_status,
              paid_at, original_payment_id)
         VALUES (:code, :nom, :plan, :cycle, :sub_statut, :debut, :fin,
                 :montant, :devise, :methode, :fournisseur, :reference, :statut,
                 :paye_le, :origine)'
    );

    $n = 0;

    foreach ($lecture as $ligne) {
        $ecriture->execute([
            'code'        => (string) $ecole['code'],
            'nom'         => (string) $ecole['name'],
            'plan'        => $ligne['plan_id'],
            'cycle'       => $ligne['billing_cycle'],
            'sub_statut'  => $ligne['sub_status'],
            'debut'       => $ligne['starts_on'],
            'fin'         => $ligne['ends_on'],
            'montant'     => $ligne['amount'],
            'devise'      => $ligne['currency'],
            'methode'     => $ligne['method'],
            'fournisseur' => $ligne['provider'],
            'reference'   => $ligne['reference'],
            'statut'      => $ligne['status'],
            'paye_le'     => $ligne['paid_at'],
            'origine'     => $ligne['id'],
        ]);
        $n++;
    }

    // Les abonnements facturés que personne n'a payés.
    $creances = $pdo->prepare(
        'SELECT s.* FROM subscriptions s
          WHERE s.school_id = :s
            AND s.price_amount IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM subscription_payments p
                 WHERE p.subscription_id = s.id
                   AND p.status <> \'cancelled\'
            )
          ORDER BY s.id'
    );
    $creances->execute(['s' => (int) $ecole['id']]);

    foreach ($creances as $sub) {
        $ecriture->execute([
            'code'        => (string) $ecole['code'],
            'nom'         => (string) $ecole['name'],
            'plan'        => $sub['plan_id'],
            'cycle'       => $sub['billing_cycle'],
            'sub_statut'  => $sub['status'],
            'debut'       => $sub['starts_on'],
            'fin'         => $sub['ends_on'],
            'montant'     => $sub['price_amount'],
            'devise'      => $sub['price_currency'],
            'methode'     => null,
            'fournisseur' => null,
            'reference'   => null,
            'statut'      => 'unpaid',
            'paye_le'     => null,
            'origine'     => null,
        ]);
        $n++;
    }

    return $n;
}

/** L'identifiant numérique de l'opérateur, s'il existe encore. */
function erase_identifiant_operateur(PDO $pdo, string $username): ?int
{
    $requete = $pdo->prepare(
        'SELECT id FROM users WHERE username = :u AND school_id IS NULL AND deleted_at IS NULL'
    );
    $requete->execute(['u' => $username]);

    $id = $requete->fetchColumn();

    return $id === false ? null : (int) $id;
}

/** Supprime les fichiers déposés de l'établissement. */
function erase_fichiers(int $ecoleId): int
{
    $n = 0;

    foreach (erase_dossiers($ecoleId) as $dossier) {
        if (!is_dir($dossier)) {
            continue;
        }

        $entrees = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entrees as $entree) {
            if ($entree->isDir()) {
                rmdir($entree->getPathname());
                continue;
            }

            unlink($entree->getPathname());
            $n++;
        }

        rmdir($dossier);
    }

    return $n;
}

function erase_uuid(): string
{
    $o = random_bytes(16);
    $o[6] = chr((ord($o[6]) & 0x0f) | 0x40);
    $o[8] = chr((ord($o[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($o), 4));
}
