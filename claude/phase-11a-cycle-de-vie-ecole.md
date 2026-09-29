# Phase 11A — Le cycle de vie d'un établissement

**Date** : 28 septembre 2026

Cette phase répond à la question du développeur : « combien d'étapes
reste-t-il ? » L'inventaire a montré **dix-sept permissions dormantes**,
et parmi elles le seul manque qui empêchait réellement de vendre.

---

## Le manque, et sa conséquence en chaîne

### On ne vend pas un SaaS où signer un client exige un DBA

```
  platform.school.create   dormante
  platform.school.edit     dormante
  platform.school.suspend  dormante
```

Une école ne naissait que par `install.php` ou à la main en SQL.

### Pire : la 10C était inatteignable

Mesuré : **rien dans le produit ne savait écrire `schools.status`.** La
console ne faisait que *filtrer* dessus. Or :

- `auth.php` refuse la connexion **et** la session de toute école qui
  n'est pas `active` — lignes 84 et 209, depuis la phase 1 ;
- l'effacement de la phase 10C exige le statut `cancelled`.

Deux règles tenues par un état qu'aucun écran ne savait poser.

> Un état que le produit fait respecter et qu'aucun écran ne sait poser
> n'est pas une protection : c'est une impasse.

C'est le motif exact de la phase 9D, sur l'année scolaire. Il s'était
reproduit un cran plus haut — sur l'établissement — et il rendait
**l'effacement livré la veille inutilisable.**

---

## Ce que la phase livre

| Écran | Chemin | Permission |
|---|---|---|
| Créer | `GET/POST /plateforme/ecoles/nouveau` | `platform.school.create` |
| Modifier | `GET/POST /plateforme/ecoles/{id}/modifier` | `platform.school.edit` |
| Suspendre · résilier · remettre | `POST /plateforme/ecoles/{id}/etat` | `platform.school.suspend` |

**Aucune migration** : les trois permissions existaient depuis la phase 1,
et elles étaient déjà attribuées au SUPER_ADMIN de plateforme.

### Créer une école, ce n'est pas l'insérer

Une ligne dans `schools` donne une école **muette** : pas de cycle donc
pas de niveau, pas d'année donc aucun écran de travail, pas de compte
donc personne pour ouvrir la porte. `install.php` l'établit depuis la
phase 1 — six écritures, pas une. Le service fait les mêmes, dans le même
ordre, dans **une** transaction :

```
  schools · school_cycles · academic_years (courante)
  subscriptions (essai 60 j) · users (admin) · user_roles
```

> Créer un client, ce n'est pas créer sa fiche : c'est lui rendre le
> produit ouvrable.

### Le mot de passe initial

Tiré au hasard, **affiché une seule fois**, jamais journalisé — pas même
rédigé : ce qui n'est pas écrit ne fuit pas. Il passe par le message
éclair et **jamais par l'URL** : une adresse se retrouve dans
l'historique du navigateur, dans les journaux du serveur et dans
l'en-tête `Referer` de la page suivante.

Il évite les caractères qui se confondent (O/0, l/1/I) : il sera dicté au
téléphone. Le changement est imposé à la première connexion.

### Ce qui n'est pas modifiable

Le **code** est l'identité de l'école — il figure sur des documents déjà
remis et sur la trace d'un éventuel effacement. Le **slug** a servi à
nommer des dossiers de fichiers. Le **statut** relève d'une autre
permission : suspendre un client et corriger son adresse ne sont pas le
même pouvoir.

Éprouvé : poster `code` et `slug` dans le formulaire de modification ne
les change pas.

---

## Trois permissions, et ce n'est pas du zèle

Ouvrir un client, corriger son adresse et lui couper l'accès sont trois
pouvoirs de nature différente. Une permission qui les recouvrirait tous
finirait par accorder le plus dangereux des trois — la leçon de la
recette Finances, reprise en 7B2.

Aucune n'est donnée à un rôle d'école. Éprouvé sur les trois.

---

## Quatre défauts trouvés en chemin

### 1. La route `/nouveau` aurait été avalée par `/{id}`

Le routeur retient le **premier** motif qui correspond. Déclarée après
`/plateforme/ecoles/{id}`, la route `/nouveau` aurait rendu
« Établissement introuvable ». Vu en lisant la boucle de répartition,
corrigé avant la première exécution, et verrouillé par la recette.

### 2. Trois fonctions inventées

`db_last_insert_id()` (c'est `db_insert()` qui rend l'identifiant),
`flash_set()` (le système ne porte que des messages typés), et un
troisième argument à `auth_attempt()` qui prend un booléen, pas une
adresse IP. Chacune trouvée par l'exécution, aucune par la relecture.

### 3. `.` lie plus fort que `??`

```php
'Établissement ' . $code . ' : ' . LABELS[$avant] ?? $avant
```

se lit `(A . B) ?? C` — donc jamais `C`. Et la trace décrivait l'état de
DÉPART au lieu de la transition. Réécrite avec `sprintf`.

### 4. Ma recette ouvrait la mauvaise porte

Elle se connectait avec `directeur.demo`, qui est le **directeur de
l'école de démonstration** et n'a aucune permission de plateforme.
L'éditeur est `editeur.demo`. Le bouton manquait à bon droit.

> Une recette qui ouvre la mauvaise porte ne mesure pas le produit, elle
> mesure son propre trousseau.

Et son sélecteur `button[type="submit"]` attrapait le bouton de
déconnexion, replié dans un menu donc invisible : trente secondes
d'attente pour un élément qui ne serait jamais cliquable.

---

## Un piège qui s'est reproduit

Deux écoles de recette traînaient en base. Cause : `abort(403)` appelle
`exit()`, qui **court-circuite le bloc `finally`**. C'est exactement ce
que l'audit de la 9D avait consigné.

La correction n'est pas de contourner l'`abort` : c'est d'interroger la
**décision** plutôt que son rendu. `platform_refusal()` existe pour cela
depuis la phase 7B, et `tests/platform_scope.php` le documente. Je l'ai
employé au lieu d'en inventer un autre.

---

## Vérification

| | |
|---|---|
| `tests/platform_school_lifecycle.php` | **46 tests** (nouveau) |
| `tests/plateforme_ecoles_browser.js` | **18 vérifications** (nouveau) |
| Total PHP | **1 441**, 27 suites, 0 échec |
| Total navigateur | **208**, 10 recettes, 0 échec |
| Installation depuis zéro | ✓ — 79 permissions, 311 role_permissions |

Ce que seul le navigateur pouvait dire : la route `/nouveau` n'est pas
avalée, le mot de passe s'affiche **une fois** puis disparaît au
rechargement, il ne transite pas par l'URL, et le bloc d'état propose
bien la résiliation.

---

## Fichiers

```
app/modules/platform/services.php      (3 services + 4 fonctions d'appui)
app/modules/platform/controllers.php   (5 contrôleurs)
app/views/platform/school_form.php     (nouveau — création ET modification)
app/views/platform/school.php          (bouton Modifier + bloc d'état)
app/views/platform/schools.php         (bouton Nouvel établissement)
app/config/routes.php                  (5 routes, AVANT le motif /{id})
tests/platform_school_lifecycle.php    (nouveau, 46)
tests/plateforme_ecoles_browser.js     (nouveau, 18)
tests/_nettoyage_recette_ecoles.php    (nouveau — fichier, pas php -r)
```

Aucune migration.

---

## Ce qui reste ouvert

1. **Le statut `pending` n'est proposé nulle part.** L'énumération le
   porte, rien ne le pose ni ne le distingue de `active`. Tant qu'il ne
   se distingue par rien de concret, l'exposer n'ajouterait qu'une
   question sans réponse — la décision prise en 9D pour `archived`.
2. **Les cycles ne se modifient plus après la création.** Une école qui
   ouvre une section humanités trois ans plus tard n'a pas d'écran pour
   le déclarer.
3. **Aucun courriel de bienvenue.** L'éditeur dicte l'identifiant et le
   mot de passe. L'envoi existe depuis la 8A ; le brancher ici
   supposerait que l'adresse de l'administrateur est renseignée, ce qui
   n'est pas exigé.
4. **Il reste seize permissions dormantes** — rôles, reçus, documents
   d'élève, registre des sortants, emploi du temps, évaluations,
   communication.
