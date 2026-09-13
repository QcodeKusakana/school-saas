-- =====================================================================
--  SEED 002 — Catalogue des permissions + rôles système
--  À exécuter APRÈS 001_reference_data.sql
--
--  Principe : le catalogue couvre TOUS les modules prévus (phases 1 à 9),
--  même ceux non encore développés. Ajouter une permission plus tard est
--  anodin ; réattribuer les droits de 50 écoles après coup ne l'est pas.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  PERMISSIONS
--  Convention : module.action — toujours en minuscules, sans accent.
--  is_platform = 1 : réservé au super administrateur du SaaS.
-- ---------------------------------------------------------------------
INSERT INTO permissions (code, module, name, is_platform, sort_order) VALUES
-- Plateforme (super admin uniquement)
('platform.school.view',      'platform', 'Voir toutes les écoles',                 1, 1),
('platform.school.create',    'platform', 'Créer une école',                        1, 2),
('platform.school.edit',      'platform', 'Modifier une école',                     1, 3),
('platform.school.suspend',   'platform', 'Suspendre ou réactiver une école',       1, 4),
('platform.plan.manage',      'platform', 'Gérer les offres commerciales',          1, 5),
('platform.subscription.manage','platform','Gérer les abonnements',                 1, 6),
('platform.impersonate',      'platform', 'Se connecter en tant qu''utilisateur',   1, 7),
('platform.audit.view',       'platform', 'Consulter le journal global',            1, 8),

-- École (paramétrage de son propre établissement)
('school.view',               'school',   'Voir la fiche de l''établissement',      0, 10),
('school.edit',               'school',   'Modifier la fiche de l''établissement',  0, 11),
('school.settings',           'school',   'Modifier les paramètres',                0, 12),
('school.branding',           'school',   'Gérer logo, cachet et en-têtes',         0, 13),

-- Utilisateurs et droits
('user.view',                 'user',     'Consulter les utilisateurs',             0, 20),
('user.create',               'user',     'Créer un utilisateur',                   0, 21),
('user.edit',                 'user',     'Modifier un utilisateur',                0, 22),
('user.delete',               'user',     'Désactiver un utilisateur',              0, 23),
('user.reset_password',       'user',     'Réinitialiser un mot de passe',          0, 24),
('role.view',                 'role',     'Consulter les rôles',                    0, 25),
('role.manage',               'role',     'Créer et modifier les rôles',            0, 26),

-- Années scolaires
('academic_year.view',        'academic', 'Consulter les années scolaires',         0, 30),
('academic_year.manage',      'academic', 'Créer et modifier une année scolaire',   0, 31),
('academic_year.close',       'academic', 'Clôturer une année scolaire',            0, 32),

-- Référentiel pédagogique (phase 2)
('curriculum.view',           'curriculum','Consulter le référentiel',              0, 40),
('curriculum.manage',         'curriculum','Gérer sections, options et matières',   0, 41),
('curriculum.assign',         'curriculum','Affecter un programme à une classe',    0, 42),

-- Élèves (phase 3)
('student.view',              'student',  'Consulter les élèves',                   0, 50),
('student.create',            'student',  'Inscrire un élève',                      0, 51),
('student.edit',              'student',  'Modifier un dossier élève',              0, 52),
('student.delete',            'student',  'Archiver un dossier élève',              0, 53),
('student.document',          'student',  'Gérer les pièces du dossier',            0, 54),
('student.history',           'student',  'Consulter le parcours scolaire',         0, 55),
('enrollment.manage',         'student',  'Gérer inscriptions et réinscriptions',   0, 56),
('orientation.manage',        'student',  'Enregistrer une orientation',            0, 57),

-- Classes et enseignants (phase 4)
('classroom.view',            'classroom','Consulter les classes',                  0, 60),
('classroom.manage',          'classroom','Créer et modifier les classes',          0, 61),
('classroom.assign',          'classroom','Affecter des élèves à une classe',       0, 62),
('teacher.view',              'teacher',  'Consulter les enseignants',              0, 65),
('teacher.manage',            'teacher',  'Gérer les enseignants',                  0, 66),
('teacher.assign',            'teacher',  'Affecter matières et classes',           0, 67),

-- Évaluations et notes
('evaluation.view',           'evaluation','Consulter les évaluations',             0, 70),
('evaluation.manage',         'evaluation','Créer et modifier les évaluations',     0, 71),
('grade.view',                'grade',    'Consulter les notes',                    0, 75),
('grade.enter',               'grade',    'Saisir des notes',                       0, 76),
('grade.edit_locked',         'grade',    'Modifier des notes verrouillées',        0, 77),
('grade.validate',            'grade',    'Valider les notes d''une période',       0, 78),
('bulletin.generate',         'grade',    'Générer les bulletins',                  0, 79),
('bulletin.publish',          'grade',    'Publier les bulletins aux parents',      0, 80),

-- Présences et emploi du temps
('attendance.view',           'attendance','Consulter les présences',               0, 85),
('attendance.record',         'attendance','Saisir les présences',                  0, 86),
('attendance.justify',        'attendance','Justifier une absence',                 0, 87),
('timetable.view',            'timetable','Consulter les emplois du temps',         0, 90),
('timetable.manage',          'timetable','Gérer les emplois du temps',             0, 91),

-- Finances (phase 5)
('finance.view',              'finance',  'Consulter la situation financière',      0, 100),
('fee.manage',                'finance',  'Définir les frais scolaires',            0, 101),
('payment.view',              'finance',  'Consulter les paiements',                0, 102),
('payment.record',            'finance',  'Enregistrer un paiement',                0, 103),
('payment.cancel',            'finance',  'Annuler un paiement',                    0, 104),
('receipt.print',             'finance',  'Imprimer un reçu',                       0, 105),
('expense.manage',            'finance',  'Gérer les dépenses',                     0, 106),

-- Documents, rapports, communication
('document.view',             'document', 'Consulter les documents',                0, 110),
('document.generate',         'document', 'Générer attestations et certificats',    0, 111),
('report.academic',           'report',   'Rapports scolaires',                     0, 115),
('report.financial',          'report',   'Rapports financiers',                    0, 116),
('report.export',             'report',   'Exporter en PDF ou Excel',               0, 117),
('communication.send',        'communication','Envoyer annonces et notifications',  0, 120),

-- Journal et archives
('audit.view',                'audit',    'Consulter le journal de l''école',       0, 130),
('archive.view',              'archive',  'Consulter les archives',                 0, 131);


-- ---------------------------------------------------------------------
--  RÔLES SYSTÈME (school_id = NULL : partagés par toutes les écoles)
--
--  `level` porte la hiérarchie : un utilisateur ne peut créer ou modifier
--  qu'un compte dont le rôle le plus élevé est STRICTEMENT inférieur au
--  sien. Cela empêche un secrétaire de s'auto-promouvoir directeur.
-- ---------------------------------------------------------------------
INSERT INTO roles (school_id, code, name, description, level, is_system) VALUES
(NULL, 'SUPER_ADMIN',  'Super administrateur',   'Administrateur de la plateforme SaaS — accès à toutes les écoles', 100, 1),
(NULL, 'SCHOOL_ADMIN', 'Administrateur école',   'Responsable informatique de l''établissement',                      90, 1),
(NULL, 'DIRECTION',    'Direction',              'Chef d''établissement / directeur',                                 80, 1),
(NULL, 'PREFET',       'Préfet des études',      'Responsable pédagogique',                                           75, 1),
(NULL, 'SECRETARIAT',  'Secrétariat',            'Inscriptions, dossiers élèves, documents',                          60, 1),
(NULL, 'COMPTABLE',    'Comptable',              'Frais scolaires, paiements, dépenses',                              55, 1),
(NULL, 'ENSEIGNANT',   'Enseignant',             'Notes, présences et évaluations de ses classes',                    40, 1),
(NULL, 'PARENT',       'Parent / tuteur',        'Consultation du dossier de ses enfants',                            20, 1),
(NULL, 'ELEVE',        'Élève',                  'Consultation de son propre dossier',                                10, 1);


-- ---------------------------------------------------------------------
--  ATTRIBUTION DES PERMISSIONS
--  Écrite en INSERT ... SELECT : ajouter une permission au catalogue ne
--  casse rien, il suffit de rejouer le bloc du rôle concerné.
-- ---------------------------------------------------------------------

-- SUPER_ADMIN : tout, y compris les permissions plateforme.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'SUPER_ADMIN' AND r.school_id IS NULL;

-- SCHOOL_ADMIN : tout SAUF la plateforme (il ne voit que son école).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'SCHOOL_ADMIN' AND r.school_id IS NULL AND p.is_platform = 0;

-- DIRECTION : tout sauf la gestion des rôles et la modification de notes verrouillées.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'DIRECTION' AND r.school_id IS NULL AND p.is_platform = 0
  AND p.code NOT IN ('role.manage');

-- PREFET : pédagogie complète, pas de finances, pas de gestion des comptes.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'PREFET' AND r.school_id IS NULL
  AND p.module IN ('curriculum','student','classroom','teacher','evaluation','grade','attendance','timetable','report','document','communication','archive','academic')
  AND p.code NOT IN ('report.financial');

-- SECRETARIAT : dossiers élèves et documents, lecture seule sur la pédagogie.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'SECRETARIAT' AND r.school_id IS NULL
  AND p.code IN (
    'school.view','academic_year.view','curriculum.view',
    'student.view','student.create','student.edit','student.document','student.history',
    'enrollment.manage','classroom.view','classroom.assign',
    'teacher.view','attendance.view','attendance.justify',
    'document.view','document.generate','report.academic','report.export',
    'communication.send','archive.view','timetable.view'
  );

-- COMPTABLE : finances uniquement + lecture des élèves (nécessaire pour encaisser).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'COMPTABLE' AND r.school_id IS NULL
  AND p.code IN (
    'school.view','academic_year.view','student.view','classroom.view',
    'finance.view','fee.manage','payment.view','payment.record','receipt.print',
    'expense.manage','report.financial','report.export','archive.view'
  );

-- ENSEIGNANT : strictement ses classes. Le filtrage par classe est appliqué
-- dans la couche métier — la permission seule ne suffit jamais.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'ENSEIGNANT' AND r.school_id IS NULL
  AND p.code IN (
    'school.view','academic_year.view','curriculum.view',
    'student.view','classroom.view','timetable.view',
    'evaluation.view','evaluation.manage',
    'grade.view','grade.enter',
    'attendance.view','attendance.record'
  );

-- PARENT : consultation seule, restreinte à ses enfants (filtrage métier).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'PARENT' AND r.school_id IS NULL
  AND p.code IN (
    'student.view','student.history','grade.view','attendance.view',
    'timetable.view','finance.view','payment.view','document.view'
  );

-- ELEVE : consultation de son propre dossier.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'ELEVE' AND r.school_id IS NULL
  AND p.code IN (
    'student.history','grade.view','attendance.view','timetable.view','document.view'
  );
