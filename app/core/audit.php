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
 *
 * Un journal d'audit est consultable par des administrateurs d'école :
 * il ne doit contenir aucun secret.
 *
 * LA LISTE EST BILINGUE, ET CE N'EST PAS DE LA COQUETTERIE
 * ========================================================
 * Elle ne contenait que des termes anglais alors que tout le produit
 * s'écrit en français. Mesuré à l'exécution : `mot_de_passe`,
 * `motdepasse`, `mdp`, `jeton` et `cle_de_chiffrement` traversaient le
 * masquage EN CLAIR et se seraient retrouvés à l'écran du journal.
 *
 *   > Une liste de masquage écrite dans une autre langue que le code
 *   > qu'elle protège ne masque rien — elle rassure.
 *
 * Un terme n'entre ici que s'il ne peut désigner qu'un secret. `key`
 * seul en est volontairement absent : il emporterait `period_key` et
 * `setting_key`, qui sont exactement ce qu'on veut lire dans le journal.
 * Trop masquer n'est pas neutre — cela rend le journal muet là où il
 * devrait parler.
 */
const AUDIT_REDACTED_FIELDS = [
    // Anglais
    'password', 'passwd', 'token', 'api_key', 'secret',
    'credential', 'cipher', 'encryption_key', 'private_key',
    // Français — le produit nomme ses champs dans cette langue
    'mot_de_passe', 'motdepasse', 'mdp', 'jeton',
    'cle_de_chiffrement', 'cle_secrete', 'cle_privee',
];

/**
 * Profondeur maximale explorée par le masquage.
 *
 * Le masquage ne descendait pas : `['smtp' => ['password' => '…']]`
 * passait en clair. Il descend désormais, mais pas indéfiniment — une
 * structure profonde n'a rien à faire dans un journal, et une borne
 * vaut mieux qu'une récursion qui dépend de ce qu'on lui donne.
 */
const AUDIT_REDACT_MAX_DEPTH = 6;

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
    // Le tableau reçu est le niveau 1. Compter à partir de zéro laissait
    // passer DEUX niveaux de plus que la constante n'en annonçait : la
    // coupure tombait au huitième tableau imbriqué pour une borne
    // déclarée à six.
    //
    //   > Une constante qui annonce une borne que le code n'applique pas
    //   > est une borne que personne ne peut vérifier en la lisant.
    return (string) json_encode(
        audit_redact($values, 1),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

/**
 * Masque récursivement les champs sensibles.
 *
 * `$depth` est le NIVEAU D'IMBRICATION du tableau reçu : 1 pour le
 * tableau de premier rang. Un enfant est donc au niveau `$depth + 1`, et
 * c'est ce niveau-là qui est comparé à la borne. Six niveaux passent
 * entiers, le septième est coupé.
 *
 * UN BOOLÉEN N'EST JAMAIS UN SECRET
 * ==================================
 * Le module Courriel journalise `mot_de_passe_change => true` : une
 * information précieuse — le mot de passe SMTP a-t-il été changé ? — et
 * sans aucun risque. La masquer sur la seule foi de son nom
 * appauvrirait le journal sans rien protéger. La valeur décide donc
 * autant que la clé : ce qui ne peut pas porter un secret n'est pas
 * masqué.
 *
 * @param array<mixed> $values
 * @return array<mixed>
 */
function audit_redact(array $values, int $depth): array
{
    foreach ($values as $key => $value) {
        if (is_array($value)) {
            // Au-delà de la borne, on ne devine pas : on remplace.
            $values[$key] = ($depth + 1) > AUDIT_REDACT_MAX_DEPTH
                ? '[trop profond]'
                : audit_redact($value, $depth + 1);

            continue;
        }

        if (!is_bool($value) && audit_key_is_sensitive((string) $key)) {
            $values[$key] = '***';

            continue;
        }

        // Les données binaires (adresses IP) ne sont pas encodables en JSON.
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            $values[$key] = '[binaire]';
        }
    }

    return $values;
}

/** Vrai si le nom du champ ne peut désigner qu'un secret. */
function audit_key_is_sensitive(string $key): bool
{
    foreach (AUDIT_REDACTED_FIELDS as $sensitive) {
        if (stripos($key, $sensitive) !== false) {
            return true;
        }
    }

    return false;
}
