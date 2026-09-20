<?php
/**
 * PÉRIMÈTRE PLATEFORME — la seule porte vers une lecture inter-écoles.
 *
 * Ce que ce fichier protège
 * =========================
 * Tout le produit repose sur une promesse : les données d'une école ne
 * sont jamais visibles d'une autre. Le garde-fou de `tenant.php` la
 * tient pour chaque requête.
 *
 * Mais l'éditeur, lui, doit voir toutes les écoles : c'est son métier de
 * savoir qui est abonné, qui expire, qui doit payer. Il existe donc
 * nécessairement des requêtes transversales — et elles sont, par
 * construction, les plus dangereuses du dépôt.
 *
 * LE DRAPEAU NE SUFFISAIT PAS.
 * ----------------------------
 * `db_query($sql, $params, true)` désactivait le garde-fou. Il était
 * déjà employé des dizaines de fois pour de bonnes raisons — lire
 * `plans`, `education_levels`, `permissions`, qui n'appartiennent à
 * aucune école. Rien, dans le code, ne distinguait ces lectures
 * légitimes d'un `SELECT … FROM students` sans filtre. Le jour où
 * quelqu'un copie une fonction de la console dans un module d'école, la
 * fuite est silencieuse et totale.
 *
 * Ici, une lecture transversale exige trois choses à la fois :
 *   1. un utilisateur de la PLATEFORME (aucune école de rattachement) ;
 *   2. la permission nommée qui correspond à l'écran ;
 *   3. une ouverture EXPLICITE et BORNÉE du périmètre.
 *
 * Aucune des trois ne s'obtient par accident.
 *
 * > Une échappatoire de sécurité qui ne dit pas QUI a le droit de
 * > l'emprunter n'est pas une échappatoire, c'est une porte.
 */

declare(strict_types=1);

/**
 * Profondeur d'ouverture du périmètre.
 *
 * Un compteur, pas un booléen : une fonction transversale peut en
 * appeler une autre, et la sortie de la première ne doit pas refermer
 * le périmètre de la seconde.
 */
function platform_scope_depth(?int $set = null): int
{
    static $depth = 0;

    if ($set !== null) {
        $depth = max(0, $set);
    }

    return $depth;
}

/** Le périmètre transversal est-il ouvert ? Lu par le garde-fou. */
function platform_scope_is_open(): bool
{
    return platform_scope_depth() > 0;
}

/**
 * L'utilisateur courant appartient-il à la PLATEFORME ?
 *
 * Le marqueur est `users.school_id IS NULL` — déjà la vérité du produit
 * depuis la phase 1 : c'est ce que porte le compte créé par
 * `install.php`, et c'est pourquoi le middleware `school` refuse les
 * pages d'établissement à ce compte.
 *
 * Un drapeau `is_platform` créerait une seconde vérité, qui finirait par
 * contredire la première.
 */
function platform_is_user(): bool
{
    $user = auth_user();

    return $user !== null && $user['school_id'] === null;
}

/**
 * Exige une habilitation plateforme, ou refuse la requête HTTP.
 *
 * LES DEUX CONDITIONS SONT NÉCESSAIRES.
 * ------------------------------------
 * La permission seule ne suffit pas : un rôle personnalisé mal accordé
 * dans une école ne doit pas ouvrir la console. Le rattachement seul ne
 * suffit pas non plus : tous les comptes de la plateforme n'ont pas à
 * tout faire.
 *
 * Rappel de la règle de la phase 3, qui vaut ici aussi : la permission
 * dit ce qu'on a le droit de FAIRE, jamais SUR QUI. C'est le
 * rattachement qui dit sur qui.
 */
function platform_require(string $permission): void
{
    $refusal = platform_refusal($permission);

    if ($refusal !== null) {
        abort(403, $refusal);
    }
}

/**
 * LA DÉCISION, SÉPARÉE DE SON RENDU.
 *
 * `platform_require()` se terminait par `abort(403)`, qui imprime une
 * page HTML et arrête le processus. La règle d'habilitation n'était donc
 * observable qu'en ouvrant un navigateur : aucun test ne pouvait
 * l'exécuter.
 *
 * Le projet a déjà nommé ce motif : du code qu'un test ne peut pas
 * exécuter n'est pas du code testé, c'est du code non écrit. La décision
 * vit ici, elle rend un motif ou `null` ; le refus HTTP n'est qu'une
 * mise en forme.
 *
 * @return string|null Le motif du refus, ou null si l'accès est ouvert.
 */
function platform_refusal(string $permission): ?string
{
    if (!platform_is_user()) {
        return 'Cet écran appartient à la plateforme. '
            . 'Un compte rattaché à un établissement n\'y a pas accès.';
    }

    if (!can($permission)) {
        return 'Habilitation plateforme insuffisante pour cet écran.';
    }

    return null;
}

/** Raccourci de lecture : l'accès est-il ouvert ? */
function platform_can(string $permission): bool
{
    return platform_refusal($permission) === null;
}

/**
 * Exécute une lecture transversale, sous habilitation.
 *
 * Usage :
 *
 *   $rows = platform_scope('platform.subscription.view', function (): array {
 *       return db_all('SELECT school_id, COUNT(*) … GROUP BY school_id', [], true);
 *   });
 *
 * Le périmètre se referme TOUJOURS, exception comprise : un `finally`
 * garantit qu'une erreur au milieu d'un tableau de bord ne laisse pas la
 * porte ouverte pour le reste de la requête HTTP.
 *
 * @template T
 * @param callable():T $work
 * @return T
 */
function platform_scope(string $permission, callable $work)
{
    platform_require($permission);

    platform_scope_depth(platform_scope_depth() + 1);

    try {
        return $work();
    } finally {
        platform_scope_depth(platform_scope_depth() - 1);
    }
}

/**
 * Périmètre du CHEMIN D'IDENTITÉ.
 *
 * POURQUOI IL EXISTE, ET POURQUOI IL EST ÉTROIT
 * =============================================
 * `users` est une table multi-école, et c'est justifié : une future page
 * « Utilisateurs » ne doit jamais lister les comptes d'un autre
 * établissement.
 *
 * Mais l'authentification pose un problème d'ordre : on cherche un
 * compte par son identifiant AVANT de savoir à quelle école il
 * appartient. C'est même cette requête qui l'apprend. Exiger un filtre
 * `school_id` y serait circulaire : il faudrait connaître la réponse
 * pour poser la question.
 *
 * Ce périmètre couvre donc exactement ces requêtes-là, et son nom le
 * dit. Il ne demande aucune habilitation — l'utilisateur n'est pas
 * encore authentifié quand elles s'exécutent — mais son étroitesse est
 * sa garantie : une requête qui RAMÈNE PLUSIEURS COMPTES n'a rien à
 * faire ici.
 *
 * > Une exception nommée se relit et se conteste. Un drapeau booléen
 * > se recopie.
 *
 * @template T
 * @param callable():T $work
 * @return T
 */
function tenant_scope_identity(callable $work)
{
    platform_scope_depth(platform_scope_depth() + 1);

    try {
        return $work();
    } finally {
        platform_scope_depth(platform_scope_depth() - 1);
    }
}

/**
 * Ouvre le périmètre SANS vérifier d'habilitation.
 *
 * RÉSERVÉ AUX SCRIPTS EN LIGNE DE COMMANDE : migrations, installateur,
 * suites de tests, tâches planifiées. Ces contextes n'ont pas
 * d'utilisateur connecté, donc `platform_require()` y refuserait tout —
 * et `install.php` doit bien pouvoir compter les écoles.
 *
 * La garde est le mode d'exécution, pas la confiance : hors CLI, cette
 * fonction lève. Un jour, quelqu'un tentera de l'appeler depuis un
 * contrôleur pour « débloquer » un écran ; ce jour-là, elle doit casser.
 *
 * @template T
 * @param callable():T $work
 * @return T
 */
function platform_scope_cli(callable $work)
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException(
            'platform_scope_cli() est réservée aux scripts en ligne de commande. '
            . 'Depuis une requête HTTP, utiliser platform_scope($permission, …).'
        );
    }

    platform_scope_depth(platform_scope_depth() + 1);

    try {
        return $work();
    } finally {
        platform_scope_depth(platform_scope_depth() - 1);
    }
}
