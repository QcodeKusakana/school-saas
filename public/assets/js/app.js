/**
 * SCHOOL SAAS RDC — JavaScript de l'interface.
 *
 * Principe : l'application doit rester utilisable sans JavaScript pour
 * tout ce qui est essentiel. Ce fichier n'apporte que du confort —
 * navigation mobile, affichage du mot de passe, confirmation d'action,
 * requêtes AJAX avec jeton CSRF.
 *
 * Aucune dépendance en dehors de Bootstrap (déjà chargé).
 */

(function () {
    'use strict';

    /* =================================================================
       Utilitaires
       ================================================================= */

    const $  = (selector, scope) => (scope || document).querySelector(selector);
    const $$ = (selector, scope) => Array.from((scope || document).querySelectorAll(selector));

    /** Jeton CSRF, lu depuis la balise meta du layout. */
    function csrfToken() {
        const meta = $('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /* =================================================================
       Navigation mobile
       ================================================================= */

    function initSidebar() {
        const sidebar = $('[data-sidebar]');
        const overlay = $('[data-sidebar-overlay]');

        if (!sidebar || !overlay) {
            return;
        }

        const open = () => {
            sidebar.classList.add('is-open');
            overlay.hidden = false;
            // Empêche le défilement de la page derrière le tiroir.
            document.body.style.overflow = 'hidden';
        };

        const close = () => {
            sidebar.classList.remove('is-open');
            overlay.hidden = true;
            document.body.style.overflow = '';
        };

        $$('[data-sidebar-toggle]').forEach((el) => el.addEventListener('click', open));
        $$('[data-sidebar-close]').forEach((el) => el.addEventListener('click', close));
        overlay.addEventListener('click', close);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                close();
            }
        });

        // Le tiroir n'a plus lieu d'être si l'écran repasse en grande taille.
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 992) {
                close();
            }
        });
    }

    /* =================================================================
       Affichage du mot de passe
       ================================================================= */

    function initPasswordToggles() {
        $$('[data-toggle-password]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = $(button.dataset.togglePassword);

                if (!input) {
                    return;
                }

                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';

                const icon = $('i', button);
                if (icon) {
                    icon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
                }

                button.setAttribute(
                    'aria-label',
                    isHidden ? 'Masquer le mot de passe' : 'Afficher le mot de passe'
                );
            });
        });
    }

    /* =================================================================
       Soumission automatique d'un filtre
       Usage : <select name="annee" data-auto-submit>

       Ces sélecteurs portaient un onchange="this.form.submit()" écrit
       directement dans le HTML. La politique de sécurité du contenu
       (script-src 'self' 'nonce-…', sans unsafe-inline) interdit les
       gestionnaires en attribut : le code n'était JAMAIS exécuté, et
       comme ces formulaires n'ont pas de bouton d'envoi, changer
       d'année scolaire était tout simplement impossible.

       Le comportement vit donc ici, dans un fichier servi par le site
       et chargé avec le nonce. Un bouton de secours est ajouté pour les
       navigateurs sans JavaScript.
       ================================================================= */

    function initAutoSubmit() {
        document.querySelectorAll('[data-auto-submit]').forEach((element) => {
            const form = element.form;

            if (!form) {
                return;
            }

            element.addEventListener('change', () => form.submit());

            // Sans JavaScript, le bouton reste visible et fonctionnel.
            const fallback = form.querySelector('[data-auto-submit-fallback]');

            if (fallback) {
                fallback.hidden = true;
            }
        });
    }

    /* =================================================================
       Confirmation avant action destructive
       Usage : <button data-confirm="Supprimer cet élève ?">
       ================================================================= */

    function initConfirmations() {
        document.addEventListener('submit', (event) => {
            const form = event.target;
            const message = form.dataset ? form.dataset.confirm : null;

            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });

        $$('[data-confirm]').forEach((el) => {
            if (el.tagName === 'A') {
                el.addEventListener('click', (event) => {
                    if (!window.confirm(el.dataset.confirm)) {
                        event.preventDefault();
                    }
                });
            }
        });
    }

    /* =================================================================
       Protection contre la double soumission
       Un clic répété sur « Enregistrer » créerait deux inscriptions
       ou deux paiements identiques.
       ================================================================= */

    function initSubmitGuard() {
        document.addEventListener('submit', (event) => {
            const form = event.target;

            if (form.dataset.noGuard !== undefined) {
                return;
            }

            const button = form.querySelector('button[type="submit"], input[type="submit"]');

            if (!button || button.disabled) {
                return;
            }

            // Court délai : laisse le navigateur transmettre les données
            // du bouton avant de le désactiver.
            window.setTimeout(() => {
                button.disabled = true;
                const original = button.innerHTML;
                button.dataset.originalLabel = original;
                button.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
                    + 'Traitement…';
            }, 10);

            // Filet de sécurité : réactive le bouton si la page n'a pas
            // changé au bout de 12 secondes (erreur réseau).
            window.setTimeout(() => {
                if (button.disabled && button.dataset.originalLabel) {
                    button.disabled = false;
                    button.innerHTML = button.dataset.originalLabel;
                }
            }, 12000);
        });
    }

    /* =================================================================
       Indicateur de connexion réseau
       Préparation de la phase 8 (mode hors connexion).
       ================================================================= */

    function initNetworkStatus() {
        const indicator = $('[data-net-status]');

        if (!indicator) {
            return;
        }

        const update = () => {
            indicator.hidden = navigator.onLine;
        };

        window.addEventListener('online', update);
        window.addEventListener('offline', update);
        update();
    }

    /* =================================================================
       Fermeture automatique des messages de succès
       Les erreurs, elles, restent affichées jusqu'à action de l'utilisateur.
       ================================================================= */

    function initFlashAutoDismiss() {
        $$('.flash-stack .alert-success').forEach((alert) => {
            window.setTimeout(() => {
                if (window.bootstrap && window.bootstrap.Alert) {
                    window.bootstrap.Alert.getOrCreateInstance(alert).close();
                }
            }, 6000);
        });
    }

    /* =================================================================
       Requête AJAX avec jeton CSRF
       Exposée en global pour les modules des phases suivantes.
       ================================================================= */

    async function request(url, options) {
        const settings = Object.assign({ method: 'GET', headers: {} }, options || {});

        settings.headers = Object.assign({
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken(),
            'Accept': 'application/json'
        }, settings.headers);

        if (settings.body && !(settings.body instanceof FormData)) {
            settings.headers['Content-Type'] = 'application/json';
            settings.body = JSON.stringify(settings.body);
        }

        const response = await fetch(url, settings);
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw Object.assign(
                new Error(payload.message || 'Une erreur est survenue.'),
                { status: response.status, errors: payload.errors || {} }
            );
        }

        return payload;
    }

    /* =================================================================
       Démarrage
       ================================================================= */

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initPasswordToggles();
        initAutoSubmit();
        initConfirmations();
        initSubmitGuard();
        initNetworkStatus();
        initFlashAutoDismiss();
    });

    // Espace de noms du produit, pour les modules à venir.
    window.School = { request: request, csrfToken: csrfToken };
})();
