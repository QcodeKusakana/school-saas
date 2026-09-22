<?php
/**
 * Module MAIL — lectures.
 *
 * AUCUNE FONCTION D'ICI NE REND LE MOT DE PASSE.
 * Le déchiffrement vit dans `mail_server_for()`, au noyau, appelé
 * uniquement au moment de l'envoi. Une lecture d'écran qui ramènerait
 * le secret finirait tôt ou tard dans une vue, un export ou un
 * journal — la seule protection qui tienne est de ne pas le sortir.
 */

declare(strict_types=1);

/** Les états d'un message, dans l'ordre où ils se lisent. */
const MAIL_STATUSES = [
    'queued'    => 'En attente',
    'sent'      => 'Envoyé',
    'failed'    => 'Échec',
    'cancelled' => 'Annulé',
];

/** Les motifs d'envoi connus du produit, pour filtrer le journal. */
const MAIL_PURPOSES = [
    'password_reset' => 'Réinitialisation de mot de passe',
    'test'           => 'Essai de configuration',
];

/**
 * La configuration d'envoi de l'école courante — SANS le secret.
 *
 * `password_set` dit seulement s'il y en a un. C'est tout ce dont un
 * écran a besoin : afficher « ●●●●●● » ou « aucun mot de passe ».
 */
function mail_repo_settings(): ?array
{
    $row = db_one(
        'SELECT id, school_id, host, port, encryption, username,
                from_email, from_name, reply_to, is_active,
                verified_at, last_error, updated_at,
                (password_cipher IS NOT NULL) AS password_set
           FROM email_settings
          WHERE school_id = :school_id
          LIMIT 1',
        ['school_id' => tenant_require()],
        true
    );

    return $row;
}

/**
 * Le journal des envois de l'école courante.
 *
 * LE CORPS N'EST JAMAIS RAMENÉ. Il est chiffré quand il est sensible,
 * effacé une fois parti — mais le principe vaut même pour les autres :
 * un journal sert à savoir QUI a reçu QUOI et QUAND, pas à relire le
 * contenu.
 *
 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int}
 */
function mail_repo_journal(array $filters, int $page = 1, int $perPage = 25): array
{
    $where  = ['m.school_id = :school_id'];
    $params = ['school_id' => tenant_require()];

    if (!empty($filters['status']) && isset(MAIL_STATUSES[$filters['status']])) {
        $where[] = 'm.status = :status';
        $params['status'] = (string) $filters['status'];
    }

    if (!empty($filters['purpose'])) {
        $where[] = 'm.purpose = :purpose';
        $params['purpose'] = (string) $filters['purpose'];
    }

    if (!empty($filters['q'])) {
        $where[] = 'm.to_email LIKE :q';
        $params['q'] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $filters['q'])) . '%';
    }

    $from = 'FROM email_messages m WHERE ' . implode(' AND ', $where);

    $total  = (int) db_value('SELECT COUNT(*) ' . $from, $params, true);
    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = min(max(1, $page), $pages);
    $offset = ($page - 1) * $perPage;

    $rows = db_all(
        'SELECT m.id, m.to_email, m.to_name, m.subject, m.purpose, m.status,
                m.attempts, m.max_attempts, m.next_attempt_at, m.sent_at,
                m.last_error, m.created_at, m.is_sensitive
         ' . $from . '
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params,
        true
    );

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/** Le décompte par état, pour la tête de l'écran. */
function mail_repo_counts(): array
{
    $rows = db_all(
        'SELECT status, COUNT(*) AS n
           FROM email_messages
          WHERE school_id = :school_id
          GROUP BY status',
        ['school_id' => tenant_require()],
        true
    );

    $out = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];

    foreach ($rows as $r) {
        $out[(string) $r['status']] = (int) $r['n'];
    }

    return $out;
}
