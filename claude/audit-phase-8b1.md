# Audit de la phase 8B1 — le hors connexion

**Date** 21/09/2026 · **Méthode** audit par exécution
**Résultat** 6 défauts trouvés, 6 corrigés · 2 sondes fausses corrigées
**État final** 921 tests PHP verts (17 suites) + 36 vérifications en navigateur réel

---

## La méthode, et pourquoi elle seule

Un module se déclare sûr en le **faisant échouer** avec des données
réelles, pas en relisant son code. Trois classes de promesse ne se
vérifient que d'une façon :

| Promesse | Ce qu'il faut pour la vérifier |
|---|---|
| un service worker, une file IndexedDB, une coupure réseau | **un vrai navigateur** |
| une course entre deux écritures | **deux processus réels** |
| une isolation multi-écoles | **deux écoles peuplées** |

Chaque défaut ci-dessous a d'abord été **reproduit**, puis corrigé, puis
la reproduction a été rejouée, puis la règle a été verrouillée par un
test.

---

## Défaut 1 — le service worker mettait en cache les routes applicatives

**Gravité : haute.** Fuite de données d'élèves, et réponse fausse.

### Ce qui a été mesuré
`tests/sonde_cache_sw.js` énumère le contenu réel du cache après une
visite ordinaire. Résultat : **`/sync/etat`**, route JSON authentifiée,
s'y trouvait.

Le code interceptait **tout GET de même origine** et recopiait toute
réponse `ok` + `basic`. Donc : recherche d'élève, données de graphique,
listes paginées — tout y serait allé.

Pire que la fuite : la stratégie était **cache d'abord**
(`enCache || surLeReseau`). Sur une tablette partagée, le deuxième
enseignant aurait reçu le JSON du premier, **en ligne**, sans requête
réseau.

L'en-tête du fichier jurait pourtant : « il ne met en cache aucune page
HTML ». Vrai pour le HTML, faux pour tout le reste.

### La cause
On décidait par **méthode** (`GET`), pas par **appartenance**.

> Un cache qui n'énumère pas ce qu'il garde garde tout — et un
> cache-d'abord qui garde tout finit par répondre à la place du serveur.

### La correction
Liste blanche de préfixes (`appartientALaCoquille`). Tout le reste sort
du champ : on **ne rend pas la main** avec `respondWith`, le navigateur
traite la requête comme si le fichier n'existait pas. `VERSION` passée à
`v2` pour que l'activation **supprime** le cache déjà pollué — un
correctif qui laisse en place les données déjà déposées ne corrige que
l'avenir.

### Vérification
`node tests/sonde_cache_sw.js` — **0 entrée hors coquille**.

---

## Défaut 2 — l'unicité démontrée par une lecture, pas par l'index

**Gravité : moyenne.** HTTP 500 dans une situation ordinaire.

### Ce qui a été mesuré
`sync_apply_one()` faisait `SELECT` puis `INSERT`, avec
`uq_sync_client_uuid` derrière. `tests/sonde_sync_course.sh` lance deux
sessions simultanées postant le même `client_uuid` :

```
session 1 → HTTP 200
session 2 → HTTP 500   (PDOException 1062)
```

**Trois fois sur trois.**

### Pourquoi le défaut était invisible
Deux onglets d'un même navigateur partagent **une** session PHP, et le
gestionnaire de sessions par fichiers pose un verrou exclusif pour toute
la durée de la requête. Les envois se suivaient donc — mais par une
propriété **incidente** du gestionnaire de sessions, pas par une
décision d'architecture.

> Une correction qui repose sur une propriété qu'on n'a pas choisie
> n'est pas une correction, c'est un sursis.

### La correction
> L'unicité se démontre par l'index, pas par une lecture qui la précède.

On tente l'écriture ; un `23000` signifie exactement ce que le `SELECT`
cherchait à savoir. On relit l'issue et on la rend — c'est la définition
de l'idempotence.

Le cas d'une collision **inter-écoles** (l'identifiant est unique pour
tout le produit, la lecture est bornée à l'établissement) rend un refus
explicite qui **ne révèle rien** de l'autre école.

### Vérification
4 exécutions consécutives : `HTTP 200 / HTTP 200`, une seule ligne en base.

---

## Défaut 3 — un état transitoire rendu comme un verdict

**Gravité : moyenne.** L'appareil aurait menti à l'enseignant.

Corollaire du défaut 2 : le perdant relit la ligne alors que le gagnant
n'a pas fini — statut `pending`.

Côté appareil, `envoyerLaFile()` efface sur `applied`, marque sur
`conflict`, et traite **tout le reste** comme un refus définitif qu'il ne
réessaiera plus. L'opération se serait affichée « refusée » alors que le
serveur était en train de l'appliquer.

> Un état transitoire rendu comme un verdict fait mentir l'appareil sur
> ce que le serveur a fait.

**Correction** : `pending` est rendu avec un message explicite
(« conservez cette opération »), et l'appareil la **garde** pour le
prochain envoi, où elle portera son état définitif.

---

## Défaut 4 — une opération qui rompt emportait le lot entier

Un lot va jusqu'à 50 opérations. Sans barrière, une exception faisait
échouer la requête : les opérations déjà appliquées ne recevaient jamais
leur accusé, et l'appareil les renvoyait indéfiniment.

> Un lot qui tombe entier pour une ligne fait payer à quarante-neuf
> saisies la faute d'une seule.

**Correction** : chaque opération est isolée dans un `try`. Le détail
technique va au journal ; l'appareil reçoit un refus clair.

---

## Défaut 5 — un commentaire promettant une protection inatteignable

**Gravité : documentaire, mais réelle.**

L'en-tête de `sync_service_resolve()` affirmait qu'un arbitrage
« ne force pas un registre clos ». Le test l'a démenti :
`attendance_service_take()` laisse passer le détenteur de
`attendance.justify`, et **tous** les rôles portant `sync.resolve`
(DIRECTION, SCHOOL_ADMIN, SUPER_ADMIN) le portent aussi.

Le refus annoncé était **structurellement inatteignable**.

> Un commentaire qui promet une protection que personne ne peut
> déclencher est pire qu'un silence : il fait croire qu'on a vérifié.

**Correction** : le commentaire dit l'état réel. Arbitrer est un acte de
direction, identique à corriger un registre clos depuis l'écran ; la
synchronisation n'ouvre **aucun droit nouveau** et journalise
(`sync.conflict_resolved`). Le verrou continue de protéger ce pour quoi
il existe : l'enseignant, qui n'a pas `sync.resolve`.

Le test a été réécrit pour verrouiller la garantie **réelle** : un
arbitrage « l'appareil a raison » ne peut pas écrire un élève étranger à
la classe, parce qu'il repasse par le service.

---

## Défaut 6 — décider de la mise en file sur un drapeau

Trouvé par accident, en corrigeant une sonde fausse (voir ci-dessous).

`attendance-offline.js` mettait en file quand `navigator.onLine` valait
`false`. Or ce drapeau dit qu'une **interface réseau** est active, pas
que le serveur répond. En RDC le cas ordinaire est exactement celui-là :
le téléphone affiche « 4G » et rien ne passe.

> `navigator.onLine` annonce qu'une interface est active, pas que le
> serveur répond. On met en file sur l'ÉCHEC RÉEL, jamais sur un drapeau.

**Correction** : l'envoi est **tenté** ; c'est l'échec du `fetch` qui
déclenche la mise en file. `navigator.onLine === false` ne sert plus qu'à
s'épargner une attente inutile.

---

## Les sondes fausses — trois fois, et c'est le plus instructif

Une sonde qui rend vert sans rien prouver est plus dangereuse qu'un
défaut : elle **ferme** la question.

### 1. `context.setOffline(true)` ne coupe pas la boucle locale
Il bascule `navigator.onLine` à `false` et **laisse passer le trafic**.
Un `fetch` rendait encore 200 pendant que le test se croyait hors ligne.

Remplacé par une interception (`route.abort('internetdisconnected')`),
qui reproduit ce que voit le navigateur quand le fil est mort : un échec
réseau, pas un code HTTP.

C'est cette correction qui a révélé le **défaut 6**.

### 2. `php -S` sert une requête à la fois
La première sonde de concurrence rendait vert : les deux envois
s'étaient **succédé**, jamais croisés, faute de
`PHP_CLI_SERVER_WORKERS`.

> Une sonde qui ne concourt pas ne prouve rien — et elle rend vert.

La leçon est inscrite **dans la sonde** : elle refuse désormais de
s'exécuter sur un serveur mono-processus, et dit comment le relancer.

### 3. `fetch` suit les redirections
La sonde CSRF déclarait les deux routes **non protégées** (HTTP 200).
Faux : le refus répond par un 302 vers la page de connexion, que `fetch`
suivait. Je mesurais la page d'arrivée, pas la décision.

> Un `fetch` qui suit la redirection mesure la page d'arrivée, pas le
> verdict.

Corrigé par `redirect: 'manual'`. Les deux routes sont bien protégées.

---

## Les promesses vérifiées, une par une

| Promesse | Comment | Résultat |
|---|---|---|
| Le service worker s'installe | navigateur réel | ✓ portée `/` |
| Seuls `inscription`, `nom`, `matricule` descendent | énumération des champs | ✓ aucun champ en trop |
| Ni tuteur, ni adresse, ni solde, ni cote | recherche sur le contenu brut d'IndexedDB | ✓ |
| Une coupure met la saisie en file | interception réseau réelle | ✓ |
| Elle porte une clé d'idempotence et ce que l'écran a vu | lecture de la file | ✓ |
| L'utilisateur est prévenu sans mensonge | texte de l'alerte | ✓ |
| Le retour du réseau vide la file | rétablissement | ✓ |
| Un rejeu ne produit pas de doublon | même `client_uuid` renvoyé | ✓ 1 session, 1 ligne |
| Un état périmé produit un conflit | `seen_updated_at` obsolète | ✓ aucun écrasement |
| L'horloge de l'appareil n'arbitre rien | horloge avancée de 3 ans | ✓ conflit quand même |
| L'écran d'arbitrage montre les deux versions | navigateur réel | ✓ |
| L'arbitrage repasse par le service | élève étranger à la classe | ✓ refusé |
| Il laisse une trace au journal | `audit_logs` | ✓ |
| Aucune école ne voit les conflits d'une autre | deux écoles peuplées | ✓ |
| Un appareil ne se greffe pas sur un autre compte | second compte | ✓ refusé |
| **CSRF exigé sur les points JSON** | POST sans jeton, `redirect: manual` | ✓ refusé |
| **La déconnexion efface tout** | vrai clic sur « Se déconnecter » | ✓ voir ci-dessous |

### Sur la purge à la déconnexion

Après déconnexion : **0 liste**, **0 opération en file**, et aucune trace
de nom d'élève dans IndexedDB.

Cinq entrées subsistent dans le cache — `app.css`,
`bootstrap.min.css`, `bootstrap.bundle.min.js`,
`bootstrap-icons.min.css`, la police. Ce **n'est pas un reliquat de la
purge** : la page de connexion se charge aussitôt après et le service
worker remet en cache la coquille dont elle a besoin. C'est son travail,
et cela ne contient aucune donnée d'école.

La première version du test assertait « le cache est vide » et échouait
à tort.

> Une purge se juge sur ce qui reste, pas sur le compteur.

L'assertion porte désormais sur la propriété qui compte : **aucune page
ni route applicative ne subsiste**.

---

## Comment rejouer cet audit

```bash
php tests\sync_isolation.php                       # 43 attendus

set PHP_CLI_SERVER_WORKERS=6
php -S 127.0.0.1:8099 -t public

node tests\offline_browser.js <motdepasse> 1       # 13 attendus
node tests\ecran_sync.js      <motdepasse>         # 12 attendus
node tests\audit_8b1.js       <motdepasse>         # 11 attendus
node tests\sonde_cache_sw.js  <motdepasse>         # 0 hors coquille
bash tests/sonde_sync_course.sh <motdepasse>       # aucune requête ne rompt
```

---

## Ce qui reste ouvert

1. **Aucune purge des appareils inactifs.** `sync_devices` grossira. Sans
   conséquence de sécurité — l'identifiant n'authentifie rien — mais à
   trancher après un an d'usage réel.
2. **Un conflit non arbitré n'alerte que par la pastille.** Si personne
   n'ouvre l'écran, l'appel saisi hors connexion n'entre jamais au
   registre. Une relance par e-mail au bout de N jours serait cohérente
   avec la messagerie de la 8A.
3. **La pastille coûte une requête par page** aux comptes portant
   `sync.view`. Le coût est borné (COUNT sur index, quelques comptes) et
   assumé : sans elle, une saisie se perd en silence.
