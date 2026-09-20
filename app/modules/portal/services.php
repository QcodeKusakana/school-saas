<?php
/**
 * Module PORTAIL — services (phase 6A).
 *
 * LE RATTACHEMENT D'UN COMPTE À UNE FICHE TUTEUR
 * ==============================================
 * `guardians.user_id` existait depuis la phase 3 ; aucun écran ne le
 * remplissait. Le lien était prévu, la porte n'avait jamais été posée :
 * un établissement ne pouvait donc donner accès au portail à personne.
 *
 * CE QUE CE SERVICE FAIT, ET CE QU'IL REFUSE DE FAIRE
 * ---------------------------------------------------
 * Il crée un compte de connexion et le relie à la fiche du tuteur. Il
 * ne choisit PAS le mot de passe à la place de la famille : il en tire
 * un au hasard, l'affiche UNE SEULE FOIS à l'agent qui crée le compte,
 * et impose son changement à la première connexion.
 *
 * Le mot de passe initial n'est jamais stocké en clair, jamais
 * journalisé, jamais envoyé par courriel — le projet n'a pas de service
 * d'envoi, et prétendre le contraire serait mentir sur le circuit réel.
 * Il est remis de la main à la main, comme un identifiant scolaire.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * Longueur du mot de passe initial.
 *
 * Douze caractères tirés d'un alphabet sans ambiguïté : ni O/0, ni
 * I/l/1. Un parent qui recopie son code sur un papier ne doit pas se
 * tromper de caractère — sinon il appelle l'école, et le portail coûte
 * plus de travail qu'il n'en fait gagner.
 */
const PORTAL_PASSWORD_LENGTH   = 12;
const PORTAL_PASSWORD_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

/** Mot de passe initial, aléatoire et sans caractère ambigu. */
function portal_generate_password(): string
{
    $alphabet = PORTAL_PASSWORD_ALPHABET;
    $max      = strlen($alphabet) - 1;
    $out      = '';

    for ($i = 0; $i < PORTAL_PASSWORD_LENGTH; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }

    return $out;
}

/**
 * Identifiant de connexion d'un tuteur, unique dans la plateforme.
 *
 * Construit sur le nom, pas sur le téléphone : un numéro change, un
 * identifiant de connexion ne doit pas. Le suffixe numérique règle les
 * homonymes, fréquents en RDC.
 */
function portal_build_username(array $guardian): string
{
    $base = str_slug(
        (string) $guardian['first_name'] . ' ' . (string) $guardian['last_name'],
        '.'
    );

    $base = $base !== '' ? mb_substr($base, 0, 40) : 'tuteur';

    $candidate = $base;
    $suffix    = 1;

    // `users.username` est unique pour TOUT le produit (uq_users_username),
    // pas par école : l'unicité doit donc se vérifier hors périmètre
    // d'établissement. C'est une lecture d'identité, elle en porte le nom.
    while (tenant_scope_identity(static fn (): bool => db_exists(
        'SELECT 1 FROM users WHERE username = :u', ['u' => $candidate], true
    ))) {
        $suffix++;
        $candidate = $base . '.' . $suffix;
    }

    return $candidate;
}

/**
 * Crée l'accès au portail pour un tuteur.
 *
 * @return array{ok: bool, message: string, username?: string, password?: string}
 */
function portal_service_create_guardian_access(int $guardianId): array
{
    if (!can('user.create')) {
        return [
            'ok'      => false,
            'message' => 'Créer un compte de connexion relève de l\'administration de l\'école.',
        ];
    }

    $guardian = tenant_one('guardians', 'id = :id AND deleted_at IS NULL', ['id' => $guardianId]);

    if ($guardian === null) {
        return ['ok' => false, 'message' => 'Tuteur introuvable.'];
    }

    if ($guardian['user_id'] !== null) {
        return [
            'ok'      => false,
            'message' => 'Ce tuteur dispose déjà d\'un accès. Pour lui redonner un mot de '
                . 'passe, utilisez « Régénérer » sur sa ligne.',
        ];
    }

    // UN ACCÈS SANS PUPILLE N'A AUCUN SENS.
    //
    // Le portail se fonde sur le lien de tutelle : un compte créé pour
    // une fiche rattachée à aucun élève ouvrirait un espace vide, et
    // l'agent croirait avoir fait le travail.
    $hasChildren = db_exists(
        'SELECT 1 FROM student_guardians
          WHERE school_id = :school_id AND guardian_id = :guardian_id LIMIT 1',
        ['school_id' => tenant_require(), 'guardian_id' => $guardianId]
    );

    if (!$hasChildren) {
        return [
            'ok'      => false,
            'message' => 'Ce tuteur n\'est rattaché à aucun élève : son espace serait vide. '
                . 'Rattachez-le d\'abord à une fiche élève.',
        ];
    }

    $username = portal_build_username($guardian);
    $password = portal_generate_password();

    $userId = db_transaction(static function () use ($guardian, $guardianId, $username, $password): int {
        $id = db_insert('users', [
            'uuid'                 => str_uuid(),
            'school_id'            => tenant_require(),
            'username'             => $username,
            // Le courriel est facultatif sur une fiche tuteur, et la
            // colonne est unique : mieux vaut NULL qu'une valeur
            // fabriquée qui entrerait en collision au deuxième tuteur
            // sans adresse.
            'email'                => $guardian['email'] ?: null,
            'password_hash'        => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'last_name'            => $guardian['last_name'],
            'post_name'            => $guardian['post_name'],
            'first_name'           => $guardian['first_name'],
            'gender'               => $guardian['gender'],
            'phone'                => $guardian['phone'],
            'status'               => 'active',
            // LE CHANGEMENT EST IMPOSÉ À LA PREMIÈRE CONNEXION.
            // Un mot de passe connu de l'agent qui l'a créé n'est pas
            // un secret : il ne doit pas survivre à la première visite.
            'must_change_password' => 1,
        ], true);

        db_query(
            'INSERT INTO user_roles (user_id, role_id)
             SELECT :user_id, id FROM roles WHERE code = \'PARENT\' AND school_id IS NULL',
            ['user_id' => $id],
            true
        );

        tenant_update('guardians', ['user_id' => $id], 'id = :id', ['id' => $guardianId]);

        return $id;
    });

    // Le mot de passe N'EST PAS journalisé : le journal d'audit est
    // consultable, et un secret consultable n'est plus un secret.
    audit_log('guardian.access', 'guardians', $guardianId, null, [
        'user_id'  => $userId,
        'username' => $username,
    ]);

    return [
        'ok'       => true,
        'message'  => 'Accès créé pour ' . $guardian['first_name'] . ' ' . $guardian['last_name'] . '.',
        'username' => $username,
        'password' => $password,
    ];
}

// =====================================================================
//  L'ESPACE ÉLÈVE (phase 6B)
// =====================================================================

/**
 * L'établissement montre-t-il le solde des frais à l'élève lui-même ?
 *
 * FAUX par défaut. En RDC, la dette scolaire est une affaire de parents :
 * un élève de douze ans n'a pas à porter le poids d'un minerval impayé,
 * et l'école n'a pas à le lui annoncer par un écran.
 *
 * Le réglage existe parce que la règle n'est pas universelle : dans une
 * école d'humanités où les élèves majeurs paient eux-mêmes, le cacher
 * serait absurde.
 */
function portal_student_sees_fees(): bool
{
    return (bool) school_setting('portal.student_sees_fees', false);
}

/**
 * Identifiant de connexion d'un élève.
 *
 * Construit sur le MATRICULE, pas sur le nom : les homonymes sont
 * fréquents, et le matricule est précisément l'identifiant que l'école
 * a déjà attribué. L'élève le connaît par cœur, il figure sur sa carte
 * et sur son bulletin — c'est le meilleur identifiant possible.
 */
function portal_build_student_username(array $student): string
{
    $base = str_slug('e ' . (string) $student['matricule'], '.');
    $base = $base !== '' ? mb_substr($base, 0, 40) : 'eleve';

    $candidate = $base;
    $suffix    = 1;

    // `users.username` est unique pour TOUT le produit (uq_users_username),
    // pas par école : l'unicité doit donc se vérifier hors périmètre
    // d'établissement. C'est une lecture d'identité, elle en porte le nom.
    while (tenant_scope_identity(static fn (): bool => db_exists(
        'SELECT 1 FROM users WHERE username = :u', ['u' => $candidate], true
    ))) {
        $suffix++;
        $candidate = $base . '.' . $suffix;
    }

    return $candidate;
}

/**
 * Crée l'accès au portail pour un élève.
 *
 * Même circuit que pour les tuteurs : mot de passe tiré au hasard sur un
 * alphabet sans ambiguïté, affiché UNE SEULE FOIS, jamais journalisé,
 * changement imposé à la première connexion.
 *
 * AUCUN COURRIEL N'EST DEMANDÉ NI INVENTÉ
 * ---------------------------------------
 * Un élève n'a pas forcément d'adresse, et la colonne `users.email` est
 * unique : fabriquer `matricule@ecole.cd` créerait des collisions dès la
 * deuxième école. Le champ reste NULL, et la connexion se fait par
 * l'identifiant.
 *
 * @return array{ok: bool, message: string, username?: string, password?: string}
 */
function portal_service_create_student_access(int $studentId): array
{
    if (!can('user.create')) {
        return [
            'ok'      => false,
            'message' => 'Créer un compte de connexion relève de l\'administration de l\'école.',
        ];
    }

    $student = tenant_one('students', 'id = :id AND deleted_at IS NULL', ['id' => $studentId]);

    if ($student === null) {
        return ['ok' => false, 'message' => 'Élève introuvable.'];
    }

    if ($student['user_id'] !== null) {
        return [
            'ok'      => false,
            'message' => 'Cet élève dispose déjà d\'un accès. Pour lui redonner un mot de '
                . 'passe, utilisez « Régénérer son accès » en haut de sa fiche.',
        ];
    }

    // UN ACCÈS SANS INSCRIPTION N'A AUCUN SENS.
    //
    // Un élève préinscrit dont le dossier n'est pas encore accepté
    // ouvrirait un espace vide. Même raison que pour un tuteur sans
    // pupille : l'agent croirait avoir fait le travail.
    $enrolled = db_exists(
        'SELECT 1 FROM enrollments
          WHERE school_id = :school_id AND student_id = :student_id
            AND status <> \'cancelled\' LIMIT 1',
        ['school_id' => tenant_require(), 'student_id' => $studentId]
    );

    if (!$enrolled) {
        return [
            'ok'      => false,
            'message' => 'Cet élève n\'a aucune inscription active : son espace serait vide.',
        ];
    }

    $username = portal_build_student_username($student);
    $password = portal_generate_password();

    $userId = db_transaction(static function () use ($student, $studentId, $username, $password): int {
        $id = db_insert('users', [
            'uuid'                 => str_uuid(),
            'school_id'            => tenant_require(),
            'username'             => $username,
            'email'                => null,
            'password_hash'        => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'last_name'            => $student['last_name'],
            'post_name'            => $student['post_name'],
            'first_name'           => $student['first_name'],
            'gender'               => $student['gender'],
            'status'               => 'active',
            'must_change_password' => 1,
        ], true);

        db_query(
            'INSERT INTO user_roles (user_id, role_id)
             SELECT :user_id, id FROM roles WHERE code = \'ELEVE\' AND school_id IS NULL',
            ['user_id' => $id],
            true
        );

        tenant_update('students', ['user_id' => $id], 'id = :id', ['id' => $studentId]);

        return $id;
    });

    // Le mot de passe N'EST PAS journalisé.
    audit_log('student.access', 'students', $studentId, null, [
        'user_id'  => $userId,
        'username' => $username,
    ]);

    return [
        'ok'       => true,
        'message'  => 'Accès créé pour ' . $student['first_name'] . ' ' . $student['last_name'] . '.',
        'username' => $username,
        'password' => $password,
    ];
}

// =====================================================================
//  RÉGÉNÉRER UN ACCÈS (audit 6B)
//
//  LE DÉFAUT QUE CETTE SECTION CORRIGE
//  -----------------------------------
//  Les phases 6A et 6B créaient des comptes dont le mot de passe est
//  remis UNE SEULE FOIS, de la main à la main. Leurs messages
//  renvoyaient ensuite « à la réinitialisation depuis la gestion des
//  utilisateurs » — un écran qui N'EXISTE PAS : il n'y a ni module
//  `users`, ni route `/utilisateurs`.
//
//  Conséquence : au premier mot de passe perdu, le compte était mort.
//  « Mot de passe oublié » exige une adresse courriel qu'un élève n'a
//  pas, et renvoie vers un administrateur qui n'avait aucun moyen d'agir.
//
//  UN PRODUIT QUI DONNE UN ACCÈS DOIT SAVOIR LE RENDRE.
//
//  Ce qui est livré ici n'est PAS un module de gestion des utilisateurs
//  — il aura sa propre phase. C'est la seule action que le portail
//  promet : régénérer le mot de passe, depuis l'écran même où l'accès a
//  été créé.
// =====================================================================

/**
 * Régénère le mot de passe d'un compte de portail.
 *
 * Le nouveau mot de passe est retourné pour être affiché UNE SEULE FOIS,
 * comme à la création. `must_change_password` est remis à 1 : un mot de
 * passe connu de l'agent qui l'a régénéré ne doit pas survivre à la
 * première visite.
 *
 * Refuse tout compte qui n'est PAS un compte de portail. Cette fonction
 * ne doit jamais devenir une porte dérobée pour réinitialiser celui d'un
 * directeur ou d'un comptable : cela relèvera du module utilisateurs,
 * avec ses propres garanties.
 *
 * @return array{ok: bool, message: string, username?: string, password?: string}
 */
function portal_service_reset_access(int $userId): array
{
    if (!can('user.reset_password')) {
        return [
            'ok'      => false,
            'message' => 'Régénérer un mot de passe relève de l\'administration de l\'école.',
        ];
    }

    $schoolId = tenant_require();

    // Le compte doit appartenir à CETTE école.
    $account = db_one(
        'SELECT id, username, school_id FROM users WHERE id = :id AND school_id = :school_id',
        ['id' => $userId, 'school_id' => $schoolId],
        true
    );

    if ($account === null) {
        return ['ok' => false, 'message' => 'Compte introuvable dans cet établissement.'];
    }

    // UN COMPTE DE PORTAIL, ET RIEN D'AUTRE.
    //
    // Le lien est la preuve : ce compte est rattaché à une fiche tuteur
    // ou à une fiche élève de cette école. Un compte de personnel n'en a
    // aucun, et cette fonction le refuse.
    $isPortalAccount = db_exists(
        'SELECT 1 FROM guardians
          WHERE school_id = :school_id AND user_id = :user_id AND deleted_at IS NULL
          LIMIT 1',
        ['school_id' => $schoolId, 'user_id' => $userId]
    ) || db_exists(
        'SELECT 1 FROM students
          WHERE school_id = :school_id AND user_id = :user_id AND deleted_at IS NULL
          LIMIT 1',
        ['school_id' => $schoolId, 'user_id' => $userId]
    );

    if (!$isPortalAccount) {
        return [
            'ok'      => false,
            'message' => 'Ce compte n\'est pas un accès au portail : sa réinitialisation '
                . 'relève de la gestion des utilisateurs du personnel.',
        ];
    }

    $password = portal_generate_password();

    // L'ÉCOLE EST DANS L'ÉCRITURE, PAS SEULEMENT DANS LE CONTRÔLE.
    //
    // L'appartenance est déjà vérifiée vingt lignes plus haut. Ce filtre
    // fait double emploi — et c'est exactement pourquoi il est là : une
    // écriture sur les identifiants d'un compte ne doit pas dépendre
    // d'un contrôle situé ailleurs dans la fonction, qu'un remaniement
    // futur pourrait déplacer ou court-circuiter.
    db_query(
        'UPDATE users
            SET password_hash = :hash,
                must_change_password = 1,
                password_changed_at = NOW(),
                failed_attempts = 0,
                locked_until = NULL
          WHERE id = :id AND school_id = :school_id',
        [
            'hash'      => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'id'        => $userId,
            'school_id' => $schoolId,
        ]
    );

    // Le mot de passe N'EST PAS journalisé.
    audit_log('portal.reset_access', 'users', $userId, null, [
        'username' => $account['username'],
    ]);

    return [
        'ok'       => true,
        'message'  => 'Nouveau mot de passe généré pour ' . $account['username'] . '.',
        'username' => (string) $account['username'],
        'password' => $password,
    ];
}
