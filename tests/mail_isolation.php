<?php
/**
 * Phase 8A — la messagerie.
 *
 * Ce que ces tests protègent
 * --------------------------
 *  · un secret n'est JAMAIS en clair : ni le mot de passe SMTP, ni le
 *    corps d'un message portant un lien ;
 *  · sans clé de chiffrement, on REFUSE d'enregistrer — pas de repli ;
 *  · une configuration ne s'active que par un essai réussi, et toute
 *    modification la désactive ;
 *  · un champ mot de passe vide CONSERVE celui en place ;
 *  · un message est écrit AVANT d'être tenté, et survit à l'échec ;
 *  · le délai de rejeu croît, puis le message est abandonné — jamais
 *    perdu en silence ;
 *  · le corps sensible disparaît à l'envoi comme à l'abandon ;
 *  · l'isolation multi-école tient sur la file comme sur la config.
 *
 * Usage : php tests/mail_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/modules/mail/services.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

function act_as(int $userId): void
{
    $_SESSION['user_id'] = $userId;
    auth_user(true);
    perm_all(true);
    perm_roles(true);
}

/** Lit une ligne de la file, hors périmètre, pour vérifier. */
function peek(int $id): ?array
{
    return platform_scope_cli(static fn (): ?array => db_one(
        'SELECT * FROM email_messages WHERE id = :id', ['id' => $id], true));
}

$schools = [];
$users   = [];

try {
    foreach (['MAIL-A' => 'École Mail A', 'MAIL-B' => 'École Mail B'] as $code => $name) {
        $schools[$code] = platform_scope_cli(static fn (): int => db_insert('schools', [
            'uuid' => str_uuid(), 'code' => $code, 'slug' => strtolower($code),
            'name' => $name, 'status' => 'active',
        ], true));
    }

    $A = $schools['MAIL-A'];
    $B = $schools['MAIL-B'];

    $mk = static function (int $school, string $u, string $role, ?string $email) use (&$users): int {
        $id = platform_scope_cli(static fn (): int => db_insert('users', [
            'uuid' => str_uuid(), 'school_id' => $school, 'username' => $u, 'email' => $email,
            'password_hash' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
            'last_name' => 'MAIL', 'first_name' => $u, 'status' => 'active',
        ], true));

        platform_scope_cli(static fn () => db_query(
            'INSERT INTO user_roles (user_id, role_id)
             SELECT :u, id FROM roles WHERE code = :c AND school_id IS NULL',
            ['u' => $id, 'c' => $role], true
        ));

        $users[] = $id;

        return $id;
    };

    $adminA = $mk($A, 'mailtest.adminA', 'SCHOOL_ADMIN', 'admina@test.cd');
    $dirA   = $mk($A, 'mailtest.dirA',   'DIRECTION',    'dira@test.cd');
    $adminB = $mk($B, 'mailtest.adminB', 'SCHOOL_ADMIN', 'adminb@test.cd');

    act_as($adminA);

    // =================================================================
    echo "\n  LE CHIFFREMENT, ET SON ABSENCE\n";

    check('Une clé est configurée sur cet environnement', crypto_available());

    $secret = 'MotDePasseSMTP!2026';
    $cipher = crypto_encrypt($secret);

    check('Le chiffré porte sa version', str_starts_with($cipher, 'v1:'));
    check('Il ne contient PAS le clair', !str_contains($cipher, $secret));
    check('Il se déchiffre', crypto_decrypt($cipher) === $secret);

    check('Deux chiffrements du MÊME secret diffèrent',
        crypto_encrypt($secret) !== crypto_encrypt($secret));

    check('Un chiffré abîmé rend null, sans lever',
        crypto_decrypt('v1:' . base64_encode('nimportequoi')) === null);

    check('Un format inconnu rend null', crypto_decrypt('v9:abc') === null);
    check('Une chaîne vide rend null', crypto_decrypt('') === null);

    // =================================================================
    echo "\n  LA CONFIGURATION NE S'ACTIVE PAS TOUTE SEULE\n";

    $saved = mail_service_save_settings([
        'host' => '127.0.0.1', 'port' => 2599, 'encryption' => 'none',
        'username' => 'ecole@test.cd', 'password' => $secret,
        'from_email' => 'direction@test.cd', 'from_name' => 'École Mail A',
    ]);

    check('La configuration s\'enregistre', $saved['ok'], $saved['message']);

    $set = mail_repo_settings();

    check('Elle naît INACTIVE', (int) $set['is_active'] === 0);
    check('Le message le dit', str_contains($saved['message'], 'essai'));
    check('La lecture d\'écran NE REND PAS le mot de passe',
        !array_key_exists('password_cipher', $set) && (int) $set['password_set'] === 1);

    check('Le mot de passe n\'est pas en clair en base',
        !platform_scope_cli(static fn (): bool => db_exists(
            'SELECT 1 FROM email_settings WHERE password_cipher LIKE :p',
            ['p' => '%' . $secret . '%'], true)));

    // UN CHAMP VIDE NE VEUT PAS DIRE « EFFACER ».
    mail_service_save_settings([
        'host' => '127.0.0.1', 'port' => 2600, 'encryption' => 'none',
        'username' => 'ecole@test.cd', 'password' => '',
        'from_email' => 'direction@test.cd',
    ]);

    check('Réenregistrer sans mot de passe le CONSERVE',
        (int) mail_repo_settings()['password_set'] === 1);

    check('Et le port a bien changé', (int) mail_repo_settings()['port'] === 2600);

    // =================================================================
    // AUDIT 8A — ON AVERTIT QUAND LE DOMAINE NE CORRESPOND PAS.
    // Un expéditeur que le SPF du domaine ne couvre pas finit en
    // indésirables ; se taire laisserait l'école croire qu'elle a
    // prévenu les familles.
    echo "\n  UN EXPÉDITEUR D'UN AUTRE DOMAINE EST SIGNALÉ\n";

    check('Même domaine : aucun avertissement',
        mail_domain_warning('mail.monecole.cd', 'direction@monecole.cd') === null);

    check('Domaine étranger : averti',
        mail_domain_warning('mail.hebergeur.com', 'direction@monecole.cd') !== null);

    check('Et l\'avertissement nomme les deux domaines',
        str_contains((string) mail_domain_warning('mail.hebergeur.com', 'direction@monecole.cd'), 'monecole.cd')
        && str_contains((string) mail_domain_warning('mail.hebergeur.com', 'direction@monecole.cd'), 'hebergeur.com'));

    check('Il AVERTIT sans refuser',
        mail_service_save_settings([
            'host' => 'mail.hebergeur.com', 'port' => 587, 'encryption' => 'tls',
            'from_email' => 'direction@monecole.cd',
        ])['ok']);

    check('Et le message porte l\'avertissement',
        str_contains(mail_service_save_settings([
            'host' => 'mail.hebergeur.com', 'port' => 587, 'encryption' => 'tls',
            'from_email' => 'direction@monecole.cd',
        ])['message'], 'indésirables'));

    // On remet une configuration cohérente pour la suite.
    mail_service_save_settings([
        'host' => '127.0.0.1', 'port' => 2600, 'encryption' => 'none',
        'username' => 'ecole@test.cd', 'password' => $secret,
        'from_email' => 'direction@test.cd',
    ]);

    // =================================================================
    // AUDIT 8A — LIRE UNE FILE NE RÉSERVE RIEN.
    // Deux passages du travail périodique lancés à la même seconde
    // envoyaient tous deux le même message : le destinataire le
    // recevait DEUX FOIS. Mesuré à deux processus contre un vrai
    // serveur SMTP (voir la recette).
    echo "\n  UN MESSAGE SE RÉSERVE AVANT DE PARTIR\n";

    $conc = mail_queue([
        'to' => 'parent@famille.cd', 'subject' => 'Réservation',
        'text' => 'corps', 'purpose' => 'test', 'school_id' => $A,
    ]);

    // Le message est resté en file (aucun serveur ne répond sur 2600).
    $ligne = peek((int) $conc['id']);

    check('Après une tentative, l\'échéance est REPOUSSÉE',
        $ligne['next_attempt_at'] !== null);

    // On le rend dû, puis on simule le SECOND lecteur : il détient la
    // même ligne, lue avant que le premier ne la réserve.
    platform_scope_cli(static fn () => db_query(
        'UPDATE email_messages SET next_attempt_at = NOW() WHERE id = :id',
        ['id' => $conc['id']], true));

    $vue = peek((int) $conc['id']);

    $premier = mail_attempt($vue);
    $second  = mail_attempt($vue);   // MÊME ligne, comme un second travail

    check('Le premier prend la main', $premier['error'] === null
        || !str_contains((string) $premier['error'], 'déjà pris en charge'));

    check('Le second est ÉCARTÉ, sans rien envoyer',
        str_contains((string) $second['error'], 'déjà pris en charge'),
        (string) $second['error']);

    check('Et il n\'a pas compté de tentative',
        (int) peek((int) $conc['id'])['attempts'] === (int) $ligne['attempts'] + 1,
        'tentatives = ' . peek((int) $conc['id'])['attempts']);

    // =================================================================
    echo "\n  RIEN NE PART SANS CONFIGURATION ACTIVE\n";

    $q = mail_queue([
        'to' => 'parent@famille.cd', 'subject' => 'Essai',
        'text' => 'corps', 'purpose' => 'test', 'school_id' => $A,
    ]);

    check('Le message est tout de même ÉCRIT', $q['queued']);
    check('Mais il n\'est pas parti', !$q['sent']);
    check('Et le motif est nommé',
        str_contains((string) $q['error'], 'Aucun serveur'), (string) $q['error']);

    $row = peek((int) $q['id']);
    check('Il reste en file', (string) $row['status'] === 'queued');
    check('Avec une tentative comptée', (int) $row['attempts'] === 1);
    check('Et une erreur consignée', $row['last_error'] !== null);

    // =================================================================
    echo "\n  LE CORPS SENSIBLE EST CHIFFRÉ AU REPOS\n";

    $lien = 'https://exemple.cd/mot-de-passe/reinitialiser/JETON-SECRET-123';

    $s1 = mail_queue([
        'to' => 'parent@famille.cd', 'subject' => 'Réinitialisation',
        'text' => "Ouvrez ce lien : " . $lien, 'purpose' => 'password_reset',
        'school_id' => $A, 'sensitive' => true,
    ]);

    $row = peek((int) $s1['id']);

    check('Le corps stocké est chiffré', str_starts_with((string) $row['body_text'], 'v1:'));
    check('Le jeton n\'apparaît nulle part en clair',
        !platform_scope_cli(static fn (): bool => db_exists(
            'SELECT 1 FROM email_messages WHERE body_text LIKE :p',
            ['p' => '%JETON-SECRET-123%'], true)));

    check('Et il se retrouve au déchiffrement',
        str_contains((string) crypto_decrypt((string) $row['body_text']), $lien));

    // =================================================================
    echo "\n  LE DÉLAI DE REJEU CROÎT, PUIS ON ABANDONNE\n";

    $id = (int) $s1['id'];
    $vus = [];

    for ($i = 0; $i < 6; $i++) {
        platform_scope_cli(static fn () => db_query(
            'UPDATE email_messages SET next_attempt_at = NOW() WHERE id = :id',
            ['id' => $id], true));

        mail_worker_run();

        $row = peek($id);
        $vus[] = (string) $row['status'];

        if ((string) $row['status'] !== 'queued') {
            break;
        }
    }

    $final = peek($id);

    check('Le message finit ABANDONNÉ, pas oublié',
        (string) $final['status'] === 'failed', implode(' → ', $vus));

    check('Après exactement max_attempts tentatives',
        (int) $final['attempts'] === (int) $final['max_attempts'],
        $final['attempts'] . '/' . $final['max_attempts']);

    check('La dernière erreur est conservée', $final['last_error'] !== null);

    check('ET LE SECRET A DISPARU', $final['body_text'] === null);

    check('Aucun rejeu n\'est reprogrammé', $final['next_attempt_at'] === null);

    // =================================================================
    echo "\n  UN MESSAGE SENSIBLE EN ÉCHEC NE SE REJOUE PAS\n";

    $retry = mail_service_retry($id);

    check('Le rejeu est refusé', !$retry['ok']);
    check('Et l\'écran dit quoi faire',
        str_contains($retry['message'], 'nouvelle réinitialisation'));

    // Un message NON sensible, lui, se rejoue.
    $plain = mail_queue([
        'to' => 'parent@famille.cd', 'subject' => 'Note de service',
        'text' => 'Bonjour', 'purpose' => 'test', 'school_id' => $A,
    ]);

    platform_scope_cli(static fn () => db_query(
        'UPDATE email_messages SET status = \'failed\' WHERE id = :id',
        ['id' => $plain['id']], true));

    check('Un message ordinaire se rejoue', mail_service_retry((int) $plain['id'])['ok']);

    // =================================================================
    echo "\n  SANS ADRESSE, PAS DE MESSAGE — ET ON LE DIT\n";

    $none = mail_queue([
        'to' => '', 'subject' => 'x', 'text' => 'x', 'purpose' => 'test', 'school_id' => $A,
    ]);

    check('Une adresse vide est refusée', !$none['ok']);
    check('Rien n\'est écrit en file', $none['id'] === null);

    check('Une adresse mal formée aussi',
        !mail_queue(['to' => 'pas-une-adresse', 'subject' => 'x', 'text' => 'x',
                     'purpose' => 'test', 'school_id' => $A])['ok']);

    // =================================================================
    echo "\n  LA RÉINITIALISATION N'ÉNUMÈRE PAS LES COMPTES\n";

    require_once APP_PATH . '/modules/auth/services.php';

    unset($_SESSION['user_id']);
    auth_user(true);
    tenant_set(null);

    $existe   = auth_service_request_reset('mailtest.dirA');
    $inconnu  = auth_service_request_reset('nexiste.vraiment.pas');
    $sansMail = auth_service_request_reset('mailtest.adminA');

    check('Un compte existant rend ok', $existe['ok'] === true);
    check('Un compte inconnu rend AUSSI ok', $inconnu['ok'] === true);
    check('Le service ne trahit pas la différence',
        $existe['ok'] === $inconnu['ok'] && $sansMail['ok'] === $inconnu['ok']);

    check('Mais un jeton n\'est créé que pour le compte réel',
        $existe['link'] !== null && $inconnu['link'] === null);

    // =================================================================
    echo "\n  L'ISOLATION TIENT\n";

    act_as($adminB);
    tenant_set($B);

    check('L\'école B ne voit pas la configuration de A',
        mail_repo_settings() === null);

    check('Ni les messages de A', mail_repo_journal([], 1)['total'] === 0);

    check('Et ne peut pas rejouer un message de A',
        !mail_service_retry($id)['ok']);

    act_as($adminA);
    tenant_set($A);

    check('Alors que A voit les siens', mail_repo_journal([], 1)['total'] > 0);

    // =================================================================
    echo "\n  LA PERMISSION EST DISTINCTE DE school.edit\n";

    act_as($dirA);
    tenant_set($A);

    check('Une direction porte bien school.edit', can('school.edit'));
    check('Mais PAS email.manage', !can('email.manage'));
    check('Et le service la refuse',
        !mail_service_save_settings(['host' => 'x', 'port' => 25,
            'encryption' => 'none', 'from_email' => 'a@b.cd'])['ok']);

    check('L\'essai aussi', !mail_service_send_test('a@b.cd')['ok']);

    // =================================================================
    echo "\n  LE MESSAGE COMPOSÉ EST BIEN FORMÉ\n";

    $mime = smtp_build_message(
        ['from_email' => 'direction@test.cd', 'from_name' => 'École Éléphant'],
        ['to' => 'parent@famille.cd', 'to_name' => 'Mme KABILA',
         'subject' => 'Bulletin — élève n°42', 'text' => 'Bonjour', 'html' => '<p>Bonjour</p>']
    );

    check('Le sujet accentué est encodé', str_contains($mime, '=?UTF-8?B?'));
    check('Le message est multipart', str_contains($mime, 'multipart/alternative'));
    check('Il porte les deux versions',
        str_contains($mime, 'text/plain') && str_contains($mime, 'text/html'));
    check('Et il ne déclenche pas de réponse automatique',
        str_contains($mime, 'Auto-Submitted: auto-generated'));

    // L'INJECTION D'EN-TÊTE.
    $inject = smtp_build_message(
        ['from_email' => 'a@b.cd', 'from_name' => 'X'],
        ['to' => 'c@d.cd', 'subject' => "Sujet\r\nBcc: pirate@ailleurs.cd",
         'text' => 'corps']
    );

    check('Un retour à la ligne dans le sujet N\'INJECTE PAS d\'en-tête',
        !preg_match('/^Bcc:/mi', $inject));

} finally {
    unset($_SESSION['user_id']);

    platform_scope_cli(static function () use ($schools, $users): void {
        foreach ($schools as $id) {
            db_query('DELETE FROM email_messages WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM email_settings WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM password_resets WHERE user_id IN
                      (SELECT id FROM users WHERE school_id = :s)', ['s' => $id], true);
            db_query('DELETE FROM audit_logs WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM user_roles WHERE user_id IN
                      (SELECT id FROM users WHERE school_id = :s)', ['s' => $id], true);
            db_query('UPDATE users SET created_by = NULL WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM users WHERE school_id = :s', ['s' => $id], true);
            db_query('DELETE FROM schools WHERE id = :id', ['id' => $id], true);
        }

        foreach ($users as $u) {
            db_query('DELETE FROM user_roles WHERE user_id = :u', ['u' => $u], true);
            db_query('DELETE FROM users WHERE id = :id', ['id' => $u], true);
        }

        db_query('DELETE FROM audit_logs WHERE action LIKE :a AND school_id IS NULL',
            ['a' => 'email.%'], true);
    });
}

printf("\n  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
