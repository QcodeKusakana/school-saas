<?php
/**
 * Phase 11A — le cycle de vie d'un établissement.
 *
 * CE QUE CES TESTS PROTÈGENT
 * --------------------------
 *  · créer une école la rend UTILISABLE : cycles, année courante,
 *    abonnement d'essai, compte d'administration — pas seulement une
 *    ligne dans `schools` ;
 *  · le code et le slug ne se disputent jamais, même entre homonymes ;
 *  · le mot de passe initial n'est rendu QU'UNE FOIS et n'entre JAMAIS
 *    au journal ;
 *  · `schools.status` sait enfin être posé — c'est ce qui rend
 *    l'effacement de la 10C atteignable, et ce que `auth.php` fait
 *    respecter depuis la phase 1 ;
 *  · suspendre ferme réellement la porte ;
 *  · une école ne peut pas créer, modifier ni suspendre un
 *    établissement : ces trois pouvoirs sont à l'éditeur seul.
 *
 * Usage : php tests/platform_school_lifecycle.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/platform/services.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

function agir_comme(int $userId): void
{
    $_SESSION['user_id'] = $userId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);
}

echo "\n  PHASE 11A — CYCLE DE VIE D'UN ÉTABLISSEMENT\n";
echo "  ══════════════════════════════════════════════════════════\n";

$creees = [];
$comptes = [];

try {
    // --- Un compte d'éditeur ------------------------------------------
    $editeur = (int) platform_scope_cli(static fn (): int => (int) db_value(
        "SELECT u.id FROM users u
           JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id AND r.code = 'SUPER_ADMIN'
          WHERE u.school_id IS NULL AND u.deleted_at IS NULL
          LIMIT 1",
        [],
        true
    ));

    check('Un compte d\'éditeur existe', $editeur > 0);

    agir_comme($editeur);

    // =================================================================
    //  CRÉER
    // =================================================================

    echo "\n  Créer un établissement\n";

    $nom = 'Institut de Recette ' . bin2hex(random_bytes(3));

    $creation = platform_service_create_school([
        'name'             => $nom,
        'school_type'      => 'prive',
        'province'         => 'Haut-Katanga',
        'city'             => 'Lubumbashi',
        'cycles'           => ['PRIMAIRE', 'CTEB'],
        'admin_last_name'  => 'MWEPU',
        'admin_first_name' => 'Clarisse',
        'admin_username'   => 'recette.' . bin2hex(random_bytes(4)),
    ]);

    check('L\'établissement est créé', $creation['ok'], (string) $creation['message']);

    if (!$creation['ok']) {
        printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail + 1);
        exit(1);
    }

    $ecoleId = (int) $creation['school_id'];
    $creees[] = $ecoleId;

    check('Il reçoit un code ECO-xxxxxx',
        preg_match('/^ECO-\d{6}$/', (string) $creation['code']) === 1,
        (string) $creation['code']);

    // CE QUI FAIT QU'UNE ÉCOLE EST UTILISABLE.
    // Une ligne dans `schools` donne une école muette : pas de cycle donc
    // pas de niveau, pas d'année donc aucun écran de travail, pas de
    // compte donc personne pour ouvrir la porte.
    $compter = static fn (string $table): int => (int) platform_scope_cli(
        static fn (): int => (int) db_value(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE school_id = :s',
            ['s' => $GLOBALS['ecoleId']],
            true
        )
    );

    $GLOBALS['ecoleId'] = $ecoleId;

    check('… deux cycles dispensés', $compter('school_cycles') === 2);
    check('… une année scolaire', $compter('academic_years') === 1);
    check('… un abonnement d\'essai', $compter('subscriptions') === 1);
    check('… un compte d\'administration', $compter('users') === 1);

    $annee = platform_scope_cli(static fn (): ?array => db_one(
        'SELECT code, is_current, status FROM academic_years WHERE school_id = :s',
        ['s' => $ecoleId],
        true
    ));

    check('L\'année est COURANTE', (int) ($annee['is_current'] ?? 0) === 1);

    $admin = platform_scope_cli(static fn (): ?array => db_one(
        'SELECT id, username, must_change_password, password_hash FROM users WHERE school_id = :s',
        ['s' => $ecoleId],
        true
    ));

    $comptes[] = (int) $admin['id'];

    check('Le changement de mot de passe est imposé',
        (int) $admin['must_change_password'] === 1);

    check('Le mot de passe rendu ouvre bien ce compte',
        password_verify((string) $creation['password'], (string) $admin['password_hash']));

    check('L\'administrateur porte le rôle SCHOOL_ADMIN',
        (int) platform_scope_cli(static fn (): int => (int) db_value(
            "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :u AND r.code = 'SCHOOL_ADMIN'",
            ['u' => (int) $admin['id']],
            true
        )) === 1);

    // LE MOT DE PASSE N'ENTRE JAMAIS AU JOURNAL.
    // Pas même rédigé : ce qui n'est pas écrit ne fuit pas.
    $fuite = (int) platform_scope_cli(static fn (): int => (int) db_value(
        'SELECT COUNT(*) FROM audit_logs
          WHERE (new_values LIKE :p OR description LIKE :p2)',
        ['p' => '%' . $creation['password'] . '%', 'p2' => '%' . $creation['password'] . '%'],
        true
    ));

    check('Le mot de passe initial n\'est nulle part au journal', $fuite === 0);

    $trace = platform_scope_cli(static fn (): ?array => db_one(
        "SELECT * FROM audit_logs WHERE action = 'platform.school.create'
           AND entity_id = :e ORDER BY id DESC LIMIT 1",
        ['e' => $ecoleId],
        true
    ));

    check('La création est journalisée', $trace !== null);
    check('… au niveau de la plateforme', $trace !== null && $trace['school_id'] === null);

    // =================================================================
    //  LES HOMONYMES
    // =================================================================

    echo "\n  Deux établissements homonymes\n";

    $jumelle = platform_service_create_school([
        'name'             => $nom,
        'school_type'      => 'prive',
        'cycles'           => ['PRIMAIRE'],
        'admin_last_name'  => 'KALALA',
        'admin_first_name' => 'Sylvie',
        'admin_username'   => 'recette.' . bin2hex(random_bytes(4)),
    ]);

    check('Le même nom est accepté', $jumelle['ok'], (string) $jumelle['message']);

    if ($jumelle['ok']) {
        $creees[] = (int) $jumelle['school_id'];

        $comptes[] = (int) platform_scope_cli(static fn (): int => (int) db_value(
            'SELECT id FROM users WHERE school_id = :s',
            ['s' => (int) $jumelle['school_id']],
            true
        ));

        check('… avec un code différent', $jumelle['code'] !== $creation['code'],
            $creation['code'] . ' puis ' . $jumelle['code']);

        $slugs = platform_scope_cli(static fn (): array => db_all(
            'SELECT slug FROM schools WHERE id IN (:a, :b)',
            ['a' => $ecoleId, 'b' => (int) $jumelle['school_id']],
            true
        ));

        check('… et un slug différent',
            count($slugs) === 2 && $slugs[0]['slug'] !== $slugs[1]['slug'],
            implode(' / ', array_column($slugs, 'slug')));
    }

    // =================================================================
    //  L'ÉCOLE CRÉÉE EST-ELLE OUVRABLE ?
    // =================================================================
    //
    // La phase affirme : « créer un client, c'est lui rendre le produit
    // ouvrable ». Compter les lignes ne le dit pas.
    //
    //   > Compter les lignes d'une école ne dit pas si quelqu'un peut y
    //   > entrer.

    echo "\n  L'école créée est-elle ouvrable ?\n";

    $_SESSION['school_id'] = $ecoleId;
    agir_comme((int) $admin['id']);
    tenant_set($ecoleId);

    require_once APP_PATH . '/modules/years/repositories.php';
    require_once APP_PATH . '/modules/curriculum/services.php';

    $anneeCourante = years_repo_current();

    check('Une année courante existe pour elle', $anneeCourante !== null,
        $anneeCourante !== null ? (string) $anneeCourante['code'] : 'AUCUNE');

    $niveaux = (int) platform_scope_cli(static fn (): int => (int) db_value(
        'SELECT COUNT(*) FROM education_levels el
           JOIN school_cycles sc ON sc.cycle_id = el.cycle_id
          WHERE sc.school_id = :s AND sc.is_active = 1',
        ['s' => $ecoleId],
        true
    ));

    check('Le référentiel lui propose des niveaux', $niveaux > 0, $niveaux . ' niveau(x)');

    if ($anneeCourante !== null && $niveaux > 0) {
        $niveau = (int) platform_scope_cli(static fn (): int => (int) db_value(
            'SELECT el.id FROM education_levels el
               JOIN school_cycles sc ON sc.cycle_id = el.cycle_id
              WHERE sc.school_id = :s AND sc.is_active = 1 ORDER BY el.id LIMIT 1',
            ['s' => $ecoleId],
            true
        ));

        $programme = curriculum_service_create_program((int) $anneeCourante['id'], $niveau, null, null);

        check('Elle peut créer un programme dès le premier jour',
            (bool) ($programme['ok'] ?? false), (string) ($programme['message'] ?? ''));
    }

    // L'ISOLATION, VUE DEPUIS LA NOUVELLE ÉCOLE.
    check('Elle ne voit aucun élève d\'une autre école',
        count(tenant_all('students', '1 = 1')) === 0);
    check('Elle ne voit aucune classe d\'une autre école',
        count(tenant_all('classrooms', '1 = 1')) === 0);
    check('… alors que la plateforme en compte',
        (int) platform_scope_cli(static fn (): int => (int) db_value(
            'SELECT COUNT(*) FROM students', [], true
        )) > 0);

    agir_comme($editeur);

    // =================================================================
    //  LES REFUS
    // =================================================================

    echo "\n  Les refus à la création\n";

    foreach ([
        ['sans nom', ['name' => 'X', 'school_type' => 'prive', 'cycles' => ['PRIMAIRE'],
                      'admin_last_name' => 'A', 'admin_first_name' => 'B', 'admin_username' => 'zz.test1']],
        ['sans cycle', ['name' => 'École sans cycle', 'school_type' => 'prive', 'cycles' => [],
                        'admin_last_name' => 'A', 'admin_first_name' => 'B', 'admin_username' => 'zz.test2']],
        ['type inconnu', ['name' => 'École type', 'school_type' => 'militaire', 'cycles' => ['PRIMAIRE'],
                          'admin_last_name' => 'A', 'admin_first_name' => 'B', 'admin_username' => 'zz.test3']],
        ['identifiant trop court', ['name' => 'École id', 'school_type' => 'prive', 'cycles' => ['PRIMAIRE'],
                                    'admin_last_name' => 'A', 'admin_first_name' => 'B', 'admin_username' => 'ab']],
        ['identifiant en majuscules', ['name' => 'École maj', 'school_type' => 'prive', 'cycles' => ['PRIMAIRE'],
                                       'admin_last_name' => 'A', 'admin_first_name' => 'B', 'admin_username' => 'ZZ.Test']],
    ] as [$libelle, $entree]) {
        $r = platform_service_create_school($entree);
        check('Refusé : ' . $libelle, $r['ok'] === false);
    }

    // L'identifiant déjà pris est dit AVANT d'échouer sur la contrainte.
    $doublon = platform_service_create_school([
        'name'             => 'École doublon',
        'school_type'      => 'prive',
        'cycles'           => ['PRIMAIRE'],
        'admin_last_name'  => 'A',
        'admin_first_name' => 'B',
        'admin_username'   => (string) $admin['username'],
    ]);

    check('Refusé : identifiant déjà pris', $doublon['ok'] === false);
    check('… et le refus l\'explique',
        str_contains((string) $doublon['message'], 'déjà pris'));

    check('Aucune école fantôme n\'a été créée par les refus',
        (int) platform_scope_cli(static fn (): int => (int) db_value(
            "SELECT COUNT(*) FROM schools WHERE name LIKE 'École %'",
            [],
            true
        )) === 0);

    // =================================================================
    //  LA SAISIE PRÉCÉDENTE, POUR UN CHAMP MULTIPLE
    // =================================================================
    //
    // Le refus promet « la saisie est conservée ». `old()` ne rend que
    // des scalaires : pour les cases à cocher des cycles, il rendait ''
    // et elles revenaient décochées — une promesse tenue à moitié.
    //
    //   > Un message qui promet quelque chose que le code ne fait pas
    //   > est pire qu'un message absent.

    echo "\n  La saisie conservée\n";

    flash_old(['name' => 'Institut Saint-Joseph', 'cycles' => ['PRIMAIRE', 'HUMANITES']]);

    check('Un champ texte revient', old('name') === 'Institut Saint-Joseph');
    check('Un champ MULTIPLE revient aussi',
        old_array('cycles') === ['PRIMAIRE', 'HUMANITES'],
        implode(', ', old_array('cycles')));

    // LE MÊME CACHE POUR LES DEUX : sans cela, le premier appelé vide la
    // session et le second ne trouve plus rien.
    check('… et l\'un n\'a pas vidé la saisie de l\'autre',
        old('name') === 'Institut Saint-Joseph');

    // Le cache est statique pour tout le processus — ce qui est juste :
    // en HTTP, chaque requête repart à neuf. On éprouve donc le contrat
    // réel : un champ ABSENT de la saisie reçoit son défaut.
    check('Un champ absent reçoit sa valeur par défaut',
        old_array('sections_inexistantes', ['PRIMAIRE']) === ['PRIMAIRE']);

    check('… et un champ texte absent aussi',
        old('champ_inexistant', 'valeur par défaut') === 'valeur par défaut');

    // =================================================================
    //  L'ÉTAT — ce qui rend la 10C atteignable
    // =================================================================

    echo "\n  L'état de l'établissement\n";

    $sansMotif = platform_service_set_school_status($ecoleId, 'suspended');

    check('Suspendre sans motif : refusé', $sansMotif['ok'] === false);
    check('… et l\'école est toujours en service',
        (string) platform_scope_cli(static fn (): mixed => db_value(
            'SELECT status FROM schools WHERE id = :s', ['s' => $ecoleId], true
        )) === 'active');

    $suspension = platform_service_set_school_status(
        $ecoleId,
        'suspended',
        'Impayé de trois mois, relances restées sans réponse'
    );

    check('Suspendre avec motif : accepté', $suspension['ok'], (string) $suspension['message']);
    check('… et l\'écran annonce la fermeture des sessions',
        str_contains((string) $suspension['message'], 'sessions'));

    check('Le statut est bien posé en base',
        (string) platform_scope_cli(static fn (): mixed => db_value(
            'SELECT status FROM schools WHERE id = :s', ['s' => $ecoleId], true
        )) === 'suspended');

    // LA GARDE EXISTAIT DEPUIS LA PHASE 1 : `auth.php` refuse la
    // connexion de toute école qui n'est pas « active ». Ce qui manquait,
    // c'était de savoir poser l'état.
    $connexion = auth_attempt((string) $admin['username'], (string) $creation['password']);

    check('La connexion de cette école est REFUSÉE', $connexion['ok'] === false);
    check('… et le message parle de suspension',
        str_contains((string) $connexion['message'], 'suspendu'));

    agir_comme($editeur);

    $deuxFois = platform_service_set_school_status($ecoleId, 'suspended', 'Encore le même motif exactement');

    check('Suspendre deux fois : refusé', $deuxFois['ok'] === false);

    // Résilier : la condition d'entrée de l'effacement (10C).
    $resiliation = platform_service_set_school_status(
        $ecoleId,
        'cancelled',
        'Résiliation demandée par le promoteur le 28 septembre'
    );

    check('Résilier : accepté', $resiliation['ok'], (string) $resiliation['message']);
    check('… et l\'écran renvoie vers l\'effacement',
        str_contains((string) $resiliation['message'], 'erase_school.php'));

    check('Le statut « cancelled » est posé',
        (string) platform_scope_cli(static fn (): mixed => db_value(
            'SELECT status FROM schools WHERE id = :s', ['s' => $ecoleId], true
        )) === 'cancelled');

    // Et la remise en service, qui n'exige pas de motif.
    $retour = platform_service_set_school_status($ecoleId, 'active');

    check('Remettre en service sans motif : accepté', $retour['ok']);

    check('La transition est journalisée avec son motif',
        (int) platform_scope_cli(static fn (): int => (int) db_value(
            "SELECT COUNT(*) FROM audit_logs
              WHERE action = 'platform.school.status' AND entity_id = :e
                AND new_values LIKE '%promoteur%'",
            ['e' => $ecoleId],
            true
        )) === 1);

    // =================================================================
    //  MODIFIER
    // =================================================================

    echo "\n  Modifier les coordonnées\n";

    $maj = platform_service_update_school($ecoleId, [
        'name'          => $nom . ' (corrigé)',
        'school_type'   => 'conventionne',
        'city'          => 'Kolwezi',
        'director_name' => 'KASONGO Bernard',
    ]);

    check('La modification passe', $maj['ok'], (string) $maj['message']);

    $apres = platform_scope_cli(static fn (): ?array => db_one(
        'SELECT code, name, school_type, city FROM schools WHERE id = :s',
        ['s' => $ecoleId],
        true
    ));

    check('… le nom est changé', str_ends_with((string) $apres['name'], '(corrigé)'));
    check('… le type aussi', $apres['school_type'] === 'conventionne');

    // LE CODE EST L'IDENTITÉ : il figure sur des documents déjà remis et
    // sur la trace d'un éventuel effacement.
    $tentative = platform_service_update_school($ecoleId, [
        'name'        => (string) $apres['name'],
        'school_type' => 'conventionne',
        'code'        => 'ECO-999999',
        'slug'        => 'autre-slug',
    ]);

    check('Le code n\'est pas modifiable, même en le postant',
        $tentative['ok'] && (string) platform_scope_cli(static fn (): mixed => db_value(
            'SELECT code FROM schools WHERE id = :s', ['s' => $ecoleId], true
        )) === (string) $apres['code']);

    // =================================================================
    //  UNE ÉCOLE NE PEUT RIEN DE TOUT CELA
    // =================================================================

    echo "\n  Une école n'a aucun de ces pouvoirs\n";

    $directeur = (int) platform_scope_cli(static fn (): int => (int) db_value(
        "SELECT u.id FROM users u WHERE u.school_id IS NOT NULL AND u.deleted_at IS NULL LIMIT 1",
        [],
        true
    ));

    if ($directeur > 0) {
        agir_comme($directeur);

        // ON INTERROGE LA DÉCISION, PAS SON RENDU.
        //
        // `platform_scope()` se termine par `platform_require()`, qui
        // appelle `abort(403)` : la page s'imprime et le processus
        // s'arrête. Appeler les services ici tuerait la recette au lieu
        // de la faire échouer. `platform_refusal()` rend la même
        // décision sans la mettre en page — c'est la convention établie
        // par `tests/platform_scope.php`, et le 403 lui-même est vérifié
        // par la recette au navigateur.
        foreach ([
            'platform.school.create' => 'Créer un établissement',
            'platform.school.edit'   => 'Modifier un établissement',
            'platform.school.suspend' => 'Suspendre ou résilier',
        ] as $permission => $libelle) {
            check($libelle . ' : refusé à une école',
                platform_refusal($permission) !== null);
        }

        agir_comme($editeur);

        check('… et rien n\'a bougé',
            (string) platform_scope_cli(static fn (): mixed => db_value(
                'SELECT status FROM schools WHERE id = :s', ['s' => $ecoleId], true
            )) === 'active');
    } else {
        check('Un compte d\'école existe pour éprouver le refus', false);
    }
} finally {
    // Remise en état : le décor de démonstration doit survivre intact.
    platform_scope_cli(static function () use ($creees, $comptes): void {
        foreach ($comptes as $u) {
            db_query('DELETE FROM user_roles WHERE user_id = :u', ['u' => $u], true);
        }

        foreach ($creees as $id) {
            db_query('DELETE FROM audit_logs WHERE entity_type = :t AND entity_id = :e',
                ['t' => 'schools', 'e' => $id], true);
            db_query('DELETE FROM schools WHERE id = :id', ['id' => $id], true);
        }

        db_query("DELETE FROM login_attempts WHERE identifier LIKE 'recette.%'", [], true);
    });
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
