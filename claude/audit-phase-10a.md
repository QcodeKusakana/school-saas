# Audit de la phase 10A — le contrôle qui ne savait pas dire non

**Date** : 28 septembre 2026
**Méthode** : audit **par exécution**. Quatre défauts trouvés, **tous
dans le contrôle que je venais d'écrire**.

---

## La question que l'audit pose

Le contrôle avant mise en service rendait « ✓ » sur une installation
préparée. Cela ne prouve rien : **un contrôle qui rendrait « ✓ » par
construction rendrait exactement la même chose.**

> Un contrôle qu'on n'a jamais vu échouer n'est pas un contrôle, c'est
> une décoration.

J'ai donc cassé **une condition à la fois**, sur une installation de
référence saine, et vérifié que le contrôle visé bascule — et qu'il porte
un remède.

**19 conditions cassées. 15 ont basculé du premier coup. Quatre défauts.**

---

## Défaut 1 — le rapport rétrécissait en silence

Trouvé **par accident** : le serveur MySQL s'est arrêté entre deux
mesures.

```
  avec la base     : 26 contrôle(s)
  sans la base     : 21 contrôle(s)     ← sans un mot
```

Cinq vérifications — migrations, migrations altérées, école de
démonstration, comptes par défaut, serveur d'envoi — **s'évaporaient**.
Rien ne distinguait « non vérifié » de « vérifié et bon », et deux
exécutions du même contrôle n'étaient plus comparables.

> Un contrôle qui disparaît quand une mesure échoue laisse croire qu'il
> est passé.

Le rapport garde désormais **toujours la même liste** ; ce qui n'a pas pu
être mesuré est rendu `inconnu`, avec la raison. Un `inconnu` sur un point
bloquant **bloque** : on n'ouvre pas un service sur une vérification qu'on
n'a pas faite.

---

## Défaut 2 — le contrôle mourait de son propre diagnostic

```
$ php -n database/preflight.php
Fatal error: Uncaught Error: Class "PDO" not found ... ligne 212
```

Le contrôle détectait `pdo_mysql` manquante, l'écrivait au rapport… puis
mourait vingt lignes plus loin en s'en servant. **Le déployeur sur
mutualisé sans `pdo_mysql` — celui pour qui ce contrôle existe — recevait
une erreur fatale au lieu du rapport qui nomme la cause.**

> Un contrôle qui meurt de ce qu'il vient de diagnostiquer ne diagnostique
> rien.

Corrigé :

```
  ✗ Extensions PHP       manquantes : pdo_mysql, mbstring, sodium
  ✗ Suite du contrôle    interrompue : sans pdo_mysql, la base ne peut pas
                         être interrogée
```

La restitution est devenue une fonction, appelable depuis un arrêt
anticipé ; et son alignement ne dépend plus de `mbstring`, que ce contrôle
sait justement trouver manquante.

---

## Défaut 3 — l'écriture mesurée pour le mauvais compte

`is_writable()` répond pour l'utilisateur qui **exécute le script** — le
déployeur, en SSH — pas pour celui qui fait tourner le serveur web.

```
  storage/ en 500, contrôle lancé sous root  →  « oui »
```

Un `storage/` que le serveur web ne peut pas écrire passait le contrôle.
Le produit n'aurait rien pu journaliser, ni stocker une photo, ni ouvrir
une session.

> Un contrôle d'écriture qui ne teste pas le bon compte rassure sur une
> panne qu'il devait trouver.

On ne peut pas éprouver le compte d'Apache depuis la ligne de commande.
Le contrôle fait donc les deux choses honnêtes : il **écrit réellement**
un fichier témoin, et il **nomme le compte** sous lequel il a écrit.

Et il descend dans les sous-dossiers — le cas que l'ancien ne voyait pas :

```
  ✓ storage/ inscriptible   écriture réussie sous « nobody »
  ✗ storage/ inscriptible   écriture REFUSÉE sous « nobody » : storage/uploads
```

Un `storage/` inscriptible avec un `storage/uploads/` en lecture seule
casse le téléversement des photos sans que rien ne le laisse voir.

---

## Défaut 4 — un contrôle qui exigeait plus qu'il n'avait besoin

Trouvé par le test lui-même, en refusant de passer.

« Serveur d'envoi » vivait **à l'intérieur** du bloc qui exige une base
joignable. Or `mail.host` vit dans `config.local.php` : quand il est
renseigné, la réponse est oui, base ou pas. Seule la question « aucune
école n'en a configuré un non plus » demande la base.

> Un contrôle qui exige plus que ce dont il a besoin devient inutilisable
> le jour où il servirait le plus.

Trois réponses distinctes désormais : **oui** (`mail.host` renseigné),
**non** (vide, et aucune école n'en a), **non mesuré** (vide, base
injoignable).

---

## Ce que l'audit a confirmé sans rien corriger

Quinze conditions basculent correctement, chacune avec un remède :

| Condition cassée | Contrôle qui bascule |
|---|---|
| `app.debug` remis à `true` | app.debug |
| `app.env` remis à `local` | app.env |
| `app.url` sur localhost | app.url |
| `app.url` en clair | app.url en HTTPS |
| `cookie_secure` à `false` | session.cookie_secure |
| clé vidée / pas du base64 / 16 octets | Clé de chiffrement (×3) |
| compte MySQL remis à `root` | Compte MySQL |
| mot de passe MySQL vidé | Mot de passe MySQL |
| mot de passe MySQL faux | Connexion à la base |
| `config.local.php` retiré | config.local.php |
| `diagnostic.php` **sans sa garde** | public/diagnostic.php, **passé bloquant** |
| `seed_demo.php` laissé en ligne | database/seed_demo.php |
| `app/.htaccess` supprimé | app/.htaccess |
| `storage/` déplacé dans `public/` | storage/ hors racine web |
| migration éditée après application | Migrations non altérées |

---

## La sonde est devenue un test

Une sonde jetable aurait décrit le contrôle du jour où je l'ai écrite —
exactement le reproche que la 10A faisait à la liste en prose.

Le test bâtit un **décor minimal et jetable** (`/tmp`), y **copie le code
réellement livré**, casse une condition, mesure, répare, et nettoie dans
un `finally`. Il ne touche jamais `config.local.php` du projet : un test
qui tombe ne doit pas laisser l'installation abîmée.

| | |
|---|---|
| `tests/preflight_mise_en_service.php` | 42 → **68 tests** |
| Total PHP | **1 298**, 24 suites, 0 échec |
| Total navigateur | **184**, 9 recettes, 0 échec |
| Installation depuis zéro | ✓ |

---

## Fichiers

```
database/preflight.php                (4 corrections)
tests/preflight_mise_en_service.php   (42 → 68)
claude/audit-phase-10a.md             (ce document)
```

Aucune migration. Aucun fichier du produit hors du contrôle lui-même.

---

## Ce qui reste ouvert

1. **Le compte du serveur web ne peut pas être éprouvé depuis la ligne de
   commande.** Le contrôle nomme celui sous lequel il a écrit ; c'est le
   déployeur qui doit comparer. Une page de diagnostic servie par le web
   le dirait vraiment — mais ce serait un fichier de plus à retirer avant
   la mise en ligne, et la 10A vient d'en compter deux.
2. **La configuration du serveur web n'est pas mesurée.** Sur Nginx les
   `.htaccess` sont sans effet ; le contrôle le dit, il ne le vérifie pas.
3. **La sauvegarde de la base** reste hors portée : vérifier qu'elle
   existe ne dit pas qu'elle se restaure. Phase 10B.
4. La procédure d'effacement d'une école (RGPD), `email_messages` non
   purgé et `archive.view` dormante restent ouverts.
