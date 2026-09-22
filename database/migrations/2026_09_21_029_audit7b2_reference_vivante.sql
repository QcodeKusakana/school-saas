-- =====================================================================
--  MIGRATION — Audit 7B2 : une reference annulee doit se liberer
--
--  LE DEFAUT, MESURE PAR EXECUTION
--  ================================
--  Un versement Mobile Money est saisi avec une erreur de frappe sur le
--  montant : 333 333 CDF au lieu de 350 000. L'editeur l'annule, avec
--  motif -- la ligne reste, c'est la regle du projet et elle est bonne.
--
--  Il ressaisit ensuite le VRAI versement. Refuse :
--
--    « Un versement portant deja la reference PRB-REF-001 chez MPESA
--      est enregistre. Un meme versement ne s'encaisse pas deux fois. »
--
--  Or le versement existe dans la vraie vie et porte exactement une
--  reference, celle de l'operateur. L'editeur n'a plus que deux
--  sorties, toutes deux pires que le doublon que le garde-fou voulait
--  empecher :
--
--    · inventer une fausse reference -- un mensonge dans une piece
--      comptable, et la quittance devient invérifiable ;
--    · ne rien enregistrer -- l'ecole apparait devoir de l'argent
--      qu'elle a paye, et le recouvrement la relance a tort.
--
--    > Un garde-fou contre le doublon qui interdit AUSSI la correction
--    > ne protege pas la comptabilite : il la force a mentir.
--
--  LA CAUSE
--  ========
--  `uq_subpay_reference (provider, reference)` est INCONDITIONNELLE :
--  elle compte les lignes annulees comme si elles portaient encore de
--  l'argent. Or une ligne annulee ne compte dans aucun solde -- c'est
--  precisement ce que l'annulation veut dire.
--
--  Corriger le seul controle PHP n'aurait rien donne : l'INSERT serait
--  tombe sur l'index. L'invariant doit changer, pas son annonce.
--
--    > Un invariant qu'aucune requete ne sait verifier n'est pas un
--    > invariant, c'est une intention. Ici l'inverse : un invariant que
--    > la base verifie trop largement n'est pas une protection, c'est
--    > une impasse.
--
--  LA SOLUTION
--  ===========
--  MySQL et MariaDB ne connaissent pas l'index unique partiel, mais une
--  colonne GENEREE le simule exactement : elle ne porte la reference
--  que tant que la ligne est VIVANTE, et NULL sinon. Dans un index
--  unique, plusieurs NULL cohabitent -- les lignes annulees ne se
--  genent donc plus entre elles, tandis que deux lignes vivantes
--  portant la meme reference restent refusees par la base.
--
--  Le meme motif resoudra, le jour venu, le « UNIQUE conditionnel sur
--  subscriptions.status » consigne en dette depuis la phase 7A.
--
--  UNE LIGNE MORTE, C'EST :
--    · annulee    -- cancelled_at IS NOT NULL ;
--    · en echec   -- status = 'failed' : le versement n'a jamais eu
--                    lieu, l'operateur l'a rejete, et la nouvelle
--                    tentative portera la meme reference.
--
--  Un versement REMBOURSE (`refunded`) garde la sienne : la transaction
--  a bien eu lieu, elle a seulement ete renversee ensuite.
--
--  AUCUNE DONNEE N'EST SUPPRIMEE NI MODIFIEE : la colonne se calcule,
--  et l'index se reconstruit.
--
--  Migration DDL : le runner ne l'enveloppe PAS dans une transaction,
--  et elle ne doit donc pas en ouvrir une.
-- =====================================================================

-- ---------------------------------------------------------------------
--  REJOUABLE : chaque instruction est gardee par information_schema.
--  Une migration qu'on ne peut pas rejouer apres un echec partiel est
--  un piege qui se referme sur le serveur de production. Motif portable
--  MySQL 8 / MariaDB -- ni l'un ni l'autre n'offre ADD COLUMN IF NOT
--  EXISTS de facon portable.
-- ---------------------------------------------------------------------

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'subscription_payments'
        AND COLUMN_NAME  = 'reference_live') > 0,
    'DO 0',
    'ALTER TABLE subscription_payments
        ADD COLUMN reference_live VARCHAR(100)
            GENERATED ALWAYS AS (
                CASE WHEN cancelled_at IS NULL AND status <> ''failed''
                     THEN reference ELSE NULL END
            ) STORED
            COMMENT ''Reference tant que la ligne compte. NULL des qu elle est annulee ou en echec (audit 7B2).''
            AFTER reference'
);

PREPARE guard FROM @ddl;
EXECUTE guard;
DEALLOCATE PREPARE guard;


SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'subscription_payments'
        AND INDEX_NAME   = 'uq_subpay_reference') > 0,
    'ALTER TABLE subscription_payments DROP INDEX uq_subpay_reference',
    'DO 0'
);

PREPARE guard FROM @ddl;
EXECUTE guard;
DEALLOCATE PREPARE guard;


SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'subscription_payments'
        AND INDEX_NAME   = 'uq_subpay_reference_live') > 0,
    'DO 0',
    'ALTER TABLE subscription_payments
        ADD UNIQUE KEY uq_subpay_reference_live (provider, reference_live)'
);

PREPARE guard FROM @ddl;
EXECUTE guard;
DEALLOCATE PREPARE guard;
