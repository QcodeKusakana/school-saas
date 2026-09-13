<?php
/**
 * Fiche élève — dossier complet et parcours scolaire.
 *
 * La frise combine deux sources : les inscriptions (enrollments), qui
 * portent l'année et la classe, et les événements (student_history),
 * qui portent orientations, transferts et décisions. Les deux sont
 * fusionnées puis triées par date.
 *
 * @var array $student
 * @var array $guardians
 * @var array $enrollments
 * @var array $history
 * @var array $orientations
 * @var array|null $year
 * @var array $years
 * @var array $classrooms
 * @var array $sections
 * @var array $options
 */
declare(strict_types=1);

$fullName = full_name($student['last_name'], $student['post_name'], $student['first_name']);
set_title($fullName);

// Inscription de l'année de travail, si elle existe.
$currentEnrollment = null;

if ($year !== null) {
    foreach ($enrollments as $enrollment) {
        if ((int) $enrollment['academic_year_id'] === (int) $year['id']) {
            $currentEnrollment = $enrollment;
        }
    }
}

// Années où l'élève n'est pas encore inscrit — cibles de réinscription.
$enrolledYearIds = array_map(static fn (array $e): int => (int) $e['academic_year_id'], $enrollments);
$availableYears  = array_filter(
    $years,
    static fn (array $y): bool => !in_array((int) $y['id'], $enrolledYearIds, true)
        && !in_array($y['status'], ['closed', 'archived'], true)
);

// Âge calculé à la date du jour.
$age = null;

if (!empty($student['birth_date'])) {
    $birth = date_create((string) $student['birth_date']);
    $age   = $birth !== false ? (int) $birth->diff(date_create())->y : null;
}

// Fusion des deux sources pour la frise.
$timeline = [];

foreach ($enrollments as $enrollment) {
    $timeline[] = [
        'date'  => $enrollment['enrolled_on'] ?? $enrollment['admitted_on'] ?? $enrollment['starts_on'],
        'type'  => $enrollment['enrollment_type'] === 're_enrollment' ? 'promotion' : 'enrollment',
        'title' => $enrollment['year_code'] . ' — ' . ($enrollment['level_short'] ?? 'Classe non affectée'),
        'text'  => trim(($enrollment['classroom_name'] ?? '') . ' ' . ($enrollment['option_short'] ?? '')),
        'badge' => $enrollment['decision'] !== 'pending' ? $enrollment['decision'] : null,
        'repeated' => (int) $enrollment['repeated_year'] === 1,
    ];
}

foreach ($history as $event) {
    // Les inscriptions figurent déjà via enrollments : on ne les répète pas.
    if (in_array($event['event_type'], ['enrollment', 'promotion', 'repeat'], true)) {
        continue;
    }

    $timeline[] = [
        'date'  => $event['event_date'],
        'type'  => $event['event_type'],
        'title' => $event['title'],
        'text'  => $event['description'],
        'badge' => null,
        'repeated' => false,
    ];
}

usort($timeline, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

$decisionLabels = [
    'passed'      => ['text-bg-success', 'Admis'],
    'failed'      => ['text-bg-danger', 'Échec'],
    'conditional' => ['text-bg-warning', 'Admis sous condition'],
    'excluded'    => ['text-bg-dark', 'Exclu'],
];

$relationshipLabels = [
    'pere' => 'Père', 'mere' => 'Mère', 'tuteur' => 'Tuteur', 'oncle' => 'Oncle',
    'tante' => 'Tante', 'frere' => 'Frère', 'soeur' => 'Sœur',
    'grand_parent' => 'Grand-parent', 'autre' => 'Autre',
];
?>

<div class="page-head">
    <div>
        <nav class="breadcrumb-mini">
            <a href="<?= e(url('/eleves')) ?>">Élèves</a><span>/</span>
        </nav>
        <h1 class="page-title"><?= e($fullName) ?></h1>
        <p class="page-subtitle">
            Matricule <code><?= e($student['matricule']) ?></code>
            · <?= $student['gender'] === 'F' ? 'Fille' : 'Garçon' ?>
            <?= $age !== null ? ' · ' . $age . ' ans' : '' ?>
            <?php if ($student['status'] !== 'active'): ?>
                <span class="badge text-bg-secondary ms-1"><?= e($student['status']) ?></span>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($currentEnrollment !== null && $currentEnrollment['classroom_name'] !== null): ?>
        <div class="text-end">
            <span class="text-muted small d-block">Classe <?= e($year['code']) ?></span>
            <strong class="fs-5"><?= e($currentEnrollment['classroom_name']) ?></strong>
        </div>
    <?php endif; ?>
</div>

<div class="row g-4">

    <!-- ==============================================================
         Colonne principale
    =============================================================== -->
    <div class="col-lg-8">

        <!-- Parcours scolaire -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Parcours scolaire</h2>
                <span class="text-muted small"><?= count($enrollments) ?> année(s)</span>
            </div>
            <div class="card-body">
                <?php if ($timeline === []): ?>
                    <p class="text-muted mb-0">Aucun événement enregistré.</p>
                <?php else: ?>
                    <ol class="timeline">
                        <?php foreach ($timeline as $item): ?>
                            <li class="timeline-item timeline-<?= e($item['type']) ?>">
                                <div class="timeline-marker"></div>
                                <div class="timeline-content">
                                    <div class="timeline-head">
                                        <strong><?= e($item['title']) ?></strong>
                                        <?php if ($item['repeated']): ?>
                                            <span class="badge text-bg-warning">redoublement</span>
                                        <?php endif; ?>
                                        <?php if ($item['badge'] !== null): ?>
                                            <?php [$class, $label] = $decisionLabels[$item['badge']] ?? ['text-bg-light', $item['badge']]; ?>
                                            <span class="badge <?= e($class) ?>"><?= e($label) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($item['text'])): ?>
                                        <span class="timeline-text"><?= e($item['text']) ?></span>
                                    <?php endif; ?>
                                    <span class="timeline-date"><?= e(format_date($item['date'])) ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tuteurs -->
        <div class="card mt-3">
            <div class="card-header">
                <h2 class="card-title">Parents et tuteurs</h2>
                <?php if (can('student.edit')): ?>
                    <button class="btn btn-sm btn-outline-primary" type="button"
                            data-bs-toggle="collapse" data-bs-target="#guardian-form">
                        <i class="bi bi-person-plus"></i> Ajouter
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($guardians === []): ?>
                <div class="card-body">
                    <p class="text-muted mb-0">
                        Aucun tuteur rattaché. Le contact principal reçoit les bulletins et les relances de paiement.
                    </p>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th style="width:110px">Lien</th>
                                <th style="width:140px">Téléphone</th>
                                <th style="width:140px">Rôles</th>
                                <?php if (can('student.edit')): ?><th style="width:50px"></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($guardians as $guardian): ?>
                                <tr>
                                    <td>
                                        <strong><?= e(full_name($guardian['last_name'], $guardian['post_name'], $guardian['first_name'])) ?></strong>
                                        <?php if (!empty($guardian['profession'])): ?>
                                            <span class="d-block text-muted small"><?= e($guardian['profession']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($relationshipLabels[$guardian['relationship']] ?? $guardian['relationship']) ?></td>
                                    <td>
                                        <a href="tel:<?= e($guardian['phone']) ?>"><?= e($guardian['phone']) ?></a>
                                    </td>
                                    <td>
                                        <?php if ((int) $guardian['is_primary'] === 1): ?>
                                            <span class="badge text-bg-success" title="Contact principal">principal</span>
                                        <?php endif; ?>
                                        <?php if ((int) $guardian['is_payer'] === 1): ?>
                                            <span class="badge text-bg-info" title="Responsable des frais">payeur</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (can('student.edit')): ?>
                                        <td class="text-end">
                                            <form method="post" class="d-inline"
                                                  data-confirm="Détacher ce tuteur de l'élève ? Sa fiche sera conservée."
                                                  action="<?= e(url('/eleves/' . (int) $student['id'] . '/tuteurs/' . (int) $guardian['link_id'] . '/retirer')) ?>">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (can('student.edit')): ?>
                <div class="collapse" id="guardian-form">
                    <div class="card-body border-top bg-light">
                        <form method="post" action="<?= e(url('/eleves/' . (int) $student['id'] . '/tuteurs')) ?>"
                              class="row g-2">
                            <?= csrf_field() ?>

                            <div class="col-md-3">
                                <label class="form-label">Nom <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="last_name" required maxlength="80">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Postnom</label>
                                <input type="text" class="form-control form-control-sm" name="post_name" maxlength="80">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Prénom <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="first_name" required maxlength="80">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Lien <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" name="relationship" required>
                                    <?php foreach ($relationshipLabels as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= $value === 'pere' ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Téléphone <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control form-control-sm" name="phone"
                                       required placeholder="0810000000">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Téléphone secondaire</label>
                                <input type="tel" class="form-control form-control-sm" name="phone_alt">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Profession</label>
                                <input type="text" class="form-control form-control-sm" name="profession" maxlength="120">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control form-control-sm" name="email" maxlength="190">
                            </div>

                            <div class="col-12">
                                <div class="d-flex flex-wrap gap-3 mt-1">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="g_primary">
                                        <label class="form-check-label small" for="g_primary">Contact principal</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_payer" value="1" id="g_payer">
                                        <label class="form-check-label small" for="g_payer">Responsable des frais</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_emergency" value="1" id="g_emergency" checked>
                                        <label class="form-check-label small" for="g_emergency">Contact d'urgence</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="can_pickup" value="1" id="g_pickup" checked>
                                        <label class="form-check-label small" for="g_pickup">Autorisé à venir le chercher</label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-sm btn-primary">Rattacher le tuteur</button>
                                <span class="form-text ms-2">
                                    Si le numéro est déjà connu, la fiche existante est réutilisée — pas de doublon dans une fratrie.
                                </span>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==============================================================
         Colonne latérale
    =============================================================== -->
    <div class="col-lg-4">

        <!-- État civil -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">État civil</h2>
                <?php if (can('student.edit')): ?>
                    <button class="btn btn-sm btn-outline-secondary" type="button"
                            data-bs-toggle="collapse" data-bs-target="#edit-form">Modifier</button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <dl class="info-list">
                    <dt>Nom</dt><dd><?= e($student['last_name']) ?></dd>
                    <dt>Postnom</dt><dd><?= e($student['post_name'] ?: '—') ?></dd>
                    <dt>Prénom</dt><dd><?= e($student['first_name']) ?></dd>
                    <dt>Naissance</dt>
                    <dd>
                        <?= e(format_date($student['birth_date'])) ?: '—' ?>
                        <?= !empty($student['birth_place']) ? '<br><span class="text-muted small">' . e($student['birth_place']) . '</span>' : '' ?>
                    </dd>
                    <dt>Nationalité</dt><dd><?= e($student['nationality'] ?: '—') ?></dd>
                    <dt>Adresse</dt><dd><?= e($student['address'] ?: '—') ?></dd>
                    <dt>Téléphone</dt><dd><?= e($student['phone'] ?: '—') ?></dd>
                    <dt>Entrée</dt><dd><?= e(format_date($student['entry_date'])) ?: '—' ?></dd>
                </dl>
            </div>

            <?php if (can('student.edit')): ?>
                <div class="collapse" id="edit-form">
                    <div class="card-body border-top bg-light">
                        <form method="post" action="<?= e(url('/eleves/' . (int) $student['id'])) ?>" class="row g-2">
                            <?= csrf_field() ?>
                            <div class="col-12">
                                <label class="form-label">Nom</label>
                                <input type="text" class="form-control form-control-sm" name="last_name"
                                       value="<?= e($student['last_name']) ?>" required maxlength="80">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Postnom</label>
                                <input type="text" class="form-control form-control-sm" name="post_name"
                                       value="<?= e($student['post_name']) ?>" maxlength="80">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Prénom</label>
                                <input type="text" class="form-control form-control-sm" name="first_name"
                                       value="<?= e($student['first_name']) ?>" required maxlength="80">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Sexe</label>
                                <select class="form-select form-select-sm" name="gender" required>
                                    <option value="M" <?= $student['gender'] === 'M' ? 'selected' : '' ?>>M</option>
                                    <option value="F" <?= $student['gender'] === 'F' ? 'selected' : '' ?>>F</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Naissance</label>
                                <input type="date" class="form-control form-control-sm" name="birth_date"
                                       value="<?= e($student['birth_date']) ?>" max="<?= e(date('Y-m-d')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Lieu de naissance</label>
                                <input type="text" class="form-control form-control-sm" name="birth_place"
                                       value="<?= e($student['birth_place']) ?>" maxlength="120">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Nationalité</label>
                                <input type="text" class="form-control form-control-sm" name="nationality"
                                       value="<?= e($student['nationality']) ?>" maxlength="60">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Adresse</label>
                                <input type="text" class="form-control form-control-sm" name="address"
                                       value="<?= e($student['address']) ?>" maxlength="255">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Téléphone</label>
                                <input type="tel" class="form-control form-control-sm" name="phone"
                                       value="<?= e($student['phone']) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control form-control-sm" name="email"
                                       value="<?= e($student['email']) ?>" maxlength="190">
                            </div>
                            <div class="col-12 mt-2">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Enregistrer</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Inscription de l'année -->
        <?php if ($year !== null): ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h2 class="card-title">Année <?= e($year['code']) ?></h2>
                </div>
                <div class="card-body">
                    <?php if ($currentEnrollment === null): ?>
                        <p class="text-muted small">Aucune inscription pour cette année.</p>

                        <?php if (can('enrollment.manage') && $availableYears !== []): ?>
                            <form method="post" action="<?= e(url('/eleves/' . (int) $student['id'] . '/reinscription')) ?>">
                                <?= csrf_field() ?>
                                <label class="form-label">Réinscrire pour</label>
                                <select class="form-select form-select-sm mb-2" name="academic_year_id" required>
                                    <?php foreach ($availableYears as $target): ?>
                                        <option value="<?= (int) $target['id'] ?>"><?= e($target['code']) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label class="form-label">Classe</label>
                                <select class="form-select form-select-sm mb-2" name="classroom_id">
                                    <option value="">Affecter plus tard</option>
                                    <?php foreach ($classrooms as $classroom): ?>
                                        <option value="<?= (int) $classroom['id'] ?>">
                                            <?= e($classroom['name']) ?>
                                            (<?= (int) $classroom['student_count'] ?>/<?= (int) $classroom['capacity'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="repeated" value="1" id="repeated">
                                    <label class="form-check-label small" for="repeated">Redoublement</label>
                                </div>

                                <button type="submit" class="btn btn-sm btn-primary w-100">Réinscrire</button>
                                <p class="form-text mb-0 mt-1">
                                    L'état civil n'est pas ressaisi : le même dossier est réutilisé.
                                </p>
                            </form>
                        <?php endif; ?>

                    <?php else: ?>
                        <dl class="info-list">
                            <dt>Statut</dt>
                            <dd><?= e($currentEnrollment['status']) ?></dd>
                            <dt>Type</dt>
                            <dd><?= e($currentEnrollment['enrollment_type']) ?></dd>
                            <dt>Classe</dt>
                            <dd><?= e($currentEnrollment['classroom_name'] ?: 'Non affectée') ?></dd>
                            <dt>Décision</dt>
                            <dd><?= e($currentEnrollment['decision']) ?></dd>
                        </dl>

                        <?php if (can('classroom.assign') && $classrooms !== []): ?>
                            <form method="post" action="<?= e(url('/eleves/' . (int) $student['id'] . '/affectation')) ?>"
                                  class="mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="enrollment_id" value="<?= (int) $currentEnrollment['id'] ?>">
                                <label class="form-label">
                                    <?= $currentEnrollment['classroom_id'] === null ? 'Affecter à une classe' : 'Changer de classe' ?>
                                </label>
                                <div class="input-group input-group-sm">
                                    <select class="form-select" name="classroom_id" required>
                                        <option value="">— Choisir —</option>
                                        <?php foreach ($classrooms as $classroom): ?>
                                            <option value="<?= (int) $classroom['id'] ?>"
                                                <?= (int) $classroom['id'] === (int) $currentEnrollment['classroom_id'] ? 'selected' : '' ?>>
                                                <?= e($classroom['name']) ?>
                                                (<?= (int) $classroom['student_count'] ?>/<?= (int) $classroom['capacity'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary">OK</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Orientation -->
        <?php if (can('orientation.manage') && $currentEnrollment !== null): ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h2 class="card-title">Orientation</h2>
                </div>
                <div class="card-body">
                    <?php if ($orientations !== []): ?>
                        <?php foreach ($orientations as $orientation): ?>
                            <div class="mb-2 pb-2 border-bottom">
                                <strong><?= e($orientation['option_name']) ?></strong>
                                <span class="d-block text-muted small">
                                    <?= e($orientation['section_name']) ?> ·
                                    décidée le <?= e(format_date($orientation['decided_on'])) ?>
                                    <?= $orientation['final_percentage'] !== null
                                        ? ' · ' . number_format((float) $orientation['final_percentage'], 2, ',', ' ') . ' %'
                                        : '' ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted small">
                            À enregistrer à l'issue de la 8ème année, au passage vers les humanités.
                        </p>

                        <form method="post" action="<?= e(url('/eleves/' . (int) $student['id'] . '/orientation')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">

                            <label class="form-label">Section</label>
                            <select class="form-select form-select-sm mb-2" name="section_id" required data-orient-section>
                                <option value="">— Choisir —</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?= (int) $section['id'] ?>"><?= e($section['name']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <label class="form-label">Option</label>
                            <select class="form-select form-select-sm mb-2" name="option_id" required data-orient-option>
                                <option value="">— Choisir une section —</option>
                            </select>

                            <label class="form-label">Pourcentage obtenu</label>
                            <input type="text" class="form-control form-control-sm mb-2" name="final_percentage"
                                   inputmode="decimal" placeholder="72.50">

                            <button type="submit" class="btn btn-sm btn-primary w-100">Enregistrer l'orientation</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statut du dossier -->
        <?php if (can('student.edit')): ?>
            <div class="card mt-3">
                <div class="card-header"><h2 class="card-title">Statut du dossier</h2></div>
                <div class="card-body">
                    <form method="post" action="<?= e(url('/eleves/' . (int) $student['id'] . '/statut')) ?>"
                          data-confirm="Confirmer le changement de statut du dossier ?">
                        <?= csrf_field() ?>
                        <select class="form-select form-select-sm mb-2" name="status" required>
                            <?php foreach ([
                                'active'      => 'Actif',
                                'graduated'   => 'Fin des études',
                                'transferred' => 'Transféré',
                                'dropped'     => 'Abandon',
                                'archived'    => 'Archivé',
                            ] as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $student['status'] === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="form-control form-control-sm mb-2" name="reason"
                               placeholder="Motif (facultatif)" maxlength="255">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Appliquer</button>
                        <p class="form-text mb-0 mt-1">
                            Le dossier n'est jamais supprimé : il passe en archive et reste consultable.
                        </p>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Options groupées par section, pour la liste dépendante de l'orientation.
$optionsBySection = [];
foreach ($options as $option) {
    $optionsBySection[(int) $option['section_id']][] = [
        'id'   => (int) $option['id'],
        'name' => $option['name'],
    ];
}
?>
<?= script_tag('', 'window.__orientOptions = ' . e_js($optionsBySection) . ';' . <<<'JS'

    (function () {
        var sectionSelect = document.querySelector('[data-orient-section]');
        var optionSelect  = document.querySelector('[data-orient-option]');

        if (!sectionSelect || !optionSelect) {
            return;
        }

        sectionSelect.addEventListener('change', function () {
            var list = window.__orientOptions[sectionSelect.value] || [];
            optionSelect.innerHTML = '<option value="">— Choisir —</option>';

            list.forEach(function (option) {
                var element = document.createElement('option');
                element.value = option.id;
                element.textContent = option.name;
                optionSelect.appendChild(element);
            });
        });
    })();
JS) ?>
