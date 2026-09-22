-- =====================================================================
--  MIGRATION — Phase 8B1 : le hors connexion
--
--  PRESQUE RIEN A AJOUTER, ET C'EST VOULU
--  =======================================
--  `sync_devices`, `sync_queue` et `sync_conflicts` existent depuis la
--  phase 1 et n'ont JAMAIS servi. Elles portaient deja les bonnes
--  intentions, jusque dans leurs commentaires :
--
--    client_uuid   UNIQUE  « cle d idempotence generee hors ligne »
--    client_time           « peut etre faux, ne jamais l utiliser
--                            seul pour arbitrer »
--    sync_conflicts        « aucune donnee n est ecrasee silencieusement »
--
--  C'etait le meme motif que les permissions `user.*` avant la 7D : une
--  intention semee sans porte. Cette phase est la porte, et elle n'a
--  pas besoin de reecrire le schema pour l'ouvrir.
--
--    > Une table qui attend depuis un an n'est pas une dette : c'est
--    > une decision prise tot. Encore faut-il finir par l'honorer.
--
--  DEUX PERMISSIONS, PAS UNE
--  ==========================
--  `sync.view` — voir la file et les conflits de son etablissement.
--  `sync.resolve` — ARBITRER un conflit, c'est-a-dire decider laquelle
--  de deux versions d'un appel fait foi.
--
--  Les separer suit la regle du projet : consulter une divergence et
--  trancher laquelle gagne sont deux pouvoirs de nature differente. La
--  seconde reecrit un registre ; la premiere le regarde.
--
--  Un enseignant n'a besoin d'AUCUNE des deux pour travailler hors
--  connexion : sa file d'attente est la sienne, elle s'affiche sur son
--  propre ecran d'appel. Ces permissions servent a regarder le parc de
--  l'etablissement, pas son propre telephone.
--
--  PORTABILITE : FROM DUAL, portable MySQL 8 et MariaDB. Rejouable.
--  AUCUNE DONNEE EXISTANTE N'EST SUPPRIMEE NI MODIFIEE.
-- =====================================================================

INSERT INTO permissions (code, module, name, description, is_platform, sort_order)
SELECT 'sync.view', 'sync', 'Consulter la synchronisation',
       'Voir les appareils, la file d attente et les conflits de l etablissement.', 0, 1
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'sync.view');

INSERT INTO permissions (code, module, name, description, is_platform, sort_order)
SELECT 'sync.resolve', 'sync', 'Arbitrer un conflit de synchronisation',
       'Decider laquelle de deux versions divergentes fait foi.', 0, 2
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'sync.resolve');

-- `sync.view` va aussi au PREFET : c'est lui qui suit les registres au
-- quotidien, et une file bloquee est d'abord son probleme.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'sync.view'
 WHERE r.code IN ('SUPER_ADMIN', 'SCHOOL_ADMIN', 'DIRECTION', 'PREFET')
   AND r.school_id IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );

-- L'ARBITRAGE REECRIT UN REGISTRE : il reste a la direction.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'sync.resolve'
 WHERE r.code IN ('SUPER_ADMIN', 'SCHOOL_ADMIN', 'DIRECTION')
   AND r.school_id IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );
