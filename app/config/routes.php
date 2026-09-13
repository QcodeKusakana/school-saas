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
// Phase 2 — Référentiel scolaire
// ---------------------------------------------------------------------
route('GET',  '/referentiel',                    'curriculum', 'ctrl_curriculum_index',            ['auth', 'school', 'perm:curriculum.view']);
route('POST', '/referentiel/importer',           'curriculum', 'ctrl_curriculum_import',           ['auth', 'school', 'perm:curriculum.manage']);
route('POST', '/referentiel/periodes',           'curriculum', 'ctrl_curriculum_create_periods',   ['auth', 'school', 'perm:curriculum.manage']);

// Branches
route('GET',  '/referentiel/branches',           'curriculum', 'ctrl_curriculum_subjects',         ['auth', 'school', 'perm:curriculum.view']);
route('POST', '/referentiel/branches',           'curriculum', 'ctrl_curriculum_store_subject',    ['auth', 'school', 'perm:curriculum.manage']);
route('POST', '/referentiel/branches/{id}',      'curriculum', 'ctrl_curriculum_update_subject',   ['auth', 'school', 'perm:curriculum.manage']);

// Sections et options
route('GET',  '/referentiel/sections',           'curriculum', 'ctrl_curriculum_sections',         ['auth', 'school', 'perm:curriculum.view']);
route('POST', '/referentiel/sections/{id}/etat', 'curriculum', 'ctrl_curriculum_toggle_section',   ['auth', 'school', 'perm:curriculum.manage']);
route('POST', '/referentiel/options/{id}/etat',  'curriculum', 'ctrl_curriculum_toggle_option',    ['auth', 'school', 'perm:curriculum.manage']);
route('GET',  '/referentiel/sections/{id}/options', 'curriculum', 'ctrl_curriculum_section_options', ['auth', 'school', 'perm:curriculum.view']);

// Programmes
route('GET',  '/referentiel/programmes',              'curriculum', 'ctrl_curriculum_programs',           ['auth', 'school', 'perm:curriculum.view']);
route('POST', '/referentiel/programmes',              'curriculum', 'ctrl_curriculum_store_program',      ['auth', 'school', 'perm:curriculum.manage']);
route('GET',  '/referentiel/programmes/{id}',         'curriculum', 'ctrl_curriculum_show_program',       ['auth', 'school', 'perm:curriculum.view']);
route('POST', '/referentiel/programmes/{id}/remplir', 'curriculum', 'ctrl_curriculum_fill_program',       ['auth', 'school', 'perm:curriculum.manage']);
route('POST', '/referentiel/programmes/{id}/branches','curriculum', 'ctrl_curriculum_add_subject',        ['auth', 'school', 'perm:curriculum.manage']);
route('POST', '/referentiel/programmes/{id}/maxima',  'curriculum', 'ctrl_curriculum_update_subjects',    ['auth', 'school', 'perm:curriculum.manage']);
route('POST', '/referentiel/programmes/{id}/activer', 'curriculum', 'ctrl_curriculum_activate_program',   ['auth', 'school', 'perm:curriculum.manage']);
route('POST', '/referentiel/programmes/{id}/dupliquer','curriculum','ctrl_curriculum_duplicate_program',  ['auth', 'school', 'perm:curriculum.manage']);
route('POST', '/referentiel/programmes/{id}/branches/{subjectId}/retirer', 'curriculum', 'ctrl_curriculum_remove_subject', ['auth', 'school', 'perm:curriculum.manage']);

// ---------------------------------------------------------------------
// Phase 3 — Classes
// ---------------------------------------------------------------------
route('GET',  '/classes',            'classrooms', 'ctrl_classrooms_index',      ['auth', 'school', 'perm:classroom.view']);
route('POST', '/classes',            'classrooms', 'ctrl_classrooms_store',      ['auth', 'school', 'perm:classroom.manage']);
route('GET',  '/classes/{id}',       'classrooms', 'ctrl_classrooms_show',       ['auth', 'school', 'perm:classroom.view']);
route('POST', '/classes/{id}',       'classrooms', 'ctrl_classrooms_update',     ['auth', 'school', 'perm:classroom.manage']);
route('POST', '/classes/salles',     'classrooms', 'ctrl_classrooms_store_room', ['auth', 'school', 'perm:classroom.manage']);

// ---------------------------------------------------------------------
// Phase 3 — Élèves
// ---------------------------------------------------------------------
route('GET',  '/eleves',             'students', 'ctrl_students_index',        ['auth', 'school', 'perm:student.view']);
route('GET',  '/eleves/nouveau',     'students', 'ctrl_students_create_form',  ['auth', 'school', 'perm:student.create']);
route('POST', '/eleves',             'students', 'ctrl_students_store',        ['auth', 'school', 'perm:student.create']);
route('GET',  '/eleves/recherche',   'students', 'ctrl_students_quick_search', ['auth', 'school', 'perm:student.view']);
route('GET',  '/eleves/{id}',        'students', 'ctrl_students_show',         ['auth', 'school', 'perm:student.view']);
route('POST', '/eleves/{id}',        'students', 'ctrl_students_update',       ['auth', 'school', 'perm:student.edit']);

route('POST', '/eleves/{id}/reinscription', 'students', 'ctrl_students_re_enroll',    ['auth', 'school', 'perm:enrollment.manage']);
route('POST', '/eleves/{id}/affectation',   'students', 'ctrl_students_assign',       ['auth', 'school', 'perm:classroom.assign']);
route('POST', '/eleves/{id}/tuteurs',       'students', 'ctrl_students_add_guardian', ['auth', 'school', 'perm:student.edit']);
route('POST', '/eleves/{id}/tuteurs/{link_id}/retirer', 'students', 'ctrl_students_detach_guardian', ['auth', 'school', 'perm:student.edit']);
route('POST', '/eleves/{id}/orientation',   'students', 'ctrl_students_orient',        ['auth', 'school', 'perm:orientation.manage']);
route('POST', '/eleves/{id}/statut',        'students', 'ctrl_students_change_status', ['auth', 'school', 'perm:student.edit']);

// ---------------------------------------------------------------------
// Les modules des phases suivantes viendront s'ajouter ici :
//
//   Phase 4 — pédagogie     /notes/..., /presences/...
//   Phase 5 — finances      /finances/...
//   Phase 6 — portails      /parent/..., /eleve/...
//   Phase 7 — plateforme    /plateforme/...
// ---------------------------------------------------------------------
