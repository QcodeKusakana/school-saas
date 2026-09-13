-- =====================================================================
--  MIGRATION — Orientations : figer les libellés
--
--  PROBLÈME
--  La table orientations référençait sections et options en RESTRICT.
--  Deux conséquences :
--
--    1. Métier : si une école renomme « Mathématique-Physique » ou
--       retire une option de son référentiel en 2032, l'orientation
--       prononcée en 2026 afficherait le nouveau libellé — ou
--       deviendrait impossible à supprimer. Un document scolaire doit
--       porter les termes en vigueur au moment de la décision.
--
--    2. Technique : la suppression d'un établissement était bloquée.
--       MySQL cascade sur options depuis schools, mais le RESTRICT
--       depuis orientations l'en empêche, alors même que ces lignes
--       seraient elles aussi supprimées.
--
--  SOLUTION
--  Les libellés sont recopiés dans la table au moment de la décision,
--  et les clés étrangères passent en SET NULL. L'orientation reste
--  lisible même si le référentiel évolue ; le lien vers la section et
--  l'option subsiste tant qu'elles existent, pour les statistiques.
--
--  C'est le même principe que le rattachement des programmes à l'année
--  scolaire : l'historique ne doit jamais être réécrit par une
--  modification postérieure du référentiel.
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Libellés figés au moment de la décision
ALTER TABLE orientations
    ADD COLUMN section_label VARCHAR(120) NULL
        COMMENT 'Libellé de la section au moment de la décision' AFTER section_id,
    ADD COLUMN option_label  VARCHAR(150) NULL
        COMMENT 'Libellé de l option au moment de la décision' AFTER option_id,
    ADD COLUMN level_label   VARCHAR(100) NULL
        COMMENT 'Libellé du niveau achevé au moment de la décision' AFTER from_level_id;

-- 2. Remplir les lignes existantes depuis le référentiel actuel
UPDATE orientations o
   JOIN sections s ON s.id = o.section_id
    SET o.section_label = s.name
  WHERE o.section_label IS NULL;

UPDATE orientations o
   JOIN options op ON op.id = o.option_id
    SET o.option_label = op.name
  WHERE o.option_label IS NULL;

UPDATE orientations o
   JOIN education_levels l ON l.id = o.from_level_id
    SET o.level_label = l.name
  WHERE o.level_label IS NULL;

-- 3. Les clés deviennent facultatives : le libellé porte désormais l'information
ALTER TABLE orientations
    DROP FOREIGN KEY fk_orientation_section,
    DROP FOREIGN KEY fk_orientation_option,
    DROP FOREIGN KEY fk_orientation_level;

ALTER TABLE orientations
    MODIFY COLUMN section_id    BIGINT UNSIGNED   NULL,
    MODIFY COLUMN option_id     BIGINT UNSIGNED   NULL,
    MODIFY COLUMN from_level_id SMALLINT UNSIGNED NULL;

ALTER TABLE orientations
    ADD CONSTRAINT fk_orientation_section FOREIGN KEY (section_id)
        REFERENCES sections (id) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT fk_orientation_option FOREIGN KEY (option_id)
        REFERENCES options (id) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT fk_orientation_level FOREIGN KEY (from_level_id)
        REFERENCES education_levels (id) ON DELETE SET NULL ON UPDATE CASCADE;
