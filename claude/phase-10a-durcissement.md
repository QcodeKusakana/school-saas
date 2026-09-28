# Phase 10A — Le durcissement avant mise en service

**Date** : 28 septembre 2026
**Méthode** : la liste « avant mise en production » n'a pas été relue.
Elle a été **exécutée**.

---

## Ce que l'exécution a trouvé

J'ai extrait du dépôt ce qu'un déployeur reçoit réellement, suivi le
README **à la lettre**, installé sur une base neuve, puis demandé une
réinitialisation de mot de passe en HTTP.

```
  POST /mot-de-passe/oublie  →  HTTP 500

  RuntimeException : Aucune clé de chiffrement configurée.
    crypto.php(56)           crypto_key()
    crypto.php(101)          crypto_encrypt()
    mailer.php(189)          mail_queue()
    auth/services.php(108)   auth_send_reset_email()
    auth/services.php(62)    auth_service_request_reset()
```

**Une installation conforme à la documentation ne savait pas
réinitialiser un mot de passe.** Pour personne, dès le premier jour.

### La cause

`app/config/config.local.example.php` — **le** fichier que le README fait
copier — ne portait pas `security.encryption_key`. L'instruction existait,
complète et bien écrite… dans `config.php`, le fichier que personne ne
copie.

> Une consigne écrite dans le fichier qu'on ne copie pas n'est pas une
> consigne : c'est une note pour soi-même.

Le corps du message de réinitialisation est chiffré au repos parce qu'il
**porte le jeton en clair** — celui dont `password_resets` ne garde que le
haché, précisément pour qu'une fuite de la base ne permette pas d'en
forger un. Sans clé, `crypto_encrypt()` lève, et c'est délibéré : un repli
silencieux en clair serait pire que pas de chiffrement, il en donnerait
l'illusion.

### Ce que l'échec laissait derrière

| | |
|---|---|
| Jeton de réinitialisation | **créé** |
| Entrée au journal | **écrite** |
| Message en file | aucun |
| Rendu au visiteur | pile d'appels complète (mode debug) |

En mode production, la même demande donnait « Erreur interne » et la vraie
cause dans un fichier de journal que l'école ne lit pas.

---

## Trois corrections, chacune mesurée

### 1. Le modèle de configuration porte la clé

Valeur **vide** — une vraie clé dans un fichier versionné serait la même
pour tous les déployeurs, ce qui revient à ne pas en avoir — la commande
de génération en commentaire, et l'avertissement que `root` ne va pas en
production, là où le déployeur le lira.

### 2. L'installateur refuse, avant la première écriture

```
  ✗ Clé de chiffrement absente ou invalide.

    Ajoutez ceci dans app/config/config.local.php, puis relancez :

      'security' => [
          'encryption_key' => '/fh/cMFvW0qDKFwEgFnKeVv9ukCSpTG100gEIoS25z8=',
      ],
```

La clé est **tirée au hasard à l'exécution** et donnée prête à coller. Le
contrôle est placé avant la connexion à la base : une école qui découvre
le manque le jour où son directeur a perdu son mot de passe le découvre
trop tard.

> Un prérequis qu'on vérifie après l'installation n'est pas un prérequis,
> c'est un regret.

Le fichier du déployeur n'est **pas** modifié par l'installateur : ce
qu'il a écrit lui appartient.

### 3. Le refus ne passe plus par un plantage public

`mail_queue()` est appelée depuis `/mot-de-passe/oublie`, route ouverte à
tout visiteur. La décision a été extraite en `mail_body_storage()` — le
même geste qu'en 9A pour `upload_inspect()` — pour qu'un test joue le code
**réellement livré** sans démonter la configuration du serveur.

Le refus reste entier : rien n'est écrit, surtout pas en clair. Il
emprunte simplement le contrat que la fonction possédait déjà pour « ce
destinataire n'a pas d'adresse », un cas normal du produit.

> Une protection qui s'exprime par un plantage public protège la donnée et
> livre le produit.

**Mesuré après correction, sur la même installation sans clé :**

```
  POST /mot-de-passe/oublie  →  302
  message rendu : « Si ce compte existe, les instructions ont été transmises. »
  email_messages : 0 ligne
  journal : cause nommée, remède nommé
```

Et sur une installation avec la clé collée comme l'installateur le
demande :

```
  message #1 · password_reset · queued · sensible=1
  corps stocké : v1:xFKHLKmxue3…   (chiffré : OUI)
  erreur d'envoi : aucun serveur SMTP configuré  ← la seule étape restante
```

La boucle se ferme.

---

## La liste de contrôle est devenue un programme

Le projet portait la même liste en prose à **deux** endroits. Confrontée
au produit, sur onze lignes :

| Ligne | Réalité |
|---|---|
| « Câbler l'envoi d'e-mails » | câblé depuis la **phase 8A** |
| « `config.local.php` hors dépôt Git » | dans `.gitignore` depuis la **phase 1** |
| « Supprimer `public/diagnostic.php` » | le fichier **se refuse lui-même** hors debug et hors machine locale |

Trois lignes sur onze décrivaient un produit disparu. Et aucune ne parlait
du manque qui empêchait le produit de fonctionner.

> Une liste de contrôle qu'on n'exécute pas décrit le produit du jour où
> on l'a écrite.

### `database/preflight.php`

**26 contrôles mesurés** — configuration réellement lue, base réellement
interrogée, fichiers réellement présents. Il ne corrige rien : un outil
qui répare ce qu'il contrôle finit par masquer ce qu'il aurait dû
signaler.

```
  ✓ Clé de chiffrement               présente, 32 octets
  ✓ Migrations non altérées          empreintes conformes
  ✗ session.cookie_secure            false — le cookie circule en clair
        Passez session.cookie_secure à true (exige HTTPS).
```

**Bloquant** (exposer les données, ou laisser une fonction essentielle
hors service) et **recommandé** sont séparés. Chaque échec nomme un
remède : un contrôle qui dit « non » sans dire quoi faire déplace le
problème. Sortie **1** tant qu'un bloquant subsiste, `--json` pour un
script de déploiement.

Deux contrôles que la liste en prose ne pouvait pas faire :

- **une migration modifiée après application** — le registre garde une
  empreinte SHA-256 ; un fichier édité après coup fait diverger deux
  installations en silence. Éprouvé : l'ajout d'un commentaire à une
  migration déjà appliquée est détecté et nommé.
- **l'absence de tout serveur d'envoi** — sans lui, un directeur qui perd
  son mot de passe n'a plus aucun moyen de rentrer.

### Une correction du contrôle lui-même

Première version : sans dépôt Git sur la machine, « `config.local.php`
hors dépôt » rendait *non mesurable*, compté comme bloquant. Or un dépôt
déposé par FTP sur un hébergement mutualisé **n'a pas** de `.git` — c'est
même le cas le plus sûr. Le contrôle bloquait précisément la mise en ligne
la mieux faite. Le verdict dit maintenant « aucun dépôt Git dans cette
copie », et passe.

---

## Vérification

```
  Sur une installation préparée comme une vraie mise en production
  26 contrôle(s) · 0 bloquant(s) · 0 recommandation(s)   → sortie 0

  Sur cette machine de développement
  26 contrôle(s) · 6 bloquant(s) · 4 recommandation(s)   → sortie 1
```

Les deux comptent : un contrôle qui ne sait pas dire « oui » n'est pas
utilisable, et un contrôle qui ne sait pas dire « non » ne contrôle rien.

| | |
|---|---|
| `tests/preflight_mise_en_service.php` | **42 tests** (nouveau) |
| Total PHP | **1 272**, 24 suites, 0 échec |
| Total navigateur | **184**, 9 recettes, 0 échec |
| Installation depuis zéro | ✓ |

Le test ne vérifie pas que le contrôle *existe* : il compare **ses
verdicts à la configuration réellement lue**. Un contrôle qui répondrait
« ✓ » par construction ne contrôlerait rien.

---

## Fichiers

```
app/config/config.local.example.php   (la clé, la commande, l'avertissement)
app/core/mailer.php                   (mail_body_storage, extraite pour être éprouvée)
database/install.php                  (refus avant la première écriture)
database/preflight.php                (nouveau — 26 contrôles)
tests/preflight_mise_en_service.php   (nouveau — 42)
README.md                             (la liste → le contrôle)
docs-projet/etat-du-projet.md         (idem, avec ce qu'elle disait de faux)
```

Aucune migration : rien à changer en base.

---

## Ce qui reste ouvert

1. **La sauvegarde automatique de la base** reste hors du contrôle : elle
   dépend de l'hébergeur, et vérifier qu'une sauvegarde *existe* ne dit
   pas qu'elle se **restaure**. À traiter en 10B, avec une restauration
   éprouvée — une sauvegarde jamais restaurée est une croyance.
2. **La procédure d'effacement d'une école (RGPD)** reste impossible.
   C'est la dette la plus lourde du projet avant commercialisation.
3. **`email_messages` n'est toujours pas purgé.**
4. **`archive.view` reste dormante** — le registre des sortants, phase 9E.
5. Le contrôle ne teste pas la **configuration du serveur web** : sur
   Nginx, les `.htaccess` sont sans effet et rien ici ne le vérifie. Il le
   dit, il ne le mesure pas.
