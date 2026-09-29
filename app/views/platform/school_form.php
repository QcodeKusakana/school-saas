<?php
/**
 * CONSOLE — créer ou modifier un établissement (11A).
 *
 * UN SEUL ÉCRAN POUR LES DEUX GESTES, et c'est délibéré : les champs
 * sont les mêmes, et deux formulaires jumeaux divergent toujours au
 * troisième correctif.
 *
 * Ce qui diffère tient en deux blocs :
 *   · à la CRÉATION, les cycles dispensés et le premier administrateur —
 *     sans eux l'école naît muette et sans personne pour l'ouvrir ;
 *   · à la MODIFICATION, ni le code ni le slug : le premier est
 *     l'identité de l'école et figure sur des documents déjà remis, le
 *     second a servi à nommer des dossiers de fichiers.
 *
 * @var ?array $ecole   null en création
 * @var array  $cycles  les cycles proposés (création seulement)
 * @var string $retour
 */
declare(strict_types=1);

$creation = $ecole === null;

set_title($creation ? 'Nouvel établissement' : 'Modifier l\'établissement');

/** La valeur à réafficher : la saisie refusée d'abord, la base ensuite. */
$valeur = static function (string $champ) use ($ecole): string {
    $ancien = old($champ);

    if ($ancien !== '') {
        return $ancien;
    }

    return (string) ($ecole[$champ] ?? '');
};

// Les cycles cochés au retour d'un refus. Au premier affichage, la
// proposition raisonnable pour la RDC : primaire et CTEB.
$cyclesCoches = $creation ? old_array('cycles', ['PRIMAIRE', 'CTEB']) : [];

$types = [
    'public'       => 'Public',
    'conventionne' => 'Conventionné',
    'prive'        => 'Privé',
    'autre'        => 'Autre',
];
?>

<div class="page-head">
    <div>
        <h1 class="page-title">
            <?= $creation ? 'Nouvel établissement' : e((string) $ecole['name']) ?>
        </h1>
        <p class="page-subtitle">
            <?php if ($creation): ?>
                L'établissement recevra son code, son année scolaire courante,
                un essai de 60 jours et un compte d'administration.
            <?php else: ?>
                Code <code><?= e((string) $ecole['code']) ?></code> — il ne se modifie pas.
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url($retour)) ?>">Retour</a>
</div>

<form method="post"
      action="<?= e(url($creation
          ? '/plateforme/ecoles'
          : '/plateforme/ecoles/' . (int) $ecole['id'] . '/modifier')) ?>">
    <?= csrf_field() ?>

    <div class="card mb-3">
        <div class="card-header fw-medium">L'établissement</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="name">Nom complet <span class="text-danger">*</span></label>
                    <input class="form-control<?= has_error('name') ? ' is-invalid' : '' ?>"
                           id="name" name="name" required minlength="3" maxlength="190"
                           value="<?= e($valeur('name')) ?>">
                    <div class="invalid-feedback"><?= e(error_for('name')) ?></div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="short_name">Sigle</label>
                    <input class="form-control" id="short_name" name="short_name" maxlength="60"
                           value="<?= e($valeur('short_name')) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="school_type">Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="school_type" name="school_type" required>
                        <option value="">—</option>
                        <?php foreach ($types as $code => $libelle): ?>
                            <option value="<?= e($code) ?>"
                                <?= $valeur('school_type') === $code ? 'selected' : '' ?>>
                                <?= e($libelle) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="province">Province</label>
                    <input class="form-control" id="province" name="province" maxlength="100"
                           value="<?= e($valeur('province')) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="city">Ville</label>
                    <input class="form-control" id="city" name="city" maxlength="100"
                           value="<?= e($valeur('city')) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="commune">Commune</label>
                    <input class="form-control" id="commune" name="commune" maxlength="100"
                           value="<?= e($valeur('commune')) ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="address">Adresse</label>
                    <input class="form-control" id="address" name="address" maxlength="255"
                           value="<?= e($valeur('address')) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="phone">Téléphone</label>
                    <input class="form-control" id="phone" name="phone" maxlength="40"
                           value="<?= e($valeur('phone')) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="email">Adresse e-mail</label>
                    <input class="form-control" type="email" id="email" name="email" maxlength="190"
                           value="<?= e($valeur('email')) ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="director_name">Nom du chef d'établissement</label>
                    <input class="form-control" id="director_name" name="director_name" maxlength="150"
                           value="<?= e($valeur('director_name')) ?>">
                </div>

                <?php if (!$creation): ?>
                    <div class="col-md-4">
                        <label class="form-label" for="sernie_number">Numéro SERNIE</label>
                        <input class="form-control" id="sernie_number" name="sernie_number" maxlength="50"
                               value="<?= e($valeur('sernie_number')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="approval_number">Numéro d'agrément</label>
                        <input class="form-control" id="approval_number" name="approval_number" maxlength="50"
                               value="<?= e($valeur('approval_number')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="phone_alt">Téléphone secondaire</label>
                        <input class="form-control" id="phone_alt" name="phone_alt" maxlength="40"
                               value="<?= e($valeur('phone_alt')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="website">Site web</label>
                        <input class="form-control" id="website" name="website" maxlength="190"
                               value="<?= e($valeur('website')) ?>">
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($creation): ?>
        <div class="card mb-3">
            <div class="card-header fw-medium">Cycles dispensés</div>
            <div class="card-body">
                <p class="text-muted small">
                    Le référentiel national s'attache aux cycles. Sans cycle, l'écran
                    des classes n'a rien à proposer et l'école découvre le produit
                    par une page vide.
                </p>

                <?php foreach ($cycles as $cycle): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               id="cycle-<?= e((string) $cycle['code']) ?>"
                               name="cycles[]" value="<?= e((string) $cycle['code']) ?>"
                            <?= in_array((string) $cycle['code'], $cyclesCoches, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cycle-<?= e((string) $cycle['code']) ?>">
                            <?= e((string) $cycle['name']) ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-medium">Premier administrateur</div>
            <div class="card-body">
                <p class="text-muted small">
                    Son mot de passe sera tiré au hasard et affiché <strong>une seule
                    fois</strong>, après la création. Il ne sera ni enregistré au journal
                    ni relisible ensuite : notez-le, ou l'école passera par
                    « mot de passe oublié ». Le changement est imposé à la première
                    connexion.
                </p>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="admin_last_name">Nom <span class="text-danger">*</span></label>
                        <input class="form-control" id="admin_last_name" name="admin_last_name"
                               required maxlength="100" value="<?= e(old('admin_last_name')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="admin_first_name">Prénom <span class="text-danger">*</span></label>
                        <input class="form-control" id="admin_first_name" name="admin_first_name"
                               required maxlength="100" value="<?= e(old('admin_first_name')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="admin_username">Identifiant <span class="text-danger">*</span></label>
                        <input class="form-control" id="admin_username" name="admin_username"
                               required pattern="[a-z0-9._-]{4,50}" maxlength="50"
                               value="<?= e(old('admin_username')) ?>">
                        <div class="form-text">Minuscules, chiffres, point, tiret — unique sur la plateforme.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="admin_email">Adresse e-mail</label>
                        <input class="form-control" type="email" id="admin_email" name="admin_email"
                               maxlength="190" value="<?= e(old('admin_email')) ?>">
                        <div class="form-text">Sans elle, « mot de passe oublié » ne fonctionnera pas pour ce compte.</div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">
            <?= $creation ? 'Créer l\'établissement' : 'Enregistrer' ?>
        </button>
        <a class="btn btn-outline-secondary" href="<?= e(url($retour)) ?>">Annuler</a>
    </div>
</form>
