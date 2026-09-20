-- =====================================================================
--  MIGRATION — Phase 6A : figer le DETAIL d'un bulletin publie
--
--  POURQUOI MAINTENANT
--  -------------------
--  La phase 4C avait fige les TOTAUX d'un bulletin publie — points,
--  pourcentage, rang, effectif, decision — apres avoir constate qu'un
--  rang fige s'affichait a cote d'un pourcentage recalcule. La table
--  `bulletins` porte donc des agregats, et rien d'autre.
--
--  Le DETAIL, lui, n'a jamais ete archive. La liste branche par branche
--  du bulletin imprime est recalculee a chaque affichage a partir des
--  cotes vivantes. Tant que seul le personnel ouvrait ce document, le
--  defaut restait discret : l'ecran affiche « publie le … » et la
--  direction peut republier.
--
--  Le portail parents le rend intenable. Une cote corrigee apres la
--  remise des bulletins, et la famille qui rouvre le document voit des
--  lignes qui ne correspondent plus au papier recu — et qui, pire, ne
--  s'additionnent plus au total fige juste en dessous. Un bulletin est
--  une PIECE : il ne se recalcule pas.
--
--  C'est la huitieme application du principe de figeage du projet.
--
--
--  CE QUI EST FIGE, ET POURQUOI CHAQUE COLONNE
--  -------------------------------------------
--  Le LIBELLE de la branche autant que la cote : renommer « Education
--  civique » en « Education a la citoyennete » ne doit pas reecrire un
--  bulletin de l'annee derniere. Meme lecon que les libelles
--  d'orientation figes en phase 3.
--
--  Le DOMAINE et le SOUS-DOMAINE : ils commandent la mise en page du
--  bulletin officiel EPST. Un programme remanie changerait sinon
--  l'ordre et les regroupements d'un document deja remis.
--
--  Le MAXIMUM UNITAIRE : il regroupe les branches en blocs sur le
--  modele « maxima ». Le maximum de la cote est deja fige depuis la 4B
--  dans `grades.max_points` ; celui-ci est le maximum du PROGRAMME.
--
--
--  VOLUME
--  ------
--  Une ligne par branche ET par periode du regroupement publie. Une
--  classe de 40 eleves, 12 branches, un semestre de 3 periodes :
--  40 x 12 x 3 = 1 440 lignes par publication. L'annuel en compte le
--  double. L'index (school_id, bulletin_id) suffit a la lecture, qui
--  se fait toujours bulletin par bulletin.
--
--  Republier ECRASE les lignes du bulletin concerne : c'est bien le
--  sens de republier — la piece est remplacee, pas completee.
-- =====================================================================

CREATE TABLE bulletin_lines (
    id                    BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    school_id             BIGINT UNSIGNED   NOT NULL,
    bulletin_id           BIGINT UNSIGNED   NOT NULL,

    -- Reference informative : la branche peut disparaitre du programme
    -- sans que le bulletin deja remis cesse d'exister.
    curriculum_subject_id BIGINT UNSIGNED   NULL,

    -- LIBELLES FIGES
    subject_name          VARCHAR(150)      NOT NULL,
    subject_short         VARCHAR(30)       NULL,
    subject_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    counts_for_ranking    TINYINT(1)        NOT NULL DEFAULT 1,

    -- MISE EN PAGE FIGEE — modele « domaines »
    domain_code           VARCHAR(30)       NULL,
    domain_name           VARCHAR(120)      NULL,
    domain_order          SMALLINT UNSIGNED NOT NULL DEFAULT 99,
    subdomain_name        VARCHAR(120)      NULL,
    subdomain_order       SMALLINT UNSIGNED NOT NULL DEFAULT 99,

    -- MISE EN PAGE FIGEE — modele « maxima »
    max_unit              DECIMAL(6,2)      NOT NULL DEFAULT 0
                          COMMENT 'Maximum unitaire du programme, avant multiplicateur',

    -- LA PERIODE
    period_code           VARCHAR(20)       NOT NULL,
    period_name           VARCHAR(80)       NOT NULL,
    period_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- LA COTE, TELLE QU'ELLE A ETE PUBLIEE
    points                DECIMAL(6,2)      NULL COMMENT 'NULL = cote manquante',
    max_points            DECIMAL(6,2)      NOT NULL,
    is_absent             TINYINT(1)        NOT NULL DEFAULT 0,

    created_at            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_bulletin_line (bulletin_id, curriculum_subject_id, period_code),
    KEY idx_bulletin_line_read (school_id, bulletin_id, subject_order, period_order),

    CONSTRAINT fk_bulletin_line_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bulletin_line_bulletin FOREIGN KEY (bulletin_id)
        REFERENCES bulletins (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Detail fige d un bulletin publie — une piece ne se recalcule pas';
