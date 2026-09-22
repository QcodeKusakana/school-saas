# Phase 9A — Les documents officiels

**Version** 0.10.0 · **État** livrée et vérifiée par exécution
**Tests** 998 verts sur 19 suites · 100 vérifications en navigateur réel

---

## 1. Objectif

Délivrer, imprimer et **rendre vérifiables** les documents que l'école
remet aux familles : attestation de fréquentation, certificat de
scolarité, carte d'élève, attestation de paiement.

Trois permissions dormaient depuis la phase 1 — `document.generate`,
`document.view`, `student.document` — sans qu'aucun écran ne les porte.

> Une permission semée sans écran n'est pas une fonctionnalité en
> attente, c'est une porte qu'on croit fermée.

---

## 2. Le problème réel

En RDC ces documents se rédigent à la main sur papier à en-tête, se
signent et se tamponnent. Les parents en demandent plusieurs par an :
bourses, démarches administratives, changement d'école, dossier de visa.

Et **le faux document scolaire est un problème courant** que rien ne
permettait de détecter. Un QR code de vérification y répond
directement — c'est d'ailleurs l'une des ambitions inscrites au projet
(§18 : « certificats numériques ; vérification par QR Code »).

---

## 3. Tables

| Table | Rôle |
|---|---|
| `documents` | ce qui a été délivré, **et ce qu'il disait** (`snapshot`) |
| `document_counters` | la numérotation, par école / nature / année |

Migration : `2026_09_21_032_phase9a_documents.sql`
(crée aussi la permission `document.revoke`).

Les deux tables sont dans `TENANT_TABLES`.

---

## 4. Fichiers

```
app/core/qrcode.php                  générateur de QR en PHP pur → SVG
app/modules/documents/services.php   délivrer, vérifier, révoquer
app/modules/documents/repositories.php  lectures + le figeage en une requête
app/modules/documents/controllers.php   5 écrans + la page publique
app/views/documents/index.php        le registre
app/views/documents/new.php          délivrer, avec les refus expliqués
app/views/documents/print.php        le document (A4 ou carte)
app/views/documents/verify.php       la page publique
app/config/routes.php                7 routes, dont 2 PUBLIQUES
app/views/partials/sidebar.php       entrée « Documents »
app/views/students/show.php          « Délivrer un document » sur le dossier
```

Recette :
```
tests/documents_isolation.php   55 tests
tests/documents_browser.js      25 vérifications, navigateur réel
tests/qrcode_decode.php         22 vérifications (14 sans Python)
```

---

## 5. Rôles et permissions

| Permission | Rôles | Ce qu'elle ouvre |
|---|---|---|
| `document.view` | DIRECTION, PREFET, SECRETARIAT, SCHOOL_ADMIN, SUPER_ADMIN | consulter et imprimer |
| `document.generate` | idem | délivrer |
| `document.revoke` | **DIRECTION, SCHOOL_ADMIN, SUPER_ADMIN** | retirer sa valeur |

`document.revoke` est **distincte** à dessein : établir une attestation
est un geste de secrétariat ; la retirer engage l'établissement
vis-à-vis de qui la détient.

La page de vérification n'exige **aucune** permission — c'est sa raison
d'être.

---

## 6. Les quatre décisions qui tiennent le module

### 6.1 Le figeage — onzième application

`snapshot` recopie tout ce que le document affirme : nom de l'élève,
classe, année, nom de l'école, décision de fin d'année. Aucune vue ne
relit les tables vivantes.

> Un document qui change après avoir été signé n'est pas un document,
> c'est un affichage.

**Vérifié** : après délivrance, renommer la classe *et* l'école ne
change ni le papier ni la page publique.

### 6.2 Le jeton n'est pas un identifiant

Douze caractères tirés par `random_int` dans un alphabet de 30, groupés
par quatre : `7QK4-M2XR-9PWD`. Environ 10^17 combinaisons — non
énumérable, ce qui autorise une page publique sans authentification.

L'alphabet écarte **I, L, O, U, 0 et 1** : un jeton se recopie parfois à
la main quand la caméra refuse, et ces caractères se confondent.

### 6.3 Ce que la page publique révèle — et tait

**Numéro, nature, établissement, date, validité. AUCUN NOM D'ÉLÈVE.**

Ce sont des mineurs. Un document tombé d'une poche ne doit rien
apprendre à qui le ramasse.

La contrepartie est assumée **et écrite sur la page** : sans le nom, un
fraudeur peut coller un QR authentique sur un faux document. La parade
tient en une phrase, que la page affirme plutôt que de la sous-entendre
— **comparez le numéro imprimé avec celui de l'écran**.

> Une vérification qui ne dit pas ce qu'elle ne vérifie pas donne une
> confiance qu'elle n'a pas gagnée.

### 6.4 La révocation

Sans elle, « ce document est valide » devient une affirmation éternelle.

> Sans révocation, « valide » est une promesse qu'on ne peut plus
> reprendre.

Révoquer **date et motive**, n'efface rien. Le motif est obligatoire :
sans lui, le guichet se retrouve devant un refus qu'il ne sait pas
expliquer à la famille.

---

## 7. Ce que chaque nature refuse

| Nature | Refusée quand | Pourquoi |
|---|---|---|
| toutes | inscription annulée, élève archivé | le document affirmerait du faux |
| carte d'élève | pas de photo | une carte sans photo n'identifie personne |
| certificat | aucune classe rattachée | rien à attester |
| attestation de paiement | **solde non nul** | elle servirait à contourner le recouvrement |

Une dette **ne bloque PAS** l'attestation de fréquentation : refuser à
un élève endetté de prouver qu'il est scolarisé serait une sanction
déplacée.

Les refus sont calculés **avant** l'écran et affichés sous chaque case,
avec ce qu'il faut faire pour les lever.

---

## 8. Le générateur de QR — pourquoi l'écrire

Le projet tourne sans Composer, sur des hébergements mutualisés. Une
bibliothèque externe pour un algorithme figé depuis 1994 serait une
dépendance à maintenir pendant des années. Le SVG évite en plus GD et
Imagick.

**Périmètre assumé** : mode octet, niveau de correction Q (≈25 %, car
ces codes sont pliés, photocopiés et scannés dans une cour), versions 1
à 10 (jusqu'à 152 caractères). Au-delà, la fonction **refuse** — un
refus se voit, une troncature non.

### Les deux fautes trouvées, et la leçon

Un QR mal encodé **ne se lit pas du tout**, et cela ne se voit pas à
l'œil : le dessin reste un joli damier.

1. **Les deux copies de l'information de format étaient mélangées** —
   chaque module recevait un bit, mais pas le sien. Et la boucle de
   réservation **effaçait le module sombre**.

2. **Le polynôme générateur Reed-Solomon était construit à l'envers.**
   Pour `n = 1`, les deux sens donnent `[1, 1]` : un test unitaire sur
   un petit cas serait passé.

   > Un polynôme symétrique sur le petit cas ne prouve rien sur le grand.

Les deux n'ont été trouvées qu'en comparant, **module par module**, avec
un encodeur de référence — puis en relisant les codes produits.

> Un code qu'on n'a pas relu avec un autre outil que le sien n'est pas
> un code vérifié, c'est un dessin.

### Pourquoi DEUX décodeurs

OpenCV a refusé une URL de vérification parfaitement valide — **et a
refusé aussi le code produit pour la même URL par la bibliothèque de
référence**. zbar lit les deux, comme les téléphones.

> Un seul décodeur ne suffit pas : son échec peut être le sien.

Avec un seul, ce refus aurait accusé un encodeur correct et l'aurait
fait « corriger » jusqu'à le casser vraiment. Un code est donc tenu pour
lisible dès qu'un décodeur indépendant le relit à l'identique.

---

## 9. Le format des documents

Pas de PDF côté serveur — décision prise en phase 4C et respectée :
« Imprimer → Enregistrer au format PDF » donne le même fichier sans
dépendance. Le layout `print.php` existait déjà et nommait les
attestations.

- **A4** pour l'attestation, le certificat et l'attestation de paiement,
  avec en-tête d'établissement, corps rédigé, QR et bloc de signature.
- **Carte 8,5 × 5,4 cm** pour la carte d'élève, format portefeuille.

**Le bloc de signature reste vide** : une signature numérisée imprimée
sur chaque exemplaire ne prouverait rien, elle se recopie.

---

## 10. Vérifications à rejouer

### Sur n'importe quel poste, sans rien installer

```bash
php database\migrate.php
php tests\documents_isolation.php     REM 55 attendus
php tests\qrcode_decode.php           REM 22 avec décodeur, 14 sans
```

`qrcode_decode.php` fonctionne **sans Python**. Il joue alors ses
**vecteurs figés** — les empreintes des huit matrices, relevées le jour
où deux décodeurs indépendants ont confirmé que ces codes se lisaient —
et annonce la relecture vivante comme **« NON VÉRIFIÉ ICI »**, sans
passer au rouge.

> Un vecteur figé ne prouve pas qu'un code se scanne ; il prouve qu'il
> n'a pas changé depuis le jour où on l'a prouvé.

Vérifié par exécution : sans Python, avec Python mais sans les
bibliothèques, et sur une régression d'un seul bit dans l'encodeur — où
les huit vecteurs tombent.

### Pour activer la relecture vivante (facultatif)

```
pip install cairosvg opencv-python-headless pyzbar
```

Sous Linux, ajouter `apt-get install libzbar0`. Le script cherche
`python3`, puis `python`, puis `py -3` : **`python3` n'existe pas sous
Windows**, où l'appeler déclenche le raccourci du Microsoft Store.

### Les recettes navigateur

```
set PHP_CLI_SERVER_WORKERS=6
php -S 127.0.0.1:8099 -t public
node tests\documents_browser.js <motdepasse>   REM 25 attendus
```

Elles exigent Node **et** Playwright avec Chromium — un attirail lourd
pour un poste de développement Windows. Elles sont pensées pour tourner
côté conteneur ou en intégration continue ; la suite PHP suffit au
quotidien.

Cette recette **lit le QR du document rendu** avec un décodeur tiers et
suit l'URL jusqu'à la page publique. C'est la seule façon de prouver que
le code imprimé mène vraiment quelque part. Sans Python, elle s'arrête
proprement à cette étape en le disant.

---

## 10 bis. Trois défauts trouvés APRÈS la livraison

Signalés par le développeur, qui a demandé « où ajouter une photo ? ».

### 1. Mon message désignait une porte qui n'existait pas

`photo_path` dormait dans `students` depuis la phase 1. **Rien ne
l'écrivait.** Le refus de la carte disait « ajoutez une photo au
dossier » alors qu'aucun écran ne le permettait.

> Un message qui demande une action que le produit ne permet pas est
> une impasse — pire qu'une fonctionnalité manquante, parce qu'elle
> envoie le secrétariat chercher ce qui n'est pas là.

**Corrigé** : téléversement depuis le dossier de l'élève, en réutilisant
`upload_store()` de la phase 1 (type MIME lu dans le contenu, nom
généré, 5 Mo). La photo est servie par une **route authentifiée**,
`/eleves/{id}/photo` — jamais par une URL publique.

> Une photo d'élève servie par URL publique est une photo d'élève
> indexable.

Vérifié : un visiteur sans session reçoit un 302, et `/storage/uploads/`
un 404.

### 2. La carte n'aurait jamais affiché la photo

Ma vue construisait `url('/' . $photo_path)`, mais les fichiers vivent
dans `storage/uploads`, **hors racine web**. L'image aurait fait 404.

> Un chemin qu'on n'a jamais rendu n'est pas un chemin, c'est une
> supposition.

Mon test posait `photo_path` en SQL et ne vérifiait que la délivrance ;
il n'a jamais rendu la carte. La recette exige désormais
`naturalWidth > 0` — l'image a-t-elle **chargé**.

### 3. Les documents s'imprimaient ENTIÈREMENT VIDES

Le plus grave, et le plus discret.

`view_capture()` fait `extract($data, EXTR_SKIP)` : une clé nommée
`data` **n'écrase pas** la variable locale du moteur. Ma vue recevait
donc le tableau de paramètres entier au lieu du figeage. « Atteste que
l'élève » était suivi de **rien** — ni nom, ni date, ni classe, ni
année. Sans la moindre erreur.

> Une variable de gabarit qui porte le nom d'une variable du moteur ne
> vaut rien, et ne prévient pas.

Et ma recette navigateur l'a laissé passer, parce qu'elle cherchait
« atteste que l'élève » et « année scolaire » — **deux phrases du
modèle**, présentes même quand tous les champs sont vides.

> Une vérification qui ne regarde que le gabarit ne dit rien sur les
> données.

**Corrigé** : la clé s'appelle `fige`. La recette exige maintenant le
NOM réel de l'élève, une classe et une année non vides.

---

## 10 ter. L'aperçu — un spécimen, jamais une pièce

Le secrétariat veut voir la mise en page avant de délivrer, surtout pour
une carte. Le lui refuser l'obligerait à **consommer un numéro officiel
pour un essai**.

Mais un aperçu identique au document réel serait une fabrique de faux.

> Un aperçu qui ne se distingue pas de l'original n'est pas un aperçu,
> c'est un blanc-seing.

Trois marques cumulatives :

1. un filigrane **SPÉCIMEN** en travers de la page, forcé à
   l'impression (`print-color-adjust: exact`) ;
2. **aucun numéro** — « SANS VALEUR » à sa place ;
3. **aucun code de vérification**, remplacé par un cadre qui explique
   où il apparaîtrait.

Rien n'est écrit : ni ligne, ni compteur, ni jeton. **Mesuré** : douze
aperçus consécutifs n'ajoutent aucun document.

L'aperçu reste offert **même quand la délivrance est bloquée** — c'est
justement le cas où l'on veut voir.

---

## 10 quater. La carte, le logo et l'identité imprimée

Signalés sur capture : le QR **débordait de la carte** et recouvrait les
boutons. Trois défauts de plus derrière, du même motif que les
précédents.

### Le code imposait sa taille

`qr_svg()` produit un SVG avec ses dimensions propres. Posé dans une
case de 1,7 cm, il s'affichait à sa taille native.

> Une image ne devrait pas deviner la place qu'on lui laisse ; c'est la
> page qui la lui donne.

**Corrigé** par une règle CSS sur le contenant (`.carte-qr svg`), pas
dans le générateur — un premier essai avec `max-width: 100%` dans le SVG
a rendu **les huit codes de l'épreuve illisibles**, un pourcentage sans
parent ne valant rien. Les vecteurs figés ne l'avaient pas vu : ils
comparent la matrice, pas le rendu.

> Un vecteur qui ne regarde que la matrice ne voit pas le rendu.

### Le code était trop petit pour être lu

Mesuré à 300 ppp : 13 mm pour 41 modules font **0,32 mm par module**.
zbar y parvenait, OpenCV non. Sur du papier, avec un téléphone, c'est
trop juste.

> Un code présent n'est pas un code lisible ; seule la taille imprimée
> en décide.

**Corrigé** : le code remonte dans le corps de la carte, sur 17 mm, soit
**0,415 mm par module** — les deux décodeurs le lisent, y compris en
média « print ». Et le QR encode désormais `/v/TOKEN` plutôt que
`/verifier/TOKEN` : sept caractères de moins font gagner une version de
code.

> Sur un support contraint, la longueur d'une URL est une décision
> d'ingénierie, pas un détail d'écriture.

### Le logo et l'identité imprimée n'existaient nulle part

`schools.logo_path` était déclaré depuis la phase 1, lu à deux endroits,
**jamais écrit**. `school.branding` dormait. Et `school_settings` était
vide : les documents imprimaient **« Fait à , »** — une virgule suivie
de rien au bas d'une pièce signée.

**Corrigé** par un écran « Mon établissement » : logo, ville, adresse,
téléphone, courriel, **ligne de tutelle** (elle était écrite en dur —
une école conventionnée et une école publique n'écrivent pas la même
chose) et devise. Le logo se sert par une route contrôlée, comme la
photo d'élève : deux chemins de service voudraient dire deux jeux de
règles.

### Deux défauts de gabarit trouvés au passage

- Le layout d'impression **fournit déjà** `.sheet` et les boutons ; ma
  vue en ajoutait un second jeu. Feuille dans une feuille, deux boutons
  « Retour » — dont celui du layout, câblé en dur vers `/notes`, égarant
  depuis une attestation. Le layout accepte désormais `$retour` et
  `$sansFeuille` (une carte n'est pas une feuille A4).
- « né le 15 juin 2015 **à Kinshasa ,** » — une espace avant la virgule,
  produite par le découpage du HTML. La phrase se compose en PHP : le
  gabarit ne sait pas où s'arrête une phrase.

### Le filigrane

Il porte le **nom de l'école**, en travers de la carte, et sa taille
suit la longueur du nom : « ITM » et « Institut Technique Industriel de
la Gombe et de ses Environs » ne tiennent pas dans la même diagonale. Le
coefficient de largeur (0,645 mm par caractère et par mm de corps) a été
**mesuré** dans un navigateur, pas deviné — le premier essai tronquait
encore le nom.

> Un coefficient deviné n'est pas un calcul, c'est un vœu.

`print-color-adjust: exact` force son impression : sans cela, les
navigateurs suppriment les fonds et le filigrane disparaîtrait au moment
précis où il sert.

---

## 11. Points ouverts

1. **La photo de l'élève n'est pas figée** — seul son chemin l'est.
   Remplacer le fichier change la carte déjà délivrée ; le retirer laisse
   son emplacement vide à la réimpression. Recopier l'image dans chaque
   document gonflerait la base et multiplierait une donnée de mineur sans
   raison ; le compromis est assumé, et le message de retrait le dit.
2. **Aucun quota de délivrance.** Un compte compromis pourrait produire
   des milliers de documents. Le journal les trace tous, mais rien ne
   les freine.
3. **La signature reste manuscrite.** Une signature électronique
   véritable (clé privée de l'établissement, horodatage) est la suite
   logique du QR, et une décision à prendre pour elle-même.
4. **Le certificat ne porte la décision de fin d'année que si elle est
   prise.** Délivré en cours d'année, il atteste la scolarité sans
   conclure — c'est voulu, mais l'école doit le savoir.
