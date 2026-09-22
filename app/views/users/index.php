<?php
/**
 * Les comptes du personnel de l'établissement.
 *
 * LES COMPTES DE FAMILLES N'Y SONT PAS, et l'écran le dit : sinon
 * l'école cherchera longtemps le compte d'un parent dans une liste
 * qui, par construction, ne le contiendra jamais.
 *
 * @var array $users
 * @var int   $total
 * @var int   $pages
 * @var int   $page
 * @var array $filters
 * @var array $statuses
 * @var array $roles
 * @var array $counts
 * @var array $quota
 */
declare(strict_types=1);

set_title('Utilisateurs');

$badge = [
    'active'    => 'bg-success-subtle text-success-emphasis',
    'pending'   => 'bg-info-subtle text-info-emphasis',
    'inactive'  => 'bg-secondary-subtle text-secondary-emphasis',
    'suspended' => 'bg-danger-subtle text-danger-emphasis',
];

$showArchived = $filters['archived'] === '1';
$hasFilter    = $filters['q'] !== '' || $filters['status'] !== ''
             || $filters['role'] !== '' || $showArchived;
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Utilisateurs</h1>
        <p class="page-subtitle">
            <?= (int) $total ?> compte(s) du personnel
            <?php if ($counts['archived'] > 0): ?>
                · <?= (int) $counts['archived'] ?> archivé(s)
            <?php endif; ?>
        </p>
    </div>

    <?php if (can('user.create')): ?>
        <a href="<?= e(url('/utilisateurs/nouveau')) ?>" class="btn btn-primary">
            <i class="bi bi-person-plus me-1"></i> Nouveau compte
        </a>
    <?php endif; ?>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if (!$quota['ok']): ?>
    <div class="alert alert-warning">
        <strong>Aucun nouveau compte ne peut être créé.</strong>
        <div class="small mt-1"><?= e($quota['message']) ?></div>
        <div class="small mt-1">
            Désactiver ou archiver un compte libère une place immédiatement.
        </div>
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?= e(url('/utilisateurs')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="q" class="form-label form-label-sm">Nom ou identifiant</label>
                <input type="search" id="q" name="q" class="form-control form-control-sm"
                       value="<?= e($filters['q']) ?>" placeholder="Début du nom…">
            </div>

            <div class="col-6 col-md-2">
                <label for="statut" class="form-label form-label-sm">État</label>
                <select id="statut" name="statut" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    <?php foreach ($statuses as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $filters['status'] === $code ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-3">
                <label for="role" class="form-label form-label-sm">Rôle</label>
                <select id="role" name="role" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= e((string) $r['code']) ?>"
                            <?= $filters['role'] === $r['code'] ? 'selected' : '' ?>>
                            <?= e((string) $r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-3 d-flex gap-2 align-items-center">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="archives"
                           name="archives" <?= $showArchived ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="archives">Voir les archivés</label>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-primary">Filtrer</button>
                <?php if ($hasFilter): ?>
                    <a href="<?= e(url('/utilisateurs')) ?>" class="btn btn-sm btn-outline-secondary">
                        Effacer
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Nom</th>
                    <th scope="col">Identifiant</th>
                    <th scope="col">Rôles</th>
                    <th scope="col">État</th>
                    <th scope="col" class="d-none d-lg-table-cell">Dernière connexion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr<?= $u['deleted_at'] !== null ? ' class="opacity-50"' : '' ?>>
                        <td>
                            <a class="fw-medium text-decoration-none"
                               href="<?= e(url('/utilisateurs/' . (int) $u['id'])) ?>">
                                <?= e((string) $u['last_name']) ?>
                                <?= e((string) ($u['post_name'] ?? '')) ?>
                                <?= e((string) $u['first_name']) ?>
                            </a>
                            <?php if ($u['deleted_at'] !== null): ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">archivé</span>
                            <?php endif; ?>
                            <?php if ($u['email'] !== null && $u['email'] !== ''): ?>
                                <div class="small text-secondary"><?= e((string) $u['email']) ?></div>
                            <?php endif; ?>
                        </td>

                        <td class="small"><code><?= e((string) $u['username']) ?></code></td>

                        <td class="small">
                            <?php foreach ($u['roles'] ?? [] as $r): ?>
                                <span class="badge bg-light text-dark border"><?= e((string) $r['name']) ?></span>
                            <?php endforeach; ?>
                            <?php if (($u['roles'] ?? []) === []): ?>
                                <span class="text-danger-emphasis">aucun rôle</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge <?= e($badge[$u['status']] ?? 'bg-secondary-subtle') ?>">
                                <?= e($statuses[$u['status']] ?? (string) $u['status']) ?>
                            </span>
                            <?php if ($u['locked_until'] !== null
                                      && strtotime((string) $u['locked_until']) > time()): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis">verrouillé</span>
                            <?php endif; ?>
                            <?php if ((int) $u['must_change_password'] === 1): ?>
                                <span class="badge bg-info-subtle text-info-emphasis"
                                      title="Doit changer son mot de passe">nouveau</span>
                            <?php endif; ?>
                        </td>

                        <td class="small d-none d-lg-table-cell">
                            <?= $u['last_login_at'] !== null
                                ? e(date('d/m/Y H:i', strtotime((string) $u['last_login_at'])))
                                : '<span class="text-secondary">jamais</span>' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($users === []): ?>
                    <tr>
                        <td colspan="5" class="text-center text-secondary py-4">
                            <?= $hasFilter
                                ? 'Aucun compte ne correspond à ces critères.'
                                : 'Aucun compte du personnel.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-secondary">Page <?= (int) $page ?> sur <?= (int) $pages ?></span>
            <div class="btn-group btn-group-sm">
                <?php if ($page > 1): ?>
                    <a class="btn btn-outline-secondary"
                       href="<?= e(url('/utilisateurs?page=' . ($page - 1)
                            . '&q=' . urlencode($filters['q'])
                            . '&statut=' . urlencode($filters['status'])
                            . '&role=' . urlencode($filters['role'])
                            . ($showArchived ? '&archives=1' : ''))) ?>">Précédente</a>
                <?php endif; ?>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-outline-secondary"
                       href="<?= e(url('/utilisateurs?page=' . ($page + 1)
                            . '&q=' . urlencode($filters['q'])
                            . '&statut=' . urlencode($filters['status'])
                            . '&role=' . urlencode($filters['role'])
                            . ($showArchived ? '&archives=1' : ''))) ?>">Suivante</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<p class="small text-secondary mt-3 mb-0">
    Cette liste ne contient que les comptes du <strong>personnel</strong>.
    Les accès des <strong>parents et des élèves</strong> se créent et se
    réinitialisent depuis la fiche du tuteur ou de l'élève, et ne comptent
    pas dans la limite de l'abonnement.
</p>
