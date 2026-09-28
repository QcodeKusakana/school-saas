# Audit de la phase 9D — l'année scolaire

**Date** : 28 septembre 2026
**Méthode** : audit **par exécution**. Cet audit n'a trouvé **aucun
défaut dans le produit**. Il en a trouvé trois dans mes propres sondes,
et il a transformé une affirmation en vérification.

---

## Le contrôle qui prime

```
bash tests/installation_zero.sh --oui
```

→ 78 permissions, 310 role_permissions. **Le produit s'installe toujours
depuis zéro.**

---

## Vérification 1 — l'année courante sous concurrence réelle

La 9D repose sur un invariant : **exactement une année courante, à tout
instant**. Douze endroits du produit lisent `is_current = 1` ; s'il n'y
en avait aucune, l'école n'aurait plus d'écran de travail.

L'index `uq_year_current` garantit qu'il n'y en aura jamais *deux*. Rien
ne garantissait qu'il en resterait *une*.

**Mesuré** : deux processus basculent l'année courante en alternance,
80 fois chacun, pendant qu'un troisième lit en continu.

```
  acteur 1 : 80 bascules, 0 erreur
  acteur 2 : 80 bascules, 0 erreur
  6 000 observations pendant la course
  ✓ à CHAQUE instant, exactement une année courante
```

La transaction — libérer l'ancienne, poser la nouvelle — et l'index
unique tiennent ensemble. Le verrou de ligne InnoDB sérialise les deux
transactions ; aucune fenêtre ne laisse l'école sans année.

---

## Vérification 2 — « sept modules », enfin éprouvé

La phase affirmait que quatorze gardes, dans sept modules, refusent
d'écrire sur une année clôturée. **Le test livré n'en jouait qu'un** :
l'inscription d'un élève.

> Une affirmation qui porte sur sept modules et n'en éprouve qu'un n'est
> pas vérifiée : elle est échantillonnée.

Onze gestes, joués sur une vraie année clôturée avec un décor réel :

| Module | Geste | Verdict |
|---|---|---|
| élèves | inscrire un nouvel élève | refusé |
| élèves | affecter un élève à une classe | refusé |
| enseignants | affecter un enseignant | refusé |
| enseignants | désigner un titulaire | refusé |
| référentiel | créer un programme | refusé |
| présences | ouvrir une séance de pointage | refusé |
| bulletins | publier | refusé |
| bulletins | poser une décision de conseil | refusé |
| finances | encaisser un paiement | refusé |
| finances | enregistrer une dépense | refusé |

**Onze sur onze**, et chacun avec un message qui **nomme la clôture**.
C'est désormais dans la suite de tests, plus dans une sonde jetable.

---

## Trois défauts, tous dans mes sondes

### 1. Une sonde jouée sur un décor vide

La première version prenait `years_repo_current()` — qui pointait sur
une année **vide** laissée par la sonde de concurrence. Six gestes
« passaient », non parce que la clôture les autorisait, mais parce que
les identifiants n'existaient pas.

> Une sonde qui s'exécute sur un décor vide ne mesure pas le produit,
> elle mesure le vide.

**J'ai failli déposer six faux défauts.**

### 2. Un verdict qui ne disait pas pourquoi

Corrigée, la sonde donnait toujours six « acceptés ». En faisant parler
chaque cas, la cause est apparue : **mes propres erreurs d'arguments** —
mauvaise arité, mauvais types, une sous-requête ambiguë.

> Un verdict qui ne dit pas POURQUOI ne distingue pas un refus de
> clôture d'un appel mal formé.

Les signatures réelles lues, les onze gestes passent au refus motivé.

### 3. Un test qui montait son décor après la clôture

Porté dans la suite, le contrôle échouait sur un 404 : le décor —
programme, classe, élève, enseignant — se construisait **après** la
clôture, donc ne pouvait rien créer. C'est exactement ce que la clôture
doit faire ; encore fallait-il l'éprouver dans le bon ordre.

Au passage, deux colonnes inventées dans ce décor : `teachers.hired_on`
(c'est `hire_date`) et un `uuid` oublié. Et une clé de regroupement
écrite en dur — `T1` — alors qu'elle **dépend du cycle de la classe** :
le refus tombait pour « Regroupement inconnu », ce qui n'aurait rien
prouvé sur la clôture. Le test demande désormais au produit la clé qu'il
reconnaît.

---

## Ce que l'audit a confirmé sans rien corriger

- **`fk_years_closed_by` est en `ON DELETE SET NULL`**, et les comptes ne
  sont jamais supprimés pour de bon (seulement `deleted_at`). L'identité
  de celui qui a clôturé survit, et le journal la garde de toute façon.
- **Le garde-fou des finances existe bien**, sous un autre nom :
  `finance_year_is_open()` / `finance_year_closed_message()`. Le
  commentaire de la phase 5 disait vrai.
- **Une `abort()` dans un test court-circuite le bloc `finally`** et
  laisse l'école de test en base. Constaté, nettoyé ; à garder en tête
  pour les suites futures.

---

## Vérification

| | |
|---|---|
| `tests/years_lifecycle.php` | 56 → **66 tests** |
| Total PHP | **1 230**, 23 suites, 0 échec |
| Total navigateur | **184**, 9 recettes, 0 échec |
| Installation depuis zéro | ✓ |

---

## Fichiers

```
tests/years_lifecycle.php    (56 → 66 : les onze gestes, sept modules)
claude/audit-phase-9d.md     (ce document)
```

Aucun fichier du produit n'a été modifié : **l'audit n'a rien trouvé à
corriger dans la 9D.**

---

## Ce qui reste ouvert

1. **La concurrence n'est éprouvée que sur la désignation de l'année
   courante.** Clôture et réouverture simultanées n'ont pas été jouées ;
   elles portent moins de risque — chacune vise une année distincte et
   l'index n'entre pas en jeu — mais ce n'est pas mesuré.
2. **Les quatorze gardes ne sont pas tous couverts** : onze gestes en
   couvrent les sept modules, pas les quatorze points d'entrée.
   `students_service_re_enroll` et `teachers_service_unassign` restent
   hors du test.
3. Les points ouverts de la 9D demeurent : pas de passage de classe en
   masse, `archived` jamais posé, `archive.view` dormante (phase 9E),
   `email_messages` non purgé.
