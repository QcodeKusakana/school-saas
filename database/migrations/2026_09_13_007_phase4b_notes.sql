-- =====================================================================
--  MIGRATION — Phase 4B : Notes
--
--  Périmètre : la cote d'un élève, dans une branche, pour une période.
--  C'est la pièce que le bulletin additionne.
--
--  Le modèle de notation de la RDC
--  ------------------------------
--  Il n'y a pas de coefficient séparé : le MAXIMUM EST la pondération.
--  Une branche notée sur 40 pèse deux fois une branche notée sur 20.
--
--  Le maximum applicable à une période se calcule ainsi :
--
--      curriculum_subjects.max_points  ×  grade_periods.max_multiplier
--
--  Mathématiques sur 40, première période (×1)  → sur 40
--  Mathématiques sur 40, examen (×2)            → sur 80
--
--  Sur l'année : 40 + 40 + 80 + 40 + 40 + 80 = 320.
--
--
--  LA DÉCISION CENTRALE DE CETTE MIGRATION
--  ---------------------------------------
--  `grades.max_points` est FIGÉ à la saisie. Il n'est pas recalculé,
--  jamais, ni à l'affichage ni à l'édition du bulletin.
--
--  Sans cela : une école corrige en mars le maximum de mathématiques,
--  de 40 à 20. Toutes les cotes déjà saisies — 32/40, 37/40 — deviennent
--  32/20 et 37/20. Les bulletins du premier trimestre, déjà remis aux
--  parents et signés, ne sont plus reproductibles.
--
--  C'est le même principe que les libellés figés des orientations en
--  phase 3 : un document scolaire porte les termes en vigueur au moment
--  où il a été établi. L'historique ne se réécrit jamais.
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;


CREATE TABLE grades (
    id                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    school_id             BIGINT UNSIGNED  NOT NULL,

    -- La cote est rattachée à l'INSCRIPTION, pas à l'élève.
    --
    -- Une inscription porte à la fois l'élève, l'année et la classe :
    -- c'est exactement le périmètre d'un bulletin. Rattacher la cote à
    -- l'élève obligerait à répéter l'année partout, et un élève
    -- réinscrit hériterait des cotes de l'année précédente.
    --
    -- Effet voulu : un élève transféré de 5A en 5B en janvier conserve
    -- ses cotes, puisque son inscription le suit.
    enrollment_id         BIGINT UNSIGNED  NOT NULL,

    curriculum_subject_id BIGINT UNSIGNED  NOT NULL,
    grade_period_id       BIGINT UNSIGNED  NOT NULL,

    -- Décimal, et non entier.
    --
    -- Le bulletin officiel affiche des entiers, mais la cote d'une
    -- période est souvent la moyenne de plusieurs interrogations : elle
    -- vaut naturellement 12,5. Arrondir à la saisie perdrait
    -- l'information définitivement ; arrondir à l'affichage la conserve.
    -- On stocke ce qui est vrai, on affiche ce qui est réglementaire.
    points                DECIMAL(6,2)     NULL,

    -- FIGÉ à la saisie. Voir l'en-tête de ce fichier.
    max_points            DECIMAL(6,2)     NOT NULL,

    -- L'absence n'est pas un zéro.
    --
    -- Un élève absent à une interrogation n'a pas échoué : il n'a pas
    -- composé. Les confondre fausse la moyenne et prive le conseil de
    -- classe d'une information dont il a besoin. Le traitement — zéro,
    -- rattrapage, exclusion du calcul — relève de la règle de
    -- l'établissement, pas du stockage.
    is_absent             TINYINT(1)       NOT NULL DEFAULT 0,

    comment               VARCHAR(255)     NULL,

    entered_by            BIGINT UNSIGNED  NULL,
    entered_at            DATETIME         NULL,
    updated_by            BIGINT UNSIGNED  NULL,

    created_at            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Une seule cote par élève, par branche, par période. La contrainte
    -- rend l'écriture idempotente : la saisie utilise
    -- INSERT ... ON DUPLICATE KEY UPDATE, sans lecture préalable, donc
    -- sans fenêtre de concurrence entre deux enseignants.
    UNIQUE KEY uq_grade_slot (school_id, enrollment_id, curriculum_subject_id, grade_period_id),

    -- Index de la grille de saisie : « toutes les cotes de cette branche
    -- pour cette période ». C'est la requête la plus fréquente du module.
    KEY idx_grade_sheet (school_id, grade_period_id, curriculum_subject_id),

    -- Index du bulletin : « toutes les cotes de cet élève ».
    KEY idx_grade_enrollment (school_id, enrollment_id),

    CONSTRAINT fk_grade_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,

    -- CASCADE : annuler une inscription supprime ses cotes. Une cote
    -- sans inscription n'a aucun sens et fausserait les effectifs.
    CONSTRAINT fk_grade_enrollment FOREIGN KEY (enrollment_id)
        REFERENCES enrollments (id) ON DELETE CASCADE ON UPDATE CASCADE,

    -- RESTRICT : retirer une branche d'un programme alors que des cotes
    -- existent doit être un refus explicite. Supprimer silencieusement
    -- les notes d'un trimestre serait une perte irréparable.
    CONSTRAINT fk_grade_subject FOREIGN KEY (curriculum_subject_id)
        REFERENCES curriculum_subjects (id) ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_grade_period FOREIGN KEY (grade_period_id)
        REFERENCES grade_periods (id) ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_grade_entered_by FOREIGN KEY (entered_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_grade_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cote d un élève dans une branche pour une période';


-- ---------------------------------------------------------------------
--  Verrouillage d'une période : qui, quand
--
--  grade_periods.is_locked existait déjà. Savoir QUI a verrouillé et
--  QUAND est indispensable : le verrou fige les cotes d'un trimestre
--  entier, c'est un acte de direction qui doit être attribuable.
-- ---------------------------------------------------------------------
ALTER TABLE grade_periods
    ADD COLUMN locked_at DATETIME        NULL AFTER is_locked,
    ADD COLUMN locked_by BIGINT UNSIGNED NULL AFTER locked_at,
    ADD CONSTRAINT fk_period_locked_by FOREIGN KEY (locked_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE;
