<?php
/**
 * Module MAIL — contrôleurs.
 *
 * Rappel de la règle du routeur : les paramètres d'URL arrivent UN PAR
 * UN, jamais en tableau.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';

function ctrl_mail_settings_form(): void
{
    view('mail/settings', [
        'title'    => 'Envoi d\'e-mails',
        'settings' => mail_repo_settings(),
        'hasKey'   => crypto_available(),
        'counts'   => mail_repo_counts(),
    ]);
}

function ctrl_mail_settings_save(): void
{
    $outcome = mail_service_save_settings([
        'host'       => (string) input('host', ''),
        'port'       => (string) input('port', '587'),
        'encryption' => (string) input('encryption', 'tls'),
        'username'   => (string) input('username', ''),
        'password'   => (string) input('password', ''),
        'from_email' => (string) input('from_email', ''),
        'from_name'  => (string) input('from_name', ''),
        'reply_to'   => (string) input('reply_to', ''),
    ]);

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/ecole/emails');
}

function ctrl_mail_test(): void
{
    $outcome = mail_service_send_test((string) input('to', ''));

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/ecole/emails');
}

function ctrl_mail_journal(): void
{
    $filters = [
        'status'  => (string) input('etat', ''),
        'purpose' => (string) input('motif', ''),
        'q'       => trim((string) input('q', '')),
    ];

    $result = mail_repo_journal($filters, max(1, (int) input_int('page', 1)));

    view('mail/journal', [
        'title'    => 'Journal des envois',
        'messages' => $result['rows'],
        'total'    => $result['total'],
        'pages'    => $result['pages'],
        'page'     => $result['page'],
        'filters'  => $filters,
        'statuses' => MAIL_STATUSES,
        'purposes' => MAIL_PURPOSES,
        'counts'   => mail_repo_counts(),
    ]);
}

function ctrl_mail_retry(string $id): void
{
    $outcome = mail_service_retry((int) $id);

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/ecole/emails/journal');
}
