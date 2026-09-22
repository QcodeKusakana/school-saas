<?php
/**
 * CLIENT SMTP — minimal, procédural, sans aucune dépendance.
 *
 * POURQUOI PAS UNE BIBLIOTHÈQUE
 * ==============================
 * L'hébergement cible est du cPanel mutualisé : pas de Composer, pas
 * de `vendor/`. Déposer PHPMailer à la main, c'est s'engager à suivre
 * ses mises à jour de sécurité à la main — sur un composant qui
 * manipule des identifiants. Le sous-ensemble du protocole dont le
 * produit a besoin tient en deux cents lignes lisibles.
 *
 * POURQUOI PAS `mail()`
 * ======================
 * `mail()` passe par le sendmail local : il ne S'AUTHENTIFIE PAS. Sur
 * un mutualisé, les messages partent alors d'une IP partagée sans
 * SPF ni DKIM alignés, et finissent en indésirables — quand la
 * fonction n'est pas purement et simplement désactivée par
 * l'hébergeur. Un produit qui « envoie » des messages que personne ne
 * reçoit est pire qu'un produit qui n'en envoie pas : l'école croit
 * avoir prévenu les familles.
 *
 * CE QUI EST COUVERT
 * ==================
 *   EHLO · STARTTLS · AUTH LOGIN et PLAIN · MAIL FROM · RCPT TO ·
 *   DATA · QUIT, en texte seul ou multipart/alternative.
 *
 * CE QUI NE L'EST PAS, ET C'EST ASSUMÉ
 * =====================================
 *   pièces jointes, AUTH CRAM-MD5 / XOAUTH2, envoi groupé sur une
 *   même connexion, pipelining. À ajouter le jour où un besoin réel
 *   se présente, pas avant.
 *
 * SÉCURITÉ
 * ========
 *   · le mot de passe n'apparaît JAMAIS dans le dialogue journalisé :
 *     la ligne d'authentification est remplacée par des astérisques ;
 *   · en `tls` comme en `ssl`, la vérification du certificat et du nom
 *     d'hôte est ACTIVE. On ne la désactive pas pour faire passer un
 *     serveur mal configuré — ce serait ouvrir la porte à l'écoute.
 *   · les en-têtes sont nettoyés de tout retour à la ligne :
 *     un sujet contenant CRLF permettrait d'injecter un Bcc.
 */

declare(strict_types=1);

/** Délais, en secondes. Un mutualisé lent ne doit pas bloquer une page. */
const SMTP_CONNECT_TIMEOUT = 10;
const SMTP_READ_TIMEOUT    = 15;

/**
 * Envoie un message.
 *
 * @param array{host: string, port: int, encryption: string, username: string,
 *              password: string, from_email: string, from_name?: string,
 *              reply_to?: ?string} $server
 * @param array{to: string, to_name?: string, subject: string,
 *              text: string, html?: ?string} $message
 * @return array{ok: bool, error: ?string, dialogue: array<int, string>}
 */
function smtp_send(array $server, array $message): array
{
    $dialogue = [];
    $socket   = null;

    try {
        $host       = (string) $server['host'];
        $port       = (int) $server['port'];
        $encryption = (string) ($server['encryption'] ?? 'tls');

        if ($host === '' || $port <= 0) {
            return ['ok' => false, 'error' => 'Serveur SMTP non configuré.', 'dialogue' => []];
        }

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';

        $context = stream_context_create([
            'ssl' => [
                // ON NE DÉSACTIVE PAS LA VÉRIFICATION.
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
            ],
        ]);

        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            SMTP_CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            return [
                'ok'       => false,
                'error'    => sprintf('Connexion impossible à %s:%d — %s', $host, $port, $errstr ?: 'erreur inconnue'),
                'dialogue' => [],
            ];
        }

        stream_set_timeout($socket, SMTP_READ_TIMEOUT);

        smtp_expect($socket, $dialogue, [220]);

        $ehloName = smtp_ehlo_name((string) ($server['from_email'] ?? ''));

        $capabilities = smtp_ehlo($socket, $dialogue, $ehloName);

        // STARTTLS : on élève la connexion AVANT de s'authentifier.
        if ($encryption === 'tls') {
            if (!str_contains(strtoupper($capabilities), 'STARTTLS')) {
                throw new RuntimeException(
                    'Le serveur n\'annonce pas STARTTLS. Choisissez « aucun chiffrement » '
                    . 'en connaissance de cause, ou « SSL » sur le port dédié.'
                );
            }

            smtp_command($socket, $dialogue, 'STARTTLS', [220]);

            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException(
                    'La liaison chiffrée a échoué : certificat refusé, ou nom d\'hôte '
                    . 'qui ne correspond pas au certificat du serveur.'
                );
            }

            // Après STARTTLS, on se re-présente : les capacités changent.
            $capabilities = smtp_ehlo($socket, $dialogue, $ehloName);
        }

        $username = (string) ($server['username'] ?? '');
        $password = (string) ($server['password'] ?? '');

        if ($username !== '') {
            smtp_authenticate($socket, $dialogue, $capabilities, $username, $password);
        }

        $fromEmail = smtp_clean_header((string) $server['from_email']);
        $toEmail   = smtp_clean_header((string) $message['to']);

        smtp_command($socket, $dialogue, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_command($socket, $dialogue, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtp_command($socket, $dialogue, 'DATA', [354]);

        $payload = smtp_build_message($server, $message);

        // Point-stuffing : une ligne réduite à « . » terminerait le
        // message au milieu. Le protocole impose de la doubler.
        $payload = preg_replace('/^\./m', '..', $payload);

        smtp_write($socket, $payload . "\r\n.\r\n");
        smtp_expect($socket, $dialogue, [250]);

        smtp_command($socket, $dialogue, 'QUIT', [221]);

        return ['ok' => true, 'error' => null, 'dialogue' => $dialogue];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'dialogue' => $dialogue];
    } finally {
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }
}

/**
 * Le nom annoncé dans EHLO.
 *
 * Certains serveurs refusent un EHLO qui n'est pas un nom de domaine.
 * On prend celui de l'adresse d'expédition, qui est par construction
 * un domaine réel.
 */
function smtp_ehlo_name(string $fromEmail): string
{
    $at = strrpos($fromEmail, '@');

    if ($at === false) {
        return 'localhost';
    }

    $domain = substr($fromEmail, $at + 1);

    return preg_match('/^[A-Za-z0-9.\-]+$/', $domain) === 1 ? $domain : 'localhost';
}

/** EHLO, avec repli sur HELO pour les serveurs anciens. */
function smtp_ehlo($socket, array &$dialogue, string $name): string
{
    try {
        return smtp_command($socket, $dialogue, 'EHLO ' . $name, [250]);
    } catch (Throwable) {
        return smtp_command($socket, $dialogue, 'HELO ' . $name, [250]);
    }
}

/**
 * AUTH — LOGIN si annoncé, sinon PLAIN.
 *
 * Le mot de passe est encodé en base64 par le protocole, ce qui n'est
 * PAS un chiffrement : c'est STARTTLS qui protège, et lui seul.
 */
function smtp_authenticate($socket, array &$dialogue, string $capabilities, string $username, string $password): void
{
    $upper = strtoupper($capabilities);

    if (str_contains($upper, 'AUTH') && str_contains($upper, 'LOGIN')) {
        smtp_command($socket, $dialogue, 'AUTH LOGIN', [334]);
        smtp_command($socket, $dialogue, base64_encode($username), [334], '***identifiant***');
        smtp_command($socket, $dialogue, base64_encode($password), [235], '***mot de passe***');

        return;
    }

    if (str_contains($upper, 'AUTH') && str_contains($upper, 'PLAIN')) {
        smtp_command(
            $socket,
            $dialogue,
            'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password),
            [235],
            'AUTH PLAIN ***'
        );

        return;
    }

    throw new RuntimeException(
        'Le serveur n\'annonce aucune méthode d\'authentification prise en charge '
        . '(LOGIN ou PLAIN). Vérifiez le port et le mode de chiffrement.'
    );
}

/**
 * Envoie une commande et vérifie le code de réponse.
 *
 * `$display` remplace la commande dans le dialogue journalisé : c'est
 * ce qui empêche un mot de passe d'atterrir dans les journaux.
 */
function smtp_command($socket, array &$dialogue, string $command, array $expected, ?string $display = null): string
{
    $dialogue[] = '> ' . ($display ?? $command);

    smtp_write($socket, $command . "\r\n");

    return smtp_expect($socket, $dialogue, $expected);
}

/** Écrit, ou lève : un `fwrite` partiel doit se voir. */
function smtp_write($socket, string $data): void
{
    $written = @fwrite($socket, $data);

    if ($written === false || $written < strlen($data)) {
        throw new RuntimeException('Écriture interrompue vers le serveur SMTP.');
    }
}

/**
 * Lit une réponse complète — les réponses multilignes portent un tiret
 * après le code, la dernière une espace — et vérifie le code attendu.
 */
function smtp_expect($socket, array &$dialogue, array $expected): string
{
    $response = '';

    while (true) {
        $line = @fgets($socket, 1024);

        if ($line === false) {
            $meta = stream_get_meta_data($socket);

            throw new RuntimeException(
                ($meta['timed_out'] ?? false)
                    ? 'Le serveur SMTP n\'a pas répondu dans le délai imparti.'
                    : 'Le serveur SMTP a fermé la connexion.'
            );
        }

        $response .= $line;
        $dialogue[] = '< ' . rtrim($line);

        // « 250-CAPACITE » : il y a une suite. « 250 OK » : c'est fini.
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    $code = (int) substr(ltrim($response), 0, 3);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(sprintf(
            'Le serveur a répondu %d là où %s était attendu : %s',
            $code,
            implode(' ou ', $expected),
            trim($response)
        ));
    }

    return $response;
}

/**
 * Nettoie une valeur destinée à un en-tête.
 *
 * UN SUJET CONTENANT UN RETOUR À LA LIGNE PERMETTRAIT D'INJECTER UN
 * EN-TÊTE — un `Bcc:` vers un tiers, par exemple. Le produit compose
 * lui-même ses sujets aujourd'hui, mais un futur écran de messagerie
 * les prendra de l'utilisateur, et ce nettoyage doit déjà être là.
 */
function smtp_clean_header(string $value): string
{
    return trim(str_replace(["\r", "\n", "\0"], '', $value));
}

/** Encode un en-tête non-ASCII (RFC 2047) — les accents sont la règle ici. */
function smtp_encode_header(string $value): string
{
    $value = smtp_clean_header($value);

    if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
        return $value;
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/** Compose le message complet : en-têtes + corps. */
function smtp_build_message(array $server, array $message): string
{
    $fromEmail = smtp_clean_header((string) $server['from_email']);
    $fromName  = smtp_encode_header((string) ($server['from_name'] ?? ''));
    $toEmail   = smtp_clean_header((string) $message['to']);
    $toName    = smtp_encode_header((string) ($message['to_name'] ?? ''));
    $replyTo   = smtp_clean_header((string) ($server['reply_to'] ?? ''));

    $html = $message['html'] ?? null;

    $headers = [
        'Date: ' . date('r'),
        'From: ' . ($fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail),
        'To: ' . ($toName !== '' ? $toName . ' <' . $toEmail . '>' : $toEmail),
        'Subject: ' . smtp_encode_header((string) $message['subject']),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . smtp_ehlo_name($fromEmail) . '>',
        'MIME-Version: 1.0',
        // Un message transactionnel ne doit pas déclencher de réponse
        // automatique d'absence, ni être trié comme une infolettre.
        'Auto-Submitted: auto-generated',
        'X-Auto-Response-Suppress: All',
    ];

    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $text = (string) $message['text'];

    if ($html === null || trim((string) $html) === '') {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';

        return implode("\r\n", $headers) . "\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n");
    }

    $boundary = 'b' . bin2hex(random_bytes(16));

    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    // LE TEXTE BRUT D'ABORD : dans multipart/alternative, le dernier
    // bloc est celui que le client préfère. Un lecteur sans HTML — ou
    // un téléphone en mode économie de données — garde donc une
    // version lisible.
    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text), 76, "\r\n")
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode((string) $html), 76, "\r\n")
        . '--' . $boundary . "--\r\n";

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}
