/**
 * L'APPEL DES PRÉSENCES, HORS CONNEXION.
 *
 * POURQUOI CET ÉCRAN, ET PAS UN AUTRE
 * ====================================
 * L'appel se fait debout devant une classe, souvent dans une cour, au
 * moment précis où le réseau manque. Il ne touche ni à l'argent ni à
 * un document officiel, et il se corrige. C'est le seul écran du
 * produit dont l'indisponibilité empêche vraiment de travailler.
 *
 * Les bulletins, la caisse et les inscriptions restent en ligne : les
 * traiter à l'aveugle produirait des divergences que personne ne
 * saurait arbitrer, et la page de repli le dit franchement.
 *
 * ON NE CROIT PAS `navigator.onLine`
 * ===================================
 * Ce drapeau dit qu'une INTERFACE RÉSEAU est active. Il ne dit pas que
 * le serveur répond. En RDC, le cas ordinaire est précisément
 * celui-là : le téléphone affiche « 4G » et rien ne passe.
 *
 *   > `navigator.onLine` annonce qu'une interface est active, pas que
 *   > le serveur répond. On met en file sur l'ÉCHEC RÉEL, jamais sur
 *   > un drapeau.
 *
 * L'envoi est donc TENTÉ, et c'est son échec qui déclenche la mise en
 * file. Un formulaire natif ne permet pas cela : quand le fil est
 * mort, le navigateur affiche sa propre page d'erreur et la saisie est
 * perdue — exactement ce que cette phase existe pour empêcher.
 *
 * `navigator.onLine === false` ne sert donc qu'à s'épargner une
 * attente inutile quand l'interface est manifestement coupée.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[data-appel]');

        if (!form || !window.Offline) { return; }

        var classeId = parseInt(form.getAttribute('data-classe'), 10);

        // ============================================================
        //  ON MÉMORISE LA LISTE — le strict nécessaire
        // ============================================================
        // Identifiant d'inscription, matricule, nom. Rien d'autre :
        // pas de tuteur, pas d'adresse, pas de solde, pas de cote.
        // IndexedDB n'est pas chiffré et l'appareil est partagé.
        if (navigator.onLine) {
            var eleves = Array.prototype.map.call(
                form.querySelectorAll('[data-eleve]'),
                function (tr) {
                    return {
                        inscription: parseInt(tr.getAttribute('data-inscription'), 10),
                        nom:         tr.getAttribute('data-nom') || '',
                        matricule:   tr.getAttribute('data-matricule') || ''
                    };
                }
            );

            if (eleves.length) {
                window.Offline.memoriserListe(
                    classeId,
                    eleves,
                    form.getAttribute('data-seen-updated') || null
                );
            }
        }

        // ============================================================
        //  L'ENVOI
        // ============================================================
        var envoiEnCours = false;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (envoiEnCours) { return; }
            envoiEnCours = true;

            // Interface manifestement coupée : inutile d'attendre un
            // délai d'expiration, on met en file tout de suite.
            if (!navigator.onLine) {
                mettreEnFile();
                return;
            }

            // SINON ON TENTE VRAIMENT. C'est l'échec du fil qui décide,
            // pas un drapeau qui ment.
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                // Le serveur a répondu — quoi qu'il réponde, c'est SA
                // réponse qui fait foi, pas notre file. On suit la
                // redirection comme l'aurait fait le navigateur.
                window.location.href = r.url || form.action;
            }).catch(function () {
                // Le fil est mort malgré « en ligne ».
                mettreEnFile();
            });
        });

        function mettreEnFile() {
            var entries = {};

            Array.prototype.forEach.call(
                form.querySelectorAll('[data-eleve]'),
                function (tr) {
                    var id     = tr.getAttribute('data-inscription');
                    var coche  = form.querySelector('input[name="status[' + id + ']"]:checked');
                    var retard = form.querySelector('input[name="minutes[' + id + ']"]');

                    if (!coche) { return; }

                    entries[id] = {
                        status:       coche.value,
                        minutes_late: retard && retard.value !== '' ? retard.value : null
                    };
                }
            );

            if (Object.keys(entries).length === 0) {
                avertir('danger', 'Aucun élève n\'a de statut : rien n\'a été enregistré.');
                envoiEnCours = false;
                return;
            }

            window.Offline.mettreEnFile({
                classroom_id:    classeId,
                date:            form.getAttribute('data-date'),
                slot:            form.getAttribute('data-moment'),
                // CE QUE L'ÉCRAN AVAIT VU. Chaîne vide = « il n'y avait
                // pas encore d'appel ». Le serveur s'en sert pour
                // détecter qu'un autre est passé entre-temps.
                seen_updated_at: form.getAttribute('data-seen-updated') || null,
                entries:         entries
            }).then(function () {
                window.Offline.indicateur();

                avertir(
                    'warning',
                    'Le serveur est injoignable : l\'appel est enregistré sur cet appareil et '
                    + 'partira dès que la liaison reviendra. Ne videz pas les données du '
                    + 'navigateur d\'ici là.'
                );

                // On désactive l'envoi : une seconde soumission hors
                // connexion créerait une deuxième opération pour le
                // même appel, et le serveur la traiterait comme une
                // correction — pas comme un doublon.
                Array.prototype.forEach.call(
                    form.querySelectorAll('button[type="submit"]'),
                    function (b) {
                        b.disabled    = true;
                        b.textContent = 'Enregistré hors connexion';
                    }
                );
            }).catch(function () {
                envoiEnCours = false;

                avertir(
                    'danger',
                    'Impossible d\'enregistrer sur cet appareil : le stockage local est '
                    + 'indisponible (navigation privée ?). Notez l\'appel sur papier.'
                );
            });
        }
    });

    /** Un message en tête de page — le même habillage que les autres. */
    function avertir(type, texte) {
        var hote = document.querySelector('.app-content') || document.body;
        var div  = document.createElement('div');

        div.className = 'alert alert-' + type;
        div.setAttribute('role', 'alert');
        div.textContent = texte;

        hote.insertBefore(div, hote.firstChild);
        div.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
})();
