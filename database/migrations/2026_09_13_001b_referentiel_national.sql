-- =====================================================================
--  SEED 003 — Référentiel national RDC (modèle)
--
--  Domaines d'apprentissage, sections et options des humanités,
--  catalogue des branches.
--
--  Ces données sont un MODÈLE : elles ne sont jamais utilisées
--  directement par une école. Chaque établissement en reçoit une copie
--  qu'il peut renommer, compléter ou réduire.
--
--  À exécuter APRÈS la migration 2026_09_13_001_phase2_referentiel.sql
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
--  Domaines d'apprentissage
-- ---------------------------------------------------------------------
INSERT INTO learning_domains (code, name, short_name, order_number) VALUES
('LANGUES',    'Langues',                          'Langues',    1),
('SCIENCES',   'Sciences et mathématiques',        'Sciences',   2),
('HUMAINES',   'Sciences humaines et sociales',    'Sc. hum.',   3),
('VIE',        'Éducation à la vie et citoyenneté','Éduc. vie',  4),
('TECHNIQUE',  'Technologies et travaux pratiques','Technique',  5),
('ARTS',       'Arts et expression',               'Arts',       6),
('SPORT',      'Éducation physique et sportive',   'EPS',        7),
('CONDUITE',   'Conduite et discipline',           'Conduite',   8);


-- ---------------------------------------------------------------------
--  Sections des humanités
--
--  Les sections n'existent qu'au niveau des humanités : le primaire et
--  le CTEB suivent un programme commun, sans filière.
-- ---------------------------------------------------------------------
INSERT INTO reference_sections (cycle_id, code, name, short_name, description, order_number) VALUES
((SELECT id FROM education_cycles WHERE code='HUMANITES'), 'SCIENTIFIQUE', 'Humanités scientifiques',              'Scientifique', 'Filière générale à dominante mathématique et scientifique', 1),
((SELECT id FROM education_cycles WHERE code='HUMANITES'), 'LITTERAIRE',   'Humanités littéraires',                'Littéraire',   'Filière générale à dominante lettres et langues',          2),
((SELECT id FROM education_cycles WHERE code='HUMANITES'), 'PEDAGOGIQUE',  'Humanités pédagogiques',               'Pédagogique',  'Formation initiale des enseignants du primaire',           3),
((SELECT id FROM education_cycles WHERE code='HUMANITES'), 'COMMERCIALE',  'Humanités commerciales et de gestion', 'Commerciale',  'Comptabilité, gestion, secrétariat',                       4),
((SELECT id FROM education_cycles WHERE code='HUMANITES'), 'INDUSTRIELLE', 'Humanités techniques industrielles',   'Industrielle', 'Électricité, mécanique, construction',                     5),
((SELECT id FROM education_cycles WHERE code='HUMANITES'), 'SOCIALE',      'Humanités techniques sociales',        'Sociale',      'Coupe et couture, hôtellerie, nutrition',                  6),
((SELECT id FROM education_cycles WHERE code='HUMANITES'), 'AGRICOLE',     'Humanités techniques agricoles',       'Agricole',     'Agriculture, élevage, vétérinaire',                        7);


-- ---------------------------------------------------------------------
--  Options par section
-- ---------------------------------------------------------------------
INSERT INTO reference_options (reference_section_id, code, name, short_name, order_number) VALUES
-- Scientifique
((SELECT id FROM reference_sections WHERE code='SCIENTIFIQUE'), 'MATH_PHYS',   'Mathématique-Physique',       'Math-Phys',   1),
((SELECT id FROM reference_sections WHERE code='SCIENTIFIQUE'), 'BIO_CHIM',    'Biologie-Chimie',             'Bio-Chimie',  2),
-- Littéraire
((SELECT id FROM reference_sections WHERE code='LITTERAIRE'),   'LATIN_PHILO', 'Latin-Philosophie',           'Latin-Philo', 1),
((SELECT id FROM reference_sections WHERE code='LITTERAIRE'),   'LATIN_GREC',  'Latin-Grec',                  'Latin-Grec',  2),
-- Pédagogique
((SELECT id FROM reference_sections WHERE code='PEDAGOGIQUE'),  'PED_GEN',     'Pédagogie générale',          'Péd. gén.',   1),
-- Commerciale
((SELECT id FROM reference_sections WHERE code='COMMERCIALE'),  'COM_GEST',    'Commerciale et gestion',      'Com-Gestion', 1),
((SELECT id FROM reference_sections WHERE code='COMMERCIALE'),  'SECRETARIAT', 'Secrétariat de direction',    'Secrétariat', 2),
((SELECT id FROM reference_sections WHERE code='COMMERCIALE'),  'INFO_GEST',   'Informatique de gestion',     'Info-Gestion',3),
-- Industrielle
((SELECT id FROM reference_sections WHERE code='INDUSTRIELLE'), 'ELECTRICITE', 'Électricité',                 'Électricité', 1),
((SELECT id FROM reference_sections WHERE code='INDUSTRIELLE'), 'ELECTRONIQUE','Électronique',                'Électronique',2),
((SELECT id FROM reference_sections WHERE code='INDUSTRIELLE'), 'MECA_GEN',    'Mécanique générale',          'Méca. gén.',  3),
((SELECT id FROM reference_sections WHERE code='INDUSTRIELLE'), 'MECA_AUTO',   'Mécanique automobile',        'Méca. auto',  4),
((SELECT id FROM reference_sections WHERE code='INDUSTRIELLE'), 'CONSTRUCTION','Construction et bâtiment',    'Construction',5),
-- Sociale
((SELECT id FROM reference_sections WHERE code='SOCIALE'),      'COUPE',       'Coupe et couture',            'Coupe-Cout.', 1),
((SELECT id FROM reference_sections WHERE code='SOCIALE'),      'HOTELLERIE',  'Hôtellerie et restauration',  'Hôtellerie',  2),
((SELECT id FROM reference_sections WHERE code='SOCIALE'),      'NUTRITION',   'Nutrition et diététique',     'Nutrition',   3),
-- Agricole
((SELECT id FROM reference_sections WHERE code='AGRICOLE'),     'AGRI_GEN',    'Agriculture générale',        'Agriculture', 1),
((SELECT id FROM reference_sections WHERE code='AGRICOLE'),     'VETERINAIRE', 'Vétérinaire',                 'Vétérinaire', 2);


-- ---------------------------------------------------------------------
--  Catalogue national des branches
--
--  Le maximum de points ne figure PAS ici : il dépend du niveau et de
--  l'option. « Mathématiques » vaut 20 points en 3e primaire et 40 en
--  1re Math-Physique. Cette valeur vit dans curriculum_subjects.
-- ---------------------------------------------------------------------
INSERT INTO reference_subjects (domain_id, code, name, short_name, order_number) VALUES
-- Langues
((SELECT id FROM learning_domains WHERE code='LANGUES'),   'FRANCAIS',     'Français',                            'Français',    1),
((SELECT id FROM learning_domains WHERE code='LANGUES'),   'ANGLAIS',      'Anglais',                             'Anglais',     2),
((SELECT id FROM learning_domains WHERE code='LANGUES'),   'LANGUE_NAT',   'Langue nationale',                    'Langue nat.', 3),
((SELECT id FROM learning_domains WHERE code='LANGUES'),   'LATIN',        'Latin',                               'Latin',       4),
((SELECT id FROM learning_domains WHERE code='LANGUES'),   'GREC',         'Grec',                                'Grec',        5),
-- Sciences
((SELECT id FROM learning_domains WHERE code='SCIENCES'),  'MATH',         'Mathématiques',                       'Math',        10),
((SELECT id FROM learning_domains WHERE code='SCIENCES'),  'PHYSIQUE',     'Physique',                            'Physique',    11),
((SELECT id FROM learning_domains WHERE code='SCIENCES'),  'CHIMIE',       'Chimie',                              'Chimie',      12),
((SELECT id FROM learning_domains WHERE code='SCIENCES'),  'BIOLOGIE',     'Biologie',                            'Biologie',    13),
((SELECT id FROM learning_domains WHERE code='SCIENCES'),  'SCIENCES',     'Sciences',                            'Sciences',    14),
((SELECT id FROM learning_domains WHERE code='SCIENCES'),  'SVT',          'Sciences de la vie et de la Terre',   'SVT',         15),
-- Sciences humaines
((SELECT id FROM learning_domains WHERE code='HUMAINES'),  'HISTOIRE',     'Histoire',                            'Histoire',    20),
((SELECT id FROM learning_domains WHERE code='HUMAINES'),  'GEOGRAPHIE',   'Géographie',                          'Géographie',  21),
((SELECT id FROM learning_domains WHERE code='HUMAINES'),  'PHILOSOPHIE',  'Philosophie',                         'Philo',       22),
((SELECT id FROM learning_domains WHERE code='HUMAINES'),  'PSYCHO',       'Psychologie',                         'Psycho',      23),
((SELECT id FROM learning_domains WHERE code='HUMAINES'),  'SOCIOLOGIE',   'Sociologie',                          'Sociologie',  24),
-- Éducation à la vie
((SELECT id FROM learning_domains WHERE code='VIE'),       'EDUC_CIVIQUE', 'Éducation civique et morale',         'Éduc. civ.',  30),
((SELECT id FROM learning_domains WHERE code='VIE'),       'RELIGION',     'Religion',                            'Religion',    31),
((SELECT id FROM learning_domains WHERE code='VIE'),       'EDUC_VIE',     'Éducation à la vie',                  'Éduc. vie',   32),
-- Technique et technologies
((SELECT id FROM learning_domains WHERE code='TECHNIQUE'), 'TIC',          'Technologies de l''information',      'TIC',         40),
((SELECT id FROM learning_domains WHERE code='TECHNIQUE'), 'TECHNOLOGIE',  'Technologie',                         'Technologie', 41),
((SELECT id FROM learning_domains WHERE code='TECHNIQUE'), 'DESSIN_TECH',  'Dessin technique',                    'Dessin tech.',42),
((SELECT id FROM learning_domains WHERE code='TECHNIQUE'), 'TRAV_PRAT',    'Travaux pratiques',                   'Trav. prat.', 43),
((SELECT id FROM learning_domains WHERE code='TECHNIQUE'), 'COMPTABILITE', 'Comptabilité',                        'Compta',      44),
((SELECT id FROM learning_domains WHERE code='TECHNIQUE'), 'DROIT',        'Droit commercial',                    'Droit',       45),
((SELECT id FROM learning_domains WHERE code='TECHNIQUE'), 'ECONOMIE',     'Économie politique',                  'Économie',    46),
((SELECT id FROM learning_domains WHERE code='TECHNIQUE'), 'PEDAGOGIE',    'Pédagogie générale',                  'Pédagogie',   47),
((SELECT id FROM learning_domains WHERE code='TECHNIQUE'), 'DIDACTIQUE',   'Didactique des disciplines',          'Didactique',  48),
((SELECT id FROM learning_domains WHERE code='TECHNIQUE'), 'AGRONOMIE',    'Agronomie',                           'Agronomie',   49),
-- Arts
((SELECT id FROM learning_domains WHERE code='ARTS'),      'DESSIN',       'Dessin et arts plastiques',           'Dessin',      60),
((SELECT id FROM learning_domains WHERE code='ARTS'),      'MUSIQUE',      'Musique et chant',                    'Musique',     61),
-- Sport
((SELECT id FROM learning_domains WHERE code='SPORT'),     'EPS',          'Éducation physique et sportive',      'EPS',         70),
-- Conduite
((SELECT id FROM learning_domains WHERE code='CONDUITE'),  'CONDUITE',     'Conduite',                            'Conduite',    80);
