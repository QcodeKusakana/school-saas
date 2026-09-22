<?php
/**
 * Module ÉTABLISSEMENT — lectures.
 */

declare(strict_types=1);

/** Le chemin relatif du logo, ou null. */
function school_repo_logo_path(): ?string
{
    $valeur = db_value(
        'SELECT logo_path FROM schools WHERE id = :id',
        ['id' => tenant_require()],
        true
    );

    return $valeur !== null && (string) $valeur !== '' ? (string) $valeur : null;
}

/**
 * Tout ce qui s'imprime sur un document, en une lecture.
 *
 * Utilisé par l'écran de réglages ET par le figeage des documents :
 * une seule source, donc pas de divergence entre ce que l'école
 * paramètre et ce qui sort de l'imprimante.
 *
 * @return array<string, string>
 */
function school_repo_print_settings(): array
{
    $out = [];

    foreach (array_keys(SCHOOL_PRINT_SETTINGS) as $cle) {
        $out[$cle] = (string) school_setting($cle, '');
    }

    return $out;
}

/** L'établissement courant — nom, code, logo. */
function school_repo_current(): ?array
{
    return db_one(
        'SELECT id, code, name, logo_path, status FROM schools WHERE id = :id LIMIT 1',
        ['id' => tenant_require()],
        true
    );
}
