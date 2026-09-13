<?php
/**
 * Création d'une fiche enseignant.
 *
 * @var array|null $teacher
 * @var array      $statuses
 * @var array      $employmentTypes
 * @var array      $availableUsers
 */
declare(strict_types=1);

set_title('Nouvel enseignant');
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Nouvel enseignant</h1>
        <p class="page-subtitle">
            Le nom s'écrit NOM Postnom Prénom, comme sur les documents officiels.
        </p>
    </div>
    <a href="<?= e(url('/enseignants')) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Retour
    </a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<form method="post" action="<?= e(url('/enseignants')) ?>">
    <?= csrf_field() ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Identité</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="last_name" class="form-label">Nom <span class="text-danger">*</span></label>
                    <input type="text" id="last_name" name="last_name" class="form-control"
                           value="<?= old('last_name') ?>" required maxlength="80" autofocus>
                </div>
                <div class="col-12 col-md-4">
                    <label for="post_name" class="form-label">Postnom</label>
                    <input type="text" id="post_name" name="post_name" class="form-control"
                           value="<?= old('post_name') ?>" maxlength="80">
                </div>
                <div class="col-12 col-md-4">
                    <label for="first_name" class="form-label">Prénom <span class="text-danger">*</span></label>
                    <input type="text" id="first_name" name="first_name" class="form-control"
                           value="<?= old('first_name') ?>" required maxlength="80">
                </div>

                <div class="col-6 col-md-3">
                    <label for="gender" class="form-label">Sexe</label>
                    <select id="gender" name="gender" class="form-select">
                        <option value="">—</option>
                        <option value="M" <?= old('gender') === 'M' ? 'selected' : '' ?>>Masculin</option>
                        <option value="F" <?= old('gender') === 'F' ? 'selected' : '' ?>>Féminin</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="birth_date" class="form-label">Date de naissance</label>
                    <input type="date" id="birth_date" name="birth_date" class="form-control"
                           value="<?= old('birth_date') ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label for="matricule" class="form-label">Matricule</label>
                    <input type="text" id="matricule" name="matricule" class="form-control"
                           value="<?= old('matricule') ?>" maxlength="30" placeholder="SECOPE ou interne">
                </div>
                <div class="col-12 col-md-3">
                    <label for="phone" class="form-label">Téléphone</label>
                    <input type="tel" id="phone" name="phone" class="form-control"
                           value="<?= old('phone') ?>" placeholder="+243…">
                </div>

                <div class="col-12 col-md-6">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="<?= old('email') ?>" maxlength="190">
                </div>
                <div class="col-12 col-md-6">
                    <label for="address" class="form-label">Adresse</label>
                    <input type="text" id="address" name="address" class="form-control"
                           value="<?= old('address') ?>" maxlength="255">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Situation professionnelle</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label for="hire_date" class="form-label">Date d'entrée</label>
                    <input type="date" id="hire_date" name="hire_date" class="form-control"
                           value="<?= old('hire_date') ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label for="employment_type" class="form-label">Type d'engagement</label>
                    <select id="employment_type" name="employment_type" class="form-select">
                        <?php foreach ($employmentTypes as $code => $label): ?>
                            <option value="<?= e($code) ?>" <?= old('employment_type') === $code ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label for="qualification" class="form-label">Diplôme</label>
                    <input type="text" id="qualification" name="qualification" class="form-control"
                           value="<?= old('qualification') ?>" maxlength="120" placeholder="D6, G3, L2…">
                </div>
                <div class="col-12 col-md-3">
                    <label for="specialty" class="form-label">Spécialité</label>
                    <input type="text" id="specialty" name="specialty" class="form-control"
                           value="<?= old('specialty') ?>" maxlength="120" placeholder="Mathématiques…">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Compte de connexion</strong></div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6">
                    <label for="user_id" class="form-label">Compte rattaché</label>
                    <select id="user_id" name="user_id" class="form-select">
                        <option value="">Aucun — l'enseignant ne se connecte pas</option>
                        <?php foreach ($availableUsers as $user): ?>
                            <option value="<?= (int) $user['id'] ?>" <?= old('user_id') === (string) $user['id'] ? 'selected' : '' ?>>
                                <?= e($user['username']) ?> —
                                <?= e(full_name($user['last_name'], $user['post_name'], $user['first_name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <p class="small text-secondary mb-0">
                        Ce rattachement détermine ce que l'enseignant verra en se connectant :
                        uniquement les élèves des classes dont il est titulaire ou dans lesquelles
                        il assure une branche. Sans compte, il n'a aucun accès.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i> Enregistrer
        </button>
        <a href="<?= e(url('/enseignants')) ?>" class="btn btn-outline-secondary">Annuler</a>
    </div>
</form>
