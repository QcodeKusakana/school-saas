/**
 * Page de repli : on revient de soi-même quand le réseau revient.
 *
 * `online` ment parfois — le système annonce une interface active
 * alors que rien ne passe. On vérifie donc par une vraie requête
 * avant de recharger, plutôt que de renvoyer l'utilisateur sur une
 * seconde page d'erreur.
 */
(function () {
    'use strict';

    var enCours = false;

    function verifier() {
        if (enCours) { return; }
        enCours = true;

        fetch('/sync/etat', { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                if (r.ok) { location.reload(); }
            })
            .catch(function () { /* toujours coupé : on réessaiera */ })
            .finally(function () { enCours = false; });
    }

    window.addEventListener('online', verifier);
    setInterval(verifier, 15000);
})();
