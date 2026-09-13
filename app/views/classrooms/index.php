<?php
/**
 * Classes de l'établissement pour une année scolaire.
 *
 * @var array $year
 * @var array $years
 * @var array $classrooms
 * @var array $curriculums
 * @var array $rooms
 */
declare(strict_types=1);

set_title('Classes');

$totalStudents = 0;
foreach ($classrooms as $classroom) {
    $totalStudents += (int) $classroom['student_count'];
}
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Classes</h1>
        <p class="page-subtitle">
            Année <strong><?= e($year['code']) ?></strong> ·
            <?= count($classrooms) ?> classe(s) · <?= $totalStudents ?> élève(s) affecté(s)
        </p>
    </div>

    <?php if (count($years) > 1): ?>
        <form method="get" action="<?= e(url('/classes')) ?>">
            <select name="annee" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($years as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === (int) $year['id'] ? 'selected' : '' ?>>
                        <?= e($option['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if ($curriculums === [] && $classrooms === []): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong>Aucun programme actif pour cette année.</strong>
        Une classe est toujours rattachée à un programme : celui-ci porte le niveau,
        la section et l'option, ce qui évite toute incohérence.
        <a href="<?= e(url('/referentiel/programmes', ['annee' => (int) $year['id']])) ?>" class="alert-link">
            Créer et activer un programme
        </a>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Classes ouvertes</h2>
    </div>

    <?php if ($classrooms === []): ?>
        <div class="card-body text-center py-5">
            <div class="empty-icon"><i class="bi bi-door-open"></i></div>
            <p class="text-muted mt-3 mb-0">Aucune classe pour cette année scolaire.</p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:90px">Code</th>
                        <th>Classe</th>
                        <th style="width:160px">Niveau / Option</th>
                        <th style="width:130px">Salle</th>
                        <th style="width:170px">Effectif</th>
                        <th style="width:100px" class="text-center">F / G</th>
                        <th style="width:150px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classrooms as $classroom): ?>
                        <?php
                        $count    = (int) $classroom['student_count'];
                        $capacity = (int) $classroom['capacity'];
                        $ratio    = $capacity > 0 ? min(100, (int) round($count / $capacity * 100)) : 0;
                        $tone     = $ratio >= 100 ? 'bg-danger' : ($ratio >= 85 ? 'bg-warning' : 'bg-success');
                        ?>
                        <tr class="<?= (int) $classroom['is_active'] === 0 ? 'row-muted' : '' ?>">
                            <td><code><?= e($classroom['code']) ?></code></td>
                            <td>
                                <a href="<?= e(url('/classes/' . (int) $classroom['id'])) ?>" class="fw-semibold">
                                    <?= e($classroom['name']) ?>
                                </a>
                            </td>
                            <td class="small">
                                <?= e($classroom['level_short']) ?>
                                <?php if ($classroom['option_short'] !== null): ?>
                                    <span class="d-block text-muted"><?= e($classroom['option_short']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= e($classroom['room_name'] ?: '—') ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:6px">
                                        <div class="progress-bar <?= e($tone) ?>" style="width: <?= $ratio ?>%"></div>
                                    </div>
                                    <span class="small text-nowrap"><?= $count ?>/<?= $capacity ?></span>
                                </div>
                            </td>
                            <td class="text-center small">
                                <?= (int) $classroom['girls'] ?> / <?= (int) $classroom['boys'] ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= e(url('/classes/' . (int) $classroom['id'])) ?>"
                                   class="btn btn-sm btn-outline-secondary">Liste</a>
                                <?php if (can('classroom.manage')): ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#class-<?= (int) $classroom['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (can('classroom.manage')): ?>
                            <tr class="collapse" id="class-<?= (int) $classroom['id'] ?>">
                                <td colspan="7" class="bg-light">
                                    <form method="post" action="<?= e(url('/classes/' . (int) $classroom['id'])) ?>"
                                          class="row g-2 align-items-end">
                                        <?= csrf_field() ?>
                                        <div class="col-md-4">
                                            <label class="form-label">Nom</label>
                                            <input type="text" class="form-control form-control-sm" name="name"
                                                   value="<?= e($classroom['name']) ?>" required maxlength="120">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Capacité</label>
                                            <input type="number" class="form-control form-control-sm" name="capacity"
                                                   value="<?= (int) $classroom['capacity'] ?>" min="1" max="200" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Salle</label>
                                            <select class="form-select form-select-sm" name="room_id">
                                                <option value="">—</option>
                                                <?php foreach ($rooms as $room): ?>
                                                    <option value="<?= (int) $room['id'] ?>"
                                                        <?= (int) $room['id'] === (int) $classroom['room_id'] ? 'selected' : '' ?>>
                                                        <?= e($room['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                                       id="ca-<?= (int) $classroom['id'] ?>"
                                                       <?= (int) $classroom['is_active'] === 1 ? 'checked' : '' ?>>
                                                <label class="form-check-label small" for="ca-<?= (int) $classroom['id'] ?>">Active</label>
                                            </div>
                                        </div>
                                        <div class="col-md-1">
                                            <button type="submit" class="btn btn-sm btn-primary w-100">OK</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (can('classroom.manage') && $curriculums !== []): ?>
    <div class="row g-3 mt-1">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header"><h2 class="card-title">Nouvelle classe</h2></div>
                <div class="card-body">
                    <form method="post" action="<?= e(url('/classes')) ?>" class="row g-3 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">

                        <div class="col-md-5">
                            <label class="form-label" for="curriculum_id">Programme <span class="text-danger">*</span></label>
                            <select class="form-select" id="curriculum_id" name="curriculum_id" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ($curriculums as $curriculum): ?>
                                    <option value="<?= (int) $curriculum['id'] ?>"><?= e($curriculum['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="code">Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= has_error('code') ? 'is-invalid' : '' ?>"
                                   id="code" name="code" value="<?= old('code') ?>"
                                   placeholder="7A" required maxlength="30">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="name">Nom <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?= old('name') ?>" placeholder="7ème année A" required maxlength="120">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="capacity">Capacité</label>
                            <input type="number" class="form-control" id="capacity" name="capacity"
                                   value="<?= old('capacity', '45') ?>" min="1" max="200" required>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Créer la classe
                            </button>
                            <span class="form-text d-block mt-1">
                                Seuls les programmes <strong>activés</strong> sont proposés.
                            </span>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header"><h2 class="card-title">Salles</h2></div>
                <div class="card-body">
                    <?php if ($rooms !== []): ?>
                        <ul class="plain-list mb-3">
                            <?php foreach ($rooms as $room): ?>
                                <li>
                                    <span><?= e($room['name']) ?></span>
                                    <span class="plain-list-meta"><?= e($room['code']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('/classes/salles')) ?>" class="row g-2">
                        <?= csrf_field() ?>
                        <div class="col-4">
                            <input type="text" class="form-control form-control-sm" name="code"
                                   placeholder="Code" required maxlength="30">
                        </div>
                        <div class="col-5">
                            <input type="text" class="form-control form-control-sm" name="name"
                                   placeholder="Nom de la salle" required maxlength="100">
                        </div>
                        <div class="col-3">
                            <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
