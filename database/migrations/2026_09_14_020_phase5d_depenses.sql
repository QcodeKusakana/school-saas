-- =====================================================================
--  MIGRATION — Phase 5D : dépenses et situation de caisse
--
--  Dernier volet de la phase 5. Les 5A a 5C decrivaient l'argent qui
--  ENTRE ; celle-ci decrit l'argent qui SORT, et permet enfin de
--  repondre a la question que tout directeur pose le soir : COMBIEN
--  RESTE-T-IL EN CAISSE.
--
--
--  UNE SORTIE DE CAISSE EST UN DOCUMENT NUMEROTE
--  ---------------------------------------------
--  Meme discipline que les reçus : sequence sans trou ni doublon,
--  annulation motivee, jamais de suppression. Un trou dans les bons de
--  sortie ne signale pas une erreur de saisie — il signale un
--  detournement. C'est precisement ce qu'une ecole doit pouvoir
--  verifier d'un coup d'oeil.
--
--
--  LES CATEGORIES VIVENT EN BASE
--  -----------------------------
--  Regle du projet : ne pas coder les referentiels en dur. Une table
--  GLOBALE, partagee par toutes les ecoles, sur le modele des niveaux
--  scolaires. Le detail de chaque depense va dans sa description ;
--  la categorie sert aux etats.
--
--
--  QUI ENREGISTRE UNE DEPENSE NE L'ANNULE PAS
--  ------------------------------------------
--  `expense.manage` appartient au COMPTABLE. L'annulation demande une
--  permission distincte, reservee a la direction — exactement la
--  separation deja posee entre payment.record et payment.cancel.
--
--
--  UN COMPTEUR DE PLUS, ET C'EST ASSUME
--  ------------------------------------
--  Voici la troisieme table de compteurs de forme identique, apres
--  student_counters (phase 3) et receipt_counters (5B). Une table
--  generique `counters` aurait ete plus propre des le depart.
--
--  Elle ne sera PAS introduite ici : consolider signifierait migrer les
--  sequences des matricules et des reçus — du code d'argent teste et en
--  service — pour zero gain fonctionnel. La dette est consignee dans
--  claude/etat-du-projet.md ; elle se paiera si une quatrieme sequence
--  apparait.
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
--  CATEGORIES DE DEPENSES — table GLOBALE
--
--  Seedee avec les postes reels d'une ecole congolaise. Une ecole qui
--  ne trouve pas son poste exact prend le plus proche et precise dans
--  la description : mieux vaut une categorie approximative et des
--  etats comparables entre ecoles, qu'un champ libre ou « salaires »,
--  « salaire » et « remunerations » coexistent.
-- ---------------------------------------------------------------------
CREATE TABLE expense_categories (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(30)       NOT NULL,
    name        VARCHAR(100)      NOT NULL,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1)        NOT NULL DEFAULT 1,
    created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expense_category_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Postes de depense — referentiel partage par toutes les ecoles';

INSERT INTO expense_categories (code, name, position) VALUES
    ('SALAIRES',      'Salaires et primes du personnel',       10),
    ('FOURNITURES',   'Fournitures scolaires et de bureau',     20),
    ('ENTRETIEN',     'Entretien et reparations',               30),
    ('ELECTRICITE',   'Electricite',                            40),
    ('EAU',           'Eau',                                    50),
    ('CARBURANT',     'Carburant et transport',                 60),
    ('LOYER',         'Loyer et charges locatives',             70),
    ('COMMUNICATION', 'Telephone et internet',                  80),
    ('EXAMENS',       'Frais d examens et droits officiels',    90),
    ('FORMATION',     'Formation et perfectionnement',         100),
    ('EQUIPEMENT',    'Equipement et mobilier',                110),
    ('SANTE',         'Sante, hygiene et securite',            120),
    ('BANQUE',        'Frais bancaires et de transfert',       130),
    ('DIVERS',        'Divers',                                900);


-- ---------------------------------------------------------------------
--  LE COMPTEUR DES BONS DE SORTIE
-- ---------------------------------------------------------------------
CREATE TABLE expense_counters (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id    BIGINT UNSIGNED NOT NULL,
    counter_key  VARCHAR(30)     NOT NULL COMMENT 'Code de l annee scolaire',
    last_number  INT UNSIGNED    NOT NULL DEFAULT 0,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expense_counter (school_id, counter_key),
    CONSTRAINT fk_expense_counter_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sequence des bons de sortie — sans trou ni doublon';


-- ---------------------------------------------------------------------
--  LES DEPENSES
--
--  Pas de conversion de devise ici, et c'est la regle du module depuis
--  la 5A : une depense est engagee dans sa monnaie. Additionner des
--  dollars et des francs donnerait un total faux dans les deux.
-- ---------------------------------------------------------------------
CREATE TABLE expenses (
    id                 BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    school_id          BIGINT UNSIGNED   NOT NULL,
    academic_year_id   BIGINT UNSIGNED   NOT NULL,
    category_id        SMALLINT UNSIGNED NULL COMMENT 'NULL si la categorie a ete desactivee',

    voucher_no         VARCHAR(30)       NOT NULL COMMENT 'Numero imprime sur le bon de sortie',
    voucher_seq        INT UNSIGNED      NOT NULL COMMENT 'Rang dans la sequence — un trou signale un incident',
    spent_on           DATE              NOT NULL,

    currency           CHAR(3)           NOT NULL,
    amount             DECIMAL(12,2)     NOT NULL,

    beneficiary        VARCHAR(160)      NOT NULL COMMENT 'A qui l argent a ete remis',
    description        VARCHAR(255)      NOT NULL,

    method             ENUM('cash','mobile_money','bank','cheque','other') NOT NULL DEFAULT 'cash',
    reference          VARCHAR(80)       NULL COMMENT 'Reference externe : transaction, bordereau, cheque',
    supporting_doc     VARCHAR(80)       NULL COMMENT 'Piece justificative fournie : facture, reçu du fournisseur',

    -- ANNULATION — jamais de suppression, jamais de modification.
    is_cancelled       TINYINT(1)        NOT NULL DEFAULT 0,
    cancelled_reason   VARCHAR(160)      NULL,
    cancelled_by       BIGINT UNSIGNED   NULL,
    cancelled_at       DATETIME          NULL,

    recorded_by        BIGINT UNSIGNED   NULL,
    created_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_expense_voucher (school_id, voucher_no),
    KEY idx_expense_date (school_id, spent_on),
    KEY idx_expense_year (school_id, academic_year_id, is_cancelled),
    KEY idx_expense_category (category_id),
    KEY idx_expense_seq (school_id, voucher_seq),

    CONSTRAINT fk_expense_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_expense_year FOREIGN KEY (academic_year_id)
        REFERENCES academic_years (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_expense_category FOREIGN KEY (category_id)
        REFERENCES expense_categories (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_expense_canceller FOREIGN KEY (cancelled_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_expense_recorder FOREIGN KEY (recorded_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sorties de caisse — ce que l ecole a depense';


-- ---------------------------------------------------------------------
--  LA PERMISSION D'ANNULER
--
--  Distincte de expense.manage : celui qui engage la depense ne doit
--  pas pouvoir en effacer la trace. Accordee a la direction et a
--  l'administrateur de l'ecole, jamais au comptable.
-- ---------------------------------------------------------------------
INSERT INTO permissions (code, module, name, is_platform, sort_order)
VALUES ('expense.cancel', 'finance', 'Annuler une depense', 0, 107);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'expense.cancel'
 WHERE r.school_id IS NULL
   AND r.code IN ('SUPER_ADMIN', 'SCHOOL_ADMIN', 'DIRECTION');

COMMIT;
