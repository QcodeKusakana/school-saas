# Audit de la phase 9A — documents officiels

**Date** : 22 septembre 2026
**Méthode** : audit **par exécution**. Chaque défaut ci-dessous a d'abord
été montré par une sonde qui le reproduit avec de vraies données, puis
corrigé, puis verrouillé par un test qui redevient rouge si la correction
disparaît.

---

## Le contrôle qui prime

```
bash tests/installation_zero.sh --oui
```

→ 4 cycles, 15 niveaux, 7 sections, 9 rôles, **77 permissions**,
308 role_permissions, 4 plans, et une école neuve créée puis retirée.
**Le produit s'installe toujours depuis zéro.**

---

## Défaut 1 — une fonction qui promettait un périmètre et n'en appliquait aucun

`app/modules/school/services.php`

```php
function platform_scope_cli_or_identity_school(callable $work): mixed
{
    return $work();           // rien d'autre
}
```

Deux écritures sur `schools` passaient par elle. Son nom laissait croire
que la question du périmètre multi-école avait été traitée. Elle ne
faisait rien.

**Correction** : fonction supprimée, appels directs, et le commentaire dit
désormais la vérité — `schools` n'a pas de `school_id` mais un `id`, le
garde-fou ne la couvre pas, et la seule protection est que la clause
porte `tenant_require()`, jamais un identifiant venu de la requête.

> Une fonction dont le nom promet un périmètre qu'elle n'applique pas est
> pire qu'un appel direct : elle fait croire que la question a été traitée.

---

## Défaut 2 — le cache des paramètres franchissait la frontière entre écoles

`app/core/settings.php`

```php
static $cache = null;               // UN seul cache, toutes écoles confondues
if ($cache !== null) return $cache; // tenant_set() ne l'invalidait pas
```

Tant qu'une requête HTTP ne sert qu'une école, le défaut est invisible.
Mais **trois chemins changent d'école dans la même exécution** :

- la console éditeur, qui ouvre une école cliente (`platform_scope`) ;
- les traitements en ligne de commande, qui bouclent sur les écoles ;
- la synchronisation hors connexion, qui rejoue des files par école.

Sur ces chemins, l'école B lisait les paramètres de l'école A : format de
matricule, seuil de réussite — et, depuis la 9A, **la ville et la tutelle
figées au bas d'un document signé**. Un document de l'école B pouvait
porter l'identité de l'école A.

**Correction** : cache indexé par `school_id`, et un rafraîchissement ne
vide que l'école courante.

> Un cache qui survit au changement de périmètre n'est plus un cache,
> c'est une fuite qui a l'air d'une optimisation.

---

## Défaut 3 — le figeage lisait des clés que rien ne produisait

`app/modules/documents/services.php`

```php
'city' => (string) ($contexte['setting_school_city'] ?? ''),
```

```
$ grep -rn "setting_school" app/
→ une seule occurrence dans tout le produit : celle qui les LIT
```

Aucune requête ne fabriquait ces clés. Le `?? ''` rendait le défaut muet :
**chaque document délivré figeait une ville vide et une tutelle vide**, et
imprimait « Fait à , » au bas d'une pièce signée — exactement le bug que le
module « Mon établissement » devait faire disparaître.

**Correction** : `document_snapshot()` lit `school_repo_print_settings()`,
la source unique déjà déclarée comme telle. Dépendance déclarée en tête du
fichier, jamais supposée.

> Une clé qu'aucune requête ne produit, lue derrière un `?? ''`, ne manque
> jamais : elle est simplement vide, et personne ne l'apprend avant
> l'impression.

---

## Défaut 4 — un refus qui avait déjà écrit, un champ absent qui effaçait

`school_service_save_settings()` écrivait **à l'intérieur** de la boucle de
validation, et traitait un champ absent comme un champ vidé :

```php
$valeur = trim((string) ($input[$champ] ?? ''));   // absent → ''
if (...) { $erreurs[] = ...; continue; }
school_setting_set($cle, $valeur);                 // écrit avant de valider le reste
```

Deux conséquences, toutes deux constatées à l'exécution :

1. une saisie **refusée** avait déjà modifié la base — l'écran annonçait
   un échec et l'école croyait n'avoir rien changé ;
2. un enregistrement **partiel** (formulaire réduit, envoi d'un seul champ)
   effaçait toutes les autres mentions.

C'est ce défaut qui, dans la sonde, a effacé « Lubumbashi » : deux refus
de validation ont emporté l'adresse, le téléphone et la tutelle.

**Correction** : `array_key_exists` distingue « absent » de « vidé » ; rien
n'est écrit tant qu'une erreur subsiste ; les écritures retenues passent
dans une transaction.

> Un champ absent n'est pas un champ vidé, et le confondre fait d'un
> enregistrement partiel un effacement.

---

## Défaut 5 — une recette qui suivait la chaîne sans regarder la donnée

`tests/documents_browser.js` délivrait, imprimait, **scannait le QR avec
deux décodeurs**, suivait l'URL, révoquait — 28 vérifications vertes — et
n'avait jamais regardé si la ville saisie par l'école figurait sur le
document. Elle n'y figurait pas (défaut 3).

**Correction** : la recette saisit désormais la ville et la tutelle dans
« Mon établissement », délivre un document **neuf**, et vérifie que les
deux mentions sont sur le papier — et qu'il ne reste aucun « Fait à , »
vide.

> Une recette qui suit la chaîne sans regarder la donnée prouve que la
> chaîne tourne, pas qu'elle transporte.

---

## Le téléversement, rendu vérifiable

Les contrôles de contenu vivaient **derrière `is_uploaded_file()`**, qui
ne vaut que pendant une requête HTTP. Aucun test ne pouvait donc les
exécuter : on ne pouvait que les relire, ou en réécrire une copie dans le
test — ce qui revient à noter sa propre copie.

**Extraction pure** de `upload_inspect(tmpPath, nomClient, kind)` depuis
`upload_store()` : ordre, messages et verdicts inchangés. Ce que la suite
exécute est désormais **la fonction du produit**.

> Un contrôle qu'aucun test ne peut exécuter n'est pas un contrôle
> vérifié, c'est un contrôle relu.

Ce que la sonde établit :

| Contenu soumis | Nom annoncé | Verdict |
|---|---|---|
| PNG authentique | `logo.png` | accepté |
| `<?php system(...) ?>` | `photo.jpg` | **refusé** (type réel) |
| `<?php ... ` | `logo.png` | **refusé** |
| SVG avec `<script>` | `logo.svg` | **refusé** |
| PDF | `doc.pdf` en `kind=image` | **refusé** |
| PNG authentique | `photo.jpg` | **refusé** (incohérence) |
| PNG + PHP ajouté | `photo.png` | **accepté** — voir ci-dessous |
| vide / trop lourd | — | **refusé** |

Le **polyglotte** (PNG authentique suivi de code PHP) passe : son type
réel est `image/png`, l'extension concorde, `getimagesize` lit l'en-tête.
Ce n'est pas une faille tant que trois choses tiennent, et la suite les
vérifie :

- le dépôt est **hors de la racine web** (`storage/uploads`) ;
- le nom de fichier est **régénéré** — rien du client n'entre dans le chemin ;
- **aucune route n'inclut un fichier du dépôt** — il est lu et renvoyé
  avec un type d'image, jamais exécuté.

---

## Nouvelle suite : `tests/school_isolation.php` — 36 tests

Le module « Mon établissement » n'avait **aucune suite**. Elle couvre :

- ce que le téléversement accepte et refuse (tableau ci-dessus) ;
- l'enregistrement, la validation, l'atomicité, absent ≠ vidé ;
- le figeage face au déménagement et au changement de tutelle ;
- le chemin du logo et de la photo **forgé en base** → refusé, pas servi ;
- `school.branding` refusée au secrétariat et à l'enseignant ;
- l'isolation : B ne lit ni n'écrit les mentions, le logo ou la photo de A,
  et retirer son logo chez B laisse celui de A intact.

---

## État de la régression

| | |
|---|---|
| Suites PHP | **20** |
| Tests PHP | **1 034**, 0 échec |
| Recettes navigateur | **6** |
| Vérifications navigateur | **105**, 0 échec |
| Installation depuis zéro | ✓ |

---

## Ce qui reste ouvert après cet audit

1. **La photo d'élève n'est pas figée** — seul son chemin l'est. La
   retirer laisse vide l'emplacement photo d'une carte déjà délivrée.
2. **Aucun quota de délivrance** : un compte compromis pourrait émettre
   des milliers de documents. Ils sont journalisés, pas bridés.
3. **La signature reste manuscrite.** La signature électronique est la
   suite logique du QR.
4. **Le polyglotte est accepté.** Inoffensif tant que les trois conditions
   ci-dessus tiennent ; un ré-encodage systématique des images à la
   réception les rendrait inutile — à arbitrer en 9B.
