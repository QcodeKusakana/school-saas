<?php
/**
 * Création d'un compte du personnel.
 *
 * L'IDENTIFIANT N'EST PAS SAISI : il se construit sur le nom et se
 * suffixe en cas d'homonyme. Le laisser au choix produirait des
 * « admin2 », « test », « aaa » — et `uq_users_username` est globale,
 * donc le premier arrivé prendrait « direction » pour tout le produit.
 *
 * LE MOT DE PASSE N'EST PAS SAISI NON PLUS : il est tiré au sort et
 * affiché une seule fois. Un mot de passe choisi par l'administrateur
 * est un mot de passe qu'il connaît.
 *
 * @var ?array $user
 * @var array  $roles
 * @var array  $held
 * @var array  $quota
 */
declare(strict_types=1);

set_title('Nouveau compte');
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Nouveau compte</h1>
        <p class="page-subtitle">Un membre du personnel de l'établissement</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/utilisateurs')) ?>">Retour à la liste</a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if (!$quota['ok']): ?>
    <div class="alert alert-danger">
        <strong>Création impossible.</strong>
        <div class="small mt-1"><?= e($quota['message']) ?></div>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/utilisateurs')) ?>">
    <?= csrf_field() ?>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header fw-medium">Identité</div>
                <div class="card-body row g-2">
                    <div class="col-12 col-md-4">
                        <label class="form-label small" for="last_name">Nom <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="last_name" name="last_name"
                               value="<?= e(old('last_name')) ?>" required maxlength="80">
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label small" for="post_name">Postnom</label>
                        <input type="text" class="form-control" id="post_name" name="post_name"
                               value="<?= e(old('post_name')) ?>" maxlength="80">
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label small" for="first_name">Prénom <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="first_name" name="first_name"
                               value="<?= e(old('first_name')) ?>" required maxlength="80">
                    </div>

                    <div class="col-12 col-md-5">
                        <label class="form-label small" for="email">E-mail</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= e(old('email')) ?>" maxlength="190">
                        <div class="form-text small">
                            Facultatif. Beaucoup d'agents n'en ont pas — la connexion se fait
                            par identifiant.
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label small" for="phone">Téléphone</label>
                        <input type="text" class="form-control" id="phone" name="phone"
                               value="<?= e(old('phone')) ?>" maxlength="40">
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label small" for="gender">Sexe</label>
                        <select class="form-select" id="gender" name="gender">
                            <option value="">—</option>
                            <option value="M" <?= old('gender') === 'M' ? 'selected' : '' ?>>Masculin</option>
                            <option value="F" <?= old('gender') === 'F' ? 'selected' : '' ?>>Féminin</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header fw-medium">Rôles <span class="text-danger">*</span></div>
                <div class="card-body">
                    <?php if ($roles === []): ?>
                        <p class="text-danger-emphasis mb-1">Aucun rôle attribuable.</p>
                        <p class="small text-secondary mb-0">
                            On n'attribue que des rôles d'un niveau strictement inférieur au
                            sien. Un compte de votre niveau doit être créé par quelqu'un
                            au-dessus de vous.
                        </p>
                    <?php else: ?>
                        <?php foreach ($roles as $r): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" value="<?= (int) $r['id'] ?>"
                                       id="role<?= (int) $r['id'] ?>" name="roles[]">
                                <label class="form-check-label" for="role<?= (int) $r['id'] ?>">
                                    <span class="fw-medium"><?= e((string) $r['name']) ?></span>
                                    <?php if ($r['description'] !== null && $r['description'] !== ''): ?>
                                        <div class="small text-secondary"><?= e((string) $r['description']) ?></div>
                                    <?php endif; ?>
                                </label>
                            </div>
                        <?php endforeach; ?>

                        <p class="small text-secondary mt-3 mb-0">
                            Un compte sans rôle se connecte et n'ouvre rien.
                            Les rôles <strong>Parent</strong> et <strong>Élève</strong> ne
                            figurent pas ici : ils naissent d'une fiche tuteur ou élève.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info small mt-3">
        L'<strong>identifiant</strong> sera construit sur le nom, et le
        <strong>mot de passe provisoire</strong> tiré au sort. Ils s'afficheront
        <strong>une seule fois</strong>, juste après la création : notez-les alors.
        Le compte devra changer son mot de passe à sa première connexion.
    </div>

    <button class="btn btn-primary" type="submit" <?= $quota['ok'] && $roles !== [] ? '' : 'disabled' ?>>
        Créer le compte
    </button>
</form>
