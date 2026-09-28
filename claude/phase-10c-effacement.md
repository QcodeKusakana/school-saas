# Phase 10C — L'effacement d'un établissement

**Date** : 28 septembre 2026

C'était la dette la plus lourde du projet avant commercialisation : un
établissement ne pouvait pas quitter la plateforme. Elle a attendu la
10B, et c'était juste — **on ne supprime pas ce qu'on ne sait pas
rendre.**

---

## Ce que le schéma a dicté

Rien n'a été supposé. Trois mesures ont commandé toute la conception.

### 1. Quarante-deux tables en cascade

```
  42 tables en ON DELETE CASCADE depuis `schools`
```

Un simple `DELETE FROM schools` emporterait donc **aussi**
`subscriptions` et `subscription_payments` : la comptabilité de
l'**éditeur**.

> Le droit à l'effacement d'un client n'efface pas les écritures
> comptables de son fournisseur.

Elle est donc **archivée avant** le DELETE, dans `billing_archive` —
montant, devise, date, moyen, référence, et le nom de l'établissement,
qui est celui d'une personne morale.

### 2. Le journal survivrait

```
  audit_logs : 0 clé étrangère vers schools
```

Ses lignes ne sont pas emportées par le CASCADE. Et le code du produit
dit ce qu'elles portent :

```php
audit_log('user.update', 'users', $userId, [
    'last_name' => …, 'first_name' => …, 'email' => …,
]);
```

La liste `AUDIT_REDACTED_FIELDS` le confirme par l'absurde : **on ne
masque que ce qui serait stocké autrement.**

> Un effacement qui épargne le journal n'efface rien.

Le journal de l'école est donc effacé explicitement, et remplacé par
**une** entrée au journal de la plateforme (`school_id = NULL`) qui dit
qu'un effacement a eu lieu — sans dire sur qui.

### 3. Les visages restent sur le disque

`storage/uploads/photos/<école>/`, `logos/<école>/`. Une base effacée
qui laisse les photos d'élèves sur le disque n'efface rien non plus.

---

## Ce n'est pas un bouton

Ce geste est rare, irréversible, et il porte sur les dossiers scolaires
de centaines de mineurs.

> Ce qui ne se rattrape pas ne s'expose pas à un clic.

Un écran web qui détruit un établissement est un écran qu'on clique par
erreur — et qu'une session volée clique très volontiers. L'outil exige
donc un accès au serveur.

La permission `platform.school.erase` n'est donnée à **aucun rôle
d'école** : un directeur ne détruit pas le dossier scolaire de ses
élèves, et un compte de direction volé encore moins.

---

## Les quatre verrous, avant la moindre écriture

| | |
|---|---|
| **Résiliation** | On n'efface pas une école active ni suspendue. La résiliation est un palier réversible entre « nous arrêtons » et « tout est perdu ». |
| **Sauvegarde** | De moins de 24 h, lisible, de la même version du produit, **et contenant cet établissement**. |
| **Le code, retapé** | À la main, comme `install.php --fresh`. |
| **Un motif** | Dix caractères minimum, comme la réouverture d'année en 9D. |

Et `--simuler` montre l'inventaire complet sans rien écrire.

### Un garde-fou que j'ai dû desserrer — en l'expliquant

La première version rejouait `restore.php --verifier`, qui compare la
base **entière** aux empreintes du manifeste. Sur une plateforme vivante,
c'est inapplicable : une simple connexion d'une **autre** école, entre la
sauvegarde et l'effacement, fait diverger une empreinte et bloque
l'opération.

> Un garde-fou qu'on ne peut pas satisfaire n'est pas franchi : il est
> contourné.

Le contrôle porte donc sur ce qui fait d'une archive un chemin de retour
**pour cette école** : lisible, même version, récente, et **son
identifiant y figure**. Une archive impeccable d'une base où
l'établissement n'était pas encore inscrit n'est pas un retour.

---

## Tout en une transaction, et les fichiers en dernier

Un effacement interrompu au milieu laisserait des bulletins sans élèves
et des paiements sans inscriptions : pire que l'état de départ **et**
pire que l'état d'arrivée.

Les fichiers, eux, partent **après** la validation. Une transaction
annulée rend les lignes ; elle ne rend pas les fichiers. L'ordre inverse
laisserait une base intacte pointant vers des photos disparues.

---

## Deux défauts trouvés par la recette

### 1. Le script ne s'exécutait pas du tout

```
Undefined constant "ERASE_TABLES_COMPTABLES"
```

PHP hisse les **déclarations de fonction**, jamais les `const`. Posées en
bas de fichier, après le `exit()` final, elles n'étaient jamais définies.
Trouvé par la recette, pas par la relecture.

### 2. Ma sonde cherchait un nom partagé

L'assertion « aucun nom ne subsiste » échouait. Cause : mon décor donnait
**le même nom** au journal des deux écoles. Je cherchais donc une trace
qui appartenait aussi à l'école survivante.

> Une sonde qui cherche une trace partagée ne mesure pas l'effacement,
> elle mesure le voisinage.

Corrigée, elle porte maintenant un contrôle **symétrique**, qui lui donne
tout son sens : le nom de l'effacée est parti, celui de la voisine est
intact.

---

## Le contrôle qui prime

```
  L'école voisine
  ✓ Elle est toujours là
  ✓ Elle n'a pas perdu une ligne — 3 contre 3
  ✓ Son journal est intact
  ✓ Ses fichiers sont intacts
  ✓ Sa comptabilité n'a pas été archivée
```

---

## Vérification

| | |
|---|---|
| `tests/school_erasure.php` | **44 tests** (nouveau) |
| Total PHP | **1 392**, 26 suites, 0 échec |
| Total navigateur | **184**, 9 recettes, 0 échec |
| Installation depuis zéro | ✓ — 79 permissions, 311 role_permissions |

---

## Fichiers

```
database/migrations/2026_09_28_035_phase10c_effacement_ecole.sql  (nouveau)
database/erase_school.php                                         (nouveau)
tests/school_erasure.php                                          (nouveau, 44)
docs-projet/etat-du-projet.md
claude/phase-10c-effacement.md                                    (ce document)
```

---

## Ce qui reste ouvert

1. **Aucune demande d'effacement depuis l'interface.** L'éditeur doit
   avoir un accès au serveur. Une file de demandes, avec un délai de
   grâce, serait le prolongement naturel — et le délai serait une
   protection de plus.
2. **L'effacement ne traite que la base et les fichiers.** Les
   sauvegardes prises **avant** contiennent toujours l'établissement.
   C'est voulu — ce sont elles, le chemin de retour — mais cela veut
   dire que l'effacement n'est complet qu'une fois ces sauvegardes
   expirées. Il faudra une politique de rétention, et la dire au client.
3. **Rien n'efface un élève seul.** Un parent qui demanderait le retrait
   du dossier de son enfant n'a pas d'outil : le produit ne sait effacer
   qu'un établissement entier.
4. Les sauvegardes restent sur le serveur ; `backup.php` n'est pas
   planifié ; la sauvegarde n'est pas chiffrée.
