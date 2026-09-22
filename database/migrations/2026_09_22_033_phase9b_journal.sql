-- ---------------------------------------------------------------------
-- Phase 9B — LE JOURNAL
--
-- `audit.view` et `platform.audit.view` sont semees depuis la phase 1 et
-- n'ouvraient aucun ecran. Cette migration n'en cree qu'une seule, la
-- purge, parce que la lecture existait deja en droit.
--
-- AUCUNE MODIFICATION DE `audit_logs`.
-- La table porte deja tout ce qu'il faut, avec ses index :
--   idx_audit_school_date (school_id, created_at)
--   idx_audit_entity      (entity_type, entity_id)
--   idx_audit_user        (user_id, created_at)
-- Le tri de l'ecran est `ORDER BY id DESC` apres filtrage sur
-- `school_id` : la premiere colonne de idx_audit_school_date suffit a
-- reduire, et `id DESC` suit l'ordre de la cle primaire.
--
-- Migration idempotente : rejouable sans effet de bord.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- 1. LA PURGE EST UNE PERMISSION A PART
--
-- Elle n'est PAS donnee a qui lit le journal. Lire et pouvoir effacer
-- sont deux pouvoirs differents : la confondre reviendrait a permettre a
-- tout consultant d'effacer ce qui le derange.
--
-- Elle ne va qu'a SCHOOL_ADMIN et SUPER_ADMIN. Volontairement PAS a
-- DIRECTION, qui lit le journal : le chef d'etablissement est justement
-- l'une des personnes que ce journal trace.
-- ---------------------------------------------------------------------
INSERT INTO permissions (code, module, name, description, is_platform, sort_order)
SELECT 'audit.purge', 'audit', 'Purger le journal',
       'Supprimer les entrees de journal anterieures a une anciennete donnee. '
       'Jamais a l unite, jamais en deca de 365 jours, et la purge est elle-meme journalisee.',
       0, 2
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'audit.purge');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'audit.purge'
 WHERE r.code IN ('SCHOOL_ADMIN', 'SUPER_ADMIN')
   AND r.school_id IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );

-- ---------------------------------------------------------------------
-- 2. INDEX POUR LE FILTRE PAR ACTION
--
-- L'ecran filtre par action sur une table qui grossit a chaque geste du
-- produit. Sans index, `WHERE school_id = ? AND action = ?` lit toute
-- l'ecole. idx_audit_school_date commence par school_id mais enchaine
-- sur created_at : il ne sert pas le filtre par action.
--
-- On ajoute (school_id, action, id) : il couvre le filtre ET le tri
-- `ORDER BY id DESC` sans tri de fichier.
-- ---------------------------------------------------------------------
SET @idx := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'audit_logs'
       AND index_name   = 'idx_audit_school_action'
);

SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_audit_school_action ON audit_logs (school_id, action, id)',
    'SELECT 1');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
