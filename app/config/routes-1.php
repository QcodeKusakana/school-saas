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

// ---------------------------------------------------------------------
// Phase 8A — Messagerie
//
// `email.manage` est DISTINCTE de `school.edit`, et ce n'est pas du
// zèle : détenir les identifiants du serveur d'envoi, c'est pouvoir
// écrire aux familles AU NOM de l'établissement. Modifier l'adresse
// postale de l'école n'a pas cette portée.
//
// Le JOURNAL est ouvert plus largement (`email.view`) : savoir si un
// message est parti n'est pas savoir comment il part.
// ---------------------------------------------------------------------
route('GET',  '/ecole/emails',          'mail', 'ctrl_mail_settings_form', ['auth', 'school', 'perm:email.manage']);
route('POST', '/ecole/emails',          'mail', 'ctrl_mail_settings_save', ['auth', 'school', 'perm:email.manage']);
route('POST', '/ecole/emails/essai',    'mail', 'ctrl_mail_test',          ['auth', 'school', 'perm:email.manage']);
route('GET',  '/ecole/emails/journal',  'mail', 'ctrl_mail_journal',       ['auth', 'school', 'perm:email.view']);
route('POST', '/ecole/emails/journal/{id}/rejouer', 'mail', 'ctrl_mail_retry', ['auth', 'school', 'perm:email.manage']);

// ---------------------------------------------------------------------
// Phase 7D — Utilisateurs du personnel
//
// LE MODULE LE PLUS DANGEREUX DU PRODUIT : tous les autres décident de
// ce qu'on peut FAIRE, celui-ci décide de QUI PEUT LE FAIRE.
//
// Les cinq permissions `user.*` étaient semées depuis la phase 1 et
// n'avaient aucun écran : les comptes du personnel naissaient en base.
//
// Trois pouvoirs distincts, trois permissions, et ce n'est pas du
// zèle : créer un compte, réinitialiser son mot de passe et le
// désactiver sont trois gestes dont le plus anodin en apparence —
// la réinitialisation — est celui qui permet de prendre la place de
// quelqu'un d'autre.
//
// `user.edit` couvre aussi les rôles : une permission de plus
// n'apporterait rien tant que la hiérarchie des niveaux, portée par
// le service, interdit déjà d'attribuer au-dessus de soi.
// ---------------------------------------------------------------------
route('GET',  '/utilisateurs',            'users', 'ctrl_users_index',          ['auth', 'school', 'perm:user.view']);
route('GET',  '/utilisateurs/nouveau',    'users', 'ctrl_users_create_form',    ['auth', 'school', 'perm:user.create']);
route('POST', '/utilisateurs',            'users', 'ctrl_users_store',          ['auth', 'school', 'perm:user.create']);
route('GET',  '/utilisateurs/{id}',       'users', 'ctrl_users_show',           ['auth', 'school', 'perm:user.view']);
route('POST', '/utilisateurs/{id}',       'users', 'ctrl_users_update',         ['auth', 'school', 'perm:user.edit']);
route('POST', '/utilisateurs/{id}/roles', 'users', 'ctrl_users_set_roles',      ['auth', 'school', 'perm:user.edit']);
route('POST', '/utilisateurs/{id}/etat',  'users', 'ctrl_users_set_status',     ['auth', 'school', 'perm:user.delete']);
route('POST', '/utilisateurs/{id}/archiver',   'users', 'ctrl_users_archive',   ['auth', 'school', 'perm:user.delete']);
route('POST', '/utilisateurs/{id}/mot-de-passe', 'users', 'ctrl_users_reset_password', ['auth', 'school', 'perm:user.reset_password']);
route('POST', '/utilisateurs/{id}/deverrouiller', 'users', 'ctrl_users_unlock', ['auth', 'school', 'perm:user.edit']);

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
route('GET',  '/bulletins/publie/{id}',         'bulletins', 'ctrl_bulletins_published', ['auth', 'school', 'perm:bulletin.generate']);
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
//  ABONNEMENT — phase 7A
//
//  L'école CONSULTE, elle ne change pas d'offre : vendre est le métier
//  de l'éditeur. `platform.subscription.manage` reste au SUPER_ADMIN.
// ---------------------------------------------------------------------
route('GET',  '/abonnement', 'subscriptions', 'ctrl_subscription_show', ['auth', 'school', 'perm:subscription.view']);

// ---------------------------------------------------------------------
//  PORTAIL DES FAMILLES — phase 6A
//
//  CES ROUTES N'EXIGENT AUCUNE PERMISSION, ET C'EST DÉLIBÉRÉ.
//
//  Partout ailleurs, une permission ouvre la porte et le périmètre
//  décide du dossier. Ici, c'est le LIEN DE TUTELLE qui fait les deux :
//  `portal_is_guardian_of()` est la seule clé.
//
//  La conséquence est voulue : aucune permission, si mal accordée
//  soit-elle par un établissement, ne peut ouvrir le portail sur
//  l'enfant d'un autre. Un directeur qui a ses propres enfants dans
//  l'école y accède comme n'importe quel parent — et n'y voit que les
//  siens.
// ---------------------------------------------------------------------
route('GET',  '/espace',                        'portal', 'ctrl_portal_index',    ['auth', 'school']);
route('GET',  '/espace/enfant/{id}',            'portal', 'ctrl_portal_child',    ['auth', 'school']);
route('GET',  '/espace/bulletin/{id}',          'portal', 'ctrl_portal_bulletin', ['auth', 'school']);

// Le rattachement d'un compte de connexion à une fiche tuteur : c'est
// l'école qui agit, depuis le dossier de l'élève. `guardians.user_id`
// existait depuis la phase 3 sans qu'aucun écran ne le remplisse.
route('POST', '/eleves/{id}/tuteurs/{guardianId}/acces', 'portal', 'ctrl_portal_guardian_access', ['auth', 'school', 'perm:user.create']);
route('POST', '/eleves/{id}/acces',                      'portal', 'ctrl_portal_student_access',  ['auth', 'school', 'perm:user.create']);

// RÉGÉNÉRER UN ACCÈS PERDU (audit 6B).
//
// Sans cette route, un mot de passe de portail égaré tuait le compte :
// « mot de passe oublié » exige un courriel qu'un élève n'a pas, et
// aucun écran de gestion des utilisateurs n'existe encore.
route('POST', '/eleves/{id}/acces/{kind}/{targetId}/regenerer', 'portal', 'ctrl_portal_reset_access', ['auth', 'school', 'perm:user.reset_password']);

// ---------------------------------------------------------------------
//  FINANCES — phase 5A : grille tarifaire et dettes
//
//  finance.view est accordée à PARENT depuis la phase 1 : la permission
//  ouvre la porte, le PÉRIMÈTRE appliqué dans chaque contrôleur décide
//  du dossier. La consultation et l'écriture sont séparées — le
//  comptable détient fee.manage, le parent jamais.
//
//  DEUX PERMISSIONS, PAS UNE (recette finances) :
//   · `fee.manage` — tenir la GRILLE et l'affecter. Un document
//     collectif, publié, que toute l'école voit. C'est le travail du
//     comptable.
//   · `fee.waive`  — toucher à la dette d'UNE famille : remise,
//     annulation, rétablissement, réalignement. Rien de cela
//     n'apparaît dans la grille, et le comptable encaisse aussi.
//     Direction uniquement, comme payment.cancel et expense.cancel.
// ---------------------------------------------------------------------
route('GET',  '/finances',                      'finance', 'ctrl_finance_index',       ['auth', 'school', 'perm:finance.view']);
route('GET',  '/finances/frais',                'finance', 'ctrl_finance_fees',        ['auth', 'school', 'perm:fee.manage']);
route('POST', '/finances/frais',                'finance', 'ctrl_finance_fee_save',    ['auth', 'school', 'perm:fee.manage']);
route('POST', '/finances/frais/{id}/realigner', 'finance', 'ctrl_finance_fee_resync',  ['auth', 'school', 'perm:fee.waive']);
route('POST', '/finances/affecter',             'finance', 'ctrl_finance_assign',      ['auth', 'school', 'perm:fee.manage']);
route('POST', '/finances/hors-portee',          'finance', 'ctrl_finance_cancel_out_of_scope', ['auth', 'school', 'perm:fee.waive']);
route('GET',  '/finances/classe/{id}',          'finance', 'ctrl_finance_classroom',   ['auth', 'school', 'perm:finance.view']);
route('GET',  '/finances/eleve/{id}',           'finance', 'ctrl_finance_student',     ['auth', 'school', 'perm:finance.view']);
route('POST', '/finances/ligne/{id}/remise',    'finance', 'ctrl_finance_discount',    ['auth', 'school', 'perm:fee.waive']);
route('POST', '/finances/ligne/{id}/annuler',   'finance', 'ctrl_finance_cancel_line', ['auth', 'school', 'perm:fee.waive']);
route('POST', '/finances/ligne/{id}/retablir',  'finance', 'ctrl_finance_restore_line', ['auth', 'school', 'perm:fee.waive']);

// ---------------------------------------------------------------------
//  CAISSE — phase 5B
//
//  payment.record appartient au COMPTABLE ; payment.cancel n'est
//  accordée qu'à la DIRECTION. Celui qui encaisse n'annule pas son
//  propre reçu.
// ---------------------------------------------------------------------
// Le journal de caisse est un document INTERNE : report.financial, que
// PARENT ne détient pas. Avec payment.view, un tuteur y accédait et y
// lisait « à compter dans la caisse » sur les seuls reçus de son enfant
// — un total juste sur un écran qui ment.
route('GET',  '/finances/journal',              'finance', 'ctrl_finance_cashbook',        ['auth', 'school', 'perm:report.financial']);
route('GET',  '/finances/eleve/{id}/encaisser', 'finance', 'ctrl_finance_pay_form',        ['auth', 'school', 'perm:payment.record']);
route('POST', '/finances/eleve/{id}/encaisser', 'finance', 'ctrl_finance_pay',             ['auth', 'school', 'perm:payment.record']);
route('GET',  '/finances/recu/{id}',            'finance', 'ctrl_finance_receipt',         ['auth', 'school', 'perm:payment.view']);
route('POST', '/finances/recu/{id}/annuler',    'finance', 'ctrl_finance_cancel_payment',  ['auth', 'school', 'perm:payment.cancel']);
route('POST', '/finances/recu/{id}/repartir',   'finance', 'ctrl_finance_reallocate',      ['auth', 'school', 'perm:payment.record']);

// ---------------------------------------------------------------------
//  RECOUVREMENT — phase 5C
//
//  L'état des impayés est un document interne : report.financial, que
//  PARENT ne détient pas. L'avis de situation, lui, est destiné à la
//  famille : finance.view suffit, et le périmètre décide du dossier.
// ---------------------------------------------------------------------
route('GET',  '/finances/impayes',              'finance', 'ctrl_finance_outstanding',     ['auth', 'school', 'perm:report.financial']);
route('POST', '/finances/avances',              'finance', 'ctrl_finance_apply_advances',  ['auth', 'school', 'perm:payment.record']);
route('GET',  '/finances/eleve/{id}/avis',      'finance', 'ctrl_finance_notice',          ['auth', 'school', 'perm:finance.view']);

// ---------------------------------------------------------------------
//  DÉPENSES — phase 5D
//
//  Qui engage la dépense ne l'annule pas : expense.manage appartient au
//  comptable, expense.cancel à la direction seule.
// ---------------------------------------------------------------------
route('GET',  '/finances/depenses',             'finance', 'ctrl_finance_expenses',        ['auth', 'school', 'perm:expense.manage']);
route('POST', '/finances/depenses',             'finance', 'ctrl_finance_expense_save',    ['auth', 'school', 'perm:expense.manage']);
route('POST', '/finances/depenses/{id}/annuler','finance', 'ctrl_finance_expense_cancel',  ['auth', 'school', 'perm:expense.cancel']);

// ---------------------------------------------------------------------
//  PHASE 7B — LA CONSOLE DE L'ÉDITEUR
// ---------------------------------------------------------------------
//
// Ces écrans n'ont PAS le middleware `school` : ils vivent hors de tout
// établissement. C'est leur nature — l'éditeur regarde toutes les
// écoles à la fois — et c'est pourquoi chaque lecture passe par
// `platform_scope()`, qui exige un compte de la plateforme ET la
// permission. Voir app/core/platform.php.
//
// La permission est posée ici EN PLUS de celle du périmètre : une
// défense qui se répète à deux niveaux survit à la disparition de l'un
// des deux.

route('GET',  '/plateforme/ecoles',       'platform', 'ctrl_platform_schools',       ['auth', 'perm:platform.school.view']);
route('GET',  '/plateforme/ecoles/{id}',  'platform', 'ctrl_platform_school_show',   ['auth', 'perm:platform.school.view']);
route('POST', '/plateforme/ecoles/{id}/ouvrir', 'platform', 'ctrl_platform_enter_school', ['auth', 'perm:platform.school.view']);
route('POST', '/plateforme/quitter',      'platform', 'ctrl_platform_leave_school',  ['auth', 'perm:platform.school.view']);

route('GET',  '/plateforme/abonnements',  'platform', 'ctrl_platform_subscriptions', ['auth', 'perm:platform.subscription.manage']);
route('POST', '/plateforme/ecoles/{id}/offre',   'platform', 'ctrl_platform_change_plan',        ['auth', 'perm:platform.subscription.manage']);
route('POST', '/plateforme/ecoles/{id}/statut',  'platform', 'ctrl_platform_subscription_status', ['auth', 'perm:platform.subscription.manage']);

// --- Facturation SaaS (7B2) ------------------------------------------
//
// `platform.billing.manage` est DISTINCTE de
// `platform.subscription.manage` : négocier une offre et constater
// qu'elle est payée sont deux pouvoirs de nature différente. La leçon
// de la recette Finances vaut ici — une permission qui recouvre deux
// pouvoirs finit toujours par accorder le plus dangereux des deux.

route('GET',  '/plateforme/soldes', 'platform', 'ctrl_platform_billing_index',  ['auth', 'perm:platform.billing.manage']);
route('GET',  '/plateforme/ecoles/{id}/facturation', 'platform', 'ctrl_platform_billing_school', ['auth', 'perm:platform.billing.manage']);
route('POST', '/plateforme/ecoles/{id}/versement',   'platform', 'ctrl_platform_billing_record', ['auth', 'perm:platform.billing.manage']);
route('POST', '/plateforme/ecoles/{id}/versement/{paymentId}/confirmer', 'platform', 'ctrl_platform_billing_confirm', ['auth', 'perm:platform.billing.manage']);
route('POST', '/plateforme/ecoles/{id}/versement/{paymentId}/annuler',   'platform', 'ctrl_platform_billing_cancel',  ['auth', 'perm:platform.billing.manage']);

// ---------------------------------------------------------------------
// Les modules des phases suivantes viendront s'ajouter ici :
//
//   Phase 7C  — Mobile Money
//   Phase 9   — journal global    /plateforme/journal
// ---------------------------------------------------------------------
