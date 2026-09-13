<?php
/**
 * Installateur — à exécuter EN LIGNE DE COMMANDE uniquement.
 *
 *   php database/install.php
 *   php database/install.php --fresh     (supprime et recrée la base)
 *   php database/install.php --demo      (ajoute un établissement de démonstration)
 *
 * Choix délibéré : pas d'installateur web. Un fichier install.php
 * accessible depuis le navigateur et oublié après la mise en ligne est
 * une porte d'entrée classique vers la prise de contrôle d'un site.
 *
 * Sur hébergement mutualisé sans accès SSH, importer manuellement
 * schema.sql puis les seeds via phpMyAdmin, dans l'ordre numéroté,
 * puis exécuter ce script en local pour générer le compte administrateur.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script ne s'exécute qu'en ligne de commande.\n");
}

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

require APP_PATH . '/core/helpers.php';
require APP_PATH . '/core/logger.php';
require __DIR__ . '/runner.php';

$options = getopt('', ['fresh', 'demo', 'help']);

if (isset($options['help'])) {
    echo <<<TXT

    Installateur School SaaS RDC
    ----------------------------
      php database/install.php            Installe le schéma et les données de référence
      php database/install.php --fresh    Supprime la base existante et réinstalle tout
      php database/install.php --demo     Crée en plus un établissement de démonstration

    TXT;
    exit(0);
}

$fresh   = isset($options['fresh']);
$withDemo = isset($options['demo']);

$dbHost = (string) config('database.host');
$dbPort = (int) config('database.port', 3306);
$dbName = (string) config('database.name');
$dbUser = (string) config('database.user');
$dbPass = (string) config('database.password');

echo "\n";
echo "  School SaaS RDC — installation\n";
echo "  ──────────────────────────────────────────────\n";
echo "  Serveur  : {$dbHost}:{$dbPort}\n";
echo "  Base     : {$dbName}\n";
echo "  Utilisateur : {$dbUser}\n\n";

// ---------------------------------------------------------------------
// Connexion au serveur MySQL (sans sélectionner de base)
// ---------------------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "  ✗ Connexion impossible : {$e->getMessage()}\n\n");
    fwrite(STDERR, "    Vérifiez app/config/config.local.php (copiez config.local.example.php).\n\n");
    exit(1);
}

echo "  ✓ Connexion au serveur MySQL (" . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ")\n";

// ---------------------------------------------------------------------
// Création de la base
// ---------------------------------------------------------------------
if ($fresh) {
    echo "\n  ⚠  --fresh : la base « {$dbName} » va être SUPPRIMÉE avec toutes ses données.\n";
    echo "     Confirmez en tapant le nom de la base : ";

    $confirmation = trim((string) fgets(STDIN));

    if ($confirmation !== $dbName) {
        echo "  ✗ Confirmation incorrecte. Aucune modification effectuée.\n\n";
        exit(1);
    }

    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    echo "  ✓ Base supprimée\n";
}

$pdo->exec(
    "CREATE DATABASE IF NOT EXISTS `{$dbName}` "
    . "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);
$pdo->exec("USE `{$dbName}`");

echo "  ✓ Base « {$dbName} » prête\n\n";

// ---------------------------------------------------------------------
// Exécution des fichiers SQL
// ---------------------------------------------------------------------
// L'installateur n'a pas sa propre liste de fichiers : il applique le
// catalogue commun (schéma, migrations, puis seeds), et l'enregistre au
// fur et à mesure. C'est ce qui garantit qu'une base fraîchement
// installée et une base migrée soient identiques, et que migrate.php
// n'essaie jamais de rejouer ce que l'installateur a déjà appliqué.
runner_ledger_ensure($pdo);

$catalog = runner_catalog();

foreach (runner_autobaseline($pdo, $catalog, runner_applied($pdo)) as $name) {
    printf("  ≡ %-38s déjà en place, enregistré\n", $name);
}

$applied = runner_applied($pdo);
$total   = 0;

foreach ($catalog as $file) {
    if (isset($applied[$file['name']])) {
        continue;
    }

    if (!is_file($file['path'])) {
        fwrite(STDERR, "  ✗ Fichier manquant : {$file['path']}\n");
        exit(1);
    }

    try {
        $count = runner_apply($pdo, $file);
    } catch (RuntimeException $e) {
        fwrite(STDERR, "\n  ✗ {$e->getMessage()}\n\n");
        exit(1);
    }

    printf("  ✓ %-38s %3d instructions\n", $file['name'], $count);
    $total++;
}

if ($total === 0) {
    echo "  → Base déjà à jour, aucun fichier à appliquer.\n";
}

// ---------------------------------------------------------------------
// Compte super administrateur
// ---------------------------------------------------------------------
echo "\n  Compte super administrateur de la plateforme\n";
echo "  ──────────────────────────────────────────────\n";

$exists = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE school_id IS NULL"
)->fetchColumn();

if ($exists > 0) {
    echo "  → Un compte plateforme existe déjà, création ignorée.\n";
} else {
    $username = prompt('  Identifiant', 'superadmin');
    $email    = prompt('  Email', 'admin@example.cd');
    $password = prompt('  Mot de passe (10 caractères min., 1 maj., 1 min., 1 chiffre)', '');

    while (strlen($password) < 10
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[0-9]/', $password)
    ) {
        echo "  ✗ Mot de passe trop faible.\n";
        $password = prompt('  Mot de passe', '');
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (uuid, school_id, username, email, password_hash,
                            last_name, first_name, status, password_changed_at)
         VALUES (:uuid, NULL, :username, :email, :hash, :last_name, :first_name, :status, NOW())'
    );

    $statement->execute([
        'uuid'       => str_uuid(),
        'username'   => $username,
        'email'      => $email,
        'hash'       => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        'last_name'  => 'Administrateur',
        'first_name' => 'Super',
        'status'     => 'active',
    ]);

    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :user_id, id FROM roles WHERE code = :code AND school_id IS NULL'
    )->execute(['user_id' => $userId, 'code' => 'SUPER_ADMIN']);

    echo "  ✓ Compte créé : {$username}\n";
}

// ---------------------------------------------------------------------
// Établissement de démonstration
// ---------------------------------------------------------------------
if ($withDemo) {
    echo "\n  Établissement de démonstration\n";
    echo "  ──────────────────────────────────────────────\n";

    $demoExists = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE code = 'ECO-000001'")->fetchColumn();

    if ($demoExists > 0) {
        echo "  → Déjà présent, création ignorée.\n";
    } else {
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'INSERT INTO schools (uuid, code, slug, name, short_name, school_type,
                                      province, city, commune, address, phone, email,
                                      director_name, status)
                 VALUES (:uuid, :code, :slug, :name, :short, :type,
                         :province, :city, :commune, :address, :phone, :email,
                         :director, :status)'
            )->execute([
                'uuid'     => str_uuid(),
                'code'     => 'ECO-000001',
                'slug'     => 'complexe-scolaire-demo',
                'name'     => 'Complexe Scolaire de Démonstration',
                'short'    => 'CS Démo',
                'type'     => 'prive',
                'province' => 'Kinshasa',
                'city'     => 'Kinshasa',
                'commune'  => 'Gombe',
                'address'  => 'Avenue de la Démonstration n°1',
                'phone'    => '+243810000000',
                'email'    => 'contact@demo.cd',
                'director' => 'MUKENDI Jean Pierre',
                'status'   => 'active',
            ]);

            $schoolId = (int) $pdo->lastInsertId();

            // Cycles dispensés : primaire, CTEB et humanités (pas de maternelle).
            $pdo->prepare(
                'INSERT INTO school_cycles (school_id, cycle_id, is_active)
                 SELECT :school_id, id, 1 FROM education_cycles
                  WHERE code IN (\'PRIMAIRE\', \'CTEB\', \'HUMANITES\')'
            )->execute(['school_id' => $schoolId]);

            // Année scolaire courante, calculée sur le calendrier congolais
            // (rentrée en septembre, fin en juillet).
            $startYear = (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;

            $pdo->prepare(
                'INSERT INTO academic_years (school_id, code, name, starts_on, ends_on, status, is_current)
                 VALUES (:school_id, :code, :name, :starts, :ends, :status, 1)'
            )->execute([
                'school_id' => $schoolId,
                'code'      => $startYear . '-' . ($startYear + 1),
                'name'      => 'Année scolaire ' . $startYear . '-' . ($startYear + 1),
                'starts'    => $startYear . '-09-01',
                'ends'      => ($startYear + 1) . '-07-31',
                'status'    => 'active',
            ]);

            // Abonnement d'essai de 60 jours.
            $pdo->prepare(
                'INSERT INTO subscriptions (school_id, plan_id, status, billing_cycle, starts_on, ends_on)
                 SELECT :school_id, id, \'trial\', \'yearly\', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                   FROM plans WHERE code = \'DECOUVERTE\''
            )->execute(['school_id' => $schoolId]);

            // Administrateur de l'établissement.
            $demoPassword = 'Demo' . random_int(1000, 9999) . 'Ecole';

            $statement = $pdo->prepare(
                'INSERT INTO users (uuid, school_id, username, email, password_hash,
                                    last_name, post_name, first_name, gender, status,
                                    must_change_password, password_changed_at)
                 VALUES (:uuid, :school_id, :username, :email, :hash,
                         :last_name, :post_name, :first_name, :gender, \'active\', 1, NOW())'
            );

            $statement->execute([
                'uuid'       => str_uuid(),
                'school_id'  => $schoolId,
                'username'   => 'admin.demo',
                'email'      => 'admin@demo.cd',
                'hash'       => password_hash($demoPassword, PASSWORD_BCRYPT, ['cost' => 12]),
                'last_name'  => 'MUKENDI',
                'post_name'  => 'Kabamba',
                'first_name' => 'Jean Pierre',
                'gender'     => 'M',
            ]);

            $demoUserId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id)
                 SELECT :user_id, id FROM roles WHERE code = :code AND school_id IS NULL'
            )->execute(['user_id' => $demoUserId, 'code' => 'SCHOOL_ADMIN']);

            $pdo->commit();

            echo "  ✓ Établissement  : Complexe Scolaire de Démonstration (ECO-000001)\n";
            echo "  ✓ Identifiant    : admin.demo\n";
            echo "  ✓ Mot de passe   : {$demoPassword}\n";
            echo "    (changement imposé à la première connexion)\n";
        } catch (Throwable $e) {
            $pdo->rollBack();
            fwrite(STDERR, "  ✗ Échec de la création : {$e->getMessage()}\n");
            exit(1);
        }
    }
}

echo "\n  ──────────────────────────────────────────────\n";
echo "  Installation terminée.\n\n";
echo "  Prochaines étapes :\n";
echo "    1. Faire pointer la racine web sur le dossier public/\n";
echo "    2. Ouvrir " . config('app.url') . "\n";
echo "    3. En production : passer app.debug à false dans config.local.php\n\n";

exit(0);

// =====================================================================
//  Fonctions utilitaires de l'installateur
// =====================================================================


/** Demande une valeur à l'utilisateur, avec valeur par défaut. */
function prompt(string $label, string $default = ''): string
{
    echo $default !== '' ? "{$label} [{$default}] : " : "{$label} : ";

    $value = trim((string) fgets(STDIN));

    return $value !== '' ? $value : $default;
}
