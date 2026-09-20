-- =====================================================================
--  MIGRATION — Phase 7A : chaque ecole a un abonnement
--
--  CE QUI MANQUAIT
--  ---------------
--  Les tables `plans`, `subscriptions` et `subscription_payments`
--  existent depuis la phase 1, et quatre offres sont semees. Mais
--  `subscriptions` etait VIDE : aucune ecole n'avait d'abonnement, le
--  tableau de bord lisait un NULL, et AUCUNE limite n'etait appliquee
--  nulle part. Une ecole sur Decouverte pouvait inscrire dix mille
--  eleves.
--
--  Le modele etait pose, la regle n'avait jamais ete branchee.
--
--
--  CHAQUE ECOLE RECOIT UN ESSAI, PAS UN VIDE
--  -----------------------------------------
--  Un abonnement absent n'est pas « pas de limite » : c'est une
--  situation que le code doit interpreter, et toute interpretation
--  finit par diverger entre deux ecrans. Chaque ecole existante recoit
--  donc un essai de 30 jours sur l'offre DECOUVERTE.
--
--  `status = 'trial'` et non 'active' : l'essai dit la verite. Une ecole
--  qui n'a jamais paye n'est pas une ecole abonnee.
--
--
--  POURQUOI `max_users` NE COMPTE QUE LE PERSONNEL
--  -----------------------------------------------
--  L'offre ESSENTIEL autorise 600 eleves et 50 utilisateurs. Avec les
--  espaces familles livres en phase 6, 600 eleves produisent jusqu'a
--  600 comptes eleves et autant de comptes tuteurs : la limite serait
--  depassee des la premiere classe.
--
--  La regle retenue, et ecrite dans le service : `max_users` compte les
--  comptes du PERSONNEL — ceux qui ne sont rattaches ni a une fiche
--  tuteur ni a une fiche eleve. Un plan se vend au nombre d'eleves ;
--  ouvrir le portail aux familles ne doit pas couter une montee de
--  gamme.
--
--  Aucune colonne n'est ajoutee pour cela : la distinction se lit dans
--  les liens `guardians.user_id` et `students.user_id`, qui sont deja
--  la source de verite du portail. Ajouter un drapeau `is_family` sur
--  `users` creerait une seconde verite, qui finirait par contredire la
--  premiere.
--
--
--  LA PERMISSION DE CHANGER D'OFFRE
--  --------------------------------
--  `platform.subscription.manage` existe deja (phase 1) et n'est
--  accordee qu'au SUPER_ADMIN : c'est l'editeur du SaaS qui vend, pas
--  l'ecole qui se sert. Une ecole CONSULTE son abonnement ; elle n'en
--  change pas toute seule.
-- =====================================================================

INSERT INTO subscriptions (school_id, plan_id, status, billing_cycle, starts_on, ends_on, auto_renew)
SELECT s.id,
       (SELECT id FROM plans WHERE code = 'DECOUVERTE' LIMIT 1),
       'trial',
       'yearly',
       CURDATE(),
       DATE_ADD(CURDATE(), INTERVAL 30 DAY),
       0
  FROM schools s
 WHERE NOT EXISTS (
       SELECT 1 FROM subscriptions sub WHERE sub.school_id = s.id
 );

-- ---------------------------------------------------------------------
--  LA PERMISSION DE CONSULTER SON PROPRE ABONNEMENT
--
--  Distincte de `platform.subscription.manage` : l'ecole regarde, la
--  plateforme decide. Accordee a la direction et a l'administrateur
--  d'ecole — c'est une information de gestion, pas de scolarite.
-- ---------------------------------------------------------------------
INSERT INTO permissions (code, module, name, is_platform, sort_order)
VALUES ('subscription.view', 'school', 'Consulter l abonnement de l ecole', 0, 120);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.code = 'subscription.view'
 WHERE r.school_id IS NULL
   AND r.code IN ('SUPER_ADMIN', 'SCHOOL_ADMIN', 'DIRECTION');
