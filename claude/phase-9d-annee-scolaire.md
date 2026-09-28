# Phase 9D — L'année scolaire : créer, désigner, clôturer, rouvrir

**Date** : 28 septembre 2026

---

## Ce que l'état des lieux a révélé

J'ouvrais la 9D sur les archives. L'inventaire a montré autre chose.

```
  academic_year.view     0 route
  academic_year.manage   0 route
  academic_year.close    0 route
  archive.view           0 route
```

**Quatre permissions dormantes, pas une.** Et pendant ce temps :

```
$ grep -rn "'closed'" app/modules/*/services.php | wc -l
14
```

**Quatorze gardes, répartis dans sept modules** — élèves, classes,
enseignants, référentiel, présences, bulletins, finances — refusent
d'écrire quand l'année porte `closed` ou `archived`. Le module Finances
l'écrivait même noir sur blanc :

> « Il n'existe pas encore d'écran de clôture (il viendra avec la phase
> 10). Le garde-fou est posé MAINTENANT, avant que l'écran n'arrive. »

Le produit entier était bâti autour d'un état que **rien ne permettait
d'atteindre**. Pire : une école ne pouvait pas créer sa **deuxième**
année scolaire autrement qu'en base.

> Un état que sept modules font respecter et qu'aucun écran ne sait
> poser n'est pas une protection : c'est une impasse.

Ce n'est pas un confort manquant — **la plateforme ne passait pas son
premier mois de juillet.** La 9D est donc le cycle de vie de l'année ;
le registre des sortants suivra en 9E.

---

## Le piège mesuré avant d'écrire une ligne

```
  is_current = 0    · 2018-2019 → créée
  is_current = 0    · 2019-2020 → ✗ REFUSÉE (Duplicate entry)
  is_current = NULL · 2016-2017 → créée
  is_current = NULL · 2017-2018 → créée
```

`uq_year_current (school_id, is_current)` est **UNIQUE**, et
`is_current` est nullable. En MySQL, `NULL` n'entre pas en collision ;
`0` si. Enregistrer les années non courantes avec `0` faisait refuser la
seconde : **une école n'aurait pas pu enregistrer son troisième
exercice.**

C'est un détail de schéma qui décide du sort du module. Il est verrouillé
par un test qui crée trois années de suite.

---

## Ce que la phase livre

| Écran | Chemin | Permission |
|---|---|---|
| Liste des années | `GET /annees` | `academic_year.view` |
| Créer | `POST /annees` | `academic_year.manage` |
| Modifier | `POST /annees/{id}` | `academic_year.manage` |
| Désigner courante | `POST /annees/{id}/courante` | `academic_year.manage` |
| Supprimer (vide) | `POST /annees/{id}/supprimer` | `academic_year.manage` |
| Clôturer | `POST /annees/{id}/cloturer` | `academic_year.close` |
| Rouvrir | `POST /annees/{id}/rouvrir` | `academic_year.close` |

**Aucune migration.** Les permissions existaient, les colonnes
`status`, `closed_at`, `closed_by` aussi. La 9D n'ajoute pas une colonne.

---

## Les décisions qui engagent

### Clôturer fige l'écriture, jamais la lecture

Les bulletins restent consultables, les documents délivrables, le
parcours de l'élève intact. Rien n'est supprimé, rien n'est déplacé.
Le test le vérifie dans les deux sens : l'inscription est refusée,
la lecture passe.

### On ne retire pas le sol sous les pieds d'une école

Douze endroits du produit lisent `is_current = 1`. Clôturer l'année
courante sans lui désigner de remplaçante laisserait l'école devant des
écrans vides : ni inscription, ni classe, ni encaissement.

**C'est le seul refus du module**, et il protège tout le reste. L'ordre
imposé est le naturel : créer l'année suivante, la désigner courante,
puis clôturer la précédente.

Symétriquement, la **toute première** année créée devient courante
d'office : sans elle, une école nouvellement ouverte trouverait un
produit muet.

### L'écran dit ce que la clôture fige, avant de la laisser faire

Le bouton n'apparaît qu'après un compte rendu : élèves inscrits, classes,
bulletins, **élèves sans classe, bulletins sans décision, cotes
manquantes, dossiers avec impayés** — ces quatre derniers mis en
évidence quand ils ne sont pas nuls.

> Une décision irréversible qu'on prend sans voir ce qu'elle fige n'est
> pas une décision, c'est un pari.

L'écran ne **refuse** pas pour autant : une école peut avoir de bonnes
raisons de clôturer avec des impayés, et ce n'est pas au logiciel d'en
juger. Il doit seulement s'assurer que personne ne signe sans savoir.

Ce compte rendu entre dans le journal avec la clôture : des années plus
tard, il expliquera un bulletin sans décision retrouvé dans un dossier.

### La réouverture exige un motif

Rouvrir rend de nouveau modifiables des bulletins signés et des écritures
comptables arrêtées. Un motif d'au moins dix caractères est exigé **côté
serveur**, pas seulement par l'attribut `minlength` du navigateur — la
recette poste directement pour le prouver. Sans motif, la trace dirait
*que* c'est arrivé, jamais *pourquoi*.

C'est l'échappatoire que le module Finances réclamait déjà : « jamais une
exception silencieuse dans le module finances ».

### Les impayés se lisent sur la même assiette que l'écran dédié

`student_fees` ne porte **pas** de colonne « montant payé » : ce qui a
été réglé vit dans `payment_allocations`, et le net dû vaut
`amount_due - discount_amount`. J'avais d'abord écrit `sf.amount_paid` et
`sf.status` — **deux colonnes qui n'existent pas**. Reprendre la formule
de `finance_repo_outstanding()` est la seule façon que les deux écrans ne
racontent pas deux histoires.

---

## Un défaut trouvé par la recette elle-même

La recette navigateur créait une année et ne la retirait pas. **Au second
passage, elle mentait** : « l'année est créée » passait sur la ligne
laissée par le passage précédent, puis l'assertion suivante tombait.

> Une recette qui ne nettoie pas ce qu'elle crée finit par se mentir à
> elle-même.

Derrière, un vrai manque du produit : **rien ne permettait de retirer une
année créée par erreur** — une faute de frappe dans le code, des dates
inversées, et elle restait là pour toujours.

### La suppression est refusée en PHP, table par table

Neuf tables sont en `ON DELETE CASCADE` sur `academic_years` :

```
classrooms · curriculums · enrollments · expenses · fees
grade_periods · orientations · teacher_subjects
```

Confier la suppression à la base effacerait tout cela **sans un mot**.
Seuls les `documents` sont protégés par un `RESTRICT`.

> Une suppression qu'on confie à la base efface ce que la base sait
> relier, pas ce que l'école accepterait de perdre.

Le refus est donc posé en PHP, et il **nomme** ce qui bloque :
« Cette année n'est pas vide : elle porte 1 inscription(s). » Une année
courante ne se supprime pas ; une année clôturée non plus — elle est la
mémoire d'un exercice achevé. La trace est écrite *avant* la suppression,
puisque après, la ligne n'existe plus.

La recette est désormais **rejouable** : trois passages consécutifs,
29 vérifications chacun.

---

## Vérification

| | |
|---|---|
| `tests/years_lifecycle.php` | **56 tests** |
| `tests/annees_browser.js` | **29 vérifications**, rejouable |
| Total PHP | **1 220**, 23 suites, 0 échec |
| Total navigateur | **184**, 9 recettes, 0 échec |
| Installation depuis zéro | ✓ |

Ce que le test PHP démontre, et que seule l'exécution pouvait établir :

- une école crée sa deuxième **et sa troisième** année ;
- deux périodes ne se chevauchent jamais ;
- clôturer l'année courante est refusé, et elle reste courante ;
- après clôture, **`students_service_enroll_new()` refuse réellement** —
  le garde d'un autre module, joué et non supposé ;
- la lecture, elle, passe toujours ;
- la réouverture sans motif est refusée, avec motif elle est journalisée
  **avec ce motif** ;
- l'école B ne voit, ne clôture, ne rouvre aucune année de A, et peut
  reprendre le même code — l'unicité est par école.

---

## Fichiers

```
app/modules/years/repositories.php        (nouveau)
app/modules/years/services.php            (nouveau)
app/modules/years/controllers.php         (nouveau)
app/views/years/index.php                 (nouveau)
app/views/partials/sidebar.php            (lien + pastille « aucune année courante »)
app/config/routes.php                     (7 routes)
tests/years_lifecycle.php                 (nouveau, 56)
tests/annees_browser.js                   (nouveau, 29)
```

---

## Ce qui reste ouvert

1. **`archived` n'est posé par aucun chemin.** L'énumération le prévoit,
   les quatorze gardes le traitent comme `closed`. Tant qu'il ne se
   distingue par rien de concret, l'exposer n'ajouterait qu'une question
   sans réponse.
2. **Pas de passage de classe en masse.** Réinscrire une classe entière
   dans l'année suivante reste élève par élève. C'est le geste de
   rentrée le plus répétitif qui subsiste.
3. **La clôture ne fige pas les données, elle les protège.** Un bulletin
   reste calculé à la lecture ; seule la délivrance d'un document le
   fige. Suffisant aujourd'hui, à revoir si une école conteste un
   bulletin après une réorganisation du référentiel.
4. **`archive.view` reste dormante** — le registre des sortants est la
   phase 9E.
5. **`email_messages` n'est toujours pas purgé.**
