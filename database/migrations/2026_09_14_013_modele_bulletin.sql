-- =====================================================================
--  MIGRATION — Le programme désigne son MODÈLE DE BULLETIN
--
--  CE QUE LES DIX MODÈLES OFFICIELS IMPOSENT
--  -----------------------------------------
--  Les bulletins du ministère ne partagent pas une mise en page unique.
--  Deux familles s'opposent réellement :
--
--    « domaines »  — les branches sont rangées sous les cinq en-têtes
--                    officiels, chacun portant une ligne SOUS-TOTAL.
--                    Degré élémentaire, moyen, terminal, 7e et 8e CTEB,
--                    humanités générales et scientifiques.
--
--    « maxima »    — aucun domaine. Les branches sont regroupées par
--                    maximum, chaque bloc ouvert par une ligne MAXIMA.
--                    Humanités techniques : construction, mécanique
--                    générale, secrétariat et administration.
--
--  Le nombre de regroupements — deux semestres ou trois trimestres — ne
--  distingue PAS une famille : il est déjà déduit des périodes du cycle
--  (migration 011). Un gabarit « primaire » séparé d'un gabarit
--  « humanités » ne serait qu'une copie du même fichier.
--
--
--  POURQUOI UNE COLONNE, ET NON UNE RÈGLE DANS LE CODE
--  --------------------------------------------------
--  Déduire le modèle du cycle et de la section reviendrait à écrire le
--  référentiel scolaire en PHP. Une école dont la filière technique
--  souhaite le format par domaines, ou une réforme qui bascule une
--  famille, se règlerait alors par une livraison logicielle.
--
--  La colonne est NULLABLE : NULL signifie « déduire ». La déduction
--  reste le comportement par défaut, la colonne n'existe que pour
--  pouvoir la contredire.
--
--
--  VARIANTES CONNUES, NON TRAITÉES ICI
--  -----------------------------------
--    · le bulletin de 6e année porte un bloc RÉSULTAT FINAL
--      (moyenne école 50 + ENAFEP 50) qu'aucun autre ne comporte ;
--    · les bulletins du CTEB et des humanités scientifiques intercalent
--      des SOUS-DOMAINES entre le domaine et la branche.
--
--  Les deux s'ajouteront comme valeurs supplémentaires de cette colonne,
--  quand leur forme sera dictée par un besoin réel plutôt que devinée.
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;


ALTER TABLE curriculums
    ADD COLUMN bulletin_model VARCHAR(30) NULL
        COMMENT 'Mise en page du bulletin ; NULL = déduite du cycle et de la section'
        AFTER education_level_id;
