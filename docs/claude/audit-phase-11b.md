# Audit de la phase 11B — par exécution

Méthode inchangée : une sonde écrite avant les tests, qui fabrique deux
écoles par le chemin réel de la phase 11A et tente de casser le module
avec des données vraies. Trois défauts trouvés, tous corrigés, tous
verrouillés par un test.

---

## 1. Deux écrans sur trois répondaient une page d'erreur

`roles_repo_holders()` comptait les porteurs d'un rôle ainsi :

```sql
SELECT COUNT(*) FROM user_roles ur
  JOIN users u ON u.id = ur.user_id AND u.deleted_at IS NULL
 WHERE ur.role_id = :r
```

`users` est une table multi-école. Le drapeau `db_value(..., true)` ne
couvre que les tables globales : le garde a refusé, à raison.

Conséquence réelle : l'écran de modification (`ctrl_roles_edit_form`) et
la désactivation (`roles_service_toggle`) appellent tous deux cette
fonction. **Ils levaient une exception avant d'afficher quoi que ce
soit.** Aucun test n'existait encore ; seule une sonde qui ouvrait
réellement les écrans pouvait le voir.

Correction : `AND u.school_id = :school_id`.

---

## 2. Une école lisait les effectifs de toutes les autres

La même jointure existait dans `roles_repo_all()`, en sous-requête. Elle,
**passait** — parce que la requête lie `school_id` ailleurs
(`r.school_id = :school_id`), ce qui suffit à `tenant_sql_binds_school()`.

Mesure depuis une école neuve : le rôle système `SCHOOL_ADMIN` affichait
**4 comptes** là où l'école n'en a **1**. Le compteur additionnait les
administrateurs de toutes les écoles de la plateforme.

> Un garde qui s'estime satisfait par un filtre posé ailleurs ne protège
> que la requête qu'il a lue.

C'est une fuite modeste en volume et frontale sur le principe : « Ne
jamais permettre à un utilisateur d'une école d'accéder aux données d'une
autre école. » Elle est aussi la démonstration que le garde multi-école
est une *aide*, pas une garantie : il ne lit pas les sous-requêtes.

Correction : la sous-requête lie son propre paramètre
(`u.school_id = :school_comptes`) — deux paramètres pour la même valeur,
les requêtes préparées non émulées refusant qu'un nom serve deux fois.

---

## 3. Deux gestes au même dégât, deux exigences différentes

Le produit exigeait un motif pour **désactiver** un rôle porté. Décocher
quatre permissions sur cinq produit exactement le même effet — les
comptes perdent ces accès à leur requête suivante — et passait sans un
mot.

> Deux gestes qui font le même dégât ne peuvent pas avoir deux
> exigences : la plus faible devient le chemin qu'on prend.

Correction : un retrait sur un rôle porté exige un motif d'au moins 10
caractères, journalisé. L'**ajout** n'exige rien, et c'est délibéré :
ouvrir un accès se rattrape, une perte d'accès en pleine journée arrête
quelqu'un dans son travail.

---

## 4. Un pouvoir qu'aucun compte livré ne détenait

L'école de démonstration n'avait qu'un `DIRECTION`. Or `role.manage` est
chez `SCHOOL_ADMIN`. Aucun compte de l'école ne pouvait composer un rôle :
l'écran n'était atteignable qu'en faisant entrer l'éditeur depuis sa
console.

> Un pouvoir que le produit prévoit et qu'aucun compte livré ne détient
> n'est pas prévu : il est absent.

Correction : `admin.demo` (SCHOOL_ADMIN) ajouté au jeu de démonstration.
`directeur.demo` reste — la comparaison est justement la démonstration
utile : le directeur consulte les rôles, l'administrateur les compose.

---

## 5. Un bouton qui n'était qu'une icône

Le bouton de désactivation portait un pictogramme barré et rien d'autre :
illisible pour un lecteur d'écran, et une devinette pour un secrétariat
qui hésite déjà à toucher aux droits. Libellé ajouté.

---

## Défauts de mes propres outils de mesure

Le produit avait raison, ma mesure avait tort — trois fois :

- **La recette exigeait plus que la règle.** Elle attendait un refus en
  désactivant sans motif un rôle que personne ne portait. Le produit
  n'exige le motif que si des comptes le portent.
  > Une recette qui exige plus que la règle mesure sa propre idée de la
  > règle.
- **Un contrôle qui pouvait passer pour la mauvaise raison.** Le refus
  opposé au directeur était mesuré en cherchant le mot « refus » dans la
  page. Il mesure désormais le code HTTP (403) et l'absence du
  formulaire.
- **La sonde a contourné le garde qu'elle mesurait**, puis est morte
  muette (le tampon de sortie retenait ses constats quand une exception
  passait). Les deux corrigés.

Enfin : deux écoles de sonde ont survécu à des plantages, `exit()`
sautant les blocs `finally` — la leçon de la phase 9D, encore. Le
nettoyage de la suite 11B vit donc dans un **fichier séparé**, exécuté à
la fin et appelable seul.

---

## Ce que la sonde a confirmé

- L'isolation tient dans les deux sens : l'école B ne voit, ne trouve, ne
  modifie ni ne désactive le rôle de l'école A.
- L'éditeur, qui détient les permissions de plateforme, ne peut pas les
  laisser dans un rôle d'école (règle 4 — la règle 3 seule aurait laissé
  passer).
- Un rôle composé est réellement **attribuable** par la phase 7D, et
  ouvre exactement ce qu'il annonce : 2 permissions accordées, 2
  permissions au porteur, rien d'autre.
- Un rôle désactivé retire l'accès immédiatement et n'est plus
  attribuable ; le réactiver le rend.
- L'effacement d'une école (phase 10C) emporte ses rôles : `roles`
  CASCADE depuis `schools`, `role_permissions` et `user_roles` CASCADE
  depuis `roles`. La 10C avait été écrite quand aucun rôle n'avait de
  `school_id` — rien ne le garantissait, un test le garantit.

---

## Résultat

**1 510 tests PHP** (28 suites) — dont 58 nouveaux.
**231 vérifications navigateur** (11 recettes) — dont 29 nouvelles.
**0 échec.** Installation depuis zéro vérifiée : 79 permissions, 311
`role_permissions`, école neuve créable.

---

## Suite de l'audit — ce que la question de la 11B a révélé ailleurs

La 11B pose une question que le produit ne s'était jamais posée : **que
voit un compte dont on vient de retirer tous les droits ?** La phase 7D
refuse de créer un compte sans rôle — « sans rôle, il se connecte et ne
peut rien ouvrir » — mais la 11B permet d'y arriver après coup, en
désactivant le rôle qu'il portait. En allant regarder, trois défauts
plus anciens.

### 6. Le tableau de bord débordait de son périmètre

`/tableau-de-bord` n'exige que `auth` : c'est le seul écran du produit
ouvert à tout compte connecté. Mesuré avec `enseignant.demo` :

| Ce qu'il voyait | Ce que dit la porte correspondante |
|---|---|
| « Découverte — Abonnement · 29 jours restants » | `/abonnement` → **403** (`subscription.view`) |
| « 4 Comptes utilisateurs » | `/utilisateurs` → **403** (`user.view`) |
| « Créer les comptes du personnel — Configurer » | même écran, même 403 |
| « Compléter la fiche de l'établissement » | `/ecole/parametres` → `school.edit` |

La phase 7A avait pourtant décidé, noir sur blanc : « `/abonnement` en
enseignant ou en élève → 403, et l'entrée de menu est absente ».

> Une porte fermée à trois endroits et ouverte sur le tableau de bord
> n'est pas fermée.

Défaut de la **phase 1**, dormant depuis. Le contrôleur ne charge plus
ce qu'il ne montrera pas, et chaque étape du guide de configuration est
gardée par la permission de l'écran qu'elle ouvre. L'enseignant garde
l'année scolaire, les cycles et l'identité de son école — ce qui figure
sur les bulletins.

### 7. Un repère de chantier montré aux clients

Un bandeau « Modules du logiciel — **Phase 1 sur 10** » listait les dix
phases du développement, neuf cochées « à faire ». Il datait de la phase
1 et n'avait jamais bougé : en septembre 2026 il annonçait encore à une
école qui **paie** que les élèves, la pédagogie et les finances
n'étaient pas faits.

> Un repère de chantier laissé dans un produit vendu ne raconte pas le
> produit, il raconte le chantier — et il ment dès la phase suivante.

Retiré. Ce que l'éditeur veut suivre se lit dans
`docs-projet/etat-du-projet.md`.

### 8. Un cul-de-sac muet

Un compte sans aucune permission voyait un écran presque vide, sans
menu, sans explication. Il en conclut que le logiciel est cassé et
appelle l'éditeur, alors que seule son administration peut le
débloquer. Le tableau de bord l'annonce désormais et nomme le recours.

### 9. Une créance perdue à l'effacement (10C)

En montant une école complète pour vérifier que l'effacement emporte
bien ses rôles propres, la simulation a annoncé « écritures comptables :
**1**, archivées » — et l'exécution a répondu « **0** archivée(s) ».
Personne ne signalait la contradiction.

Cause : `erase_archiver_comptabilite()` ne lisait que
`subscription_payments`, tandis que l'inventaire additionnait
`subscriptions` **et** `subscription_payments`. Conséquence réelle : un
abonnement facturé dont aucun versement n'a été enregistré — l'école est
partie sans payer, ou le versement est resté « en attente » chez
l'opérateur — était détruit sans trace.

> L'effacement du client effaçait la dette du client, à son bénéfice.
> Un inventaire qui ne compte pas ce que l'exécution écrira n'est pas un
> inventaire, c'est une estimation.

Les créances sont désormais archivées (`payment_status = 'unpaid'`,
`original_payment_id` nul), et l'inventaire reproduit **exactement** la
règle de l'archivage. Un abonnement sans tarif figé reste dehors : il
n'engage rien, décision de la 7B2 — inventer une dette est pire que
l'avouer.

**Vérifié par exécution réelle**, pas par lecture d'`information_schema` :
une école montée avec deux rôles propres, trois comptes et une créance de
250 USD a été réellement effacée. Aucun rôle, aucune permission, aucune
attribution orpheline ; la créance a survécu.

### Un défaut de ma propre mesure, encore

Ma première sonde tronquait le corps de la page à 25 lignes. J'en ai
conclu que l'enseignant ne voyait aucune tuile — alors que je n'avais
simplement pas regardé assez loin. J'ai failli classer le défaut comme
inexistant.

> Une mesure qui coupe avant la fin ne dit pas « il n'y a rien », elle
> dit « je n'ai pas regardé ».

---

## Résultat après cette suite

**1 544 tests PHP** (29 suites) — dont `tests/dashboard_perimetre.php`
(23, nouvelle) et `tests/school_erasure.php` porté de 47 à 58.
**239 vérifications navigateur** (11 recettes).
**0 échec.** Installation depuis zéro vérifiée.
