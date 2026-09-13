<?php
/**
 * Journal d'audit.
 *
 * Trace les actions sensibles : qui, quoi, quand, depuis où, et quelles
 * valeurs ont changé. Indispensable pour un logiciel scolaire — une note
 * ou un paiement modifié doit toujours pouvoir être expliqué.
 *
 * Le journal ne doit jamais faire échouer l'action métier : toute erreur
 * d'écriture est capturée et renvoyée vers les logs fichier.
 */

declare(strict_types=1);

/**
 * Champs jamais recopiés dans le journal.
 * Un journal d'audit est consultable par des administrateurs d'école :
 * il ne doit contenir aucun secret.
 */
const AUDIT_REDACTED_FIELDS = [
    'password', 'password_hash', 'password_confirmation',
    'token', 'token_hash', 'session_token', 'api_key', 'secret',
];

/**
 * Enregistre une entrée d'audit.
 *
 * @param string     $action      create|update|delete|login|logout|export|access_denied|...
 * @param string|null $entityType Nom logique de l'entité concernée (student, payment, ...)
 * @param int|null   $entityId
 * @param array|null $oldValues   État avant modification
 * @param array|null $newValues   État après modification
 * @param string     $description Phrase lisible par un humain
 */
function audit_log(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    string $description = ''
): void {
    try {
        db_insert('audit_logs', [
            'school_id'   => $_SESSION['school_id'] ?? null,
            'user_id'     => $_SESSION['user_id'] ?? null,
            'action'      => mb_substr($action, 0, 60),
            'entity_type' => $entityType !== null ? mb_substr($entityType, 0, 60) : null,
            'entity_id'   => $entityId,
            'old_values'  => $oldValues !== null ? audit_encode($oldValues) : null,
            'new_values'  => $newValues !== null ? audit_encode($newValues) : null,
            'description' => $description !== '' ? mb_substr($description, 0, 255) : null,
            'ip_address'  => ip_binary(),
            'user_agent'  => user_agent(),
        ], true);
    } catch (Throwable $e) {
        // Un journal indisponible ne doit jamais bloquer une inscription
        // ou un encaissement en cours.
        log_error('Écriture du journal d\'audit impossible', [
            'action' => $action,
            'error'  => $e->getMessage(),
        ]);
    }
}

/**
 * Journalise une modification en ne conservant que les champs réellement
 * changés. Évite de saturer la table avec des enregistrements identiques.
 */
function audit_update(string $entityType, int $entityId, array $before, array $after, string $description = ''): void
{
    $changedOld = [];
    $changedNew = [];

    foreach ($after as $key => $newValue) {
        $oldValue = $before[$key] ?? null;

        // Comparaison souple : la base renvoie des chaînes là où le
        // formulaire renvoie des entiers.
        if ((string) $oldValue !== (string) $newValue) {
            $changedOld[$key] = $oldValue;
            $changedNew[$key] = $newValue;
        }
    }

    if ($changedNew === []) {
        return;
    }

    audit_log('update', $entityType, $entityId, $changedOld, $changedNew, $description);
}

/** Encode les valeurs en JSON après masquage des champs sensibles. */
function audit_encode(array $values): string
{
    foreach ($values as $key => $value) {
        foreach (AUDIT_REDACTED_FIELDS as $sensitive) {
            if (stripos((string) $key, $sensitive) !== false) {
                $values[$key] = '***';
                continue 2;
            }
        }

        // Les données binaires (adresses IP) ne sont pas encodables en JSON.
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            $values[$key] = '[binaire]';
        }
    }

    return (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
