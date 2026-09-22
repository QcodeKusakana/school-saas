-- =====================================================================
--  MIGRATION — Phase 8A : la messagerie
--
--  CE QUI MANQUAIT, ET CE QUE CA COUTAIT
--  ======================================
--  Rien n'envoyait d'e-mail dans le produit. Consequences en service
--  reel, toutes constatees :
--
--    · « mot de passe oublie » ne fonctionne POUR PERSONNE. Le lien ne
--      s'affiche qu'en mode debug, c'est-a-dire jamais en production ;
--    · aucun rappel d'echeance d'abonnement : une ecole decouvre sa
--      suspension le jour ou elle ne peut plus inscrire ;
--    · les identifiants d'un nouveau compte se remettent uniquement en
--      main propre.
--
--  DEUX TABLES, ET UNE DECISION DANS CHACUNE
--  ==========================================
--
--  1. `email_settings` — LA CONFIGURATION, PAR ECOLE
--  --------------------------------------------------
--  Chaque etablissement envoie depuis SA propre adresse. Un message
--  qui partirait d'une adresse de l'editeur pour annoncer les notes
--  d'un eleve serait illisible pour la famille, et mauvais pour la
--  delivrabilite : le SPF du domaine de l'ecole ne couvrirait pas
--  l'expediteur.
--
--  Pourquoi une table dediee plutot que `school_settings`, qui existe
--  deja ? Parce qu'un mot de passe de serveur n'est pas un reglage.
--  `school_settings_all()` est un cache lu partout, et il suffirait
--  d'un futur ecran « parametres » un peu genereux pour le rendre a la
--  vue. Un secret merite sa propre porte.
--
--  LE MOT DE PASSE EST CHIFFRE, JAMAIS EN CLAIR. La cle vit dans
--  `config.local.php`, hors du depot et hors de la base : une
--  sauvegarde qui fuit ne livre rien.
--
--    > Une cle rangee a cote de ce qu'elle protege ne protege rien.
--
--  Il ne peut pas etre hache comme un mot de passe d'utilisateur : le
--  protocole SMTP exige de le relire en clair au moment de la
--  connexion. C'est la difference entre un secret qu'on VERIFIE et un
--  secret qu'on PRESENTE.
--
--  2. `email_messages` — LA FILE, ET LE JOURNAL
--  ---------------------------------------------
--  Envoyer pendant la requete web condamne l'utilisateur a attendre un
--  serveur SMTP lent, et PERD le message si l'envoi echoue. En RDC,
--  ou la liaison tombe regulierement, c'est la regle et non
--  l'exception.
--
--  Le message est donc d'abord ECRIT, puis tente immediatement. En cas
--  d'echec il reste en file, et un travail periodique le rejoue avec
--  un delai croissant. L'utilisateur n'attend jamais, et rien ne se
--  perd.
--
--    > Un envoi qui n'a pas ete ecrit avant d'etre tente est un envoi
--    > qu'on ne saura pas rejouer.
--
--  LE CORPS D'UN MESSAGE PEUT ETRE UN SECRET.
--  Un lien de reinitialisation EST le secret : `password_resets` ne
--  stocke que le HACHAGE du jeton, precisement pour qu'une fuite de la
--  base ne permette pas d'en forger un. Recopier le lien en clair dans
--  la file annulerait cette protection pendant toute la duree
--  d'attente.
--
--  Les messages marques `is_sensitive` portent donc un corps CHIFFRE,
--  et ce corps est EFFACE des l'envoi reussi. Le journal garde le
--  destinataire, le sujet, l'etat et l'erreur -- jamais le contenu.
--
--  `school_id` est NULLABLE : un message de l'editeur a une ecole
--  cliente n'appartient a aucune ecole. Les deux tables entrent dans
--  TENANT_TABLES ; les lectures transversales passent par
--  `platform_scope()`, comme partout ailleurs.
--
--  PORTABILITE : `CREATE TABLE IF NOT EXISTS` est idempotent et
--  portable MySQL 8 / MariaDB. Rejouable apres un echec partiel.
--
--  AUCUNE DONNEE EXISTANTE N'EST SUPPRIMEE NI MODIFIEE.
-- =====================================================================


CREATE TABLE IF NOT EXISTS `email_settings` (
    `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `school_id`       BIGINT UNSIGNED  NOT NULL,
    `host`            VARCHAR(190)     NOT NULL COMMENT 'Serveur SMTP, ex. mail.monecole.cd',
    `port`            SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    `encryption`      ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls',
    `username`        VARCHAR(190)     NULL,
    `password_cipher` VARCHAR(512)     NULL COMMENT 'CHIFFRE (v1:base64). Jamais en clair, jamais journalise.',
    `from_email`      VARCHAR(190)     NOT NULL COMMENT 'Adresse d expedition. Doit appartenir au domaine, sinon le SPF echoue.',
    `from_name`       VARCHAR(120)     NULL,
    `reply_to`        VARCHAR(190)     NULL,
    `is_active`       TINYINT(1)       NOT NULL DEFAULT 0 COMMENT 'Tant que 0, aucun envoi ne part pour cette ecole.',
    `verified_at`     DATETIME         NULL COMMENT 'Date du dernier envoi d essai reussi.',
    `last_error`      VARCHAR(500)     NULL,
    `updated_by`      BIGINT UNSIGNED  NULL,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_settings_school` (`school_id`),
    KEY `fk_email_settings_user` (`updated_by`),
    CONSTRAINT `fk_email_settings_school` FOREIGN KEY (`school_id`)
        REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_email_settings_user` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configuration d envoi par etablissement. Le mot de passe y est chiffre.';


CREATE TABLE IF NOT EXISTS `email_messages` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36)        NOT NULL,
    `school_id`       BIGINT UNSIGNED NULL COMMENT 'NULL = message de la plateforme, hors de toute ecole.',
    `to_email`        VARCHAR(190)    NOT NULL,
    `to_name`         VARCHAR(190)    NULL,
    `subject`         VARCHAR(255)    NOT NULL,
    `body_text`       MEDIUMTEXT      NULL COMMENT 'CHIFFRE si is_sensitive = 1. Efface des l envoi reussi.',
    `body_html`       MEDIUMTEXT      NULL COMMENT 'Meme regle que body_text.',
    `is_sensitive`    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = le corps porte un secret (lien, mot de passe).',
    `purpose`         VARCHAR(60)     NOT NULL COMMENT 'password_reset, account_created, subscription_due, ...',
    `related_type`    VARCHAR(60)     NULL,
    `related_id`      BIGINT UNSIGNED NULL,
    `status`          ENUM('queued','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
    `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `next_attempt_at` DATETIME        NULL COMMENT 'Delai croissant entre deux tentatives.',
    `sent_at`         DATETIME        NULL,
    `last_error`      VARCHAR(500)    NULL,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_messages_uuid` (`uuid`),
    KEY `idx_email_pending` (`status`, `next_attempt_at`),
    KEY `idx_email_school` (`school_id`, `created_at`),
    KEY `idx_email_purpose` (`purpose`, `created_at`),
    KEY `fk_email_messages_user` (`created_by`),
    CONSTRAINT `fk_email_messages_school` FOREIGN KEY (`school_id`)
        REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_email_messages_user` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='File d envoi et journal. Le corps sensible est chiffre puis efface.';


-- ---------------------------------------------------------------------
--  Les permissions
--
--  `email.manage` est DISTINCTE de `school.edit`, et ce n'est pas du
--  zele : detenir les identifiants du serveur d'envoi, c'est pouvoir
--  ecrire aux familles AU NOM de l'etablissement. Modifier l'adresse
--  postale de l'ecole n'a pas cette portee.
--
--  Meme raisonnement que `fee.waive`, separee de `fee.manage` a la
--  recette Finances : une permission qui recouvre deux pouvoirs de
--  nature differente finit toujours par accorder le plus dangereux.
--
--  Elles vont au SUPER_ADMIN et au SCHOOL_ADMIN. Pas a la DIRECTION,
--  qui porte pourtant `school.edit` -- c'est exactement le point.
--
--  FROM DUAL : portable MySQL 8 et MariaDB.
-- ---------------------------------------------------------------------
INSERT INTO permissions (code, module, name, description, is_platform, sort_order)
SELECT 'email.view', 'email', 'Consulter le journal des envois',
       'Voir les messages envoyes, en attente et en echec.', 0, 1
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'email.view');

INSERT INTO permissions (code, module, name, description, is_platform, sort_order)
SELECT 'email.manage', 'email', 'Configurer l envoi d e-mails',
       'Renseigner le serveur SMTP de l etablissement et ses identifiants.', 0, 2
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'email.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code IN ('email.view', 'email.manage')
 WHERE r.code IN ('SUPER_ADMIN', 'SCHOOL_ADMIN')
   AND r.school_id IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );
