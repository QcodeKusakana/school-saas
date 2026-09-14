-- =====================================================================
--  MIGRATION 006 — Motif de changement de statut d'un enseignant
--
--  Défaut corrigé
--  --------------
--  teachers_service_change_status() écrivait le motif du changement
--  (« Fin de contrat », « Suspension disciplinaire ») dans la colonne
--  `notes`, qui est aussi un champ libre de la fiche, saisi par le
--  secrétariat. Suspendre un enseignant effaçait donc définitivement les
--  notes de sa fiche, sans possibilité de les retrouver : le journal
--  d'audit ne consignait que l'ancien et le nouveau statut.
--
--  Correction
--  ----------
--  Une colonne dédiée. Un champ qui porte deux significations finit
--  toujours par en perdre une.
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE teachers
    ADD COLUMN status_reason VARCHAR(255) NULL
        COMMENT 'Motif du dernier changement de statut' AFTER left_on;
