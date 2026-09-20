# État du projet — School SaaS RDC

**Emplacement** : `C:\laragon\www\school-saas` (dépôt Git déjà initialisé)
**Version** : 0.4.2 — phases 1 à 6 terminées et auditées · phase 7A livrée et auditée · **garde-fou multi-école durci**
**Dernière mise à jour** : 20/09/2026
**Tests** : 635 verts sur base fraîche (12 suites)

**Environnement local constaté** : Laragon 6.0, Apache 2.4.54 **sur le port 8000** (pas 80), PHP 8.4.16 en FastCGI (mod_fcgid), MySQL 8.0.30.
URL de travail : `http://school-saas.test:8000`

> Ce document est maintenu dans le dépôt, à `docs-projet/etat-du-projet.md`,
> et publié dans le projet Claude. Modifier le fichier du dépôt, pas la copie.

---

## Plan en 10 phases — feuille de route de référence

| # | Phase | Contenu | État |
|---|---|---|---|
| 1 | **Fondation** | Noyau, base, droits, authentification, interface | ✅ |
| 2 | **Référentiel scolaire** | Sections, options, matières, programmes, maxima | ✅ |
| 3 | **Élèves** | Inscription, dossier, réinscription, orientation, historique | ✅ + audit |
| 4 | **Pédagogie** | 4A Enseignants ✅ · 4B Notes ✅ · 4C Bulletins ✅ · 4D Présences ✅ | ✅ |
| 5 | **Finances** | 5A Grille ✅ · 5B Encaissements ✅ · 5C Recouvrement ✅ · 5D Dépenses ✅ + audit + recette | ✅ |
| 6 | **Portails** | 6A Portail parent ✅ + audit · 6B Espace élève ✅ + audit | ✅ |
| 7 | **Abonnements** | 7A Offres et limites ✅ + audit · 7B Facturation SaaS ⏭ · 7C Mobile Money ⛔ | 🔵 en cours |
| 8 | **Hors connexion** | PWA, Service Worker, IndexedDB, synchronisation | à venir |
| 9 | **Modules avancés** | Communication, documents, rapports, archives, QR code | à venir |
| 10 | **Recette** | Tests d'acceptation, durcissement, mise en production | à venir |

> Cette feuille de route est la référence. Ne pas la renuméroter : les
> phases 6 à 10 ont déjà été citées sous ces numéros dans les décisions
> antérieures.

Documentation par module : `phase-2-referentiel.md`, `phase-3-eleves.md`,
`audit-phase-3.md`, `phase-4a-enseignants.md`, `phase-4b-notes.md`,
`audit-phase-4b.md`, `phase-4c-bulletins.md`, `conformite-bulletin-officiel.md`,
`phase-5a-frais.md`, `phase-5b-caisse.md`, `phase-5cd-recouvrement-depenses.md`,
`audit-phase-5d.md`, `recette-finances.md`, `phase-6a-portail-parents.md`,
`phase-6b-espace-eleve.md`, `audit-phase-6b.md`, `phase-7a-abonnements.md`,
`audit-phase-7a.md`, `securite-perimetre-plateforme.md`.

---

## Comment tester — procédure de recette

### 1. Appliquer les migrations

```
cd C:\laragon\www\school-saas
php database\migrate.php --status
php database\migrate.php
```

> **La migration 021 MODIFIE des montants existants** : elle arrondit à l'unité
> les sommes libellées en CDF (écart maximal 0,49 CDF par ligne). Sauvegardez la
> base avant de l'appliquer si une école est déjà en service.

> **La migration 026 crée un abonnement d'essai (30 jours, offre Découverte)**
> pour toute école qui n'en a pas. Une école dépassant déjà 150 élèves ne pourra
> plus en inscrire tant que son offre n'aura pas été changée en base.

### 2. Lancer les tests automatisés

```
php tests\tenant_isolation.php
php tests\curriculum_isolation.php
php tests\students_isolation.php
php tests\students_perimetre.php
php tests\teachers_isolation.php
php tests\grades_isolation.php
php tests\bulletins_isolation.php
php tests\attendance_isolation.php
php tests\finance_isolation.php
php tests\portal_isolation.php
php tests\subscriptions_isolation.php
php tests\platform_scope.php
```

Chaque suite se nettoie derrière elle (bloc `finally`) et sort en code 0 si
tout passe. Total attendu : **635 tests verts**.

### 3. Peupler une école de démonstration puis parcourir l'application

```
php database\seed_demo.php
```

Parcours de recette dans le navigateur :

| Écran | Ce qu'on vérifie |
|---|---|
| `/notes/classe/{id}` | Tableau croisé branches × périodes, cases vertes/orange/grises |
| Une case de saisie | Refus au-dessus du maximum, case « Absent » |
| `/bulletins/classe/{id}` | Classement, mention « document de travail » avant publication |
| Publication | Le rang se fige ; ajouter une cote ensuite ne change plus le tableau |
| `/presences` | Classes « à faire » distinctes des classes sans absent ; badge **incomplet X/Y** |
| `/finances` | Compte les inscrits **non facturés** en rouge |
| `/finances/frais` | Créer un frais, choisir sa **portée**, l'affecter |
| Un frais en CDF à 45 000,60 | Stocké **45 001** : l'écran et la base disent la même chose |
| Relever un tarif | Les dettes déjà affectées **ne bougent pas** |
| `/finances/eleve/{id}/encaisser` | Payer en CDF une dette en USD ; le taux est figé sur le reçu |
| Double clic sur « Encaisser » | Le second envoi est **refusé**, pas dupliqué |
| Remise sur une dette, en comptable | **403** : `fee.waive` est réservée à la direction |
| `/finances/impayes` | Retard **par échéance** ; colonne avance ; export CSV |
| `/finances/depenses` | Bon numéroté, bénéficiaire obligatoire, situation de caisse |
| Une dépense datée hors de l'exercice | Refusée, avec la fenêtre acceptée dans le message |
| `/finances/journal` | Entrées **et** sorties, solde cumulé, monnaie remise |
| Fiche élève → tuteur → 🔑 | Crée l'accès ; le mot de passe s'affiche **une seule fois** |
| Se connecter avec ce compte | Toute route redirige vers `/mot-de-passe/changer` |
| `/espace` | Les enfants du tuteur, et eux seuls |
| `/espace/enfant/{id}` d'un autre élève | **404**, même pour le directeur |
| `/espace/bulletin/{id}` | Le bulletin **figé** : corriger une cote ensuite ne le change pas |
| `/bulletins/publie/{id}` | Le même document, côté personnel |
| Fiche élève → 🔑 « Créer son accès élève » | L'élève voit **son** dossier, et rien d'autre |
| Fiche élève → ↻ « Régénérer son accès » | Nouveau mot de passe ; un compte du personnel est **refusé** |
| `/presences` avec un compte PARENT ou ELEVE | **403** : l'appel est un écran de personnel |
| `/abonnement` en direction | Offre, échéance, et **une jauge par année comptée**, nommée |
| Créer une seconde année, non courante | Elle **apparaît** avec son propre décompte |
| Remplir cette seconde année jusqu'au plafond | L'alerte nomme **cette année-là**, pas toutes |
| Suspendre l'abonnement en base | **Aucune jauge**, aucun « / 0 » : l'état est écrit en mots |
| `/abonnement` en enseignant ou en élève | **403**, et l'entrée de menu est absente |
| Inscrire au-delà du plafond de l'offre | Refusé, message nommant l'offre et le plafond |
| Encaisser avec le plafond atteint | **Passe** : une limite ne ferme jamais la caisse |

---

## Méthode de travail établie

**Avant chaque nouvelle phase, la précédente est auditée par EXÉCUTION.**
Chaque audit a trouvé un défaut, toujours dans un chemin que les tests
n'exécutaient pas :

| Audit | Défaut trouvé |
|---|---|
| Phase 3 | `student.view` accordé à PARENT et ENSEIGNANT **sans périmètre** → dossier complet d'un mineur lisible |
| Phase 4A | `/classes/{id}` livrait la liste complète des élèves ; un enseignant gardait un accès à vie |
| Phase 4B | Cote enregistrable à **237 %** de son maximum figé ; colonne vide comptée « complète » |
| Phase 4C | Pourcentage archivé ≠ pourcentage imprimé ; publication sans contrôle de périmètre |
| Phase 4D | Appel de 2 élèves sur 40 affiché « fait » ; un appel « matin » après « journée » comptait chaque absence **deux fois** |
| Phase 5A | L'échappatoire de correction produisait un **solde négatif**, réécrivait la **devise**, et l'annulation était sans retour |
| Phase 5A (2) | Les `onchange` en attribut étaient **inertes** sous la CSP |
| Phase 5B | **Interblocage dès deux guichets simultanés** — le même motif dormait dans les matricules depuis la phase 3 |
| Phase 5C | Un versement pouvait être **compté deux fois** ; l'export CSV était **intestable, donc cassé** |
| Phase 5D | L'écran **mentait sur les francs** ; un exercice **clos** acceptait encore de l'argent ; une dépense s'imputait à n'importe quel exercice ; un double clic créait **deux écritures** |
| Recette Finances | Le **comptable pouvait annuler la dette d'une famille** — et il encaisse aussi. `fee.waive` sépare la grille de la dette |
| Phase 6A | Le **détail** d'un bulletin publié n'était pas archivé ; la liste des absences était **tronquée à 30 en silence** |
| Phase 6B | PARENT et ELEVE atteignaient `/presences`, l'écran d'appel du personnel ; un cache statique non clé faisait hériter à l'enseignant la réponse du tuteur ; **les comptes de familles étaient irrécupérables** — le message renvoyait vers un écran qui n'existe pas |
| Phase 7A (livraison) | Une jauge « Comptes du personnel » **rougissait à 100 %** en annonçant un blocage que rien ne porte : aucune porte n'appelle `subscription_can_add_staff_user()` |
| **Garde-fou** | Le contrôle testait la **mention** du mot `school_id`, pas son filtre : `SELECT school_id, COUNT(*) FROM students GROUP BY school_id` rendait 124 élèves de toutes les écoles sans une alerte — la forme exacte d'un tableau de bord éditeur, et celle d'une fuite. Le drapeau `db_query(…, true)` était une échappatoire sur l'honneur. Deux défauts de production au passage : une écriture de mot de passe sans filtre d'école, un décompte transversal derrière un simple `if` |
| **Audit 7A** | L'écran lisait **deux abonnements** : la carte annonçait « Réseau — résilié — 300 jours » à une école payant une offre Essentiel active. La jauge comptait l'année **courante** quand la limite compte l'année **visée** : « 2 places libres » là où l'inscription refusait. Et l'absence d'abonnement s'affichait comme un **plafond de zéro élève** |

### Règles tirées de ces audits

> **Une restriction d'accès ne vaut que si TOUTES les portes la portent.**

> **Une protection qui ne peut jamais se déclencher est pire qu'aucune
> protection** : elle donne l'illusion d'un contrôle.

> **Un dépôt non exécuté est un dépôt non testé.**

> **La permission dit ce qu'on a le droit de FAIRE, jamais SUR QUI.**

> **L'absence de donnée se lit comme une donnée nulle.** Compter la COUVERTURE,
> pas l'existence.

> **Une règle sans échappatoire devient un piège, mais l'échappatoire est
> l'endroit le plus dangereux du module.**

> **Rétrécir une portée ne retire jamais ce qui a déjà été créé.**

> **Un compteur verrouillé n'est jamais prouvé tant qu'il n'a pas tourné à
> PLUSIEURS PROCESSUS.**

> **Du code qui ne peut pas être exécuté par un test n'est pas du code testé —
> c'est du code non écrit.**

> **Une donnée affichée autrement qu'elle n'est stockée est un mensonge à
> retardement.** La précision d'une devise est une règle de STOCKAGE.

> **Une protection ajoutée après coup à un module d'argent déjà en service ne
> rattrape jamais les lignes déjà écrites.**

> **Une règle de cohérence qui refuse le travail réel n'est pas une règle,
> c'est une panne.**

> **Un jeton CSRF prouve l'ORIGINE d'une requête, jamais son UNICITÉ.**

> **Une permission qui recouvre deux pouvoirs de nature différente finit
> toujours par accorder le plus dangereux des deux.** La grille tarifaire se
> voit, la remise sur une ligne ne se voit nulle part.

> **Un périmètre de travail n'est pas un périmètre de famille.** Un enseignant
> doit voir les élèves de ses classes ; il n'a rien à lire dans leur espace
> familial. Le portail prend le plus étroit des deux, et il le prend seul.

> **Un écran qui tronque doit le dire.** Un plafond d'affichage invisible fait
> compter les lignes à l'utilisateur, qui conclut que le décompte est faux.

> **Une clé est juste par construction ; un paramètre `$refresh` oblige chaque
> appelant à y penser.** Un cache statique non clé répond la même chose à tout
> le monde dès la première question.

> **Un produit qui donne un accès doit savoir le rendre.** Un compte créé sans
> moyen de réinitialisation est perdu au premier oubli — et un élève n'a pas
> d'adresse e-mail.

> **Une sonde qui ne mesure rien ne prouve rien, même quand elle a raison.**
> En CLI, seul `public/index.php` charge la table des routes : une sonde y lisait
> une table vide et concluait juste, pour la mauvaise raison.

> **Une jauge qui annonce un plafond que rien ne porte est une promesse creuse.**
> Le jour où elle mordra vraiment, l'école aura appris à ne pas la croire.

> **Une limite commerciale ne ferme jamais la caisse.** Retenir l'argent d'une
> école pour la contraindre à payer serait une prise d'otage — et l'empêcherait
> précisément de payer. Le produit refuse de grandir, il ne se retourne pas
> contre son client.

> **Deux fonctions de lecture sur un même écran finissent toujours par désigner
> deux objets différents.** L'abonnement qui GOUVERNE est celui qui s'affiche ;
> un repli n'a le droit d'exister que là où rien ne gouverne.

> **Une jauge et une règle qui ne mesurent pas la même chose finissent toujours
> par se contredire devant l'utilisateur — et c'est la jauge qu'il croira.**

> **Un zéro technique qui signifie « rien n'est autorisé » n'est pas un plafond
> de zéro.** Un état s'affiche en mots ; seul un nombre qui veut dire quelque
> chose s'affiche en chiffres.

> **Le raisonnement interne n'a pas à être téléchargé par chaque visiteur.**
> Les commentaires d'architecture appartiennent au PHP, pas au HTML livré.

> **Un garde-fou qui ne distingue pas la requête légitime de la fuite qui lui
> ressemble ne garde rien.** Mentionner `school_id` n'est pas le filtrer.

> **Une échappatoire de sécurité qui ne dit pas QUI a le droit de l'emprunter
> n'est pas une échappatoire, c'est une porte.** Un drapeau booléen se recopie ;
> une exception nommée se relit et se conteste.

> **Une écriture sur les identifiants d'un compte ne doit pas dépendre d'un
> contrôle situé ailleurs dans la fonction**, qu'un remaniement pourrait
> déplacer. Le filtre vit DANS l'écriture, même s'il fait double emploi.

> **Un `if` n'est pas un périmètre.** Une restriction portée par une condition
> de contrôleur ne se voit pas depuis la requête qu'elle protège.

---

## Décisions d'architecture actées

| Décision | Choix retenu | Raison |
|---|---|---|
| **Modèle de notation** | **Maxima RDC** (le maximum *est* la pondération) | Format officiel EPST |
| **Périodes** | PRIMAIRE : 3 trimestres (annuel = 12×). CTEB et HUMANITÉS : 2 semestres (annuel = 8×) | Structure EPST |
| **Modèles de bulletin** | Deux gabarits **séparés** (`domaines`, `maxima`) | « Adapter par famille, ne fait pas un modèle adaptatif » |
| **Devises** | Une dette est due, et soldée, **dans sa devise** | Un solde converti à un taux flottant change tout seul |
| **Précision d'une devise** | Règle de **stockage** : `finance_round()` sur tout montant. CDF = 0 décimale | Un solde de 0,41 CDF s'affiche « 0 CDF » et rend une dette insoldable |
| **Taux de change** | Figé **sur le paiement** | Un reçu doit rester vérifiable dix ans plus tard |
| **Caisse** | Compte la monnaie **remise**, jamais celle créditée | Sinon la caisse ne se recoupe jamais |
| **Exercice clos** | Aucun mouvement d'argent. Échappatoire : réouverture explicite | Des chiffres remis au promoteur ne se réécrivent pas |
| **Date d'une opération** | Fenêtre de **180 j avant / 90 j après** l'exercice | Assez large pour l'avance et le règlement tardif |
| **Annulations** | Jamais de suppression : motif, auteur, date, numéro consommé | Un trou dans une séquence signale un détournement |
| **Écrans qui créent de l'argent** | Jeton CSRF **plus** jeton à usage unique | Le CSRF prouve l'origine, jamais l'unicité |
| **Remise sur une dette** | `fee.waive`, direction seule — distincte de `fee.manage` | Celui qui encaisse ne réduit pas ce qui est dû |
| **Portail des familles** | **Aucune permission** : `guardians.user_id` et `students.user_id` sont les seules clés | Aucune permission mal accordée ne peut ouvrir l'espace de l'enfant d'un autre |
| **Élève et solde des frais** | **Non par défaut**, réglable par l'école (`portal.student_sees_fees`) | En RDC, la dette est l'affaire des parents |
| **Décompte d'un abonnement** | Les **inscrits de l'année visée**, jamais les dossiers | Compter les archives reviendrait à faire payer l'archivage |
| **Années comptées à l'écran** | L'année courante **et** toute année non clôturée à venir — une jauge par année | Ce sont exactement les années où une inscription peut être refusée |
| **Abonnement affiché** | Celui qui **gouverne** (`subscription_to_show()`) ; `any()` n'est qu'un repli | Un écran, une vérité |
| **`max_users`** | Ne compte **que le personnel** | 600 élèves produisent jusqu'à 1 200 comptes de familles ; ouvrir le portail ne doit pas coûter une montée de gamme |
| **Limite atteinte** | Refuse la **création**, jamais la lecture ni l'encaissement | Une limite qui coupe la caisse empêche l'école de payer |
| **Absence d'abonnement** | Un **état nommé** à l'écran, un refus net en interne | Zéro n'est pas un plafond d'offre |
| **Identité hors connexion** | `BIGINT AUTO_INCREMENT` + `client_uuid` UNIQUE | Index compacts, idempotence à la synchro |
| **Hébergement cible** | cPanel mutualisé | Pas de Composer, **pas de bibliothèque PDF**, exports en CSV |
| **Framework** | PHP procédural structuré | Séparation controllers/services/repositories |
| **Frontend** | Bootstrap 5.3 servi **localement** | CDN incompatible avec la CSP stricte et le hors connexion |
| **CSP** | `script-src 'self' 'nonce-…'` | Les `onclick`/`onchange` en attribut sont **inertes** |
| **URL générées** | **Relatives à la racine** | Déploiement insensible au port, à l'hôte, au sous-dossier |

---

## Le principe du figeage — appliqué huit fois

Une donnée qui sert de **preuve** ne se recalcule pas : elle se fige au
moment où elle engage l'établissement.

| Phase | Ce qui se fige | Au moment de | Pourquoi |
|---|---|---|---|
| 3 | Libellés d'orientation | la décision | Renommer une option ne doit pas réécrire l'histoire d'un élève |
| 4B | `grades.max_points` | la saisie | Corriger un barème ne doit pas changer des cotes déjà posées |
| 4C | Rang, totaux, décision | la publication | Une cote ajoutée reclasserait des bulletins déjà remis |
| 4D | Le registre d'appel | le verrouillage | Un registre remis à la direction ne se corrige plus qu'en direction |
| 5A | `student_fees.amount_due` et le libellé | l'affectation | Relever le minerval en janvier ne réécrit pas septembre |
| 5B | Somme remise, **taux**, somme créditée | l'encaissement | Un reçu doit rester vérifiable sans connaître le cours du jour |
| 5D | L'exercice entier | la clôture | Des chiffres remis au promoteur ne se réécrivent plus |
| 6A | Le **détail** d'un bulletin : cote, libellé de branche, domaine, ordre, maximum | la publication | Le bulletin rouvert par la famille doit être celui qu'elle a reçu, à la ligne près |

**Corollaire appris en 5A** : tout gel a besoin d'une échappatoire, mais elle
doit être explicite, motivée, tracée, et **annoncer sa conséquence avant de la
produire**.

---

## Isolation multi-établissements — le point critique

1. **Helpers `tenant_*`** qui injectent `school_id` automatiquement.
2. **Garde-fou** dans `db_query()` : toute requête touchant une table de
   `TENANT_TABLES` sans mentionner `school_id` lève une exception en
   développement et est journalisée en `ERROR` en production.
3. **Contexte rechargé en base à chaque requête** par `auth_user()`.

> **Règle absolue** : toute nouvelle table métier doit être ajoutée à
> `TENANT_TABLES` dans `app/core/tenant.php`.

⚠️ `users` **est** une table tenant (corrigé le 20/09/2026 — ce document
affirmait l'inverse). Toute requête qui la lit doit lier `school_id`, sauf sur
le chemin d'identité, qui passe par `tenant_scope_identity()`.

### Le garde-fou exige une LIAISON, pas une mention

Depuis le 20/09/2026, `school_id` doit occuper une position liante :

| Position | Exemple |
|---|---|
| Prédicat | `s.school_id = :x`, `school_id IN (…)`, `school_id IS NOT NULL` |
| Jointure | `USING (school_id)` |
| Affectation | `INSERT INTO t (school_id, …)`, `SET school_id = …` |

Un placeholder (`:school_id`) ne lie rien. Commentaires et littéraux sont
retirés avant analyse.

### Les trois périmètres — `app/core/platform.php`

| Périmètre | Pour quoi | Ce qu'il exige |
|---|---|---|
| `platform_scope($perm, $work)` | console de l'éditeur | compte **plateforme** (`users.school_id IS NULL`) **ET** la permission |
| `tenant_scope_identity($work)` | connexion, unicité d'un identifiant | rien — l'école se découvre depuis l'utilisateur |
| `platform_scope_cli($work)` | migrations, installateur, suites | `PHP_SAPI === 'cli'`, sinon lève |

Tous se referment par `finally`, exception comprise, et s'imbriquent par
compteur. Voir `securite-perimetre-plateforme.md`.

---

## Base de données

- **Runner unique** `database/runner.php`. Ordre imposé :
  **schema.sql → seeds → migrations**.
- `schema_migrations` avec empreintes SHA-256, `--status`, `--seed`,
  `--baseline`.
- **27 migrations** appliquées. Tables : **57**.
- Référentiel RDC complet (4 cycles, 15 niveaux, MAT_1 → HUM_4), 5 domaines
  officiels, 6 sous-domaines attestés, 14 postes de dépense.
- **9 rôles**, ~75 permissions, hiérarchie par `roles.level`.
- **4 offres** au catalogue : Découverte 150/15, Essentiel 600/50,
  Professionnel 2 000/200, Réseau illimité.

> **Le runner ouvre lui-même une transaction pour toute migration SANS DDL.**
> Une migration purement DML ne doit donc PAS écrire son propre
> `START TRANSACTION` / `COMMIT`.

---

## Bugs trouvés et corrigés — ils peuvent se reproduire

1. **Paramètre nommé réutilisé** — rejeté avec `ATTR_EMULATE_PREPARES = false`
   (`HY093`). **Survenu cinq fois**.
2. **Colonne générée refusée par MariaDB** (erreur 1901). Remplacée par
   `is_current` **nullable** + `UNIQUE`. **Ne jamais écrire `0`, toujours `NULL`.**
3. **Champ désactivé non soumis** — cocher « Absent » retirait le champ du POST.
4. **Colonne inventée** — toujours `DESCRIBE` avant d'écrire.
5. **Signature de fonction inventée** — lire la signature avant d'appeler.
6. **Gestionnaire d'événement en attribut** — inerte sous la CSP.
7. **`fputcsv()` sous PHP 8.4** — exige son paramètre d'échappement.
8. **Double chemin de cascade** — faisait échouer `DELETE FROM schools`.
9. **Interblocage `INSERT IGNORE` + `SELECT … FOR UPDATE`** — préparer la ligne
   **hors transaction**, et rejouer sur interblocage.
10. **Caches statiques figés à vide** — paramètre `$refresh` obligatoire.
11. **Boucle de réécriture `.htaccess`** — la garde doit être dans le motif.
12. **Ordre des opérations dans une migration de renommage** :
    remapper → supprimer → renommer.
13. **Erreurs CLI rendues en page HTML** — `errors_render_cli()`.
14. **`git stash` + `git am` destructeur** — déployer les fichiers complets.
15. **Précision de devise appliquée à l'affichage seulement** — tout montant
    passe désormais par `finance_round($amount, $currency)`.
16. **Statut d'exercice jamais lu** — `academic_years.status` existait depuis la
    phase 1 ; aucun module d'argent ne le consultait.
17. **Années de test irréalistes** — les dates de test doivent être **relatives
    à la date réelle**, sinon aucun contrôle temporel n'est testable.
18. **Cache statique non clé** — `portal_has_children()` répondait la même chose
    à tout le monde. Clé par utilisateur ET par école.
19. **Fonction d'un autre module non chargée** — le routeur ne charge que le
    module de la route. Un gabarit partagé (`bulletins/published.php` utilise
    `grades_format()`) doit déclarer ses dépendances dans CHAQUE contrôleur qui
    le rend, et une vue appelée depuis la barre latérale dans la barre latérale
    elle-même.
20. **Table inventée** — `attendance_entries` / `attendance_registers` n'existent
    pas ; les vraies sont `attendance_records` et `attendance_sessions`.
21. **Colonne et énumération inventées** — `school_settings` n'a pas de colonne
    `description`, et son `setting_type` vaut `'bool'`, jamais `'boolean'`.
22. **Un invariant posé par migration ne vaut que pour l'existant** — la
    migration 026 a donné un abonnement à chaque école présente ; une école née
    ensuite n'en aurait pas eu. Le rattrapage vit dans le chemin d'**écriture**
    (`subscription_ensure()`, appelé par le contrôle de quota), jamais dans une
    lecture : une lecture ne doit pas écrire.
23. **Une règle nouvelle casse les suites qui fabriquent des objets à la main** —
    dix suites ont échoué d'un coup en ajoutant la limite d'abonnement, parce
    qu'elles créent des écoles hors de tout parcours normal.
24. **Un contrôleur qui compose son écran avec deux fonctions de lecture** —
    `subscription_any()` pour la carte, `subscription_current()` pour les jauges.
    Un seul jeu de données réel les a fait désigner deux abonnements différents.
    Un écran se compose d'**une** source.
25. **Un « ordre par date » choisit silencieusement le mauvais enregistrement** —
    `ORDER BY ends_on DESC` rendait l'abonnement **résilié**, dont la date payée
    courait plus loin que celle de l'offre active. Ordonner par une date n'est
    pas filtrer par un statut.
26. **Un contrôle par mot-clé sur du SQL se contourne par la forme** —
    `preg_match('/\bschool_id\b/')` était satisfait par la colonne dans un
    SELECT, un commentaire, une chaîne. Analyser du SQL demande au minimum d'en
    retirer commentaires et littéraux, et de vérifier la POSITION du jeton.
27. **Une règle métier terminée par `abort()` n'est pas testable** —
    `platform_require()` imprimait une page 403 et arrêtait le processus ; la
    décision n'était observable qu'au navigateur. Séparer la DÉCISION
    (`platform_refusal()`, qui rend un motif ou `null`) de son RENDU.

---

## Prochaine étape — phase 7B : facturation SaaS et console éditeur

La 7A est livrée **et auditée**. Voir `phase-7a-abonnements.md` et
`audit-phase-7a.md`.

Le **préalable de sécurité est posé** (20/09/2026) : le garde-fou distingue
désormais une lecture transversale légitime d'une fuite, et `platform_scope()`
est la seule porte. Voir `securite-perimetre-plateforme.md`. La console peut
être écrite sans que chaque requête soit un pari.

Ce que la 7B demandera, relevé avant de coder :

- **Une console éditeur** (`/plateforme/…`) : la permission
  `platform.subscription.manage` existe déjà et la barre latérale la teste, mais
  **aucune route ne la sert**. Aujourd'hui, changer l'offre d'une école se fait
  en base. Cette console doit être la **seule** à créer un abonnement : c'est
  elle qui garantira qu'une école n'en a jamais deux « en cours » (voir la dette).
- **`subscription_payments` est créée et inutilisée** : c'est la table de la
  facturation SaaS.
- **Aucune notification d'échéance** — et rien n'envoie d'e-mail dans le produit
  (voir les questions ouvertes ci-dessous). Un abonnement qui expire sans
  prévenir est une résiliation subie.
- **`max_storage_mb` n'est mesuré nulle part** : le catalogue l'affiche, aucun
  code ne l'évalue.

La **7C (Mobile Money)** est bloquée tant que les identifiants du prestataire ne
sont pas fournis.

---

## Questions ouvertes — décisions attendues du développeur

1. **Aucun envoi d'e-mail n'est câblé.** Le lien de réinitialisation ne
   s'affiche qu'en mode debug. En production, « mot de passe oublié » ne
   fonctionne **pour personne**, personnel compris. À trancher : SMTP cPanel ou
   service tiers. **À régler avant la mise en production.**
2. **Il n'existe pas de module Utilisateurs** (lister, désactiver, réattribuer
   un rôle). Aucun écran ne crée de compte du personnel : ils naissent en base.
   Cela mérite sa propre phase — avant ou après la 7B, au choix.
3. **`max_users` ne compte que le personnel** — décision commerciale prise par
   défaut, à confirmer ou à renverser.
4. **`fee.manage` permet encore au comptable de fixer les tarifs.**
5. **`student.view` reste accordé à PARENT** — décision de la phase 3, devenue
   discutable depuis que le portail existe.

---

## Dette connue

- **L'effacement d'une école est IMPOSSIBLE en l'état** — correction d'une
  affirmation fausse portée par ce document jusqu'au 14/09/2026.
  `DELETE FROM schools` échoue dès qu'une cote existe :
  `grades.curriculum_subject_id` → `curriculum_subjects` est en **RESTRICT**, et
  les deux tables sont filles de `schools`, l'ordre de cascade n'étant pas
  garanti. Vérifié par exécution : une école vide s'efface, une école avec des
  cotes non. Les suites de tests font déjà le ménage table par table, ce qui
  masquait le défaut.
  **Ne PAS corriger en passant ces clés en CASCADE** : le RESTRICT protège aussi
  contre la suppression d'une branche de programme qui porte des cotes. Il faut
  une procédure d'effacement **ordonnée**, avec export préalable et confirmation
  explicite — sa propre étape, avant la mise en production.
- **Rien n'interdit deux abonnements « en cours » simultanés.** MySQL ne sait pas
  exprimer un `UNIQUE` conditionnel sur `status IN ('trial','active','past_due')`.
  `subscription_current()` prend le plus lointain : un arbitrage raisonnable, pas
  une garantie. À traiter par la console éditeur de la 7B.
- **Le plafond d'élèves n'est pas verrouillé transactionnellement.** Le contrôle
  est une lecture suivie d'une écriture. Vérifié par exécution : deux guichets
  simultanés à une place de la limite ne la franchissent pas — mais grâce au
  `SELECT … FOR UPDATE` du compteur de matricules, pas par conception. Un
  dépassement d'une ou deux unités resterait commercialement anodin ; consigné
  pour ne pas être redécouvert.
- **Pas de change de monnaie.** Une conversion CDF → USD au guichet produit un
  solde négatif dans une devise, expliqué à l'écran mais non modélisé.
- **Trois tables de compteurs de forme identique**. À consolider si une
  quatrième séquence apparaît.
- **Pas de flux de remboursement** : les montants sont planchés.
- **Le jeton à usage unique protège l'écran, pas le service** : un import ou une
  API mobile pourraient encore créer un doublon.
- **Pas d'écran de clôture d'exercice** : le verrou refuse déjà toute écriture
  sur une année `closed`, mais la manœuvre qui la clôture arrive en phase 10.
  Conséquence : les années passées restent `active`. Sans effet sur les
  abonnements, qui ne comptent que l'année courante et les années à venir.
- **Marges de 180 / 90 jours** autour d'un exercice : un choix, pas un réglage
  d'établissement.
- **`fee.manage` permet encore au comptable de fixer les tarifs.** Décider du
  minerval est une décision de gouvernance ; laissé tel quel parce que la grille
  est visible de tous, donc qu'un abus se remarque.
- **Aucun plafond de remise** : la direction peut annuler 100 % d'une dette d'un
  clic. Un seuil exigeant un second accord relève d'un circuit de validation —
  phase 9.
- **Le compte de connexion d'un tuteur ou d'un élève survit à l'archivage de sa
  fiche.** Il n'ouvre plus rien — le portail est vide — mais reste un
  identifiant valide.
- **L'accueil du portail est un N+1** : 6 enfants coûtent 31 requêtes.
  Acceptable pour une famille, à revoir si un tuteur institutionnel (orphelinat,
  internat) utilise un jour le portail.
- **Les bulletins publiés avant la migration 023** n'ont pas de détail figé :
  l'écran le dit et invite à republier la classe.
- **La limite de comptes du personnel n'est appliquée nulle part** :
  `subscription_can_add_staff_user()` n'a aucune porte qui l'appelle, faute de
  module Utilisateurs. Sans fuite commerciale aujourd'hui — rien ne crée de
  compte du personnel — mais la règle dort. Elle est verrouillée par un test,
  prête pour le jour où cette porte existera.
- **`max_storage_mb` n'est mesuré nulle part.**
- **Le changement d'offre passe par la base** : pas de console éditeur avant la
  7B.
- **`tenant_extract_tables()` ne comprend ni les sous-requêtes imbriquées ni les
  CTE.** Une table multi-école citée dans un `WITH` échapperait à l'extraction.
  Non exploité — aucune CTE dans le dépôt — mais à vérifier avant d'en écrire une.
- **En production, le garde-fou journalise sans bloquer.** Choix d'origine, pour
  ne pas renvoyer l'utilisateur sur une page d'erreur. À rediscuter avant la mise
  en service : une fuite tracée reste une fuite.
- Pas de budget prévisionnel par poste de dépense.
- `external_status` stocké mais non piloté — prévu pour Mobile Money en 7C.
- `reference_level_subjects` : le remplissage automatique ajoute latin et grec à
  un programme primaire.
- `bulletins.comment` : colonne créée, saisie non exposée.
- Bloc **RÉSULTAT FINAL / ENAFEP** du bulletin de 6e primaire : attesté, non
  traité.
- `grade_periods.starts_on` / `ends_on` jamais remplis : bloque les statistiques
  de présence par période.
- Dépôt distant `QcodeKusakana/school-saas` non autorisé dans cette session :
  les commits sont locaux, `git push` refusé par le proxy (403). Un bundle Git
  complet a été remis au développeur.

---

## Avant mise en production (phase 10)

- [ ] `app.debug` à `false`
- [ ] `session.cookie_secure` à `true` (HTTPS)
- [ ] Racine web sur `public/`
- [ ] **Câbler l'envoi d'e-mails** — sans lui, « mot de passe oublié » ne
      fonctionne pour personne
- [ ] **Supprimer `public/diagnostic.php`**
- [ ] **Supprimer `database/seed_demo.php`**
- [ ] `config.local.php` hors dépôt Git
- [ ] `app.url` renseigné avec l'URL publique réelle
- [ ] Utilisateur MySQL dédié, jamais `root`
- [ ] Sauvegarde automatique de la base
- [ ] **Écrire la procédure d'effacement d'une école** (RGPD) — aujourd'hui
      impossible, voir la dette ci-dessus
