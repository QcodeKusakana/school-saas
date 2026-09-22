-- =====================================================================
--  MIGRATION — Phase 7B : l'editeur entre dans une ecole cliente
--
--  LE PROBLEME, TROUVE PAR EXECUTION
--  ---------------------------------
--  La console de l'editeur doit pouvoir OUVRIR une ecole pour la
--  depanner. La premiere version placait l'ecole visitee dans la
--  session -- et elle ne tenait pas une seule requete.
--
--  `auth_user()` reetablit le contexte multi-ecole DEPUIS LA BASE a
--  chaque requete, jamais depuis la session, et le commentaire qui
--  l'accompagne dit pourquoi : « une session alteree ne peut pas
--  changer d'ecole ». Un compte de plateforme portant school_id NULL,
--  son contexte etait donc remis a NULL immediatement apres l'entree.
--
--  Mesure par la sonde : le bandeau annoncait « Ecole Beta », et
--  tenant_id() valait NULL. L'ecran disait une chose, le systeme en
--  tenait une autre.
--
--
--  POURQUOI UNE COLONNE, ET PAS LA SESSION
--  ---------------------------------------
--  On aurait pu apprendre a `auth_user()` a lire la session pour les
--  seuls comptes de plateforme. Cela aurait marche, et cela aurait
--  ouvert une porte : un cookie vole ou une session bricolee aurait
--  permis d'entrer dans une ecole SANS PASSER PAR LA ROUTE AUDITEE.
--
--  Or la trace est precisement ce qui protege contre un compte de
--  plateforme compromis ou malveillant. Une visite qui peut se faire
--  sans laisser de trace n'est pas une visite tracee.
--
--  La visite devient donc un etat de la BASE, ecrit par le seul
--  service habilite, et la propriete d'origine est preservee mot pour
--  mot : le contexte vient de la base, jamais de la session.
--
--
--  CE QUE LA COLONNE NE FAIT PAS
--  -----------------------------
--  Elle n'a d'effet que pour un compte dont `school_id` est NULL --
--  c'est-a-dire un compte de la plateforme. Renseignee par erreur sur
--  un compte d'ecole, elle est ignoree par `auth_user()`. Une colonne
--  qui ne peut agir que la ou elle a un sens ne devient pas une faille
--  en cas de mauvaise ecriture.
--
--  ON DELETE SET NULL : si l'ecole visitee disparait, la visite
--  s'efface d'elle-meme plutot que de pointer dans le vide.
-- =====================================================================

ALTER TABLE users
    ADD COLUMN visiting_school_id BIGINT UNSIGNED NULL
        COMMENT 'Ecole ouverte par un compte de la plateforme (phase 7B). Sans effet si school_id est renseigne.'
        AFTER school_id,
    ADD KEY idx_users_visiting (visiting_school_id),
    ADD CONSTRAINT fk_users_visiting
        FOREIGN KEY (visiting_school_id) REFERENCES schools (id)
        ON DELETE SET NULL ON UPDATE CASCADE;
