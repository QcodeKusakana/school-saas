# Correction 10A — l'outil supposait Linux, la machine est sous Windows

**Date** : 28 septembre 2026
**Origine** : exécution par le développeur sur Laragon / Windows. Onze
contrôles en échec et une erreur fatale. **Aucun défaut dans le produit :
quatre dans l'outil de contrôle et son test.**

---

## Ce que la machine du développeur a montré

```
  ✗ Il rend du JSON exploitable
  ✗ Il joue plus de vingt contrôles — 0 contrôle(s)
  ✗ Contrôlé : app.debug            (et neuf autres, en cascade)

  ✗ ErrorException
    symlink(): Permission denied
    tests/preflight_mise_en_service.php ligne 416
```

La 10A n'avait été éprouvée que sous Linux. Or les instructions du projet
posent Windows + Laragon en local et Linux en production.

> Un outil destiné à la machine du déployeur n'a pas le droit de supposer
> le système du développeur.

---

## Défaut 1 — `2>/dev/null`, le fichier qui n'existe pas sous Windows

`preflight.php` demandait à Git si `config.local.php` est suivi :

```php
exec('git ... ls-files --error-unmatch app/config/config.local.php 2>/dev/null', ...);
```

Sous Windows, `cmd` cherche un fichier « `\dev\null` », ne le trouve pas,
et écrit **« The system cannot find the path specified. »** sur SA sortie
d'erreur — que `exec()` ne capture pas, et qui remonte donc au processus
appelant.

Le test capturait avec `2>&1` : cette ligne s'est glissée devant le JSON,
`json_decode` a rendu `null`, et **onze contrôles sont tombés sur une
cause qui n'avait rien à voir avec eux**.

> Une sortie d'erreur mêlée à une sortie de données transforme un
> incident en mystère.

**Reproduit puis corrigé, mesure à l'appui :**

```
  capture AVEC 2>&1  → "JSON ILLISIBLE"
  capture SANS 2>&1  → "ok, 1 contrôle(s)"
```

Deux corrections : le fichier « rien » prend le nom du système
(`NUL` / `/dev/null`), et le test ne fusionne plus la sortie d'erreur
dans le canal de données.

---

## Défaut 2 — `symlink()` exige l'administrateur sous Windows

Le test plaçait `storage/` dans la racine web par un lien symbolique.
Sous Windows, cela demande des privilèges d'administrateur. Il n'a pas
échoué : **il a planté**, et les contrôles suivants n'ont jamais été
joués — y compris le nettoyage du décor.

> Un test qui ne sait pas jouer un cas doit le DIRE, pas mourir en
> essayant.

Le refus est désormais capté et déclaré :

```
  · storage/ dans la racine web : non joué — cette plateforme refuse
    les liens symboliques
```

Non joué, et **pas compté comme une réussite**.

---

## Défaut 3 — `rm -rf` laissait le décor sur le disque

Le nettoyage du décor jetable passait par `rm -rf`, qui n'existe pas sous
Windows. À chaque exécution du test, un dossier restait dans le `Temp` du
développeur. L'effacement se fait maintenant en PHP, récursivement, et
retire un lien de dossier selon le système plutôt qu'en essayant à
l'aveugle.

---

## Défaut 4 — deux assertions réussissaient sur le vide

Le plus insidieux, visible seulement grâce à la panne :

```
  ✓ Tout constat en échec porte un remède          ← sur 0 constat
  ✓ Le code de sortie suit le décompte — -1 bloquant(s)
```

`$controles` valait `[]` : « aucun constat sans remède » était vrai parce
qu'il n'y avait aucun constat. Et le décompte comparait `-1` à `0`, deux
valeurs différentes, ce qui validait un code de sortie non nul.

> Une assertion qui réussit sur le vide ne mesure pas le produit, elle
> mesure son absence.

Les deux exigent maintenant un rapport non vide et un décompte
réellement rendu.

---

## Sur les deux `@` restants

`dossier_inscriptible()` en garde deux, et ce n'est pas un masquage : le
refus d'écriture **est** la mesure, et il est rendu au rapport. Ce que
`@` empêche, c'est l'avertissement PHP de s'écrire sur la sortie standard
et de rendre le JSON illisible — la panne même de ce document. C'est
écrit dans le fichier, pour qu'un relecteur ne le prenne pas pour une
entorse à la règle du projet.

---

## Vérification

| | |
|---|---|
| `tests/preflight_mise_en_service.php` | **68 tests** (inchangé en nombre, corrigé en fond) |
| Total PHP | **1 298**, 24 suites, 0 échec |
| Total navigateur | **184**, 9 recettes, 0 échec |
| Installation depuis zéro | ✓ |

**Ce que je ne peux pas vérifier d'ici** : je n'ai pas de machine
Windows. Le mécanisme est reproduit et corrigé sous Linux, et les
corrections suppriment les quatre idiomes Unix. **La confirmation
appartient à l'exécution sur Laragon.**

---

## Fichiers

```
database/preflight.php                (NUL / dev-null ; note sur les deux @)
tests/preflight_mise_en_service.php   (capture, symlink, effacement, deux assertions)
claude/audit-10a-windows.md           (ce document)
```
