/**
 * HORS CONNEXION — IndexedDB, file d'attente, et l'appel des présences.
 *
 * CE QUI DESCEND SUR L'APPAREIL, ET POURQUOI SI PEU
 * ==================================================
 * Uniquement ce que l'appel exige : pour chaque classe ouverte,
 * l'identifiant d'inscription, le matricule et le nom de l'élève.
 *
 * PAS d'adresse, PAS de tuteur, PAS de téléphone, PAS de solde, PAS de
 * cote, PAS de photo. Ce sont des mineurs, et l'appareil est souvent
 * un téléphone partagé, parfois prêté, parfois volé. IndexedDB n'est
 * pas chiffré : la seule protection qui tienne est de ne pas y mettre
 * ce dont on n'a pas besoin.
 *
 *   > Ce qu'on ne télécharge pas ne peut pas fuir.
 *
 * TROIS BORNES, EN PLUS DE LA MINIMISATION
 * =========================================
 *  · la déconnexion efface tout — listes, file, cache du service
 *    worker ;
 *  · une liste de classe expire au bout de 7 jours ;
 *  · seules les classes réellement ouvertes descendent, jamais
 *    l'établissement entier.
 *
 * LA FILE D'ATTENTE
 * =================
 * Une saisie hors connexion est ÉCRITE dans `outbox` avec un
 * `client_uuid` tiré une seule fois. Ce même identifiant repart à
 * chaque tentative : le serveur en fait sa clé d'idempotence, et un
 * renvoi ne produit pas de doublon. C'est la même règle que la
 * messagerie — écrire d'abord, tenter ensuite.
 *
 * Ce que l'appareil a VU part avec la saisie (`seen_updated_at`) :
 * c'est ce qui permet au serveur de distinguer « personne n'a touché
 * à cet appel » de « quelqu'un l'a modifié pendant ma coupure ». On
 * n'écrase jamais en silence.
 */
(function () {
    'use strict';

    var BASE      = 'school-saas-offline';
    var VERSION   = 1;
    var JOURS_MAX = 7;

    var db = null;

    // =================================================================
    //  LA BASE LOCALE
    // =================================================================

    function ouvrir() {
        if (db) { return Promise.resolve(db); }

        return new Promise(function (resolve, reject) {
            var req = indexedDB.open(BASE, VERSION);

            req.onupgradeneeded = function (e) {
                var d = e.target.result;

                if (!d.objectStoreNames.contains('meta')) {
                    d.createObjectStore('meta', { keyPath: 'cle' });
                }

                if (!d.objectStoreNames.contains('listes')) {
                    d.createObjectStore('listes', { keyPath: 'cle' });
                }

                if (!d.objectStoreNames.contains('outbox')) {
                    var o = d.createObjectStore('outbox', { keyPath: 'client_uuid' });
                    o.createIndex('etat', 'etat', { unique: false });
                }
            };

            req.onsuccess = function (e) { db = e.target.result; resolve(db); };
            req.onerror   = function ()  { reject(req.error); };
        });
    }

    function tx(magasin, mode, travail) {
        return ouvrir().then(function (d) {
            return new Promise(function (resolve, reject) {
                var t = d.transaction(magasin, mode);
                var r = travail(t.objectStore(magasin));

                t.oncomplete = function () { resolve(r && r.result !== undefined ? r.result : r); };
                t.onerror    = function () { reject(t.error); };
                t.onabort    = function () { reject(t.error); };
            });
        });
    }

    function lire(magasin, cle) {
        return tx(magasin, 'readonly', function (s) { return s.get(cle); });
    }

    function ecrire(magasin, valeur) {
        return tx(magasin, 'readwrite', function (s) { return s.put(valeur); });
    }

    function tout(magasin) {
        return tx(magasin, 'readonly', function (s) { return s.getAll(); });
    }

    function effacer(magasin, cle) {
        return tx(magasin, 'readwrite', function (s) { return s.delete(cle); });
    }

    // =================================================================
    //  L'IDENTIFIANT DE L'APPAREIL
    // =================================================================

    function uuid() {
        // `randomUUID` n'existe qu'en contexte sécurisé ; le repli tire
        // ses octets de `getRandomValues`, jamais de Math.random — un
        // identifiant devinable serait une clé d'idempotence devinable.
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }

        var o = crypto.getRandomValues(new Uint8Array(16));
        o[6] = (o[6] & 0x0f) | 0x40;
        o[8] = (o[8] & 0x3f) | 0x80;

        var h = Array.prototype.map.call(o, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');

        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16)
             + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }

    function identifiantAppareil() {
        return lire('meta', 'device').then(function (m) {
            if (m && m.uuid) { return m; }

            var neuf = { cle: 'device', uuid: uuid(), device_id: null };

            return ecrire('meta', neuf).then(function () { return neuf; });
        });
    }

    // =================================================================
    //  LE DIALOGUE AVEC LE SERVEUR
    // =================================================================

    function jeton() {
        var m = document.querySelector('meta[name="csrf-token"]');

        return m ? m.getAttribute('content') : '';
    }

    function poster(url, corps) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': jeton(),
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(corps)
        }).then(function (r) {
            return r.json().then(function (j) { return { http: r.status, corps: j }; });
        });
    }

    function enregistrerAppareil() {
        return identifiantAppareil().then(function (m) {
            if (m.device_id) { return m; }

            return poster('/sync/appareil', {
                device_uuid: m.uuid,
                label: (navigator.platform || 'appareil') + ' — ' + (navigator.language || 'fr')
            }).then(function (rep) {
                if (rep.http === 200 && rep.corps && rep.corps.data) {
                    m.device_id = rep.corps.data.device_id;

                    return ecrire('meta', m).then(function () { return m; });
                }

                return m;
            }).catch(function () { return m; });
        });
    }

    // =================================================================
    //  LA FILE
    // =================================================================

    function mettreEnFile(payload) {
        var op = {
            client_uuid:    uuid(),
            entity_type:    'attendance_session',
            operation:      'update',
            payload:        payload,
            client_version: 1,
            client_time:    new Date().toISOString().slice(0, 19).replace('T', ' '),
            etat:           'en_attente',
            cree_le:        Date.now()
        };

        return ecrire('outbox', op).then(function () { return op; });
    }

    function envoyerLaFile() {
        return Promise.all([enregistrerAppareil(), tout('outbox')]).then(function (r) {
            var m   = r[0];
            var ops = (r[1] || []).filter(function (o) { return o.etat === 'en_attente'; });

            if (!m.device_id || ops.length === 0) {
                return { envoyees: 0, conflits: 0, refusees: 0, enCours: 0 };
            }

            return poster('/sync/envoyer', {
                device_id: m.device_id,
                operations: ops.slice(0, 50).map(function (o) {
                    return {
                        client_uuid:    o.client_uuid,
                        entity_type:    o.entity_type,
                        operation:      o.operation,
                        payload:        o.payload,
                        client_version: o.client_version,
                        client_time:    o.client_time
                    };
                })
            }).then(function (rep) {
                if (rep.http !== 200 || !rep.corps || !rep.corps.data) {
                    return { envoyees: 0, conflits: 0, refusees: 0, enCours: 0 };
                }

                var bilan = { envoyees: 0, conflits: 0, refusees: 0, enCours: 0 };

                var suites = rep.corps.data.results.map(function (res) {
                    if (res.status === 'applied') {
                        bilan.envoyees++;

                        // APPLIQUÉE : la ligne locale disparaît. La garder
                        // ferait grossir la base du téléphone sans fin, et
                        // elle contient des noms d'élèves.
                        return effacer('outbox', res.client_uuid);
                    }

                    // AUDIT 8B1 — `pending` N'EST PAS UN VERDICT.
                    //
                    // Le serveur répond ainsi quand un autre envoi de la
                    // MÊME opération est encore en cours de traitement
                    // (deux onglets vident la même file au retour du
                    // réseau). L'issue n'est pas connue : la marquer
                    // « refusée » afficherait un échec à l'enseignant
                    // alors que le serveur est en train d'appliquer.
                    //
                    // On la LAISSE en attente, telle quelle. Le prochain
                    // envoi lira son état définitif.
                    if (res.status === 'pending') {
                        bilan.enCours++;
                        return null;
                    }

                    if (res.status === 'conflict') { bilan.conflits++; } else { bilan.refusees++; }

                    // CONFLIT OU REFUS : on marque, on ne renvoie plus.
                    // Réessayer en boucle une opération que le serveur a
                    // écartée noierait la file et le journal.
                    return lire('outbox', res.client_uuid).then(function (o) {
                        if (!o) { return null; }

                        o.etat    = res.status === 'conflict' ? 'conflit' : 'refusee';
                        o.message = res.message;

                        return ecrire('outbox', o);
                    });
                });

                return Promise.all(suites).then(function () { return bilan; });
            }).catch(function () {
                return { envoyees: 0, conflits: 0, refusees: 0, enCours: 0 };
            });
        });
    }

    // =================================================================
    //  LES LISTES DE CLASSE
    // =================================================================

    function cleListe(classeId) { return 'classe-' + classeId; }

    function memoriserListe(classeId, eleves, vu) {
        return ecrire('listes', {
            cle:     cleListe(classeId),
            classe:  classeId,
            eleves:  eleves,
            vu:      vu,
            date:    Date.now()
        });
    }

    function purgerListesAnciennes() {
        var limite = Date.now() - JOURS_MAX * 86400000;

        return tout('listes').then(function (l) {
            return Promise.all((l || [])
                .filter(function (x) { return x.date < limite; })
                .map(function (x) { return effacer('listes', x.cle); }));
        });
    }

    // =================================================================
    //  LA PURGE
    // =================================================================

    function toutEffacer() {
        return ouvrir().then(function (d) {
            d.close();
            db = null;

            return new Promise(function (resolve) {
                var r = indexedDB.deleteDatabase(BASE);
                r.onsuccess = r.onerror = r.onblocked = function () { resolve(); };
            });
        }).then(function () {
            if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({ type: 'purger-cache' });
            }
        });
    }

    // =================================================================
    //  L'INDICATEUR
    // =================================================================

    function indicateur() {
        var el = document.getElementById('etat-connexion');

        if (!el) { return; }

        tout('outbox').then(function (ops) {
            var attente  = (ops || []).filter(function (o) { return o.etat === 'en_attente'; }).length;
            var conflits = (ops || []).filter(function (o) { return o.etat === 'conflit'; }).length;
            var refus    = (ops || []).filter(function (o) { return o.etat === 'refusee'; }).length;

            var txt = [];
            var cls = 'bg-success-subtle text-success-emphasis';

            if (!navigator.onLine) {
                txt.push('Hors connexion');
                cls = 'bg-warning-subtle text-warning-emphasis';
            } else {
                txt.push('En ligne');
            }

            if (attente)  { txt.push(attente + ' en attente d\'envoi'); }
            if (conflits) { txt.push(conflits + ' en conflit'); cls = 'bg-danger-subtle text-danger-emphasis'; }
            if (refus)    { txt.push(refus + ' refusée(s)'); cls = 'bg-danger-subtle text-danger-emphasis'; }

            el.className   = 'badge ' + cls;
            el.textContent = txt.join(' · ');
        });
    }

    // =================================================================
    //  LE SERVICE WORKER
    // =================================================================

    function installerSW() {
        if (!('serviceWorker' in navigator)) { return Promise.resolve(); }

        return navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .catch(function () { /* pas de mode hors connexion : on continue */ });
    }

    // =================================================================
    //  L'API EXPOSÉE
    // =================================================================

    window.Offline = {
        ouvrir: ouvrir,
        identifiantAppareil: identifiantAppareil,
        enregistrerAppareil: enregistrerAppareil,
        mettreEnFile: mettreEnFile,
        envoyerLaFile: envoyerLaFile,
        memoriserListe: memoriserListe,
        lireListe: function (id) { return lire('listes', cleListe(id)); },
        fileEnAttente: function () {
            return tout('outbox').then(function (o) {
                return (o || []).filter(function (x) { return x.etat === 'en_attente'; });
            });
        },
        toutEffacer: toutEffacer,
        indicateur: indicateur
    };

    // =================================================================
    //  DÉMARRAGE
    // =================================================================

    document.addEventListener('DOMContentLoaded', function () {
        installerSW();
        purgerListesAnciennes();
        indicateur();

        if (navigator.onLine) {
            envoyerLaFile().then(indicateur);
        }

        window.addEventListener('online', function () {
            envoyerLaFile().then(indicateur);
        });

        window.addEventListener('offline', indicateur);

        // LA DÉCONNEXION EFFACE TOUT.
        // Sans cela, la liste des élèves d'une classe resterait sur un
        // téléphone qu'un autre agent utilisera demain.
        document.querySelectorAll('[data-deconnexion]').forEach(function (f) {
            f.addEventListener('submit', function () { toutEffacer(); });
        });
    });
})();
