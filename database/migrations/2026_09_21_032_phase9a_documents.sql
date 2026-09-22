-- =====================================================================
--  MIGRATION — Phase 9A : les documents officiels
--
--  CE QUI MANQUAIT
--  ===============
--  Trois permissions dorment depuis la phase 1 : `document.generate`,
--  `document.view` et `student.document`. Aucun ecran ne les portait.
--
--    > Une permission semee sans ecran n'est pas une fonctionnalite en
--    > attente, c'est une porte qu'on croit fermee.
--
--  Dans la realite d'une ecole congolaise, l'attestation de
--  frequentation et le certificat de scolarite se redigent a la main
--  sur papier a en-tete, se signent, se tamponnent. Les parents en
--  demandent plusieurs par an : bourses, demarches administratives,
--  changement d'ecole, dossier de visa. Et le faux document scolaire
--  est un probleme reel, que rien ne permet aujourd'hui de detecter.
--
--  DEUX TABLES
--  ===========
--
--  1. `documents` — CE QUI A ETE DELIVRE, ET CE QU'IL DISAIT
--  ---------------------------------------------------------
--  LE FIGEAGE, pour la onzieme fois dans ce produit. `snapshot` garde
--  la copie de tout ce que le document affirme : le nom de l'eleve tel
--  qu'il etait, sa classe, l'annee, le nom de l'ecole, la decision de
--  fin d'annee. Un certificat delivre en septembre doit dire la meme
--  chose dix ans plus tard, meme si la classe a ete renommee, l'eleve
--  reinscrit ailleurs ou l'ecole rebaptisee.
--
--    > Un document qui change apres avoir ete signe n'est pas un
--    > document, c'est un affichage.
--
--  LE JETON DE VERIFICATION (`token`) est tire au sort, JAMAIS derive
--  de l'identifiant. Douze caracteres dans un alphabet de 32 font
--  environ 10^18 combinaisons : le jeton n'est pas enumerable. C'est
--  ce qui autorise une page publique sans authentification.
--
--  Il est UNIQUE pour tout le produit, comme `sync_devices.device_uuid`
--  et `users.username` : la page de verification ne connait pas encore
--  l'ecole quand elle le recoit.
--
--  LA REVOCATION n'est pas un detail. Sans elle, « ce document est
--  valide » devient une affirmation eternelle : une attestation
--  delivree par erreur, ou a un eleve dont l'inscription a ete
--  annulee, resterait verifiable indefiniment.
--
--    > Sans revocation, « valide » est une promesse qu'on ne peut plus
--    > reprendre.
--
--  Une ligne n'est JAMAIS supprimee. Revoquer, c'est dater et motiver,
--  pas effacer : « quel document avions-nous delivre ? » doit rester
--  lisible.
--
--  2. `document_counters` — LA NUMEROTATION
--  -----------------------------------------
--  Meme mecanique que `receipt_counters` et `student_counters` :
--  INSERT IGNORE pour garantir la ligne, puis SELECT ... FOR UPDATE
--  dans une transaction. Deux secretaires qui delivrent a la meme
--  seconde obtiennent deux numeros distincts et consecutifs.
--
--  La cle inclut le TYPE : chaque nature de document a sa propre
--  serie, comme dans un registre papier. Un numero d'attestation et un
--  numero de certificat ne se marchent pas dessus.
--
--  CE QUE LA PAGE PUBLIQUE MONTRERA — et ce qu'elle taira
--  =======================================================
--  Numero, nature, ecole, date, validite. PAS LE NOM DE L'ELEVE.
--
--  Ce sont des mineurs, et un document tombe de la poche d'un parent
--  ne doit rien apprendre a qui le ramasse. La contrepartie est
--  assumee : le verificateur doit comparer le NUMERO imprime avec
--  celui qu'affiche l'ecran, pas se contenter de scanner. Le texte de
--  la page le dit explicitement.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. Les documents delivres
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED NOT NULL,

    -- Le numero lisible, tel qu'il est imprime : ATT/2026-2027/0001
    number          VARCHAR(40)  NOT NULL,
    sequence_number INT UNSIGNED NOT NULL,

    type            ENUM('attestation_frequentation',
                         'certificat_scolarite',
                         'carte_eleve',
                         'attestation_paiement') NOT NULL,

    -- Le jeton public. Unique pour TOUT le produit : la page de
    -- verification ne connait pas l'ecole quand elle le recoit.
    token           VARCHAR(20)  NOT NULL,

    student_id      BIGINT UNSIGNED NOT NULL,
    enrollment_id   BIGINT UNSIGNED NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,

    -- LE FIGEAGE. Tout ce que le document affirme, copie au moment de
    -- la delivrance. Voir l'en-tete.
    snapshot        JSON NOT NULL,

    issued_by       BIGINT UNSIGNED NULL,
    issued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- La revocation. `revoked_at` NULL = le document vaut.
    revoked_at      DATETIME NULL,
    revoked_by      BIGINT UNSIGNED NULL,
    revoke_reason   VARCHAR(255) NULL,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_document_token  (token),
    UNIQUE KEY uq_document_number (school_id, number),

    KEY idx_document_school  (school_id, type, issued_at),
    KEY idx_document_student (school_id, student_id, issued_at),
    KEY idx_document_year    (school_id, academic_year_id),

    CONSTRAINT fk_document_school
        FOREIGN KEY (school_id) REFERENCES schools (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_document_student
        FOREIGN KEY (student_id) REFERENCES students (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_document_year
        FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_document_issued_by
        FOREIGN KEY (issued_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_document_revoked_by
        FOREIGN KEY (revoked_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Documents officiels delivres — figes, verifiables, revocables';

-- `ON DELETE RESTRICT` sur l'eleve est delibere : un eleve ne se
-- supprime pas dans ce produit (il s'archive), et un document delivre
-- ne doit pas pouvoir disparaitre avec lui.

-- ---------------------------------------------------------------------
--  2. La numerotation, par ecole / type / annee
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_counters (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id   BIGINT UNSIGNED NOT NULL,

    -- « attestation_frequentation:2026-2027 »
    counter_key VARCHAR(60) NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,

    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_document_counter (school_id, counter_key),

    CONSTRAINT fk_document_counter_school
        FOREIGN KEY (school_id) REFERENCES schools (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sequence des numeros de document — sans trou ni doublon';

-- ---------------------------------------------------------------------
--  3. La permission de revoquer
-- ---------------------------------------------------------------------
--  `document.generate` et `document.view` existent deja. Revoquer est
--  un acte distinct : delivrer une attestation est un geste de
--  secretariat, retirer sa valeur a un document deja remis engage
--  l'etablissement. On ne confie pas les deux au meme niveau.
INSERT INTO permissions (code, name, module, description)
SELECT 'document.revoke', 'Revoquer un document', 'documents',
       'Retirer sa validite a un document deja delivre'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'document.revoke');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'document.revoke'
 WHERE r.school_id IS NULL
   AND r.code IN ('SUPER_ADMIN', 'SCHOOL_ADMIN', 'DIRECTION')
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );
