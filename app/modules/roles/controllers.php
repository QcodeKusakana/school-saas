<?php
/**
 * Module RÔLES — contrôleurs (phase 11B).
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';

/** La liste des rôles visibles par l'établissement. */
function ctrl_roles_index(): void
{
    view('roles/index', [
        'title'  => 'Rôles et permissions',
        'roles'  => roles_repo_all(),
        'niveau' => roles_repo_actor_level(),
    ], 'app');
}

/** Le formulaire de création. */
function ctrl_roles_create_form(): void
{
    view('roles/form', [
        'title'       => 'Nouveau rôle',
        'role'        => null,
        'accordees'   => [],
        'permissions' => roles_repo_grantable_permissions(),
        'miennes'     => perm_all(),
        'niveau'      => roles_repo_actor_level(),
    ], 'app');
}

function ctrl_roles_store(): void
{
    csrf_verify();

    $resultat = roles_service_create([
        'name'        => (string) input('name', ''),
        'description' => (string) input('description', ''),
        'level'       => input('level'),
        'permissions' => (array) input('permissions', []),
    ]);

    if (!$resultat['ok']) {
        flash_old(input_all());
        flash_error($resultat['message']);
        redirect('/roles/nouveau');
    }

    flash_success($resultat['message']);
    redirect('/roles/' . (int) $resultat['role_id']);
}

/** Le formulaire de modification. */
function ctrl_roles_edit_form(string $id): void
{
    $role = roles_repo_find((int) $id);

    if ($role === null) {
        flash_error('Ce rôle n\'existe pas, ou il appartient à un autre établissement.');
        redirect('/roles');
    }

    view('roles/form', [
        'title'       => 'Modifier ' . (string) $role['name'],
        'role'        => $role,
        'accordees'   => roles_repo_permission_ids((int) $id),
        'permissions' => roles_repo_grantable_permissions(),
        'miennes'     => perm_all(),
        'niveau'      => roles_repo_actor_level(),
        'porteurs'    => roles_repo_holders((int) $id),
        'refus'       => roles_refus_modification($role),
    ], 'app');
}

function ctrl_roles_update(string $id): void
{
    csrf_verify();

    $resultat = roles_service_update((int) $id, [
        'name'        => (string) input('name', ''),
        'description' => (string) input('description', ''),
        'level'       => input('level'),
        'permissions' => (array) input('permissions', []),
        'reason'      => (string) input('reason', ''),
    ]);

    if (!$resultat['ok']) {
        flash_old(input_all());
        flash_error($resultat['message']);
        redirect('/roles/' . (int) $id);
    }

    flash_success($resultat['message']);
    redirect('/roles');
}

/** Active ou désactive un rôle. */
function ctrl_roles_toggle(string $id): void
{
    csrf_verify();

    $resultat = roles_service_toggle(
        (int) $id,
        (string) input('actif', '') === 'oui',
        (string) input('reason', '')
    );

    $resultat['ok'] ? flash_success($resultat['message']) : flash_error($resultat['message']);

    redirect('/roles');
}
