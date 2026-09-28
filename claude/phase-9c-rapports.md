# Phase 9C — Rapports et exports

**Date** : 22 septembre 2026

---

## Ce que la phase ouvre

`report.academic` et `report.export` étaient semées depuis la phase 1 et
n'ouvraient **aucun écran**. Trois rapports, une permission d'export
distincte, et un fichier qu'Excel ouvre sans manipulation.

| Écran | Chemin | Permission |
|---|---|---|
| Effectifs | `/rapports/effectifs` | `report.academic` |
| Résultats | `/rapports/resultats` | `report.academic` |
| Assiduité | `/rapports/assiduite` | `report.academic` |
| Export CSV | `?export=csv` sur chacun | `report.export` |

**Aucune migration.** Les permissions existaient, les tables aussi.
La 9C n'ajoute pas une colonne.

---

## La décision qui engage : le format d'export

Le cahier des charges demande « export PDF/Excel ». Le produit s'interdit
Composer — hébergement cPanel, PHP procédural. Ni PhpSpreadsheet, ni
TCPDF.

### Ce qui est livré

- **Excel** → un **CSV** avec BOM UTF-8 et séparateur `;`. Excel
  francophone l'ouvre en double-cliquant : colonnes séparées, accents
  intacts, décimales à la virgule. C'est le motif déjà éprouvé par
  l'export des impayés (phase 5) — la 9C s'aligne dessus plutôt que
  d'inventer un second mécanisme.
- **PDF** → la **feuille imprimable**. L'école imprime depuis son
  navigateur, qui sait produire un PDF depuis dix ans. La page perd ses
  commandes, gagne un en-tête d'édition (école, objet, date) et répète
  les en-têtes de colonnes à chaque page.

### L'alternative écartée, et pourquoi

Écrire un générateur XLSX à la main est faisable : l'extension `zip` est
présente, un XLSX n'est qu'une archive de XML. Cela ferait environ trois
cents lignes de plus à maintenir pour gagner la mise en forme et les
formules — dont aucune école n'a exprimé le besoin.

> Une technologie qu'on ajoute « parce qu'elle est plus propre » sans
> besoin exprimé est une dette qu'on choisit.

**Ce choix n'est pas gravé.** Si des feuilles multiples ou des formules
deviennent nécessaires, il se rediscute.

---

## Trois défauts trouvés par exécution, avant livraison

### 1. Une colonne inventée — `curriculums.level_id`

Trois requêtes la citaient. La colonne s'appelle
`education_level_id`. Trouvé en exécutant, jamais à la relecture.

### 2. Les effectifs ne s'additionnaient pas

La jointure écarte les élèves archivés (`deleted_at`), mais `effectif` ne
testait que le statut de l'inscription. Mesuré sur le décor de
démonstration, après archivage d'une élève :

```
  AVANT : G=4 + F=4 = 8 · effectif annoncé = 8   ✓
  APRÈS : G=4 + F=3 = 7 · effectif annoncé = 8   ✗
```

> Un état d'effectifs qui ne s'additionne pas est un état qu'on ne peut
> remettre à personne.

Corrigé par `s.id IS NOT NULL` : on compte les inscriptions dont l'élève
est réellement là.

### 3. La même faute, ailleurs — la jointure externe

`sans_decision` testait seulement `b.decision IS NULL`. Les élèves **sans
aucun bulletin**, que le `LEFT JOIN` rend avec toutes les colonnes à
NULL, y étaient comptés : sur une classe de 8 élèves dont 5 ont un
bulletin, le rapport annonçait 5 bulletins dont 5 sans décision.

> Dans une jointure externe, « la colonne est NULL » et « la ligne
> n'existe pas » se ressemblent au point de se confondre.

C'est le test qui l'a trouvé, en vérifiant que
`bulletins = décidés + sans décision`.

---

## Ce que les rapports refusent de faire

### Un bulletin sans décision n'est pas un échec

`bulletins.decision` vaut NULL tant que le conseil n'a pas statué. Le
taux de réussite porte sur les seuls bulletins **décidés**, et l'écran
dit combien ne le sont pas. Un taux sans dénominateur affiche un tiret,
jamais « 0 % ».

> Un indicateur qui confond « pas encore décidé » et « refusé » ne mesure
> pas la réussite, il mesure l'avancement du conseil.

### Pas de colonne « Publiés »

`bulletins.published_at` est `NOT NULL` avec `CURRENT_TIMESTAMP` pour
défaut : une ligne n'existe qu'une fois le bulletin publié. La colonne
aurait toujours égalé le nombre de bulletins.

> Une colonne qui ne peut pas différer d'une autre n'est pas un
> indicateur, c'est une décoration.

Elle était écrite ; elle a été retirée.

### Pas de moyenne générale d'école

La moyenne des moyennes de classe n'est pas la moyenne de l'école tant
que les classes n'ont pas le même effectif. Le pied de tableau affiche un
tiret et la page le dit.

### Un retard reste une présence

L'élève est là. Le compter en absence gonflerait l'absentéisme d'un
établissement ponctuel mais mal desservi. Le taux rapporte présences et
retards au nombre de **pointages réellement effectués** — une séance non
pointée n'invente pas d'absents.

### L'assiduité se découpe par DATES, pas par période

`grade_periods.starts_on` et `ends_on` ne sont jamais remplis — dette
connue, inscrite dans l'état du projet. Un découpage par période
supposerait des bornes qui n'existent pas ;
`attendance_sessions.session_date` est une donnée réelle.

> Mieux vaut un découpage que la donnée permet qu'un découpage que la
> donnée n'a jamais porté.

---

## Un export est une sortie de données : il se trace

`report.exported` s'inscrit au journal avec le rapport, l'année et le
nombre de lignes. Un fichier d'effectifs quitte le produit et vit sa vie.

> Une donnée qui sort sans laisser de trace est une donnée dont on perd
> la garde au moment précis où elle devient copiable.

### Lire et exporter sont deux pouvoirs distincts

`report.export` est vérifiée **dans le contrôleur**, pas sur la route :
c'est la même route qui sert l'écran et le fichier, selon `?export=csv`.
La mettre sur la route fermerait aussi la consultation.

Le COMPTABLE porte `report.export` sans `report.academic` — ses propres
états financiers relèvent de `report.financial`.

---

## Une règle remontée dans le noyau

`date_filtre()` — forme `Y-m-d` exigée **et** date qui existe
(`checkdate` refuse le 31 février, que `strtotime` reporterait au 3 mars)
— est née dans le module Journal en 9B. Le module Rapports en avait
besoin à l'identique.

> Deux copies de la même règle divergent toujours, et c'est la plus
> laxiste qu'on finit par emprunter.

Elle vit désormais dans `app/core/helpers.php` ; `audit_date_valide()`
reste comme porte d'entrée du module Journal et point d'ancrage de ses
tests.

---

## Vérification

| | |
|---|---|
| `tests/reports_isolation.php` | **55 tests** |
| `tests/rapports_browser.js` | **21 vérifications** |
| Total PHP | **1 145**, 22 suites, 0 échec |
| Total navigateur | **155**, 8 recettes, 0 échec |
| Installation depuis zéro | ✓ |

Ce que la recette navigateur prouve, et que le PHP ne peut pas :

- sur **chaque ligne rendue**, garçons + filles = effectif ;
- le total du pied égale la somme des lignes affichées ;
- le fichier téléchargé commence bien par `EF BB BF` ;
- **le CSV totalise comme l'écran** — un export qui recalcule finit par
  dire autre chose ;
- à l'impression, les commandes disparaissent, l'en-tête d'édition
  apparaît, et la ligne de totaux garde son fond ;
- un enseignant reçoit 403 sur l'écran **et** sur l'export.

---

## Fichiers

```
app/core/helpers.php                      (+ date_filtre, règle partagée)
app/modules/audit/repositories.php        (délègue au noyau)
app/modules/reports/repositories.php      (nouveau)
app/modules/reports/services.php          (nouveau)
app/modules/reports/controllers.php       (nouveau)
app/views/reports/_nav.php                (nouveau)
app/views/reports/headcount.php           (nouveau)
app/views/reports/results.php             (nouveau)
app/views/reports/attendance.php          (nouveau)
app/views/partials/sidebar.php            (lien Rapports)
app/config/routes.php                     (4 routes)
public/assets/css/app.css                 (impression : .no-print, .print-only)
tests/reports_isolation.php               (nouveau, 55)
tests/rapports_browser.js                 (nouveau, 21)
```

---

## Ce qui reste ouvert

1. **Pas de rapport financier consolidé.** Le journal de caisse et les
   impayés existent depuis la phase 5 ; un état de synthèse
   recettes/dépenses par période reste à faire.
2. **Pas d'évolution dans le temps.** Les trois rapports donnent un
   instantané ; comparer deux années demanderait un second axe.
3. **Pas de rapport par élève.** Le dossier de l'élève porte déjà son
   parcours ; un état imprimable par élève relèverait des archives (9D).
4. **`archive.view` reste dormante** — phase 9D.
5. **Le format d'export reste le CSV.** Voir la décision ci-dessus : à
   rediscuter si des formules ou des feuilles multiples deviennent
   nécessaires.
