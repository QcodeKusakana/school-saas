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
    // L'invariant vaut aussi ici : une école qui n'a jamais rien écrit
    // n'a pas encore d'abonnement, et l'écran doit montrer son essai
    // plutôt qu'un vide inexplicable.
    subscription_ensure();

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
