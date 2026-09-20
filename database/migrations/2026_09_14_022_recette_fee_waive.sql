-- =====================================================================
--  MIGRATION — Recette finances : separer la GRILLE de la DETTE
--
--  CE QUE LA RECETTE A TROUVE
--  --------------------------
--  Le comptable detenait `fee.manage`. Cette permission ouvrait deux
--  pouvoirs de nature tres differente :
--
--    1. tenir la GRILLE TARIFAIRE — un document collectif, publie,
--       que toute l'ecole voit ; c'est bien le travail du comptable ;
--
--    2. modifier la DETTE D'UNE SEULE FAMILLE — accorder une remise,
--       annuler la ligne, la retablir, realigner les dettes deja
--       figees. Rien de tout cela n'apparait dans la grille.
--
--  Le comptable detient aussi `payment.record`. Il pouvait donc
--  encaisser 45 000 CDF en especes, annuler la dette correspondante, et
--  garder l'argent : les comptes tombaient juste, puisqu'il ne restait
--  ni dette ni recu. Verifie par requete HTTP reelle, et retrouve au
--  journal sous `student_fee.cancel` / `student_fee.discount`.
--
--
--  POURQUOI C'ETAIT UNE INCOHERENCE, PAS SEULEMENT UN RISQUE
--  ---------------------------------------------------------
--  Le module applique deja exactement le principe inverse, deux fois :
--  `payment.cancel` (phase 5B) et `expense.cancel` (phase 5D) sont
--  reservees a la direction, precisement pour que CELUI QUI ENCAISSE
--  N'EFFACE PAS LA TRACE. La remise sur une dette echappait seule a
--  cette regle.
--
--
--  CE QUE FAIT CETTE MIGRATION
--  ---------------------------
--  Elle cree `fee.waive` et l'accorde a la direction uniquement. Aucune
--  permission n'est retiree a personne : `fee.manage` garde la grille.
--  Le comptable perd seulement ce qu'il n'aurait jamais du avoir.
--
--  Une ecole qui veut vraiment confier la remise a son comptable peut
--  lui accorder `fee.waive` depuis la gestion des roles — c'est alors
--  une decision explicite de la direction, pas un defaut de conception.
-- =====================================================================

INSERT INTO permissions (code, module, name, is_platform, sort_order)
VALUES ('fee.waive', 'finance', 'Accorder une remise ou annuler une dette', 0, 108);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'fee.waive'
 WHERE r.school_id IS NULL
   AND r.code IN ('SUPER_ADMIN', 'SCHOOL_ADMIN', 'DIRECTION');
