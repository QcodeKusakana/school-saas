-- =====================================================================
--  MIGRATION — Phase 6B : l'espace eleve
--
--  CE QUI MANQUAIT
--  ---------------
--  Le role ELEVE existe depuis la phase 1 (niveau 10) avec ses
--  permissions de lecture. Mais RIEN ne reliait un compte de connexion a
--  un eleve : la table `students` n'avait pas de `user_id`, et
--  `students_scope_clause()` n'avait pas de branche « eleve ». Un compte
--  ELEVE voyait donc `1 = 0` — c'est-a-dire rien du tout.
--
--  Le role etait declare, la porte n'avait jamais ete posee. Meme
--  situation que `guardians.user_id` avant la phase 6A.
--
--
--  POURQUOI UNE COLONNE ET PAS UNE TABLE DE LIAISON
--  ------------------------------------------------
--  Un eleve a AU PLUS un compte, et un compte appartient a AU PLUS un
--  eleve. C'est une relation 1-1 : une table de liaison n'ajouterait
--  qu'une jointure et la possibilite d'un etat incoherent — deux comptes
--  pour un eleve, que rien n'interdirait plus.
--
--  `guardians.user_id` suit deja exactement cette forme. Deux conventions
--  differentes pour le meme besoin finiraient par diverger.
--
--
--  ON DELETE SET NULL, PAS CASCADE
--  -------------------------------
--  Desactiver le compte d'un eleve ne doit PAS effacer son dossier
--  scolaire. Le lien tombe, le parcours reste : c'est toute la promesse
--  du produit.
--
--
--  L'UNICITE EST GLOBALE, PAS PAR ECOLE
--  ------------------------------------
--  `uq_student_user` porte sur `user_id` seul. Un meme compte ne peut
--  donc pas etre rattache a deux eleves, meme dans deux ecoles
--  differentes. C'est voulu : un identifiant de connexion designe UNE
--  personne. Le NULL echappe a la contrainte en MySQL, donc les milliers
--  d'eleves sans compte cohabitent sans probleme.
--
--
--  LA VISIBILITE DES FRAIS
--  -----------------------
--  En RDC, la dette scolaire est une affaire de parents. Un eleve de 12
--  ans n'a pas a porter le poids d'un minerval impaye, et l'ecole n'a pas
--  a le lui annoncer par un ecran.
--
--  Le reglage est donc a FAUX par defaut, et modifiable par
--  l'etablissement : certaines ecoles d'humanites, ou les eleves sont
--  majeurs et paient eux-memes, voudront l'inverse.
-- =====================================================================

ALTER TABLE students
    ADD COLUMN user_id BIGINT UNSIGNED NULL
        COMMENT 'Compte de connexion de l eleve — phase 6B'
        AFTER uuid,
    ADD UNIQUE KEY uq_student_user (user_id),
    ADD CONSTRAINT fk_student_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE;

INSERT INTO school_settings (school_id, setting_key, setting_value, setting_type)
SELECT s.id,
       'portal.student_sees_fees',
       '0',
       'bool'
  FROM schools s
 WHERE NOT EXISTS (
       SELECT 1 FROM school_settings ss
        WHERE ss.school_id = s.id AND ss.setting_key = 'portal.student_sees_fees'
 );
