-- =====================================================================
--  MIGRATION — Phase 7B2 : facturer un abonnement sans mentir
--
--  VERSION REJOUABLE (corrigée le 21/09/2026)
--  ==========================================
--  La première version n'était pas idempotente. Appliquée sur un
--  serveur ou une instruction ultérieure a échoué, elle laissait les
--  colonnes déjà ajoutées EN PLACE sans enregistrer la migration : le
--  passage suivant retombait sur « Duplicate column name price_amount »
--  et la base restait bloquee a mi-chemin, sans moyen d'avancer.
--
--    > Une migration qu'on ne peut pas rejouer apres un echec partiel
--    > n'est pas une migration, c'est un piege -- et il se referme sur
--    > le serveur de production, celui ou personne ne peut improviser.
--
--  Chaque ALTER est desormais garde par information_schema. Le motif
--  SET @ddl / PREPARE / EXECUTE fonctionne sur MySQL 8 ET MariaDB --
--  aucun des deux ne connait ADD COLUMN IF NOT EXISTS de facon
--  portable. Les deux INSERT etaient deja gardes par NOT EXISTS.
--
--  Verifie par execution sur les DEUX moteurs, base neuve et base
--  partiellement migree.
--
--  AUCUNE DONNEE N'EST SUPPRIMEE NI MODIFIEE.
--
--
--  DEUX MANQUES STRUCTURELS, DEUX REPONSES DEJA ECRITES DANS LE PROJET
--  ===================================================================
--
--  1. `subscriptions` NE PORTE AUCUN PRIX
--  --------------------------------------
--  Ce qu'une ecole doit se lisait donc dans `plans`. Or le catalogue
--  est modifiable : relever ESSENTIEL de 250 a 300 USD reecrirait ce
--  que doivent TOUTES les ecoles -- y compris pour des periodes deja
--  servies, deja facturees et deja payees. Un versement de 250 sur une
--  dette devenue 300 laisserait un impaye de 50 apparu du jour au
--  lendemain, sans qu'aucune ecriture ne le justifie.
--
--  C'est exactement le defaut de la phase 5A, et il a exactement la
--  meme reponse : `student_fees.amount_due` fige le montant au moment
--  de l'affectation, parce que relever le minerval en janvier ne doit
--  pas reecrire septembre.
--
--    > Une donnee qui sert de PREUVE ne se recalcule pas : elle se fige
--    > au moment ou elle engage l'etablissement.
--
--  `price_amount` est NULLABLE : les abonnements crees avant cette
--  migration n'ont pas de prix fige, et inventer le leur serait pire
--  que l'avouer. Les ecrans le disent (« tarif non fige »), le service
--  refuse d'y rattacher un versement, et ils n'entrent dans aucun
--  solde. Appliquer une offre depuis la console les renseigne.
--
--  2. LE PAIEMENT SAAS NE SAIT PAS CHANGER DE MONNAIE
--  ---------------------------------------------------
--  `subscription_payments` porte `amount` et `currency`, et rien
--  d'autre. Les offres sont libellees en USD ; en RDC, Mobile Money
--  encaisse en francs. Un versement de 700 000 CDF sur une dette de
--  250 USD n'etait donc pas representable : il fallait convertir avant
--  d'enregistrer, et le taux se perdait.
--
--  La phase 5B avait deja tranche pour la caisse scolaire. Memes
--  colonnes, MEMES NOMS -- un developpeur qui a lu `payments` lit
--  `subscription_payments` sans reapprendre :
--
--    tendered_currency / tendered_amount  la somme REMISE
--    exchange_rate                        le taux FIGE sur le versement
--    amount / currency                    la somme CREDITEE sur la dette
--
--  Le taux est obligatoire des que les monnaies different : sans lui,
--  le montant credite serait une estimation, et la quittance
--  inverifiable dix ans plus tard.
--
--  Les colonnes d'annulation completent la regle du projet : on
--  n'efface jamais une ligne d'argent, on l'annule avec un motif, un
--  auteur et une date.
--
--  Migration DDL : le runner ne l'enveloppe PAS dans une transaction,
--  et elle ne doit donc pas en ouvrir une.
-- =====================================================================


-- ---------------------------------------------------------------------
--  1. Le tarif fige sur l'abonnement
-- ---------------------------------------------------------------------
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'subscriptions'
        AND COLUMN_NAME  = 'price_amount') > 0,
    'DO 0',
    'ALTER TABLE subscriptions
        ADD COLUMN price_amount DECIMAL(12,2) NULL
            COMMENT ''Tarif FIGE de la periode (phase 7B2). NULL = abonnement anterieur a la migration 028.''
            AFTER billing_cycle,
        ADD COLUMN price_currency CHAR(3) NULL
            COMMENT ''Devise du tarif fige. NULL avec price_amount.''
            AFTER price_amount'
);

PREPARE guard FROM @ddl;
EXECUTE guard;
DEALLOCATE PREPARE guard;


-- ---------------------------------------------------------------------
--  2. Le versement SaaS sait changer de monnaie, et s'annuler
-- ---------------------------------------------------------------------
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'subscription_payments'
        AND COLUMN_NAME  = 'tendered_currency') > 0,
    'DO 0',
    'ALTER TABLE subscription_payments
        ADD COLUMN tendered_currency CHAR(3) NULL
            COMMENT ''Monnaie REMISE par l ecole (phase 7B2).''
            AFTER subscription_id,
        ADD COLUMN tendered_amount DECIMAL(14,2) NULL
            COMMENT ''Somme remise, dans sa monnaie.''
            AFTER tendered_currency,
        ADD COLUMN exchange_rate DECIMAL(16,6) NULL
            COMMENT ''Taux FIGE au paiement. NULL si remise et credit sont dans la meme monnaie.''
            AFTER tendered_amount,
        ADD COLUMN cancelled_reason VARCHAR(160) NULL AFTER status,
        ADD COLUMN cancelled_by BIGINT UNSIGNED NULL AFTER cancelled_reason,
        ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by,
        ADD KEY idx_subpay_cancelled_by (cancelled_by),
        ADD CONSTRAINT fk_subpay_cancelled_by
            FOREIGN KEY (cancelled_by) REFERENCES users (id)
            ON DELETE SET NULL ON UPDATE CASCADE'
);

PREPARE guard FROM @ddl;
EXECUTE guard;
DEALLOCATE PREPARE guard;


-- ---------------------------------------------------------------------
--  3. La permission : encaisser n'est pas negocier
--
--  `platform.billing.manage` est DISTINCTE de
--  `platform.subscription.manage`. Negocier une offre et constater
--  qu'elle est payee sont deux pouvoirs de nature differente -- la
--  lecon de la recette Finances, ou `fee.waive` a du etre separee de
--  `fee.manage` parce que celui qui encaisse ne doit pas reduire ce
--  qui est du.
--
--  FROM DUAL : portable sur MySQL comme sur MariaDB.
-- ---------------------------------------------------------------------
INSERT INTO permissions (code, module, name, description, is_platform, sort_order)
SELECT 'platform.billing.manage',
       'platform',
       'Encaisser les abonnements',
       'Enregistrer et annuler les paiements d abonnement des ecoles clientes.',
       1,
       9
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'platform.billing.manage');


-- ---------------------------------------------------------------------
--  4. Elle revient au super administrateur de la plateforme
-- ---------------------------------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'platform.billing.manage'
 WHERE r.code = 'SUPER_ADMIN'
   AND r.school_id IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );
