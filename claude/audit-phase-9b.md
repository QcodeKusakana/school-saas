# Audit de la phase 9B — le journal

**Date** : 22 septembre 2026
**Méthode** : audit **par exécution**. Chaque constat ci-dessous a d'abord
été produit par une sonde, sur des données réelles, avant toute
correction — y compris le constat qui m'a donné tort.

---

## Le contrôle qui prime

```
bash tests/installation_zero.sh --oui
```

→ 78 permissions, 310 role_permissions, école neuve créée puis retirée.
**Le produit s'installe toujours depuis zéro**, migration 034 comprise.

---

## Une sonde fausse, et je le dis

Ma première sonde concluait que **toute** action de l'éditeur dans une
école cliente était invisible pour cette école. Elle posait
`$_SESSION['school_id'] = null` à la main — un état que le produit ne
produit pas : `platform_service_enter_school()` renseigne bien la session
à l'entrée.

> Une sonde qui simule un état que le produit ne produit jamais ne
> mesure rien — elle accuse.

Rejouée par la vraie route, la visite journalise correctement. Mais deux
cas voisins, eux, étaient bien défaillants.

---

## Défaut 1 — le journal prenait son périmètre dans la session

`app/core/audit.php`

```php
'school_id' => $_SESSION['school_id'] ?? null,
```

`auth_user()` réétablit le contexte multi-école **depuis la base** à
chaque requête, précisément pour qu'une session altérée ne puisse pas
changer d'école. Le journal, lui, lisait la session.

Mesuré, le contexte valant `2` :

| Situation | `tenant_id()` | trace écrite |
|---|---|---|
| visite par la vraie route | 2 | 2 ✓ |
| **ligne de commande** (pas de session) | 2 | **NULL** |
| **session portant `999999`** | 2 | **999999** |

Dans le premier cas défaillant, la trace n'appartenait à aucune école et
disparaissait de tous les journaux. Dans le second, la session primait
sur le contexte.

> Une trace qui prend son périmètre dans la source que l'on protège
> partout ailleurs ne protège rien.

**Correction** : `'school_id' => tenant_id()`. Pour un compte d'école,
les deux coïncident — rien ne change. Pour la visite d'un éditeur, la
trace porte l'école visitée, c'est-à-dire **l'école dont les données sont
touchées**.

### Ce que la correction oblige à ajouter

L'école voit désormais les actions de l'éditeur dans son journal. Elle
doit pouvoir les distinguer des siennes, sans quoi un nom inconnu
apparaîtrait au milieu de son personnel.

- les lectures exposent `author_school_id` (un compte de plateforme porte
  `school_id IS NULL`) ;
- la liste marque ces lignes d'un badge **« Éditeur »**, et un bandeau
  d'avertissement n'apparaît **que si** l'éditeur est réellement
  intervenu — une mise en garde permanente finirait par ne plus être lue ;
- la fiche de détail précise « — éditeur de la plateforme » ;
- l'écran de l'éditeur disait que ses actions n'apparaissaient dans aucun
  journal d'école. **C'était devenu faux** : le texte a été corrigé.

---

## Défaut 2 — un filtre de date incompréhensible plantait ou mentait

Le champ est un `<input type="date">`, mais rien n'oblige un client à le
respecter. Mesuré sur l'écran livré :

| Saisie | Avant |
|---|---|
| `au=pas-une-date` | **HTTP 500** — `strtotime()` rend `false`, `date()` le refuse en PHP 8 |
| `au=2026-13-45` | **HTTP 500** |
| `du=2026-13-45` | **0 résultat** — l'écran affirmait qu'il ne s'était rien passé |

> Un filtre qu'on n'a pas compris ne doit ni planter ni répondre
> « rien » : il doit être ignoré.

**Correction** : `audit_date_valide()` exige la forme exacte `Y-m-d`
**et** une date qui existe — `checkdate` refuse le 31 février, que
`strtotime` reporterait au 3 mars. Une date invalide est ignorée ; une
date valide filtre toujours, ce que le test vérifie explicitement pour
que « ignoré » ne devienne pas « inopérant ».

---

## Défaut 3 — une affirmation de performance que je n'avais pas mesurée

La migration 033 affirmait que `idx_audit_school_action` couvrait « le
filtre **et** le tri ». Vrai pour le filtre par action ; **faux pour
l'écran par défaut**, qui n'en pose aucun.

Mesuré sur **250 000 lignes**, une école détenant 20 % du parc :

| Requête | Clé choisie | Coût |
|---|---|---|
| page 1, sans filtre | `PRIMARY` (scan arrière) | 0,29 ms |
| page 200, sans filtre | `idx_audit_school_action` + **filesort** | 12,16 ms |

Le tri de fichier porte sur ~99 000 lignes pour en rendre 50, et il
**croît avec la taille de l'école**.

Après `(school_id, id)` — migration 034 :

| Requête | Clé | Coût |
|---|---|---|
| page 1 | `idx_audit_school_id` | 1,65 ms |
| page 200 | `idx_audit_school_id` | 3,53 ms |

Plus aucun `filesort`.

### Le coût à l'écriture, mesuré et non supposé

`audit_logs` est écrite à **chaque geste** du produit. Sur 10 000
insertions :

| | par écriture |
|---|---|
| sans le 4ᵉ index | 0,1165 ms |
| avec le 4ᵉ index | 0,1187 ms (**+2 %**) |

Deux pour cent seulement, parce que `(school_id, id)` s'écrit toujours
par la droite : `id` est croissant, aucune page d'index n'a à être
scindée.

> Un index qu'on ajoute sans mesurer ce qu'il coûte à l'écriture n'est
> pas une optimisation, c'est un pari.

**Le compromis est assumé** : la page 1 passe de 0,29 à 1,65 ms — MySQL
préfère désormais l'index secondaire au scan arrière de la clé primaire.
On échange 1,3 ms sur le cas courant, qui reste sous deux millisecondes,
contre la disparition d'un coût qui, lui, n'a pas de borne.

---

## Défaut 4 — dix lignes de prose envoyées à MySQL

L'explication de `author_school_id` avait été écrite **dans la chaîne
SQL**, en commentaire `--` : elle partait vers le serveur à chaque
requête. Déplacée en commentaire PHP.

Et l'en-tête de `repositories.php` affirmait encore que les actions de
l'éditeur restaient invisibles à l'école — ce qui était devenu la
description du défaut, pas du comportement. Corrigé.

---

## Vérification

| | |
|---|---|
| `tests/audit_journal.php` | 41 → **56 tests** |
| `tests/journal_browser.js` | 25 → **29 vérifications** |
| Total PHP | **1 090**, 21 suites, 0 échec |
| Total navigateur | **134**, 7 recettes, 0 échec |
| Installation depuis zéro | ✓ |

Ce que les nouveaux tests verrouillent :

- sans session, la trace porte quand même l'école ;
- une session portant `999999` ne détourne pas la trace ;
- l'école voit l'action de l'éditeur **et** peut la reconnaître, en liste
  comme en détail ;
- six formes de dates invalides sont ignorées sans erreur, en borne haute
  comme en borne basse, et une date valide filtre toujours ;
- le 31 février est refusé, pas reporté au 3 mars ;
- en navigateur : `au=pas-une-date` répond 200 et n'applique pas le
  filtre.

---

## Ce qui reste ouvert

1. **La pagination profonde reste en `OFFSET`.** L'index supprime le tri,
   pas la lecture des 10 000 entrées sautées. Une pagination par clé
   (`WHERE id < :dernier`) serait exacte à toute profondeur — à faire si
   l'usage le montre nécessaire.
2. **Le filtre par auteur n'a pas d'index dédié** sur `(school_id,
   user_id, id)`. `idx_audit_user` existe mais commence par `user_id`.
   Non mesuré comme gênant ; à surveiller.
3. **Pas de purge automatique**, et **`email_messages` n'est toujours pas
   purgé** — reportés en 9C ou 9D.
4. **`archive.view`, `report.academic`, `report.export` restent
   dormantes** — phases 9C et 9D.
