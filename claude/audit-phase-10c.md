# Audit de la phase 10C — l'effacement laissait des noms derrière lui

**Date** : 28 septembre 2026
**Méthode** : audit **par exécution**. Un défaut réel trouvé dans le
produit livré, et un garde-fou trop lâche resserré.

---

## La question posée

L'outil et sa recette comptaient tous deux les tables portant
`school_id`. C'est exactement le même angle mort : **ce qui ne porte pas
`school_id` était invisible des deux côtés.**

> Un inventaire qui n'énumère que ce qu'il sait compter ne mesure pas ce
> qui reste.

J'ai donc énuméré les **dix-huit** tables qui n'en portent pas, et
cherché celles qui gardent une donnée personnelle.

---

## Défaut — `login_attempts` survivait à l'effacement

```
  password_resets → users   CASCADE   ✓ emportée
  user_roles      → users   CASCADE   ✓ emportée
  login_attempts  → (aucune clé étrangère)
```

`login_attempts` ne porte **ni `school_id` ni clé étrangère**. Aucun
CASCADE ne l'emporte. Et elle garde :

```
  identifier         ip            successful   created_at
  directeur.demo     127.0.0.1     1            2026-09-28 13:52:55
  enseignant.demo    127.0.0.1     1            2026-09-28 13:53:03
```

L'identifiant du compte **et** l'adresse IP : deux données personnelles.

**Mesuré sur un effacement réel :**

```
  ✗ Les tentatives de connexion de cette école sont parties
    — 3 tentative(s) restante(s), avec identifiant et adresse IP
```

L'effacement se déclarait complet et ne l'était pas.

### La correction

Les identifiants sont **uniques sur toute la plateforme**
(`uq_users_username`, `uq_users_email`) : effacer par identifiant ne peut
donc pas emporter les tentatives d'une autre école. Vérifié avant
d'écrire la requête.

```sql
DELETE la FROM login_attempts la
  JOIN users u ON la.identifier = u.username OR la.identifier = u.email
 WHERE u.school_id = :s
```

Et cela **précède** la suppression des comptes — après, il n'y aurait
plus rien à joindre.

Le contrôle symétrique donne son sens au premier :

```
  ✓ Les tentatives de connexion de cette école sont parties
  ✓ … tandis que celles de l'école voisine restent
```

---

## Resserrement — l'opérateur n'était pas vérifié

N'importe quelle chaîne était acceptée et recopiée dans la trace. Or
cette trace est ce qui répondra, dans dix ans, à « qui a effacé cet
établissement ».

> Une responsabilité qu'on saisit au clavier sans la vérifier n'est pas
> une responsabilité, c'est une mention.

L'objection « il a déjà un accès au serveur, il pourrait écrire en base »
est vraie et ne change rien : **rendre le contournement explicite vaut
mieux que l'offrir dans le formulaire.**

```
  ✓ Un opérateur inventé : refusé
  ✓ … et rien n'a bougé
```

---

## Un constat, et la décision de ne rien faire

`user_sessions.school_id` n'est en tête d'aucun index : le `COUNT(*)` de
l'inventaire y fait un parcours complet.

**Je n'ajoute pas l'index.** Il coûterait à chaque écriture de session —
c'est-à-dire à chaque connexion de chaque utilisateur de chaque école —
pour accélérer une lecture faite une fois par an, sur une table dont les
lignes sont éphémères.

> Un index se paie à l'écriture et se touche à la lecture ; quand le
> rapport est de mille à un, il ne se pose pas.

---

## Ce que l'audit a confirmé sans rien corriger

- **`password_resets` et `user_roles`** sont bien emportés par le
  CASCADE depuis `users`.
- Les quinze autres tables sans `school_id` sont du **référentiel**
  (cycles, niveaux, matières nationales, permissions, plans) ou les deux
  tables de la 10C elles-mêmes : aucune donnée personnelle.

---

## Vérification

| | |
|---|---|
| `tests/school_erasure.php` | 44 → **47 tests** |
| Total PHP | **1 395**, 26 suites, 0 échec |
| Total navigateur | **190**, 0 échec |
| Installation depuis zéro | ✓ |

---

## Fichiers

```
database/erase_school.php   (login_attempts ; opérateur vérifié)
tests/school_erasure.php    (44 → 47)
claude/audit-phase-10c.md   (ce document)
```

Aucune migration.

---

## Ce qui reste ouvert

1. **L'effacement n'a pas été mesuré sur un gros volume.** Le décor
   compte quelques dizaines de lignes ; une école de dix ans en compte
   des centaines de milliers, effacées en une seule transaction. Rien
   n'indique un problème — InnoDB encaisse —, mais ce n'est pas mesuré.
2. Les points ouverts de la 10C demeurent : pas de demande depuis
   l'interface, les sauvegardes antérieures contiennent toujours
   l'établissement, et rien n'efface un élève seul.
