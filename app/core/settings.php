<?php
/**
 * Paramètres par établissement.
 *
 * Table school_settings : un couple clé/valeur par école, typé.
 * Évite d'ajouter une colonne à la table schools chaque fois qu'une
 * option apparaît, et permet à chaque établissement de régler le
 * produit sans modification de code.
 *
 * Les valeurs sont chargées en une seule requête par requête HTTP,
 * puis conservées en mémoire.
 */

declare(strict_types=1);

/**
 * Lit un paramètre de l'établissement courant.
 *
 *   school_setting('student.matricule_format', '{YY}-{SEQ:4}')
 *   school_setting('grade.pass_percentage', 50)
 */
function school_setting(string $key, mixed $default = null): mixed
{
    $settings = school_settings_all();

    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

/** Tous les paramètres de l'établissement courant, déjà typés. */
function school_settings_all(bool $refresh = false): array
{
    static $cache = null;

    if ($refresh) {
        $cache = null;
    }

    if ($cache !== null) {
        return $cache;
    }

    if (tenant_id() === null) {
        return $cache = [];
    }

    $rows  = tenant_all('school_settings');
    $cache = [];

    foreach ($rows as $row) {
        $cache[$row['setting_key']] = school_setting_cast(
            $row['setting_value'],
            (string) $row['setting_type']
        );
    }

    return $cache;
}

/**
 * Écrit un paramètre.
 * Le type est déduit de la valeur si l'appelant ne le précise pas.
 */
function school_setting_set(string $key, mixed $value, ?string $type = null): void
{
    $type ??= match (true) {
        is_bool($value)  => 'bool',
        is_int($value)   => 'int',
        is_float($value) => 'float',
        is_array($value) => 'json',
        default          => 'string',
    };

    $stored = match ($type) {
        'bool'  => $value ? '1' : '0',
        'json'  => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        default => (string) $value,
    };

    $before = school_setting($key);

    // ON DUPLICATE KEY : la contrainte uq_school_setting rend
    // l'opération atomique, sans SELECT préalable ni risque de doublon
    // lorsque deux écrans enregistrent en même temps.
    db_query(
        'INSERT INTO school_settings (school_id, setting_key, setting_value, setting_type)
         VALUES (:school_id, :key, :value, :type)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type)',
        [
            'school_id' => tenant_require(),
            'key'       => $key,
            'value'     => $stored,
            'type'      => $type,
        ]
    );

    school_settings_all(true);

    if ((string) $before !== (string) $stored) {
        audit_log(
            'update',
            'school_setting',
            null,
            [$key => $before],
            [$key => $stored],
            'Modification du paramètre ' . $key
        );
    }
}

/** Convertit une valeur stockée en texte vers son type déclaré. */
function school_setting_cast(?string $value, string $type): mixed
{
    if ($value === null) {
        return null;
    }

    return match ($type) {
        'int'   => (int) $value,
        'float' => (float) $value,
        'bool'  => $value === '1' || strtolower($value) === 'true',
        'json'  => json_decode($value, true) ?? [],
        default => $value,
    };
}
