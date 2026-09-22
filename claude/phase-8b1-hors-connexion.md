# Phase 8B1 — Le hors connexion

**Version** 0.9.0 · **État** livrée et auditée par exécution
**Tests** 921 verts sur 17 suites, dont 43 pour `sync_isolation`

---

## 1. Objectif et périmètre

Permettre à **l'appel des présences** de se faire sans réseau, puis de
remonter au serveur sans doublon ni écrasement silencieux.

### Pourquoi cet écran, et lui seul

L'appel se fait debout devant une classe, souvent dans une cour, au
moment précis où le réseau manque. Il ne touche ni à l'argent ni à un
document officiel, et il se corrige.

Les bulletins, la caisse et les inscriptions **restent en ligne**. Les
traiter à l'aveugle produirait des divergences que personne ne saurait
arbitrer. La page de repli (`public/offline.html`) le dit franchement
plutôt que de laisser croire le contraire.

> Ne jamais prétendre qu'une fonctionnalité fonctionne hors ligne si
> elle dépend obligatoirement du serveur.

---

## 2. Tables utilisées

| Table | Rôle |
|---|---|
| `sync_devices` | l'appareil d'un utilisateur ; `device_uuid` UNIQUE produit-wide |
| `sync_queue` | une ligne par opération reçue ; `client_uuid` UNIQUE = clé d'idempotence |
| `sync_conflicts` | les divergences en attente d'arbitrage humain |
| `attendance_sessions`, `attendance_records` | écrites **uniquement** via `attendance_service_take()` |

Migration : `2026_09_21_031_phase8b_hors_connexion.sql`
(permissions `sync.view`, `sync.resolve` ; aucune modification de schéma —
les trois tables datent de la phase 1).

---

## 3. Fichiers

### Serveur
```
app/modules/sync/services.php        les trois gardes + l'arbitrage
app/modules/sync/repositories.php    lectures, décomptes, appareils
app/modules/sync/controllers.php     3 points JSON + 2 écrans
app/views/sync/conflicts.php         l'écran d'arbitrage
app/config/routes.php                5 routes
app/views/partials/sidebar.php       entrée + pastille
```

### Appareil
```
public/manifest.webmanifest          PWA
public/sw.js                         service worker — COQUILLE SEULEMENT
public/offline.html                  page de repli honnête
public/assets/js/offline.js          IndexedDB, file d'attente, purge
public/assets/js/offline-retry.js    la page de repli se rouvre seule
public/assets/js/attendance-offline.js  l'appel hors connexion
public/assets/img/icon-{192,512,maskable}.png
```

### Recette
```
tests/sync_isolation.php             43 tests
tests/offline_browser.js             13 vérifications, vrai navigateur
tests/ecran_sync.js                  12 vérifications, écran d'arbitrage
tests/sonde_cache_sw.js              sonde — contenu réel du cache
tests/sonde_sync_course.sh           sonde — course sur client_uuid
```

---

## 4. Rôles et permissions

| Permission | Rôles | Ce qu'elle ouvre |
|---|---|---|
| `sync.view` | DIRECTION, PREFET, SCHOOL_ADMIN, SUPER_ADMIN | voir les conflits et les appareils |
| `sync.resolve` | DIRECTION, SCHOOL_ADMIN, SUPER_ADMIN | arbitrer |

Les trois points JSON (`/sync/appareil`, `/sync/envoyer`, `/sync/etat`)
n'exigent que `auth` + `school` : tout enseignant qui peut faire l'appel
doit pouvoir le remonter. Le contrôle métier est fait par
`attendance_service_take()`, pas par la route.

---

## 5. Le flux

```
Écran d'appel (en ligne)
   ↓  memoriserListe() → IndexedDB : inscription, nom, matricule. RIEN D'AUTRE.
Coupure
   ↓  submit → fetch TENTÉ → échec réel → mettreEnFile()
IndexedDB.outbox (client_uuid tiré une seule fois)
   ↓  événement `online` ou rechargement
POST /sync/envoyer  (lot ≤ 50)
   ↓
1. IDEMPOTENCE     déjà reçue ? → son résultat d'origine, rien n'est rejoué
2. CONCURRENCE     seen_updated_at ≠ état serveur ? → sync_conflicts, statut `conflict`
3. SERVICE MÉTIER  attendance_service_take() — son refus devient le refus
   ↓
applied → la ligne locale disparaît
conflict → marquée, plus renvoyée, attend un humain
pending  → CONSERVÉE, réessayée au prochain envoi
rejected → marquée avec le message du service
```

---

## 6. Sécurité — les décisions et leur raison

### Ce qui descend sur l'appareil
`inscription`, `nom`, `matricule`. Rien d'autre. Pas de tuteur, pas
d'adresse, pas de solde, pas de cote, pas de photo.

Ce sont des mineurs, l'appareil est souvent partagé ou prêté, et
IndexedDB n'est pas chiffré.

> Ce qu'on ne télécharge pas ne peut pas fuir.

Trois bornes en plus : purge à la déconnexion, expiration à 7 jours,
et seules les classes réellement ouvertes descendent.

### Le service worker ne met en cache QUE la coquille
Décision par **appartenance** (préfixe de chemin), jamais par méthode.
Voir §8, défaut n° 1.

### L'identifiant d'appareil n'authentifie rien
C'est une étiquette générée par le navigateur. C'est la session qui
authentifie, et chaque opération est rattachée à l'utilisateur connecté
**au moment de la réception** — jamais à celui de la charge utile. Un
identifiant deviné ne permet pas de se greffer sur l'appareil d'autrui.

### L'horodatage de l'appareil n'arbitre jamais
Il est conservé pour le journal, borné à ±2 ans. Un téléphone mal réglé
ne gagne pas une divergence.

### Isolation multi-écoles
`client_uuid` est unique pour tout le produit, mais toutes les lectures
sont bornées à l'établissement. Une collision inter-écoles rend un refus
qui **ne révèle rien** de l'autre école.

### CSRF
Les trois points JSON passent par le jeton, envoyé en en-tête. Un point
d'entrée de synchronisation dispensé de CSRF serait une porte ouverte.

---

## 7. Ce qui reste EN LIGNE, volontairement

Bulletins · caisse · paiements · rapports · inscriptions · modification
d'un dossier élève · envoi de messages.

Raison : ces écrans touchent à de l'argent ou à des documents officiels.

---

## 8. Audit par exécution — les défauts trouvés et corrigés

### 1. Le service worker mettait en cache les routes applicatives
**Mesuré** avec `tests/sonde_cache_sw.js` : `/sync/etat`, route JSON
authentifiée, se trouvait dans le cache. Toute route AJAX du produit y
serait allée : recherche d'élève, données de graphique, listes paginées.

Plus grave que la fuite : la stratégie était **cache d'abord**. Sur une
tablette partagée, le deuxième enseignant aurait reçu le JSON du
premier, **en ligne**, sans requête réseau.

L'en-tête du fichier jurait pourtant le contraire. La cause : on décidait
par **méthode** (`GET`), pas par **appartenance**.

> Un cache qui n'énumère pas ce qu'il garde garde tout — et un
> cache-d'abord qui garde tout finit par répondre à la place du serveur.

**Correction** : liste blanche de préfixes (`/assets/`,
`/manifest.webmanifest`, `/offline.html`) ; tout le reste sort du champ
du service worker, qui ne rend pas la main avec `respondWith`. `VERSION`
passée à `v2` pour que l'activation **supprime** le cache déjà pollué.

### 2. L'unicité était démontrée par une lecture, pas par l'index
`sync_apply_one()` faisait `SELECT` puis `INSERT` avec un index UNIQUE
derrière. **Mesuré** à deux sessions simultanées
(`tests/sonde_sync_course.sh`), **3 fois sur 3** : le perdant prenait un
1062 non rattrapé, donc un **HTTP 500**.

Le verrou de session PHP masquait le défaut tant que les deux envois
venaient de deux onglets du même navigateur — une propriété du
gestionnaire de sessions par fichiers, pas une décision d'architecture.

> L'unicité se démontre par l'index, pas par une lecture qui la précède.

**Correction** : on tente l'écriture, et un `23000` est traité comme ce
qu'il signifie — l'opération est déjà là. On relit son issue et on la
rend.

### 3. Un état transitoire rendu comme un verdict
Corollaire du précédent : le perdant peut relire la ligne alors que le
gagnant n'a pas fini (`pending`). Côté appareil, tout ce qui n'est ni
`applied` ni `conflict` était marqué **refusé définitivement** —
l'enseignant aurait vu un échec pendant que le serveur appliquait.

> Un état transitoire rendu comme un verdict fait mentir l'appareil sur
> ce que le serveur a fait.

**Correction** : `pending` est rendu avec un message explicite, et
l'appareil **conserve** l'opération pour le prochain envoi.

### 4. Une opération qui rompt emportait le lot entier
Un lot va jusqu'à 50 opérations. Une seule exception faisait échouer la
requête : les opérations déjà appliquées ne recevaient jamais leur accusé.

> Un lot qui tombe entier pour une ligne fait payer à quarante-neuf
> saisies la faute d'une seule.

**Correction** : chaque opération est isolée ; le détail va au journal,
l'appareil reçoit un refus clair.

### 5. Un commentaire qui promettait une protection inatteignable
L'en-tête de `sync_service_resolve()` affirmait qu'un arbitrage « ne
force pas un registre clos ». Faux : `attendance_service_take()` laisse
passer le détenteur de `attendance.justify`, et **tous** les rôles
portant `sync.resolve` le portent aussi.

> Un commentaire qui promet une protection que personne ne peut
> déclencher est pire qu'un silence : il fait croire qu'on a vérifié.

**Correction** : le commentaire dit l'état réel — arbitrer est un acte de
direction, identique à corriger un registre clos depuis l'écran ; la
synchronisation n'ouvre **aucun droit nouveau** et journalise
(`sync.conflict_resolved`). Le verrou continue de protéger ce pour quoi
il existe : l'enseignant, qui n'a pas `sync.resolve`.

### 6. Deux sondes qui rendaient vert sans rien prouver
- `context.setOffline(true)` bascule `navigator.onLine` mais **laisse
  passer** le trafic vers la boucle locale. Corrigé par
  `route.abort()`. Et cela a révélé un défaut réel dans mon code : il
  décidait de mettre en file sur le **drapeau**. Il tente désormais
  l'envoi et met en file sur l'**échec réel**.

  > `navigator.onLine` annonce qu'une interface est active, pas que le
  > serveur répond.

- `php -S` sert **une requête à la fois** sans
  `PHP_CLI_SERVER_WORKERS`. La première sonde de concurrence était
  sérialisée.

  > Une sonde qui ne concourt pas ne prouve rien — et elle rend vert.

  La sonde **refuse désormais de s'exécuter** sur un serveur
  mono-processus.

---

## 9. Vérifications à rejouer

```bash
php database\migrate.php
php tests\sync_isolation.php          # 43 attendus

# avec le serveur de développement lancé :
#   PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8099 -t public
node tests\offline_browser.js  <motdepasse> 1   # 13 attendus
node tests\ecran_sync.js       <motdepasse>     # 12 attendus
node tests\sonde_cache_sw.js   <motdepasse>     # 0 entrée hors coquille
bash tests/sonde_sync_course.sh <motdepasse>    # aucune requête ne rompt
```

---

## 10. Points ouverts

1. **Un appareil jamais revu reste en base.** Aucune purge des
   `sync_devices` inactifs n'est prévue. Sans conséquence de sécurité —
   l'identifiant n'authentifie rien — mais la table grossira. À trancher
   quand une école aura un an d'usage.
2. **Un conflit non arbitré n'alerte que par la pastille.** Si personne
   n'ouvre l'écran, l'appel saisi hors connexion n'entre jamais au
   registre. Une relance par e-mail au bout de N jours serait cohérente
   avec la messagerie de la phase 8A.
3. **Seul l'appel descend.** Étendre `SYNC_ENTITIES` exigera, pour chaque
   type, de répondre aux mêmes trois questions : quel service rejoue
   l'écriture, quel champ sert de `seen_updated_at`, et qui arbitre.
