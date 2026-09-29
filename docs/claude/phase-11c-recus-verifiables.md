# Phase 11C — Un reçu vérifiable

## Le problème

Le reçu est le seul justificatif qu'une famille possède d'avoir payé.
Deux fraudes le visent :

| Fraude | Fréquence | Ce qu'un numéro imprimé y peut |
|---|---|---|
| Faux reçu fabriqué par un tiers | occasionnelle | rien, il s'imite |
| **Reçu authentique remis sans enregistrer l'encaissement** | **courante** | **rien : le numéro est vrai** |

La seconde est la plus douloureuse. Le caissier garde l'argent ; l'école,
qui ne voit aucun paiement, réclame la somme une seconde fois — à une
famille de bonne foi, papier en main.

La parade n'est pas un plus beau papier : c'est que **le papier cesse de
prouver seul**. La preuve devient la ligne en base, et le reçu porte de
quoi la consulter.

## Tables

| Table | Ce qui change |
|---|---|
| `payments` | `verify_token VARCHAR(14) NULL`, index `uq_payments_verify_token` |
| `permissions` | descriptions renseignées pour `receipt.print`, `payment.*` |

Colonne **nullable** : les reçus antérieurs n'ont pas de jeton, et leur
bloc de vérification est simplement absent plutôt qu'un code mort.

## Fichiers

```
database/migrations/2026_09_29_036_phase11c_verification_recus.sql
database/migrations/2026_09_29_037_phase11c_permission_recu.sql
app/modules/finance/verification.php       tirage, lecture publique
app/modules/finance/services.php           le jeton naît avec le paiement
app/modules/finance/controllers.php        QR + URL passés à la vue
app/modules/documents/services.php         document_token_is_wellformed()
app/modules/documents/controllers.php      /verifier sert les deux familles
app/views/finance/receipt.php              le bloc imprimé
app/views/documents/verify.php             la réponse « reçu »
database/seed_demo.php                     finances + documents démontrables
tests/receipt_verification.php             37 tests
tests/recu_browser.js                      21 vérifications
```

## Ce que la page publique dit — et tait

**Dit** : numéro du reçu, **montant remis**, date, établissement, état
(valide / annulé), et le **motif de l'annulation** le cas échéant.

Le montant est là parce que c'est ce qu'un fraudeur retoucherait. Le
motif est là parce qu'une page rouge sans explication ne se conteste pas.

**Tait** : le nom de l'élève, sa classe, le nom du payeur, la note
interne du guichet. Ce sont des mineurs — même discipline qu'en 9A.

**Dit aussi ce qu'elle ne vérifie pas** : elle confirme qu'un versement
de ce numéro et de ce montant existe, pas que le papier entre vos mains
est celui-là.

> Une vérification qui ne dit pas ce qu'elle ne vérifie pas donne une
> confiance qu'elle n'a pas gagnée.

## Une seule porte pour deux familles de pièces

`/verifier` sert documents (9A) et reçus (11C). Une personne qui recopie
un code ne sait pas — et n'a pas à savoir — ce qu'elle tient.

L'unicité est garantie des deux côtés : `receipt_new_token()` refuse un
jeton déjà porté par un **document**, et l'index unique ferme la course.

> Une ambiguïté sur une preuve de paiement est pire qu'une absence de
> preuve.

## `receipt.print` — la case qui ne ferme rien

Semée depuis la phase 1, elle n'a jamais gardé quoi que ce soit. La
consultation d'un reçu passe par `payment.view`, et c'est le bon choix :
un parent doit pouvoir rouvrir le reçu de son enfant. On ne peut pas
techniquement empêcher d'imprimer une page qu'on affiche.

> Une permission qu'aucune porte ne peut faire respecter n'est pas une
> permission, c'est une case à cocher.

Elle n'est **pas supprimée** — elle est peut-être attribuée dans des
installations en service. Sa description dit désormais qu'elle est sans
effet, et l'écran des rôles (11B) l'affiche.

**Question ouverte pour le développeur** : faut-il lui donner un vrai
usage ? La piste la plus solide serait le **duplicata** — un reçu
réimprimé après perte, marqué comme tel et tracé, pour que deux papiers
identiques ne circulent pas. C'est une fonctionnalité nouvelle, pas un
correctif : elle demande votre arbitrage.

## Comment tester

```
php tests\receipt_verification.php      REM 37
node tests\recu_browser.js <motdepasse> REM 21
```

À l'écran : `/finances/journal` → un reçu → **Ctrl+P**. Le code figure en
bas, entre les deux signatures. Scannez-le, ou tapez son adresse dans un
navigateur **sans être connecté**.

## Dépendances

Phase 5B (encaissements et numérotation), phase 9A (jeton, QR, page
publique), phase 11B (l'écran des rôles, où la description se lit).

---

# Audit de la phase 11C — par exécution

## Le défaut : un code produit n'est pas un code lisible

Mesuré à la taille du papier, pas à l'écran.

| `app.url` | URL encodée | Modules | mm/module à 17 mm | Lisible ? |
|---|---|---|---|---|
| `http://localhost:8080` | 38 car. | 33 | 0,46 | ✓ |
| `https://gestion.csj-kinshasa.cd` | 48 car. | 37 | 0,41 | ✓ |
| `https://scolarite.complexe-scolaire-saint-joseph.gombe.cd` | 74 car. | 41 | **0,38** | **✗** |
| domaine très long | 98 car. | 49 | **0,32** | **✗** |

Le code est **toujours produit** : aucune erreur, un SVG valide. Rien
dans le fichier ne distingue un code lisible d'un code qui ne le sera
pas.

> Un code produit n'est pas un code lisible — et le défaut ne se voit
> jamais en développement, où l'adresse locale est la plus courte
> possible. Il n'apparaît qu'après impression, chez le client, quand il
> n'a plus de correctif mais un coût de réimpression.

Le seuil de 0,40 mm par module est un **choix documenté**, pas une
constante de la norme : c'est ce qu'un téléphone d'entrée de gamme — celui
qu'un parent aura en RDC — accroche à bout de bras sur papier ordinaire.

## Trois réponses, selon ce que la pièce peut se permettre

| Pièce | Contrainte | Réponse |
|---|---|---|
| **Reçu** | aucune, il a la place | taille **calculée** par `qr_taille_impression_mm()` |
| **Carte d'élève** | format carte bancaire, 17 mm | ne peut pas grandir → **le préflight avertit** |
| Tous | — | le message d'`app.url` dit désormais « imprimés », pas seulement « e-mail » |

L'adresse en clair reçoit une césure : cent caractères déborderaient
entre les deux signatures.

## Vérifié par décodage réel

Avec le domaine long, zbar **et** OpenCV relisent le code dès **120 px**
de côté — la règle de la 9A : un seul décodeur ne suffit pas, son échec
peut être le sien.

## Sondé sans défaut trouvé

- Un reçu reste vérifiable quand son école est **suspendue** puis
  **résiliée**. La famille a payé ; un litige commercial entre l'école et
  l'éditeur ne la regarde pas.
- La réponse publique ne porte **ni identifiant technique** (`school_id`,
  `enrollment_id`) **ni statut commercial** de l'école.
- Le périmètre `tenant_scope_identity` est employé conformément à son
  esprit : la vérification cherche une pièce par une clé globale unique
  avant de savoir de quelle école elle vient, et ramène **au plus une
  ligne**.

## Un défaut de mon propre outillage

Le décor jetable de `tests/preflight_mise_en_service.php` ne copiait pas
`qrcode.php`. Le contrôle mesure désormais la densité : il lui faut le
générateur, comme en production. Sans cela, 27 contrôles tombaient en
cascade sur une cause sans rapport.

## Résultat

**1 586 tests PHP** (30 suites) — `receipt_verification.php` 37 → 41,
`preflight_mise_en_service.php` 72 → 73.
**260 vérifications navigateur** (12 recettes). **0 échec.**
