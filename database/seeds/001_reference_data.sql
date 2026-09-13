-- =====================================================================
--  SEED 001 — Référentiel national RDC + offres SaaS
--  À exécuter APRÈS database/schema.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  Cycles du système éducatif congolais
-- ---------------------------------------------------------------------
INSERT INTO education_cycles (code, name, short_name, order_number, is_optional, is_active) VALUES
('MATERNELLE', 'Enseignement maternel',                    'Maternelle', 1, 1, 1),
('PRIMAIRE',   'Enseignement primaire',                    'Primaire',   2, 0, 1),
('CTEB',       'Cycle Terminal de l''Éducation de Base',   'CTEB',       3, 0, 1),
('HUMANITES',  'Humanités',                                'Humanités',  4, 0, 1);


-- ---------------------------------------------------------------------
--  Niveaux scolaires
--  order_number = position dans le parcours complet de l'élève (1 à 13).
--  C'est cette colonne qui permettra de calculer automatiquement le
--  passage de classe et l'orientation, sans jamais coder « 8ème » en dur.
-- ---------------------------------------------------------------------
INSERT INTO education_levels (cycle_id, code, name, short_name, order_number, age_min, age_max, requires_option) VALUES
-- Maternelle (optionnelle)
((SELECT id FROM education_cycles WHERE code='MATERNELLE'), 'MAT_1',  '1ère année maternelle', '1ère Mat', 1,  3,  4, 0),
((SELECT id FROM education_cycles WHERE code='MATERNELLE'), 'MAT_2',  '2ème année maternelle', '2ème Mat', 2,  4,  5, 0),
((SELECT id FROM education_cycles WHERE code='MATERNELLE'), 'MAT_3',  '3ème année maternelle', '3ème Mat', 3,  5,  6, 0),
-- Primaire
((SELECT id FROM education_cycles WHERE code='PRIMAIRE'),   'PRI_1',  '1ère année primaire',   '1ère P',   4,  6,  8, 0),
((SELECT id FROM education_cycles WHERE code='PRIMAIRE'),   'PRI_2',  '2ème année primaire',   '2ème P',   5,  7,  9, 0),
((SELECT id FROM education_cycles WHERE code='PRIMAIRE'),   'PRI_3',  '3ème année primaire',   '3ème P',   6,  8, 10, 0),
((SELECT id FROM education_cycles WHERE code='PRIMAIRE'),   'PRI_4',  '4ème année primaire',   '4ème P',   7,  9, 11, 0),
((SELECT id FROM education_cycles WHERE code='PRIMAIRE'),   'PRI_5',  '5ème année primaire',   '5ème P',   8, 10, 12, 0),
((SELECT id FROM education_cycles WHERE code='PRIMAIRE'),   'PRI_6',  '6ème année primaire',   '6ème P',   9, 11, 13, 0),
-- CTEB (7ème et 8ème années de l'éducation de base)
((SELECT id FROM education_cycles WHERE code='CTEB'),       'CTEB_7', '7ème année de l''éducation de base', '7ème EB', 10, 12, 14, 0),
((SELECT id FROM education_cycles WHERE code='CTEB'),       'CTEB_8', '8ème année de l''éducation de base', '8ème EB', 11, 13, 15, 0),
-- Humanités (exigent une section + une option)
((SELECT id FROM education_cycles WHERE code='HUMANITES'),  'HUM_1',  '1ère année des humanités', '1ère H', 12, 14, 16, 1),
((SELECT id FROM education_cycles WHERE code='HUMANITES'),  'HUM_2',  '2ème année des humanités', '2ème H', 13, 15, 17, 1),
((SELECT id FROM education_cycles WHERE code='HUMANITES'),  'HUM_3',  '3ème année des humanités', '3ème H', 14, 16, 18, 1),
((SELECT id FROM education_cycles WHERE code='HUMANITES'),  'HUM_4',  '4ème année des humanités', '4ème H', 15, 17, 19, 1);


-- ---------------------------------------------------------------------
--  Offres commerciales du SaaS
--  Tarifs en USD, pratique courante pour les abonnements B2B en RDC.
--  À ajuster : ce sont des valeurs de départ, pas une étude de prix.
-- ---------------------------------------------------------------------
INSERT INTO plans (code, name, description, price_monthly, price_yearly, currency, max_students, max_users, max_storage_mb, features, sort_order) VALUES
('DECOUVERTE', 'Découverte', 'Essai gratuit 60 jours, école de petite taille',
 0.00, 0.00, 'USD', 150, 15, 512,
 JSON_OBJECT('offline', false, 'sms', false, 'parent_portal', false, 'reports_advanced', false, 'api', false), 1),

('ESSENTIEL', 'Essentiel', 'Gestion complète des élèves, notes et finances',
 25.00, 250.00, 'USD', 600, 50, 2048,
 JSON_OBJECT('offline', true, 'sms', false, 'parent_portal', true, 'reports_advanced', false, 'api', false), 2),

('PRO', 'Professionnel', 'Toutes les fonctionnalités + portails parents et élèves',
 60.00, 600.00, 'USD', 2000, 200, 10240,
 JSON_OBJECT('offline', true, 'sms', true, 'parent_portal', true, 'reports_advanced', true, 'api', false), 3),

('RESEAU', 'Réseau scolaire', 'Établissements à implantations multiples, sans limite d''effectif',
 150.00, 1500.00, 'USD', NULL, NULL, 51200,
 JSON_OBJECT('offline', true, 'sms', true, 'parent_portal', true, 'reports_advanced', true, 'api', true), 4);
