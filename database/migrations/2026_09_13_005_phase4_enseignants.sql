-- =====================================================================
--  MIGRATION — Phase 4A : Enseignants et affectations pédagogiques
--
--  Périmètre : fiche enseignant, rattachement à un compte utilisateur,
--  affectation « une branche dans une classe », titulaire de classe.
--
--  Pourquoi cette phase vient AVANT les notes
--  ------------------------------------------
--  L'audit de la phase 3 a montré qu'une permission ne dit jamais sur
--  QUI elle s'exerce. « grade.enter » ne suffira donc pas à autoriser la
--  saisie d'une note : il faudra prouver que l'enseignant assure bien
--  cette branche dans cette classe. Cette preuve, c'est teacher_subjects.
--
--  Sans cette table, la saisie des notes ne pourrait être autorisée que
--  globalement — c'est-à-dire mal.
--
--  Elle referme aussi la dette ouverte par l'audit : l'enseignant ne
--  voyait AUCUN élève, faute de périmètre calculable.
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;


-- =====================================================================
--  SECTION 1 — FICHE ENSEIGNANT
-- =====================================================================

CREATE TABLE teachers (
    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    school_id         BIGINT UNSIGNED  NOT NULL,
    uuid              CHAR(36)         NOT NULL,

    -- Compte de connexion. NULL tant que l'enseignant n'en a pas :
    -- beaucoup d'établissements saisissent d'abord le personnel, et
    -- n'ouvrent les comptes qu'ensuite. Le périmètre de consultation
    -- s'appuie sur cette colonne : sans compte, pas d'accès, ce qui est
    -- le comportement voulu.
    user_id           BIGINT UNSIGNED  NULL,

    matricule         VARCHAR(30)      NULL COMMENT 'Matricule interne ou numéro SECOPE',

    -- Nom complet à la congolaise : NOM Postnom Prénom.
    last_name         VARCHAR(80)      NOT NULL,
    post_name         VARCHAR(80)      NULL,
    first_name        VARCHAR(80)      NOT NULL,
    gender            ENUM('M','F')    NULL,
    birth_date        DATE             NULL,

    phone             VARCHAR(30)      NULL,
    phone_alt         VARCHAR(30)      NULL,
    email             VARCHAR(190)     NULL,
    address           VARCHAR(255)     NULL,

    hire_date         DATE             NULL,
    employment_type   ENUM('permanent','contract','volunteer') NOT NULL DEFAULT 'permanent',
    qualification     VARCHAR(120)     NULL COMMENT 'Diplôme : D6, G3, L2…',
    specialty         VARCHAR(120)     NULL COMMENT 'Discipline principale',

    status            ENUM('active','suspended','left') NOT NULL DEFAULT 'active',
    left_on           DATE             NULL,
    notes             VARCHAR(255)     NULL,

    created_by        BIGINT UNSIGNED  NULL,
    created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME         NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_teacher_uuid (uuid),

    -- Le matricule est facultatif ; MySQL ignore les NULL dans un index
    -- unique, plusieurs enseignants peuvent donc rester sans matricule.
    UNIQUE KEY uq_teacher_matricule (school_id, matricule),

    -- Un compte utilisateur ne peut représenter qu'un seul enseignant.
    -- Sans cette contrainte, deux fiches partageant un compte donneraient
    -- à une même personne deux périmètres cumulés.
    UNIQUE KEY uq_teacher_user (school_id, user_id),

    KEY idx_teacher_names (school_id, last_name, post_name, first_name),
    KEY idx_teacher_status (school_id, status, deleted_at),

    CONSTRAINT fk_teacher_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_teacher_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_teacher_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Personnel enseignant de l établissement';


-- =====================================================================
--  SECTION 2 — AFFECTATION PÉDAGOGIQUE
--
--  Une ligne = « cet enseignant assure CETTE branche dans CETTE classe,
--  pour CETTE année ».
--
--  La branche est désignée par curriculum_subjects et non par subjects :
--  c'est le programme qui porte le maximum de points et le volume
--  horaire. Passer par la branche nue perdrait le lien avec le programme
--  de la classe, et permettrait d'affecter une branche qui n'y figure
--  même pas.
--
--  Règle : une branche d'une classe n'a qu'un enseignant. C'est ce qui
--  rend la saisie des notes non ambiguë et permet au bulletin de nommer
--  un responsable par branche. Un établissement pratiquant le
--  co-enseignement devra faire évoluer cette contrainte explicitement —
--  ce n'est pas un oubli.
-- =====================================================================

CREATE TABLE teacher_subjects (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id             BIGINT UNSIGNED NOT NULL,
    teacher_id            BIGINT UNSIGNED NOT NULL,
    academic_year_id      BIGINT UNSIGNED NOT NULL,
    classroom_id          BIGINT UNSIGNED NOT NULL,
    curriculum_subject_id BIGINT UNSIGNED NOT NULL,

    -- Volume horaire réellement assuré. Peut différer de celui du
    -- programme (dédoublement, heures partagées).
    weekly_hours          DECIMAL(4,1)    NULL,

    assigned_on           DATE            NOT NULL DEFAULT (CURRENT_DATE),
    created_by            BIGINT UNSIGNED NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_assignment_slot (school_id, academic_year_id, classroom_id, curriculum_subject_id),

    -- Index du périmètre : « quelles classes pour cet enseignant ? »
    -- est la question posée à chaque requête d'un compte enseignant.
    KEY idx_assignment_teacher (school_id, teacher_id, academic_year_id),
    KEY idx_assignment_classroom (school_id, classroom_id),

    CONSTRAINT fk_assignment_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assignment_teacher FOREIGN KEY (teacher_id)
        REFERENCES teachers (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assignment_year FOREIGN KEY (academic_year_id)
        REFERENCES academic_years (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assignment_classroom FOREIGN KEY (classroom_id)
        REFERENCES classrooms (id) ON DELETE CASCADE ON UPDATE CASCADE,

    -- RESTRICT : retirer une branche d'un programme alors qu'un
    -- enseignant l'assure doit être un refus explicite, pas une
    -- suppression silencieuse de son service.
    CONSTRAINT fk_assignment_subject FOREIGN KEY (curriculum_subject_id)
        REFERENCES curriculum_subjects (id) ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT fk_assignment_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Affectation : un enseignant, une branche, une classe, une année';


-- =====================================================================
--  SECTION 3 — TITULAIRE DE CLASSE
--
--  Annoncé dès la phase 3, reporté faute de table teachers.
--  SET NULL : le départ d'un enseignant ne doit pas supprimer la classe.
-- =====================================================================

ALTER TABLE classrooms
    ADD COLUMN main_teacher_id BIGINT UNSIGNED NULL
        COMMENT 'Titulaire de la classe' AFTER room_id,
    ADD KEY idx_classroom_main_teacher (school_id, main_teacher_id),
    ADD CONSTRAINT fk_classroom_main_teacher FOREIGN KEY (main_teacher_id)
        REFERENCES teachers (id) ON DELETE SET NULL ON UPDATE CASCADE;


-- =====================================================================
--  SECTION 4 — PARAMÈTRE : SEUIL DE RÉUSSITE
--
--  La règle nationale est 50 %. Elle est néanmoins enregistrée comme un
--  paramètre d'établissement, et non écrite dans le code : une école
--  conventionnée peut appliquer un seuil différent, et une modification
--  réglementaire ne doit pas imposer une nouvelle version du logiciel.
--
--  Rien n'est inséré ici : school_setting() renvoie la valeur par défaut
--  tant qu'une école n'a rien enregistré. Insérer une ligne par école
--  créerait une copie à maintenir pour chaque établissement.
-- =====================================================================
