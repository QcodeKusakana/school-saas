-- =====================================================================
--  MIGRATION — Sous-domaines d'apprentissage
--
--  CE QUE LES MODÈLES OFFICIELS ÉTABLISSENT
--  ----------------------------------------
--  Trois bulletins — 7e CTEB, 8e CTEB et 3e humanités scientifiques —
--  intercalent un niveau entre le domaine et la branche :
--
--      DOMAINE DES SCIENCES
--        Sous-domaine des Mathématiques
--          Arithmétique · Statistique · Géométrie · Algèbre
--          Sous-Total
--        Sous-domaine des Sciences de la Vie et de la Terre
--          Anatomie · Botanique · Zoologie
--          Sous-Total
--        Sous-domaine des Sciences Physiques, Technologie et TIC
--          Sciences Physiques · Technologie · TIC
--          Sous-Total
--
--  Les bulletins du primaire font de même sous d'autres intitulés :
--  LANGUES CONGOLAISES et FRANÇAIS portent chacun leur sous-total dans le
--  domaine des langues ; MATHÉMATIQUES, SCIENCES et TECHNOLOGIE
--  structurent celui des sciences.
--
--  Le niveau n'est donc pas propre au CTEB : c'est une strate générale du
--  référentiel, présente partout où le document l'imprime.
--
--
--  CE QUI EST SEMÉ ICI, ET CE QUI NE L'EST PAS
--  -------------------------------------------
--  Seuls les sous-domaines LUS sur les dix modèles fournis. Aucun n'est
--  déduit, aucun n'est complété « par symétrie ». Un sous-domaine absent
--  de cette liste est un sous-domaine dont aucun bulletin ne prouve
--  l'existence.
--
--  Le rattachement des branches suit la même règle : il n'est posé que
--  là où un bulletin le montre. Les autres branches restent directement
--  sous leur domaine, ce que le gabarit sait afficher.
--
--
--  DEUX COLONNES, COMME POUR LES DOMAINES
--  --------------------------------------
--  subjects.subdomain_id           — le cas général, porté par la matière
--  curriculum_subjects.subdomain_id — la dérogation, portée par le
--                                     programme
--
--  Même dispositif que la migration 012, et pour la même raison : un
--  découpage peut différer d'un cycle à l'autre. NULL des deux côtés
--  signifie « branche rattachée directement au domaine ».
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
--  1. TABLE DE RÉFÉRENCE
--
--  Globale, comme learning_domains : le découpage officiel est national,
--  il n'appartient pas à un établissement.
-- ---------------------------------------------------------------------
CREATE TABLE learning_subdomains (
    id           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain_id    TINYINT UNSIGNED NOT NULL,
    code         VARCHAR(30)      NOT NULL,
    name         VARCHAR(150)     NOT NULL,
    short_name   VARCHAR(60)      NULL,
    order_number TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active    TINYINT(1)       NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    UNIQUE KEY uq_subdomain_code (code),
    KEY idx_subdomain_domain (domain_id, order_number),

    CONSTRAINT fk_subdomain_domain FOREIGN KEY (domain_id)
        REFERENCES learning_domains (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sous-domaines officiels, lus sur les bulletins du ministère';


-- ---------------------------------------------------------------------
--  2. LES SOUS-DOMAINES ATTESTÉS
-- ---------------------------------------------------------------------
INSERT INTO learning_subdomains (domain_id, code, name, short_name, order_number)
SELECT d.id, v.code, v.name, v.short_name, v.order_number
  FROM (
        SELECT 'SCIENCES' AS domain_code, 'SD_MATH'      AS code,
               'Sous-domaine des mathématiques' AS name,
               'Mathématiques' AS short_name, 1 AS order_number
  UNION SELECT 'SCIENCES', 'SD_SVT',
               'Sous-domaine des sciences de la vie et de la Terre',
               'Sciences de la vie et de la Terre', 2
  UNION SELECT 'SCIENCES', 'SD_PHYS_TIC',
               'Sous-domaine des sciences physiques, technologie et TIC',
               'Sciences physiques, technologie et TIC', 3
  UNION SELECT 'LANGUES', 'SD_LANG_NAT',
               'Langues congolaises', 'Langues congolaises', 1
  UNION SELECT 'LANGUES', 'SD_FRANCAIS',
               'Français', 'Français', 2
  UNION SELECT 'ARTS', 'SD_ARTISTIQUE',
               'Éducation artistique', 'Éducation artistique', 1
       ) v
  JOIN learning_domains d ON d.code = v.domain_code;


-- ---------------------------------------------------------------------
--  3. RATTACHEMENT DES BRANCHES
-- ---------------------------------------------------------------------
ALTER TABLE reference_subjects
    ADD COLUMN subdomain_id TINYINT UNSIGNED NULL
        COMMENT 'Sous-domaine officiel ; NULL = directement sous le domaine'
        AFTER domain_id,
    ADD CONSTRAINT fk_reference_subject_subdomain FOREIGN KEY (subdomain_id)
        REFERENCES learning_subdomains (id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE subjects
    ADD COLUMN subdomain_id TINYINT UNSIGNED NULL
        COMMENT 'Sous-domaine ; NULL = directement sous le domaine'
        AFTER domain_id,
    ADD CONSTRAINT fk_subject_subdomain FOREIGN KEY (subdomain_id)
        REFERENCES learning_subdomains (id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE curriculum_subjects
    ADD COLUMN subdomain_id TINYINT UNSIGNED NULL
        COMMENT 'Sous-domaine pour CE programme ; NULL = celui de la matière'
        AFTER domain_id,
    ADD CONSTRAINT fk_curriculum_subject_subdomain FOREIGN KEY (subdomain_id)
        REFERENCES learning_subdomains (id) ON DELETE SET NULL ON UPDATE CASCADE;


-- ---------------------------------------------------------------------
--  4. RATTACHEMENTS LUS SUR LES BULLETINS
--
--  Mathématiques, physique, technologie et TIC figurent nommément sous
--  leurs sous-domaines respectifs sur les modèles du CTEB. Biologie et
--  sciences de la vie et de la Terre relèvent du sous-domaine du même
--  nom. Langue nationale et français portent les leurs au primaire.
--
--  Chimie et « Sciences » ne sont rattachées à aucun sous-domaine :
--  aucun des dix modèles ne les y place, et deviner serait inventer.
-- ---------------------------------------------------------------------
UPDATE reference_subjects rs
  JOIN learning_subdomains sd ON sd.code = 'SD_MATH'
   SET rs.subdomain_id = sd.id
 WHERE rs.code = 'MATH';

UPDATE reference_subjects rs
  JOIN learning_subdomains sd ON sd.code = 'SD_SVT'
   SET rs.subdomain_id = sd.id
 WHERE rs.code IN ('BIOLOGIE', 'SVT');

UPDATE reference_subjects rs
  JOIN learning_subdomains sd ON sd.code = 'SD_PHYS_TIC'
   SET rs.subdomain_id = sd.id
 WHERE rs.code IN ('PHYSIQUE', 'TECHNOLOGIE', 'TIC');

UPDATE reference_subjects rs
  JOIN learning_subdomains sd ON sd.code = 'SD_LANG_NAT'
   SET rs.subdomain_id = sd.id
 WHERE rs.code = 'LANGUE_NAT';

UPDATE reference_subjects rs
  JOIN learning_subdomains sd ON sd.code = 'SD_FRANCAIS'
   SET rs.subdomain_id = sd.id
 WHERE rs.code = 'FRANCAIS';

UPDATE reference_subjects rs
  JOIN learning_subdomains sd ON sd.code = 'SD_ARTISTIQUE'
   SET rs.subdomain_id = sd.id
 WHERE rs.code IN ('DESSIN', 'MUSIQUE');


-- Les matières déjà copiées dans les écoles héritent du rattachement.
UPDATE subjects s
  JOIN reference_subjects rs ON rs.id = s.reference_subject_id
   SET s.subdomain_id = rs.subdomain_id
 WHERE s.subdomain_id IS NULL;
