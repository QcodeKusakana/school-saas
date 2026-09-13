-- =====================================================================
--  MIGRATION — Phase 2 : Référentiel scolaire
--
--  À exécuter APRÈS database/schema.sql et les seeds 001 et 002.
--
--  Deux familles de tables, et c'est la décision structurante de cette
--  phase :
--
--  1. LE MODÈLE NATIONAL (reference_*) — global, en lecture seule.
--     Décrit les sections, options et matières du système éducatif
--     congolais tels que publiés par l'EPST. Maintenu une seule fois
--     pour tout le SaaS.
--
--  2. LE RÉFÉRENTIEL DE CHAQUE ÉCOLE (sections, options, subjects…) —
--     porte school_id, créé par COPIE du modèle national.
--
--  Pourquoi copier plutôt que partager ?
--    · une école renomme « Mathématique-Physique » en « Math-Phys »
--      sans affecter les 200 autres écoles ;
--    · une école ajoute une option locale non prévue au national ;
--    · aucune exception au garde-fou multi-établissement : toutes les
--      requêtes métier restent uniformément filtrées par school_id ;
--    · le coût est négligeable — quelques dizaines de lignes par école.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- =====================================================================
--  SECTION 1 — MODÈLE NATIONAL (global, sans school_id)
-- =====================================================================

CREATE TABLE learning_domains (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(30)      NOT NULL,
    name            VARCHAR(100)     NOT NULL,
    short_name      VARCHAR(40)      NOT NULL,
    order_number    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       TINYINT(1)       NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_domain_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Domaines d apprentissage du programme national (langues, sciences, …)';


CREATE TABLE reference_sections (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cycle_id        TINYINT UNSIGNED  NOT NULL,
    code            VARCHAR(30)       NOT NULL,
    name            VARCHAR(120)      NOT NULL,
    short_name      VARCHAR(40)       NOT NULL,
    description     VARCHAR(255)      NULL,
    order_number    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       TINYINT(1)        NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ref_section_code (code),
    KEY idx_ref_section_cycle (cycle_id, order_number),
    CONSTRAINT fk_ref_section_cycle FOREIGN KEY (cycle_id)
        REFERENCES education_cycles (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sections des humanités au niveau national — modèle à copier';


CREATE TABLE reference_options (
    id                  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_section_id SMALLINT UNSIGNED NOT NULL,
    code                VARCHAR(40)       NOT NULL,
    name                VARCHAR(150)      NOT NULL,
    short_name          VARCHAR(50)       NOT NULL,
    order_number        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active           TINYINT(1)        NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ref_option_code (code),
    KEY idx_ref_option_section (reference_section_id, order_number),
    CONSTRAINT fk_ref_option_section FOREIGN KEY (reference_section_id)
        REFERENCES reference_sections (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Options rattachées à une section — modèle national';


CREATE TABLE reference_subjects (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain_id       TINYINT UNSIGNED  NULL,
    code            VARCHAR(40)       NOT NULL,
    name            VARCHAR(150)      NOT NULL,
    short_name      VARCHAR(50)       NOT NULL,
    order_number    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       TINYINT(1)        NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ref_subject_code (code),
    KEY idx_ref_subject_domain (domain_id, order_number),
    CONSTRAINT fk_ref_subject_domain FOREIGN KEY (domain_id)
        REFERENCES learning_domains (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catalogue national des branches — modèle à copier dans chaque école';


-- =====================================================================
--  SECTION 2 — RÉFÉRENTIEL DE CHAQUE ÉCOLE
-- =====================================================================

CREATE TABLE sections (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED   NOT NULL,
    cycle_id            TINYINT UNSIGNED  NOT NULL,
    reference_section_id SMALLINT UNSIGNED NULL COMMENT 'Origine nationale, NULL si créée localement',
    code                VARCHAR(30)       NOT NULL,
    name                VARCHAR(120)      NOT NULL,
    short_name          VARCHAR(40)       NOT NULL,
    order_number        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active           TINYINT(1)        NOT NULL DEFAULT 1,
    created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_section_school_code (school_id, code),
    KEY idx_section_school (school_id, is_active, order_number),
    CONSTRAINT fk_section_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_section_cycle FOREIGN KEY (cycle_id)
        REFERENCES education_cycles (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_section_reference FOREIGN KEY (reference_section_id)
        REFERENCES reference_sections (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sections réellement ouvertes par l établissement';


CREATE TABLE options (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED   NOT NULL,
    section_id          BIGINT UNSIGNED   NOT NULL,
    reference_option_id SMALLINT UNSIGNED NULL,
    code                VARCHAR(40)       NOT NULL,
    name                VARCHAR(150)      NOT NULL,
    short_name          VARCHAR(50)       NOT NULL,
    order_number        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active           TINYINT(1)        NOT NULL DEFAULT 1,
    created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_option_school_code (school_id, code),
    KEY idx_option_section (school_id, section_id, is_active),
    CONSTRAINT fk_option_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_option_section FOREIGN KEY (section_id)
        REFERENCES sections (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_option_reference FOREIGN KEY (reference_option_id)
        REFERENCES reference_options (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Options ouvertes par l établissement. Relation 1-N avec sections : une option appartient à une seule section, ce qui reflète l organisation réelle des humanités en RDC.';


CREATE TABLE subjects (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED   NOT NULL,
    domain_id           TINYINT UNSIGNED  NULL,
    reference_subject_id SMALLINT UNSIGNED NULL,
    code                VARCHAR(40)       NOT NULL,
    name                VARCHAR(150)      NOT NULL,
    short_name          VARCHAR(50)       NOT NULL COMMENT 'Libellé imprimé dans la colonne du bulletin',
    is_active           TINYINT(1)        NOT NULL DEFAULT 1,
    created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subject_school_code (school_id, code),
    KEY idx_subject_school (school_id, is_active),
    CONSTRAINT fk_subject_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_subject_domain FOREIGN KEY (domain_id)
        REFERENCES learning_domains (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_subject_reference FOREIGN KEY (reference_subject_id)
        REFERENCES reference_subjects (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Branches enseignées. Le MAXIMUM ne figure pas ici : il dépend du niveau et de l option, et vit dans curriculum_subjects.';


-- ---------------------------------------------------------------------
--  Périodes d'évaluation
--
--  Structure du bulletin EPST : quatre périodes, un examen par semestre.
--  Ces lignes déterminent les colonnes du bulletin et la façon dont les
--  maxima sont totalisés. Créées ici et non en phase 4, car il s'agit de
--  paramétrage et non de notation.
-- ---------------------------------------------------------------------
CREATE TABLE grade_periods (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED  NOT NULL,
    academic_year_id    BIGINT UNSIGNED  NOT NULL,
    code                VARCHAR(20)      NOT NULL COMMENT 'P1, P2, EX1, P3, P4, EX2',
    name                VARCHAR(100)     NOT NULL,
    period_type         ENUM('period','exam') NOT NULL DEFAULT 'period',
    semester            TINYINT UNSIGNED NOT NULL COMMENT '1 ou 2',
    order_number        TINYINT UNSIGNED NOT NULL,
    -- Multiplicateur appliqué au maximum de base de la branche.
    -- Une période vaut 1x, un examen semestriel vaut 2x : une branche
    -- à 20 points par période est donc notée sur 40 à l'examen.
    max_multiplier      DECIMAL(4,2)     NOT NULL DEFAULT 1.00,
    starts_on           DATE             NULL,
    ends_on             DATE             NULL,
    is_locked           TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1 = saisie des notes fermée',
    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_period_year_code (school_id, academic_year_id, code),
    KEY idx_period_year (school_id, academic_year_id, order_number),
    CONSTRAINT fk_period_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_period_year FOREIGN KEY (academic_year_id)
        REFERENCES academic_years (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Périodes et examens d une année scolaire — définissent les colonnes du bulletin';


-- ---------------------------------------------------------------------
--  Programmes
--
--  Un programme = les branches et leurs maxima, pour un niveau donné
--  (et une option donnée dans les humanités), sur une année scolaire.
--
--  Rattaché à l'ANNÉE SCOLAIRE à dessein : modifier un maximum en
--  2027-2028 ne doit jamais altérer les bulletins déjà émis en
--  2026-2027. C'est la condition d'un historique fiable sur treize ans.
-- ---------------------------------------------------------------------
CREATE TABLE curriculums (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED   NOT NULL,
    academic_year_id    BIGINT UNSIGNED   NOT NULL,
    education_level_id  SMALLINT UNSIGNED NOT NULL,
    section_id          BIGINT UNSIGNED   NULL COMMENT 'NULL hors humanités',
    option_id           BIGINT UNSIGNED   NULL COMMENT 'NULL hors humanités',
    -- Clé d'unicité calculée par l'application.
    -- Un index UNIQUE ordinaire ne conviendrait pas : MySQL ne compare
    -- jamais deux NULL entre eux, deux programmes « 5e primaire, sans
    -- section » passeraient donc tous les deux. Cette colonne, remplie
    -- avec un jeton explicite à la place des NULL, ferme la porte.
    uniq_key            VARCHAR(120)      NOT NULL,
    name                VARCHAR(180)      NOT NULL,
    status              ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    notes               VARCHAR(255)      NULL,
    created_by          BIGINT UNSIGNED   NULL,
    created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_curriculum_key (school_id, uniq_key),
    KEY idx_curriculum_year (school_id, academic_year_id, status),
    KEY idx_curriculum_level (school_id, education_level_id),
    CONSTRAINT fk_curriculum_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_curriculum_year FOREIGN KEY (academic_year_id)
        REFERENCES academic_years (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_curriculum_level FOREIGN KEY (education_level_id)
        REFERENCES education_levels (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_curriculum_section FOREIGN KEY (section_id)
        REFERENCES sections (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_curriculum_option FOREIGN KEY (option_id)
        REFERENCES options (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_curriculum_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Programme d un niveau/option pour une année scolaire donnée';


CREATE TABLE curriculum_subjects (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED  NOT NULL,
    curriculum_id       BIGINT UNSIGNED  NOT NULL,
    subject_id          BIGINT UNSIGNED  NOT NULL,
    -- LE MAXIMUM, cœur du bulletin congolais.
    -- Maximum de points de la branche POUR UNE PÉRIODE. Les maxima des
    -- examens et des totaux s'en déduisent via grade_periods.max_multiplier.
    -- C'est ce nombre qui porte la pondération : une branche sur 40 pèse
    -- deux fois plus qu'une branche sur 20. Il n'y a pas de coefficient
    -- multiplicateur séparé — ce serait pondérer deux fois.
    max_points          SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    weekly_hours        DECIMAL(4,1)     NULL COMMENT 'Volume horaire hebdomadaire',
    is_optional         TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1 = branche facultative, hors total',
    counts_for_ranking  TINYINT(1)       NOT NULL DEFAULT 1 COMMENT '0 = exclue du classement (conduite, religion…)',
    order_number        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ordre des lignes du bulletin',
    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_curriculum_subject (curriculum_id, subject_id),
    KEY idx_cs_school (school_id, curriculum_id, order_number),
    KEY idx_cs_subject (school_id, subject_id),
    CONSTRAINT fk_cs_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cs_curriculum FOREIGN KEY (curriculum_id)
        REFERENCES curriculums (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cs_subject FOREIGN KEY (subject_id)
        REFERENCES subjects (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Branches d un programme avec leur maximum de points par période';


SET FOREIGN_KEY_CHECKS = 1;
