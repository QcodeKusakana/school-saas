-- =====================================================================
--  Phase 11C — dire la vérité sur `receipt.print`
-- =====================================================================
--
-- `receipt.print` est semée depuis la phase 1 et n'a JAMAIS gardé quoi
-- que ce soit : la consultation d'un reçu est gardée par `payment.view`,
-- et c'est le bon choix — un parent doit pouvoir rouvrir le reçu de son
-- enfant.
--
-- Or, depuis la phase 11B, une école compose ses rôles en cochant des
-- permissions dans une liste. Une case qui n'a aucun effet y est pire
-- qu'absente : l'école la décoche en croyant fermer une porte, et la
-- porte reste ouverte.
--
--   > Une permission qu'aucune porte ne peut faire respecter n'est pas
--   > une permission, c'est une case à cocher.
--
-- ON NE LA SUPPRIME PAS. Elle est peut-être attribuée dans des
-- installations en service, et supprimer une ligne de référence sans
-- nécessité est un geste qu'on ne peut pas reprendre. On lui donne une
-- description qui dit ce qu'elle fait — rien — et ce qui garde
-- réellement l'écran.
--
-- Les descriptions manquantes des permissions de paiement sont
-- renseignées au passage : elles s'affichent dans l'écran des rôles, et
-- une liste de codes sans explication oblige l'école à deviner.

UPDATE permissions
   SET description = 'Sans effet aujourd''hui : la consultation et l''impression d''un reçu sont gardées par « Consulter les paiements ». Une famille doit pouvoir rouvrir son propre reçu.'
 WHERE code = 'receipt.print'
   AND (description IS NULL OR description = '');

UPDATE permissions
   SET description = 'Ouvrir la situation financière d''un élève et ses reçus. Les familles ne voient que leurs enfants.'
 WHERE code = 'payment.view'
   AND (description IS NULL OR description = '');

UPDATE permissions
   SET description = 'Encaisser au guichet et imputer le versement sur les dettes.'
 WHERE code = 'payment.record'
   AND (description IS NULL OR description = '');

UPDATE permissions
   SET description = 'Annuler un reçu déjà émis. Le numéro reste consommé et le motif est journalisé.'
 WHERE code = 'payment.cancel'
   AND (description IS NULL OR description = '');
