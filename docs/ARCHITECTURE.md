# Architecture — School SaaS RDC

Document de référence technique. À lire avant toute contribution.

---

## 1. Principes directeurs

| Principe | Traduction concrète |
|---|---|
| **Sécurité d'abord** | Point d'entrée unique, CSRF global, garde-fou multi-école, requêtes préparées natives |
| **Isolation stricte** | Aucune donnée d'un établissement ne peut être atteinte depuis un autre, même par erreur de développement |
| **Simplicité** | PHP procédural structuré, aucune dépendance superflue, déployable sur un mutualisé cPanel |
| **Conformité RDC** | Référentiel scolaire en base, jamais en dur : cycles, niveaux, sections, options, matières |
| **Traçabilité** | Toute action sensible écrite dans `audit_logs`, avec l'avant et l'après |

---

## 2. Pile technique

| Couche | Choix | Justification |
|---|---|---|
| Backend | PHP 8.1+ procédural | Contrainte du cahier des charges ; hébergement mutualisé accessible |
| Base de données | MySQL 8 / MariaDB 10.3+, PDO | Compatible avec la quasi-totalité des offres cPanel |
| Frontend | Bootstrap 5.3 servi **localement** | Le CDN est incompatible avec la CSP et avec le mode hors connexion |
| Icônes | Bootstrap Icons, police locale | Idem |
| Hors connexion | PWA, Service Worker, IndexedDB (phase 8) | Socle de synchronisation déjà en base |

**Aucune dépendance Composer** dans le noyau. Les seules bibliothèques tierces sont des fichiers statiques versionnés dans `public/assets/vendor/`.

---

## 3. Arborescence

```
school-saas/
├── public/                 ← RACINE WEB (seul dossier exposé)
│   ├── index.php               point d'entrée unique
│   ├── .htaccess               réécriture vers le front controller
│   └── assets/
│       ├── css/app.css
│       ├── js/app.js
│       └── vendor/             Bootstrap + icônes (servis localement)
│
├── app/                    ← code applicatif, jamais accessible par le web
│   ├── bootstrap.php           amorçage, ordre de chargement figé
│   ├── config/
│   │   ├── config.php          configuration versionnée, sans secret
│   │   ├── config.local.php    secrets, IGNORÉ PAR GIT
│   │   └── routes.php          table de routes = référence des URL
│   ├── core/                   noyau technique (voir §4)
│   ├── modules/<module>/       code métier
│   │   ├── controllers.php         HTTP : lire, valider, appeler, rediriger
│   │   ├── services.php            règles métier, transactions
│   │   └── repositories.php        requêtes SQL, et rien d'autre
│   └── views/
│       ├── layouts/            app.php (connecté), auth.php (public)
│       ├── partials/           composants réutilisables
│       └── errors/             pages autonomes, sans dépendance base
│
├── database/
│   ├── schema.sql              schéma complet
│   ├── seeds/                  données de référence, numérotées
│   └── install.php             installateur CLI
│
├── storage/                ← hors web, inscriptible
│   ├── logs/  sessions/  cache/  uploads/  backups/
│
├── tests/
└── docs/
```

**Règle** : un fichier PHP de `app/` n'est jamais appelé directement par une URL. Tout passe par `public/index.php`.

---

## 4. Le noyau (`app/core/`)

| Fichier | Rôle |
|---|---|
| `helpers.php` | `config()`, `e()`, chemins, UUID, formatage |
| `logger.php` | Journal fichier avec rotation quotidienne |
| `errors.php` | Erreurs → exceptions ; page de diagnostic en dev, page neutre en production |
| `db.php` | PDO, requêtes préparées, transactions, identifiants SQL sûrs |
| **`tenant.php`** | **Isolation multi-école — voir §5** |
| `session.php` | Sessions durcies : double expiration, rotation d'identifiant, empreinte |
| `csrf.php` | Jeton CSRF, vérifié globalement dans `index.php` |
| `security.php` | Téléversements, normalisation des saisies |
| `validator.php` | Validation serveur déclarative |
| `request.php` / `response.php` | Entrées normalisées, redirections, JSON, en-têtes de sécurité, CSP |
| `flash.php` | Messages éphémères, réaffichage des formulaires |
| `view.php` | Gabarits PHP, nonce CSP, versionnement des ressources |
| `router.php` | Table de routes, middlewares |
| `auth.php` | Connexion, limitation de tentatives, hachage |
| `permission.php` | Permissions, hiérarchie des rôles |
| `audit.php` | Journal d'audit avec masquage des champs sensibles |

---

## 5. Isolation multi-établissements

**C'est le point critique du produit.** Dans un SaaS à base partagée, la faille la plus fréquente n'est pas l'injection SQL : c'est le `WHERE school_id` oublié.

Trois niveaux de protection :

### Niveau 1 — Helpers qui injectent `school_id`

Voie normale. Le développeur n'a rien à penser.

```php
tenant_all('academic_years', 'status = :s', ['s' => 'active']);
tenant_find('students', $id);          // null si l'élève est d'une autre école
tenant_insert('enrollments', $data);   // school_id ajouté automatiquement
tenant_update('students', $data, 'id = :id', ['id' => $id]);
tenant_count('users', 'deleted_at IS NULL');
```

### Niveau 2 — Garde-fou sur les requêtes écrites à la main

`db_query()` inspecte chaque requête. Si elle touche une table déclarée dans `TENANT_TABLES` sans mentionner `school_id` :

- **en développement** : exception immédiate, l'oubli ne peut pas atteindre la production ;
- **en production** : incident journalisé en `ERROR`.

Contournement légitime (super admin, tâche planifiée) :

```php
// Requête volontairement inter-écoles : rapport consolidé plateforme.
db_all('SELECT COUNT(*) FROM academic_years', [], true);
```

Le troisième argument doit **toujours** être accompagné d'un commentaire justifiant pourquoi.

### Niveau 3 — Contexte rechargé depuis la base à chaque requête

`auth_user()` relit l'utilisateur et son établissement en base à chaque requête HTTP, puis appelle `tenant_set()`. Conséquences :

- une session altérée ne peut pas changer d'établissement ;
- un compte désactivé ou une école suspendue perd l'accès **immédiatement**, sans attendre l'expiration de la session.

> **Toute nouvelle table métier doit être ajoutée à `TENANT_TABLES`** dans `app/core/tenant.php`, sans quoi le garde-fou la laisse passer sans contrôle.

---

## 6. Sécurité — récapitulatif

| Menace | Protection |
|---|---|
| Injection SQL | PDO, `ATTR_EMULATE_PREPARES = false`, aucune concaténation. Identifiants dynamiques validés par liste blanche + `db_safe_identifier()` |
| XSS | `e()` sur toute sortie ; CSP stricte, scripts inline autorisés uniquement par nonce |
| CSRF | Jeton vérifié globalement dans `index.php` pour POST/PUT/PATCH/DELETE. Déconnexion en POST |
| Fixation de session | `use_strict_mode`, régénération à la connexion et toutes les 15 min |
| Vol de session | Empreinte navigateur, double expiration, table `user_sessions` révocable |
| Force brute | Double limitation : 5 tentatives par identifiant, 20 par IP, sur 15 min glissantes + verrouillage du compte |
| Énumération de comptes | Message unique quel que soit l'échec ; `password_verify` exécuté même compte absent (durée constante) |
| Élévation de privilège | Hiérarchie `roles.level` : on ne gère qu'un niveau strictement inférieur au sien |
| Téléversement piégé | `is_uploaded_file`, type MIME lu par `finfo`, cohérence extension/MIME, `getimagesize`, nom régénéré, stockage hors web |
| Redirection ouverte | `redirect()` refuse les hôtes externes |
| Fuite d'information | Trace d'exécution jamais affichée en production ; `app/`, `storage/`, `database/` bloqués par `.htaccess` |

---

## 7. Modèle de données — Phase 1

**Référentiel national** (global, sans `school_id`)
`education_cycles` · `education_levels`

**Plateforme**
`plans` · `schools` · `school_cycles` · `school_settings` · `subscriptions` · `subscription_payments`

**Identité**
`users` · `roles` · `permissions` · `role_permissions` · `user_roles`

**Scolarité**
`academic_years`

**Sécurité et synchronisation**
`audit_logs` · `login_attempts` · `password_resets` · `user_sessions` · `sync_devices` · `sync_queue` · `sync_conflicts`

### Décisions notables

**Clés primaires `BIGINT` + `client_uuid`**
Les enregistrements créés hors connexion portent un `client_uuid` généré par le navigateur, servant de clé d'idempotence à la synchronisation. Le serveur attribue l'identifiant définitif. Index compacts, débogage lisible, rejeu sans doublon.

**`is_current` nullable dans `academic_years`**
`1` = année courante, `NULL` = non courante. Un index `UNIQUE(school_id, is_current)` ignore les `NULL` : la base garantit donc elle-même qu'une école n'a jamais deux années courantes. **Ne jamais écrire `0`** dans cette colonne.

**Nom, postnom, prénom**
`last_name` / `post_name` / `first_name` — l'usage congolais, pas le modèle occidental.

**Notation par maxima**
Le bulletin officiel EPST fonctionne par maximum de points par branche et par période (Français /20, Math /40), avec totalisation des points bruts — et non par coefficient multiplicateur. Le schéma des notes suivra ce modèle en phase 4.

**Tables de synchronisation créées dès la phase 1**
Les ajouter plus tard imposerait de migrer des dizaines de milliers de lignes de notes et de présences.

---

## 8. Cycle d'une requête

```
Navigateur
   │
   ▼
public/.htaccess ──────────► réécriture vers index.php
   │
   ▼
public/index.php
   │
   ├─ app/bootstrap.php
   │     ├─ chemins, helpers, journal, gestionnaire d'erreurs
   │     ├─ vérification des dossiers inscriptibles
   │     ├─ session_start_secure()
   │     └─ auth_user()  ──► tenant_set()   (contexte établissement)
   │
   ├─ response_security_headers()           (CSP, HSTS, nosniff…)
   ├─ vérification CSRF globale             (POST/PUT/PATCH/DELETE)
   │
   └─ router_dispatch()
         ├─ middlewares : guest | auth | perm:<code> | platform | school
         ├─ changement de mot de passe imposé si nécessaire
         └─ modules/<module>/controllers.php
               ├─ validate()
               ├─ services.php   ──► règles métier, transactions
               │     └─ repositories.php ──► SQL
               ├─ audit_log()
               └─ view() | redirect() | json_response()
```

---

## 9. Feuille de route

| Phase | Contenu | État |
|---|---|---|
| 1 | Fondation : noyau, base, authentification, rôles, permissions | **Terminée** |
| 2 | Référentiel scolaire : sections, options, matières, programmes | À faire |
| 3 | Élèves : préinscription, inscription, réinscription, parcours, orientation | À faire |
| 4 | Pédagogie : enseignants, évaluations, notes, bulletins, présences, emploi du temps | À faire |
| 5 | Finances : frais, paiements, reçus, impayés, dépenses | À faire |
| 6 | Portails : direction, enseignant, parent, élève | À faire |
| 7 | SaaS : abonnements, quotas, super-admin, paiement mobile | À faire |
| 8 | Hors connexion : PWA, Service Worker, IndexedDB, synchronisation, conflits | À faire |
| 9 | Modules avancés : QR code, documents, discipline, bibliothèque, transport | À faire |
| 10 | Recette : charge, sécurité, multi-écoles, sauvegarde | À faire |
