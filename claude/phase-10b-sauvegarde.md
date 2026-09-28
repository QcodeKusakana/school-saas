# Phase 10B — La sauvegarde, et la restauration qui la rend crédible

**Date** : 28 septembre 2026

---

## Le point de départ

La liste d'avant portait une ligne : « sauvegarde automatique de la base
configurée ». Une **intention**, que rien ne mesurait et que personne
n'avait jamais éprouvée.

> Une sauvegarde jamais restaurée est une croyance, pas une protection.

Tant qu'aucun chemin de retour n'est écrit **et** joué, personne ne sait
si les fichiers accumulés dans `storage/backups/` valent quoi que ce soit.

---

## Le choix qui engage : PHP pur, pas `mysqldump`

Dans l'ordre du principe de décision du projet.

**Sécurité (1).** `mysqldump -u x -pMOTDEPASSE` affiche le mot de passe de
la base dans la liste des processus, lisible par tout compte du serveur —
et sur un hébergement mutualisé, ces comptes ne sont pas les vôtres. Le
contourner demande un fichier d'options temporaire : un secret écrit sur
le disque le temps de la sauvegarde.

**Fiabilité (2).** `mysqldump` n'est pas garanti sur un mutualisé ; PDO
l'est, puisque le produit ne tourne pas sans. Prévoir les deux donnerait
deux chemins de code dont un seul serait éprouvé — et ce serait toujours
l'autre, le jour de l'incident.

> Une sauvegarde qui emprunte un chemin qu'on n'éprouve jamais est une
> sauvegarde qu'on découvre le jour où elle doit servir.

---

## Les trois pièges de ce schéma

Relevés **avant** d'écrire l'export, en interrogeant la base — pas en les
supposant.

### 1. Cinq colonnes `varbinary`

```
audit_logs.ip_address      login_attempts.ip_address
password_resets.ip_address user_sessions.ip_address
users.last_login_ip
```

Des adresses compactées par `inet_pton` : **des octets bruts, pas du
texte**. Passées par `PDO::quote()`, elles reviendraient corrompues — et
silencieusement, puisqu'une adresse abîmée reste une chaîne d'octets.

Elles sortent en littéraux hexadécimaux, et la recette vérifie qu'une
IPv6 revient **octet pour octet** :

```
  ✓ L'adresse IPv6 binaire revient octet pour octet — inet_ntop : 2001:db8::dead:beef
```

### 2. Une colonne générée

`subscription_payments.reference_live` est calculée par MySQL, qui refuse
qu'on l'insère. Elle est exclue de la liste des colonnes et se recalcule à
la restauration — c'est tout son intérêt.

### 3. Les clés étrangères

Aucun ordre d'insertion naïf ne tient : une inscription précède son élève
dans l'ordre alphabétique. Le fichier les suspend et les rétablit à la
dernière ligne, **jamais au-delà**.

---

## Ce que l'archive contient

```
  base.sql        le schéma et toutes les données
  manifeste.json  date, migrations appliquées, empreinte par table
  uploads/        photos, logos, documents déposés
```

Un export qui oublierait `storage/uploads` rendrait des lignes pointant
vers des fichiers disparus. La recette vérifie qu'un fichier déposé revient
**identique à l'octet près**.

---

## La restauration vérifie — sinon elle n'est qu'un espoir

Trois choses la distinguent d'un `SOURCE dump.sql` :

### Elle refuse une archive plus récente que le code

Restaurer un schéma d'après-demain sous un code d'hier ne casse pas tout
de suite. Ça casse à la première requête qui cherche une colonne que le
code ignore — des jours plus tard, sur des données déjà modifiées.

Le refus tombe **avant** toute demande de confirmation, et il nomme la
migration inconnue.

### Elle demande le nom de la base

La restauration écrase tout. On ne tape pas ce nom par accident.

### Elle recalcule les empreintes — avec la fonction qui les a produites

`database/dump.php` porte **l'unique** définition de l'empreinte.

> Une vérification qui n'emprunte pas le même chemin que ce qu'elle
> vérifie finit par valider autre chose.

Le prix est une seconde lecture de chaque table au moment de la
sauvegarde. Il est payé volontiers : le principe de décision met la
fiabilité des données (2) avant la performance (4).

`--verifier` fait cette étape **seule**, sans rien écrire — de quoi
s'assurer qu'une sauvegarde vaut quelque chose sans attendre le sinistre.

---

## La preuve

`tests/backup_restore.php` prend une base complète, la **détruit**, la
rend, et compare les 61 empreintes — sur un décor jetable, jamais sur la
base de travail.

```
  ✓ Base intacte : elle dit conforme — 61 table(s) conforme(s) sur 61
  ✓ Le décor a bien été modifié avant de vérifier — 1 ligne(s) touchée(s)
  ✓ Un seul champ modifié : elle dit NON
  ✓ … en signalant que le compte de lignes n'a pas bougé
  ✓ Le décor est réellement abîmé — 59 table(s) au lieu de 61
  ✓ Sans le nom de la base, la restauration REFUSE
  ✓ La restauration se termine et se déclare VÉRIFIÉE
  ✓ L'adresse IPv6 binaire revient octet pour octet
  ✓ Le fichier déposé est revenu, identique à l'octet près
  ✓ Une archive plus récente que le code est REFUSÉE
```

Le troisième contrôle est le plus important : **une vérification qui
répondrait « conforme » quoi qu'il arrive ne vérifie rien.** On la met à
l'épreuve avant de lui faire confiance.

---

## Deux défauts trouvés en chemin, tous deux dans mon travail

### `getopt()` ignorait le drapeau, et la base allait être écrasée

```
php database/restore.php <archive> --verifier
```

`getopt()` suit la convention POSIX et **cesse d'analyser au premier
argument positionnel** — or l'archive en est un, et elle vient forcément
avant. Le script s'apprêtait donc à écraser la base alors qu'on lui
demandait de ne rien écrire.

> Une option qu'on place après l'argument obligatoire n'est pas une option
> exotique : c'est l'ordre naturel.

Lecture directe de `$argv`, où la position n'a aucune importance.

### La sonde mesurait le vide

Elle modifiait `schools` pour éprouver la détection d'écart. Sans
`--demo`, cette table est **vide** : zéro ligne touchée, donc rien de
changé, donc une vérification qui répondait « conforme » — et une recette
qui accusait un produit sain.

Le piège est déjà au canon du projet depuis la 9D. Il m'a repris. La sonde
vérifie désormais que sa modification a eu lieu, et le dit :

```
  ✓ Le décor a bien été modifié avant de vérifier — 1 ligne(s) touchée(s)
```

---

## Deux ajouts au contrôle avant mise en service

**Un contrôle « Sauvegarde »** : existe-t-elle, et de quand date-t-elle ?
Au-delà de sept jours, bloquant. Il ne dit pas qu'elle est restaurable —
c'est `--verifier` qui le dit, et rien d'autre ne le dira à sa place.

**Le rapport annonce ce que l'installation se déclare être.** Sur une
machine de développement, huit points bloquants sont la **bonne** réponse.
Rendus sans un mot, ils ressemblent à une alarme.

> Un avertissement qu'on apprend à ignorer ne protège plus personne.

Le code de sortie, lui, ne bouge pas : la question posée reste « cette
installation peut-elle être ouverte au public ».

---

## Vérification

| | |
|---|---|
| `tests/backup_restore.php` | **39 tests** (nouveau) |
| `tests/preflight_mise_en_service.php` | 68 → **72** |
| Total PHP | **1 341**, 25 suites, 0 échec |
| Total navigateur | **184**, 9 recettes, 0 échec |
| Installation depuis zéro | ✓ |

---

## Fichiers

```
database/dump.php         (nouveau — la bibliothèque partagée, sans effet de bord)
database/backup.php       (nouveau)
database/restore.php      (nouveau)
database/preflight.php    (contrôle « Sauvegarde » + la ligne de contexte)
tests/backup_restore.php  (nouveau, 39)
tests/preflight_mise_en_service.php (68 → 72)
README.md, docs-projet/etat-du-projet.md
```

Aucune migration : rien à changer en base.

---

## Ce qui reste ouvert

1. **Les sauvegardes restent sur le serveur.** Un disque qui meurt emporte
   la base *et* ses sauvegardes. Une copie hors du serveur est le geste
   qui manque — et il dépend de l'hébergeur, pas du produit.
2. **`backup.php` n'est pas planifié.** Le produit sait sauvegarder ; il
   ne sait pas encore le faire tout seul. Une tâche cron le fait, mais
   elle n'est pas posée par l'installation.
3. **`sql_split()` charge le fichier entier en mémoire.** Sans effet à
   cette taille (351 Ko pour la base de démonstration) ; à revoir si une
   école dépasse quelques dizaines de mégaoctets.
4. **La sauvegarde n'est pas chiffrée.** Elle contient les données
   personnelles de tous les élèves. Elle est hors de la racine web et
   protégée par `.htaccess` ; chiffrer ajouterait le risque de perdre la
   clé *et* la sauvegarde. À trancher avec le développeur.
5. **La procédure d'effacement d'une école (RGPD)** reste la dette la plus
   lourde — mais elle a maintenant son prérequis : on ne supprime pas ce
   qu'on ne sait pas rendre.
