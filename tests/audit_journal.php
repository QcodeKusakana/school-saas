<?php
/**
 * Phase 9B — le journal : masquage, isolation, purge.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · aucun secret n'entre dans le journal, quelle que soit la LANGUE du
 *    nom du champ et quelle que soit sa profondeur ;
 *  · une école ne lit jamais le journal d'une autre, ni en liste ni à
 *    l'unité ;
 *  · la purge ne descend pas sous le plancher, ne sort pas de l'école,
 *    et s'inscrit elle-même dans le journal ;
 *  · lire et purger sont deux pouvoirs distincts ;
 *  · une lecture inter-écoles hors périmètre plateforme est REFUSÉE.
 *
 * Usage : php tests/audit_journal.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_PATH . '/modules/audit/services.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

function act_as(int $schoolId, string $roleCode): int
{
    static $n = 0;
    $n++;

    $userId = db_insert('users', [
        'uuid'          => str_uuid(),
        'school_id'     => $schoolId,
        'username'      => 'jrn.' . strtolower($roleCode) . '.' . $n,
        'email'         => 'jrn' . $n . '@example.test',
        'password_hash' => password_hash('MotDePasse2026', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name'     => 'JOURNAL',
        'first_name'    => ucfirst(strtolower($roleCode)),
        'status'        => 'active',
    ], true);

    db_query(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :u, id FROM roles WHERE code = :c AND school_id IS NULL',
        ['u' => $userId, 'c' => $roleCode],
        true
    );

    reprendre($userId, $schoolId);

    return $userId;
}

function reprendre(int $userId, int $schoolId): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;

    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($schoolId);
}

function ecole(string $code, string $nom): int
{
    return db_insert('schools', [
        'uuid' => str_uuid(), 'code' => $code, 'slug' => strtolower($code),
        'name' => $nom, 'status' => 'active',
    ], true);
}

/** Écrit une entrée datée — `audit_log()` pose toujours « maintenant ». */
function entree_datee(int $schoolId, int $userId, string $action, string $quand): int
{
    return db_insert('audit_logs', [
        'school_id'  => $schoolId,
        'user_id'    => $userId,
        'action'     => $action,
        'created_at' => $quand,
    ], true);
}

echo "\n  LE JOURNAL — MASQUAGE, ISOLATION, PURGE\n";
echo "  ──────────────────────────────────────────\n\n";

$ecoles = [];

try {
    // =================================================================
    echo "  AUCUN SECRET N'ENTRE DANS LE JOURNAL\n";

    $masque = static fn (string $cle, mixed $val): bool
        => (json_decode(audit_encode([$cle => $val]), true)[$cle] ?? null) === '***';

    // LE DÉFAUT QUI A MOTIVÉ CES TESTS.
    //
    // La liste de masquage était en ANGLAIS alors que tout le produit
    // s'écrit en français. Mesuré : `mot_de_passe`, `motdepasse`, `mdp`,
    // `jeton` et `cle_de_chiffrement` traversaient le masquage en clair.
    foreach (['password', 'password_hash', 'api_key', 'token_hash', 'secret'] as $cle) {
        check('« ' . $cle .' » est masqué (anglais)', $masque($cle, 'Secret2026'));
    }

    foreach (['mot_de_passe', 'motdepasse', 'mdp', 'jeton', 'cle_de_chiffrement'] as $cle) {
        check('« ' . $cle . ' » est masqué (FRANÇAIS)', $masque($cle, 'Secret2026'));
    }

    // Le masquage ne descendait pas d'un niveau.
    $imbrique = json_decode(
        audit_encode(['smtp' => ['host' => 'mail.ecole.cd', 'password' => 'Secret2026',
                                 'interne' => ['mdp' => 'Secret2026']]]),
        true
    );

    check('Un secret IMBRIQUÉ est masqué', ($imbrique['smtp']['password'] ?? '') === '***');
    check('…et à deux niveaux de profondeur', ($imbrique['smtp']['interne']['mdp'] ?? '') === '***');
    check('…sans masquer ce qui est innocent autour',
        ($imbrique['smtp']['host'] ?? '') === 'mail.ecole.cd');

    // TROP MASQUER N'EST PAS NEUTRE : cela rend le journal muet là où il
    // devrait parler.
    $garde = json_decode(audit_encode([
        'mot_de_passe_change' => true,     // booléen : ne peut pas être un secret
        'setting_key'         => 'school.city',
        'period_key'          => 'T1',
    ]), true);

    check('Un booléen n\'est jamais masqué', $garde['mot_de_passe_change'] === true);
    check('« setting_key » reste lisible', $garde['setting_key'] === 'school.city');
    check('« period_key » reste lisible', $garde['period_key'] === 'T1');

    // La profondeur est bornée. On construit exactement un niveau de plus
    // que la borne : la coupure doit tomber, et pas avant.
    $profond = 'fond';

    for ($i = AUDIT_REDACT_MAX_DEPTH + 1; $i >= 1; $i--) {
        $profond = ['n' . $i => $profond];
    }

    // LA BORNE DOIT DIRE CE QU'ELLE FAIT.
    // Elle était comptée à partir de zéro : la coupure tombait au
    // HUITIÈME tableau imbriqué pour une borne déclarée à six.
    check('Le niveau ' . (AUDIT_REDACT_MAX_DEPTH + 1) . ' est coupé',
        str_contains(audit_encode($profond), 'trop profond'));

    // Et une structure DANS la borne passe entière : une coupure trop
    // zélée perdrait de l'information sans rien protéger.
    $juste = 'fond';

    for ($i = AUDIT_REDACT_MAX_DEPTH; $i >= 1; $i--) {
        $juste = ['n' . $i => $juste];
    }

    check('…et le niveau ' . AUDIT_REDACT_MAX_DEPTH . ' passe entier',
        !str_contains(audit_encode($juste), 'trop profond')
        && str_contains(audit_encode($juste), 'fond'));

    // =================================================================
    echo "\n  AUCUNE ÉCOLE NE LIT LE JOURNAL D'UNE AUTRE\n";

    $idA = ecole('JRN-A', 'Institut Alpha');
    $idB = ecole('JRN-B', 'Lycée Bêta');
    $ecoles = [$idA, $idB];

    $dirA = act_as($idA, 'DIRECTION');
    audit_log('document.issued', 'documents', 1, null, ['number' => 'SECRET-A'], 'Chez A');
    $idEntreeA = (int) db_value('SELECT MAX(id) FROM audit_logs WHERE school_id = :s',
        ['s' => $idA], true);

    $dirB = act_as($idB, 'DIRECTION');
    audit_log('document.issued', 'documents', 1, null, ['number' => 'SECRET-B'], 'Chez B');

    $listeB = audit_repo_search([]);

    check('B ne voit que ses propres entrées',
        array_filter($listeB['rows'], static fn (array $r): bool
            => (int) $r['school_id'] !== $idB) === [],
        $listeB['total'] . ' entrée(s)');

    check('Et le détail d\'une entrée de A lui est refusé',
        audit_repo_find($idEntreeA) === null);

    check('Les filtres de B ne listent que ses auteurs',
        array_filter(audit_repo_authors(), static fn (array $u): bool
            => (int) $u['id'] === $dirA) === []);

    reprendre($dirA, $idA);

    check('A retrouve bien la sienne', audit_repo_find($idEntreeA) !== null);

    // Le volume de A doit compter SES lignes, pas le total de la table.
    $volumeA = audit_repo_volume()['total'];
    $totalTable = (int) platform_scope_cli(static fn (): int
        => (int) db_value('SELECT COUNT(*) FROM audit_logs', [], true));

    check('Le volume de A ne compte pas la table entière',
        $volumeA < $totalTable && $volumeA > 0,
        'A : ' . $volumeA . ' · table : ' . $totalTable);

    // =================================================================
    echo "\n  UNE LECTURE INTER-ÉCOLES HORS PÉRIMÈTRE EST REFUSÉE\n";

    $refuse = false;

    try {
        audit_repo_search_platform([]);
    } catch (Throwable $e) {
        $refuse = str_contains($e->getMessage(), 'périmètre')
            || str_contains($e->getMessage(), 'inter-écoles');
    }

    check('Le garde-fou refuse le journal global sans habilitation', $refuse);

    // =================================================================
    echo "\n  LIRE ET PURGER SONT DEUX POUVOIRS DISTINCTS\n";

    check('La DIRECTION lit le journal', can('audit.view'));

    // VOLONTAIRE : le chef d'établissement est l'une des personnes que ce
    // journal trace. Lui donner le droit de l'effacer viderait la trace
    // de son sens.
    check('…mais ne peut PAS le purger', !can('audit.purge'));

    check('Et la purge lui est refusée en service, pas seulement à l\'écran',
        !audit_service_purge(730)['ok']);

    act_as($idA, 'SECRETARIAT');
    check('Le secrétariat ne lit même pas le journal', !can('audit.view'));

    // =================================================================
    echo "\n  LA PURGE\n";

    $adminA = act_as($idA, 'SCHOOL_ADMIN');

    check('SCHOOL_ADMIN peut purger', can('audit.purge'));

    // Le plancher.
    $trop = audit_service_purge(30);

    check('Une purge sous le plancher est refusée', !$trop['ok'], $trop['message']);

    check('Le plancher est bien de ' . AUDIT_RETENTION_FLOOR_DAYS . ' jours',
        !audit_service_purge(AUDIT_RETENTION_FLOOR_DAYS - 1)['ok']
        && audit_service_purge(AUDIT_RETENTION_FLOOR_DAYS)['ok']);

    // Du vieux chez A, du vieux chez B : la purge de A ne doit toucher
    // que A.
    entree_datee($idA, $adminA, 'login', date('Y-m-d H:i:s', strtotime('-3 years')));
    entree_datee($idA, $adminA, 'login', date('Y-m-d H:i:s', strtotime('-3 years')));
    entree_datee($idB, $dirB, 'login', date('Y-m-d H:i:s', strtotime('-3 years')));

    $avantB = (int) db_value('SELECT COUNT(*) FROM audit_logs WHERE school_id = :s',
        ['s' => $idB], true);

    $purge = audit_service_purge(730);

    check('La purge supprime les entrées trop anciennes',
        $purge['ok'] && (int) $purge['deleted'] === 2, $purge['message']);

    $apresB = (int) db_value('SELECT COUNT(*) FROM audit_logs WHERE school_id = :s',
        ['s' => $idB], true);

    check('Elle ne sort PAS de l\'école', $apresB === $avantB,
        $avantB . ' → ' . $apresB);

    // LA TRACE. Une purge qui ne se journalise pas est indiscernable
    // d'un effacement.
    $trace = db_one(
        'SELECT new_values FROM audit_logs
          WHERE school_id = :s AND action = \'audit.purged\'
          ORDER BY id DESC LIMIT 1',
        ['s' => $idA],
        true
    );

    check('La purge s\'inscrit elle-même dans le journal', $trace !== null);

    if ($trace !== null) {
        $v = (array) json_decode((string) $trace['new_values'], true);

        check('…avec le nombre de lignes supprimées', ($v['lignes'] ?? null) === 2);
        check('…et la borne appliquée', isset($v['anterieur_a']));
        check('Et cette trace SURVIT à la purge qui l\'a écrite',
            (int) db_value(
                'SELECT COUNT(*) FROM audit_logs
                  WHERE school_id = :s AND action = \'audit.purged\'',
                ['s' => $idA],
                true
            ) >= 1);
    }

    // =================================================================
    echo "\n  LES BORNES DE DATES SONT INCLUSIVES DES DEUX CÔTÉS\n";

    // `created_at` est un `datetime`. Une borne haute écrite `<= '2026-…'`
    // exclurait toute la journée, qui vaut 00:00:00.
    $jour = date('Y-m-d', strtotime('-10 days'));
    entree_datee($idA, $adminA, 'logout', $jour . ' 15:42:00');

    $dansLaBorne = audit_repo_search(['du' => $jour, 'au' => $jour, 'action' => 'logout']);

    check('Une entrée à 15h42 est dans la journée qui la borne',
        $dansLaBorne['total'] >= 1, $dansLaBorne['total'] . ' trouvée(s)');

    check('Et une journée antérieure ne la contient pas',
        audit_repo_search([
            'du' => date('Y-m-d', strtotime('-11 days')),
            'au' => date('Y-m-d', strtotime('-11 days')),
            'action' => 'logout',
        ])['total'] === 0);

    // =================================================================
    echo "\n  LA PAGINATION NE SAUTE NI NE RÉPÈTE\n";

    // Douze entrées dans la MÊME seconde : un tri sur `created_at` seul
    // les rendrait dans un ordre indéfini, et deux pages successives
    // pourraient se recouvrir.
    $seconde = date('Y-m-d H:i:s');

    for ($i = 0; $i < 12; $i++) {
        entree_datee($idA, $adminA, 'create', $seconde);
    }

    $p1 = audit_repo_search(['action' => 'create'], 1, 5);
    $p2 = audit_repo_search(['action' => 'create'], 2, 5);

    $ids1 = array_column($p1['rows'], 'id');
    $ids2 = array_column($p2['rows'], 'id');

    check('Deux pages ne partagent aucune ligne',
        array_intersect($ids1, $ids2) === [],
        count($ids1) . ' + ' . count($ids2) . ' ligne(s)');

    $decroissant = true;

    for ($i = 1; $i < count($ids1); $i++) {
        if ((int) $ids1[$i] >= (int) $ids1[$i - 1]) {
            $decroissant = false;
        }
    }

    check('Et l\'ordre est strictement décroissant', $decroissant,
        implode(' > ', $ids1));
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    foreach ($ecoles as $schoolId) {
        db_query('DELETE FROM audit_logs WHERE school_id = :s', ['s' => $schoolId], true);
        db_query('DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE school_id = :s)',
            ['s' => $schoolId], true);
        db_query('DELETE FROM users WHERE school_id = :s', ['s' => $schoolId], true);
        db_query('DELETE FROM schools WHERE id = :s', ['s' => $schoolId], true);
    }
}

echo "\n  ──────────────────────────────────────────────────\n";
printf("  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
