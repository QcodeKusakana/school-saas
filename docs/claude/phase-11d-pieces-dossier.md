# Phase 11D — Les pièces du dossier de l'élève

## Objectif

Ranger dans le produit ce que l'école **reçoit** à l'inscription : acte
de naissance, bulletin de l'établissement précédent, pièce du tuteur.

C'est ce que le « dossier numérique unique » du projet promettait depuis
le départ. Sans cet écran, l'école continuait de gérer une chemise en
carton pour les seules pièces qu'elle ne peut pas perdre.

## Ce que cette table n'est pas

| | `documents` (9A) | `student_documents` (11D) |
|---|---|---|
| Sens | ce que l'école **émet** | ce qu'elle **reçoit** |
| Exemples | attestation, certificat, carte | acte de naissance, bulletin antérieur |
| Numéro officiel | oui | non |
| Figeage | oui | non |
| Code de vérification | oui | non |
| Rattachement | inscription | **élève** |

Les mélanger aurait donné une table aux deux tiers vide, où la moitié
des colonnes n'aurait eu de sens que pour la moitié des lignes.

**La pièce appartient à l'élève, pas à son inscription** : un acte de
naissance ne se redépose pas chaque année.

## Tables

`student_documents` — `school_id`, `student_id`, `type`, `label`,
`file_path`, `mime`, `size_bytes`, `uploaded_by`, `created_at`.

| Clé étrangère | Règle | Pourquoi |
|---|---|---|
| `schools` | CASCADE | l'effacement d'une école emporte tout |
| `students` | CASCADE | un dossier archivé emporte ses pièces |
| `users` | **SET NULL** | un compte fermé ne doit pas emporter l'acte de naissance d'un élève |

`uq_studoc_path` : deux lignes ne peuvent pas désigner le même fichier —
en supprimer une rendrait l'autre morte.

Table déclarée dans `TENANT_TABLES`.

## Fichiers

```
database/migrations/2026_09_29_038_phase11d_pieces_dossier.sql
app/modules/students/documents.php     le module (types, service, fichier)
app/modules/students/controllers.php   3 actions
app/views/students/show.php            le bloc dans la fiche élève
app/config/routes.php                  3 routes
app/core/tenant.php                    la table au garde multi-école
tests/student_documents.php            35 tests
tests/pieces_browser.js                15 vérifications
```

## Routes

| Méthode | Chemin | Permission |
|---|---|---|
| POST | `/eleves/{id}/pieces` | `student.document` |
| GET | `/pieces/{id}` | `student.document` |
| POST | `/pieces/{id}/retirer` | `student.document` |

**Lecture comprise** : une pièce de mineur est plus sensible que la fiche
qui la porte. `student.view`, que les enseignants détiennent, serait une
porte trop large.

## Sécurité

- **Le périmètre avant la permission.** `student.document` dit ce qu'on
  peut faire, jamais sur qui : `students_can_view()` décide. C'est la
  leçon de l'audit de la phase 3.
- **Le contenu décide, pas l'extension.** `upload_store()` lit le type
  réel par `finfo`, vérifie la cohérence, refuse un script renommé et
  régénère le nom du fichier. Rien n'est réécrit ici — un second chemin
  de téléversement serait un second jeu de règles.
- **Servi en pièce jointe**, avec `nosniff` et `no-store`. Un PDF rendu
  dans l'onglet exécute son JavaScript dans plusieurs lecteurs et hérite
  alors de la session de qui l'ouvre.
- **Le chemin est reconfiné avant lecture**, même venu de la base : une
  reprise SQL ou une restauration pourrait y glisser un `../`.
- **Le retrait efface le fichier**, et exige un motif journalisé. Le
  fichier part *après* la ligne et hors transaction : un `unlink()` ne se
  défait pas, un fichier orphelin se rattrape.

## Effacement RGPD

Les pièces vivent sous `storage/uploads/documents/{école}/eleves` —
dossier que `erase_dossiers()` connaît déjà. **La 10C les emporte sans
qu'une de ses lignes ait à changer.**

## Comment tester

```
php tests\student_documents.php          REM 35
node tests\pieces_browser.js <motdepasse> REM 15
```

À l'écran : `/eleves/1` avec `admin.demo` → bloc « Pièces du dossier ».
Puis le même écran avec `enseignant.demo` : le bloc est absent, et
`/pieces/{id}` répond 403.

## Dépendances

Phase 3 (élèves et périmètre), phase 9A (stockage hors racine web),
phase 10C (effacement), phase 11B (l'écran des rôles, où la description
de la permission se lit).
