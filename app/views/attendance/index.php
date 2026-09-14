<?php
/**
 * Tableau du jour : quelles classes ont fait l'appel, et lesquelles non.
 *
 * C'est l'écran qui justifie la table des sessions. Sans elle, une classe
 * dont personne n'a fait l'appel serait indiscernable d'une classe sans
 * absent — et la direction n'aurait aucun moyen de le savoir.
 *
 * @var string     $date
 * @var string     $slot
 * @var array      $slots
 * @var array|null $year
 * @var array      $classrooms
 */
declare(strict_types=1);

set_title('Présences');

$missing = array_filter($classrooms, static fn (array $c): bool => $c['session_id'] === null);

// APPEL FAIT N'EST PAS APPEL COMPLET.
//
// Une session existe dès le premier élève enregistré. Un appel portant
// sur deux élèves sur quarante s'affichait « fait » : les trente-huit
// autres n'étaient ni présents ni absents, et personne ne pouvait le
// savoir.
$partial = array_filter(
    $classrooms,
    static fn (array $c): bool => $c['session_id'] !== null
        && (int) $c['recorded'] < (int) $c['student_count']
);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Présences</h1>
        <p class="page-subtitle">
            <?= e(date('d/m/Y', strtotime($date))) ?>
            · <?= e($slots[$slot]) ?>
            <?php if ($year !== null): ?> · année <?= e($year['code']) ?><?php endif; ?>
        </p>
    </div>

    <form method="get" action="<?= e(url('/presences')) ?>" class="d-flex gap-2">
        <input type="date" name="date" value="<?= e($date) ?>" class="form-control form-control-sm">
        <select name="moment" class="form-select form-select-sm">
            <?php foreach ($slots as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $key === $slot ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-outline-secondary">Voir</button>
    </form>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($classrooms === []): ?>
    <div class="alert alert-info">Aucune classe dans votre périmètre pour cette année.</div>
<?php else: ?>
    <?php if ($missing !== []): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle"></i>
            <div>
                <strong><?= count($missing) ?></strong> classe(s) n'ont pas encore fait l'appel.
                Une journée sans appel n'est pas une journée sans absent.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($partial !== []): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2">
            <i class="bi bi-person-dash"></i>
            <div>
                <strong><?= count($partial) ?></strong> classe(s) ont un appel <strong>incomplet</strong> :
                des élèves n'y sont ni présents, ni absents, ni en retard.
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Classe</th>
                        <th scope="col" class="text-center">Effectif</th>
                        <th scope="col" class="text-center">Appel</th>
                        <th scope="col" class="text-center">Absents</th>
                        <th scope="col" class="text-center">Retards</th>
                        <th scope="col" class="d-none d-lg-table-cell">Par</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classrooms as $row): ?>
                        <?php $done = $row['session_id'] !== null; ?>
                        <tr>
                            <td>
                                <span class="fw-medium"><?= e($row['name']) ?></span>
                                <span class="small text-secondary"><?= e((string) ($row['level_short'] ?? '')) ?></span>
                            </td>
                            <td class="text-center"><?= (int) $row['student_count'] ?></td>
                            <td class="text-center">
                                <?php if ($done): ?>
                                    <?php $complete = (int) $row['recorded'] >= (int) $row['student_count']; ?>
                                    <?php if ($complete): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis">fait</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger-emphasis"
                                              title="<?= (int) $row['recorded'] ?> élève(s) appelés sur <?= (int) $row['student_count'] ?>">
                                            incomplet <?= (int) $row['recorded'] ?>/<?= (int) $row['student_count'] ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ((int) $row['is_locked'] === 1): ?>
                                        <i class="bi bi-lock-fill text-secondary" title="Verrouillé"></i>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis">à faire</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?= $done ? (int) $row['absents'] : '<span class="text-secondary">—</span>' ?>
                            </td>
                            <td class="text-center">
                                <?= $done ? (int) $row['lates'] : '<span class="text-secondary">—</span>' ?>
                            </td>
                            <td class="d-none d-lg-table-cell small text-secondary">
                                <?= e((string) ($row['taken_by_name'] ?? '')) ?>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm <?= $done ? 'btn-outline-secondary' : 'btn-primary' ?>"
                                   href="<?= e(url('/presences/classe/' . (int) $row['classroom_id'],
                                        ['date' => $date, 'moment' => $slot])) ?>">
                                    <?= $done ? 'Voir' : 'Faire l\'appel' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
