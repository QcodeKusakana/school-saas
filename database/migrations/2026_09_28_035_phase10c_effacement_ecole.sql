-- =====================================================================
--  Phase 10C — L'EFFACEMENT D'UNE ÉCOLE
-- =====================================================================
--
--  POURQUOI DEUX TABLES, ET PAS UN SIMPLE « DELETE FROM schools »
--  --------------------------------------------------------------
--  Mesuré sur le schéma : 42 tables sont en ON DELETE CASCADE depuis
--  `schools`. Un `DELETE FROM schools` emporterait donc TOUT — y compris
--  `subscriptions` et `subscription_payments`, c'est-à-dire la
--  comptabilité de l'ÉDITEUR, qu'aucun droit à l'effacement ne concerne
--  et qu'une obligation comptable impose au contraire de conserver.
--
--    > Le droit à l'effacement d'un client n'efface pas les écritures
--    > comptables de son fournisseur.
--
--  Et `audit_logs` n'a AUCUNE clé étrangère vers `schools` : ses lignes
--  survivraient au CASCADE. Or `audit_log('user.update', ...)` y écrit
--  nom, prénom et adresse e-mail en clair — la liste
--  `AUDIT_REDACTED_FIELDS` le prouve : on ne masque que ce qui serait
--  stocké autrement. Un effacement qui épargnerait le journal
--  n'effacerait rien.
--
--  D'où trois traitements distincts :
--    1. les données scolaires  → effacées pour de bon ;
--    2. la comptabilité éditeur → archivée AVANT, sans donnée personnelle ;
--    3. le journal de l'école  → effacé, et remplacé par UNE trace de
--       plateforme qui dit qu'un effacement a eu lieu, sans dire sur qui.
--
--  Le nom d'un établissement est celui d'une personne morale : le garder
--  n'est pas garder une donnée personnelle.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. LE REGISTRE DES EFFACEMENTS
-- ---------------------------------------------------------------------
--  Il survit à l'école. C'est ce qui permet, des années plus tard, de
--  répondre « oui, cet établissement a été effacé le tant, à sa demande,
--  après sauvegarde vérifiée » — sans conserver une seule donnée d'élève.
CREATE TABLE IF NOT EXISTS school_erasures (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid              CHAR(36)        NOT NULL,

    -- L'établissement, en tant que personne morale.
    school_code       VARCHAR(20)     NOT NULL,
    school_name       VARCHAR(190)    NOT NULL,
    school_slug       VARCHAR(100)    NOT NULL,

    -- Qui, quand, pourquoi.
    erased_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    erased_by         BIGINT UNSIGNED     NULL,
    -- L'identifiant est gardé EN TEXTE : le compte qui a effacé peut
    -- lui-même disparaître, la responsabilité, non.
    erased_by_username VARCHAR(100)   NOT NULL,
    reason            VARCHAR(255)    NOT NULL,

    -- La sauvegarde vérifiée qui a précédé. Sans elle, l'effacement est
    -- refusé : on ne supprime pas ce qu'on ne sait pas rendre.
    backup_archive    VARCHAR(255)    NOT NULL,
    backup_verified_at DATETIME       NOT NULL,

    -- Ce qui a été effacé, par catégorie. Des nombres, pas des données.
    counts            JSON                NULL,

    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_erasure_uuid (uuid),
    KEY idx_erasure_code (school_code),
    KEY idx_erasure_date (erased_at),

    -- Le compte auteur peut disparaître ; la ligne reste.
    CONSTRAINT fk_erasure_user FOREIGN KEY (erased_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. LA TRACE COMPTABLE DE L'ÉDITEUR
-- ---------------------------------------------------------------------
--  Aucune clé étrangère vers `schools` : c'est tout l'objet de cette
--  table, survivre à l'école. Aucune donnée personnelle non plus — un
--  montant, une date, un moyen de paiement, une référence.
CREATE TABLE IF NOT EXISTS billing_archive (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    school_code         VARCHAR(20)     NOT NULL,
    school_name         VARCHAR(190)    NOT NULL,

    -- L'abonnement dont relève l'écriture.
    plan_id             TINYINT UNSIGNED    NULL,
    billing_cycle       VARCHAR(20)         NULL,
    subscription_status VARCHAR(20)         NULL,
    subscription_starts_on DATE             NULL,
    subscription_ends_on   DATE             NULL,

    -- L'écriture elle-même.
    amount              DECIMAL(12,2)   NOT NULL,
    currency            CHAR(3)         NOT NULL,
    method              VARCHAR(30)         NULL,
    provider            VARCHAR(50)         NULL,
    reference           VARCHAR(100)        NULL,
    payment_status      VARCHAR(20)         NULL,
    paid_at             DATETIME            NULL,

    -- De quelle ligne elle vient, pour rapprocher un ancien rapport.
    original_payment_id BIGINT UNSIGNED     NULL,
    archived_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_billing_code (school_code),
    KEY idx_billing_paid (paid_at),
    KEY idx_billing_origine (original_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. LA PERMISSION
-- ---------------------------------------------------------------------
--  Elle n'est donnée à AUCUN rôle d'école, et c'est délibéré : un
--  directeur ne doit pas pouvoir détruire le dossier scolaire de ses
--  élèves, et un compte de direction volé encore moins.
INSERT INTO permissions (code, module, name, description, is_platform, sort_order)
SELECT 'platform.school.erase', 'platform', 'Effacer definitivement un etablissement',
       'Efface les donnees d un etablissement resilie. Irreversible. Exige une '
       'sauvegarde verifiee et se joue en ligne de commande. Donnee a AUCUN role '
       'd ecole : un directeur ne detruit pas le dossier scolaire de ses eleves.',
       1, 10
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'platform.school.erase');

-- Au SUPER_ADMIN de plateforme, et a lui seul.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'platform.school.erase'
 WHERE r.code = 'SUPER_ADMIN'
   AND r.school_id IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );
