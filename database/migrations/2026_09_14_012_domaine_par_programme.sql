-- =====================================================================
--  MIGRATION — Le domaine d'une branche appartient au PROGRAMME
--
--  LE PROBLÈME, CONSTATÉ SUR LES MODÈLES OFFICIELS
--  -----------------------------------------------
--  Le domaine d'une matière DÉPEND DU CYCLE :
--
--      Religion  →  DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT
--                   (bulletins 7e et 8e CTEB)
--      Religion  →  DOMAINE DU DÉVELOPPEMENT PERSONNEL
--                   (bulletins du degré élémentaire, moyen et terminal)
--
--  Une colonne `subjects.domain_id` unique ne peut donc pas être exacte
--  pour les deux cycles à la fois. Tant que le domaine servait de simple
--  classification, l'approximation ne se voyait pas. Elle devient une
--  ERREUR IMPRIMÉE dès que le bulletin affiche les en-têtes de domaine et
--  leurs sous-totaux — ce qui est l'étape suivante.
--
--
--  LA CORRECTION
--  -------------
--  Le rattachement correct appartient au PROGRAMME : c'est lui qui sait
--  de quel niveau et de quel cycle il relève. `curriculum_subjects`
--  reçoit donc son propre `domain_id`.
--
--  NULL = « suivre la matière ». La colonne est une DÉROGATION, pas une
--  duplication : une école qui ne touche à rien garde le comportement
--  actuel, et les programmes existants ne sont pas réécrits à l'aveugle.
--
--  Le remplissage automatique d'un programme la renseigne désormais, en
--  appliquant les corrections propres au cycle. Une école peut ensuite la
--  modifier branche par branche : le référentiel reste configurable, rien
--  n'est figé dans le code PHP.
--
--
--  CE QUI N'EST PAS FAIT ICI
--  -------------------------
--  Les SOUS-DOMAINES — « Sous-domaine des Mathématiques », « des Sciences
--  de la Vie et de la Terre », « des Sciences Physiques, Technologie et
--  TIC » — n'apparaissent que sur les bulletins du CTEB et des humanités
--  scientifiques. Ils viendront avec le gabarit qui les affiche, pas
--  avant : créer une table pour un besoin qu'aucun écran n'exprime encore
--  reviendrait à deviner sa forme.
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;


ALTER TABLE curriculum_subjects
    ADD COLUMN domain_id TINYINT UNSIGNED NULL
        COMMENT 'Domaine pour CE programme ; NULL = celui de la matière'
        AFTER subject_id,
    ADD CONSTRAINT fk_curriculum_subject_domain FOREIGN KEY (domain_id)
        REFERENCES learning_domains (id) ON DELETE SET NULL ON UPDATE CASCADE;


-- ---------------------------------------------------------------------
--  CORRECTION DES PROGRAMMES DU PRIMAIRE DÉJÀ CRÉÉS
--
--  Religion y relève du développement personnel, et non de l'univers
--  social. Les programmes remplis avant cette migration portent donc un
--  rattachement contredit par le bulletin officiel.
--
--  Seul ce cas est corrigé : c'est le seul écart que les dix modèles
--  fournis établissent. Rien n'est supposé au-delà.
-- ---------------------------------------------------------------------
UPDATE curriculum_subjects cs
  JOIN subjects s            ON s.id  = cs.subject_id AND s.school_id = cs.school_id
  JOIN curriculums cu        ON cu.id = cs.curriculum_id AND cu.school_id = cs.school_id
  JOIN education_levels l    ON l.id  = cu.education_level_id
  JOIN education_cycles c    ON c.id  = l.cycle_id
  JOIN learning_domains d    ON d.code = 'DEV_PERS'
    SET cs.domain_id = d.id
  WHERE c.code = 'PRIMAIRE'
    AND s.code = 'RELIGION'
    AND cs.domain_id IS NULL;
