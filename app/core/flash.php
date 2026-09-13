<?php
/**
 * Messages éphémères et réaffichage des formulaires.
 *
 * Un message flash survit à une seule redirection puis disparaît.
 * Les anciennes saisies (old input) évitent à l'utilisateur de
 * ressaisir tout un formulaire après une erreur de validation.
 */

declare(strict_types=1);

/** Enregistre un message affiché après la prochaine redirection. */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][$type][] = $message;
}

function flash_success(string $message): void
{
    flash('success', $message);
}

function flash_error(string $message): void
{
    flash('error', $message);
}

function flash_warning(string $message): void
{
    flash('warning', $message);
}

function flash_info(string $message): void
{
    flash('info', $message);
}

/** Récupère et vide tous les messages flash. */
function flash_all(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return $messages;
}

/**
 * Enregistre les erreurs de validation et les anciennes saisies,
 * puis prépare le retour au formulaire.
 */
function flash_errors(array $errors, array $oldInput = []): void
{
    $_SESSION['_errors'] = $errors;
    flash_old($oldInput ?: input_all(['password', 'password_confirmation', 'current_password']));
}

/** Erreurs de validation de la requête précédente. */
function errors_all(): array
{
    static $errors = null;

    if ($errors === null) {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
    }

    return $errors;
}

/** Première erreur d'un champ, ou chaîne vide. */
function error_for(string $field): string
{
    $errors = errors_all();

    return isset($errors[$field][0]) ? (string) $errors[$field][0] : '';
}

/** Vrai si le champ porte une erreur (pour la classe CSS is-invalid). */
function has_error(string $field): bool
{
    return error_for($field) !== '';
}

/** Mémorise les valeurs saisies pour le prochain affichage. */
function flash_old(array $input): void
{
    // Aucun mot de passe ne doit transiter par la session.
    foreach (['password', 'password_confirmation', 'current_password', 'new_password'] as $key) {
        unset($input[$key]);
    }

    $_SESSION['_old'] = $input;
}

/** Ancienne valeur d'un champ, échappée pour un attribut HTML. */
function old(string $field, mixed $default = ''): string
{
    static $old = null;

    if ($old === null) {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
    }

    $value = $old[$field] ?? $default;

    return is_scalar($value) ? e($value) : '';
}
