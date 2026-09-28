# Audit de la phase 10B — la sauvegarde était un collage, pas une photo

**Date** : 28 septembre 2026
**Méthode** : audit **par exécution**. Un défaut grave trouvé, dans le
produit livré la veille.

---

## Le défaut : 61 tables, 61 instants

`backup.php` parcourt les tables l'une après l'autre, chacune par son
propre `SELECT`, **sans transaction**. Mesuré :

```
  SANS transaction : 14 ligne(s) avant, 15 après  ← DEUX INSTANTS
  AVEC instantané  : 15 ligne(s) avant, 15 après  ← un seul
```

Les tables sortent par ordre alphabétique. `classrooms` est donc lue
**avant** `enrollments`. Une classe créée entre les deux lectures, et une
inscription qui la vise, donnent une archive portant **l'inscription sans
la classe**.

> Une sauvegarde prise pendant que l'école travaille n'est pas une photo :
> c'est un collage, à moins qu'on ne l'exige autrement.

### Et la restauration ne l'aurait pas vu

La deuxième moitié du défaut, mesurée séparément :

```
  contrôles suspendus → INSERT enfant vers un parent inexistant → contrôles rétablis

  Lignes orphelines après rétablissement : 1
  → MySQL NE REVALIDE PAS l'existant.
  → Une écriture ultérieure passe aussi.
```

La restauration suspend les clés étrangères pendant le chargement — elle
le doit, aucun ordre d'insertion naïf ne tient. Mais MySQL ne revalide
rien quand on les rétablit. **La clé pendante reste, définitivement, sans
un mot.**

Pour le module financier, cela veut dire une allocation de paiement sans
son paiement : de l'argent affecté à rien, et la formule des impayés
fausse pour toujours.

### Une sauvegarde nocturne ne dispense pas du verrou

Un établissement qui a des surveillants, un comptable en heures décalées,
ou simplement un travail périodique, écrit la nuit.

---

## La correction

`backup_ouvrir_instantane()` pose `REPEATABLE READ` puis
`START TRANSACTION WITH CONSISTENT SNAPSHOT`. L'isolation est **posée**,
pas héritée du défaut de MySQL : on ne s'appuie pas sur une valeur par
défaut pour une garantie d'intégrité.

L'instantané couvre **aussi le calcul des empreintes**. S'il ne couvrait
que l'écriture du SQL, le manifeste décrirait un autre moment que le
fichier qu'il accompagne — et la vérification dénoncerait un écart
inexistant, ou en validerait un qui existe.

Les 61 tables sont en InnoDB — vérifié —, condition de
`WITH CONSISTENT SNAPSHOT`.

**Rejoué sur le code corrigé :**

```
  pendant l'instantané : 14 puis 14  → UN SEUL INSTANT
  après fermeture      : 15          → la ligne redevient visible
```

---

## Ce que l'audit a éprouvé en plus

### Le texte, là où les exports écrits à la main cassent

Neuf pièges posés dans le décor, qui traversent `PDO::quote()`, le
fichier, `sql_split()`, puis MySQL :

| | |
|---|---|
| apostrophe | `O'Brien, dit l'Ancien` |
| antislash | `C:\laragon\www\school-saas` |
| point-virgule | `fin; DROP TABLE users; --` |
| commentaire SQL | `-- ceci n'est pas un commentaire` |
| accents | `Élève à Kinshasa — « guillemets »` |
| émoji (4 octets) | `école 👨‍🏫 rentrée 🎒` |
| retours à la ligne | `ligne 1\nligne 2\r\nligne 3` |
| tabulation | `avant\tapres` |
| chaîne vide | `` |

**Neuf sur neuf reviennent à l'identique.** Et deux distinctions que
beaucoup d'exports écrasent :

```
  ✓ NULL reste NULL
  ✓ … et la chaîne vide reste une chaîne vide
  ✓ Le découpage SQL n'a pas été trompé par « ; » ni par « -- »
```

### Les clés primaires composées

Vérifié : **aucune table du schéma n'en porte.** L'ordre imposé à
l'empreinte par une clé simple suffit donc, et rien ne doit être corrigé.

---

## Vérification

| | |
|---|---|
| `tests/backup_restore.php` | 39 → **46 tests** |
| Total PHP | **1 348**, 25 suites, 0 échec |
| Total navigateur | **184**, 9 recettes, 0 échec |
| Installation depuis zéro | ✓ |

---

## Fichiers

```
database/dump.php         (backup_ouvrir_instantane / backup_fermer_instantane)
database/backup.php       (l'instantané enveloppe le SQL ET les empreintes)
tests/backup_restore.php  (39 → 46 : l'instantané, les neuf textes, NULL vs vide)
claude/audit-phase-10b.md (ce document)
```

Aucune migration.

---

## Ce qui reste ouvert

1. **L'instantané allonge la transaction.** Sur une très grosse base, le
   journal d'annulation d'InnoDB enfle pendant la sauvegarde. C'est le
   prix que paie aussi `mysqldump --single-transaction` ; sans effet à
   l'échelle d'une école, à surveiller si la plateforme grossit.
2. **Les sauvegardes restent sur le serveur** — un disque qui meurt
   emporte la base et ses sauvegardes.
3. **`backup.php` n'est pas planifié** par l'installation.
4. **La sauvegarde n'est pas chiffrée** — à trancher avec le développeur :
   chiffrer ajoute le risque de perdre la clé *et* la sauvegarde.
5. La procédure d'effacement d'une école (RGPD) reste la dette la plus
   lourde — avec, désormais, son prérequis en place.
