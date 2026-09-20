-- =====================================================================
--  MIGRATION — Phase 6B : moindre privilege pour les familles
--
--  CE QUE LA RECETTE A TROUVE
--  --------------------------
--  Un eleve connecte, et un tuteur connecte, atteignaient `/presences` —
--  l'ECRAN DU PERSONNEL, celui ou l'on fait l'appel. Le menu leur
--  proposait meme l'entree « Presences ».
--
--  Ce n'est pas une fuite : le perimetre pose en phase 4D filtre les
--  classes, et l'ecran leur revient VIDE. Mais c'est un defaut reel :
--  une entree de menu qui ne mene nulle part, sur un ecran qui n'a pas
--  ete pense pour eux.
--
--
--  POURQUOI LA PERMISSION EXISTAIT
--  -------------------------------
--  `attendance.view` a ete accordee a PARENT et ELEVE en phase 1, quand
--  aucun ecran familial n'existait : elle tenait lieu de promesse — « un
--  jour, les familles verront les absences ».
--
--  La phase 6 a tenu cette promesse AUTREMENT. Le portail ne se fonde
--  sur AUCUNE permission : `guardians.user_id` et `students.user_id`
--  sont ses seules cles. Les familles voient donc desormais les absences
--  dans `/espace/enfant/{id}`, et la permission n'ouvre plus qu'une
--  porte dont elles n'ont pas l'usage.
--
--  La retirer, c'est appliquer le moindre privilege : une permission qui
--  ne sert plus a rien ne doit pas rester accordee « au cas ou ».
--
--
--  CE QUI N'EST PAS TOUCHE, ET POURQUOI
--  ------------------------------------
--  · `student.view` reste au PARENT : la phase 3 l'a explicitement
--    conservee avec son perimetre, et la fiche de l'eleve reste utile a
--    un tuteur. La question de la restreindre au profit du seul portail
--    est posee au developpeur, pas tranchee ici.
--  · `finance.view` et `payment.view` restent : le portail s'en sert
--    pour l'avis de paiement imprimable et le recu.
--  · `document.view` et `timetable.view` n'ouvrent aucune route
--    aujourd'hui : les retirer serait sans effet, et ces modules
--    viendront avec leur propre ecran familial.
--
--
--  REVERSIBLE
--  ----------
--  Un etablissement qui voudrait vraiment ouvrir l'ecran d'appel a ses
--  parents peut leur reaccorder `attendance.view` depuis la gestion des
--  roles. C'est alors une decision explicite.
-- =====================================================================

DELETE rp
  FROM role_permissions rp
  JOIN roles r       ON r.id = rp.role_id
  JOIN permissions p ON p.id = rp.permission_id
 WHERE r.school_id IS NULL
   AND r.code IN ('PARENT', 'ELEVE')
   AND p.code = 'attendance.view';
