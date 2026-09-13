# School SaaS RDC

Plateforme SaaS de gestion scolaire et de suivi du parcours de l'élève, conçue pour les établissements de la République Démocratique du Congo.

**Version 0.1.0 — Phase 1 : Fondation**

---

## Installation en local (Laragon / Windows)

### 1. Prérequis

- PHP **8.1 ou supérieur** (extensions `pdo_mysql`, `mbstring`, `fileinfo`, `json`)
- MySQL 8 ou MariaDB 10.3+
- Apache avec `mod_rewrite` activé

Laragon fournit l'ensemble par défaut.

### 2. Configuration

```powershell
cd C:\laragon\www\school-saas
copy app\config\config.local.example.php app\config\config.local.php
```

Ouvrez `app\config\config.local.php` et renseignez vos identifiants MySQL. Sous Laragon, les valeurs par défaut (`root`, mot de passe vide) conviennent en général.

### 3. Base de données

```powershell
php database\install.php --demo
```

Le script crée la base, le schéma, le référentiel scolaire RDC, les rôles et permissions, puis demande les identifiants du super administrateur. L'option `--demo` ajoute un établissement de démonstration prêt à l'emploi.

> Sans accès à la ligne de commande (hébergement mutualisé), importez manuellement via phpMyAdmin, **dans cet ordre** :
> `database/schema.sql` → `database/seeds/001_reference_data.sql` → `database/seeds/002_roles_permissions.sql`

### 4. Racine web

La racine web doit pointer sur le dossier **`public/`**, jamais sur la racine du projet.

Sous Laragon, l'hôte virtuel `http://school-saas.test` est créé automatiquement et pointe sur la racine du projet : le `.htaccess` à la racine assure alors la redirection vers `public/` et bloque l'accès à `app/`, `storage/` et `database/`.

Pour un hôte virtuel propre, dans `C:\laragon\etc\apache2\sites-enabled\` :

```apache
<VirtualHost *:80>
    ServerName school-saas.test
    DocumentRoot "C:/laragon/www/school-saas/public"
    <Directory "C:/laragon/www/school-saas/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 5. Vérification

Ouvrez `http://school-saas.test` et connectez-vous avec le compte affiché à la fin de l'installation.

---

## Vérifications

```powershell
# Syntaxe de tous les fichiers PHP
Get-ChildItem -Recurse -Filter *.php app,database,public,tests |
    ForEach-Object { php -l $_.FullName } | Select-String -NotMatch "No syntax errors"

# Isolation multi-établissements (17 contrôles)
php tests\tenant_isolation.php
```

---

## Ce que contient la phase 1

**Noyau technique**
Point d'entrée unique · routeur à middlewares · PDO avec requêtes préparées natives · sessions durcies · CSRF global · validation serveur · journalisation · gestion centralisée des erreurs · téléversements sécurisés

**Isolation multi-établissements**
Helpers `tenant_*` injectant `school_id` · garde-fou bloquant toute requête non filtrée · contexte rechargé en base à chaque requête

**Authentification**
Connexion par identifiant ou email · double limitation des tentatives (identifiant + IP) · verrouillage de compte · mot de passe oublié à jeton haché à usage unique · changement de mot de passe imposé et non contournable · révocation des sessions

**Droits**
9 rôles système · 67 permissions réparties sur 18 modules · hiérarchie par niveau empêchant l'élévation de privilège

**Base de données**
21 tables · 28 clés étrangères · référentiel scolaire RDC complet (4 cycles, 15 niveaux) · journal d'audit · socle de synchronisation hors connexion

**Interface**
Bootstrap 5.3 servi localement · responsive téléphone / tablette / ordinateur · tiroir de navigation sur mobile · protection contre la double soumission · indicateur de connexion réseau

---

## Documentation

| Document | Contenu |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Structure, noyau, isolation multi-école, modèle de données, sécurité |
| [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md) | Nommage, séparation des couches, règles SQL, vues, Git |

**À lire avant toute contribution** : la section « Isolation multi-établissements » de `ARCHITECTURE.md`.

---

## Mise en production

Avant toute mise en ligne :

- [ ] `app.debug` à **`false`** dans `config.local.php`
- [ ] `session.cookie_secure` à **`true`** (exige HTTPS)
- [ ] Racine web pointant sur `public/`
- [ ] `storage/` inscriptible (chmod 775) et **non accessible** depuis le web
- [ ] `config.local.php` absent du dépôt Git
- [ ] Sauvegarde automatique de la base configurée
- [ ] Mot de passe MySQL dédié à l'application, jamais `root`

---

## Feuille de route

| Phase | Contenu | État |
|---|---|---|
| 1 | Fondation | **Terminée** |
| 2 | Référentiel scolaire (sections, options, matières, programmes) | À faire |
| 3 | Élèves (inscription, réinscription, parcours, orientation) | À faire |
| 4 | Pédagogie (notes, bulletins, présences, emploi du temps) | À faire |
| 5 | Finances | À faire |
| 6 | Portails parent, élève, enseignant | À faire |
| 7 | Abonnements SaaS | À faire |
| 8 | Mode hors connexion (PWA) | À faire |
| 9 | Modules avancés (QR code, documents, discipline) | À faire |
| 10 | Recette et mise en production | À faire |
