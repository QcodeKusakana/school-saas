<?php
/**
 * LA MESSAGERIE — file d'attente, envoi, rejeu.
 *
 * LE CONTRAT, EN UNE PHRASE
 * ==========================
 * `mail_queue()` ÉCRIT d'abord, TENTE ensuite. L'appelant n'attend
 * jamais un serveur distant pour rendre sa page, et rien ne se perd
 * quand la liaison tombe.
 *
 *   > Un envoi qui n'a pas été écrit avant d'être tenté est un envoi
 *   > qu'on ne saura pas rejouer.
 *
 * En RDC, la coupure est la règle et non l'exception : c'est la même
 * raison qui a imposé le mode hors connexion au reste du produit.
 *
 * CE QUE LA COUCHE GARANTIT
 * ==========================
 *   · un message est écrit AVANT toute tentative ;
 *   · un échec laisse la ligne en file, avec un délai croissant ;
 *   · au-delà de `max_attempts`, la ligne passe `failed` et le
 *     journal porte la dernière erreur — pas de disparition
 *     silencieuse ;
 *   · le corps d'un message SENSIBLE est chiffré au repos et effacé
 *     dès l'envoi réussi ;
 *   · le mot de passe du serveur n'apparaît nulle part dans les
 *     journaux.
 *
 * CE QU'ELLE NE GARANTIT PAS, ET LE DIT
 * ======================================
 * Qu'un message parti soit LU, ni même accepté par le serveur du
 * destinataire. « Envoyé » veut dire « remis au serveur d'expédition
 * sans erreur », rien de plus. L'écran emploie ce mot-là.
 */

declare(strict_types=1);

/** Délais de rejeu, en minutes, par numéro de tentative. */
const MAIL_BACKOFF_MINUTES = [1, 5, 15, 60, 240];

/** Nombre de messages traités par passage du travail périodique. */
const MAIL_WORKER_BATCH = 25;

// ---------------------------------------------------------------------
//  LA CONFIGURATION D'UNE ÉCOLE
// ---------------------------------------------------------------------

/**
 * La configuration d'envoi d'une école, mot de passe DÉCHIFFRÉ.
 *
 * Réservée à l'envoi : aucun écran ne doit appeler cette fonction,
 * `mail_repo_settings()` rend la même chose sans le secret.
 *
 * @return array{host: string, port: int, encryption: string, username: string,
 *               password: string, from_email: string, from_name: string,
 *               reply_to: ?string}|null
 */
function mail_server_for(int $schoolId): ?array
{
    $row = db_one(
        'SELECT * FROM email_settings WHERE school_id = :school_id AND is_active = 1 LIMIT 1',
        ['school_id' => $schoolId],
        true
    );

    if ($row === null) {
        return null;
    }

    $password = crypto_decrypt($row['password_cipher'] ?? null);

    if ($row['password_cipher'] !== null && $password === null) {
        // LA CLÉ A CHANGÉ, OU LA DONNÉE EST ABÎMÉE.
        // On ne tente pas une connexion sans mot de passe « au cas
        // où » : elle échouerait avec une erreur d'authentification
        // incompréhensible. On dit la vraie cause.
        throw new RuntimeException(
            'Le mot de passe SMTP de cet établissement est illisible : la clé de '
            . 'chiffrement a changé, ou la donnée est corrompue. Ressaisissez-le.'
        );
    }

    return [
        'host'       => (string) $row['host'],
        'port'       => (int) $row['port'],
        'encryption' => (string) $row['encryption'],
        'username'   => (string) ($row['username'] ?? ''),
        'password'   => (string) ($password ?? ''),
        'from_email' => (string) $row['from_email'],
        'from_name'  => (string) ($row['from_name'] ?? ''),
        'reply_to'   => $row['reply_to'] !== null ? (string) $row['reply_to'] : null,
    ];
}

/**
 * La configuration de la PLATEFORME, lue dans `config.local.php`.
 *
 * Elle ne vit pas en base, et c'est délibéré : elle appartient à
 * l'éditeur, pas à un client, et elle doit fonctionner AVANT qu'une
 * seule école existe — notamment pour la connexion du premier compte.
 */
function mail_platform_server(): ?array
{
    $conf = (array) config('mail', []);

    if (trim((string) ($conf['host'] ?? '')) === ''
        || trim((string) ($conf['from_email'] ?? '')) === '') {
        return null;
    }

    return [
        'host'       => (string) $conf['host'],
        'port'       => (int) ($conf['port'] ?? 587),
        'encryption' => (string) ($conf['encryption'] ?? 'tls'),
        'username'   => (string) ($conf['username'] ?? ''),
        'password'   => (string) ($conf['password'] ?? ''),
        'from_email' => (string) $conf['from_email'],
        'from_name'  => (string) ($conf['from_name'] ?? 'School SaaS'),
        'reply_to'   => ($conf['reply_to'] ?? null) !== null ? (string) $conf['reply_to'] : null,
    ];
}

/**
 * Quel serveur pour ce message ?
 *
 * Celui de l'école si elle en a un d'actif ; sinon celui de la
 * plateforme, en dernier recours. Une école sans configuration n'est
 * donc pas muette — mais ses messages partiront d'une adresse qui
 * n'est pas la sienne, et l'écran le dit.
 */
function mail_server_for_message(?int $schoolId): ?array
{
    if ($schoolId !== null) {
        $own = mail_server_for($schoolId);

        if ($own !== null) {
            return $own;
        }
    }

    return mail_platform_server();
}

// ---------------------------------------------------------------------
//  LA FILE
// ---------------------------------------------------------------------

/**
 * Écrit un message dans la file, puis tente immédiatement de l'envoyer.
 *
 * @param array{to: string, to_name?: ?string, subject: string, text: string,
 *              html?: ?string, purpose: string, school_id?: ?int,
 *              sensitive?: bool, related_type?: ?string, related_id?: ?int} $message
 * @return array{ok: bool, queued: bool, sent: bool, id: ?int, error: ?string}
 */
function mail_queue(array $message): array
{
    $to = trim((string) ($message['to'] ?? ''));

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        // PAS D'ADRESSE, PAS DE MESSAGE — et on le DIT.
        // Beaucoup d'agents et de familles n'ont pas d'e-mail : c'est
        // un cas normal du produit, pas une erreur. L'appelant doit
        // pouvoir proposer autre chose (remise en main propre).
        return ['ok' => false, 'queued' => false, 'sent' => false, 'id' => null,
                'error' => 'Aucune adresse e-mail valide pour ce destinataire.'];
    }

    $schoolId  = $message['school_id'] ?? tenant_id();
    $sensitive = (bool) ($message['sensitive'] ?? false);
    $actor     = auth_user();

    $text = (string) ($message['text'] ?? '');
    $html = $message['html'] ?? null;

    // LE CORPS SENSIBLE EST CHIFFRÉ AU REPOS.
    // Un lien de réinitialisation EST le secret que `password_resets`
    // protège en n'en stockant que le haché. Le recopier en clair ici
    // annulerait cette protection pendant toute l'attente en file.
    $storedText = $sensitive ? crypto_encrypt($text) : $text;
    $storedHtml = ($sensitive && $html !== null && $html !== '')
        ? crypto_encrypt((string) $html)
        : $html;

    $id = db_insert('email_messages', [
        'uuid'            => str_uuid(),
        'school_id'       => $schoolId,
        'to_email'        => $to,
        'to_name'         => $message['to_name'] ?? null,
        'subject'         => mb_substr((string) $message['subject'], 0, 255),
        'body_text'       => $storedText,
        'body_html'       => $storedHtml,
        'is_sensitive'    => $sensitive ? 1 : 0,
        'purpose'         => (string) $message['purpose'],
        'related_type'    => $message['related_type'] ?? null,
        'related_id'      => $message['related_id'] ?? null,
        'status'          => 'queued',
        'next_attempt_at' => date('Y-m-d H:i:s'),
        'created_by'      => $actor !== null ? (int) $actor['id'] : null,
    ], true);

    // TENTATIVE IMMÉDIATE — mais l'écriture est déjà faite.
    // Si elle échoue, la ligne reste en file et le travail périodique
    // la reprendra. L'appelant n'a rien à gérer.
    $row  = mail_repo_find($id, $schoolId !== null ? (int) $schoolId : null);
    $sent = $row !== null
        ? mail_attempt($row)
        : ['ok' => false, 'error' => 'Message introuvable après écriture.'];

    return [
        'ok'     => true,
        'queued' => true,
        'sent'   => $sent['ok'],
        'id'     => $id,
        'error'  => $sent['ok'] ? null : $sent['error'],
    ];
}

/**
 * Lit UN message de la file, DANS SON PÉRIMÈTRE.
 *
 * LE FILTRE D'ÉCOLE EST DANS LA REQUÊTE, PAS AILLEURS.
 * La première version lisait `WHERE id = :id` et rien d'autre : le
 * garde-fou l'a refusée, à juste titre. Un identifiant deviné aurait
 * suffi à lire le corps d'un message d'une autre école — et ces corps
 * portent des liens de réinitialisation.
 *
 * `<=>` est l'égalité SÛRE-AVEC-NULL de MySQL : elle rend vrai quand
 * les deux côtés sont NULL, ce qu'un `=` ne fait jamais. C'est ce qui
 * permet de lire un message de la PLATEFORME (`school_id IS NULL`)
 * avec la même requête.
 */
function mail_repo_find(int $messageId, ?int $schoolId): ?array
{
    return db_one(
        'SELECT * FROM email_messages
          WHERE id = :id AND school_id <=> :school_id
          LIMIT 1',
        ['id' => $messageId, 'school_id' => $schoolId],
        true
    );
}

/**
 * Tente l'envoi d'UN message, et met la ligne à jour.
 *
 * ELLE PREND LA LIGNE, PAS UN IDENTIFIANT. L'appelant est ainsi
 * responsable de l'avoir lue dans un périmètre légitime : la file
 * traverse les écoles par nature, et ce n'est pas à la fonction
 * d'envoi de décider qui a le droit de la lire.
 *
 *   > Un `if` n'est pas un périmètre — et un identifiant nu non plus.
 *
 * @return array{ok: bool, error: ?string}
 */
function mail_attempt(array $row): array
{
    if ((string) $row['status'] !== 'queued') {
        return ['ok' => false, 'error' => 'Ce message n\'est plus en attente.'];
    }

    $schoolId = $row['school_id'] !== null ? (int) $row['school_id'] : null;

    try {
        $server = mail_server_for_message($schoolId);
    } catch (Throwable $e) {
        return mail_record_failure($row, $e->getMessage());
    }

    if ($server === null) {
        return mail_record_failure(
            $row,
            'Aucun serveur d\'envoi configuré pour cet établissement, '
            . 'ni pour la plateforme.'
        );
    }

    $text = (int) $row['is_sensitive'] === 1
        ? crypto_decrypt($row['body_text'])
        : (string) $row['body_text'];

    $html = (int) $row['is_sensitive'] === 1
        ? crypto_decrypt($row['body_html'])
        : $row['body_html'];

    if ($text === null) {
        return mail_record_failure(
            $row,
            'Le corps du message est illisible : la clé de chiffrement a changé.'
        );
    }

    $result = smtp_send($server, [
        'to'      => (string) $row['to_email'],
        'to_name' => (string) ($row['to_name'] ?? ''),
        'subject' => (string) $row['subject'],
        'text'    => $text,
        'html'    => $html,
    ]);

    if (!$result['ok']) {
        return mail_record_failure($row, (string) $result['error']);
    }

    // ENVOYÉ : le corps sensible disparaît maintenant.
    // Le garder « pour l'historique » reviendrait à conserver un
    // secret dont plus personne n'a besoin.
    db_query(
        'UPDATE email_messages
            SET status = \'sent\',
                sent_at = NOW(),
                attempts = attempts + 1,
                last_error = NULL,
                next_attempt_at = NULL,
                body_text = ' . ((int) $row['is_sensitive'] === 1 ? 'NULL' : 'body_text') . ',
                body_html = ' . ((int) $row['is_sensitive'] === 1 ? 'NULL' : 'body_html') . '
          WHERE id = :id AND school_id <=> :school_id',
        ['id' => (int) $row['id'], 'school_id' => $schoolId],
        true
    );

    log_info('E-mail envoyé', [
        'id'      => (int) $row['id'],
        'purpose' => (string) $row['purpose'],
        'school'  => $schoolId,
    ]);

    return ['ok' => true, 'error' => null];
}

/**
 * Consigne un échec : compteur, délai de rejeu, et abandon au-delà du
 * nombre de tentatives permis.
 *
 * @return array{ok: bool, error: ?string}
 */
function mail_record_failure(array $row, string $error): array
{
    $attempts = (int) $row['attempts'] + 1;
    $max      = (int) $row['max_attempts'];

    // L'erreur est tronquée pour tenir en colonne, mais jamais vidée :
    // un échec sans motif ne se diagnostique pas à distance.
    $short = mb_substr($error, 0, 500);

    if ($attempts >= $max) {
        // ABANDONNÉ : le corps sensible disparaît aussi.
        //
        // Au bout de 1 + 5 + 15 + 60 minutes de rejeux, un lien de
        // réinitialisation valable une heure est de toute façon
        // périmé. Conserver un secret que plus rien ne peut servir,
        // c'est garder un risque sans contrepartie.
        db_query(
            'UPDATE email_messages
                SET status = \'failed\', attempts = :n, last_error = :e, next_attempt_at = NULL,
                    body_text = ' . ((int) $row['is_sensitive'] === 1 ? 'NULL' : 'body_text') . ',
                    body_html = ' . ((int) $row['is_sensitive'] === 1 ? 'NULL' : 'body_html') . '
              WHERE id = :id AND school_id <=> :school_id',
            [
                'n'         => $attempts,
                'e'         => $short,
                'id'        => (int) $row['id'],
                'school_id' => $row['school_id'] !== null ? (int) $row['school_id'] : null,
            ],
            true
        );

        log_error('E-mail abandonné après ' . $attempts . ' tentatives', [
            'id'    => (int) $row['id'],
            'error' => $short,
        ]);

        return ['ok' => false, 'error' => $error];
    }

    $delay = MAIL_BACKOFF_MINUTES[min($attempts - 1, count(MAIL_BACKOFF_MINUTES) - 1)];

    db_query(
        'UPDATE email_messages
            SET attempts = :n, last_error = :e,
                next_attempt_at = DATE_ADD(NOW(), INTERVAL :d MINUTE)
          WHERE id = :id AND school_id <=> :school_id',
        [
            'n'         => $attempts,
            'e'         => $short,
            'd'         => $delay,
            'id'        => (int) $row['id'],
            'school_id' => $row['school_id'] !== null ? (int) $row['school_id'] : null,
        ],
        true
    );

    log_warning('E-mail en échec, rejeu dans ' . $delay . ' min', [
        'id'    => (int) $row['id'],
        'error' => $short,
    ]);

    return ['ok' => false, 'error' => $error];
}

/**
 * Le travail périodique : rejoue les messages dus, toutes écoles
 * confondues.
 *
 * TRANSVERSAL PAR NATURE — il passe donc par `platform_scope_cli()`,
 * qui exige la ligne de commande. Une requête web ne peut pas
 * l'appeler, et c'est voulu.
 *
 * @return array{traites: int, envoyes: int, echoues: int}
 */
function mail_worker_run(int $batch = MAIL_WORKER_BATCH): array
{
    return platform_scope_cli(static function () use ($batch): array {
        // LA LECTURE TRANSVERSALE EST ICI, ET ELLE EST DÉCLARÉE.
        // `platform_scope_cli()` l'autorise et exige la ligne de
        // commande : une requête web ne peut pas l'emprunter.
        $due = db_all(
            'SELECT * FROM email_messages
              WHERE status = \'queued\'
                AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
              ORDER BY next_attempt_at, id
              LIMIT ' . max(1, min(200, $batch)),
            [],
            true
        );

        $sent = 0;
        $failed = 0;

        foreach ($due as $row) {
            $out = mail_attempt($row);
            $out['ok'] ? $sent++ : $failed++;
        }

        return ['traites' => count($due), 'envoyes' => $sent, 'echoues' => $failed];
    });
}

// ---------------------------------------------------------------------
//  LA MISE EN FORME
// ---------------------------------------------------------------------

/**
 * Enveloppe HTML d'un message.
 *
 * TABLEAUX ET STYLES EN LIGNE, volontairement : les clients de
 * messagerie ignorent les feuilles de style externes et une bonne
 * partie du CSS moderne. Ce qui serait une faute dans une page du
 * produit est ici la seule chose qui fonctionne partout.
 *
 * Aucune image, aucune police distante : un message doit rester
 * lisible sur un téléphone en 2G, et ne pas signaler son ouverture.
 */
function mail_render_html(string $title, string $bodyHtml, string $footer = ''): string
{
    $title  = e($title);
    $footer = $footer !== '' ? e($footer) : '';

    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $title . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f5f7;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="background:#f4f5f7;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="max-width:560px;background:#ffffff;border-radius:8px;'
        . 'font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
        . '<tr><td style="padding:24px 24px 8px;">'
        . '<h1 style="margin:0;font-size:18px;line-height:1.4;color:#111827;">' . $title . '</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:0 24px 24px;font-size:15px;line-height:1.6;">'
        . $bodyHtml
        . '</td></tr>'
        . ($footer !== ''
            ? '<tr><td style="padding:16px 24px;border-top:1px solid #e5e7eb;'
              . 'font-size:12px;color:#6b7280;">' . $footer . '</td></tr>'
            : '')
        . '</table></td></tr></table></body></html>';
}

/** Un bouton lisible, y compris là où le CSS ne passe pas. */
function mail_button(string $label, string $url): string
{
    return '<p style="margin:24px 0;"><a href="' . e($url) . '" '
        . 'style="display:inline-block;padding:12px 20px;background:#1d4ed8;color:#ffffff;'
        . 'text-decoration:none;border-radius:6px;font-weight:bold;">' . e($label) . '</a></p>'
        . '<p style="margin:0;font-size:13px;color:#6b7280;word-break:break-all;">'
        . 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>'
        . e($url) . '</p>';
}
