<?php
/**
 * Validation des données côté serveur.
 *
 * Règle absolue du projet : la validation JavaScript est un confort
 * d'interface, jamais une sécurité. Toute donnée entrante est revalidée
 * ici avant d'atteindre la base.
 *
 * Usage :
 *
 *   $v = validate(input_all(), [
 *       'last_name'  => 'required|string|max:80',
 *       'email'      => 'nullable|email|max:190|unique:users,email',
 *       'birth_date' => 'required|date|before:today',
 *       'level_id'   => 'required|int|exists:education_levels,id',
 *   ]);
 *
 *   if (!validator_passes($v)) {
 *       flash_errors(validator_errors($v));
 *       redirect_back();
 *   }
 */

declare(strict_types=1);

/**
 * Valide un jeu de données contre un ensemble de règles.
 *
 * @return array{data: array, errors: array<string, string[]>}
 */
function validate(array $data, array $rules, array $labels = []): array
{
    $errors = [];
    $clean  = [];

    foreach ($rules as $field => $ruleString) {
        $value    = $data[$field] ?? null;
        $label    = $labels[$field] ?? validator_humanize($field);
        $ruleList = is_array($ruleString) ? $ruleString : explode('|', $ruleString);

        $isNullable = in_array('nullable', $ruleList, true);
        $isRequired = in_array('required', $ruleList, true);
        $isEmpty    = $value === null || $value === '' || (is_array($value) && $value === []);

        if ($isEmpty) {
            if ($isRequired) {
                $errors[$field][] = "Le champ « {$label} » est obligatoire.";
            } elseif ($isNullable) {
                $clean[$field] = null;
            }

            continue;
        }

        foreach ($ruleList as $rule) {
            if ($rule === '' || $rule === 'required' || $rule === 'nullable') {
                continue;
            }

            [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

            $error = validator_apply($name, $value, $parameter, $label, $data);

            if ($error !== null) {
                $errors[$field][] = $error;
                break; // une seule erreur par champ suffit à l'utilisateur
            }
        }

        if (!isset($errors[$field])) {
            $clean[$field] = $value;
        }
    }

    return ['data' => $clean, 'errors' => $errors];
}

function validator_passes(array $result): bool
{
    return $result['errors'] === [];
}

function validator_errors(array $result): array
{
    return $result['errors'];
}

function validator_data(array $result): array
{
    return $result['data'];
}

/** Première erreur de chaque champ, format adapté aux réponses JSON. */
function validator_first_errors(array $result): array
{
    return array_map(static fn (array $messages): string => $messages[0], $result['errors']);
}

/**
 * Applique une règle unique. Retourne le message d'erreur, ou null si valide.
 */
function validator_apply(string $rule, mixed $value, ?string $parameter, string $label, array $data): ?string
{
    switch ($rule) {
        case 'string':
            return is_string($value) ? null : "Le champ « {$label} » doit être du texte.";

        case 'int':
            return filter_var($value, FILTER_VALIDATE_INT) !== false
                ? null : "Le champ « {$label} » doit être un nombre entier.";

        case 'numeric':
            return is_numeric(str_replace(',', '.', (string) $value))
                ? null : "Le champ « {$label} » doit être un nombre.";

        case 'bool':
            return in_array($value, ['0', '1', 0, 1, true, false, 'on', 'true', 'false'], true)
                ? null : "Le champ « {$label} » est invalide.";

        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? null : "L'adresse email « {$label} » n'est pas valide.";

        case 'url':
            return filter_var($value, FILTER_VALIDATE_URL) !== false
                ? null : "L'adresse « {$label} » n'est pas valide.";

        case 'phone':
            // Format souple : chiffres, espaces, +, -, parenthèses (8 à 20 signes).
            return preg_match('/^\+?[0-9\s\-\(\)]{8,20}$/', (string) $value)
                ? null : "Le numéro « {$label} » n'est pas valide.";

        case 'min':
            $min = (int) $parameter;

            if (is_numeric($value)) {
                return (float) $value >= $min ? null : "« {$label} » doit être supérieur ou égal à {$min}.";
            }

            return mb_strlen((string) $value) >= $min
                ? null : "« {$label} » doit contenir au moins {$min} caractères.";

        case 'max':
            $max = (int) $parameter;

            if (is_numeric($value)) {
                return (float) $value <= $max ? null : "« {$label} » doit être inférieur ou égal à {$max}.";
            }

            return mb_strlen((string) $value) <= $max
                ? null : "« {$label} » ne doit pas dépasser {$max} caractères.";

        case 'between':
            [$low, $high] = array_pad(explode(',', (string) $parameter), 2, 0);
            $length = is_numeric($value) ? (float) $value : mb_strlen((string) $value);

            return ($length >= (float) $low && $length <= (float) $high)
                ? null : "« {$label} » doit être compris entre {$low} et {$high}.";

        case 'in':
            $allowed = explode(',', (string) $parameter);

            return in_array((string) $value, $allowed, true)
                ? null : "La valeur de « {$label} » n'est pas autorisée.";

        case 'date':
            $ts = strtotime((string) $value);

            return $ts !== false ? null : "La date « {$label} » n'est pas valide.";

        case 'before':
            $limit = $parameter === 'today' ? time() : (int) strtotime((string) $parameter);

            return strtotime((string) $value) < $limit
                ? null : "La date « {$label} » doit être antérieure.";

        case 'after':
            $limit = $parameter === 'today' ? time() : (int) strtotime((string) $parameter);

            return strtotime((string) $value) > $limit
                ? null : "La date « {$label} » doit être postérieure.";

        case 'confirmed':
            $other = $data[$parameter ?? ''] ?? null;

            return (string) $value === (string) $other
                ? null : "La confirmation de « {$label} » ne correspond pas.";

        case 'regex':
            return preg_match((string) $parameter, (string) $value)
                ? null : "Le format de « {$label} » est invalide.";

        case 'alpha_dash':
            return preg_match('/^[a-zA-Z0-9_\-\.]+$/', (string) $value)
                ? null : "« {$label} » ne peut contenir que lettres, chiffres, tiret, point et souligné.";

        case 'password':
            return validator_password((string) $value, $label);

        case 'unique':
            return validator_unique($value, $parameter, $label);

        case 'exists':
            return validator_exists($value, $parameter, $label);

        default:
            throw new InvalidArgumentException("Règle de validation inconnue : {$rule}");
    }
}

/**
 * Politique de mot de passe.
 * Longueur d'abord : c'est le facteur le plus déterminant.
 */
function validator_password(string $value, string $label): ?string
{
    $min = (int) config('security.password_min_length', 10);

    if (mb_strlen($value) < $min) {
        return "« {$label} » doit contenir au moins {$min} caractères.";
    }

    $checks = [
        preg_match('/[a-z]/', $value),
        preg_match('/[A-Z]/', $value),
        preg_match('/[0-9]/', $value),
    ];

    if (array_sum($checks) < 3) {
        return "« {$label} » doit contenir au moins une minuscule, une majuscule et un chiffre.";
    }

    return null;
}

/**
 * Unicité en base. Format : unique:table,colonne[,id_a_ignorer]
 *
 * Le nom de table et de colonne vient du code appelant, jamais de
 * l'utilisateur : db_safe_identifier verrouille tout de même le format.
 */
function validator_unique(mixed $value, ?string $parameter, string $label): ?string
{
    [$table, $column, $ignoreId] = array_pad(explode(',', (string) $parameter), 3, null);

    if (!$table || !$column) {
        throw new InvalidArgumentException('Règle unique mal formée : unique:table,colonne[,id]');
    }

    $sql    = 'SELECT 1 FROM ' . db_safe_identifier($table)
            . ' WHERE ' . db_safe_identifier($column) . ' = :value';
    $params = ['value' => $value];

    // Les tables multi-école sont vérifiées dans le périmètre de l'école.
    if (in_array(strtolower($table), TENANT_TABLES, true) && tenant_id() !== null) {
        $sql .= ' AND school_id = :school_id';
        $params['school_id'] = tenant_id();
    }

    if ($ignoreId !== null && $ignoreId !== '') {
        $sql .= ' AND id <> :ignore_id';
        $params['ignore_id'] = (int) $ignoreId;
    }

    $skipGuard = !in_array(strtolower($table), TENANT_TABLES, true) || tenant_id() === null;

    return db_exists($sql . ' LIMIT 1', $params, $skipGuard)
        ? "Cette valeur de « {$label} » est déjà utilisée."
        : null;
}

/**
 * Existence en base. Format : exists:table,colonne
 * Empêche qu'un identifiant forgé dans un formulaire désigne une ligne
 * inexistante ou appartenant à une autre école.
 */
function validator_exists(mixed $value, ?string $parameter, string $label): ?string
{
    [$table, $column] = array_pad(explode(',', (string) $parameter), 2, 'id');

    if (!$table) {
        throw new InvalidArgumentException('Règle exists mal formée : exists:table,colonne');
    }

    $sql    = 'SELECT 1 FROM ' . db_safe_identifier($table)
            . ' WHERE ' . db_safe_identifier((string) $column) . ' = :value';
    $params = ['value' => $value];

    if (in_array(strtolower($table), TENANT_TABLES, true) && tenant_id() !== null) {
        $sql .= ' AND school_id = :school_id';
        $params['school_id'] = tenant_id();
    }

    $skipGuard = !in_array(strtolower($table), TENANT_TABLES, true) || tenant_id() === null;

    return db_exists($sql . ' LIMIT 1', $params, $skipGuard)
        ? null
        : "La valeur sélectionnée pour « {$label} » n'existe pas.";
}

/** Transforme un nom de champ technique en libellé lisible. */
function validator_humanize(string $field): string
{
    return ucfirst(str_replace(['_id', '_'], ['', ' '], $field));
}
