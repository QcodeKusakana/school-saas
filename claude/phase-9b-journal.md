# Phase 9B — Le journal

**Date** : 22 septembre 2026

---

## Pourquoi cette phase avant les rapports

L'état des lieux, mesuré et non supposé :

```
$ grep -c "perm:<code>" app/config/routes.php
  archive.view           0 route
  audit.view             0 route      ← semée en phase 1
  platform.audit.view    0 route      ← semée en phase 1
  report.academic        0 route
  report.export          0 route
  report.financial       2 routes
```

Cinq permissions sur six ne menaient nulle part. Et pendant ce temps,
**neuf phases ont écrit consciencieusement dans `audit_logs`** — 55
actions distinctes, de la connexion au retrait d'un document officiel.
Personne ne pouvait en lire une ligne.

> Un journal que personne ne peut lire n'est pas une traçabilité,
> c'est une table qui grossit.

Le principe de décision du projet classe la sécurité en premier. Les
rapports (9C) et les archives (9D) suivent.

---

## Défauts trouvés AVANT d'écrire l'écran

Rendre un journal lisible sans savoir ce qu'il contient serait
irresponsable. Trois sondes ont précédé la première ligne d'interface.

### 1. La liste de masquage était écrite dans une autre langue que le code

`AUDIT_REDACTED_FIELDS` ne contenait que des termes anglais. Mesuré à
l'exécution :

| Champ | Avant |
|---|---|
| `password`, `api_key`, `token_hash` | masqué |
| `mot_de_passe` | **EN CLAIR** |
| `motdepasse` | **EN CLAIR** |
| `mdp` | **EN CLAIR** |
| `jeton` | **EN CLAIR** |
| `cle_de_chiffrement` | **EN CLAIR** |

Tout le produit nomme ses champs en français. Un futur appel
`audit_log(..., ['mot_de_passe' => $x])` aurait déposé le secret en base,
puis à l'écran.

> Une liste de masquage écrite dans une autre langue que le code qu'elle
> protège ne masque rien — elle rassure.

### 2. Le masquage ne descendait pas d'un niveau

`['smtp' => ['password' => '…']]` passait entier. Le masquage est
désormais récursif, borné en profondeur.

### 3. La borne de profondeur n'appliquait pas ce qu'elle annonçait

`AUDIT_REDACT_MAX_DEPTH = 6`, et la coupure tombait au **huitième**
tableau imbriqué : la profondeur était comptée à partir de zéro.

> Une constante qui annonce une borne que le code n'applique pas est une
> borne que personne ne peut vérifier en la lisant.

### Ce qui n'est PAS masqué, et pourquoi

Trop masquer n'est pas neutre : cela rend le journal muet là où il doit
parler.

- **Un booléen n'est jamais masqué.** Le module Courriel journalise
  `mot_de_passe_change => true` — précieux, et sans risque. La valeur
  décide autant que la clé.
- **`key` seul est absent de la liste.** Il emporterait `setting_key` et
  `period_key`, qui sont exactement ce qu'on veut lire.

---

## Ce que la phase livre

### `/journal` — l'école

Permission `audit.view` (DIRECTION, SCHOOL_ADMIN, SUPER_ADMIN).

- liste paginée, 50 par page, triée `id DESC` ;
- filtres : action, objet, auteur, période ;
- fiche de détail : valeurs avant/après, adresse IP, appareil ;
- les listes de filtres sont **lues en base**, pas tenues à la main.

### `/plateforme/journal` — l'éditeur

Permission `platform.audit.view`. Le lien existait dans la barre
latérale depuis la 7B, masqué par `route_exists()`.

Il montre en plus la colonne établissement **et les lignes sans
établissement** : les actions de l'éditeur lui-même (`school_id = NULL`)
n'apparaissent dans aucun journal d'école. Sans cet écran, personne ne
relit ce que fait l'éditeur.

Il **n'affiche pas les valeurs modifiées** : elles concernent des élèves
mineurs d'écoles clientes. L'éditeur voit *que* quelque chose a été fait,
par qui et quand ; pour savoir *quoi*, il entre dans l'école — et cette
entrée est elle-même journalisée.

---

## Ce que le module ne fait PAS, délibérément

**Aucune suppression à l'unité.** Un journal qu'on peut élaguer ligne à
ligne ne prouve plus rien : celui qu'il surveille effacerait la sienne.

La seule sortie est une **purge par ancienneté**, avec trois protections :

1. **Un plancher de 365 jours.** Une année scolaire entière, plus le
   délai pendant lequel une contestation de note ou de paiement reste
   plausible.
2. **Le périmètre** : la clause porte `tenant_require()`, jamais une
   valeur venue de la requête.
3. **La trace** : la purge s'inscrit dans le journal qu'elle vient de
   réduire, avec le nombre de lignes et la borne. Écrite *après* la
   suppression, elle est hors de portée de la purge qu'elle décrit.

> Une purge qui ne laisse pas de trace d'elle-même est indiscernable
> d'un effacement.

### Lire et purger sont deux pouvoirs distincts

`audit.purge` est une permission nouvelle, donnée à **SCHOOL_ADMIN et
SUPER_ADMIN seulement**. Volontairement **pas à DIRECTION**, qui lit le
journal : le chef d'établissement est l'une des personnes que ce journal
trace.

---

## Base de données

Migration `2026_09_22_033_phase9b_journal.sql`, idempotente.

**Aucune modification de `audit_logs`** — la table portait déjà tout.

1. Permission `audit.purge` + affectation à SCHOOL_ADMIN et SUPER_ADMIN.
2. Index `idx_audit_school_action (school_id, action, id)` : le filtre
   par action sur une table qui grossit à chaque geste du produit.
   `idx_audit_school_date` commence par `school_id` mais enchaîne sur
   `created_at` — il ne sert pas ce filtre. Le nouvel index couvre le
   filtre **et** le tri `id DESC`, sans tri de fichier.

---

## Défauts trouvés par la recette navigateur

### La fiche de détail répondait 500

Le routeur passe les paramètres de route en `string` ; le contrôleur
typait `int`. `TypeError`, page d'erreur.

Et la première version de la recette l'a presque laissé passer : elle
vérifiait que l'**URL** valait `/journal/\d+`. Elle valait. C'est la page
qui ne valait rien.

> Une URL correcte ne dit rien de la page qu'elle a rendue.

La recette assert désormais le **code de réponse** et l'absence de trace
d'erreur.

### Aucun compte éditeur dans le jeu de démonstration

Toute la console de l'éditeur — parc, abonnements, soldes, journal
global — était **invérifiable en navigateur** : les suites PHP la
couvraient, aucune recette ne l'ouvrait.

> Un écran qu'aucun compte ne peut ouvrir n'est pas un écran testé,
> c'est un écran supposé.

`seed_demo.php` crée désormais `editeur.demo` (SUPER_ADMIN,
`school_id = NULL`). C'est une donnée de démonstration comme le reste du
fichier, à supprimer avant la mise en service.

### Un commentaire vidé de sa substance

Dans `sidebar.php`, un commentaire avait perdu les deux noms de
permission qu'il citait et se lisait « un compte portant  sans  voyait un
lien qui le refusait ». Rétabli.

---

## Vérification

| | |
|---|---|
| `tests/audit_journal.php` | **41 tests** |
| `tests/journal_browser.js` | **25 vérifications** |
| Total PHP | **1 075**, 21 suites, 0 échec |
| Total navigateur | **130**, 7 recettes, 0 échec |
| Installation depuis zéro | ✓ 78 permissions, 310 role_permissions |

Ce que la suite PHP démontre, entre autres :

- le masquage, en français comme en anglais, imbriqué, borné à la
  profondeur exacte annoncée ;
- l'école B ne lit ni la liste, ni une entrée, ni les auteurs de A ;
- `audit_repo_search_platform()` hors `platform_scope` est **refusé par
  le garde-fou** ;
- la purge : plancher, périmètre, trace, et la trace survit ;
- les bornes de dates sont inclusives des deux côtés — une entrée à 15h42
  appartient à la journée qui la borne ;
- la pagination ne saute ni ne répète, même sur douze entrées écrites
  dans la même seconde.

---

## Fichiers

```
app/core/audit.php                                (masquage : 3 corrections)
app/modules/audit/repositories.php                (nouveau)
app/modules/audit/services.php                    (nouveau)
app/modules/audit/controllers.php                 (nouveau)
app/modules/platform/controllers.php              (+ ctrl_platform_audit)
app/views/audit/index.php                         (nouveau)
app/views/audit/entry.php                         (nouveau)
app/views/platform/audit.php                      (nouveau)
app/views/partials/sidebar.php                    (lien + commentaire rétabli)
app/config/routes.php                             (4 routes)
database/migrations/2026_09_22_033_phase9b_journal.sql
database/seed_demo.php                            (compte éditeur)
tests/audit_journal.php                           (nouveau, 41)
tests/journal_browser.js                          (nouveau, 25)
```

---

## Ce qui reste ouvert

1. **Pas de purge automatique.** La purge est manuelle. Une tâche
   planifiée supposerait un ordonnanceur, que l'hébergement cPanel visé
   n'offre pas toujours.
2. **`email_messages` n'est toujours pas purgé** — le journal des envois
   grandit sans fin. Même mécanisme à appliquer, en 9C ou 9D.
3. **Pas d'export du journal.** `report.export` reste dormante ; c'est la
   phase 9C.
4. **Le journal global n'est pas paginé par école.** À mille écoles, le
   filtre devient indispensable ; il existe, mais rien ne l'impose.
5. **`archive.view`, `report.academic`, `report.export` restent
   dormantes** — phases 9C et 9D.
