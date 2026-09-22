-- ---------------------------------------------------------------------
-- Audit de la phase 9B — l'index qui manquait au journal
--
-- LA MIGRATION 033 AFFIRMAIT QUE `idx_audit_school_action` COUVRAIT
-- « LE FILTRE ET LE TRI ». C'etait vrai POUR LE FILTRE PAR ACTION, et
-- faux pour l'ecran par defaut, qui n'en pose aucun.
--
-- MESURE, sur 250 000 lignes, une ecole detenant 20 % du parc :
--
--   requete                       cle choisie                 cout
--   ---------------------------   -------------------------   --------
--   page 1, sans filtre           PRIMARY (scan arriere)       0,29 ms
--   page 200, sans filtre         idx_audit_school_action     12,16 ms
--                                 + Using filesort
--
-- Le tri de fichier porte sur ~99 000 lignes pour en rendre 50, et il
-- CROIT avec la taille de l'ecole : une ecole a 500 000 entrees le
-- paierait dix fois plus cher.
--
-- APRES `(school_id, id)` :
--
--   page 1                        idx_audit_school_id          1,65 ms
--   page 200                      idx_audit_school_id          3,53 ms
--                                 (plus aucun filesort)
--
-- COUT A L'ECRITURE, mesure sur 10 000 insertions :
--   sans le 4e index : 0,1165 ms / ecriture
--   avec le 4e index : 0,1187 ms / ecriture   (+2 %)
--
-- Deux pour cent, parce que `(school_id, id)` s'ecrit TOUJOURS par la
-- droite : `id` est croissant, aucune page d'index n'a a etre scindee.
--
-- LE COMPROMIS EST ASSUME : la page 1 passe de 0,29 a 1,65 ms — MySQL
-- prefere desormais l'index secondaire au scan arriere de la cle
-- primaire. On echange 1,3 ms sur le cas courant, qui reste sous deux
-- millisecondes, contre la disparition d'un cout qui, lui, n'a pas de
-- borne.
--
--   > Un index qu'on ajoute sans mesurer ce qu'il coute a l'ecriture
--   > n'est pas une optimisation, c'est un pari.
--
-- Migration idempotente.
-- ---------------------------------------------------------------------

SET @idx := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'audit_logs'
       AND index_name   = 'idx_audit_school_id'
);

SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_audit_school_id ON audit_logs (school_id, id)',
    'SELECT 1');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
