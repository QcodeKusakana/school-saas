# Phase 11B — Rôles et permissions par école

## Objectif

Laisser chaque établissement composer ses propres rôles, sans jamais lui
permettre de s'accorder ce qu'il ne détient pas, ni de toucher aux rôles
livrés avec le produit, ni d'atteindre les capacités de la plateforme.

C'est le deuxième verrou commercial identifié : une école qui ne peut pas
organiser ses droits calque son fonctionnement sur les neuf rôles livrés,
ou distribue des comptes trop puissants.

## Tables utilisées

| Table | Rôle dans le module |
|---|---|
| `roles` | `school_id NULL` = rôle système partagé ; `school_id` renseigné = rôle de l'école. Unicité `(school_id, code)`, FK CASCADE depuis `schools`. |
| `permissions` | Catalogue. `is_platform = 1` (10 lignes) exclu **toujours** du catalogue accordable. |
| `role_permissions` | Remplacement total à chaque modification. CASCADE depuis `roles`. |
| `user_roles` | Attributions. CASCADE depuis `roles`. |
| `audit_logs` | `role.create`, `role.update`, `role.activate`, `role.deactivate`. |

**Aucune migration.** `role.view` et `role.manage` existaient déjà —
`role.view` chez SUPER_ADMIN, SCHOOL_ADMIN et DIRECTION ; `role.manage`
chez SUPER_ADMIN et SCHOOL_ADMIN.

## Fichiers

```
app/modules/roles/repositories.php    lectures, catalogue accordable
app/modules/roles/services.php        les cinq règles
app/modules/roles/controllers.php     6 actions
app/views/roles/index.php             liste : système / plateforme / siens
app/views/roles/form.php              composer, avec les permissions non détenues grisées
app/views/partials/sidebar.php        le lien (un écran sans lien est une adresse)
app/core/permission.php               durcissement de perm_all() / perm_roles()
app/config/routes.php                 6 routes
database/seed_demo.php                admin.demo (SCHOOL_ADMIN)
tests/roles_isolation.php             58 tests
tests/_nettoyage_roles.php            décor, hors `finally`
tests/roles_browser.js                29 vérifications
```

## Routes

| Méthode | Chemin | Permission |
|---|---|---|
| GET | `/roles` | `role.view` |
| GET | `/roles/nouveau` | `role.manage` |
| POST | `/roles` | `role.manage` |
| GET | `/roles/{id}` | `role.view` |
| POST | `/roles/{id}` | `role.manage` |
| POST | `/roles/{id}/etat` | `role.manage` |

`/roles/nouveau` est déclaré **avant** `/roles/{id}` : le routeur retient
le premier motif qui correspond.

## Les cinq règles

1. **Un rôle d'école appartient à son école.** `school_id` est posé par le
   service, jamais par le formulaire. Aucun rôle global ne naît ici.
2. **Un rôle système ne se modifie pas.** Les neuf livrés portent
   l'installation, la création d'école et les portails ; les rendre
   modifiables laisserait une école casser `SCHOOL_ADMIN` pour toutes.
3. **On n'accorde que ce qu'on détient.**
4. **Jamais une permission de plateforme**, même détenue — l'éditeur en
   visite chez un client les détient. Sans cette règle, il pourrait
   laisser derrière lui un rôle d'école portant `platform.school.erase`.
5. **Un niveau strictement inférieur au sien**, à la création comme à la
   modification.

Les règles 3, 4 et 5 se recouvrent. C'est voulu : une défense qui se
répète survit à la disparition de l'une de ses couches.

## Ce que le code technique n'est pas

`roles.code` sert de clé dans tout le produit (`r.code = 'SCHOOL_ADMIN'`
apparaît dans l'installateur, la création d'école et les portails). Il
est donc **dérivé du nom, préfixé par l'école** (`E12_SURVEILLANT`), et
jamais saisi.

> Une clé technique que l'utilisateur choisit n'est plus une clé : c'est
> une collision qui attend son heure.

## Retirer des accès

Deux gestes retirent des accès, et ils sont traités pareillement :

- **désactiver** un rôle porté → motif d'au moins 10 caractères ;
- **décocher** une permission d'un rôle porté → motif d'au moins 10
  caractères.

L'**ajout** n'exige rien : ouvrir un accès se rattrape en le refermant,
alors qu'une perte d'accès en pleine journée d'école arrête quelqu'un
dans son travail sans qu'il sache pourquoi.

On ne supprime jamais un rôle : `user_roles` le référence, et la question
« qui avait quoi » doit garder une réponse.

## Sécurité

- Isolation : `roles_repo_find()` et `roles_repo_all()` filtrent sur
  `school_id IS NULL OR school_id = <école>`.
- Les compteurs de porteurs sont ceux de l'école (voir l'audit).
- `perm_all()` et `perm_roles()` ignorent un rôle appartenant à une autre
  école — deuxième ligne de défense derrière `users_role_refusal()`.
- CSRF sur les trois POST ; permissions portées par le routeur.
- Aucune permission de plateforme n'apparaît dans le formulaire, ni ne
  peut être postée par son identifiant.

## Comment tester

```bash
php tests/roles_isolation.php          # 58
node tests/roles_browser.js <mdp>      # 29
php tests/_nettoyage_roles.php         # décor, appelable seul
```

Comptes de démonstration : `admin.demo` compose, `directeur.demo`
consulte, `editeur.demo` administre la plateforme.

## Dépendances

Phase 7D (attribution des rôles), phase 7B (console éditeur et visite),
phase 11A (création d'école, qui fournit le SCHOOL_ADMIN initial).
