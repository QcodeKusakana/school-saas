-- =====================================================================
--  MIGRATION — Phase 5B : encaissements, reçus et affectation
--
--  La 5A pose ce que l'ecole RECLAME. Celle-ci pose ce qu'elle ENCAISSE.
--
--
--  LE TAUX EST FIGE SUR LE PAIEMENT, JAMAIS SUR LA DETTE
--  ----------------------------------------------------
--  Une dette est due dans sa devise (5A). Le parent, lui, remet ce qu'il
--  a : 140 000 CDF pour un minerval de 50 USD. Le paiement porte donc
--  TROIS informations, et les trois sont figees a la seconde ou le
--  caissier valide :
--
--      ce qui a ete REMIS      140 000,00 CDF
--      le TAUX applique          2 800,000000 CDF pour 1 USD
--      ce qui est CREDITE           50,00 USD
--
--  Sans le taux fige, un reçu imprime hier ne se recouperait plus avec
--  la caisse aujourd'hui. Avec lui, un reçu reste verifiable dix ans
--  plus tard sans connaitre le cours du jour. Sixieme application du
--  principe de gel dans ce projet.
--
--
--  LE NUMERO DE REÇU NE CONNAIT NI TROU NI DOUBLON
--  ----------------------------------------------
--  Un compteur dedie, verrouille par SELECT ... FOR UPDATE dans la
--  transaction, sur le modele des matricules (phase 3). Deux caissiers
--  qui encaissent a la meme seconde obtiennent deux numeros distincts et
--  consecutifs.
--
--  `student_counters` n'est PAS reutilise : son nom dirait le contraire
--  de ce qu'il ferait, et un nom trompeur coute plus cher qu'une table.
--
--  Un numero attribue est consomme A JAMAIS, meme si le paiement est
--  annule. C'est ce qui rend la sequence verifiable : un trou signifie
--  une ligne effacee, donc une fraude ou un incident — jamais une
--  annulation reguliere.
--
--
--  UN PAIEMENT S'ANNULE, IL NE SE MODIFIE PAS
--  ------------------------------------------
--  Le plan initial parlait de contre-passation — une ecriture miroir.
--  Elle est ecartee ici, et c'est un changement assume :
--
--   · elle exigerait des allocations NEGATIVES sur chaque dette touchee,
--     donc deux mecaniques de lecture partout ;
--   · elle doublerait les lignes de la liste des paiements sans rien
--     apprendre au caissier ;
--   · l'ecole remet un reçu PAPIER : quand il est annule, on le raye et
--     on ecrit le motif. Il n'existe pas de culture de l'avoir sur des
--     frais scolaires ;
--   · le reste du projet annule sans supprimer (dettes 5A, registres
--     d'appel 4D). Deux mecaniques d'annulation dans le meme module
--     seraient une source d'erreur.
--
--  La tracabilite est identique : la ligne survit, le numero reste
--  consomme, l'auteur et le motif sont conserves.
--
--
--  L'AFFECTATION EST EXPLICITE
--  ---------------------------
--  Un paiement ne credite pas un solde global : il se repartit sur des
--  DETTES NOMMEES. Sans cela, impossible de repondre a « qui n'a pas
--  paye les frais d'examen » — question tres reelle, ces frais remontant
--  a l'Etat.
--
--  Le reliquat non affecte est conserve tel quel : c'est une AVANCE. En
--  RDC un parent paie souvent en avance sur la tranche suivante ; la
--  refuser obligerait le caissier a mentir sur le montant reçu.
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
--  LE COMPTEUR DE REÇUS
--
--  Une sequence par ecole et par cle. La cle est l'annee scolaire : les
--  ecoles numerotent leurs reçus par annee, et un compteur unique sur
--  dix ans produirait des numeros a six chiffres des la troisieme.
-- ---------------------------------------------------------------------
CREATE TABLE receipt_counters (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id    BIGINT UNSIGNED NOT NULL,
    counter_key  VARCHAR(30)     NOT NULL COMMENT 'Code de l annee scolaire',
    last_number  INT UNSIGNED    NOT NULL DEFAULT 0,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipt_counter (school_id, counter_key),
    CONSTRAINT fk_receipt_counter_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sequence des numeros de reçu — sans trou ni doublon, meme a deux guichets';


-- ---------------------------------------------------------------------
--  LES ENCAISSEMENTS
-- ---------------------------------------------------------------------
CREATE TABLE payments (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id          BIGINT UNSIGNED NOT NULL,
    enrollment_id      BIGINT UNSIGNED NOT NULL,

    receipt_no         VARCHAR(30)     NOT NULL COMMENT 'Numero imprime sur le reçu',
    receipt_seq        INT UNSIGNED    NOT NULL COMMENT 'Rang dans la sequence — un trou signale un incident',
    paid_on            DATE            NOT NULL,

    -- CE QUI A ETE REMIS AU GUICHET.
    tendered_currency  CHAR(3)         NOT NULL,
    tendered_amount    DECIMAL(14,2)   NOT NULL,

    -- LE TAUX APPLIQUE, FIGE. NULL quand aucune conversion n a eu lieu.
    -- Exprime en unites de la devise REMISE pour une unite de la devise
    -- CREDITEE : 2800 CDF pour 1 USD.
    exchange_rate      DECIMAL(16,6)   NULL,

    -- CE QUI EST PORTE AU CREDIT DES DETTES.
    credited_currency  CHAR(3)         NOT NULL,
    credited_amount    DECIMAL(12,2)   NOT NULL,

    method             ENUM('cash','mobile_money','bank','cheque','other') NOT NULL DEFAULT 'cash',

    -- Reference externe : identifiant de transaction Mobile Money,
    -- numero de bordereau bancaire, numero de cheque. Prevu des
    -- maintenant pour ne pas migrer en phase 7.
    reference          VARCHAR(80)     NULL,
    -- Statut de l operation externe. « settled » pour les especes :
    -- l argent est dans la caisse des que le reçu est signe.
    external_status    ENUM('settled','pending','failed') NOT NULL DEFAULT 'settled',

    payer_name         VARCHAR(120)    NULL COMMENT 'Qui a paye, si ce n est pas le tuteur principal',
    note               VARCHAR(255)    NULL,

    -- ANNULATION — jamais de suppression, jamais de modification.
    is_cancelled       TINYINT(1)      NOT NULL DEFAULT 0,
    cancelled_reason   VARCHAR(160)    NULL,
    cancelled_by       BIGINT UNSIGNED NULL,
    cancelled_at       DATETIME        NULL,

    received_by        BIGINT UNSIGNED NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_receipt (school_id, receipt_no),
    KEY idx_payment_enrollment (school_id, enrollment_id, is_cancelled),
    KEY idx_payment_date (school_id, paid_on),
    KEY idx_payment_seq (school_id, receipt_seq),

    CONSTRAINT fk_payment_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_enrollment FOREIGN KEY (enrollment_id)
        REFERENCES enrollments (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_canceller FOREIGN KEY (cancelled_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_payment_receiver FOREIGN KEY (received_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Encaissements — ce que l ecole a reçu, avec son taux fige';


-- ---------------------------------------------------------------------
--  LA REPARTITION SUR LES DETTES
--
--  Pas de cle etrangere vers `student_fees` : la migration 017 a montre
--  qu'un second chemin de cascade vers une table deja supprimee par le
--  premier fait echouer la suppression d'une ecole. school_id et
--  payment_id suffisent a la cascade ; l index porte la jointure.
-- ---------------------------------------------------------------------
CREATE TABLE payment_allocations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED NOT NULL,
    payment_id      BIGINT UNSIGNED NOT NULL,
    student_fee_id  BIGINT UNSIGNED NOT NULL,
    currency        CHAR(3)         NOT NULL COMMENT 'Toujours celle de la dette ET du credit',
    amount          DECIMAL(12,2)   NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- Une dette n apparait qu une fois par paiement : corriger une
    -- repartition remplace la ligne au lieu d en ajouter une seconde.
    UNIQUE KEY uq_allocation (school_id, payment_id, student_fee_id),
    KEY idx_allocation_fee (school_id, student_fee_id),

    CONSTRAINT fk_allocation_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_allocation_payment FOREIGN KEY (payment_id)
        REFERENCES payments (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ce que chaque paiement solde, dette par dette';


-- ---------------------------------------------------------------------
--  PARAMETRES DE CAISSE
--
--  Le taux est une VALEUR PAR DEFAUT du formulaire, jamais une source
--  de calcul retroactive : le changer ne doit modifier aucun paiement
--  deja enregistre.
-- ---------------------------------------------------------------------
INSERT INTO school_settings (school_id, setting_key, setting_value, setting_type)
SELECT s.id, 'finance.usd_rate', '2800', 'float'
  FROM schools s
 WHERE NOT EXISTS (
       SELECT 1 FROM school_settings x
        WHERE x.school_id = s.id AND x.setting_key = 'finance.usd_rate'
 );

INSERT INTO school_settings (school_id, setting_key, setting_value, setting_type)
SELECT s.id, 'finance.receipt_prefix', 'REC', 'string'
  FROM schools s
 WHERE NOT EXISTS (
       SELECT 1 FROM school_settings x
        WHERE x.school_id = s.id AND x.setting_key = 'finance.receipt_prefix'
 );

COMMIT;
