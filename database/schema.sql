-- =====================================================================
--  SCHOOL SAAS RDC — Schéma de base de données
--  Phase 1 : Fondation (plateforme, écoles, utilisateurs, rôles,
--            permissions, années scolaires, référentiel de niveaux,
--            audit, sécurité)
--
--  Moteur   : InnoDB
--  Charset  : utf8mb4 / utf8mb4_unicode_ci (compatible MySQL 5.7+,
--             MySQL 8.x et MariaDB 10.3+ — indispensable en mutualisé)
--  Convention : tables au pluriel, colonnes en snake_case anglais,
--               toute table "tenant" porte school_id NOT NULL.
--
--  IMPORTANT : ne pas exécuter ce fichier sur une base contenant déjà
--  des données. Voir database/migrations/ pour les évolutions.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';


-- =====================================================================
--  SECTION 1 — RÉFÉRENTIEL NATIONAL (global, partagé par toutes les écoles)
--  Ces tables ne portent PAS de school_id : elles décrivent le système
--  éducatif de la RDC, identique pour tous. Chaque école choisit ensuite
--  les cycles qu'elle active (table school_cycles).
-- =====================================================================

CREATE TABLE education_cycles (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(30)      NOT NULL,
    name            VARCHAR(100)     NOT NULL,
    short_name      VARCHAR(30)      NOT NULL,
    order_number    TINYINT UNSIGNED NOT NULL,
    is_optional     TINYINT(1)       NOT NULL DEFAULT 0 COMMENT 'Maternelle = optionnel',
    is_active       TINYINT(1)       NOT NULL DEFAULT 1,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cycles_code (code),
    KEY idx_cycles_order (order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cycles du système éducatif RDC : maternelle, primaire, CTEB, humanités';


CREATE TABLE education_levels (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cycle_id        TINYINT UNSIGNED  NOT NULL,
    code            VARCHAR(30)       NOT NULL,
    name            VARCHAR(100)      NOT NULL,
    short_name      VARCHAR(30)       NOT NULL,
    order_number    TINYINT UNSIGNED  NOT NULL COMMENT 'Ordre global du parcours : 1=1ère maternelle ... 13=4ème humanités',
    age_min         TINYINT UNSIGNED  NULL,
    age_max         TINYINT UNSIGNED  NULL,
    requires_option TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1 = ce niveau exige une section/option (humanités)',
    is_active       TINYINT(1)        NOT NULL DEFAULT 1,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_levels_code (code),
    KEY idx_levels_cycle (cycle_id, order_number),
    CONSTRAINT fk_levels_cycle FOREIGN KEY (cycle_id)
        REFERENCES education_cycles (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Niveaux scolaires RDC. order_number porte la progression du parcours de l élève.';


-- =====================================================================
--  SECTION 2 — PLATEFORME SAAS (offres, écoles, abonnements)
-- =====================================================================

CREATE TABLE plans (
    id                  TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                VARCHAR(30)      NOT NULL,
    name                VARCHAR(100)     NOT NULL,
    description         VARCHAR(255)     NULL,
    price_monthly       DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
    price_yearly        DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
    currency            CHAR(3)          NOT NULL DEFAULT 'USD',
    max_students        INT UNSIGNED     NULL COMMENT 'NULL = illimité',
    max_users           INT UNSIGNED     NULL,
    max_storage_mb      INT UNSIGNED     NULL,
    features            JSON             NULL COMMENT 'Drapeaux de modules activés : {"offline":true,"sms":false}',
    is_active           TINYINT(1)       NOT NULL DEFAULT 1,
    sort_order          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Offres commerciales du SaaS';


CREATE TABLE schools (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36)        NOT NULL COMMENT 'Identifiant public (URL, QR code) — ne jamais exposer l id numérique',
    code                VARCHAR(20)     NOT NULL COMMENT 'Code interne plateforme, ex: ECO-000123',
    slug                VARCHAR(100)    NOT NULL COMMENT 'Sous-domaine / segment d URL',
    name                VARCHAR(190)    NOT NULL,
    short_name          VARCHAR(60)     NULL,
    sernie_number       VARCHAR(50)     NULL COMMENT 'Numéro SERNIE / matricule officiel EPST',
    approval_number     VARCHAR(50)     NULL COMMENT 'Numéro d arrêté d agrément',
    school_type         ENUM('public','conventionne','prive','autre') NOT NULL DEFAULT 'prive',
    province            VARCHAR(100)    NULL,
    city                VARCHAR(100)    NULL,
    commune             VARCHAR(100)    NULL,
    address             VARCHAR(255)    NULL,
    phone               VARCHAR(40)     NULL,
    phone_alt           VARCHAR(40)     NULL,
    email               VARCHAR(190)    NULL,
    website             VARCHAR(190)    NULL,
    logo_path           VARCHAR(255)    NULL,
    stamp_path          VARCHAR(255)    NULL COMMENT 'Cachet scanné pour les documents PDF',
    director_name       VARCHAR(150)    NULL,
    default_currency    CHAR(3)         NOT NULL DEFAULT 'CDF',
    timezone            VARCHAR(50)     NOT NULL DEFAULT 'Africa/Kinshasa',
    locale              VARCHAR(10)     NOT NULL DEFAULT 'fr_CD',
    status              ENUM('pending','active','suspended','cancelled') NOT NULL DEFAULT 'pending',
    suspended_reason    VARCHAR(255)    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME        NULL COMMENT 'Suppression logique — un établissement n est jamais supprimé physiquement',
    PRIMARY KEY (id),
    UNIQUE KEY uq_schools_uuid (uuid),
    UNIQUE KEY uq_schools_code (code),
    UNIQUE KEY uq_schools_slug (slug),
    KEY idx_schools_status (status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Établissements = tenants du SaaS. school_id référence toujours cette table.';


CREATE TABLE school_cycles (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    school_id   BIGINT UNSIGNED  NOT NULL,
    cycle_id    TINYINT UNSIGNED NOT NULL,
    is_active   TINYINT(1)       NOT NULL DEFAULT 1,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_school_cycle (school_id, cycle_id),
    CONSTRAINT fk_school_cycles_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_school_cycles_cycle FOREIGN KEY (cycle_id)
        REFERENCES education_cycles (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cycles réellement dispensés par chaque école (une école primaire seule n active que PRIMAIRE)';


CREATE TABLE school_settings (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED NOT NULL,
    setting_key     VARCHAR(100)    NOT NULL,
    setting_value   TEXT            NULL,
    setting_type    ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_school_setting (school_id, setting_key),
    CONSTRAINT fk_settings_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Paramètres par école (clé/valeur) — évite d ajouter une colonne à schools à chaque option';


CREATE TABLE subscriptions (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id               BIGINT UNSIGNED NOT NULL,
    plan_id                 TINYINT UNSIGNED NOT NULL,
    status                  ENUM('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'trial',
    billing_cycle           ENUM('monthly','yearly') NOT NULL DEFAULT 'yearly',
    starts_on               DATE            NOT NULL,
    ends_on                 DATE            NOT NULL,
    max_students_override   INT UNSIGNED    NULL COMMENT 'Surcharge négociée du quota du plan',
    auto_renew              TINYINT(1)      NOT NULL DEFAULT 0,
    cancelled_at            DATETIME        NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subscriptions_school (school_id, status),
    KEY idx_subscriptions_ends (ends_on, status),
    CONSTRAINT fk_subscriptions_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id)
        REFERENCES plans (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Abonnement d une école. L historique est conservé : une école peut avoir plusieurs lignes.';


CREATE TABLE subscription_payments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id           BIGINT UNSIGNED NOT NULL,
    subscription_id     BIGINT UNSIGNED NOT NULL,
    amount              DECIMAL(12,2)   NOT NULL,
    currency            CHAR(3)         NOT NULL DEFAULT 'USD',
    method              ENUM('mobile_money','bank_transfer','cash','card','other') NOT NULL DEFAULT 'mobile_money',
    provider            VARCHAR(50)     NULL COMMENT 'M-Pesa, Orange Money, Airtel Money, ...',
    reference           VARCHAR(100)    NULL,
    status              ENUM('pending','confirmed','failed','refunded') NOT NULL DEFAULT 'pending',
    paid_at             DATETIME        NULL,
    recorded_by         BIGINT UNSIGNED NULL,
    notes               VARCHAR(255)    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subpay_reference (provider, reference),
    KEY idx_subpay_school (school_id, paid_at),
    CONSTRAINT fk_subpay_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_subpay_subscription FOREIGN KEY (subscription_id)
        REFERENCES subscriptions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Paiements de l abonnement SaaS (à ne pas confondre avec les frais scolaires des élèves)';


-- =====================================================================
--  SECTION 3 — IDENTITÉ, RÔLES ET PERMISSIONS
-- =====================================================================

CREATE TABLE users (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                    CHAR(36)        NOT NULL,
    school_id               BIGINT UNSIGNED NULL COMMENT 'NULL = compte plateforme (super admin), non rattaché à une école',
    username                VARCHAR(60)     NOT NULL COMMENT 'Identifiant de connexion — beaucoup d utilisateurs n ont pas d email',
    email                   VARCHAR(190)    NULL,
    phone                   VARCHAR(40)     NULL,
    password_hash           VARCHAR(255)    NOT NULL,
    last_name               VARCHAR(80)     NOT NULL COMMENT 'Nom',
    post_name               VARCHAR(80)     NULL COMMENT 'Postnom (usage RDC)',
    first_name              VARCHAR(80)     NOT NULL COMMENT 'Prénom',
    gender                  ENUM('M','F')   NULL,
    photo_path              VARCHAR(255)    NULL,
    status                  ENUM('pending','active','inactive','suspended') NOT NULL DEFAULT 'active',
    must_change_password    TINYINT(1)      NOT NULL DEFAULT 0,
    email_verified_at       DATETIME        NULL,
    last_login_at           DATETIME        NULL,
    last_login_ip           VARBINARY(16)   NULL,
    failed_attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until            DATETIME        NULL,
    password_changed_at     DATETIME        NULL,
    created_by              BIGINT UNSIGNED NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_uuid (uuid),
    UNIQUE KEY uq_users_username (username) COMMENT 'Unicité globale : évite toute ambiguïté à la connexion',
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_school (school_id, status, deleted_at),
    KEY idx_users_name (last_name, first_name),
    CONSTRAINT fk_users_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Comptes de connexion. Un enseignant/parent/élève possède un user + une fiche métier dédiée.';


CREATE TABLE roles (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED   NULL COMMENT 'NULL = rôle système partagé par toutes les écoles',
    code            VARCHAR(40)       NOT NULL,
    name            VARCHAR(100)      NOT NULL,
    description     VARCHAR(255)      NULL,
    level           SMALLINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Hiérarchie : un rôle ne peut gérer qu un rôle de niveau strictement inférieur',
    is_system       TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1 = non modifiable / non supprimable',
    is_active       TINYINT(1)        NOT NULL DEFAULT 1,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_scope_code (school_id, code),
    KEY idx_roles_level (level),
    CONSTRAINT fk_roles_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rôles système (school_id NULL) + rôles personnalisés créés par une école';


CREATE TABLE permissions (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(80)       NOT NULL COMMENT 'Format module.action, ex: student.create',
    module          VARCHAR(40)       NOT NULL,
    name            VARCHAR(150)      NOT NULL,
    description     VARCHAR(255)      NULL,
    is_platform     TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1 = réservé au super admin plateforme',
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_code (code),
    KEY idx_permissions_module (module, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catalogue global des permissions du produit (non spécifique à une école)';


CREATE TABLE role_permissions (
    role_id         SMALLINT UNSIGNED NOT NULL,
    permission_id   SMALLINT UNSIGNED NOT NULL,
    granted_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_roleperm_permission (permission_id),
    CONSTRAINT fk_roleperm_role FOREIGN KEY (role_id)
        REFERENCES roles (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_roleperm_permission FOREIGN KEY (permission_id)
        REFERENCES permissions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE user_roles (
    user_id     BIGINT UNSIGNED   NOT NULL,
    role_id     SMALLINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED   NULL,
    assigned_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    KEY idx_userroles_role (role_id),
    CONSTRAINT fk_userroles_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_userroles_role FOREIGN KEY (role_id)
        REFERENCES roles (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un utilisateur peut cumuler plusieurs rôles (ex: enseignant + titulaire de classe)';


-- =====================================================================
--  SECTION 4 — ANNÉES SCOLAIRES
-- =====================================================================

CREATE TABLE academic_years (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(20)     NOT NULL COMMENT 'ex: 2026-2027',
    name            VARCHAR(100)    NOT NULL,
    starts_on       DATE            NOT NULL,
    ends_on         DATE            NOT NULL,
    status          ENUM('draft','active','closed','archived') NOT NULL DEFAULT 'draft',
    -- NULLABLE À DESSEIN : 1 = année courante, NULL = année non courante.
    -- Un index UNIQUE ignore les valeurs NULL sous MySQL comme sous MariaDB.
    -- uq_year_current (school_id, is_current) garantit donc, au niveau de la
    -- base, qu'une école n'a jamais deux années courantes simultanément —
    -- sans dépendre d'une colonne générée, que MariaDB refuse ici.
    -- Ne jamais écrire 0 dans cette colonne : utiliser NULL.
    is_current      TINYINT(1)      NULL DEFAULT NULL,
    closed_at       DATETIME        NULL,
    closed_by       BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_year_school_code (school_id, code),
    UNIQUE KEY uq_year_current (school_id, is_current),
    KEY idx_year_school_status (school_id, status),
    CONSTRAINT fk_years_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Années scolaires. Une année clôturée devient historique et reste consultable.';


-- =====================================================================
--  SECTION 5 — SÉCURITÉ, TRAÇABILITÉ, SYNCHRONISATION
-- =====================================================================

CREATE TABLE audit_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED NULL,
    user_id         BIGINT UNSIGNED NULL,
    action          VARCHAR(60)     NOT NULL COMMENT 'create, update, delete, login, logout, export, ...',
    entity_type     VARCHAR(60)     NULL COMMENT 'Nom logique de l entité, ex: student',
    entity_id       BIGINT UNSIGNED NULL,
    old_values      JSON            NULL,
    new_values      JSON            NULL,
    description     VARCHAR(255)    NULL,
    ip_address      VARBINARY(16)   NULL,
    user_agent      VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_school_date (school_id, created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Journal d audit. Aucune FK sur user_id : le journal doit survivre à la suppression du compte.';


CREATE TABLE login_attempts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier      VARCHAR(190)    NOT NULL COMMENT 'Username ou email saisi',
    ip_address      VARBINARY(16)   NOT NULL,
    successful      TINYINT(1)      NOT NULL DEFAULT 0,
    user_agent      VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_identifier (identifier, created_at),
    KEY idx_attempts_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Limitation des tentatives de connexion (double garde : par identifiant ET par IP)';


CREATE TABLE password_resets (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    token_hash      CHAR(64)        NOT NULL COMMENT 'SHA-256 du jeton — le jeton en clair n est jamais stocké',
    expires_at      DATETIME        NOT NULL,
    used_at         DATETIME        NULL,
    ip_address      VARBINARY(16)   NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_user (user_id, expires_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE user_sessions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    school_id       BIGINT UNSIGNED NULL,
    session_token   CHAR(64)        NOT NULL COMMENT 'Hash de l identifiant de session PHP',
    ip_address      VARBINARY(16)   NULL,
    user_agent      VARCHAR(255)    NULL,
    last_activity   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at      DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_session_token (session_token),
    KEY idx_session_user (user_id, revoked_at),
    KEY idx_session_activity (last_activity),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sessions actives — permet « déconnecter tous mes appareils » et la détection de vol de session';


-- ---------------------------------------------------------------------
--  Socle de synchronisation hors connexion (Phase 8).
--  Créé dès la Phase 1 : ajouter ces tables plus tard obligerait à
--  migrer des dizaines de milliers de lignes de notes et de présences.
-- ---------------------------------------------------------------------

CREATE TABLE sync_devices (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    device_uuid     CHAR(36)        NOT NULL COMMENT 'Généré par le navigateur, stocké en IndexedDB',
    device_label    VARCHAR(100)    NULL,
    last_sync_at    DATETIME        NULL,
    last_seen_at    DATETIME        NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_device_uuid (device_uuid),
    KEY idx_device_school (school_id, user_id),
    CONSTRAINT fk_devices_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_devices_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE sync_queue (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED NOT NULL,
    device_id       BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    client_uuid     CHAR(36)        NOT NULL COMMENT 'Clé d idempotence générée hors ligne',
    entity_type     VARCHAR(60)     NOT NULL,
    entity_id       BIGINT UNSIGNED NULL COMMENT 'Renseigné après application côté serveur',
    operation       ENUM('create','update','delete') NOT NULL,
    payload         JSON            NOT NULL,
    client_version  INT UNSIGNED    NOT NULL DEFAULT 1,
    client_time     DATETIME        NOT NULL COMMENT 'Horodatage de l appareil — peut être faux, ne jamais l utiliser seul pour arbitrer',
    status          ENUM('pending','applied','conflict','rejected') NOT NULL DEFAULT 'pending',
    error_message   VARCHAR(255)    NULL,
    received_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at      DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_client_uuid (client_uuid),
    KEY idx_sync_status (school_id, status, received_at),
    KEY idx_sync_entity (entity_type, entity_id),
    CONSTRAINT fk_sync_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sync_device FOREIGN KEY (device_id)
        REFERENCES sync_devices (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='File des opérations reçues des appareils hors connexion. client_uuid UNIQUE = rejeu sans doublon.';


CREATE TABLE sync_conflicts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id       BIGINT UNSIGNED NOT NULL,
    sync_queue_id   BIGINT UNSIGNED NOT NULL,
    entity_type     VARCHAR(60)     NOT NULL,
    entity_id       BIGINT UNSIGNED NULL,
    server_values   JSON            NULL,
    client_values   JSON            NULL,
    resolution      ENUM('pending','server_wins','client_wins','merged','manual') NOT NULL DEFAULT 'pending',
    resolved_by     BIGINT UNSIGNED NULL,
    resolved_at     DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conflict_school (school_id, resolution),
    CONSTRAINT fk_conflict_school FOREIGN KEY (school_id)
        REFERENCES schools (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_conflict_queue FOREIGN KEY (sync_queue_id)
        REFERENCES sync_queue (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Conflits nécessitant un arbitrage. Aucune donnée n est écrasée silencieusement.';


-- =====================================================================
--  SECTION 6 — CLÉS ÉTRANGÈRES DIFFÉRÉES
--  (auto-références impossibles à déclarer à la création de la table)
-- =====================================================================

ALTER TABLE users
    ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE user_roles
    ADD CONSTRAINT fk_userroles_assigned_by FOREIGN KEY (assigned_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE academic_years
    ADD CONSTRAINT fk_years_closed_by FOREIGN KEY (closed_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE subscription_payments
    ADD CONSTRAINT fk_subpay_recorded_by FOREIGN KEY (recorded_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE sync_conflicts
    ADD CONSTRAINT fk_conflict_resolved_by FOREIGN KEY (resolved_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE;


SET FOREIGN_KEY_CHECKS = 1;
