<?php
/**
 * Phase 10C — l'effacement d'un établissement.
 *
 * CE QUE CES TESTS PROTÈGENT
 * --------------------------
 *  · l'effacement est REFUSÉ tant que l'école n'est pas résiliée ;
 *  · il est REFUSÉ sans sauvegarde, et sans que cette école y figure ;
 *  · il est REFUSÉ si le code n'est pas retapé, ou le motif trop court ;
 *  · le journal de l'école part AUSSI — il n'a aucune clé étrangère
 *    vers `schools` et survivrait au CASCADE, avec les noms et les
 *    adresses qu'il porte ;
 *  · la comptabilité de l'éditeur SURVIT, sans donnée personnelle ;
 *  · une trace survit, et elle ne nomme aucun élève ;
 *  · les fichiers déposés partent avec la base ;
 *  · ET SURTOUT : l'école voisine ne perd pas une ligne.
 *
 * Sur un décor jetable — on détruit une école pour prouver qu'on sait
 * la détruire proprement, jamais sur la base de travail.
 *
 * Usage : php tests/school_erasure.php
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

echo "\n  PHASE 10C — L'EFFACEMENT D'UN ÉTABLISSEMENT\n";
echo "  ══════════════════════════════════════════════════════════\n";

$base   = 'school_saas_recette_effacement';
$racine = sys_get_temp_dir() . '/effacement-' . bin2hex(random_bytes(6));

function serveur(): PDO
{
    return new PDO(
        'mysql:host=' . (string) config('database.host')
        . ';port=' . (int) config('database.port', 3306) . ';charset=utf8mb4',
        (string) config('database.user'),
        (string) config('database.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function base_decor(string $base): PDO
{
    return new PDO(
        'mysql:host=' . (string) config('database.host')
        . ';port=' . (int) config('database.port', 3306)
        . ';dbname=' . $base . ';charset=utf8mb4',
        (string) config('database.user'),
        (string) config('database.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function lancer(string $racine, string $script, array $args = [], string $entree = ''): array
{
    $commande = 'php ' . escapeshellarg($racine . '/database/' . $script);

    foreach ($args as $a) {
        $commande .= ' ' . escapeshellarg($a);
    }

    $processus = proc_open(
        $commande,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $tuyaux
    );

    if (!is_resource($processus)) {
        return ['sortie' => '', 'erreur' => 'proc_open a échoué', 'code' => -1];
    }

    fwrite($tuyaux[0], $entree);
    fclose($tuyaux[0]);

    $sortie = (string) stream_get_contents($tuyaux[1]);
    $erreur = (string) stream_get_contents($tuyaux[2]);

    fclose($tuyaux[1]);
    fclose($tuyaux[2]);

    return ['sortie' => $sortie, 'erreur' => $erreur, 'code' => proc_close($processus)];
}

function effacer_dossier(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }

    $entrees = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entrees as $entree) {
        $entree->isDir() ? rmdir($entree->getPathname()) : unlink($entree->getPathname());
    }

    rmdir($chemin);
}

/** Crée un établissement complet dans le décor. @return array{id:int,code:string,uuid:string} */
function creer_ecole(PDO $db, string $code, string $nom, string $slug): array
{
    $uuid = sprintf('%s-%s-4%s-a%s-%s',
        bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
        substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1),
        bin2hex(random_bytes(6)));

    $db->prepare(
        "INSERT INTO schools (uuid, code, slug, name, school_type, status, default_currency, created_at)
         VALUES (:uuid, :code, :slug, :nom, 'prive', 'active', 'CDF', NOW())"
    )->execute(['uuid' => $uuid, 'code' => $code, 'slug' => $slug, 'nom' => $nom]);

    return ['id' => (int) $db->lastInsertId(), 'code' => $code, 'uuid' => $uuid];
}

/** Pose des données représentatives : journal, comptabilité, fichiers. */
function garnir(PDO $db, string $racine, int $ecoleId, string $etiquette): void
{
    // Une entrée de journal PORTANT UN NOM ET UNE ADRESSE — exactement ce
    // que `audit_log('user.update', …)` écrit dans le produit.
    $db->prepare(
        "INSERT INTO audit_logs (school_id, action, entity_type, entity_id, new_values, description, created_at)
         VALUES (:s, 'user.update', 'users', 1, :v, :d, NOW())"
    )->execute([
        's' => $ecoleId,
        // CHAQUE ÉCOLE PORTE UN NOM DISTINCT.
        // Première version : les deux journaux portaient « Espérance
        // KABILA ». L'assertion cherchait donc un nom qui appartenait
        // AUSSI à l'école survivante, et accusait un effacement correct.
        //
        //   > Une sonde qui cherche une trace partagée ne mesure pas
        //   > l'effacement, elle mesure le voisinage.
        'v' => json_encode(['last_name' => 'KABILA-' . mb_strtoupper($etiquette),
                            'first_name' => 'Espérance',
                            'email' => 'esperance.' . $etiquette . '@exemple.cd'], JSON_UNESCAPED_UNICODE),
        'd' => 'Modification du compte de Espérance KABILA-' . mb_strtoupper($etiquette),
    ]);

    // Un abonnement et un paiement : la comptabilité de l'éditeur.
    $db->prepare(
        "INSERT INTO subscriptions (school_id, plan_id, status, billing_cycle,
                                    price_amount, price_currency, starts_on, ends_on, created_at)
         VALUES (:s, 1, 'active', 'yearly', 120.00, 'USD', '2026-01-01', '2026-12-31', NOW())"
    )->execute(['s' => $ecoleId]);

    $abonnement = (int) $db->lastInsertId();

    $db->prepare(
        "INSERT INTO subscription_payments (school_id, subscription_id, amount, currency,
                                            method, provider, reference, status, paid_at)
         VALUES (:s, :a, 120.00, 'USD', 'mobile_money', 'mpesa', :r, 'confirmed', NOW())"
    )->execute(['s' => $ecoleId, 'a' => $abonnement, 'r' => 'REF-' . $etiquette]);

    // UNE CRÉANCE : un abonnement facturé que personne n'a payé.
    //
    // C'est le cas qui coûte. L'archivage ne lisait que
    // `subscription_payments` : une école partie sans payer emportait sa
    // dette avec elle, et l'éditeur n'en gardait aucune trace.
    $db->prepare(
        "INSERT INTO subscriptions (school_id, plan_id, status, billing_cycle,
                                    price_amount, price_currency, starts_on, ends_on, created_at)
         VALUES (:s, 2, 'past_due', 'yearly', 250.00, 'USD', '2027-01-01', '2027-12-31', NOW())"
    )->execute(['s' => $ecoleId]);

    // UN RÔLE COMPOSÉ PAR L'ÉCOLE (phase 11B).
    //
    // `roles` n'avait aucune ligne portant `school_id` quand cette
    // procédure a été écrite. Vérifier la règle DELETE_RULE dans
    // `information_schema` n'est pas la même chose que voir la ligne
    // disparaître.
    $db->prepare(
        "INSERT INTO roles (school_id, code, name, level, is_system, is_active, created_at)
         VALUES (:s, :c, 'Surveillant maison', 30, 0, 1, NOW())"
    )->execute(['s' => $ecoleId, 'c' => 'E' . $ecoleId . '_SURVEILLANT']);

    $roleMaison = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO role_permissions (role_id, permission_id)
         SELECT :r, id FROM permissions WHERE is_platform = 0 ORDER BY id LIMIT 2'
    )->execute(['r' => $roleMaison]);

    // ET UN ABONNEMENT SANS TARIF FIGÉ : il n'engage rien, il ne doit
    // PAS être archivé — `billing_archive` exige un montant, et
    // inventer une dette est pire que l'avouer (décision de la 7B2).
    $db->prepare(
        "INSERT INTO subscriptions (school_id, plan_id, status, billing_cycle,
                                    price_amount, price_currency, starts_on, ends_on, created_at)
         VALUES (:s, 1, 'trial', 'yearly', NULL, NULL, '2025-01-01', '2025-12-31', NOW())"
    )->execute(['s' => $ecoleId]);

    // UN COMPTE, ET SES TENTATIVES DE CONNEXION.
    //
    // `login_attempts` ne porte NI school_id NI clé étrangère : ses
    // lignes ne sont emportées par aucun CASCADE. Elles gardent
    // pourtant l'identifiant du compte et l'adresse IP — deux données
    // personnelles.
    $db->prepare(
        "INSERT INTO users (uuid, school_id, username, email, password_hash,
                            last_name, first_name, status, created_at)
         VALUES (:uuid, :s, :u, :e, :h, 'NGOY', 'Jean', 'active', NOW())"
    )->execute([
        // uuid : CHAR(36), donc 8-4-4-4-12.
        'uuid' => sprintf('%s-%s-4%s-a%s-%s',
            bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
            substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1),
            bin2hex(random_bytes(6))),
        's'    => $ecoleId,
        'u'    => 'directeur.' . $etiquette,
        'e'    => 'directeur.' . $etiquette . '@exemple.cd',
        'h'    => password_hash('MotDePasse2026', PASSWORD_BCRYPT),
    ]);

    foreach ([['directeur.' . $etiquette, 1], ['directeur.' . $etiquette, 0],
              ['directeur.' . $etiquette . '@exemple.cd', 0]] as [$identifiant, $reussi]) {
        $db->prepare(
            "INSERT INTO login_attempts (identifier, ip_address, successful, user_agent, created_at)
             VALUES (:i, :ip, :r, 'Mozilla', NOW())"
        )->execute(['i' => $identifiant, 'ip' => inet_pton('41.243.11.7'), 'r' => $reussi]);
    }

    // Un fichier déposé.
    $dossier = $racine . '/storage/uploads/photos/' . $ecoleId;
    mkdir($dossier, 0o775, true);
    file_put_contents($dossier . '/eleve.png', random_bytes(256));
}

// --- Le décor -----------------------------------------------------------
mkdir($racine . '/app/config', 0o775, true);
mkdir($racine . '/app/core', 0o775, true);
mkdir($racine . '/storage/backups', 0o775, true);
mkdir($racine . '/storage/uploads', 0o775, true);

copy(BASE_PATH . '/app/config/config.php', $racine . '/app/config/config.php');
copy(BASE_PATH . '/app/core/helpers.php',  $racine . '/app/core/helpers.php');
copy(BASE_PATH . '/app/core/logger.php',   $racine . '/app/core/logger.php');

$copier = static function (string $de, string $vers) use (&$copier): void {
    if (!is_dir($vers)) {
        mkdir($vers, 0o775, true);
    }

    foreach (scandir($de) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }

        is_dir($de . '/' . $e) ? $copier($de . '/' . $e, $vers . '/' . $e)
                               : copy($de . '/' . $e, $vers . '/' . $e);
    }
};

$copier(BASE_PATH . '/database', $racine . '/database');

file_put_contents(
    $racine . '/app/config/config.local.php',
    "<?php\ndeclare(strict_types=1);\nreturn [\n"
    . "    'app' => ['env' => 'local', 'debug' => true, 'url' => 'http://recette.test'],\n"
    . "    'database' => ['host' => " . var_export((string) config('database.host'), true)
    . ", 'port' => " . (int) config('database.port', 3306)
    . ", 'name' => " . var_export($base, true)
    . ", 'user' => " . var_export((string) config('database.user'), true)
    . ", 'password' => " . var_export((string) config('database.password'), true) . "],\n"
    . "    'security' => ['encryption_key' => " . var_export(base64_encode(random_bytes(32)), true) . "],\n"
    . "];\n"
);

try {
    try {
        $serveur = serveur();
        $serveur->exec("DROP DATABASE IF EXISTS `{$base}`");
        $serveur->exec("CREATE DATABASE `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        check('Le compte MySQL peut créer la base de recette', false, $e->getMessage());
        echo "\n  Accordez : GRANT ALL ON `school\\_saas\\_recette\\_%`.* TO '"
            . (string) config('database.user') . "'@'localhost';\n\n";
        effacer_dossier($racine);
        exit(1);
    }

    $install = lancer($racine, 'install.php', [], "editeur.recette\nediteur@exemple.cd\nRecette2026Ab\n");

    check('Le décor est installé', str_contains($install['sortie'], 'Installation terminée'));

    $db = base_decor($base);

    // =================================================================
    //  DEUX ÉCOLES : celle qu'on efface, et celle qui doit survivre
    // =================================================================

    echo "\n  Deux établissements, dont un seul doit disparaître\n";

    $partante = creer_ecole($db, 'ECO-900001', 'École qui s\'en va', 'ecole-partante');
    $voisine  = creer_ecole($db, 'ECO-900002', 'École voisine', 'ecole-voisine');

    garnir($db, $racine, $partante['id'], 'partante');
    garnir($db, $racine, $voisine['id'], 'voisine');

    check('Les deux établissements sont en place',
        (int) $db->query("SELECT COUNT(*) FROM schools WHERE code LIKE 'ECO-9000%'")->fetchColumn() === 2);

    /** Compte les lignes d'une école, toutes tables portant school_id. */
    $inventaire = static function (PDO $db, int $id) use ($base): int {
        $tables = $db->prepare(
            "SELECT table_name FROM information_schema.columns
              WHERE table_schema = :b AND column_name = 'school_id'"
        );
        $tables->execute(['b' => $base]);

        $total = 0;

        foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $c = $db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE school_id = :s');
            $c->execute(['s' => $id]);
            $total += (int) $c->fetchColumn();
        }

        return $total;
    };

    $avantVoisine = $inventaire($db, $voisine['id']);

    check('L\'école voisine porte des données', $avantVoisine > 0, $avantVoisine . ' ligne(s)');

    // =================================================================
    //  LES REFUS — avant toute écriture
    // =================================================================

    echo "\n  Les refus\n";

    $active = lancer($racine, 'erase_school.php', ['ECO-900001', '--simuler']);

    check('Une école ACTIVE : refusé',
        $active['code'] !== 0 && str_contains($active['erreur'], 'pas résilié'));

    // On résilie, comme la console de l'éditeur le ferait.
    $db->exec("UPDATE schools SET status = 'cancelled' WHERE code = 'ECO-900001'");

    $sansSauvegarde = lancer($racine, 'erase_school.php', ['ECO-900001', '--simuler']);

    check('Sans sauvegarde : refusé',
        $sansSauvegarde['code'] !== 0
        && str_contains($sansSauvegarde['erreur'], 'Aucune sauvegarde'));

    // Une sauvegarde prise AVANT l'inscription de l'école ne vaut pas.
    // On en fabrique une qui ne contient pas son identifiant.
    file_put_contents($racine . '/storage/backups/vieille.zip', 'PK pas une archive');

    $mauvaise = lancer($racine, 'erase_school.php', ['ECO-900001', '--simuler']);

    check('Une archive illisible : refusée',
        $mauvaise['code'] !== 0 && str_contains($mauvaise['erreur'], 'archive illisible'));

    unlink($racine . '/storage/backups/vieille.zip');

    // La vraie sauvegarde.
    $sauvegarde = lancer($racine, 'backup.php');

    check('La sauvegarde est prise', $sauvegarde['code'] === 0);

    // Une archive vieille de trois jours n'est plus un retour crédible.
    $archives = glob($racine . '/storage/backups/*.zip') ?: [];
    touch($archives[0], time() - 3 * 86400);

    $vieille = lancer($racine, 'erase_school.php', ['ECO-900001', '--simuler']);

    check('Une sauvegarde de trois jours : refusée',
        $vieille['code'] !== 0 && str_contains($vieille['erreur'], 'heure(s)'));

    touch($archives[0], time());

    // =================================================================
    //  LA SIMULATION N'ÉCRIT RIEN
    // =================================================================

    echo "\n  La simulation\n";

    $avantPartante = $inventaire($db, $partante['id']);
    $simulation    = lancer($racine, 'erase_school.php', ['ECO-900001', '--simuler']);

    check('La simulation aboutit', $simulation['code'] === 0,
        $simulation['erreur'] !== '' ? trim($simulation['erreur']) : '');

    check('Elle montre ce qui sera effacé',
        str_contains($simulation['sortie'], 'CE QUI SERA EFFACÉ'));

    check('… et ce qui sera gardé',
        str_contains($simulation['sortie'], 'CE QUI SERA GARDÉ'));

    check('Elle dit n\'avoir rien écrit',
        str_contains($simulation['sortie'], 'rien n\'a été écrit'));

    check('… et elle n\'a effectivement rien écrit',
        $inventaire($db, $partante['id']) === $avantPartante,
        $avantPartante . ' ligne(s) avant comme après');

    // =================================================================
    //  LES REFUS DE CONFIRMATION
    // =================================================================

    echo "\n  La confirmation\n";

    $mauvaisCode = lancer($racine, 'erase_school.php', ['ECO-900001'], "ECO-000999\n");

    check('Un code mal retapé : refusé',
        $mauvaisCode['code'] !== 0 && str_contains($mauvaisCode['sortie'], 'Code incorrect'));

    $motifCourt = lancer($racine, 'erase_school.php', ['ECO-900001'], "ECO-900001\ncourt\n");

    check('Un motif trop court : refusé',
        $motifCourt['code'] !== 0 && str_contains($motifCourt['sortie'], 'Motif trop court'));

    // La trace doit désigner quelqu'un qui existe : un nom inventé ne
    // désigne personne, et c'est elle qui répondra dans dix ans à
    // « qui a effacé cet établissement ».
    $operateurInvente = lancer(
        $racine,
        'erase_school.php',
        ['ECO-900001'],
        "ECO-900001\nRésiliation du contrat à la demande de l'établissement\nmonsieur.personne\n"
    );

    check('Un opérateur inventé : refusé',
        $operateurInvente['code'] !== 0
        && str_contains($operateurInvente['sortie'], 'compte de plateforme actif'));

    check('… et rien n\'a bougé', $inventaire($db, $partante['id']) === $avantPartante);

    // =================================================================
    //  L'EFFACEMENT
    // =================================================================

    echo "\n  L'effacement\n";

    $photo = $racine . '/storage/uploads/photos/' . $partante['id'] . '/eleve.png';
    $photoVoisine = $racine . '/storage/uploads/photos/' . $voisine['id'] . '/eleve.png';

    check('Le fichier de l\'école partante existe', is_file($photo));

    $effacement = lancer(
        $racine,
        'erase_school.php',
        ['ECO-900001'],
        "ECO-900001\nRésiliation du contrat à la demande de l'établissement\nediteur.recette\n"
    );

    check('L\'effacement aboutit', $effacement['code'] === 0,
        $effacement['erreur'] !== '' ? trim($effacement['erreur']) : '');

    check('Il se déclare terminé', str_contains($effacement['sortie'], 'Effacement terminé'));

    $db = base_decor($base);

    check('L\'établissement a disparu',
        (int) $db->query("SELECT COUNT(*) FROM schools WHERE code = 'ECO-900001'")->fetchColumn() === 0);

    check('… et toutes ses lignes avec lui',
        $inventaire($db, $partante['id']) === 0,
        $inventaire($db, $partante['id']) . ' ligne(s) restante(s)');

    // LE PIÈGE : `audit_logs` n'a AUCUNE clé étrangère vers `schools`.
    // Sans un effacement explicite, ses lignes survivraient au CASCADE —
    // avec les noms et les adresses qu'elles portent.
    check('Le journal de l\'école est parti',
        (int) $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE school_id = ?')
            ->execute([$partante['id']]) !== null
        && (int) $db->query('SELECT COUNT(*) FROM audit_logs WHERE school_id = '
            . $partante['id'])->fetchColumn() === 0);

    $resteNom = (int) $db->query(
        "SELECT COUNT(*) FROM audit_logs
          WHERE new_values LIKE '%esperance.partante@exemple.cd%'
             OR description LIKE '%KABILA-PARTANTE%'"
    )->fetchColumn();

    check('Aucun nom ni adresse de cette école ne subsiste au journal', $resteNom === 0);

    // Et le contrôle symétrique, qui donne son sens au précédent : le
    // nom de l'école VOISINE, lui, doit être toujours là.
    $resteVoisine = (int) $db->query(
        "SELECT COUNT(*) FROM audit_logs
          WHERE new_values LIKE '%esperance.voisine@exemple.cd%'
             OR description LIKE '%KABILA-VOISINE%'"
    )->fetchColumn();

    check('… tandis que celui de l\'école voisine est intact', $resteVoisine === 1,
        $resteVoisine . ' entrée(s)');

    // LE PIÈGE SUIVANT : `login_attempts` n'a ni school_id ni clé
    // étrangère. Rien ne l'emporte, et il garde l'identifiant du compte
    // ET l'adresse IP.
    $tentatives = (int) $db->query(
        "SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE 'directeur.partante%'"
    )->fetchColumn();

    check('Les tentatives de connexion de cette école sont parties', $tentatives === 0,
        $tentatives . ' tentative(s) restante(s), avec identifiant et adresse IP');

    check('… tandis que celles de l\'école voisine restent',
        (int) $db->query(
            "SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE 'directeur.voisine%'"
        )->fetchColumn() === 3);

    check('Le rôle composé par l\'école est parti',
        (int) $db->query("SELECT COUNT(*) FROM roles WHERE code LIKE 'E%_SURVEILLANT'
                            AND school_id = " . $partante['id'])->fetchColumn() === 0);

    check('… ainsi que les permissions qu\'il accordait',
        (int) $db->query('SELECT COUNT(*) FROM role_permissions rp
                            LEFT JOIN roles r ON r.id = rp.role_id
                           WHERE r.id IS NULL')->fetchColumn() === 0);

    check('… sans toucher aux neuf rôles livrés avec le produit',
        (int) $db->query('SELECT COUNT(*) FROM roles WHERE school_id IS NULL
                            AND is_system = 1')->fetchColumn() === 9);

    check('Les fichiers déposés sont partis', !is_file($photo));
    check('… et leur dossier aussi',
        !is_dir($racine . '/storage/uploads/photos/' . $partante['id']));

    // =================================================================
    //  CE QUI DOIT SURVIVRE
    // =================================================================

    echo "\n  Ce qui survit\n";

    $comptable = $db->query(
        "SELECT * FROM billing_archive WHERE school_code = 'ECO-900001'"
    )->fetchAll();

    // DEUX LIGNES : le versement encaissé, ET la créance impayée.
    // La troisième — l'essai sans tarif figé — n'engage rien et reste
    // dehors.
    check('La comptabilité de l\'éditeur est archivée', count($comptable) === 2,
        count($comptable) . ' ligne(s), 2 attendues');

    $verse  = null;
    $impaye = null;

    foreach ($comptable as $ligne) {
        $ligne['original_payment_id'] === null ? $impaye = $ligne : $verse = $ligne;
    }

    check('Le versement encaissé est archivé', $verse !== null);

    if ($verse !== null) {
        check('… avec le montant et la devise',
            (float) $verse['amount'] === 120.00 && $verse['currency'] === 'USD');
        check('… avec la référence du paiement',
            $verse['reference'] === 'REF-partante');
        check('… et le nom de l\'établissement, personne morale',
            $verse['school_name'] === 'École qui s\'en va');
    }

    check('LA CRÉANCE IMPAYÉE est archivée elle aussi', $impaye !== null,
        'une école ne s\'acquitte pas de sa dette en demandant son effacement');

    if ($impaye !== null) {
        check('… avec son montant', (float) $impaye['amount'] === 250.00);
        check('… marquée impayée', $impaye['payment_status'] === 'unpaid');
        check('… et sans versement d\'origine', $impaye['original_payment_id'] === null);
    }

    check('L\'abonnement SANS tarif figé n\'est pas archivé',
        count(array_filter($comptable, static fn ($l) => (float) $l['amount'] === 0.0)) === 0,
        'il n\'engage rien : inventer une dette est pire que l\'avouer');

    $trace = $db->query(
        "SELECT * FROM school_erasures WHERE school_code = 'ECO-900001'"
    )->fetchAll();

    check('Une trace de l\'effacement subsiste', count($trace) === 1);

    if ($trace !== []) {
        check('… avec le motif', str_contains((string) $trace[0]['reason'], 'Résiliation du contrat'));
        check('… avec l\'opérateur, gardé en texte',
            $trace[0]['erased_by_username'] === 'editeur.recette');
        check('… avec la sauvegarde qui l\'a précédé',
            str_ends_with((string) $trace[0]['backup_archive'], '.zip'));

        $comptes = json_decode((string) $trace[0]['counts'], true);
        check('… et le décompte de ce qui est parti',
            is_array($comptes) && ($comptes['journal'] ?? 0) >= 1);
    }

    $journalPlateforme = $db->query(
        "SELECT * FROM audit_logs WHERE action = 'school.erase' AND school_id IS NULL"
    )->fetchAll();

    check('Le journal de la PLATEFORME garde l\'événement', count($journalPlateforme) === 1);

    if ($journalPlateforme !== []) {
        check('… et il ne nomme aucun élève',
            !str_contains((string) $journalPlateforme[0]['description'], 'KABILA')
            && !str_contains((string) ($journalPlateforme[0]['new_values'] ?? ''), 'KABILA'));
    }

    // =================================================================
    //  L'ÉCOLE VOISINE — LE CONTRÔLE QUI PRIME
    // =================================================================

    echo "\n  L'école voisine\n";

    check('Elle est toujours là',
        (int) $db->query("SELECT COUNT(*) FROM schools WHERE code = 'ECO-900002'")->fetchColumn() === 1);

    check('Elle n\'a pas perdu une ligne',
        $inventaire($db, $voisine['id']) === $avantVoisine,
        $inventaire($db, $voisine['id']) . ' contre ' . $avantVoisine);

    check('Son journal est intact',
        (int) $db->query('SELECT COUNT(*) FROM audit_logs WHERE school_id = '
            . $voisine['id'])->fetchColumn() === 1);

    check('Ses fichiers sont intacts', is_file($photoVoisine));

    check('Sa comptabilité n\'a pas été archivée',
        (int) $db->query("SELECT COUNT(*) FROM billing_archive WHERE school_code = 'ECO-900002'")
            ->fetchColumn() === 0);

    check('Son rôle maison est intact',
        (int) $db->query('SELECT COUNT(*) FROM roles WHERE school_id = '
            . $voisine['id'])->fetchColumn() === 1);

    check('Sa créance impayée est intacte',
        (int) $db->query('SELECT COUNT(*) FROM subscriptions WHERE school_id = '
            . $voisine['id'] . ' AND price_amount = 250.00')->fetchColumn() === 1);

    // =================================================================
    //  ON N'EFFACE PAS DEUX FOIS
    // =================================================================

    $rejeu = lancer($racine, 'erase_school.php', ['ECO-900001', '--simuler']);

    check('Un second effacement du même code : refusé',
        $rejeu['code'] !== 0 && str_contains($rejeu['erreur'], 'Aucun établissement'));
} finally {
    try {
        serveur()->exec("DROP DATABASE IF EXISTS `{$base}`");
    } catch (Throwable) {
        // La base n'a peut-être jamais existé.
    }

    effacer_dossier($racine);
}

check('Le décor jetable est nettoyé', !is_dir($racine));

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
