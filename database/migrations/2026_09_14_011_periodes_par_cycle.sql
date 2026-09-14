-- =====================================================================
--  MIGRATION — La structure des périodes appartient au CYCLE
--
--  CE QUE LES DIX MODÈLES OFFICIELS ÉTABLISSENT
--  --------------------------------------------
--  Le projet créait six périodes réparties en DEUX SEMESTRES, pour tout
--  le monde. Les bulletins du ministère montrent deux structures :
--
--    PRIMAIRE — TROIS TRIMESTRES
--      degré élémentaire (1re, 2e) · degré moyen (3e, 4e)
--      degré terminal (5e) · degré terminal spécial (6e)
--      → 1reP, 2eP, examen · 3eP, 4eP, examen · 5eP, 6eP, examen
--      → neuf périodes
--
--    CTEB ET HUMANITÉS — DEUX SEMESTRES
--      7e et 8e année · toutes les humanités
--      → 1reP, 2eP, examen · 3eP, 4eP, examen
--      → six périodes
--
--  Vérifié sur le bulletin du degré moyen : MAX per 300, MAX EX 600,
--  MAX TRIM 1200, TOTAL 3600. Soit 300 + 300 + 600 = 1200 par trimestre,
--  et 3 × 1200 = 3600. Le multiplicateur d'examen reste 2 : la règle des
--  maxima ne change pas, seul le DÉCOUPAGE change.
--
--
--  POURQUOI LE CYCLE, ET NON LE NIVEAU
--  -----------------------------------
--  Les quatre modèles du primaire — élémentaire, moyen, terminal,
--  terminal spécial — sont tous en trimestres. Les deux du CTEB et les
--  quatre des humanités sont tous en semestres. Le découpage suit donc
--  le cycle, pas le niveau, et le porter au cycle évite de répéter
--  l'information sur quinze niveaux.
--
--  Il reste CONFIGURABLE : une colonne en base, pas une condition dans
--  le code PHP. Une réforme du ministère se règle par un UPDATE.
--
--
--  CYCLES SANS MODÈLE FOURNI
--  -------------------------
--  Aucun bulletin de MATERNELLE ne figure parmi les modèles. Ce cycle
--  conserve donc la valeur par défaut « semestre ». Ce n'est pas une
--  affirmation sur le programme officiel du maternel : c'est l'absence
--  d'information, et il vaut mieux une valeur modifiable qu'une
--  supposition figée.
--
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
--  1. LE CYCLE PORTE SA STRUCTURE DE PÉRIODES
-- ---------------------------------------------------------------------
ALTER TABLE education_cycles
    ADD COLUMN period_structure ENUM('semestre', 'trimestre')
        NOT NULL DEFAULT 'semestre'
        COMMENT 'Découpage de l''année : 2 semestres ou 3 trimestres'
        AFTER name;

UPDATE education_cycles SET period_structure = 'trimestre' WHERE code = 'PRIMAIRE';


-- ---------------------------------------------------------------------
--  2. UNE PÉRIODE APPARTIENT À UN CYCLE
--
--  Une école qui dispense à la fois le primaire et les humanités a
--  besoin des DEUX jeux dans la même année scolaire. La période doit
--  donc dire à quel cycle elle s'applique.
--
--  NULL = jeu HÉRITÉ, antérieur à cette migration, applicable à tous
--  les cycles. Les jeux existants ne sont pas rattachés d'office à un
--  cycle : rien ne permet de deviner lequel, et inventer un
--  rattachement fausserait des bulletins déjà calculés. Ils continuent
--  de fonctionner tels quels ; les nouveaux jeux sont rattachés.
-- ---------------------------------------------------------------------
ALTER TABLE grade_periods
    ADD COLUMN cycle_id TINYINT UNSIGNED NULL
        COMMENT 'Cycle concerné ; NULL = jeu hérité, tous cycles'
        AFTER academic_year_id,
    ADD CONSTRAINT fk_period_cycle FOREIGN KEY (cycle_id)
        REFERENCES education_cycles (id) ON DELETE CASCADE ON UPDATE CASCADE;


-- ---------------------------------------------------------------------
--  3. L'UNICITÉ DU CODE DEVIENT RELATIVE AU CYCLE
--
--  L'ancienne clé (school_id, academic_year_id, code) interdisait deux
--  structures dans la même année : le code « P1 » du primaire heurtait
--  celui des humanités. Le cycle entre donc dans la clé.
--
--  Limite assumée : MySQL ne déduplique pas les NULL dans un index
--  unique, donc deux jeux hérités de même code resteraient possibles.
--  Le service ne crée plus jamais de période sans cycle, et aucun écran
--  ne le permet — le risque ne subsiste que pour une écriture SQL
--  manuelle.
-- ---------------------------------------------------------------------
ALTER TABLE grade_periods
    DROP INDEX uq_period_year_code,
    ADD UNIQUE KEY uq_period_year_cycle_code (school_id, academic_year_id, cycle_id, code);
