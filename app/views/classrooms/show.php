<?php
/**
 * Liste nominative d'une classe — imprimable.
 *
 * @var array $classroom
 * @var array $students
 */
declare(strict_types=1);

set_title($classroom['name']);

$girls = 0;
foreach ($students as $student) {
    if ($student['gender'] === 'F') {
        $girls++;
    }
}
?>

<div class="page-head">
    <div>
        <nav class="breadcrumb-mini">
            <a href="<?= e(url('/classes', ['annee' => (int) $classroom['academic_year_id']])) ?>">Classes</a><span>/</span>
        </nav>
        <h1 class="page-title"><?= e($classroom['name']) ?></h1>
        <p class="page-subtitle">
            <?= e($classroom['level_name']) ?>
            <?= $classroom['option_name'] !== null ? ' · ' . e($classroom['option_name']) : '' ?>
            · Année <?= e($classroom['year_code']) ?>
            <?= $classroom['room_name'] !== null ? ' · Salle ' . e($classroom['room_name']) : '' ?>
        </p>
    </div>

    <div class="text-end">
        <span class="text-muted small d-block">Effectif</span>
        <strong class="fs-5"><?= count($students) ?></strong>
        <span class="text-muted small">/ <?= (int) $classroom['capacity'] ?></span>
        <span class="d-block text-muted small"><?= $girls ?> F · <?= count($students) - $girls ?> G</span>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Liste nominative</h2>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-print>
            <i class="bi bi-printer"></i> Imprimer
        </button>
    </div>

    <?php if ($students === []): ?>
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">Aucun élève affecté à cette classe.</p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:50px">N°</th>
                        <th style="width:110px">Matricule</th>
                        <th>Nom complet</th>
                        <th style="width:60px" class="text-center">Sexe</th>
                        <th style="width:110px">Naissance</th>
                        <th style="width:110px" class="text-center">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $index => $student): ?>
                        <tr>
                            <td class="text-muted"><?= $index + 1 ?></td>
                            <td><code><?= e($student['matricule']) ?></code></td>
                            <td>
                                <a href="<?= e(url('/eleves/' . (int) $student['id'])) ?>">
                                    <?= e(full_name($student['last_name'], $student['post_name'], $student['first_name'])) ?>
                                </a>
                            </td>
                            <td class="text-center"><?= e($student['gender']) ?></td>
                            <td class="small text-muted"><?= e(format_date($student['birth_date'])) ?: '—' ?></td>
                            <td class="text-center">
                                <span class="badge text-bg-light"><?= e($student['enrollment_status']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= script_tag('', <<<'JS'
    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () { window.print(); });
    });
JS) ?>
