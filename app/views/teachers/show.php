<?php
/**
 * Fiche enseignant : identité, service de l'année, statut.
 *
 * @var array      $teacher
 * @var array|null $year
 * @var array      $years
 * @var array      $assignments
 * @var array      $mainClassrooms
 * @var float      $weeklyLoad
 * @var array      $statuses
 * @var array      $employmentTypes
 * @var array      $availableUsers
 */
declare(strict_types=1);

$fullName = full_name($teacher['last_name'], $teacher['post_name'], $teacher['first_name']);
set_title($fullName);

$badge = [
    'active'    => 'bg-success-subtle text-success-emphasis',
    'suspended' => 'bg-warning-subtle text-warning-emphasis',
    'left'      => 'bg-secondary-subtle text-secondary-emphasis',
];

// Regroupement par classe : l'enseignant lit son service classe par
// classe, pas branche par branche.
$byClassroom = [];

foreach ($assignments as $row) {
    $key = (int) $row['classroom_id'];

    if (!isset($byClassroom[$key])) {
        $byClassroom[$key] = [
            'code'     => $row['classroom_code'],
            'name'     => $row['classroom_name'],
            'level'    => $row['level_short'],
            'subjects' => [],
        ];
    }

    $byClassroom[$key]['subjects'][] = $row;
}
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($fullName) ?></h1>
        <p class="page-subtitle">
            <span class="badge <?= e($badge[$teacher['status']] ?? 'bg-secondary-subtle') ?>">
                <?= e($statuses[$teacher['status']] ?? $teacher['status']) ?>
            </span>
            <?php if ($teacher['matricule'] !== null): ?>
                · Matricule <strong><?= e($teacher['matricule']) ?></strong>
            <?php endif; ?>
            <?php if ($teacher['specialty'] !== null): ?>
                · <?= e($teacher['specialty']) ?>
            <?php endif; ?>
        </p>
    </div>

    <div class="d-flex gap-2 align-items-center">
        <?php if (count($years) > 1 && $year !== null): ?>
            <form method="get" action="<?= e(url('/enseignants/' . (int) $teacher['id'])) ?>">
                <select name="annee" class="form-select form-select-sm" data-auto-submit>
                    <?php foreach ($years as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === (int) $year['id'] ? 'selected' : '' ?>>
                            <?= e($option['code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-outline-secondary ms-1" data-auto-submit-fallback>OK</button>
            </form>
        <?php endif; ?>
        <a href="<?= e(url('/enseignants')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Liste
        </a>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="row g-3">
    <!-- ---------------------------------------------------------------
         Service de l'année
    ---------------------------------------------------------------- -->
    <div class="col-12 col-lg-7">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Service <?= $year !== null ? e($year['code']) : '' ?></strong>
                <span class="small text-secondary">
                    <?= count($assignments) ?> branche(s) ·
                    <?= e(number_format($weeklyLoad, 1, ',', ' ')) ?> h/semaine
                </span>
            </div>

            <?php if ($byClassroom === []): ?>
                <div class="card-body text-center py-4">
                    <p class="mb-1">Aucune branche confiée pour cette année.</p>
                    <p class="small text-secondary mb-0">
                        Les affectations se font depuis la répartition de chaque classe.
                    </p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($byClassroom as $classroomId => $group): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <a href="<?= e(url('/classes/' . (int) $classroomId . '/repartition')) ?>"
                                       class="fw-medium text-decoration-none">
                                        <?= e($group['name']) ?>
                                    </a>
                                    <span class="small text-secondary ms-1"><?= e($group['code']) ?></span>
                                </div>
                                <span class="badge bg-light text-secondary"><?= e($group['level']) ?></span>
                            </div>

                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($group['subjects'] as $subject): ?>
                                    <span class="badge bg-primary-subtle text-primary-emphasis fw-normal">
                                        <?= e($subject['subject_name']) ?>
                                        <span class="opacity-75">/ <?= (int) $subject['max_points'] ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($mainClassrooms !== []): ?>
            <div class="card mb-3">
                <div class="card-header"><strong>Titulaire de classe</strong></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($mainClassrooms as $classroom): ?>
                        <a href="<?= e(url('/classes/' . (int) $classroom['id'] . '/repartition')) ?>"
                           class="list-group-item list-group-item-action d-flex justify-content-between">
                            <span><?= e($classroom['name']) ?></span>
                            <span class="small text-secondary"><?= e($classroom['code']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ---------------------------------------------------------------
         Identité et administration
    ---------------------------------------------------------------- -->
    <div class="col-12 col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><strong>Coordonnées</strong></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-secondary fw-normal">Téléphone</dt>
                    <dd class="col-7">
                        <?php if ($teacher['phone'] !== null): ?>
                            <a href="tel:<?= e($teacher['phone']) ?>"><?= e($teacher['phone']) ?></a>
                        <?php else: ?>
                            <span class="text-secondary">—</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-5 text-secondary fw-normal">Email</dt>
                    <dd class="col-7"><?= $teacher['email'] !== null ? e($teacher['email']) : '<span class="text-secondary">—</span>' ?></dd>

                    <dt class="col-5 text-secondary fw-normal">Adresse</dt>
                    <dd class="col-7"><?= $teacher['address'] !== null ? e($teacher['address']) : '<span class="text-secondary">—</span>' ?></dd>

                    <dt class="col-5 text-secondary fw-normal">Diplôme</dt>
                    <dd class="col-7"><?= $teacher['qualification'] !== null ? e($teacher['qualification']) : '<span class="text-secondary">—</span>' ?></dd>

                    <dt class="col-5 text-secondary fw-normal">Engagement</dt>
                    <dd class="col-7"><?= e($employmentTypes[$teacher['employment_type']] ?? $teacher['employment_type']) ?></dd>

                    <dt class="col-5 text-secondary fw-normal">Entrée en fonction</dt>
                    <dd class="col-7"><?= $teacher['hire_date'] !== null ? e($teacher['hire_date']) : '<span class="text-secondary">—</span>' ?></dd>
                </dl>
            </div>
        </div>

        <?php if (can('teacher.manage')): ?>
            <div class="card mb-3">
                <div class="card-header"><strong>Compte de connexion</strong></div>
                <div class="card-body">
                    <form method="post" action="<?= e(url('/enseignants/' . (int) $teacher['id'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="last_name" value="<?= e($teacher['last_name']) ?>">
                        <input type="hidden" name="first_name" value="<?= e($teacher['first_name']) ?>">

                        <label for="user_id" class="form-label form-label-sm">Compte rattaché</label>
                        <select id="user_id" name="user_id" class="form-select form-select-sm mb-2">
                            <option value="">Aucun — pas d'accès à la plateforme</option>
                            <?php foreach ($availableUsers as $user): ?>
                                <option value="<?= (int) $user['id'] ?>"
                                    <?= (int) $teacher['user_id'] === (int) $user['id'] ? 'selected' : '' ?>>
                                    <?= e($user['username']) ?> —
                                    <?= e(full_name($user['last_name'], $user['post_name'], $user['first_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <p class="small text-secondary">
                            Le compte ouvre l'accès aux seuls élèves de ses classes.
                        </p>

                        <button type="submit" class="btn btn-sm btn-outline-primary">Enregistrer</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>Statut</strong></div>
                <div class="card-body">
                    <form method="post" action="<?= e(url('/enseignants/' . (int) $teacher['id'] . '/statut')) ?>"
                          data-confirm="Confirmez-vous le changement de statut de cet enseignant ?">
                        <?= csrf_field() ?>

                        <select name="status" class="form-select form-select-sm mb-2" aria-label="Nouveau statut">
                            <?php foreach ($statuses as $code => $label): ?>
                                <option value="<?= e($code) ?>" <?= $teacher['status'] === $code ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text" name="reason" class="form-control form-control-sm mb-2"
                               maxlength="255" placeholder="Motif (facultatif)">

                        <p class="small text-secondary">
                            Un départ ne supprime rien : les affectations passées restent,
                            pour que les bulletins déjà produits gardent le nom du professeur.
                            Elles cessent simplement de donner accès aux élèves.
                        </p>

                        <button type="submit" class="btn btn-sm btn-outline-secondary">Appliquer</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
