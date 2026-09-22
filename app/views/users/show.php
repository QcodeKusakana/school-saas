<?php
/**
 * La fiche d'un compte du personnel.
 *
 * CHAQUE POUVOIR A SON FORMULAIRE. L'état civil, les rôles, l'état du
 * compte, le mot de passe et l'archivage ne se soumettent pas
 * ensemble : mélanger un champ « nom » et une case « rôle » dans un
 * même envoi ferait d'une correction d'orthographe une promotion.
 *
 * @var array   $user
 * @var array   $roles
 * @var array   $held
 * @var array   $statuses
 * @var ?string $refusal
 * @var bool    $isSelf
 * @var bool    $lastAdmin
 */
declare(strict_types=1);

$fullName = trim((string) $user['last_name'] . ' ' . (string) ($user['post_name'] ?? '')
    . ' ' . (string) $user['first_name']);

set_title($fullName);

$badge = [
    'active'    => 'bg-success-subtle text-success-emphasis',
    'pending'   => 'bg-info-subtle text-info-emphasis',
    'inactive'  => 'bg-secondary-subtle text-secondary-emphasis',
    'suspended' => 'bg-danger-subtle text-danger-emphasis',
];

$archived = $user['deleted_at'] !== null;
$locked   = $user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time();

/** Toute écriture est fermée si le compte est hors de portée. */
$readOnly = $refusal !== null;
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($fullName) ?></h1>
        <p class="page-subtitle">
            <code><?= e((string) $user['username']) ?></code>
            · <span class="badge <?= e($badge[$user['status']] ?? 'bg-secondary-subtle') ?>">
                <?= e($statuses[$user['status']] ?? (string) $user['status']) ?>
            </span>
            <?php if ($archived): ?>
                <span class="badge bg-secondary-subtle text-secondary-emphasis">archivé</span>
            <?php endif; ?>
            <?php if ($locked): ?>
                <span class="badge bg-warning-subtle text-warning-emphasis">verrouillé</span>
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/utilisateurs')) ?>">Retour à la liste</a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($readOnly): ?>
    <div class="alert alert-secondary">
        <strong>Ce compte est hors de votre portée.</strong>
        <div class="small mt-1"><?= e((string) $refusal) ?></div>
    </div>
<?php endif; ?>

<?php if ($isSelf): ?>
    <div class="alert alert-info small">
        <strong>C'est votre propre compte.</strong>
        Vous ne pouvez ni modifier vos rôles, ni vous désactiver : un autre
        administrateur doit le faire. Sans cette règle, une fausse manœuvre
        vous enfermerait dehors.
    </div>
<?php endif; ?>

<?php if ($lastAdmin && !$archived): ?>
    <div class="alert alert-warning small">
        <strong>Dernier compte capable de créer des utilisateurs.</strong>
        Tant qu'aucun autre n'existe, il ne peut être ni désactivé ni archivé —
        l'établissement n'aurait plus aucun moyen de rouvrir un accès.
    </div>
<?php endif; ?>

<div class="row g-3">
    <!-- ==================== IDENTITÉ ==================== -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header fw-medium">Identité et coordonnées</div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/utilisateurs/' . (int) $user['id'])) ?>"
                      class="row g-2">
                    <?= csrf_field() ?>

                    <div class="col-12 col-md-4">
                        <label class="form-label small" for="last_name">Nom</label>
                        <input type="text" class="form-control" id="last_name" name="last_name"
                               value="<?= e((string) $user['last_name']) ?>" required maxlength="80"
                               <?= $readOnly ? 'disabled' : '' ?>>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label small" for="post_name">Postnom</label>
                        <input type="text" class="form-control" id="post_name" name="post_name"
                               value="<?= e((string) ($user['post_name'] ?? '')) ?>" maxlength="80"
                               <?= $readOnly ? 'disabled' : '' ?>>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label small" for="first_name">Prénom</label>
                        <input type="text" class="form-control" id="first_name" name="first_name"
                               value="<?= e((string) $user['first_name']) ?>" required maxlength="80"
                               <?= $readOnly ? 'disabled' : '' ?>>
                    </div>

                    <div class="col-12 col-md-5">
                        <label class="form-label small" for="email">E-mail</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= e((string) ($user['email'] ?? '')) ?>" maxlength="190"
                               <?= $readOnly ? 'disabled' : '' ?>>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label small" for="phone">Téléphone</label>
                        <input type="text" class="form-control" id="phone" name="phone"
                               value="<?= e((string) ($user['phone'] ?? '')) ?>" maxlength="40"
                               <?= $readOnly ? 'disabled' : '' ?>>
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label small" for="gender">Sexe</label>
                        <select class="form-select" id="gender" name="gender" <?= $readOnly ? 'disabled' : '' ?>>
                            <option value="">—</option>
                            <option value="M" <?= $user['gender'] === 'M' ? 'selected' : '' ?>>Masculin</option>
                            <option value="F" <?= $user['gender'] === 'F' ? 'selected' : '' ?>>Féminin</option>
                        </select>
                    </div>

                    <?php if (can('user.edit') && !$readOnly): ?>
                        <div class="col-12 mt-3">
                            <button class="btn btn-primary btn-sm" type="submit">Enregistrer</button>
                        </div>
                    <?php endif; ?>
                </form>

                <hr>

                <div class="row small text-secondary">
                    <div class="col-6">
                        Créé le <?= e(date('d/m/Y', strtotime((string) $user['created_at']))) ?>
                    </div>
                    <div class="col-6 text-end">
                        <?= $user['last_login_at'] !== null
                            ? 'Dernière connexion le '
                              . e(date('d/m/Y H:i', strtotime((string) $user['last_login_at'])))
                            : 'Ne s\'est jamais connecté' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== RÔLES ==================== -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-medium">Rôles</div>
            <div class="card-body">
                <?php if ($user['roles'] === []): ?>
                    <p class="text-danger-emphasis small">
                        Ce compte ne porte aucun rôle : il se connecte et n'ouvre rien.
                    </p>
                <?php endif; ?>

                <?php if (can('user.edit') && !$readOnly && !$isSelf && $roles !== []): ?>
                    <form method="post"
                          action="<?= e(url('/utilisateurs/' . (int) $user['id'] . '/roles')) ?>">
                        <?= csrf_field() ?>

                        <?php foreach ($roles as $r): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" value="<?= (int) $r['id'] ?>"
                                       id="role<?= (int) $r['id'] ?>" name="roles[]"
                                       <?= in_array((int) $r['id'], $held, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="role<?= (int) $r['id'] ?>">
                                    <?= e((string) $r['name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>

                        <button class="btn btn-outline-primary btn-sm mt-2" type="submit">
                            Appliquer les rôles
                        </button>
                    </form>
                <?php else: ?>
                    <?php foreach ($user['roles'] as $r): ?>
                        <span class="badge bg-light text-dark border me-1"><?= e((string) $r['name']) ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php
                /* Un rôle porté MAIS non attribuable doit rester visible :
                   sinon la case décochée d'un rôle invisible l'effacerait
                   silencieusement à la première soumission. */
                $assignable = array_map(static fn (array $r): int => (int) $r['id'], $roles);
                $beyond     = array_values(array_filter(
                    $user['roles'],
                    static fn (array $r): bool => !in_array((int) $r['id'], $assignable, true)
                ));
                ?>

                <?php if ($beyond !== [] && can('user.edit') && !$readOnly && !$isSelf): ?>
                    <div class="alert alert-warning small mt-3 mb-0">
                        Ce compte porte aussi
                        <?php foreach ($beyond as $r): ?>
                            <strong><?= e((string) $r['name']) ?></strong>
                        <?php endforeach; ?>
                        — un rôle que vous ne pouvez pas attribuer, donc pas retirer non plus.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ==================== ACCÈS ==================== -->
<div class="card mt-3">
    <div class="card-header fw-medium">Accès au compte</div>
    <div class="card-body row g-3">

        <?php if (can('user.reset_password') && !$readOnly): ?>
            <div class="col-12 col-md-4">
                <form method="post"
                      action="<?= e(url('/utilisateurs/' . (int) $user['id'] . '/mot-de-passe')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-warning btn-sm w-100" type="submit">
                        Régénérer le mot de passe
                    </button>
                </form>
                <p class="small text-secondary mt-2 mb-0">
                    Affiché <strong>une seule fois</strong>. Le compte devra le changer
                    à sa prochaine connexion.
                </p>
            </div>
        <?php endif; ?>

        <?php if ($locked && can('user.edit') && !$readOnly): ?>
            <div class="col-12 col-md-4">
                <form method="post"
                      action="<?= e(url('/utilisateurs/' . (int) $user['id'] . '/deverrouiller')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-secondary btn-sm w-100" type="submit">
                        Déverrouiller
                    </button>
                </form>
                <p class="small text-secondary mt-2 mb-0">
                    Verrouillé jusqu'au
                    <?= e(date('d/m/Y H:i', strtotime((string) $user['locked_until']))) ?>
                    après des échecs de connexion.
                </p>
            </div>
        <?php endif; ?>

        <?php if (can('user.delete') && !$readOnly && !$isSelf): ?>
            <div class="col-12 col-md-4">
                <form method="post"
                      action="<?= e(url('/utilisateurs/' . (int) $user['id'] . '/etat')) ?>">
                    <?= csrf_field() ?>
                    <label class="form-label small" for="status">État du compte</label>
                    <select class="form-select form-select-sm mb-2" id="status" name="status">
                        <?php foreach (['active', 'suspended', 'inactive'] as $code): ?>
                            <option value="<?= e($code) ?>" <?= $user['status'] === $code ? 'selected' : '' ?>>
                                <?= e($statuses[$code]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm mb-2" name="reason"
                           placeholder="Motif (obligatoire pour une suspension)">
                    <button class="btn btn-outline-secondary btn-sm w-100" type="submit">Appliquer</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if (can('user.delete') && !$readOnly && !$isSelf): ?>
        <div class="card-footer">
            <form method="post"
                  action="<?= e(url('/utilisateurs/' . (int) $user['id'] . '/archiver')) ?>"
                  class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="archive" value="<?= $archived ? '0' : '1' ?>">

                <?php if (!$archived): ?>
                    <div class="col-12 col-md-8">
                        <label class="form-label small" for="reason_archive">
                            Motif de l'archivage
                        </label>
                        <input type="text" class="form-control form-control-sm" id="reason_archive"
                               name="reason" placeholder="Ex. : a quitté l'établissement en juin 2026">
                    </div>
                    <div class="col-12 col-md-4">
                        <button class="btn btn-outline-danger btn-sm w-100" type="submit">
                            Archiver ce compte
                        </button>
                    </div>
                <?php else: ?>
                    <div class="col-12 col-md-4">
                        <button class="btn btn-outline-success btn-sm w-100" type="submit">
                            Rappeler ce compte
                        </button>
                    </div>
                <?php endif; ?>
            </form>

            <p class="small text-secondary mt-2 mb-0">
                Un compte <strong>ne se supprime jamais</strong> : le journal des
                opérations désigne son auteur, et un journal sans auteur ne prouve
                rien. L'archivage le sort des listes et <strong>libère sa place</strong>
                dans l'abonnement.
            </p>
        </div>
    <?php endif; ?>
</div>
