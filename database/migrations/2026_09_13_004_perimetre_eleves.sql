-- =====================================================================
--  MIGRATION 004 — Périmètre de consultation du fichier élèves
--
--  Défaut corrigé
--  --------------
--  La permission « student.view » était accordée aux rôles ENSEIGNANT,
--  PARENT et COMPTABLE, avec en commentaire l'intention d'un filtrage
--  métier (« ses classes », « ses enfants »). Ce filtrage n'a jamais été
--  écrit. Conséquence : tout titulaire d'un compte de l'école pouvait
--  lire la totalité du fichier élèves — état civil des mineurs, adresse
--  du domicile, téléphone et profession des parents.
--
--  Correction
--  ----------
--  « student.view » ne signifie plus « voir tous les élèves », mais
--  « accéder au module élèves ». Le droit de voir TOUT l'établissement
--  devient une permission distincte, stockée en base et donc
--  réattribuable par école sans toucher au code :
--
--      student.view      → accéder au module, dans son périmètre
--      student.view.all  → voir tous les élèves de l'établissement
--
--  Qui ne détient pas student.view.all ne voit que les élèves de son
--  périmètre, calculé par students_scope_clause(). Aujourd'hui seul le
--  périmètre « parent » est implémenté ; le périmètre « enseignant »
--  arrivera avec la table teachers (phase 4). D'ici là un enseignant ne
--  voit rien plutôt que tout : en sécurité, le défaut se ferme.
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
--  1. Nouvelle permission
-- ---------------------------------------------------------------------
INSERT INTO permissions (code, module, name, description, is_platform, sort_order)
VALUES (
    'student.view.all',
    'student',
    'Consulter tous les élèves de l''établissement',
    'Sans cette permission, l''utilisateur ne voit que les élèves de son périmètre : ses enfants pour un parent, ses classes pour un enseignant.',
    0,
    51
)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), sort_order = VALUES(sort_order);


-- ---------------------------------------------------------------------
--  2. Attribution aux rôles qui gèrent l'établissement entier
--
--  DIRECTION, PREFET, SECRETARIAT et SCHOOL_ADMIN travaillent par
--  nature sur tout le fichier. COMPTABLE y figure aussi : le suivi des
--  frais scolaires porte sur l'ensemble des élèves.
--
--  SUPER_ADMIN reçoit toutes les permissions par une requête distincte
--  du seed 002 ; il est traité plus bas.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'student.view.all'
 WHERE r.school_id IS NULL
   AND r.code IN ('SCHOOL_ADMIN', 'DIRECTION', 'PREFET', 'SECRETARIAT', 'COMPTABLE');


-- ---------------------------------------------------------------------
--  3. Le super administrateur détient toutes les permissions
-- ---------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'student.view.all'
 WHERE r.school_id IS NULL AND r.code = 'SUPER_ADMIN';


-- ---------------------------------------------------------------------
--  4. Rôles personnalisés déjà créés par des écoles
--
--  Une école ayant dupliqué un rôle administratif avant cette migration
--  doit conserver le comportement qu'elle connaît : tout rôle détenant
--  déjà le droit de créer ou modifier un élève gérait forcément le
--  fichier entier.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p.id
  FROM role_permissions rp
  JOIN permissions source ON source.id = rp.permission_id AND source.code = 'student.create'
  JOIN permissions p      ON p.code = 'student.view.all'
  JOIN roles r            ON r.id = rp.role_id AND r.school_id IS NOT NULL;
