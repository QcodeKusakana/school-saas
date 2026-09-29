# Audit de la phase 11A — la promesse non vérifiée, et la course au code

**Date** : 28 septembre 2026
**Méthode** : audit **par exécution**. Un défaut réel, une promesse
mensongère, et une affirmation que j'avais faite sans la mesurer.

---

## La promesse que je n'avais pas vérifiée

La phase affirmait :

> Créer un client, ce n'est pas créer sa fiche : c'est lui rendre le
> produit ouvrable.

La recette avait vérifié que les **lignes** existent. Elle n'avait jamais
ouvert la porte.

> Compter les lignes d'une école ne dit pas si quelqu'un peut y entrer.

Une sonde a donc créé une école par le service réel, s'est connectée avec
le compte qu'il produit, et a regardé ce que le produit répond :

```
  ✓ Son administrateur se connecte
  ✓ Le produit lui impose de changer son mot de passe
  ✓ Une année courante existe pour elle — 2026-2027
  ✓ Le référentiel national lui propose des niveaux — 8 niveau(x)
  ✓ Elle peut créer un programme
  ✓ Elle ne voit AUCUN élève d'une autre école — 0 sur 8 en base
```

**La promesse tient.** C'est maintenant dans la suite de tests, plus dans
une affirmation.

### Deux défauts de ma sonde, en chemin

1. `auth_attempt()` appelle `auth_login()`, qui **régénère l'identifiant
   de session** — impossible une fois les en-têtes envoyés, et en ligne
   de commande le moindre `echo` les « envoie ». Tamponner la sortie
   permet d'éprouver le vrai chemin plutôt qu'une approximation.
2. Ma requête d'isolation, `SELECT COUNT(*) FROM students`, a été
   **refusée par le garde multi-école** — c'est-à-dire que le produit a
   fonctionné. Une sonde qui contourne la protection qu'elle vient
   mesurer ne mesure rien.

---

## Défaut — deux créations simultanées se disputent le même code

`platform_prochain_code_ecole()` calcule `MAX+1`. J'avais écrit que
« la base tranche en dernier ressort » — sans jamais mesurer ce que
l'éditeur verrait.

**Seize créations simultanées, deux processus :**

```
  8 réussies, 8 REFUSÉES
  « SQLSTATE[23000] … Duplicate entry 'ECO-000002' for key 'schools.uq_schools_code' »
```

La donnée était sauve — c'est le rôle de la contrainte — mais **la
moitié des créations échouaient**, et l'éditeur recevait un message qui
lui parle d'une clé d'index. Il n'avait rien fait de mal : deux personnes
qui inscrivent un client en même temps, ou un simple double-clic,
suffisent.

> Une collision que la base sait résoudre ne doit pas remonter jusqu'à
> l'utilisateur, et surtout pas dans sa langue à elle.

### La correction

On rejoue, comme `db_transaction()` rejoue un interblocage depuis la
phase 5B — **même motif, même raison**, jusqu'à trois fois, avec la même
attente de 10 à 60 ms pour que les deux ne repartent pas ensemble.

Et l'on ne rejoue **que** la collision de code ou de slug : une violation
sur l'identifiant de l'administrateur se reproduirait à l'identique, et
la rejouer masquerait la vraie cause.

**Rejoué sur le code corrigé :**

```
  14 réussies, 2 refusées · 14 codes distincts sur 14 · aucun doublon
  « Plusieurs créations simultanées se sont disputé le même code.
    Réessayez : la saisie est conservée. »
```

Le message de MySQL ne remonte plus jamais jusqu'à l'écran : il nomme des
index, n'apprend rien à l'éditeur, et renseigne un visiteur mal
intentionné.

### Ma sonde, elle aussi, a mesuré le vide

Premier passage : **seize refus**… sur la règle de saisie de
l'identifiant, parce que mon étiquette de processus était une majuscule.
Zéro mesure de la concurrence. Le piège de la 9D, une fois de plus.

---

## Une promesse mensongère, corrigée

Le message de rejeu dit « la saisie est conservée ». **Elle ne l'était
pas** : le contrôleur ne posait pas `flash_old()`. L'éditeur aurait
retapé quinze champs pour une collision qui dure quelques millisecondes.

> Un message qui promet quelque chose que le code ne fait pas est pire
> qu'un message absent.

Le produit sait le faire depuis la phase 3 ; il n'y avait qu'à s'en
servir.

### Et la promesse n'était tenue qu'à moitié

`old()` ne rend que des **scalaires** — par conception. Pour les cases à
cocher des cycles, il rendait `''` et elles revenaient décochées.

`old_array()` s'ajoute donc à `app/core/flash.php`, et **partage le même
cache** que `old()`. Sans cela, le premier appelé viderait la session et
le second ne trouverait plus rien : un formulaire mêlant champs texte et
cases à cocher perdrait la moitié de sa saisie selon l'ordre de ses
lignes.

C'est un ajout au noyau, donc justifié ici : tout formulaire à choix
multiples en aura besoin.

---

## Vérification

| | |
|---|---|
| `tests/platform_school_lifecycle.php` | 46 → **57 tests** |
| Total PHP | **1 452**, 27 suites, 0 échec |
| Total navigateur | **208**, 10 recettes, 0 échec |
| Installation depuis zéro | ✓ |

---

## Fichiers

```
app/core/flash.php                     (flash_old_all + old_array)
app/modules/platform/services.php      (rejeu, message humain)
app/modules/platform/controllers.php   (flash_old — la promesse tenue)
app/views/platform/school_form.php     (cycles restitués)
tests/platform_school_lifecycle.php    (46 → 57)
claude/audit-phase-11a.md              (ce document)
```

Aucune migration.

---

## Ce qui reste ouvert

1. **Deux refus sur seize subsistent** sous forte contention. Trois
   rejeux espacés règlent ce qu'un éditeur humain produit ; une file
   d'attente serait la réponse si la plateforme inscrivait des écoles en
   lot, ce qui n'est pas son usage.
2. **Le slug suit le même calcul** que le code et court le même risque ;
   le rejeu le couvre, mais il n'a pas été mis en concurrence
   séparément.
3. Les points ouverts de la 11A demeurent : `pending` jamais posé, les
   cycles non modifiables après création, aucun courriel de bienvenue,
   et seize permissions encore dormantes.
