-- =====================================================================
--  Phase 11C — un reçu vérifiable
-- =====================================================================
--
-- POURQUOI UN REÇU A BESOIN D'ÊTRE VÉRIFIABLE
-- -------------------------------------------
-- Le reçu est le seul document que la famille emporte, et le seul
-- justificatif qu'elle possède d'avoir payé. Deux fraudes le visent :
--
--   · le faux reçu, fabriqué par la famille ou par un tiers ;
--   · le reçu authentique qu'un caissier a remis SANS enregistrer
--     l'encaissement — l'argent reste dans sa poche, et l'école
--     réclamera la somme une seconde fois à la famille.
--
-- La seconde est la plus courante, et la plus douloureuse : c'est la
-- famille, de bonne foi, qui se retrouve à devoir payer deux fois. Un
-- reçu dont l'existence se vérifie publiquement la protège — le papier
-- ne prouve plus rien à lui seul, c'est la ligne en base qui prouve.
--
-- LE JETON EST UNIQUE, ET IL L'EST AUSSI FACE AUX DOCUMENTS.
-- La page publique `/verifier` sert déjà les documents de la phase 9A.
-- Deux familles de jetons qui s'y présentent doivent être discernables
-- sans ambiguïté : le service refuse d'attribuer à un reçu un jeton déjà
-- porté par un document.
--
-- Colonne NULLABLE : les paiements déjà enregistrés n'en ont pas, et la
-- migration leur en attribue un (voir la phase de remplissage). Une
-- colonne NOT NULL exigerait une valeur au milieu d'un ALTER, sur une
-- table qui peut être grosse.

-- La migration est REJOUABLE : chaque ALTER est gardé par
-- `information_schema`, seul motif portable qui survive à un arrêt à
-- mi-chemin (leçon des migrations 028 et 029).

-- LA BRANCHE « DÉJÀ FAIT » EST `DO 0`, PAS UN SELECT.
-- Première version : elle renvoyait `SELECT 'existe déjà'`. Le jeu de
-- résultats, jamais consommé, bloquait le `DEALLOCATE` suivant —
-- « Cannot execute queries while other unbuffered queries are active ».
-- La migration passait sur une base neuve et cassait sur une base déjà
-- migrée : exactement le cas qu'une garde d'idempotence doit couvrir.
--
--   > Une garde qui ne survit pas à son propre second passage ne garde
--   > rien.
--
-- `DO 0` évalue une expression et ne rend aucune ligne. C'est le motif
-- des migrations 028 et 029, éprouvé sur MySQL 8 et MariaDB.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'payments'
        AND COLUMN_NAME  = 'verify_token') > 0,
    'DO 0',
    'ALTER TABLE payments
        ADD COLUMN verify_token VARCHAR(14) NULL
            COMMENT ''Jeton public de verification du recu (phase 11C)''
            AFTER receipt_seq'
);

PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- L'unicité est portée par l'INDEX, jamais par une lecture qui la
-- précède : un SELECT avant l'INSERT écarte le cas ordinaire, il ne
-- sérialise rien. Plusieurs NULL cohabitent dans un index unique MySQL,
-- ce qui permet de garder la colonne nullable.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'payments'
        AND INDEX_NAME   = 'uq_payments_verify_token') > 0,
    'DO 0',
    'ALTER TABLE payments ADD UNIQUE KEY uq_payments_verify_token (verify_token)'
);

PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================================
--  LA PERMISSION
-- =====================================================================
-- `receipt.print` existe depuis la phase 1 et n'a JAMAIS été employée :
-- la route du reçu est gardée par `payment.view`, que les parents
-- détiennent aussi. Elle n'est pas ajoutée ici — elle existe — mais le
-- SECRÉTARIAT, qui tient souvent la caisse dans une école congolaise,
-- ne l'avait pas. Il ne l'obtient pas non plus : c'est une décision
-- commerciale qui appartient à l'école, et la phase 11B lui permet
-- désormais de composer le rôle qui lui convient.
--
-- Rien à écrire ici, donc.
--
-- (Une première version terminait par un `SELECT IF(...)` censé vérifier
-- que la permission existe. Il cassait le runner — un jeu de résultats
-- non consommé bloque la requête suivante — et n'aurait de toute façon
-- rien contrôlé : un message affiché n'arrête rien. Le vrai contrôle vit
-- dans `tests/receipt_verification.php`, où il peut échouer.
--
--   > Un contrôle qui ne peut pas faire échouer n'est pas un contrôle,
--   > c'est un commentaire qui coûte une requête.)
