-- =====================================================================
--  MIGRATION — Audit 5D : ramener les montants en francs à l'entier
--
--  AVERTISSEMENT — CETTE MIGRATION MODIFIE DES MONTANTS EXISTANTS
--  --------------------------------------------------------------
--  Elle ne supprime aucune ligne. Elle ARRONDIT à l'unite les sommes
--  libellees en CDF. Un montant de 56 022,41 CDF devient 56 022 CDF.
--  L'ecart maximal par ligne est de 0,49 CDF, soit environ 0,0002 USD.
--
--  Sauvegardez la base avant de l'appliquer si l'ecole est deja en
--  service. La correction est faite dans cet ordre precis pour qu'aucun
--  solde ne devienne negatif a aucun instant : les DETTES d'abord, les
--  ENCAISSEMENTS ensuite, les IMPUTATIONS en dernier, puis un plafond
--  qui ramene toute imputation au reste reellement du.
--
--
--  POURQUOI
--  --------
--  `finance_decimals()` connaissait depuis la phase 5A la verite : le
--  franc congolais ne se compte pas en centimes. Mais elle ne servait
--  qu'a l'AFFICHAGE. Les calculs, eux, arrondissaient toujours a deux
--  decimales : `round($remis / $taux, 2)`.
--
--  Consequence, verifiee par execution a l'audit 5D : 20 USD convertis
--  au guichet donnaient 56 022,41 CDF. L'ecran affichait « 56 022 CDF ».
--  Le reste du imprime sur le recu n'etait donc pas le reste du, et une
--  dette pouvait conserver un solde de 0,41 CDF — une piece qui
--  n'existe pas. Cette dette restait dans l'etat des impayes en
--  affichant « 0 CDF » : introuvable pour le comptable, insoldable pour
--  la famille, eternelle.
--
--  Le code ne produit plus de centimes de franc (finance_round()).
--  Cette migration nettoie ce qui a deja ete ecrit.
--
--
--  PAS DE TRANSACTION ICI
--  ----------------------
--  `database/runner.php` ouvre lui-meme une transaction pour toute
--  migration SANS DDL. Ecrire START TRANSACTION / COMMIT dans ce
--  fichier ferait echouer le commit du runner sur « There is no active
--  transaction ».
-- =====================================================================

-- 1. La grille tarifaire.
UPDATE fees
   SET amount = ROUND(amount, 0)
 WHERE currency = 'CDF'
   AND amount <> ROUND(amount, 0);

-- 2. Les dettes figees et leurs remises.
UPDATE student_fees
   SET amount_due      = ROUND(amount_due, 0),
       discount_amount = ROUND(discount_amount, 0)
 WHERE currency = 'CDF'
   AND (amount_due <> ROUND(amount_due, 0)
        OR discount_amount <> ROUND(discount_amount, 0));

-- 3. Les encaissements — la monnaie remise et la monnaie creditee sont
--    arrondies separement : un recu peut melanger USD et CDF.
UPDATE payments
   SET tendered_amount = ROUND(tendered_amount, 0)
 WHERE tendered_currency = 'CDF'
   AND tendered_amount <> ROUND(tendered_amount, 0);

UPDATE payments
   SET credited_amount = ROUND(credited_amount, 0)
 WHERE credited_currency = 'CDF'
   AND credited_amount <> ROUND(credited_amount, 0);

-- 4. Les imputations.
UPDATE payment_allocations
   SET amount = ROUND(amount, 0)
 WHERE currency = 'CDF'
   AND amount <> ROUND(amount, 0);

-- 5. LE PLAFOND DE SECURITE.
--
--    Arrondir une imputation VERS LE HAUT pourrait la faire depasser le
--    reste reellement du et creer un trop-percu — exactement ce que le
--    module refuse de savoir representer. On ramene donc toute
--    imputation excedentaire au net de sa dette.
--
--    La sous-requete est calculee AVANT la mise a jour par le moteur :
--    elle lit le total impute sur chaque dette, dette par dette.
UPDATE payment_allocations pa
  JOIN student_fees sf
    ON sf.id = pa.student_fee_id
   AND sf.school_id = pa.school_id
   SET pa.amount = GREATEST(0, sf.amount_due - sf.discount_amount)
 WHERE pa.currency = 'CDF'
   AND pa.amount > GREATEST(0, sf.amount_due - sf.discount_amount);

-- 6. Les sorties de caisse.
UPDATE expenses
   SET amount = ROUND(amount, 0)
 WHERE currency = 'CDF'
   AND amount <> ROUND(amount, 0);
