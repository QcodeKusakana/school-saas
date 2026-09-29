<?php
/**
 * RÔLES — la liste (11B).
 *
 * Elle montre côte à côte les rôles LIVRÉS — communs à toute la
 * plateforme, non modifiables — et ceux que l'établissement a composés.
 * Les distinguer d'un coup d'œil évite la question « pourquoi ne puis-je
 * pas modifier Direction ? ».
 *
 * @var array $roles
 * @var int   $niveau
 */
declare(strict_types=1);

set_title('Rôles et permissions');
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Rôles et permissions</h1>
        <p class="page-subtitle">
            Qui peut faire quoi dans l'établissement. Votre niveau : <strong><?= (int) $niveau ?></strong> —
            vous ne composez que des rôles d'un niveau inférieur.
        </p>
    </div>

    <?php if (can('role.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('/roles/nouveau')) ?>">
            <i class="bi bi-plus-lg"></i> Nouveau rôle
        </a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Rôle</th>
                    <th style="width:90px" class="text-center">Niveau</th>
                    <th style="width:120px" class="text-center">Permissions</th>
                    <th style="width:110px" class="text-center">Comptes</th>
                    <th style="width:150px">Origine</th>
                    <th style="width:170px"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                    <?php
                    $systeme   = (int) $role['is_system'] === 1;
                    $actif     = (int) $role['is_active'] === 1;
                    $modifiable = !$systeme
                        && $role['school_id'] !== null
                        && (int) $role['level'] < $niveau;
                    ?>
                    <tr class="<?= $actif ? '' : 'opacity-50' ?>">
                        <td>
                            <span class="fw-semibold"><?= e((string) $role['name']) ?></span>
                            <?php if (!$actif): ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">désactivé</span>
                            <?php endif; ?>
                            <?php if ($role['description'] !== null): ?>
                                <span class="d-block small text-muted"><?= e((string) $role['description']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= (int) $role['level'] ?></td>
                        <td class="text-center"><?= (int) $role['permissions'] ?></td>
                        <td class="text-center"><?= (int) $role['comptes'] ?></td>
                        <td class="small">
                            <?php if ($systeme): ?>
                                <span class="text-muted">Livré avec le produit</span>
                            <?php elseif ($role['school_id'] === null): ?>
                                <span class="text-muted">Commun à la plateforme</span>
                            <?php else: ?>
                                <span class="text-success-emphasis">Votre établissement</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e(url('/roles/' . (int) $role['id'])) ?>">
                                <?= $modifiable && can('role.manage') ? 'Modifier' : 'Voir' ?>
                            </a>

                            <?php if ($modifiable && can('role.manage') && $actif): ?>
                                <!--
                                    UNE ICÔNE SEULE N'EST PAS UN LIBELLÉ.
                                    Le bouton portait un pictogramme barré et rien d'autre :
                                    illisible pour un lecteur d'écran, et une devinette pour
                                    un secrétariat qui hésite déjà à toucher aux droits.
                                -->
                                <button class="btn btn-sm btn-outline-warning" type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#etat-<?= (int) $role['id'] ?>">
                                    <i class="bi bi-slash-circle"></i> Désactiver
                                </button>
                            <?php elseif ($modifiable && can('role.manage')): ?>
                                <form method="post" class="d-inline"
                                      action="<?= e(url('/roles/' . (int) $role['id'] . '/etat')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="actif" value="oui">
                                    <button class="btn btn-sm btn-outline-success" type="submit">Réactiver</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($modifiable && can('role.manage') && $actif): ?>
                        <tr class="collapse" id="etat-<?= (int) $role['id'] ?>">
                            <td colspan="6" class="bg-light">
                                <form method="post" class="row g-2 align-items-end"
                                      action="<?= e(url('/roles/' . (int) $role['id'] . '/etat')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="actif" value="non">

                                    <div class="col-md-8">
                                        <label class="form-label small"
                                               for="reason-<?= (int) $role['id'] ?>">
                                            Motif —
                                            <?php if ((int) $role['comptes'] > 0): ?>
                                                <strong><?= (int) $role['comptes'] ?> compte(s)</strong>
                                                perdront ces accès à leur requête suivante
                                            <?php else: ?>
                                                aucun compte ne le porte
                                            <?php endif; ?>
                                        </label>
                                        <input class="form-control form-control-sm"
                                               id="reason-<?= (int) $role['id'] ?>" name="reason"
                                               maxlength="255"
                                               placeholder="10 caractères minimum dès qu'un compte le porte">
                                    </div>

                                    <div class="col-md-4">
                                        <button class="btn btn-sm btn-outline-warning" type="submit">
                                            Désactiver ce rôle
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
