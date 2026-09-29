<?php
/**
 * RÔLES — composer (11B).
 *
 * L'ÉCRAN DIT CE QU'IL NE PEUT PAS FAIRE, ET POURQUOI.
 * Une permission que l'utilisateur ne détient pas apparaît, grisée, avec
 * sa raison. La masquer laisserait croire qu'elle n'existe pas ; la
 * montrer cochable serait un mensonge que le service démentirait après
 * coup.
 *
 * @var ?array $role
 * @var array  $accordees    identifiants déjà accordés
 * @var array  $permissions  catalogue accordable (hors plateforme)
 * @var array  $miennes      codes que l'utilisateur détient
 * @var int    $niveau
 * @var ?string $refus       pourquoi ce rôle est en lecture seule
 */
declare(strict_types=1);

$creation = $role === null;
$refus    = $refus ?? null;
$porteurs = $porteurs ?? 0;
$lecture  = !$creation && ($refus !== null || !can('role.manage'));

set_title($creation ? 'Nouveau rôle' : (string) $role['name']);

$anciennes = old_array('permissions');
$cochees   = $anciennes !== []
    ? array_map('intval', $anciennes)
    : array_map('intval', $accordees);

$parModule = [];

foreach ($permissions as $permission) {
    $parModule[(string) $permission['module']][] = $permission;
}

ksort($parModule);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">
            <?= $creation ? 'Nouveau rôle' : e((string) $role['name']) ?>
        </h1>
        <p class="page-subtitle">
            <?php if ($lecture): ?>
                <?= e((string) ($refus ?? 'Lecture seule.')) ?>
            <?php else: ?>
                Un rôle ne peut porter que des permissions que vous détenez, et
                son niveau reste strictement inférieur au vôtre (<?= (int) $niveau ?>).
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/roles')) ?>">Retour</a>
</div>

<?php if ($lecture): ?>
    <div class="alert alert-secondary"><?= e((string) ($refus ?? '')) ?></div>
<?php endif; ?>

<form method="post" id="form-role"
      action="<?= e(url($creation ? '/roles' : '/roles/' . (int) $role['id'])) ?>">
    <?= csrf_field() ?>

    <div class="card mb-3">
        <div class="card-header fw-medium">Le rôle</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="name">Nom <span class="text-danger">*</span></label>
                    <input class="form-control" id="name" name="name" required
                           minlength="3" maxlength="100" <?= $lecture ? 'disabled' : '' ?>
                           value="<?= e(old('name', (string) ($role['name'] ?? ''))) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="level">Niveau <span class="text-danger">*</span></label>
                    <input class="form-control" type="number" id="level" name="level" required
                           min="1" max="<?= max(1, $niveau - 1) ?>" <?= $lecture ? 'disabled' : '' ?>
                           value="<?= e(old('level', (string) ($role['level'] ?? ''))) ?>">
                    <div class="form-text">1 à <?= max(1, $niveau - 1) ?> — le vôtre est <?= (int) $niveau ?>.</div>
                </div>

                <div class="col-md-3">
                    <?php if (!$creation): ?>
                        <label class="form-label">Comptes concernés</label>
                        <p class="form-control-plaintext"><?= (int) $porteurs ?></p>
                    <?php endif; ?>
                </div>

                <?php if (!$creation && !$lecture && $porteurs > 0): ?>
                    <div class="col-12">
                        <label class="form-label" for="reason">
                            Motif — exigé si vous DÉCOCHEZ une permission
                        </label>
                        <input class="form-control" id="reason" name="reason" maxlength="255"
                               value="<?= e(old('reason')) ?>">
                        <div class="form-text">
                            <?= (int) $porteurs ?> compte(s) portent ce rôle. Retirer une
                            permission leur retire l'accès immédiatement : le motif reste
                            au journal pour qu'on sache, plus tard, pourquoi.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="col-12">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" id="description" name="description" maxlength="255"
                           <?= $lecture ? 'disabled' : '' ?>
                           value="<?= e(old('description', (string) ($role['description'] ?? ''))) ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header fw-medium d-flex justify-content-between align-items-center">
            <span>Permissions</span>
            <span class="small text-muted">
                Les capacités de la plateforme n'apparaissent pas : elles ne
                s'accordent jamais depuis un établissement.
            </span>
        </div>
        <div class="card-body">
            <?php foreach ($parModule as $module => $liste): ?>
                <h3 class="h6 text-uppercase text-muted mt-3"><?= e($module) ?></h3>

                <div class="row g-2">
                    <?php foreach ($liste as $permission): ?>
                        <?php
                        $detenue = in_array((string) $permission['code'], $miennes, true);
                        $id      = (int) $permission['id'];
                        ?>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       id="perm-<?= $id ?>" name="permissions[]" value="<?= $id ?>"
                                    <?= in_array($id, $cochees, true) ? 'checked' : '' ?>
                                    <?= ($lecture || !$detenue) ? 'disabled' : '' ?>>
                                <label class="form-check-label<?= $detenue ? '' : ' text-muted' ?>"
                                       for="perm-<?= $id ?>">
                                    <?= e((string) $permission['name']) ?>
                                    <?php if (!$detenue): ?>
                                        <span class="badge bg-light text-muted border">vous ne la détenez pas</span>
                                    <?php endif; ?>
                                    <?php if ($permission['description'] !== null): ?>
                                        <span class="d-block small text-muted">
                                            <?= e((string) $permission['description']) ?>
                                        </span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$lecture): ?>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
                <?= $creation ? 'Créer le rôle' : 'Enregistrer' ?>
            </button>
            <a class="btn btn-outline-secondary" href="<?= e(url('/roles')) ?>">Annuler</a>
        </div>
    <?php endif; ?>
</form>
