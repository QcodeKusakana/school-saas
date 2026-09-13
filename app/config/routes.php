<?php
/**
 * Table de routes de l'application.
 *
 * Cette table est la référence unique des URL du produit. Elle sert aussi
 * de contrôle de sécurité : une route sans middleware 'auth' est publique,
 * et cela se voit immédiatement à la lecture.
 *
 * Format :
 *   route(MÉTHODE, CHEMIN, MODULE, FONCTION, [MIDDLEWARES])
 *
 * Middlewares disponibles :
 *   guest           utilisateur NON connecté uniquement
 *   auth            utilisateur connecté
 *   perm:<code>     permission requise
 *   platform        super administrateur du SaaS
 *   school          un établissement doit être sélectionné
 *
 * Les URL sont en français : ce sont des utilisateurs francophones qui
 * les lisent et les partagent.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Public / authentification
// ---------------------------------------------------------------------
route('GET',  '/',                      'auth', 'ctrl_auth_root',            []);
route('GET',  '/login',                 'auth', 'ctrl_auth_login_form',      ['guest']);
route('POST', '/login',                 'auth', 'ctrl_auth_login',           ['guest']);
route('POST', '/logout',                'auth', 'ctrl_auth_logout',          ['auth']);

route('GET',  '/mot-de-passe/oublie',   'auth', 'ctrl_auth_forgot_form',     ['guest']);
route('POST', '/mot-de-passe/oublie',   'auth', 'ctrl_auth_forgot',          ['guest']);
route('GET',  '/mot-de-passe/reinitialiser/{token}', 'auth', 'ctrl_auth_reset_form', ['guest']);
route('POST', '/mot-de-passe/reinitialiser',         'auth', 'ctrl_auth_reset',      ['guest']);

// Changement de mot de passe imposé à la première connexion.
route('GET',  '/mot-de-passe/changer',  'auth', 'ctrl_auth_change_form',     ['auth']);
route('POST', '/mot-de-passe/changer',  'auth', 'ctrl_auth_change',          ['auth']);

// ---------------------------------------------------------------------
// Tableau de bord
// ---------------------------------------------------------------------
route('GET',  '/tableau-de-bord',       'dashboard', 'ctrl_dashboard_index', ['auth']);

// ---------------------------------------------------------------------
// Les modules des phases suivantes viendront s'ajouter ici :
//
//   Phase 2 — référentiel   /referentiel/...
//   Phase 3 — élèves        /eleves/...
//   Phase 4 — pédagogie     /notes/..., /presences/...
//   Phase 5 — finances      /finances/...
//   Phase 6 — portails      /parent/..., /eleve/...
//   Phase 7 — plateforme    /plateforme/...
// ---------------------------------------------------------------------
