-- =====================================================================
--  MIGRATION — Référentiel des domaines d'apprentissage : les 5 officiels
--
--  CE QUI EST CORRIGÉ ICI EST UNE INVENTION DE LA PHASE 2
--  ------------------------------------------------------
--  Le projet portait huit domaines : LANGUES, SCIENCES, HUMAINES, VIE,
--  TECHNIQUE, ARTS, SPORT, CONDUITE. Aucun document officiel ne les
--  définit ainsi. Les six bulletins du ministère fournis par le client en
--  comptent CINQ, et toujours les mêmes :
--
--      DOMAINE DES LANGUES
--      DOMAINE DES MATHÉMATIQUES, SCIENCES ET TECHNOLOGIE
--        — intitulé « DOMAINE DES SCIENCES » au CTEB
--      DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT
--      DOMAINE DES ARTS
--      DOMAINE DU DÉVELOPPEMENT PERSONNEL
--
--  « CONDUITE » n'est pas un domaine. Sur le bulletin officiel, la
--  conduite est une LIGNE D'APPRÉCIATION en bas de page, au même titre
--  que l'application, notée par période en lettres (B, AB, M). Elle sera
--  traitée comme telle ; la matière existante est conservée ici, faute de
--  quoi cette migration détruirait des cotes déjà saisies.
--
--
--  UNE LIMITE CONNUE, ASSUMÉE ET DOCUMENTÉE
--  ----------------------------------------
--  Le domaine d'une matière DÉPEND DU CYCLE :
--
--      Religion   →  Univers social et environnement   (7e et 8e CTEB)
--      Religion   →  Développement personnel           (degré élémentaire)
--
--  Une colonne `subjects.domain_id` unique ne peut donc pas être exacte
--  pour les deux cycles à la fois. Le rattachement correct appartient au
--  PROGRAMME (curriculum_subjects), pas à la matière.
--
--  Ce n'est pas traité ici, délibérément : les bulletins des humanités
--  n'affichent aucun domaine — ils regroupent par maxima — et ceux du
--  primaire et du CTEB ne sont pas encore produits avec leurs
--  sous-totaux. Le domaine porté par la matière est donc aujourd'hui une
--  classification, jamais un en-tête imprimé. La correction viendra avec
--  les sous-domaines, où le problème se pose pour de bon.
--
--  Valeur retenue en attendant : celle du CTEB, qui couvre deux niveaux
--  contre un pour le degré élémentaire.
--
--
--  AUCUNE DONNÉE N'EST SUPPRIMÉE
--  -----------------------------
--  Les matières sont toutes conservées et rerattachées. Seules
--  disparaissent trois LIGNES DE DOMAINE devenues vides. L'ordre des
--  instructions garantit qu'aucune matière ne pointe vers une ligne
--  effacée : si une seule restait rattachée, la clé étrangère ferait
--  échouer la migration — ce qui est le comportement voulu.
--
--  À appliquer avec : php database/migrate.php
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
--  1. RERATTACHEMENT DU RÉFÉRENTIEL NATIONAL
--
--  Un seul UPDATE avec CASE, et non une suite d'UPDATE : enchaîner
--  « 4 → 3 » puis « 6 → 4 » déplacerait deux fois les mêmes lignes.
--
--  Cibles :
--    1 LANGUES   2 SCIENCES   3 UNIVERS   4 ARTS   5 DÉVELOPPEMENT PERS.
-- ---------------------------------------------------------------------
UPDATE reference_subjects SET domain_id = CASE code

    -- Domaine des langues
    WHEN 'FRANCAIS'     THEN 1
    WHEN 'ANGLAIS'      THEN 1
    WHEN 'LANGUE_NAT'   THEN 1
    WHEN 'LATIN'        THEN 1
    WHEN 'GREC'         THEN 1

    -- Domaine des mathématiques, sciences et technologie.
    -- La technologie et les TIC y figurent explicitement : au 7e CTEB,
    -- le sous-domaine « Sciences Physiques, Technologie et TIC » relève
    -- du DOMAINE DES SCIENCES.
    WHEN 'MATH'         THEN 2
    WHEN 'PHYSIQUE'     THEN 2
    WHEN 'CHIMIE'       THEN 2
    WHEN 'BIOLOGIE'     THEN 2
    WHEN 'SCIENCES'     THEN 2
    WHEN 'SVT'          THEN 2
    WHEN 'TIC'          THEN 2
    WHEN 'TECHNOLOGIE'  THEN 2
    WHEN 'DESSIN_TECH'  THEN 2
    WHEN 'COMPTABILITE' THEN 2
    WHEN 'AGRONOMIE'    THEN 2

    -- Domaine de l'univers social et environnement.
    -- Religion, éducation à la vie et ECM y figurent au CTEB (modèles
    -- 7e et 8e année) ; voir la limite documentée en tête de fichier.
    WHEN 'HISTOIRE'     THEN 3
    WHEN 'GEOGRAPHIE'   THEN 3
    WHEN 'EDUC_CIVIQUE' THEN 3
    WHEN 'EDUC_VIE'     THEN 3
    WHEN 'RELIGION'     THEN 3
    WHEN 'PHILOSOPHIE'  THEN 3
    WHEN 'PSYCHO'       THEN 3
    WHEN 'SOCIOLOGIE'   THEN 3
    WHEN 'DROIT'        THEN 3
    WHEN 'ECONOMIE'     THEN 3

    -- Domaine des arts
    WHEN 'DESSIN'       THEN 4
    WHEN 'MUSIQUE'      THEN 4

    -- Domaine du développement personnel.
    -- Le modèle du degré élémentaire y place « Init. Trav. Prod. » :
    -- les travaux pratiques relèvent bien de ce domaine, et par
    -- extension les disciplines de formation professionnelle.
    WHEN 'EPS'          THEN 5
    WHEN 'TRAV_PRAT'    THEN 5
    WHEN 'PEDAGOGIE'    THEN 5
    WHEN 'DIDACTIQUE'   THEN 5
    WHEN 'CONDUITE'     THEN 5

    ELSE domain_id
END;


-- ---------------------------------------------------------------------
--  2. RERATTACHEMENT DES MATIÈRES DES ÉCOLES
--
--  Deux cas :
--    · la matière vient du référentiel national → on suit son code ;
--    · l'école l'a créée elle-même → on applique la correspondance
--      globale ancien domaine → nouveau, seule information disponible.
-- ---------------------------------------------------------------------
UPDATE subjects s
  LEFT JOIN reference_subjects rs ON rs.id = s.reference_subject_id
    SET s.domain_id = CASE
        WHEN rs.id IS NOT NULL THEN rs.domain_id
        ELSE CASE s.domain_id
            WHEN 1 THEN 1   -- LANGUES   → Langues
            WHEN 2 THEN 2   -- SCIENCES  → Mathématiques, sciences et technologie
            WHEN 3 THEN 3   -- HUMAINES  → Univers social et environnement
            WHEN 4 THEN 3   -- VIE       → Univers social et environnement
            WHEN 5 THEN 2   -- TECHNIQUE → Mathématiques, sciences et technologie
            WHEN 6 THEN 4   -- ARTS      → Arts
            WHEN 7 THEN 5   -- SPORT     → Développement personnel
            WHEN 8 THEN 5   -- CONDUITE  → Développement personnel
            ELSE s.domain_id
        END
    END;


-- ---------------------------------------------------------------------
--  3. SUPPRESSION DES TROIS LIGNES DEVENUES VIDES
--
--  AVANT le renommage, et non après : le code d'un domaine est unique.
--  Renommer la ligne 4 en « ARTS » pendant que la ligne 6 porte encore ce
--  code viole la contrainte — la première écriture de cette migration
--  s'est arrêtée là, et c'est bien ce qu'on attend d'elle.
--
--  Aucune matière ne doit plus se rattacher à 6, 7 ou 8 après les deux
--  étapes précédentes. Si l'une subsistait, la clé étrangère ferait
--  échouer la migration : mieux vaut une migration qui s'arrête qu'une
--  matière silencieusement orpheline.
-- ---------------------------------------------------------------------
DELETE FROM learning_domains WHERE id IN (6, 7, 8);


-- ---------------------------------------------------------------------
--  4. LES CINQ DOMAINES OFFICIELS
--
--  Les lignes 1 à 5 sont réécrites sur place plutôt que recréées : leurs
--  identifiants sont référencés par deux tables, et les renuméroter
--  n'apporterait rien qu'un risque.
-- ---------------------------------------------------------------------
UPDATE learning_domains SET
    code = 'LANGUES', name = 'Domaine des langues',
    short_name = 'Langues', order_number = 1
  WHERE id = 1;

UPDATE learning_domains SET
    code = 'SCIENCES',
    name = 'Domaine des mathématiques, sciences et technologie',
    short_name = 'Sciences', order_number = 2
  WHERE id = 2;

UPDATE learning_domains SET
    code = 'UNIVERS',
    name = 'Domaine de l''univers social et environnement',
    short_name = 'Univers social', order_number = 3
  WHERE id = 3;

UPDATE learning_domains SET
    code = 'ARTS', name = 'Domaine des arts',
    short_name = 'Arts', order_number = 4
  WHERE id = 4;

UPDATE learning_domains SET
    code = 'DEV_PERS', name = 'Domaine du développement personnel',
    short_name = 'Développement personnel', order_number = 5
  WHERE id = 5;
