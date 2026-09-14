-- =====================================================================
--  MIGRATION — Phase 4C : Bulletins
--
--  Périmètre : le résultat d'un élève pour une période, un semestre ou
--  l'année — total obtenu, maximum, pourcentage, rang, décision.
--
--
--  POURQUOI UNE TABLE, ALORS QUE TOUT EST CALCULABLE
--  -------------------------------------------------
--  Un bulletin se recalcule depuis `grades` à tout instant. On pourrait
--  donc n'en stocker aucun. Ce serait une erreur, pour une raison
--  précise : LE RANG NE DÉPEND PAS QUE DE L'ÉLÈVE.
--
--  Le rang d'un élève dépend de toute sa classe au moment du calcul.
--  Qu'un élève arrive en cours de trimestre, qu'une cote oubliée soit
--  ajoutée, et le rang de chacun change — y compris sur les bulletins
--  déjà imprimés, signés et remis aux parents.
--
--  Un bulletin publié est un document. Il se fige.
--
--  C'est la troisième fois que ce principe s'applique dans ce projet :
--    · les libellés d'orientation, figés à la décision (phase 3) ;
--    · le maximum d'une cote, figé à la saisie (phase 4B) ;
--    · le rang et la décision, figés à la publication (ici).
--
--  Tant qu'un bulletin n'est pas publié, il est recalculé à chaque
--  affichage — c'est l'état de travail, et il doit suivre les cotes.
--
--
--  PÉRIODES ET REGROUPEMENTS
--  -------------------------
--  period_key porte à la fois les périodes réelles et les totaux :
--
--      'P1','P2','EX1'   les trois périodes du premier semestre
--      'S1'              leur total
--      'P3','P4','EX2'   celles du second
--      'S2'              leur total
--      'ANNUAL'          le total général
--
--  Une clé textuelle plutôt qu'une référence nullable vers
--  grade_periods : MySQL ne déduplique pas les NULL dans un index
--  unique, et « le total annuel » n'est pas une période.
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;


CREATE TABLE bulletins (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id        BIGINT UNSIGNED NOT NULL,

    -- L'inscription porte l'élève, l'année et la classe : c'est
    -- exactement le périmètre d'un bulletin.
    enrollment_id    BIGINT UNSIGNED NOT NULL,

    period_key       VARCHAR(20)     NOT NULL COMMENT 'P1, P2, EX1, S1, P3, P4, EX2, S2, ANNUAL',

    -- Résultat figé.
    --
    -- DEUX POURCENTAGES, ET ILS NE SE CONFONDENT PAS
    -- ----------------------------------------------
    -- `percentage` est celui du DOCUMENT : total_points / max_points,
    -- toutes branches confondues. C'est le chiffre imprimé, celui que la
    -- famille lit, et celui que l'inscription recopie.
    --
    -- `ranking_percentage` ne porte que les branches marquées
    -- counts_for_ranking = 1 (la conduite et la religion en sont
    -- traditionnellement exclues). C'est LUI qui produit class_rank.
    --
    -- Les stocker tous les deux évite qu'un rang devienne inexplicable :
    -- sans cette colonne, un bulletin affichant 77,5 % pouvait être classé
    -- derrière un bulletin affichant 75 %, sans qu'aucune donnée
    -- conservée ne permette de le justifier dix ans plus tard.
    total_points       DECIMAL(9,2)  NOT NULL,
    max_points         DECIMAL(9,2)  NOT NULL,
    percentage         DECIMAL(5,2)  NOT NULL,
    ranking_percentage DECIMAL(5,2)  NULL,

    -- Le rang et l'effectif vont ensemble : « 7e » ne veut rien dire
    -- sans « sur 45 ». Un bulletin réimprimé dix ans plus tard doit
    -- porter les deux.
    class_rank       SMALLINT UNSIGNED NULL,
    class_size       SMALLINT UNSIGNED NULL,

    -- Nombre de cotes manquantes au moment de la publication. Un
    -- bulletin établi sur une saisie incomplète doit le dire : sans
    -- cela, un total faible se confond avec un échec.
    missing_grades   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    absent_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- La décision de fin d'année n'est portée que par 'ANNUAL'.
    decision         ENUM('passed','failed','conditional','excluded','pending') NULL,

    comment          VARCHAR(500)    NULL COMMENT 'Appréciation du titulaire',

    published_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_by     BIGINT UNSIGNED NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_bulletin_slot (school_id, enrollment_id, period_key),

    -- Index du classement : « tous les bulletins de cette période pour
    -- cette classe ». C'est la requête du conseil de classe.
    KEY idx_bulletin_period (school_id, period_key),

    CONSTRAINT fk_bulletin_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,

    -- CASCADE : annuler une inscription retire ses bulletins. Un
    -- bulletin sans inscription fausserait les effectifs et les rangs.
    CONSTRAINT fk_bulletin_enrollment FOREIGN KEY (enrollment_id)
        REFERENCES enrollments (id) ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_bulletin_publisher FOREIGN KEY (published_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Résultat figé d un élève pour une période, un semestre ou l année';


-- ---------------------------------------------------------------------
--  PARAMÈTRE : traitement des absences
--
--  Rien n'est inséré ici. school_setting() renvoie la valeur par défaut
--  tant qu'une école n'a rien enregistré ; insérer une ligne par école
--  créerait une copie à maintenir partout.
--
--  Clé : grading.absence_mode
--    'excluded' (défaut) — la période absente sort du total ET du
--                          maximum. La moyenne reste juste, mais le
--                          dénominateur diffère d'un élève à l'autre.
--    'zero'             — l'absence vaut zéro, maximum plein. Les
--                          totaux restent comparables, mais un élève
--                          malade est traité comme s'il avait échoué.
--
--  Aucune des deux règles n'est universelle en RDC. En figer une dans le
--  code reproduirait l'erreur des maxima : c'est une décision
--  d'établissement.
--
--  Le bulletin affiche le nombre de périodes retenues, de sorte qu'un
--  dénominateur réduit soit visible et non subi.
-- ---------------------------------------------------------------------
