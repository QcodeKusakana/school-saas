-- =====================================================================
--  MIGRATION — Phase 4D : Présences
--
--  Périmètre : l'appel d'une classe pour une journée, et ce qui en
--  découle — absences, retards, justifications, statistiques.
--
--
--  DEUX TABLES, ET LA PREMIÈRE EST LA PLUS IMPORTANTE
--  -------------------------------------------------
--  On pourrait n'enregistrer que les absences : une ligne par élève
--  manquant, rien pour les présents. Ce serait une erreur, pour une
--  raison que ce projet a déjà payée une fois.
--
--  En phase 4B, le tableau de bord comptait les LIGNES de cotes pour
--  décider qu'une colonne était complète. Une colonne vierge affichait
--  donc « complet », et le préfet pouvait publier des bulletins sur une
--  classe sans une seule note. Le même piège attend ici : sans trace de
--  l'appel lui-même, une journée où PERSONNE n'a fait l'appel est
--  indiscernable d'une journée où personne n'était absent.
--
--  `attendance_sessions` est cette trace. Elle dit « l'appel a été fait,
--  par untel, à telle heure ». `attendance_records` dit ce qu'il a
--  donné. Une école pourra donc répondre à la question qui compte
--  vraiment : QUELLES CLASSES N'ONT PAS FAIT L'APPEL AUJOURD'HUI.
--
--
--  UNE ABSENCE JUSTIFIÉE RESTE UNE ABSENCE
--  ---------------------------------------
--  La justification n'est pas un quatrième statut à côté de présent,
--  absent et en retard : c'est un ATTRIBUT de l'absence. Les confondre
--  ferait disparaître les absences justifiées des statistiques, alors
--  qu'un élève absent trente jours avec motif reste un élève qui a
--  manqué trente jours de cours.
--
--
--  LA JOURNÉE, PAS L'HEURE DE COURS
--  --------------------------------
--  L'appel est ici quotidien, avec une distinction matin / après-midi.
--  L'appel par heure de cours exigerait un EMPLOI DU TEMPS pour savoir
--  quel cours a lieu quand — module qui n'existe pas encore. Le
--  rattacher à autre chose reviendrait à inventer un ancrage.
--
--  La colonne `slot` accepte déjà la demi-journée : une école qui
--  n'appelle qu'une fois utilise 'day' et ne voit jamais la différence.
--
--
--  RATTACHEMENT À UNE PÉRIODE
--  --------------------------
--  Volontairement absent. Une session porte une DATE ; la période se
--  déduit de cette date — à condition que grade_periods.starts_on et
--  ends_on soient renseignées, ce qu'aucun écran ne fait aujourd'hui.
--  Stocker un grade_period_id figerait un rattachement que rien ne
--  permet encore de calculer. Les statistiques portent donc sur un
--  intervalle de dates, explicite et vérifiable.
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
--  1. L'APPEL A-T-IL ÉTÉ FAIT ?
-- ---------------------------------------------------------------------
CREATE TABLE attendance_sessions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id     BIGINT UNSIGNED NOT NULL,
    classroom_id  BIGINT UNSIGNED NOT NULL,

    session_date  DATE            NOT NULL,
    slot          ENUM('day', 'morning', 'afternoon') NOT NULL DEFAULT 'day'
                  COMMENT 'Journée entière, ou demi-journée pour les écoles qui appellent deux fois',

    -- Qui a fait l'appel, et quand. Un registre sans signature ne vaut
    -- rien devant une contestation de famille.
    taken_by      BIGINT UNSIGNED NULL,
    taken_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Verrouillage : au-delà, seule la direction corrige. Même principe
    -- que le verrouillage des périodes de notes.
    is_locked     TINYINT(1)      NOT NULL DEFAULT 0,
    locked_at     DATETIME        NULL,
    locked_by     BIGINT UNSIGNED NULL,

    comment       VARCHAR(255)    NULL,

    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Un seul appel par classe, par jour et par moment.
    UNIQUE KEY uq_attendance_session (school_id, classroom_id, session_date, slot),

    -- « Quelles classes n'ont pas fait l'appel le 14 ? » et « tout
    -- l'historique de cette classe » : les deux requêtes du module.
    KEY idx_attendance_date (school_id, session_date),
    KEY idx_attendance_classroom (school_id, classroom_id, session_date),

    CONSTRAINT fk_attendance_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_classroom FOREIGN KEY (classroom_id)
        REFERENCES classrooms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_taken_by FOREIGN KEY (taken_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_locked_by FOREIGN KEY (locked_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Trace de l appel : qui l a fait, quand, pour quelle classe';


-- ---------------------------------------------------------------------
--  2. CE QUE L'APPEL A DONNÉ
-- ---------------------------------------------------------------------
CREATE TABLE attendance_records (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id      BIGINT UNSIGNED NOT NULL,
    session_id     BIGINT UNSIGNED NOT NULL,

    -- L'inscription, et non l'élève : un élève réinscrit l'année
    -- suivante ne doit pas hériter des absences de l'année passée.
    enrollment_id  BIGINT UNSIGNED NOT NULL,

    status         ENUM('present', 'absent', 'late') NOT NULL DEFAULT 'present',

    -- Retard : la durée compte, un quart d'heure n'est pas une heure.
    minutes_late   SMALLINT UNSIGNED NULL,

    -- La justification est un ATTRIBUT de l'absence, pas un statut.
    -- Un élève absent trente jours avec motif a manqué trente jours.
    is_justified   TINYINT(1)      NOT NULL DEFAULT 0,
    justification  VARCHAR(255)    NULL,
    justified_by   BIGINT UNSIGNED NULL,
    justified_at   DATETIME        NULL,

    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_attendance_record (school_id, session_id, enrollment_id),

    -- « Combien d absences pour cet élève cette année ? »
    KEY idx_record_enrollment (school_id, enrollment_id, status),

    CONSTRAINT fk_record_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_record_session FOREIGN KEY (session_id)
        REFERENCES attendance_sessions (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_record_enrollment FOREIGN KEY (enrollment_id)
        REFERENCES enrollments (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_record_justified_by FOREIGN KEY (justified_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Présence, absence ou retard d un élève à un appel donné';
