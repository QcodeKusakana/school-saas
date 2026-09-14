-- =====================================================================
--  MIGRATION — Audit 5A : deux corrections sur les dettes
--
--
--  1. UNE ECOLE AVEC UN FRAIS DE CLASSE ETAIT INDERACINABLE
--  --------------------------------------------------------
--  `DELETE FROM schools` echouait des qu'un frais de portee « classe »
--  avait produit des dettes :
--
--      Cannot add or update a child row ... fk_student_fee_school
--
--  La cause tient a un DOUBLE CHEMIN de cascade vers `fees` :
--
--      schools ─(CASCADE)─→ fees
--      schools ─(CASCADE)─→ classrooms ─(CASCADE)─→ fees
--
--  Quand la seconde branche supprime un frais, `fk_student_fee_fee`
--  (ON DELETE SET NULL) demande a MySQL de METTRE A JOUR des lignes de
--  `student_fees` qui sont elles-memes en cours de suppression par la
--  premiere branche. L'ecole dont le `school_id` a disparu fait alors
--  echouer l'UPDATE, et toute la suppression est annulee.
--
--  Trois issues etaient possibles :
--
--   · passer `fk_student_fee_fee` en CASCADE — refuse : supprimer une
--     classe effacerait les dettes de sa sortie scolaire, qui restent
--     des creances reelles ;
--   · passer `fk_fee_classroom` en RESTRICT — refuse : la suppression
--     d'une ecole echouerait pour une autre raison ;
--   · RETIRER la cle etrangere sur `student_fees.fee_id`, retenue.
--
--  Cette colonne est un REPERE vers la ligne de grille qui a produit la
--  dette, conserve pour le rapprochement. Elle a toujours eu le droit
--  de ne pointer sur rien — c'est ecrit dans la migration 016 : « la
--  dette survit a la grille qui l'a produite ». Tout ce dont la dette a
--  besoin est FIGE dans sa propre ligne : libelle, montant, devise,
--  echeance. Une contrainte d'integrite sur une colonne dont le modele
--  autorise explicitement l'orphelinat n'achetait donc rien, et coutait
--  une suppression d'ecole impossible — donc une procedure d'effacement
--  RGPD impossible.
--
--  L'index est conserve : c'est lui qui sert aux jointures.
--
--
--  2. LES DETTES HORS PORTEE DOIVENT POUVOIR ETRE ANNULEES EN BLOC
--  ---------------------------------------------------------------
--  Rien a modifier en base : l'annulation existe deja et conserve la
--  ligne. La correction est applicative (finance_service_cancel_out_of_scope).
-- =====================================================================

START TRANSACTION;

ALTER TABLE student_fees
    DROP FOREIGN KEY fk_student_fee_fee;

-- La cle etrangere disparait, l'index reste : il portait la jointure.
-- MySQL cree automatiquement un index pour une cle etrangere et le
-- laisse en place ; on s'assure ici qu'il existe bien sous ce nom.
-- (`idx_student_fee_fee` est declare dans la migration 016.)

COMMIT;
