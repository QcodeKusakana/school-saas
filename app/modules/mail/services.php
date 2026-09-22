<?php
/**
 * Module MAIL — les règles.
 *
 * TROIS PRINCIPES, ET CHACUN FERME UNE PORTE
 * ===========================================
 *
 * 1. LE MOT DE PASSE NE SE RELIT JAMAIS DEPUIS UN ÉCRAN.
 *    On l'écrit, on ne le rend pas. Un champ laissé vide au
 *    réenregistrement CONSERVE celui déjà en place : sans cela,
 *    corriger un numéro de port effacerait le mot de passe.
 *
 * 2. SANS CLÉ DE CHIFFREMENT, ON REFUSE D'ENREGISTRER.
 *    Pas de repli en clair « en attendant ». Un produit qui range un
 *    secret en clair faute de configuration est pire qu'un produit
 *    sans secret : il en donne l'illusion.
 *
 * 3. UNE CONFIGURATION NE S'ACTIVE PAS SANS AVOIR SERVI UNE FOIS.
 *    Un envoi d'essai doit avoir abouti. Sinon l'école croit prévenir
 *    les familles alors que rien ne part — et elle ne s'en apercevra
 *    que le jour d'un impayé.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * L'adresse d'expédition appartient-elle au domaine du serveur ?
 *
 * ON AVERTIT, ON NE REFUSE PAS. Un relais légitime existe : une école
 * peut expédier `direction@monecole.cd` par un serveur mutualisé dont
 * l'hôte porte un tout autre nom. Refuser bloquerait ces
 * configurations valables.
 *
 * Mais se taire serait pire : un expéditeur que le SPF du domaine ne
 * couvre pas finit en indésirables, et l'école croira avoir prévenu
 * les familles. C'est précisément ce que cette phase refuse de
 * laisser arriver.
 *
 *   > Ce qu'on ne peut pas vérifier, on le dit.
 *
 * La comparaison porte sur les deux derniers libellés — heuristique
 * assumée, sans liste publique des suffixes. Un faux avertissement ne
 * coûte qu'une phrase ; un silence coûte une campagne d'envois.
 */
function mail_domain_warning(string $host, string $fromEmail): ?string
{
    $at = strrpos($fromEmail, '@');

    if ($at === false) {
        return null;
    }

    $deux = static function (string $nom): string {
        $parts = array_filter(explode('.', strtolower(trim($nom, '. '))));

        return implode('.', array_slice($parts, -2));
    };

    $domaineExpediteur = $deux(substr($fromEmail, $at + 1));
    $domaineServeur    = $deux($host);

    if ($domaineExpediteur === '' || $domaineServeur === ''
        || $domaineExpediteur === $domaineServeur) {
        return null;
    }

    return 'L\'adresse d\'expédition (' . $domaineExpediteur . ') n\'appartient pas au '
        . 'domaine du serveur (' . $domaineServeur . '). C\'est valable si votre '
        . 'hébergeur vous sert de relais, mais si ce n\'est pas le cas, le SPF de '
        . 'votre domaine ne couvrira pas cet expéditeur et les messages partiront '
        . 'en indésirables. Faites l\'essai et vérifiez où il arrive.';
}

/**
 * Enregistre la configuration d'envoi de l'école courante.
 *
 * @param array{host: string, port: int|string, encryption: string,
 *              username?: string, password?: string, from_email: string,
 *              from_name?: string, reply_to?: string} $input
 * @return array{ok: bool, message: string}
 */
function mail_service_save_settings(array $input): array
{
    if (!can('email.manage')) {
        return ['ok' => false, 'message' => 'Configurer l\'envoi d\'e-mails relève de '
            . 'l\'administration de l\'établissement.'];
    }

    if (!crypto_available()) {
        return [
            'ok'      => false,
            'message' => 'Aucune clé de chiffrement n\'est configurée sur ce serveur : '
                . 'le mot de passe ne pourrait pas être protégé, et il est exclu de '
                . 'l\'enregistrer en clair. Renseignez security.encryption_key dans '
                . 'app/config/config.local.php.',
        ];
    }

    $schoolId = tenant_require();

    $host = trim((string) ($input['host'] ?? ''));
    $port = (int) ($input['port'] ?? 0);

    if ($host === '') {
        return ['ok' => false, 'message' => 'Le serveur SMTP est obligatoire.'];
    }

    if ($port < 1 || $port > 65535) {
        return ['ok' => false, 'message' => 'Le port doit être compris entre 1 et 65535.'];
    }

    $encryption = (string) ($input['encryption'] ?? 'tls');

    if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
        return ['ok' => false, 'message' => 'Mode de chiffrement inconnu.'];
    }

    $fromEmail = trim((string) ($input['from_email'] ?? ''));

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'L\'adresse d\'expédition n\'est pas valide.'];
    }

    $replyTo = trim((string) ($input['reply_to'] ?? ''));

    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'L\'adresse de réponse n\'est pas valide.'];
    }

    $existing = mail_repo_settings();
    $password = (string) ($input['password'] ?? '');

    // UN CHAMP VIDE NE VEUT PAS DIRE « EFFACER ».
    // Le mot de passe n'étant jamais réaffiché, le formulaire revient
    // toujours vide. L'interpréter comme un effacement ferait perdre
    // la configuration à chaque correction de port.
    $cipher = $password !== ''
        ? crypto_encrypt($password)
        : null;

    $data = [
        'host'        => $host,
        'port'        => $port,
        'encryption'  => $encryption,
        'username'    => trim((string) ($input['username'] ?? '')) ?: null,
        'from_email'  => $fromEmail,
        'from_name'   => trim((string) ($input['from_name'] ?? '')) ?: null,
        'reply_to'    => $replyTo !== '' ? $replyTo : null,
        'updated_by'  => (int) auth_user()['id'],
    ];

    if ($cipher !== null) {
        $data['password_cipher'] = $cipher;
    }

    if ($existing === null) {
        // TOUTE NOUVELLE CONFIGURATION NAÎT INACTIVE.
        // Elle ne s'activera qu'après un essai réussi.
        $data['school_id'] = $schoolId;
        $data['is_active'] = 0;

        db_insert('email_settings', $data, true);

        audit_log('email.settings_created', 'email_settings', null, null, [
            'host' => $host, 'port' => $port, 'from' => $fromEmail,
        ]);

        $avertissement = mail_domain_warning($host, $fromEmail);

        return ['ok' => true, 'message' => 'Configuration enregistrée. '
            . 'Faites un envoi d\'essai pour l\'activer.'
            . ($avertissement !== null ? ' ⚠ ' . $avertissement : '')];
    }

    // TOUTE MODIFICATION DÉSACTIVE ET REDEMANDE UN ESSAI.
    // Changer d'hôte ou de mot de passe peut casser l'envoi ; laisser
    // la configuration active laisserait l'école croire que ses
    // messages partent encore.
    $data['is_active']   = 0;
    $data['verified_at'] = null;
    $data['last_error']  = null;

    $sets   = [];
    $params = ['school_id' => $schoolId];

    foreach ($data as $column => $value) {
        $sets[]          = $column . ' = :' . $column;
        $params[$column] = $value;
    }

    // L'école est DANS l'écriture, pas seulement dans le contrôle.
    db_query(
        'UPDATE email_settings SET ' . implode(', ', $sets)
        . ' WHERE school_id = :school_id',
        $params,
        true
    );

    audit_log('email.settings_updated', 'email_settings', (int) $existing['id'], [
        'host' => $existing['host'], 'port' => $existing['port'],
    ], [
        'host' => $host, 'port' => $port,
        'mot_de_passe_change' => $cipher !== null,
    ]);

    $avertissement = mail_domain_warning($host, $fromEmail);

    return ['ok' => true, 'message' => 'Configuration mise à jour. '
        . 'Un nouvel envoi d\'essai est nécessaire pour la réactiver.'
        . ($avertissement !== null ? ' ⚠ ' . $avertissement : '')];
}

/**
 * Envoie un message d'essai, et active la configuration s'il aboutit.
 *
 * C'EST LE SEUL CHEMIN QUI ACTIVE UNE CONFIGURATION. Une case à cocher
 * « actif » laisserait l'école se déclarer joignable sans l'être.
 *
 * @return array{ok: bool, message: string}
 */
function mail_service_send_test(string $to): array
{
    if (!can('email.manage')) {
        return ['ok' => false, 'message' => 'Réservé à l\'administration de l\'établissement.'];
    }

    $settings = mail_repo_settings();

    if ($settings === null) {
        return ['ok' => false, 'message' => 'Aucune configuration à éprouver.'];
    }

    $to = trim($to);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Indiquez une adresse de destination valide.'];
    }

    $schoolId = tenant_require();
    $school   = auth_user()['school_name'] ?? 'votre établissement';

    // L'ESSAI CONTOURNE `is_active`, PAR CONSTRUCTION.
    // La configuration est inactive tant qu'elle n'a pas servi ; si
    // l'essai passait par le chemin normal, il ne partirait jamais et
    // rien ne pourrait jamais s'activer.
    try {
        $server = mail_test_server($settings);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    $result = smtp_send($server, [
        'to'      => $to,
        'subject' => 'Essai de configuration — ' . $school,
        'text'    => "Ce message confirme que l'envoi d'e-mails fonctionne pour "
            . $school . ".\n\nServeur : " . $settings['host'] . ':' . $settings['port']
            . ' (' . $settings['encryption'] . ")\n"
            . "Expéditeur : " . $settings['from_email'] . "\n\n"
            . "Si vous recevez ce message, les réinitialisations de mot de passe "
            . "et les notifications partiront de cette adresse.\n",
        'html'    => mail_render_html(
            'Essai de configuration',
            '<p>Ce message confirme que l\'envoi d\'e-mails fonctionne pour <strong>'
            . e($school) . '</strong>.</p>'
            . '<p style="font-size:13px;color:#6b7280;">Serveur : '
            . e($settings['host'] . ':' . $settings['port'])
            . ' (' . e((string) $settings['encryption']) . ')<br>'
            . 'Expéditeur : ' . e((string) $settings['from_email']) . '</p>',
            'Si vous recevez ce message, les réinitialisations de mot de passe '
            . 'partiront de cette adresse.'
        ),
    ]);

    // On trace l'essai dans le journal, comme n'importe quel envoi :
    // « avons-nous déjà éprouvé cette configuration, et quand ? » est
    // une vraie question.
    db_insert('email_messages', [
        'uuid'         => str_uuid(),
        'school_id'    => $schoolId,
        'to_email'     => $to,
        'subject'      => 'Essai de configuration — ' . $school,
        'purpose'      => 'test',
        'is_sensitive' => 0,
        'status'       => $result['ok'] ? 'sent' : 'failed',
        'attempts'     => 1,
        'sent_at'      => $result['ok'] ? date('Y-m-d H:i:s') : null,
        'last_error'   => $result['ok'] ? null : mb_substr((string) $result['error'], 0, 500),
        'created_by'   => (int) auth_user()['id'],
    ], true);

    if (!$result['ok']) {
        db_query(
            'UPDATE email_settings SET is_active = 0, verified_at = NULL, last_error = :e
              WHERE school_id = :school_id',
            ['e' => mb_substr((string) $result['error'], 0, 500), 'school_id' => $schoolId],
            true
        );

        audit_log('email.test_failed', 'email_settings', (int) $settings['id'], null, [
            'to' => $to, 'error' => mb_substr((string) $result['error'], 0, 200),
        ]);

        return ['ok' => false, 'message' => 'L\'essai a échoué : ' . $result['error']];
    }

    db_query(
        'UPDATE email_settings SET is_active = 1, verified_at = NOW(), last_error = NULL
          WHERE school_id = :school_id',
        ['school_id' => $schoolId],
        true
    );

    audit_log('email.test_succeeded', 'email_settings', (int) $settings['id'], null, ['to' => $to]);

    $avertissement = mail_domain_warning(
        (string) $settings['host'],
        (string) $settings['from_email']
    );

    return ['ok' => true, 'message' => 'Message d\'essai remis au serveur, et envoi activé. '
        . 'Vérifiez la boîte de ' . $to . ' — y compris les indésirables.'
        . ($avertissement !== null ? ' ⚠ ' . $avertissement : '')];
}

/**
 * Le serveur à éprouver : la configuration enregistrée, mot de passe
 * déchiffré, QUE `is_active` soit posé ou non.
 */
function mail_test_server(array $settings): array
{
    $row = db_one(
        'SELECT password_cipher FROM email_settings WHERE school_id = :school_id LIMIT 1',
        ['school_id' => tenant_require()],
        true
    );

    $password = crypto_decrypt($row['password_cipher'] ?? null);

    if (($row['password_cipher'] ?? null) !== null && $password === null) {
        throw new RuntimeException(
            'Le mot de passe enregistré est illisible : la clé de chiffrement du '
            . 'serveur a changé depuis. Ressaisissez-le.'
        );
    }

    return [
        'host'       => (string) $settings['host'],
        'port'       => (int) $settings['port'],
        'encryption' => (string) $settings['encryption'],
        'username'   => (string) ($settings['username'] ?? ''),
        'password'   => (string) ($password ?? ''),
        'from_email' => (string) $settings['from_email'],
        'from_name'  => (string) ($settings['from_name'] ?? ''),
        'reply_to'   => $settings['reply_to'] !== null ? (string) $settings['reply_to'] : null,
    ];
}

/**
 * Remet un message en file après un échec.
 *
 * Ni duplication, ni renvoi d'un secret périmé : un message
 * `sensitive` a perdu son corps en échouant, il ne peut pas repartir.
 * L'écran propose alors la seule chose honnête — refaire la demande.
 *
 * @return array{ok: bool, message: string}
 */
function mail_service_retry(int $messageId): array
{
    if (!can('email.manage')) {
        return ['ok' => false, 'message' => 'Réservé à l\'administration de l\'établissement.'];
    }

    $schoolId = tenant_require();

    $row = db_one(
        'SELECT * FROM email_messages
          WHERE id = :id AND school_id = :school_id AND status = \'failed\'
          LIMIT 1',
        ['id' => $messageId, 'school_id' => $schoolId],
        true
    );

    if ($row === null) {
        return ['ok' => false, 'message' => 'Message introuvable, ou pas en échec.'];
    }

    if ((int) $row['is_sensitive'] === 1) {
        return [
            'ok'      => false,
            'message' => 'Ce message portait un lien à usage unique, effacé après l\'échec. '
                . 'Il ne peut pas être renvoyé tel quel : demandez une nouvelle '
                . 'réinitialisation depuis l\'écran de connexion.',
        ];
    }

    db_query(
        'UPDATE email_messages
            SET status = \'queued\', attempts = 0, last_error = NULL, next_attempt_at = NOW()
          WHERE id = :id AND school_id = :school_id',
        ['id' => $messageId, 'school_id' => $schoolId],
        true
    );

    $fresh = db_one(
        'SELECT * FROM email_messages WHERE id = :id AND school_id = :school_id LIMIT 1',
        ['id' => $messageId, 'school_id' => $schoolId],
        true
    );

    $out = mail_attempt($fresh);

    audit_log('email.retry', 'email_messages', $messageId, null, ['ok' => $out['ok']]);

    return $out['ok']
        ? ['ok' => true, 'message' => 'Message remis au serveur.']
        : ['ok' => true, 'message' => 'Message remis en file : ' . $out['error']];
}
