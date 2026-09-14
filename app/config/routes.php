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
// Phase 4A — Enseignants et répartition des branches
//
// La répartition est rattachée à la CLASSE et non à l'enseignant : le
// préfet des études raisonne classe par classe, branche par branche.
// Elle exige teacher.assign, distincte de teacher.manage : confier une
// branche n'est pas modifier un dossier de personnel.
// ---------------------------------------------------------------------
route('GET',  '/enseignants',          'teachers', 'ctrl_teachers_index',         ['auth', 'school', 'perm:teacher.view']);
route('GET',  '/enseignants/nouveau',  'teachers', 'ctrl_teachers_create_form',   ['auth', 'school', 'perm:teacher.manage']);
route('POST', '/enseignants',          'teachers', 'ctrl_teachers_store',         ['auth', 'school', 'perm:teacher.manage']);
route('GET',  '/enseignants/{id}',     'teachers', 'ctrl_teachers_show',          ['auth', 'school', 'perm:teacher.view']);
route('POST', '/enseignants/{id}',     'teachers', 'ctrl_teachers_update',        ['auth', 'school', 'perm:teacher.manage']);
route('POST', '/enseignants/{id}/statut', 'teachers', 'ctrl_teachers_change_status', ['auth', 'school', 'perm:teacher.manage']);

route('GET',  '/classes/{id}/repartition',                  'teachers', 'ctrl_teachers_classroom_grid', ['auth', 'school', 'perm:teacher.view']);
route('POST', '/classes/{id}/repartition',                  'teachers', 'ctrl_teachers_assign',         ['auth', 'school', 'perm:teacher.assign']);
route('POST', '/classes/{id}/repartition/{assignment_id}/retirer', 'teachers', 'ctrl_teachers_unassign', ['auth', 'school', 'perm:teacher.assign']);
route('POST', '/classes/{id}/titulaire',                    'teachers', 'ctrl_teachers_set_main',       ['auth', 'school', 'perm:teacher.assign']);

// ---------------------------------------------------------------------
// Phase 4B — Notes
//
// La grille de saisie est adressée par (classe, branche, période) :
// c'est le geste réel de l'enseignant, qui remplit une colonne entière.
// Les trois identifiants sont recoupés dans grades_sheet_context() —
// la branche doit appartenir au programme de la classe, la période à
// son année.
//
// perm:grade.enter ne suffit jamais : teachers_can_teach() décide.
// ---------------------------------------------------------------------
route('GET',  '/notes',                'grades', 'ctrl_grades_index',     ['auth', 'school', 'perm:grade.view']);
route('GET',  '/notes/classe/{id}',    'grades', 'ctrl_grades_classroom', ['auth', 'school', 'perm:grade.view']);

route('GET',  '/notes/classe/{id}/branche/{subject_id}/periode/{period_id}', 'grades', 'ctrl_grades_sheet', ['auth', 'school', 'perm:grade.view']);
route('POST', '/notes/classe/{id}/branche/{subject_id}/periode/{period_id}', 'grades', 'ctrl_grades_store', ['auth', 'school', 'perm:grade.enter']);

route('POST', '/notes/periodes/{id}/verrou', 'grades', 'ctrl_grades_lock_period', ['auth', 'school', 'perm:grade.validate']);

// ---------------------------------------------------------------------
// Phase 4C — Bulletins
//
// Le bulletin d'un élève est adressé par son INSCRIPTION : elle porte à
// la fois l'élève, l'année et la classe — exactement le périmètre du
// document. Son accès suit le périmètre de l'ÉLÈVE et non celui de la
// classe, pour qu'un parent puisse lire le bulletin de son enfant sans
// voir le reste du groupe.
// ---------------------------------------------------------------------
route('GET',  '/bulletins/classe/{id}',         'bulletins', 'ctrl_bulletins_classroom', ['auth', 'school', 'perm:bulletin.generate']);
route('POST', '/bulletins/classe/{id}/publier', 'bulletins', 'ctrl_bulletins_publish',   ['auth', 'school', 'perm:bulletin.publish']);

route('GET',  '/bulletins/parametres',          'bulletins', 'ctrl_bulletins_settings_form', ['auth', 'school', 'perm:school.edit']);
route('POST', '/bulletins/parametres',          'bulletins', 'ctrl_bulletins_settings',      ['auth', 'school', 'perm:school.edit']);

route('GET',  '/bulletins/{id}',                'bulletins', 'ctrl_bulletins_show',     ['auth', 'school', 'perm:bulletin.generate']);
route('POST', '/bulletins/{id}/decision',       'bulletins', 'ctrl_bulletins_decide',   ['auth', 'school', 'perm:bulletin.publish']);

// ---------------------------------------------------------------------
//  PRÉSENCES — phase 4D
//
//  attendance.view est accordée à PARENT et ELEVE depuis la phase 1.
//  La permission ouvre donc la porte ; c'est le PÉRIMÈTRE, appliqué dans
//  chaque contrôleur, qui décide de ce qu'on y voit.
// ---------------------------------------------------------------------
route('GET',  '/presences',                     'attendance', 'ctrl_attendance_index',    ['auth', 'school', 'perm:attendance.view']);
route('GET',  '/presences/classe/{id}',         'attendance', 'ctrl_attendance_register', ['auth', 'school', 'perm:attendance.view']);
route('POST', '/presences/classe/{id}',         'attendance', 'ctrl_attendance_save',     ['auth', 'school', 'perm:attendance.record']);
route('POST', '/presences/absence/{id}',        'attendance', 'ctrl_attendance_justify',  ['auth', 'school', 'perm:attendance.justify']);
route('POST', '/presences/registre/{id}/verrou', 'attendance', 'ctrl_attendance_lock',    ['auth', 'school', 'perm:attendance.justify']);

// ---------------------------------------------------------------------
//  FINANCES — phase 5A : grille tarifaire et dettes
//
//  finance.view est accordée à PARENT depuis la phase 1 : la permission
//  ouvre la porte, le PÉRIMÈTRE appliqué dans chaque contrôleur décide
//  du dossier. La consultation et l'écriture sont séparées — le
//  comptable détient fee.manage, le parent jamais.
// ---------------------------------------------------------------------
route('GET',  '/finances',                      'finance', 'ctrl_finance_index',       ['auth', 'school', 'perm:finance.view']);
route('GET',  '/finances/frais',                'finance', 'ctrl_finance_fees',        ['auth', 'school', 'perm:fee.manage']);
route('POST', '/finances/frais',                'finance', 'ctrl_finance_fee_save',    ['auth', 'school', 'perm:fee.manage']);
route('POST', '/finances/frais/{id}/realigner', 'finance', 'ctrl_finance_fee_resync',  ['auth', 'school', 'perm:fee.manage']);
route('POST', '/finances/affecter',             'finance', 'ctrl_finance_assign',      ['auth', 'school', 'perm:fee.manage']);
route('GET',  '/finances/classe/{id}',          'finance', 'ctrl_finance_classroom',   ['auth', 'school', 'perm:finance.view']);
route('GET',  '/finances/eleve/{id}',           'finance', 'ctrl_finance_student',     ['auth', 'school', 'perm:finance.view']);
route('POST', '/finances/ligne/{id}/remise',    'finance', 'ctrl_finance_discount',    ['auth', 'school', 'perm:fee.manage']);
route('POST', '/finances/ligne/{id}/annuler',   'finance', 'ctrl_finance_cancel_line', ['auth', 'school', 'perm:fee.manage']);
route('POST', '/finances/ligne/{id}/retablir',  'finance', 'ctrl_finance_restore_line', ['auth', 'school', 'perm:fee.manage']);

// ---------------------------------------------------------------------
// Les modules des phases suivantes viendront s'ajouter ici :
//
//   Phase 5B — encaissements /finances/caisse/...
//   Phase 6 — portails      /parent/..., /eleve/...
//   Phase 7 — plateforme    /plateforme/...
// ---------------------------------------------------------------------
