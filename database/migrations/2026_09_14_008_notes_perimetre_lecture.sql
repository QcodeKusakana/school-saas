-- =====================================================================
--  MIGRATION 008 — Périmètre de lecture des notes
--
--  Défaut corrigé
--  --------------
--  La permission « grade.view » était accordée aux rôles PARENT et
--  ELEVE. Aucun écran ne filtrait alors sur l'enfant rattaché : la
--  grille de saisie /notes/classe/{id}/branche/{s}/periode/{p} affichait
--  nom, post-nom, prénom, matricule et cote de TOUS les élèves de
--  N'IMPORTE QUELLE classe de l'établissement. Les identifiants étant de
--  petits entiers, l'énumération était triviale.
--
--  Le contrôle censé l'empêcher était écrit :
--
--      if (!$auth['ok'] && !perm_has('grade.view')) { abort(403); }
--
--  La route exigeant déjà grade.view, la seconde condition était
--  toujours fausse : le refus n'était jamais atteint.
--
--  Correction
--  ----------
--  Deux gestes, et il en faut deux.
--
--  1. Le code : la lecture est désormais bornée par
--     students_can_view_classroom(), le même critère que le tableau de
--     bord de classe.
--
--  2. La permission : « grade.view » est retirée des rôles PARENT et
--     ELEVE. Accorder un droit dont la seule implémentation existante
--     est non filtrée, c'est accorder plus que ce qu'on croit. Le droit
--     sera réattribué en phase 6, avec le portail parent qui filtrera
--     sur l'enfant.
--
--  Ce que cela change pour l'utilisateur : rien aujourd'hui, aucun écran
--  ne leur était destiné. Le portail parent reste à construire.
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;

DELETE rp
  FROM role_permissions rp
  JOIN roles r       ON r.id = rp.role_id
  JOIN permissions p ON p.id = rp.permission_id
 WHERE r.school_id IS NULL
   AND r.code IN ('PARENT', 'ELEVE')
   AND p.code = 'grade.view';
