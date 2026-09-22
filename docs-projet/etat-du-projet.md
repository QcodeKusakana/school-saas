# État du projet — School SaaS RDC

**Emplacement** : `C:\laragon\www\school-saas` (dépôt Git déjà initialisé)
**Version** : 0.10.0 — phases 1 à 6 terminées et auditées · 7A, 7B1, 7B2, 7D, 8A, 8B1 et **9A livrées et auditées**
**Dernière mise à jour** : 21/09/2026
**Tests** : 998 verts sur base **reconstruite depuis zéro** en MySQL 8 (19 suites), plus 100 vérifications en navigateur réel et une recette d'installation

**Environnement local constaté** : Laragon 6.0, Apache 2.4.54 **sur le port 8000** (pas 80), PHP 8.4.16 en FastCGI (mod_fcgid), MySQL 8.0.30.

> ⚠️ **Le SQL doit être portable MySQL 8.** Tout ce qui est écrit ici est
> éprouvé sur MySQL 8, moteur du poste de développement comme de l'hébergement
> cible. Ne pas s'appuyer sur les tolérances de MariaDB.
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
| 7 | **Abonnements & comptes** | 7A ✅ + audit · 7B1 ✅ + audit · 7B2 ✅ + audit · 7C Mobile Money ⛔ · **7D Utilisateurs ✅ + audit** | 🔵 en cours |
| 8 | **Messagerie & hors connexion** | **8A Messagerie ✅ + audit** · **8B1 Hors connexion ✅ + audit** (PWA, Service Worker, IndexedDB, appel des présences) · 8B2 étendre aux autres écrans | 🔵 en cours |
| 9 | **Modules avancés** | **9A Documents officiels + QR de vérification ✅** · 9B rapports, archives, communication | 🔵 en cours |
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
`audit-phase-7a.md`, `securite-perimetre-plateforme.md`,
`phase-7b-console-editeur.md`, `audit-phase-7b1.md`, `phase-7b2-facturation.md`,
`audit-phase-7b2.md`, `phase-7d-utilisateurs.md`, `audit-phase-7d.md`,
`phase-8a-messagerie.md`, `audit-phase-8a.md`.

---

## Comment tester — procédure de recette

### 0. Vérifier que le produit s'installe DEPUIS ZÉRO

Avant toute livraison, sur un poste de développement :

```
bash tests/installation_zero.sh
```

Le script **détruit et reconstruit** la base configurée, rejoue la
commande documentée telle quelle, puis vérifie que le référentiel est en
place et qu'une école neuve peut être créée.

> Une suite de tests ne peut pas attraper une installation cassée : elle
> s'exécute sur la base déjà construite. Le 21/09/2026, le produit passait
> 921 tests alors qu'**aucune école neuve n'aurait pu être installée** —
> `migrate.php` sautait les seeds, et la migration 001b échouait sur une
> erreur qui ne nommait pas la cause.
>
> **Un produit qui ne s'installe pas depuis zéro n'a jamais été installé,
> il a seulement été migré.**

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

> **Les migrations 028 et 029 sont REJOUABLES** (corrigées le 21/09/2026).
> Chaque `ALTER` est gardé par `information_schema` via
> `SET @ddl / PREPARE / EXECUTE` — le seul motif portable MySQL 8 **et**
> MariaDB, aucun des deux n'offrant `ADD COLUMN IF NOT EXISTS` de façon
> portable. Une base arrêtée à mi-chemin se répare en relançant simplement
> `php database\migrate.php`. Aucune donnée n'est supprimée ni modifiée.

> **La migration 028 laisse `subscriptions.price_amount` à NULL sur les
> abonnements existants** : leur tarif n'a jamais été figé, et l'inventer serait
> un mensonge. Les écrans affichent « non figé », le service **refuse d'y
> rattacher un versement**, et ils n'entrent dans aucun solde. Pour les remettre
> en facturation, appliquer une offre depuis `/plateforme/ecoles/{id}` — le tarif
> se fige alors sur la nouvelle période.

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
php tests\platform_console.php
php tests\platform_billing.php
php tests\users_isolation.php
php tests\mail_isolation.php
php tests\sync_isolation.php
php tests\documents_isolation.php
php tests\qrcode_decode.php
```

Chaque suite se nettoie derrière elle (bloc `finally`) et sort en code 0 si
tout passe. Total attendu : **998 tests verts**.

#### Les vérifications qui exigent un vrai navigateur

Certaines promesses ne se prouvent pas en PHP : un service worker, une
file IndexedDB, une coupure réseau, une course entre deux requêtes. Avec
le serveur de développement lancé **en multi-processus** :

```
set PHP_CLI_SERVER_WORKERS=6
php -S 127.0.0.1:8099 -t public

node tests\offline_browser.js  <motdepasse> 1   REM 13 attendus
node tests\ecran_sync.js       <motdepasse>     REM 12 attendus
node tests\documents_browser.js <motdepasse>    REM 25 attendus
node tests\sonde_cache_sw.js   <motdepasse>     REM 0 entrée hors coquille
bash tests/sonde_sync_course.sh <motdepasse>     REM aucune requête ne rompt
```

> `PHP_CLI_SERVER_WORKERS` n'est pas une commodité : sans lui, `php -S`
> sert une requête à la fois et **toute sonde de concurrence rend vert
> sans rien prouver**. La sonde de course refuse d'ailleurs de démarrer
> sur un serveur mono-processus.

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
| `/plateforme/ecoles` en super-administrateur | Le parc entier, avec offre et effectif |
| Le même écran en direction | **403** |
| « Ouvrir » sur une école | Un **bandeau rouge** apparaît sur toutes les pages |
| Naviguer dans l'école, puis « Quitter » | Le bandeau disparaît, `/eleves` redevient inaccessible |
| Appliquer une offre depuis la fiche | La précédente passe en `cancelled`, la nouvelle en `active` |
| L'historique de la fiche | Les **deux** lignes y figurent |
| Suspendre sans motif | Refusé |
| `/plateforme/abonnements` | Uniquement ce qui expire, dépasse, ou manque d'offre |
| Archiver l'école visitée (`deleted_at`) en base | Le bandeau **rougit** et dit « archivé » — il ne disparaît pas |
| Ouvrir une 2ᵉ école sans quitter la 1ʳᵉ | Le journal porte **une sortie** pour la première |
| `/abonnement` sur une école sans aucune offre | **Aucun** abonnement n'est créé par la consultation |
| `/plateforme/soldes` en super-administrateur | Deux listes : ce qui est dû, **et les trop-perçus** |
| Le même écran en direction | **403** |
| Appliquer une offre avec un tarif négocié de 200 USD | Le tarif est **figé sur l'abonnement**, pas relu du catalogue |
| Relever le tarif de l'offre au catalogue ensuite | Les dettes déjà engagées **ne bougent pas** |
| Encaisser 560 000 CDF au taux 2 800 sur une dette en USD | **200,00 USD crédités**, écran « Soldé », taux figé |
| Le même versement **sans** indiquer de taux | Refusé : « exige le taux de change du jour » |
| Saisir deux fois la même référence chez le même prestataire | Le second envoi est **refusé** |
| Monter en gamme en cours d'année | La période remplacée est facturée **au prorata**, pas deux fois |
| Annuler un versement sans motif | Refusé — et la ligne n'est **jamais** supprimée |
| Encaisser sur un abonnement sans tarif figé | Refusé : on n'encaisse pas sur une dette inconnue |
| Ressaisir une référence **vivante** | Refusé, et le message dit **comment corriger** |
| Annuler cette ligne, puis ressaisir la **même** référence | **Accepté** — la ligne annulée reste affichée avec son motif |
| Archiver en base une école qui doit | Elle **reste** dans `/plateforme/soldes`, badge « archivée » |
| `/utilisateurs` en secrétariat | **403** — et l'entrée de menu est absente |
| La direction ouvre la fiche d'un administrateur d'école | « Hors de votre portée », aucun formulaire |
| Elle poste quand même `roles[]=SCHOOL_ADMIN` | Refus nommant les **deux niveaux** ; rôles inchangés en base |
| Elle poste la régénération de SON mot de passe | Refus ; le **condensat n'a pas bougé** |
| Créer un compte | Identifiant et mot de passe affichés **une seule fois** |
| Se connecter avec ce compte | **Toute** route mène à `/mot-de-passe/changer` |
| Créer un homonyme | L'identifiant est **suffixé**, jamais dupliqué |
| Désactiver le dernier compte capable de créer | Refusé, même pour l'éditeur |
| Se désactiver soi-même | Refusé |
| Décocher un rôle **non attribuable** | Il est **conservé**, et le message le dit |
| Archiver un compte | La ligne reste en base ; la place se libère dans l'abonnement |
| `/ecole/emails` en direction | **403** — `email.manage` n'est pas `school.edit` |
| Enregistrer une configuration SMTP | Elle naît **inactive** ; le mot de passe est **chiffré** en base |
| Envoyer avant l'essai | Rien ne part, et le motif est nommé |
| Lancer l'essai | Il **active** la configuration — seul chemin possible |
| Réenregistrer en laissant le mot de passe vide | Il est **conservé** |
| Mot de passe oublié, compte avec adresse | Message reçu, **lien ouvrant**, changement, connexion |
| Le même, compte inconnu | **Message identique** — aucune énumération possible |
| Le lien, rouvert après usage | Refusé |
| Couper le serveur SMTP | Message **écrit** quand même, rejeu à 1, 5, 15, 60 min, puis abandon |
| Un message sensible abandonné | Son corps a **disparu** ; le rejeu est refusé et dit quoi faire |
| **Deux passages du travail périodique en même temps** | **Un seul envoi** — à rejouer à deux processus contre un vrai serveur SMTP |
| Adresse d'expédition d'un autre domaine que le serveur | **Averti**, pas refusé : un relais légitime existe |
| Un compte cumulant secrétariat **et** administration | Hors de portée d'une direction : c'est le rôle le PLUS HAUT qui compte |
| Le refus hiérarchique | Nomme le recours : l'éditeur de la plateforme |
| **Deux désactivations simultanées** du dernier carré d'administrateurs | **Une seule passe** — à rejouer à deux processus, comme l'interblocage 5B |

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
| **Terrain (21/09)** | La migration 028 s'est **arrêtée à mi-chemin** sur la base du développeur, laissant `price_amount` en place sans s'enregistrer : le passage suivant butait sur « Duplicate column name ». Deux causes derrière une : une migration non idempotente, et un moteur de développement (MariaDB) différent du moteur réel (MySQL 8). Les deux sont corrigées |
| **Audit 8A** | **Le même message partait deux fois.** Deux passages du travail périodique lancés à la même seconde lisaient tous deux la ligne « en attente » et l'envoyaient tous deux — le serveur de test a bien reçu deux exemplaires. Une tâche cron qui déborde sur la suivante suffit. Corrigé par une réservation atomique (`rowCount()` désigne le gagnant). Et un expéditeur d'un autre domaine que le serveur passait **en silence**, alors que le SPF ne le couvrira pas |
| **Phase 8A** | Première version de `mail_attempt()` : lecture `WHERE id = :id` sans filtre d'école. **Le garde-fou l'a refusée**, à juste titre — un identifiant deviné aurait suffi à lire le corps d'un message d'une autre école, et ces corps portent des liens de réinitialisation. La fonction prend désormais la LIGNE, pas un identifiant |
| **Audit 7D** | Le contrôle « il reste un compte capable de créer » était une **lecture suivie d'une écriture**. Deux désactivations simultanées, lancées par l'éditeur sur deux directions, passaient toutes les deux : **zéro porte ouverte, école enfermée dehors**. Mesuré à deux processus. Le verrou devait porter sur TOUS les candidats, cible comprise — l'exclure donnait deux ensembles disjoints qui ne s'attendaient jamais |
| **Phase 7D** | `roles.level` promettait depuis la phase 1 qu'« un rôle ne peut gérer qu'un rôle de niveau strictement inférieur ». **Aucune ligne ne l'appliquait** — faute d'écran qui attribue un rôle. Le module est cet écran, et il porte enfin la règle, y compris contre le contournement en deux temps : régénérer le mot de passe d'un supérieur pour prendre sa place |
| **Audit 7B2** | Une **référence annulée restait consommée à jamais** : après une erreur de frappe corrigée par annulation, le vrai versement Mobile Money — qui porte exactement une référence — devenait inenregistrable. Et **archiver une école effaçait sa dette** de `/plateforme/soldes` : le défaut du bandeau de la 7B1, transposé à l'argent |
| **Audit 7B1** | Le bandeau **disparaissait sous l'éditeur** quand l'école était archivée pendant la visite : contexte maintenu, signal éteint. Changer d'école laissait **deux entrées et aucune sortie** au journal. Et ouvrir l'écran d'abonnement **démarrait un essai de 30 jours** — les jours couraient depuis la visite de l'éditeur, pas depuis la première utilisation de l'école |
| **Phase 7B1** | La visite d'une école ne tenait **pas une requête** : posée en session, `auth_user()` la remplaçait par NULL au rechargement — le bandeau annonçait l'école, `tenant_id()` valait NULL. Et le bandeau lui-même **ne s'affichait pas** hors du module plateforme : le seul signal disant « vous êtes chez un client » manquait sur les écrans du client |
| **Audit 7A** | L'écran lisait **deux abonnements** : la carte annonçait « Réseau — résilié — 300 jours » à une école payant une offre Essentiel active. La jauge comptait l'année **courante** quand la limite compte l'année **visée** : « 2 places libres » là où l'inscription refusait. Et l'absence d'abonnement s'affichait comme un **plafond de zéro élève** |

### Règles tirées de ces audits

> **Un code qu'on n'a pas relu avec un autre outil que le sien n'est pas
> un code vérifié, c'est un dessin.** Vaut pour tout format binaire
> qu'un humain ne sait pas lire à l'œil.

> **Un code présent n'est pas un code lisible ; seule la taille imprimée
> en décide.** Mesuré à 300 ppp : 0,32 mm par module passait chez zbar,
> pas chez OpenCV. Il faut rendre à la résolution d'impression et relire.

> **Une image ne devrait pas deviner la place qu'on lui laisse ; c'est la
> page qui la lui donne.** Un `max-width: 100%` dans le SVG lui-même a
> rendu huit codes illisibles — un pourcentage sans parent ne vaut rien.

> **Un vecteur qui ne regarde que la matrice ne voit pas le rendu.**

> **Un coefficient deviné n'est pas un calcul, c'est un vœu.** Mesurer
> coûte une minute.

> **Une mesure fausse accuse un produit sain.**

> **Un gabarit qui impose sa forme à tout ce qu'il enveloppe finit par
> déformer ce qu'il devait servir.**

> **Une variable de gabarit qui porte le nom d'une variable du moteur ne
> vaut rien, et ne prévient pas.** `view_capture()` fait
> `extract($data, EXTR_SKIP)` : une clé `data` n'écrase pas la locale du
> noyau. Des documents se sont imprimés entièrement vides, sans erreur.

> **Une vérification qui ne regarde que le gabarit ne dit rien sur les
> données.** Chercher une phrase du modèle passe même quand tous les
> champs sont vides.

> **Un message qui demande une action que le produit ne permet pas est
> une impasse.** Pire qu'une fonctionnalité manquante : il envoie
> l'utilisateur chercher ce qui n'existe pas.

> **Un chemin qu'on n'a jamais rendu n'est pas un chemin, c'est une
> supposition.**

> **Une sonde qui dépend de l'état laissé par une autre n'est pas
> indépendante** — et celle qui bascule un état au lieu de l'exiger
> dépend de l'ordre dans lequel on la joue.

> **Un aperçu qui ne se distingue pas de l'original n'est pas un aperçu,
> c'est un blanc-seing.**

> **Un vecteur figé ne prouve pas qu'un code se scanne ; il prouve qu'il
> n'a pas changé depuis le jour où on l'a prouvé.** C'est ce qui permet
> à une épreuve d'outil externe de rester jouable sur un poste nu.

> **Un contrôle qu'on n'a pas pu jouer n'est ni un succès ni un
> échec — et la sortie doit le NOMMER.** Une suite qui rougit faute
> d'outil installé confond « le code est faux » avec « l'outil manque
> ici » ; une suite qui verdit en silence ne prouve rien.

> **Un seul décodeur ne suffit pas : son échec peut être le sien.**
> Mesuré : OpenCV a refusé un QR valide, et aussi celui produit par la
> bibliothèque de référence. Avec un seul outil, ce refus aurait fait
> « corriger » un encodeur correct jusqu'à le casser.

> **Un polynôme symétrique sur le petit cas ne prouve rien sur le
> grand.** Un test unitaire sur n = 1 validait un Reed-Solomon construit
> à l'envers.

> **Un document qui change après avoir été signé n'est pas un document,
> c'est un affichage.**

> **Sans révocation, « valide » est une promesse qu'on ne peut plus
> reprendre.**

> **Une vérification qui ne dit pas ce qu'elle ne vérifie pas donne une
> confiance qu'elle n'a pas gagnée.**

> **Un cache qui n'énumère pas ce qu'il garde garde tout** — et un
> cache-d'abord qui garde tout finit par répondre à la place du serveur.
> Une ressource entre en cache par APPARTENANCE déclarée, jamais parce
> qu'elle est arrivée par un GET.

> **L'unicité se démontre par l'index, pas par une lecture qui la
> précède.** Un `SELECT` avant l'`INSERT` écarte le cas ordinaire ; il ne
> sérialise rien.

> **Un état transitoire rendu comme un verdict fait mentir l'appelant sur
> ce que le serveur a fait.**

> **Un lot qui tombe entier pour une ligne** fait payer aux autres la
> faute d'une seule.

> **Une sonde qui ne coupe pas vraiment, ou qui ne concourt pas vraiment,
> ne prouve rien — et elle rend vert.** `setOffline` laisse passer la
> boucle locale ; `php -S` sérialise sans `PHP_CLI_SERVER_WORKERS`. La
> précondition s'inscrit DANS la sonde.

> **`navigator.onLine` annonce qu'une interface est active, pas que le
> serveur répond.** On met en file sur l'ÉCHEC RÉEL, jamais sur un drapeau.

> **Un second chemin d'écriture est un second jeu de règles**, et c'est
> toujours le plus permissif qui finit par être emprunté. La
> synchronisation REJOUE le service métier, elle ne réécrit pas les tables.

> **Un produit qui ne s'installe pas depuis zéro n'a jamais été installé,
> il a seulement été migré.** Une suite de tests s'exécute sur la base
> déjà construite : elle ne verra jamais une installation cassée.

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

> **Un invariant qu'aucune requête ne sait vérifier n'est pas un invariant,
> c'est une intention.** Si le schéma ne peut pas le porter, le service le
> tient — et une requête doit pouvoir le contredire.

> **Une visite qui peut se faire sans laisser de trace n'est pas une visite
> tracée.** L'état qui l'autorise appartient à la base, pas à la session.

> **Une vue de layout ne doit dépendre d'aucun module.** Le routeur ne charge
> que le module de la route ; ce qui se rend sur chaque page vit dans le noyau.

> **Un bandeau qui disparaît avant la visite qu'il annonce est pire que pas de
> bandeau** : il donne l'illusion d'être sorti.

> **Une trace qui note les entrées sans les sorties ne date rien.** « Jusqu'à
> quand avez-vous eu accès à nos données ? » est une question à laquelle un
> journal doit savoir répondre.

> **Lire une file ne réserve rien.** Tant que deux lecteurs peuvent repartir
> avec la même ligne, la file n'en est pas une. La réservation est l'UPDATE
> lui-même : `rowCount()` désigne le gagnant.

> **Une échéance repoussée vaut mieux qu'un état « en cours ».** L'état
> resterait collé si le travail était tué ; l'échéance expire d'elle-même et
> rien ne se coince.

> **Ce qu'on ne peut pas vérifier, on le dit.** Le produit ne sait pas si le
> SPF du domaine couvre l'expéditeur : il avertit au lieu de refuser, et au
> lieu de se taire.

> **Un envoi qui n'a pas été écrit avant d'être tenté est un envoi qu'on ne
> saura pas rejouer.** En RDC la coupure est la règle : écrire d'abord, tenter
> ensuite.

> **Une clé rangée à côté de ce qu'elle protège ne protège rien.** La clé de
> chiffrement vit dans `config.local.php`, hors du dépôt ET hors de la base.

> **Un secret qu'on VÉRIFIE se hache ; un secret qu'on PRÉSENTE se chiffre.**
> Un mot de passe d'utilisateur et un mot de passe de serveur SMTP n'ont pas
> le même traitement, et les confondre casse l'un ou l'autre.

> **Un produit qui « envoie » des messages que personne ne reçoit est pire
> qu'un produit qui n'en envoie pas** : l'école croit avoir prévenu les
> familles. D'où le refus de `mail()`, qui ne s'authentifie pas, et
> l'activation par essai réussi uniquement.

> **Un compteur qui protège d'un état final ne vaut que verrouillé.** La leçon
> de la 5B transposée : ce n'est pas l'argent qu'on protège ici, c'est la
> capacité de rouvrir la porte.

> **Un verrou qui exclut la cible ne sérialise rien.** Deux transactions qui
> verrouillent des ensembles disjoints ne s'attendent jamais. Le verrou porte
> sur TOUS les candidats, la cible comprise.

> **On ne retire pas un rôle qu'on ne pourrait pas redonner.** Un remplacement
> total efface ce qui n'est pas coché ; si l'acteur ne peut pas réattribuer ce
> rôle, l'opération est à sens unique — exactement ce que la hiérarchie interdit.

> **Une permission semée sans écran n'est pas une fonctionnalité en attente,
> c'est une porte qu'on croit fermée.** Les cinq `user.*` existaient depuis la
> phase 1 ; pendant tout ce temps, les comptes du personnel naissaient en base
> et aucun ne pouvait être fermé.

> **Un garde-fou contre le doublon qui interdit AUSSI la correction ne protège
> pas la comptabilité : il la force à mentir.** L'éditeur n'a plus alors que le
> choix entre inventer une fausse référence et ne rien enregistrer.

> **Archiver une école range son dossier ; cela n'éteint pas sa dette.** Un
> filtre `deleted_at IS NULL` sur un écran d'argent crée une incitation
> perverse : archiver le mauvais payeur fait disparaître ce qu'il doit.

> **On ne facture pas une période qu'on n'a pas servie, et on ne facture pas
> deux fois la même.** Clôturer puis rouvrir un abonnement est la manœuvre
> normale d'un changement d'offre : facturer chaque ligne à son tarif plein fait
> payer deux années à une école qui monte en gamme en janvier.

> **Un écran qui ne montre que ce qui nous est dû n'est pas une comptabilité,
> c'est un rappel de facture.** Un trop-perçu engage l'éditeur autant qu'une
> dette : il doit du service ou un remboursement.

> **Une migration qu'on ne peut pas rejouer après un échec partiel n'est pas une
> migration, c'est un piège** — et il se referme sur le serveur de production,
> celui où personne ne peut improviser. Le runner n'enregistre pas une migration
> interrompue, mais il ne défait pas non plus ce qu'elle a déjà écrit : le
> passage suivant retombe sur « Duplicate column » et la base reste bloquée.

> **Un moteur de développement qui n'est pas celui de production ne valide
> rien.** Toutes les migrations avaient été éprouvées sur MariaDB seul ; la 028
> s'est cassée chez le développeur, sur MySQL 8, dans un état qu'aucune base
> neuve ne reproduit. Le conteneur de développement tourne désormais sur
> **MySQL 8**, comme Laragon et comme cPanel.

> **Une consultation ne démarre pas une horloge commerciale.** L'audit 7A avait
> posé « une lecture ne doit pas écrire » et laissé passer cette violation :
> elle était sans conséquence tant que seule l'école ouvrait son propre écran.
> La console n'a pas créé le défaut, elle a rendu visible un défaut dormant.

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
| **Changement d'offre** | **Clôturer puis ouvrir**, sous verrou — jamais muter | L'historique répond à « depuis quand payons-nous ce tarif ? » |
| **Résiliation** | Par changement d'OFFRE, jamais par changement de statut | Sans abonnement en cours, l'école se verrait rouvrir un essai gratuit |
| **Visite de l'éditeur** | `users.visiting_school_id` — un état de la BASE | Une session bricolée ne doit pas permettre d'entrer sans trace |
| **Hiérarchie des rôles** | On n'attribue, et on ne touche, qu'un niveau **strictement inférieur** | Sinon une direction se fabrique un administrateur, puis se fait promouvoir |
| **Identifiant de connexion** | Construit sur le nom, suffixé, **jamais saisi ni modifiable** | `uq_users_username` est globale au produit ; un identifiant est une identité |
| **Mot de passe initial** | Tiré au sort, affiché **une seule fois**, jamais journalisé | Un mot de passe choisi par l'administrateur est un mot de passe qu'il connaît |
| **Suppression d'un compte** | **Jamais** — désactivation, suspension ou archivage | `audit_logs.user_id` pointe dessus : un journal sans auteur ne prouve rien |
| **Envoi d'e-mails** | Client SMTP **maison**, ni bibliothèque ni `mail()` | Pas de Composer sur cPanel ; `mail()` ne s'authentifie pas et finit en indésirables |
| **Serveur d'envoi** | **Par école**, en base ; celui de la plateforme dans `config.local.php` | Un message doit partir du domaine de l'école, sinon le SPF échoue |
| **Mot de passe SMTP** | **Chiffré** (sodium), clé hors base et hors dépôt | Il se relit en clair au moment de la connexion : le hachage est impossible |
| **Activation d'une configuration** | Uniquement par un **essai réussi** ; toute modification désactive | Une case « actif » laisserait l'école se croire joignable sans l'être |
| **Corps d'un message sensible** | **Chiffré** au repos, **effacé** à l'envoi comme à l'abandon | Le lien de réinitialisation EST le secret que `password_resets` protège |
| **Tarif d'un abonnement** | **Figé** sur `subscriptions.price_amount` à l'ouverture | Le catalogue est un modèle de départ, jamais la source d'une dette déjà engagée |
| **Tarif négocié** | L'emporte sur le catalogue ; **0 est une valeur** (école pilote, partenariat) | Distinguer « gratuit » de « non renseigné » évite de facturer un partenaire |
| **Abonnement sans tarif figé** | Affiché « non figé », **inencaissable**, hors solde | Inventer une dette est pire que l'avouer |
| **Période remplacée** | Facturée **au prorata** des jours servis ; clôturée avant son 1ᵉʳ jour = **zéro** | Une correction de saisie n'est pas une période vendue |
| **Facturation SaaS** | `platform.billing.manage`, distincte de `platform.subscription.manage` | Négocier une offre et constater qu'elle est payée sont deux pouvoirs différents |
| **Versement SaaS en attente** | Ne compte dans **aucun** solde | Tant que l'opérateur n'a pas confirmé, l'argent n'est pas arrivé |
| **Identité hors connexion** | `BIGINT AUTO_INCREMENT` + `client_uuid` UNIQUE | Index compacts, idempotence à la synchro |
| **Hébergement cible** | cPanel mutualisé | Pas de Composer, **pas de bibliothèque PDF**, exports en CSV |
| **Framework** | PHP procédural structuré | Séparation controllers/services/repositories |
| **Frontend** | Bootstrap 5.3 servi **localement** | CDN incompatible avec la CSP stricte et le hors connexion |
| **CSP** | `script-src 'self' 'nonce-…'` | Les `onclick`/`onchange` en attribut sont **inertes** |
| **URL générées** | **Relatives à la racine** | Déploiement insensible au port, à l'hôte, au sous-dossier |

---

## Le principe du figeage — appliqué dix fois

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
| 7B2 | `subscriptions.price_amount` / `price_currency` | l'ouverture de l'abonnement | Relever un tarif au catalogue ne réécrit pas ce qu'une école devait l'an dernier |
| 7B2 | `subscription_payments.exchange_rate` | le versement SaaS | Même raison qu'en 5B : une quittance doit rester vérifiable dix ans plus tard |

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
- **30 migrations** appliquées, dont les **028, 029 et 030 rejouables**. Tables : **59**.
- Suite complète vérifiée sur **MySQL 8.0** : 878 verts, 0 échec.
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
27. **Le routeur passe les paramètres d'URL UN PAR UN**, jamais en tableau :
    `$route['handler'](...$params)`. Un contrôleur déclarant `array $params`
    lève un TypeError — invisible aux tests, qui appellent les services.
28. **Une règle métier terminée par `abort()` n'est pas testable** —
    `platform_require()` imprimait une page 403 et arrêtait le processus ; la
    décision n'était observable qu'au navigateur. Séparer la DÉCISION
    (`platform_refusal()`, qui rend un motif ou `null`) de son RENDU.

---

## Prochaine étape

La **8B1 (hors connexion)** est livrée **et auditée**. Voir
`phase-8b1-hors-connexion.md`.

Ce qui a été tranché, et qui engage la suite :

1. **Un seul écran descend : l'appel des présences.** Il se fait debout
   devant une classe, il ne touche ni à l'argent ni à un document
   officiel, et il se corrige. Bulletins, caisse et inscriptions restent
   en ligne — la page de repli le dit franchement plutôt que de le
   laisser croire.
2. **L'arbitrage est humain, jamais automatique.** L'appareil envoie ce
   qu'il avait VU (`seen_updated_at`) ; si le serveur a changé depuis, un
   conflit s'ouvre et la direction tranche sur un écran qui montre les
   deux versions. **L'horloge de l'appareil n'arbitre rien.**
3. **Ce qui descend est réduit au strict nécessaire** : identifiant
   d'inscription, nom, matricule. Rien d'autre. IndexedDB n'est pas
   chiffré et l'appareil est souvent partagé — ce sont des mineurs.

**8B2**, si elle se fait, devra répondre aux mêmes trois questions pour
chaque nouveau type : quel service rejoue l'écriture, quel champ sert de
`seen_updated_at`, et qui arbitre. Rien n'oblige à l'ouvrir : l'appel
était le seul usage dont la coupure empêche vraiment de travailler.

La **7C (Mobile Money)** reste bloquée sur les identifiants du prestataire.

---

## Questions ouvertes — décisions attendues du développeur

1. ~~Aucun envoi d'e-mail n'est câblé.~~ **Réglé en 8A** : SMTP par école,
   file d'attente et rejeu. Reste à décider si l'éditeur propose aussi un
   service tiers (meilleure délivrabilité, coût récurrent) en alternative au
   SMTP cPanel ; la couche le permet sans toucher aux appels métier.
2. **Il n'existe pas de module Utilisateurs** (lister, désactiver, réattribuer
   un rôle). Aucun écran ne crée de compte du personnel : ils naissent en base.
   Cela mérite sa propre phase — avant ou après la 7B, au choix.
3. **`max_users` ne compte que le personnel** — décision commerciale prise par
   défaut, à confirmer ou à renverser.
4. **`fee.manage` permet encore au comptable de fixer les tarifs.**
5. **`student.view` reste accordé à PARENT** — décision de la phase 3, devenue
   discutable depuis que le portail existe.
6. **Quand l'éditeur ouvre une école cliente, il y a l'accès COMPLET** —
   il conserve toutes ses permissions. C'était déjà vrai avant la console ;
   celle-ci le rend simplement praticable, tracé et visible. Un accès en
   lecture seule serait défendable (l'éditeur n'a pas à corriger une cote),
   mais empêcherait le dépannage et demande de filtrer les permissions par
   contexte. **Les données concernées sont celles de mineurs : la question
   n'est pas seulement technique.**

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
  une garantie. **La migration 029 montre le motif qui le résoudra** : une colonne
  générée `STORED` qui ne porte la valeur que sur les lignes concernées, indexée
  en UNIQUE — plusieurs NULL cohabitent dans un index unique.
- **`ends_on` antérieur à `starts_on` est accepté par le schéma** et facturé au
  tarif plein. Aucun écran ne produit cette donnée ; une contrainte
  `CHECK (ends_on >= starts_on)` fermerait la question, à poser avec les autres
  invariants de schéma.
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
- ~~La limite de comptes du personnel n'est appliquée nulle part~~ —
  **réglé en 7D** : `users_service_create()` est la porte qui l'appelle.
- **Aucun écran de gestion des RÔLES eux-mêmes** : `role.manage` est semée sans
  écran, créer un rôle maison se fait encore en base. Les rôles système
  suffisent aujourd'hui.
- **Un compte du personnel n'est pas relié à sa fiche enseignant** : créer l'un
  ne crée pas l'autre, et les deux coexistent sans se connaître.
- **L'identifiant de connexion ne se change jamais**, même après une faute de
  frappe sur le nom. Volontaire, mais la faute reste visible pour toujours.
- **Le plafond de comptes du personnel n'est pas verrouillé** : deux créations
  simultanées à une place de la limite peuvent la franchir d'une unité. Même
  arbitrage que pour le plafond d'élèves — commercialement anodin,
  contrairement à l'enfermement, lui corrigé.
- **La dernière porte se mesure sur `user.create` seul.** Une école dont le
  dernier compte porterait `user.create` sans `user.edit` pourrait créer un
  compte sans pouvoir lui attribuer de rôle. Aucun rôle système n'est dans ce
  cas.
- **Deux administrateurs d'école ne peuvent rien l'un sur l'autre** (`level >=`
  refuse). Si l'unique administrateur part sans passer la main, seul l'éditeur
  peut fermer son compte. Le message de refus le dit désormais.
- **`max_storage_mb` n'est mesuré nulle part.**
- **Aucun rappel d'échéance d'abonnement n'est câblé** : la couche d'envoi
  existe depuis la 8A, le déclencheur non.
- **Les identifiants d'un nouveau compte ne partent pas par e-mail** : la
  remise en main propre reste la règle, à rebrancher maintenant que l'envoi
  existe.
- **Aucun suivi de rebond** : une adresse morte restera « envoyé ».
- **Au moins une fois, pas exactement une fois** : si le travail périodique est
  tué entre l'envoi effectif et l'écriture du résultat, le message repartira à
  l'expiration de la réservation. Un exactement-une-fois demanderait que le
  serveur destinataire dédoublonne sur `Message-ID`, ce sur quoi on ne peut pas
  compter.
- **Le lot de 25 n'est pas adapté à la durée réelle d'un envoi** : sur un
  serveur lent, un passage peut dépasser la minute du cron. La réservation
  empêche le doublon, pas l'empilement de travaux.
- **La rotation de la clé de chiffrement n'est pas outillée** : la changer rend
  illisibles les mots de passe SMTP enregistrés. Les écrans le disent et
  invitent à ressaisir, mais un script de rechiffrement manque.
- **Pas de purge de `email_messages`** : le journal grandit indéfiniment. À
  traiter avec les archives (phase 9).
- **Aucune quittance imprimable pour un versement SaaS.** La caisse scolaire
  émet des reçus numérotés ; la facturation de l'éditeur n'a ni document ni
  séquence. Une école qui paie son abonnement n'a pas de justificatif — à
  traiter avant la mise en service commerciale.
- **Aucun rappel d'échéance.** L'éditeur doit ouvrir `/plateforme/soldes` de
  lui-même. Dépend de l'envoi d'e-mails, toujours non câblé.
- **La proration se calcule sur `cancelled_at`**, qui est la date à laquelle
  l'offre a été remplacée. Une résiliation antidatée — l'école a cessé le
  service il y a un mois — n'est pas représentable : il faudrait une date de
  fin de service distincte de la date d'enregistrement.
- **Le statut `refunded` existe dans l'énumération et n'est jamais posé** : un
  remboursement se constate aujourd'hui par une annulation motivée.
- **Les abonnements antérieurs à la migration 028 n'ont pas de tarif figé** :
  ils sont signalés à l'écran et exclus des soldes. Appliquer une offre les
  remet en facturation.
- **`/plateforme/journal` n'existe pas** : `platform.audit.view` est semée, le
  lien est masqué par `route_exists()`. Phase 9.
- **Le catalogue d'offres se modifie en base** : `platform.plan.manage` n'a pas
  d'écran. Créer une offre est rare et engage tout le parc.
- **`platform.impersonate` et `platform.school.create` sont semées et sans
  écran** : créer une école se fait encore en base.
- **La liste du parc n'est pas paginée** : une requête, pas de N+1, mais à mille
  écoles la page sera longue.
- **`visiting_school_id` n'est pas effacée si `users.school_id` change.** Un
  compte d'école promu compte de plateforme atterrirait dans l'école restée
  dans cette colonne. Théorique — aucun écran ne modifie `school_id` — mais le
  futur module Utilisateurs devra l'effacer en même temps.
- **Rien ne borne la durée d'une visite** : la colonne survit à la déconnexion.
  Une expiration automatique, ou une sortie à la déconnexion, serait plus propre.
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
