<?php
/**
 * Inscription d'un nouvel élève.
 *
 * @var array|null $student
 * @var array $year
 * @var array $classrooms
 */
declare(strict_types=1);

set_title('Inscrire un élève');
?>

<div class="page-head">
    <div>
        <nav class="breadcrumb-mini">
            <a href="<?= e(url('/eleves', ['annee' => (int) $year['id']])) ?>">Élèves</a><span>/</span>
        </nav>
        <h1 class="page-title">Inscrire un élève</h1>
        <p class="page-subtitle">
            Année <strong><?= e($year['code']) ?></strong> ·
            le matricule est attribué automatiquement
        </p>
    </div>
</div>

<form method="post" action="<?= e(url('/eleves')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h2 class="card-title">État civil</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="last_name">Nom <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= has_error('last_name') ? 'is-invalid' : '' ?>"
                                   id="last_name" name="last_name" value="<?= old('last_name') ?>"
                                   required maxlength="80" autofocus>
                            <?php if (has_error('last_name')): ?>
                                <div class="invalid-feedback"><?= e(error_for('last_name')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="post_name">Postnom</label>
                            <input type="text" class="form-control" id="post_name" name="post_name"
                                   value="<?= old('post_name') ?>" maxlength="80">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="first_name">Prénom <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= has_error('first_name') ? 'is-invalid' : '' ?>"
                                   id="first_name" name="first_name" value="<?= old('first_name') ?>"
                                   required maxlength="80">
                            <?php if (has_error('first_name')): ?>
                                <div class="invalid-feedback"><?= e(error_for('first_name')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="gender">Sexe <span class="text-danger">*</span></label>
                            <select class="form-select" id="gender" name="gender" required>
                                <option value="">—</option>
                                <option value="M" <?= old('gender') === 'M' ? 'selected' : '' ?>>Masculin</option>
                                <option value="F" <?= old('gender') === 'F' ? 'selected' : '' ?>>Féminin</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="birth_date">Date de naissance</label>
                            <input type="date" class="form-control <?= has_error('birth_date') ? 'is-invalid' : '' ?>"
                                   id="birth_date" name="birth_date" value="<?= old('birth_date') ?>"
                                   max="<?= e(date('Y-m-d')) ?>">
                            <?php if (has_error('birth_date')): ?>
                                <div class="invalid-feedback"><?= e(error_for('birth_date')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="birth_place">Lieu de naissance</label>
                            <input type="text" class="form-control" id="birth_place" name="birth_place"
                                   value="<?= old('birth_place') ?>" maxlength="120">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="nationality">Nationalité</label>
                            <input type="text" class="form-control" id="nationality" name="nationality"
                                   value="<?= old('nationality', 'Congolaise') ?>" maxlength="60">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h2 class="card-title">Coordonnées</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="address">Adresse</label>
                            <input type="text" class="form-control" id="address" name="address"
                                   value="<?= old('address') ?>" maxlength="255"
                                   placeholder="Avenue, numéro, quartier, commune">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="phone">Téléphone</label>
                            <input type="tel" class="form-control <?= has_error('phone') ? 'is-invalid' : '' ?>"
                                   id="phone" name="phone" value="<?= old('phone') ?>" placeholder="0810000000">
                            <?php if (has_error('phone')): ?>
                                <div class="invalid-feedback"><?= e(error_for('phone')) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= old('email') ?>" maxlength="190">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label" for="previous_school">École précédente</label>
                            <input type="text" class="form-control" id="previous_school" name="previous_school"
                                   value="<?= old('previous_school') ?>" maxlength="190">
                            <div class="form-text">
                                Renseignée, l'inscription est enregistrée comme un transfert.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Affectation</h2></div>
                <div class="card-body">
                    <label class="form-label" for="classroom_id">Classe</label>
                    <select class="form-select" id="classroom_id" name="classroom_id">
                        <option value="">Affecter plus tard</option>
                        <?php foreach ($classrooms as $classroom): ?>
                            <?php
                            $full = (int) $classroom['capacity'] > 0
                                && (int) $classroom['student_count'] >= (int) $classroom['capacity'];
                            ?>
                            <option value="<?= (int) $classroom['id'] ?>" <?= $full ? 'disabled' : '' ?>>
                                <?= e($classroom['name']) ?>
                                (<?= (int) $classroom['student_count'] ?>/<?= (int) $classroom['capacity'] ?><?= $full ? ' — complète' : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($classrooms === []): ?>
                        <div class="alert alert-warning mt-3 mb-0 small">
                            Aucune classe pour cette année scolaire.
                            <a href="<?= e(url('/classes', ['annee' => (int) $year['id']])) ?>" class="alert-link">
                                Créer une classe
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="form-text mt-2">
                            Sans classe, le dossier reste au statut « admis » jusqu'à l'affectation.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg"></i> Inscrire l'élève
                    </button>
                    <a href="<?= e(url('/eleves', ['annee' => (int) $year['id']])) ?>"
                       class="btn btn-link w-100 mt-1">Annuler</a>
                    <p class="small text-muted mt-2 mb-0">
                        Les tuteurs se rattachent depuis la fiche de l'élève, après l'inscription.
                    </p>
                </div>
            </div>
        </div>
    </div>
</form>
