-- =====================================================================
--  MIGRATION — Phase 5A : grille tarifaire et dettes des élèves
--
--  Périmètre : ce que l'école RÉCLAME. Ce qu'elle ENCAISSE viendra en
--  phase 5B — et les deux ne doivent surtout pas se confondre.
--
--
--  UNE DETTE EST DUE DANS SA DEVISE
--  --------------------------------
--  En RDC, le minerval est presque toujours annoncé en dollars, et
--  souvent remis en francs congolais au taux du jour. La tentation est
--  de tout ramener a une devise de reference pour additionner.
--
--  Ce serait une faute grave. Un solde converti a un taux flottant
--  CHANGE TOUT SEUL : une famille qui ne paie rien verrait sa dette
--  varier chaque matin, et deux etats imprimes a deux jours d'intervalle
--  ne se recouperaient pas.
--
--  La regle retenue est celle que les ecoles appliquent deja au
--  guichet : une dette est due dans sa devise et soldee dans sa devise.
--  Le parent peut REMETTRE une autre monnaie — c'est alors le taux du
--  paiement qui est fige sur le paiement (phase 5B), jamais sur la
--  dette. Les soldes s'affichent par devise, jamais additionnes.
--
--
--  LE TARIF EST FIGE SUR LA DETTE
--  ------------------------------
--  `student_fees` ne pointe pas vers le tarif : il en porte une COPIE.
--  Si l'ecole releve le minerval en janvier, les eleves deja inscrits
--  gardent le montant qui leur a ete annonce en septembre.
--
--  C'est la cinquieme application du meme principe dans ce projet :
--  libelles d'orientation (phase 3), grades.max_points (4B),
--  bulletins.class_rank (4C), verrou de l'appel (4D). A chaque fois, la
--  meme lecon : un document remis a une famille ne doit jamais pouvoir
--  etre reecrit par une modification ulterieure du parametrage.
--
--  `fee_id` est donc nullable et passe a NULL si le frais disparait :
--  la dette survit a la grille qui l'a produite.
--
--
--  L'AFFECTATION EST EXPLICITE, ET SON ABSENCE EST VISIBLE
--  ------------------------------------------------------
--  Les dettes ne sont pas creees automatiquement a l'inscription : le
--  comptable decide quand sa grille est definitive. Mais un eleve sans
--  dette affectee ne doit surtout pas ressembler a un eleve en regle —
--  c'est exactement le piege de l'appel partiel corrige en phase 4D.
--  Les ecrans comptent donc les inscrits SANS affectation et les
--  signalent.
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
--  LA GRILLE TARIFAIRE
--
--  Une ligne = une ligne du tableau que l'ecole affiche a l'entree.
--  Les tranches ne sont pas une sous-table : « Minerval 1ere tranche »
--  est une ligne, avec son echeance. C'est ainsi que la grille est
--  imprimee, et le modele doit ressembler au document reel.
--  `group_label` permet de les regrouper a l'affichage sans les lier.
-- ---------------------------------------------------------------------
CREATE TABLE fees (
    id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    school_id         BIGINT UNSIGNED   NOT NULL,
    academic_year_id  BIGINT UNSIGNED   NOT NULL,
    code              VARCHAR(30)       NOT NULL,
    name              VARCHAR(120)      NOT NULL,
    group_label       VARCHAR(80)       NULL COMMENT 'Regroupement d affichage : Minerval, Frais annexes',
    currency          CHAR(3)           NOT NULL DEFAULT 'USD',
    amount            DECIMAL(12,2)     NOT NULL,

    -- Portee : toute l'ecole, un niveau, ou une classe precise.
    -- Le minerval varie souvent du primaire aux humanites.
    scope             ENUM('school','level','classroom') NOT NULL DEFAULT 'school',
    level_id          SMALLINT UNSIGNED NULL,
    classroom_id      BIGINT UNSIGNED   NULL,

    due_on            DATE              NULL COMMENT 'Echeance annoncee aux familles',
    is_mandatory      TINYINT(1)        NOT NULL DEFAULT 1 COMMENT '0 = optionnel (transport, cantine)',
    is_active         TINYINT(1)        NOT NULL DEFAULT 1,
    position          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by        BIGINT UNSIGNED   NULL,
    created_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_code (school_id, academic_year_id, code),
    KEY idx_fee_year (school_id, academic_year_id, is_active),
    KEY idx_fee_level (level_id),
    KEY idx_fee_classroom (classroom_id),

    CONSTRAINT fk_fee_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fee_year FOREIGN KEY (academic_year_id)
        REFERENCES academic_years (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fee_level FOREIGN KEY (level_id)
        REFERENCES education_levels (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_fee_classroom FOREIGN KEY (classroom_id)
        REFERENCES classrooms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fee_author FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grille tarifaire d une annee — ce que l ecole reclame';


-- ---------------------------------------------------------------------
--  LA DETTE D'UN ELEVE
--
--  Rattachee a l'INSCRIPTION, pas a l'eleve : un redoublant doit deux
--  minervals, un par annee, et l'archive de l'an dernier ne bouge plus.
--
--  `amount_due` et `label` sont des copies figees. `fee_id` n'est
--  conserve que pour le rapprochement avec la grille ; il peut devenir
--  NULL sans que la dette disparaisse.
--
--  La remise (`discount_amount`) est portee par la dette et non par un
--  bareme separe : les exonerations en RDC sont individuelles et
--  motivees — enfant du personnel, orphelin, fratrie, bourse. Le motif
--  est obligatoire des que la remise est non nulle : une exoneration
--  sans justification ecrite est une porte ouverte.
-- ---------------------------------------------------------------------
CREATE TABLE student_fees (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id         BIGINT UNSIGNED NOT NULL,
    enrollment_id     BIGINT UNSIGNED NOT NULL,
    fee_id            BIGINT UNSIGNED NULL COMMENT 'NULL si la ligne de grille a disparu : la dette survit',

    label             VARCHAR(120)    NOT NULL COMMENT 'Fige : le libelle annonce a la famille',
    currency          CHAR(3)         NOT NULL,
    amount_due        DECIMAL(12,2)   NOT NULL COMMENT 'Fige : le tarif annonce a la famille',
    discount_amount   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    discount_reason   VARCHAR(160)    NULL,
    due_on            DATE            NULL,

    -- Une dette ne se supprime pas : elle s'annule, avec un motif.
    -- Supprimer effacerait la trace d'un montant reclame a une famille.
    is_cancelled      TINYINT(1)      NOT NULL DEFAULT 0,
    cancelled_reason  VARCHAR(160)    NULL,
    cancelled_by      BIGINT UNSIGNED NULL,
    cancelled_at      DATETIME        NULL,

    assigned_by       BIGINT UNSIGNED NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- Un frais ne peut etre affecte deux fois a la meme inscription.
    -- C'est ce qui rend l'affectation rejouable sans creer de doublons.
    UNIQUE KEY uq_student_fee (school_id, enrollment_id, fee_id),
    KEY idx_student_fee_enrollment (school_id, enrollment_id, is_cancelled),
    KEY idx_student_fee_fee (fee_id),
    KEY idx_student_fee_due (school_id, due_on),

    CONSTRAINT fk_student_fee_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_fee_enrollment FOREIGN KEY (enrollment_id)
        REFERENCES enrollments (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_fee_fee FOREIGN KEY (fee_id)
        REFERENCES fees (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_student_fee_canceller FOREIGN KEY (cancelled_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_student_fee_assigner FOREIGN KEY (assigned_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dette figee d une inscription — ce qui a ete reclame a la famille';


-- ---------------------------------------------------------------------
--  DEVISE DE REFERENCE DE L'ECOLE
--
--  Sert de valeur par defaut a la saisie d'un frais, rien de plus.
--  Aucune conversion n'est faite a partir de ce parametre : le changer
--  ne doit modifier AUCUNE dette existante.
-- ---------------------------------------------------------------------
INSERT INTO school_settings (school_id, setting_key, setting_value, setting_type)
SELECT s.id, 'finance.currency', 'USD', 'string'
  FROM schools s
 WHERE NOT EXISTS (
       SELECT 1 FROM school_settings x
        WHERE x.school_id = s.id AND x.setting_key = 'finance.currency'
 );

COMMIT;
