-- =====================================================================
--  Phase 11D — les pièces du dossier de l'élève
-- =====================================================================
--
-- CE QUE CETTE TABLE N'EST PAS
-- ----------------------------
-- Ce ne sont PAS les documents de la phase 9A. `documents` contient ce
-- que l'école ÉMET — attestations, certificats, cartes — avec un numéro
-- officiel, un figeage et un code de vérification. Ici, c'est ce que
-- l'école REÇOIT : l'acte de naissance, le bulletin de l'établissement
-- précédent, la pièce du tuteur.
--
-- Rien de tout cela ne se numérote, ne se fige ni ne se vérifie : ce
-- sont des pièces jointes. Les mélanger aurait donné une table aux
-- deux tiers vides, où la moitié des colonnes n'aurait eu de sens que
-- pour la moitié des lignes.
--
-- LA PIÈCE APPARTIENT À L'ÉLÈVE, PAS À SON INSCRIPTION
-- -----------------------------------------------------
-- Un acte de naissance ne se redépose pas chaque année. Le rattacher à
-- `enrollments` obligerait à le recopier à chaque réinscription, ou à
-- remonter l'historique pour le retrouver — exactement ce que le
-- « dossier numérique unique » du projet doit éviter.
--
-- CE QU'ELLE NE PORTE PAS : le nom du fichier d'origine.
-- `upload_store()` régénère un nom aléatoire, et c'est délibéré : le
-- nom choisi par le client n'entre jamais dans un chemin. Le libellé
-- lisible est saisi à part, et c'est lui qui s'affiche.

CREATE TABLE IF NOT EXISTS student_documents (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id    BIGINT UNSIGNED NOT NULL,
    student_id   BIGINT UNSIGNED NOT NULL,

    -- Le type est une chaîne courte, pas une clé étrangère. Les pièces
    -- d'un dossier scolaire congolais sont stables (acte de naissance,
    -- bulletin precedent, piece du tuteur…) et une table de référence
    -- pour six valeurs coûterait une jointure sur chaque affichage sans
    -- rien rendre de configurable : c'est le LIBELLÉ, libre, qui porte
    -- ce que l'école veut préciser.
    type         VARCHAR(40)  NOT NULL,
    label        VARCHAR(150) NULL COMMENT 'Precision saisie par l ecole',

    file_path    VARCHAR(255) NOT NULL COMMENT 'Relatif a storage/uploads',
    mime         VARCHAR(100) NOT NULL,
    size_bytes   INT UNSIGNED NOT NULL,

    uploaded_by  BIGINT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Le chemin est unique : deux lignes qui désigneraient le même
    -- fichier feraient qu'en supprimer une rendrait l'autre morte.
    UNIQUE KEY uq_studoc_path (file_path),
    KEY idx_studoc_dossier (school_id, student_id, type),

    CONSTRAINT fk_studoc_school  FOREIGN KEY (school_id)  REFERENCES schools (id)  ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_studoc_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,

    -- L'AUTEUR SE DÉTACHE, LA PIÈCE RESTE.
    -- Un compte fermé ne doit pas emporter l'acte de naissance d'un
    -- élève : `SET NULL`, jamais `CASCADE`.
    CONSTRAINT fk_studoc_user    FOREIGN KEY (uploaded_by) REFERENCES users (id)   ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pieces recues et rangees au dossier de l eleve (phase 11D)';

-- `student.document` existe depuis la phase 1 sans qu'aucun écran ne
-- l'emploie. Elle n'est donc pas créée ici : elle est simplement, enfin,
-- mise au travail. Sa description manquait — elle s'affiche dans l'écran
-- des rôles depuis la phase 11B.
UPDATE permissions
   SET description = 'Deposer, consulter et retirer les pieces du dossier d un eleve : acte de naissance, bulletin anterieur, piece du tuteur. Ces pieces concernent des mineurs.'
 WHERE code = 'student.document'
   AND (description IS NULL OR description = '');
