<?php
/**
 * Module UTILISATEURS — contrôleurs.
 *
 * ATTENTION, RÈGLE DU ROUTEUR : les paramètres d'URL sont passés UN
 * PAR UN (`$route['handler'](...$params)`), jamais en tableau. Un
 * contrôleur déclarant `array $params` lève un TypeError invisible aux
 * tests, qui appellent les services. Défaut vécu en 7B1.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';

// ---------------------------------------------------------------------
//  LISTE
// ---------------------------------------------------------------------

function ctrl_users_index(): void
{
    $filters = [
        'q'        => trim((string) input('q', '')),
        'status'   => (string) input('statut', ''),
        'role'     => (string) input('role', ''),
        'archived' => (string) input('archives', ''),
    ];

    $result = users_repo_search($filters, max(1, (int) input_int('page', 1)));

    view('users/index', [
        'title'    => 'Utilisateurs',
        'users'    => $result['rows'],
        'total'    => $result['total'],
        'pages'    => $result['pages'],
        'page'     => $result['page'],
        'filters'  => $filters,
        'statuses' => USERS_STATUSES,
        'roles'    => users_repo_assignable_roles(),
        'counts'   => users_repo_counts(),
        'quota'    => subscription_can_add_staff_user(),
    ]);
}

// ---------------------------------------------------------------------
//  CRÉATION
// ---------------------------------------------------------------------

function ctrl_users_create_form(): void
{
    view('users/form', [
        'title'   => 'Nouveau compte',
        'user'    => null,
        'roles'   => users_repo_assignable_roles(),
        'held'    => [],
        'quota'   => subscription_can_add_staff_user(),
    ]);
}

function ctrl_users_store(): void
{
    $outcome = users_service_create([
        'last_name'  => (string) input('last_name', ''),
        'post_name'  => (string) input('post_name', ''),
        'first_name' => (string) input('first_name', ''),
        'email'      => (string) input('email', ''),
        'phone'      => (string) input('phone', ''),
        'gender'     => (string) input('gender', ''),
        'roles'      => (array) input('roles', []),
    ]);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        flash_old(input_all());
        redirect('/utilisateurs/nouveau');
    }

    // LE MOT DE PASSE S'AFFICHE UNE SEULE FOIS.
    //
    // Il n'est ni journalisé, ni stocké en clair, ni renvoyé par
    // e-mail — rien n'envoie d'e-mail dans le produit. L'administrateur
    // le remet en main propre, et le compte devra le changer à la
    // première connexion.
    flash_success($outcome['message']);
    flash_warning(
        'Identifiant : ' . $outcome['username'] . ' — Mot de passe provisoire : '
        . $outcome['password'] . '. Notez-le maintenant : il ne sera plus jamais '
        . 'affiché. Le compte devra le changer à sa première connexion.'
    );

    redirect('/utilisateurs/' . (int) $outcome['id']);
}

// ---------------------------------------------------------------------
//  FICHE
// ---------------------------------------------------------------------

function ctrl_users_show(string $id): void
{
    $user = users_repo_find((int) $id);

    if ($user === null) {
        abort(404);
    }

    view('users/show', [
        'title'     => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
        'user'      => $user,
        'roles'     => users_repo_assignable_roles(),
        'held'      => array_map(static fn (array $r): int => (int) $r['id'], $user['roles']),
        'statuses'  => USERS_STATUSES,
        'refusal'   => users_target_refusal($user),
        'isSelf'    => auth_user() !== null && (int) auth_user()['id'] === (int) $user['id'],
        'lastAdmin' => users_repo_count_with_permission('user.create', (int) $user['id']) === 0,
    ]);
}

function ctrl_users_update(string $id): void
{
    $outcome = users_service_update((int) $id, [
        'last_name'  => (string) input('last_name', ''),
        'post_name'  => (string) input('post_name', ''),
        'first_name' => (string) input('first_name', ''),
        'email'      => (string) input('email', ''),
        'phone'      => (string) input('phone', ''),
        'gender'     => (string) input('gender', ''),
    ]);

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/utilisateurs/' . (int) $id);
}

// ---------------------------------------------------------------------
//  RÔLES, ÉTAT, ARCHIVAGE
// ---------------------------------------------------------------------

function ctrl_users_set_roles(string $id): void
{
    $outcome = users_service_set_roles((int) $id, (array) input('roles', []));

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/utilisateurs/' . (int) $id);
}

function ctrl_users_set_status(string $id): void
{
    $outcome = users_service_set_status(
        (int) $id,
        (string) input('status', ''),
        (string) input('reason', '')
    );

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/utilisateurs/' . (int) $id);
}

function ctrl_users_archive(string $id): void
{
    $outcome = users_service_archive(
        (int) $id,
        (string) input('archive', '1') === '1',
        (string) input('reason', '')
    );

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/utilisateurs/' . (int) $id);
}

// ---------------------------------------------------------------------
//  MOT DE PASSE ET VERROU
// ---------------------------------------------------------------------

function ctrl_users_reset_password(string $id): void
{
    $outcome = users_service_reset_password((int) $id);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        redirect('/utilisateurs/' . (int) $id);
    }

    flash_success($outcome['message']);
    flash_warning(
        'Identifiant : ' . $outcome['username'] . ' — Nouveau mot de passe : '
        . $outcome['password'] . '. Notez-le maintenant : il ne sera plus jamais affiché.'
    );

    redirect('/utilisateurs/' . (int) $id);
}

function ctrl_users_unlock(string $id): void
{
    $outcome = users_service_unlock((int) $id);

    $outcome['ok'] ? flash_success($outcome['message']) : flash_error($outcome['message']);

    redirect('/utilisateurs/' . (int) $id);
}
