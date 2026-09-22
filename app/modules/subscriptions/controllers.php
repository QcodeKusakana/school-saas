<?php
/**
 * Module ABONNEMENTS — contrôleurs (phase 7A).
 *
 * Un seul écran, en lecture : l'école CONSULTE son abonnement, elle
 * n'en change pas. Vendre est le métier de l'éditeur, et
 * `platform.subscription.manage` ne lui appartient pas.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';

function ctrl_subscription_show(): void
{
    // CET ÉCRAN NE CRÉE PLUS L'ESSAI — audit 7B1.
    //
    // Il appelait `subscription_ensure()`, au motif qu'une école sans
    // abonnement devait voir son essai plutôt qu'un vide inexplicable.
    // La console de l'éditeur a montré ce que cela coûtait : un éditeur
    // qui OUVRE une école cliente et regarde cet écran lui démarrait un
    // essai de 30 jours. Les 30 jours couraient donc depuis sa visite,
    // et non depuis la première utilisation de l'école — qui, se
    // connectant huit jours plus tard, n'en avait plus que 22.
    //
    // > Une consultation ne démarre pas une horloge commerciale.
    //
    // L'invariant reste tenu là où il doit l'être : sur le chemin
    // d'ÉCRITURE (`subscription_can_add_student()` et
    // `_can_add_staff_user()`), là où une écriture est de toute façon
    // demandée. Et la vue sait dire « aucun abonnement enregistré ».

    view('subscriptions/show', [
        'title'        => 'Mon abonnement',
        // CELUI QUI GOUVERNE EST CELUI QUI S'AFFICHE — voir
        // subscription_to_show() et l'audit 7A.
        'subscription' => subscription_to_show(),
        'usage'        => subscription_usage(),
        'daysLeft'     => subscription_days_left(),
        'plans'        => subscription_plans(),
    ]);
}
