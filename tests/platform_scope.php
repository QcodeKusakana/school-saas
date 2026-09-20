<?php
/**
 * LE PÉRIMÈTRE PLATEFORME — la seule porte vers une lecture inter-écoles.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · le drapeau `true` de db_query() ne couvre PLUS que les tables
 *    globales : sur une table multi-école, il ne suffit plus ;
 *  · mentionner `school_id` sans le LIER ne trompe plus le garde-fou —
 *    ni dans un SELECT, ni dans un commentaire, ni dans une chaîne ;
 *  · un utilisateur rattaché à une école ne peut PAS ouvrir le
 *    périmètre, même en détenant la permission ;
 *  · un utilisateur plateforme sans la permission ne le peut pas non
 *    plus : les deux conditions sont nécessaires ;
 *  · le périmètre se referme toujours, exception comprise ;
 *  · platform_scope_cli() refuse de s'exécuter hors ligne de commande.
 *
 * Usage : php tests/platform_scope.php
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

/** Exécute et dit si le garde-fou a refusé. */
function refused(callable $work): bool
{
    try {
        $work();

        return false;
    } catch (RuntimeException) {
        return true;
    }
}

/**
 * L'habilitation est-elle refusée ?
 *
 * On interroge la DÉCISION, pas son rendu : `platform_require()` se
 * termine par `abort(403)`, qui imprime une page et arrête le
 * processus. Le 403 lui-même est vérifié par la recette HTTP.
 */
function forbidden(string $permission): bool
{
    return platform_refusal($permission) !== null;
}

$createdSchools = [];
$createdUsers   = [];

function make_user(?int $schoolId, ?string $roleCode, string $tag): int
{
    global $createdUsers;

    $id = db_insert('users', [
        'uuid'          => str_uuid(),
        'school_id'     => $schoolId,
        'username'      => 'scope.' . $tag,
        'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'last_name'     => 'SCOPE',
        'first_name'    => $tag,
        'status'        => 'active',
    ], true);

    if ($roleCode !== null) {
        db_query(
            'INSERT INTO user_roles (user_id, role_id)
             SELECT :u, id FROM roles WHERE code = :c AND school_id IS NULL',
            ['u' => $id, 'c' => $roleCode],
            true
        );
    }

    $createdUsers[] = $id;

    return $id;
}

function act_as(int $userId, ?int $schoolId): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['school_id'] = $schoolId;

    auth_user(true);
    perm_all(true);
    perm_roles(true);
    tenant_set($schoolId);
}

try {
    $schoolId = db_insert('schools', [
        'uuid' => str_uuid(), 'code' => 'SCOPE', 'slug' => 'scope',
        'name' => 'École du périmètre', 'status' => 'active',
    ], true);
    $createdSchools[] = $schoolId;

    // =================================================================
    echo "\n  LE DRAPEAU NE COUVRE PLUS QUE LES TABLES GLOBALES\n";

    check(
        'Table globale sans filtre : autorisée',
        !refused(static fn () => db_value('SELECT COUNT(*) FROM plans', [], true))
    );

    check(
        'Table multi-école sans filtre : REFUSÉE malgré le drapeau',
        refused(static fn () => db_value('SELECT COUNT(*) FROM students', [], true))
    );

    check(
        'Table multi-école AVEC filtre : autorisée',
        !refused(static fn () => db_value(
            'SELECT COUNT(*) FROM students WHERE school_id = :s',
            ['s' => $schoolId],
            true
        ))
    );

    // =================================================================
    echo "\n  MENTIONNER N'EST PAS LIER\n";

    // Ces quatre formes franchissaient l'ancien garde-fou. La première
    // est celle d'un tableau de bord éditeur — et celle d'une fuite.
    $forms = [
        'colonne seulement sélectionnée'
            => ['SELECT school_id, COUNT(*) FROM students GROUP BY school_id', []],
        'mot dans un commentaire'
            => ['SELECT COUNT(*) FROM students /* school_id */', []],
        'mot dans un commentaire de ligne'
            => ["SELECT COUNT(*) FROM students -- school_id\n", []],
        'mot dans une chaîne littérale'
            => ["SELECT COUNT(*) FROM students WHERE 'school_id' <> ''", []],
        'placeholder pris pour un filtre'
            => ['SELECT COUNT(*) FROM students WHERE id = :school_id', ['school_id' => 1]],
    ];

    foreach ($forms as $label => [$sql, $params]) {
        check("Refusé — {$label}", refused(static fn () => db_value($sql, $params)));
    }

    echo "\n  ET LES VRAIES LIAISONS PASSENT\n";

    $bindings = [
        'égalité simple'
            => ['SELECT COUNT(*) FROM students WHERE school_id = :s', ['s' => $schoolId]],
        'préfixée par un alias'
            => ['SELECT COUNT(*) FROM students st WHERE st.school_id = :s', ['s' => $schoolId]],
        'appartenance'
            => ['SELECT COUNT(*) FROM students WHERE school_id IN (:s)', ['s' => $schoolId]],
        'jointure portant le filtre'
            => ['SELECT COUNT(*) FROM students st
                   JOIN enrollments e ON e.student_id = st.id AND e.school_id = st.school_id
                  WHERE st.school_id = :s', ['s' => $schoolId]],
    ];

    foreach ($bindings as $label => [$sql, $params]) {
        check("Autorisé — {$label}", !refused(static fn () => db_value($sql, $params)));
    }

    // =================================================================
    echo "\n  LES DEUX CONDITIONS SONT NÉCESSAIRES\n";

    $platformUser = make_user(null, 'SUPER_ADMIN', 'plateforme');
    $schoolUser   = make_user($schoolId, 'SCHOOL_ADMIN', 'ecole');
    $bareUser     = make_user(null, null, 'nu');

    // 1. Un utilisateur d'ÉCOLE, même très habilité.
    act_as($schoolUser, $schoolId);

    check(
        'Un compte d\'école ne détient pas la permission plateforme',
        !can('platform.school.view')
    );

    check(
        'Et il ne peut pas ouvrir le périmètre',
        forbidden('platform.school.view')
    );

    // LE POINT QUI COMPTE : même si la permission lui était accordée par
    // erreur — un rôle personnalisé mal réglé — le rattachement le
    // refuse. La permission dit ce qu'on a le droit de FAIRE, jamais
    // SUR QUI.
    $granted = false;

    try {
        db_query(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p
              WHERE r.code = :r AND r.school_id IS NULL AND p.code = :p',
            ['r' => 'SCHOOL_ADMIN', 'p' => 'platform.school.view'],
            true
        );

        perm_all(true);
        $granted = can('platform.school.view');

        check('Permission plateforme accordée par erreur à un rôle d\'école', $granted);

        check(
            'MALGRÉ CELA, le périmètre reste fermé à un compte d\'école',
            forbidden('platform.school.view')
        );
    } finally {
        db_query(
            'DELETE rp FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.code = :r AND r.school_id IS NULL AND p.code = :p',
            ['r' => 'SCHOOL_ADMIN', 'p' => 'platform.school.view'],
            true
        );

        perm_all(true);
    }

    // 2. Un utilisateur PLATEFORME sans la permission.
    act_as($bareUser, null);

    check(
        'Un compte plateforme SANS la permission ne l\'ouvre pas',
        forbidden('platform.school.view')
    );

    check(
        'Le motif du refus nomme l\'habilitation, pas le rattachement',
        str_contains((string) platform_refusal('platform.school.view'), 'Habilitation')
    );

    // 3. Les deux réunies.
    act_as($platformUser, null);

    $counted = null;

    check(
        'Un compte plateforme habilité l\'ouvre',
        !refused(static function () use (&$counted): void {
            $counted = platform_scope(
                'platform.school.view',
                static fn (): int => (int) db_value('SELECT COUNT(*) FROM students', [], true)
            );
        })
    );

    check('Et il obtient bien un décompte', is_int($counted), (string) $counted);

    // =================================================================
    echo "\n  LE PÉRIMÈTRE SE REFERME TOUJOURS\n";

    check('Refermé après un usage normal', refused(
        static fn () => db_value('SELECT COUNT(*) FROM students', [], true)
    ));

    try {
        platform_scope('platform.school.view', static function (): void {
            throw new RuntimeException('panne au milieu du tableau de bord');
        });
    } catch (RuntimeException) {
        // attendu
    }

    check('Refermé même quand la lecture échoue', refused(
        static fn () => db_value('SELECT COUNT(*) FROM students', [], true)
    ));

    // L'IMBRICATION NE DOIT PAS REFERMER TROP TÔT.
    //
    // Une fonction transversale peut en appeler une autre ; si la
    // sortie de la première refermait le périmètre, la seconde
    // échouerait au milieu de son propre travail.
    $nested = platform_scope('platform.school.view', static function (): bool {
        platform_scope('platform.school.view', static fn (): int
            => (int) db_value('SELECT COUNT(*) FROM students', [], true));

        // Toujours ouvert après la sortie de l'appel imbriqué ?
        return !refused(static fn () => db_value('SELECT COUNT(*) FROM students', [], true));
    });

    check('Un périmètre imbriqué ne referme pas celui qui l\'englobe', $nested);

    check('Mais tout se referme à la fin', refused(
        static fn () => db_value('SELECT COUNT(*) FROM students', [], true)
    ));

    // =================================================================
    echo "\n  LE PÉRIMÈTRE CLI EST RÉSERVÉ À LA LIGNE DE COMMANDE\n";

    check(
        'En CLI, il ouvre sans habilitation',
        !refused(static fn () => platform_scope_cli(
            static fn (): int => (int) db_value('SELECT COUNT(*) FROM students', [], true)
        ))
    );

    check('PHP_SAPI vaut bien « cli » ici', PHP_SAPI === 'cli');

    // Hors CLI, il doit lever. On ne peut pas changer PHP_SAPI depuis un
    // test ; on vérifie donc que la garde est ÉCRITE, faute de pouvoir
    // la déclencher. Une garde absente du fichier serait une garde
    // absente tout court.
    $source = (string) file_get_contents(APP_PATH . '/core/platform.php');

    check(
        'La garde hors CLI existe dans le code',
        str_contains($source, "PHP_SAPI !== 'cli'")
            && str_contains($source, 'throw new RuntimeException')
    );

    // =================================================================
    echo "\n  LA LECTURE D'IDENTITÉ RESTE POSSIBLE SANS HABILITATION\n";

    // Sans elle, personne ne pourrait se connecter : on cherche un
    // compte par son identifiant AVANT de savoir son école.
    unset($_SESSION['user_id'], $_SESSION['school_id']);
    auth_user(true);
    perm_all(true);
    tenant_set(null);

    check(
        'Une recherche de compte par identifiant passe, non authentifié',
        !refused(static fn () => tenant_scope_identity(
            static fn (): bool => db_exists(
                'SELECT 1 FROM users WHERE username = :u',
                ['u' => 'scope.plateforme'],
                true
            )
        ))
    );

    check('Et elle se referme derrière elle', refused(
        static fn () => db_value('SELECT COUNT(*) FROM students', [], true)
    ));
} finally {
    unset($_SESSION['user_id'], $_SESSION['school_id']);

    // Le ménage touche des comptes de plusieurs écoles, dont un compte
    // plateforme : c'est une écriture transversale, et elle le déclare.
    platform_scope_cli(static function () use ($createdUsers, $createdSchools): void {
        foreach ($createdUsers as $id) {
            db_query('DELETE FROM user_roles WHERE user_id = :u', ['u' => $id], true);
            db_query('DELETE FROM users WHERE id = :id', ['id' => $id], true);
        }

        foreach ($createdSchools as $id) {
            db_query('DELETE FROM school_cycles WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM users WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM schools WHERE id = :id', ['id' => $id], true);
        }
    });
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
