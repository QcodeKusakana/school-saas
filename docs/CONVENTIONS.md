# Conventions de développement

Ces règles ne sont pas des préférences de style : elles sont ce qui permet à un projet **procédural** de plusieurs dizaines de milliers de lignes de rester lisible et sûr. En PHP procédural, toutes les fonctions partagent un espace de noms global — sans discipline de nommage, les collisions et les responsabilités floues arrivent vite.

---

## 1. Nommage des fonctions

Chaque fonction porte le préfixe de son module.

| Couche | Préfixe | Exemples |
|---|---|---|
| Noyau | nom du composant | `db_query()`, `auth_attempt()`, `perm_has()`, `tenant_find()` |
| Utilitaires globaux | aucun (liste fermée) | `e()`, `config()`, `view()`, `url()`, `can()`, `now()` |
| Contrôleur métier | `ctrl_<module>_<action>` | `ctrl_students_index()`, `ctrl_grades_store()` |
| Service métier | `<module>_service_<action>` | `students_service_enroll()`, `grades_service_compute()` |
| Dépôt métier | `<module>_repo_<action>` | `students_repo_find_by_matricule()` |

**Interdit** : déclarer une fonction sans préfixe dans un module métier. `save()`, `getAll()`, `format()` sont des collisions en attente.

---

## 2. Séparation des responsabilités

```
controllers.php    HTTP uniquement : lire l'entrée, valider, appeler un
                   service, rediriger ou rendre une vue.
                   → AUCUNE requête SQL, AUCUNE règle métier.

services.php       Règles métier, transactions, orchestration.
                   → Ne connaît ni $_POST, ni les vues, ni les redirections.
                   → Reçoit des valeurs, retourne un résultat.

repositories.php   Requêtes SQL, et rien d'autre.
                   → Aucune règle métier, aucune décision.
```

Test simple : si une fonction de `services.php` lit `$_POST`, elle est mal placée. Si une fonction de `controllers.php` contient `SELECT`, elle est mal placée.

---

## 3. Base de données

### Toujours

```php
db_all('SELECT * FROM students WHERE school_id = :sid AND status = :s',
       ['sid' => tenant_require(), 's' => 'active']);

tenant_all('students', 'status = :s', ['s' => 'active']);   // préférable
```

### Jamais

```php
db_all("SELECT * FROM students WHERE name = '$name'");      // injection SQL
db_all('SELECT * FROM students');                           // fuite entre écoles
```

### Paramètres nommés : ne jamais réutiliser le même

Les requêtes préparées natives (`ATTR_EMULATE_PREPARES = false`) l'interdisent — le pilote MySQL rejette la requête.

```php
// ✗ Rejeté par MySQL
'WHERE username = :id OR email = :id', ['id' => $value]

// ✓ Deux noms distincts pour une même valeur
'WHERE username = :username OR email = :email',
['username' => $value, 'email' => $value]
```

### Tables et colonnes dynamiques

Elles ne viennent **jamais** d'une saisie utilisateur. Pour un tri :

```php
$order = db_order_by(
    (string) input('sort', ''),
    (string) input('dir', ''),
    ['last_name', 'created_at', 'matricule'],   // liste blanche
    'last_name'
);
```

### Transactions

Toute opération écrivant dans plusieurs tables :

```php
db_transaction(function () use ($data) {
    $studentId = tenant_insert('students', $data);
    tenant_insert('enrollments', ['student_id' => $studentId, /* … */]);
    audit_log('create', 'student', $studentId, null, $data, 'Inscription');
    return $studentId;
});
```

### Nouvelle table métier

1. La créer avec `school_id BIGINT UNSIGNED NOT NULL` et sa clé étrangère.
2. **L'ajouter à `TENANT_TABLES`** dans `app/core/tenant.php`.
3. Indexer `(school_id, <colonne de filtre habituelle>)`.
4. Fournir la migration dans `database/migrations/`.

---

## 4. Vues

### Échappement obligatoire

```php
<?= e($student['last_name']) ?>                        <!-- texte -->
<input value="<?= e($student['phone']) ?>">            <!-- attribut -->
<?= script_tag('', 'const id = ' . e_js($id) . ';') ?> <!-- JavaScript -->
```

Une sortie non échappée doit être précédée d'un commentaire expliquant pourquoi c'est sûr.

### Scripts et CSP

La politique de sécurité interdit les scripts inline sans nonce. Toujours passer par `script_tag()` — un `<script>` écrit à la main sera silencieusement bloqué par le navigateur.

### Masquer n'est pas protéger

```php
<?php if (can('student.create')): ?>
    <a href="<?= e(url('/eleves/nouveau')) ?>">Inscrire un élève</a>
<?php endif; ?>
```

Cacher le bouton est un confort d'interface. La **vraie** protection est le middleware `perm:student.create` sur la route.

---

## 5. Validation

Systématiquement côté serveur. La validation JavaScript est un confort, jamais une sécurité.

```php
$result = validate(input_all(), [
    'last_name'  => 'required|string|max:80',
    'first_name' => 'required|string|max:80',
    'birth_date' => 'required|date|before:today',
    'email'      => 'nullable|email|max:190|unique:users,email',
    'level_id'   => 'required|int|exists:education_levels,id',
    'gender'     => 'required|in:M,F',
], [
    'last_name' => 'Nom',
    'level_id'  => 'Niveau',
]);

if (!validator_passes($result)) {
    flash_errors(validator_errors($result));
    redirect_back();
}

$data = validator_data($result);
```

`exists:` et `unique:` appliquent automatiquement le filtre `school_id` sur les tables multi-école : un identifiant forgé dans un formulaire ne peut pas désigner une ressource d'un autre établissement.

---

## 6. Routes

```php
route('GET',  '/eleves',            'students', 'ctrl_students_index',  ['auth', 'perm:student.view']);
route('GET',  '/eleves/{id}',       'students', 'ctrl_students_show',   ['auth', 'perm:student.view']);
route('POST', '/eleves',            'students', 'ctrl_students_store',  ['auth', 'perm:student.create']);
```

- URL en **français** : ce sont des utilisateurs francophones qui les lisent.
- `{id}` n'accepte que des chiffres ; les autres paramètres acceptent `[a-zA-Z0-9_-]`.
- Une route sans middleware `auth` est **publique** — cela doit se voir à la lecture.

---

## 7. Journal d'audit

À écrire pour toute action sensible : création, modification, suppression, encaissement, modification de note, export, changement de droits.

```php
audit_log('create', 'payment', $paymentId, null, $data, 'Encaissement de frais scolaires');
audit_update('student', $id, $before, $after, 'Modification du dossier');
```

Les champs contenant `password`, `token`, `secret` ou `api_key` sont masqués automatiquement.

---

## 8. Erreurs

**Jamais** :

```php
@mysqli_query(...);          // masque l'erreur
error_reporting(0);          // masque toutes les erreurs
try { ... } catch (Throwable $e) {}   // avale l'exception silencieusement
```

Une erreur attrapée est soit journalisée, soit relancée, soit traduite en message utilisateur. Jamais ignorée.

---

## 9. Style

- Indentation : **4 espaces**, jamais de tabulation.
- `declare(strict_types=1);` en tête de chaque fichier PHP.
- Accolade ouvrante sur une nouvelle ligne pour les fonctions, sur la même ligne pour les structures de contrôle (PSR-12).
- Longueur de ligne : viser 120 caractères.
- Un commentaire explique **pourquoi**, pas **quoi**. `// incrémente i` est du bruit ; `// sans cette vérification, un chemin forgé désignerait un fichier arbitraire` a de la valeur.
- Commentaires et messages utilisateur en **français**. Noms de variables, fonctions et colonnes en **anglais**.

---

## 10. Git

**Messages de commit**

```
<type>(<portée>): <description à l'impératif>

feat(students): inscription et génération automatique du matricule
fix(auth): corrige la réutilisation du paramètre nommé à la connexion
security(tenant): bloque les requêtes sans filtre school_id
refactor(grades): extrait le calcul des moyennes dans un service
docs(architecture): documente la stratégie d'isolation multi-école
```

Types : `feat`, `fix`, `security`, `refactor`, `perf`, `docs`, `test`, `chore`.

**Branches**

```
main                    production, toujours déployable
develop                 intégration
feature/<phase>-<nom>   ex: feature/p3-inscriptions
fix/<nom>
```

**Ne jamais committer** : `app/config/config.local.php`, `storage/**` (hors `.gitkeep`), `vendor/`, un identifiant ou un mot de passe réel.

---

## 11. Avant chaque commit

```powershell
# Syntaxe de tous les fichiers PHP (PowerShell, Windows)
Get-ChildItem -Recurse -Filter *.php app,database,public,tests |
    ForEach-Object { php -l $_.FullName } | Select-String -NotMatch "No syntax errors"

# Isolation multi-école
php tests/tenant_isolation.php
```

Les deux doivent être sans erreur.
