-- =====================================================================
--  MIGRATION — Phase 3 : Élèves, classes et parcours scolaire
--
--  Périmètre : classes, dossier élève, parents/tuteurs, inscriptions,
--  réinscriptions, orientation, journal du parcours.
--
--  Les classes sont incluses ici, et non reportées à une phase
--  ultérieure : une inscription sans affectation de classe reste un
--  dossier incomplet.
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- =====================================================================
--  SECTION 1 — CLASSES
-- =====================================================================

CREATE TABLE rooms (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED  NOT NULL,
    code            VARCHAR(30)      NOT NULL,
    name            VARCHAR(100)     NOT NULL,
    capacity        SMALLINT UNSIGNED NULL,
    building        VARCHAR(60)      NULL,
    is_active       TINYINT(1)       NOT NULL DEFAULT 1,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_room_school_code (school_id, code),
    CONSTRAINT fk_room_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Salles physiques — utilisées par l emploi du temps en phase 4';


CREATE TABLE classrooms (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED   NOT NULL,
    academic_year_id    BIGINT UNSIGNED   NOT NULL,
    -- Le programme porte le niveau, la section et l'option : les
    -- redupliquer ici ouvrirait la porte à des incohérences (une classe
    -- de 5e primaire rattachée à un programme d'humanités). Un JOIN
    -- suffit, et l'intégrité est garantie par construction.
    curriculum_id       BIGINT UNSIGNED   NOT NULL,
    room_id             BIGINT UNSIGNED   NULL,
    code                VARCHAR(30)       NOT NULL COMMENT 'Ex: 7A, 1SC-A',
    name                VARCHAR(120)      NOT NULL COMMENT 'Ex: 7ème année A',
    capacity            SMALLINT UNSIGNED NOT NULL DEFAULT 45,
    order_number        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active           TINYINT(1)        NOT NULL DEFAULT 1,
    created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_classroom_year_code (school_id, academic_year_id, code),
    KEY idx_classroom_year (school_id, academic_year_id, is_active),
    KEY idx_classroom_curriculum (school_id, curriculum_id),
    CONSTRAINT fk_classroom_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_classroom_year FOREIGN KEY (academic_year_id)
        REFERENCES academic_years (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_classroom_curriculum FOREIGN KEY (curriculum_id)
        REFERENCES curriculums (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_classroom_room FOREIGN KEY (room_id)
        REFERENCES rooms (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Classes d une année scolaire. Le titulaire sera ajouté en phase 4 avec la table teachers.';


-- =====================================================================
--  SECTION 2 — DOSSIER DE L'ÉLÈVE
-- =====================================================================

CREATE TABLE students (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED NOT NULL,
    uuid                CHAR(36)        NOT NULL COMMENT 'Identifiant public — carte scolaire, QR code',
    matricule           VARCHAR(30)     NOT NULL COMMENT 'Unique par école, jamais réattribué',

    -- État civil. Convention RDC : NOM Postnom Prénom.
    last_name           VARCHAR(80)     NOT NULL COMMENT 'Nom',
    post_name           VARCHAR(80)     NULL COMMENT 'Postnom',
    first_name          VARCHAR(80)     NOT NULL COMMENT 'Prénom',
    gender              ENUM('M','F')   NOT NULL,
    birth_date          DATE            NULL,
    birth_place         VARCHAR(120)    NULL,
    nationality         VARCHAR(60)     NULL DEFAULT 'Congolaise',

    -- Coordonnées propres à l'élève (les humanités ont souvent un téléphone).
    address             VARCHAR(255)    NULL,
    phone               VARCHAR(40)     NULL,
    email               VARCHAR(190)    NULL,
    photo_path          VARCHAR(255)    NULL,

    -- Parcours
    entry_date          DATE            NULL COMMENT 'Date d entrée dans l établissement',
    previous_school     VARCHAR(190)    NULL,
    status              ENUM('active','graduated','transferred','dropped','archived')
                        NOT NULL DEFAULT 'active',
    status_changed_on   DATE            NULL,
    notes               TEXT            NULL,

    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME        NULL COMMENT 'Un dossier élève n est jamais supprimé physiquement',

    PRIMARY KEY (id),
    UNIQUE KEY uq_student_uuid (uuid),
    UNIQUE KEY uq_student_matricule (school_id, matricule),
    KEY idx_student_school_status (school_id, status, deleted_at),
    -- Index de recherche : la recherche d'élève est l'action la plus
    -- fréquente du secrétariat, elle doit rester instantanée à
    -- plusieurs milliers de dossiers.
    KEY idx_student_names (school_id, last_name, post_name, first_name),
    KEY idx_student_birth (school_id, birth_date),
    CONSTRAINT fk_student_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dossier permanent de l élève — état civil, stable sur toute la scolarité';


-- ---------------------------------------------------------------------
--  Compteur de matricules
--
--  Table dédiée plutôt qu'un MAX(matricule) + 1 : deux inscriptions
--  simultanées liraient le même maximum et produiraient le même
--  matricule. Ici, la ligne du compteur est verrouillée par
--  SELECT ... FOR UPDATE le temps de la transaction — l'attribution
--  devient strictement séquentielle.
-- ---------------------------------------------------------------------
CREATE TABLE student_counters (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED  NOT NULL,
    counter_key     VARCHAR(30)      NOT NULL COMMENT 'Année d entrée, ou GLOBAL selon le format choisi',
    last_number     INT UNSIGNED     NOT NULL DEFAULT 0,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_counter (school_id, counter_key),
    CONSTRAINT fk_counter_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Séquence des matricules — garantit l unicité même en cas d inscriptions simultanées';


-- =====================================================================
--  SECTION 3 — PARENTS ET TUTEURS
-- =====================================================================

CREATE TABLE guardians (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED NOT NULL,
    uuid            CHAR(36)        NOT NULL,
    user_id         BIGINT UNSIGNED NULL COMMENT 'Compte de connexion au portail parent (phase 6)',
    last_name       VARCHAR(80)     NOT NULL,
    post_name       VARCHAR(80)     NULL,
    first_name      VARCHAR(80)     NOT NULL,
    gender          ENUM('M','F')   NULL,
    phone           VARCHAR(40)     NOT NULL COMMENT 'Principal moyen de contact en RDC',
    phone_alt       VARCHAR(40)     NULL,
    email           VARCHAR(190)    NULL,
    address         VARCHAR(255)    NULL,
    profession      VARCHAR(120)    NULL,
    employer        VARCHAR(150)    NULL,
    notes           VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guardian_uuid (uuid),
    KEY idx_guardian_school (school_id, deleted_at),
    KEY idx_guardian_phone (school_id, phone),
    KEY idx_guardian_name (school_id, last_name, first_name),
    CONSTRAINT fk_guardian_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_guardian_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Parents et tuteurs. Rattachés à une école : un même parent ayant des enfants dans deux établissements a une fiche dans chacun.';


CREATE TABLE student_guardians (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED NOT NULL,
    student_id          BIGINT UNSIGNED NOT NULL,
    guardian_id         BIGINT UNSIGNED NOT NULL,
    relationship        ENUM('pere','mere','tuteur','oncle','tante','frere','soeur','grand_parent','autre')
                        NOT NULL DEFAULT 'tuteur',
    is_primary          TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Contact principal — destinataire des bulletins et relances',
    is_emergency        TINYINT(1)      NOT NULL DEFAULT 1,
    can_pickup          TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'Autorisé à venir chercher l élève',
    is_payer            TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Responsable des frais scolaires',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_guardian (student_id, guardian_id),
    KEY idx_sg_school (school_id, student_id),
    KEY idx_sg_guardian (school_id, guardian_id),
    CONSTRAINT fk_sg_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sg_student FOREIGN KEY (student_id)
        REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sg_guardian FOREIGN KEY (guardian_id)
        REFERENCES guardians (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Liaison élève ↔ tuteur, avec le rôle de chacun';


-- =====================================================================
--  SECTION 4 — INSCRIPTIONS
-- =====================================================================

CREATE TABLE enrollments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED NOT NULL,
    student_id          BIGINT UNSIGNED NOT NULL,
    academic_year_id    BIGINT UNSIGNED NOT NULL,
    classroom_id        BIGINT UNSIGNED NULL COMMENT 'NULL tant que l affectation n est pas faite',

    enrollment_type     ENUM('new','re_enrollment','transfer') NOT NULL DEFAULT 'new',
    -- Le flux du cahier des charges : préinscription → admission →
    -- inscription. Chaque étape est datée pour pouvoir suivre les
    -- dossiers en attente.
    status              ENUM('pre_registered','admitted','enrolled','cancelled')
                        NOT NULL DEFAULT 'pre_registered',
    pre_registered_on   DATE            NULL,
    admitted_on         DATE            NULL,
    enrolled_on         DATE            NULL,
    cancelled_on        DATE            NULL,
    cancel_reason       VARCHAR(255)    NULL,

    -- Décision de fin d'année. Renseignée en phase 4, à la clôture.
    decision            ENUM('passed','failed','conditional','excluded','pending')
                        NOT NULL DEFAULT 'pending',
    decision_date       DATE            NULL,
    final_percentage    DECIMAL(5,2)    NULL COMMENT 'Pourcentage annuel, calculé en phase 4',
    class_rank          SMALLINT UNSIGNED NULL,

    repeated_year       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = redoublement',
    notes               VARCHAR(255)    NULL,
    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- Un élève ne peut être inscrit qu'une fois par année scolaire.
    UNIQUE KEY uq_enrollment_student_year (student_id, academic_year_id),
    KEY idx_enrollment_year (school_id, academic_year_id, status),
    KEY idx_enrollment_classroom (school_id, classroom_id, status),
    KEY idx_enrollment_student (school_id, student_id),
    CONSTRAINT fk_enrollment_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id)
        REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_enrollment_year FOREIGN KEY (academic_year_id)
        REFERENCES academic_years (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_enrollment_classroom FOREIGN KEY (classroom_id)
        REFERENCES classrooms (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_enrollment_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Inscription d un élève pour une année scolaire. Une ligne par année = le parcours complet.';


-- =====================================================================
--  SECTION 5 — ORIENTATION ET PARCOURS
-- =====================================================================

CREATE TABLE orientations (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED   NOT NULL,
    student_id          BIGINT UNSIGNED   NOT NULL,
    academic_year_id    BIGINT UNSIGNED   NOT NULL COMMENT 'Année où la décision est prise',
    from_level_id       SMALLINT UNSIGNED NOT NULL COMMENT 'Niveau achevé, en principe la 8ème',
    section_id          BIGINT UNSIGNED   NOT NULL,
    option_id           BIGINT UNSIGNED   NOT NULL,
    final_percentage    DECIMAL(5,2)      NULL COMMENT 'Résultat ayant motivé la décision',
    decided_on          DATE              NOT NULL,
    decided_by          BIGINT UNSIGNED   NULL,
    notes               VARCHAR(255)      NULL,
    created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orientation_student_year (student_id, academic_year_id),
    KEY idx_orientation_school (school_id, academic_year_id),
    CONSTRAINT fk_orientation_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_orientation_student FOREIGN KEY (student_id)
        REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_orientation_year FOREIGN KEY (academic_year_id)
        REFERENCES academic_years (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_orientation_level FOREIGN KEY (from_level_id)
        REFERENCES education_levels (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_orientation_section FOREIGN KEY (section_id)
        REFERENCES sections (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_orientation_option FOREIGN KEY (option_id)
        REFERENCES options (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_orientation_decider FOREIGN KEY (decided_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Orientation vers une filière après la 8ème année — décision datée, conservée définitivement';


-- ---------------------------------------------------------------------
--  Journal du parcours
--
--  Ce n'est PAS une copie des inscriptions : la timeline scolaire se
--  reconstruit déjà à partir de la table enrollments. Cette table
--  enregistre les ÉVÉNEMENTS qui ne sont pas des inscriptions —
--  orientation, transfert, obtention du diplôme, sanction, récompense.
--
--  Les deux sources se combinent pour former la frise affichée sur la
--  fiche de l'élève.
-- ---------------------------------------------------------------------
CREATE TABLE student_history (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED NOT NULL,
    student_id          BIGINT UNSIGNED NOT NULL,
    academic_year_id    BIGINT UNSIGNED NULL,
    event_type          ENUM('enrollment','promotion','repeat','orientation','transfer_in',
                             'transfer_out','graduation','suspension','award','status_change','note')
                        NOT NULL,
    event_date          DATE            NOT NULL,
    title               VARCHAR(150)    NOT NULL,
    description         VARCHAR(500)    NULL,
    reference_type      VARCHAR(40)     NULL COMMENT 'Table liée : enrollment, orientation…',
    reference_id        BIGINT UNSIGNED NULL,
    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_history_student (school_id, student_id, event_date),
    KEY idx_history_type (school_id, event_type, event_date),
    CONSTRAINT fk_history_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_history_student FOREIGN KEY (student_id)
        REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_history_year FOREIGN KEY (academic_year_id)
        REFERENCES academic_years (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_history_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Événements du parcours autres que les inscriptions';


SET FOREIGN_KEY_CHECKS = 1;
