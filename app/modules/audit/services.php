<?php
/**
 * Module JOURNAL — lecture, libellés, rétention.
 *
 * POURQUOI CE MODULE EXISTE
 * ==========================
 * Neuf phases ont écrit consciencieusement dans `audit_logs` : 55 actions
 * distinctes, de la connexion au retrait d'un document officiel. Aucun
 * écran ne permettait d'en lire une seule ligne, et les permissions
 * `audit.view` et `platform.audit.view` étaient semées depuis la phase 1
 * sans rien ouvrir.
 *
 *   > Un journal que personne ne peut lire n'est pas une traçabilité,
 *   > c'est une table qui grossit.
 *
 * CE QU'IL NE FAIT PAS, ET C'EST VOULU
 * =====================================
 * Aucune suppression à l'unité. Un journal qu'on peut élaguer ligne à
 * ligne ne prouve plus rien : celui qu'il surveille effacerait la sienne.
 * La seule sortie possible est une purge par ANCIENNETÉ, bornée par un
 * plancher, et elle-même journalisée.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * Libellés lisibles des actions.
 *
 * La liste n'est pas exhaustive et n'a pas à l'être : une action absente
 * s'affiche telle qu'elle est écrite en base. Mieux vaut un code brut
 * qu'un libellé inventé.
 */
const AUDIT_ACTION_LABELS = [
    'login'                    => 'Connexion',
    'logout'                   => 'Déconnexion',
    'access_denied'            => 'Accès refusé',
    'password_changed'         => 'Mot de passe modifié',
    'password_reset'           => 'Mot de passe réinitialisé',
    'password_reset_requested' => 'Réinitialisation demandée',
    'create'                   => 'Création',
    'update'                   => 'Modification',
    'delete'                   => 'Suppression',
    'import'                   => 'Import',
    'publish'                  => 'Publication',
    'upload'                   => 'Fichier déposé',
    'document.issued'          => 'Document délivré',
    'document.revoked'         => 'Document révoqué',
    'payment.record'           => 'Paiement encaissé',
    'payment.cancel'           => 'Paiement annulé',
    'expense.record'           => 'Dépense enregistrée',
    'user.create'              => 'Compte créé',
    'user.set_roles'           => 'Rôles modifiés',
    'user.set_status'          => 'Statut de compte modifié',
    'student.access'           => 'Dossier d\'élève consulté',
    'guardian.access'          => 'Accès parent',
    'school.branding_updated'  => 'Identité de l\'école modifiée',
    'audit.purged'             => 'Journal purgé',
];

/**
 * Le plancher de rétention, en jours.
 *
 * On ne purge rien de plus récent. Douze mois couvrent une année
 * scolaire entière plus le délai pendant lequel une contestation de note
 * ou de paiement reste plausible. Un chiffre plus bas rendrait le
 * journal inutile là où il sert : après coup.
 */
const AUDIT_RETENTION_FLOOR_DAYS = 365;

/** Libellé d'une action, ou le code lui-même s'il est inconnu. */
function audit_action_label(string $action): string
{
    return AUDIT_ACTION_LABELS[$action] ?? $action;
}

/**
 * L'adresse IP, rendue lisible.
 *
 * Elle est stockée en `varbinary(16)` — forme binaire produite par
 * `inet_pton`. L'afficher brute donnerait des octets illisibles.
 */
function audit_ip_display(mixed $binaire): string
{
    if (!is_string($binaire) || $binaire === '') {
        return '—';
    }

    $texte = @inet_ntop($binaire);

    return $texte === false ? '—' : $texte;
}

/**
 * Purge les entrées antérieures à un nombre de jours donné.
 *
 * TROIS PROTECTIONS, ET AUCUNE N'EST DÉCORATIVE
 * ==============================================
 *  1. Le plancher : rien de plus récent que douze mois ne part.
 *  2. Le périmètre : la clause porte `tenant_require()`, jamais une
 *     valeur venue de la requête — une école ne purge que la sienne.
 *  3. La trace : la purge s'écrit dans le journal qu'elle vient de
 *     réduire, avec le nombre de lignes et la borne appliquée.
 *
 *   > Une purge qui ne laisse pas de trace d'elle-même est
 *   > indiscernable d'un effacement.
 *
 * @return array{ok: bool, message: string, deleted?: int}
 */
function audit_service_purge(int $jours): array
{
    if (!can('audit.purge')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de purger le journal.'];
    }

    if ($jours < AUDIT_RETENTION_FLOOR_DAYS) {
        return [
            'ok'      => false,
            'message' => 'Le journal se conserve au moins '
                . AUDIT_RETENTION_FLOOR_DAYS . ' jours. Une purge plus courte '
                . 'effacerait la trace d\'une année scolaire en cours.',
        ];
    }

    $limite = date('Y-m-d H:i:s', strtotime('-' . $jours . ' days'));

    $supprimees = (int) db_query(
        'DELETE FROM audit_logs WHERE school_id = :school_id AND created_at < :limite',
        ['school_id' => tenant_require(), 'limite' => $limite]
    )->rowCount();

    // La trace s'écrit APRÈS la suppression : elle est donc elle-même
    // hors de portée de la purge qu'elle décrit.
    audit_log('audit.purged', 'audit_logs', null, null, [
        'jours_conserves' => $jours,
        'anterieur_a'     => $limite,
        'lignes'          => $supprimees,
    ], 'Purge du journal');

    return [
        'ok'      => true,
        'deleted' => $supprimees,
        'message' => $supprimees === 0
            ? 'Aucune entrée n\'était antérieure à cette date.'
            : $supprimees . ' entrée(s) supprimée(s), antérieures au '
              . date('d/m/Y', strtotime($limite)) . '.',
    ];
}
