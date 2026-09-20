<?php
/**
 * État des impayés.
 *
 * DEUX PRÉCAUTIONS QUI TIENNENT TOUT L'ÉCRAN
 * ------------------------------------------
 * 1. Les devises ne sont jamais additionnées : un élève apparaît une
 *    fois par monnaie due.
 * 2. La colonne AVANCE existe pour qu'on ne relance jamais une famille
 *    dont l'école détient déjà l'argent. C'est le reproche le plus
 *    grave qu'on puisse faire à un état de recouvrement.
 *
 * @var array  $year
 * @var array  $years
 * @var array  $rows
 * @var array  $filters
 * @var array  $currencies
 * @var array  $classrooms
 * @var array  $totals
 * @var array  $byClass
 */
declare(strict_types=1);

set_title('Impayés');

$advanceTotal = [];

foreach ($rows as $row) {
    if ((float) ($row['advance'] ?? 0) > 0.005) {
        $currency = (string) $row['currency'];
        $advanceTotal[$currency] = ($advanceTotal[$currency] ?? 0.0) + (float) $row['advance'];
    }
}

$query = [
    'annee'  => (int) $year['id'],
    'classe' => $filters['classroom_id'],
    'devise' => $filters['currency'],
    'retard' => $filters['only_overdue'] ? '1' : null,
    'partis' => $filters['include_cancelled'] ? '1' : null,
];
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Impayés</h1>
        <p class="page-subtitle">
            Année <?= e($year['code']) ?> · <?= count($rows) ?> ligne(s)
            <?= $filters['only_overdue'] ? '· échéances dépassées uniquement' : '' ?>
        </p>
    </div>

    <div class="d-flex gap-2 d-print-none">
        <a href="<?= e(url('/finances/impayes', $query + ['export' => 'csv'])) ?>"
           class="btn btn-outline-secondary">
            <i class="bi bi-download me-1"></i> CSV
        </a>
        <button type="button" class="btn btn-outline-secondary" data-print>
            <i class="bi bi-printer me-1"></i> Imprimer
        </button>
    </div>
</div>

<div class="d-print-none"><?php require APP_PATH . '/views/partials/flash.php'; ?></div>

<!-- ============================== LES TOTAUX ============================== -->
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-4">
        <?php if ($totals === []): ?>
            <span class="text-secondary">Aucune dette enregistrée pour cette année.</span>
        <?php else: ?>
            <?php foreach ($totals as $currency => $t): ?>
                <div>
                    <div class="small text-secondary">Reste à recouvrer</div>
                    <div class="fs-4 fw-semibold"><?= e(finance_amount((float) $t['balance'], (string) $currency)) ?></div>
                    <div class="small">
                        <span class="text-danger-emphasis">
                            dont <?= e(finance_amount((float) $t['overdue'], (string) $currency)) ?> échu
                        </span>
                    </div>
                    <div class="small text-secondary">
                        réclamé <?= e(finance_amount((float) $t['due'], (string) $currency)) ?>
                        · encaissé <?= e(finance_amount((float) $t['paid'], (string) $currency)) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ============================== LES FILTRES ============================= -->
<form method="get" action="<?= e(url('/finances/impayes')) ?>"
      class="card card-body mb-3 d-print-none">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="f-annee">Année</label>
            <select name="annee" id="f-annee" class="form-select form-select-sm">
                <?php foreach ($years as $y): ?>
                    <option value="<?= (int) $y['id'] ?>"
                        <?= (int) $y['id'] === (int) $year['id'] ? 'selected' : '' ?>>
                        <?= e($y['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="f-classe">Classe</label>
            <select name="classe" id="f-classe" class="form-select form-select-sm">
                <option value="">Toutes</option>
                <?php foreach ($classrooms as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"
                        <?= (int) ($filters['classroom_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="f-devise">Devise</label>
            <select name="devise" id="f-devise" class="form-select form-select-sm">
                <option value="">Toutes</option>
                <?php foreach ($currencies as $code => $label): ?>
                    <option value="<?= e($code) ?>"
                        <?= ($filters['currency'] ?? '') === $code ? 'selected' : '' ?>>
                        <?= e($code) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="f-retard" name="retard" value="1"
                    <?= $filters['only_overdue'] ? 'checked' : '' ?>>
                <label class="form-check-label small" for="f-retard">Échéance dépassée</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="f-partis" name="partis" value="1"
                    <?= $filters['include_cancelled'] ? 'checked' : '' ?>>
                <label class="form-check-label small" for="f-partis">Élèves partis</label>
            </div>
        </div>

        <div class="col-12 col-md-2">
            <button type="submit" class="btn btn-sm btn-primary w-100">Filtrer</button>
        </div>
    </div>

    <?php if (!$filters['include_cancelled']): ?>
        <p class="small text-secondary mb-0 mt-2">
            « Élèves partis » ajoute les inscriptions annulées : leur dette reste
            une créance réelle — un élève parti en janvier doit toujours le premier
            trimestre.
        </p>
    <?php endif; ?>
</form>

<!-- ====================== LES AVANCES NON IMPUTÉES ======================= -->
<?php if ($advanceTotal !== [] && can('payment.record')): ?>
    <div class="alert alert-info d-print-none d-flex align-items-start gap-2">
        <i class="bi bi-piggy-bank"></i>
        <div class="flex-grow-1">
            <strong>
                <?php $parts = [];
                foreach ($advanceTotal as $currency => $total) {
                    $parts[] = finance_amount((float) $total, (string) $currency);
                } ?>
                <?= e(implode(' et ', $parts)) ?>
            </strong>
            sont déjà encaissés sans être imputés, chez des familles qui figurent
            dans cette liste. Tant qu'ils n'y sont pas rattachés, ces familles
            apparaissent comme débitrices alors que l'école détient leur argent.

            <form method="post" action="<?= e(url('/finances/avances')) ?>" class="mt-2">
                <?= csrf_field() ?>
                <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">
                <?php if ($filters['classroom_id'] !== null): ?>
                    <input type="hidden" name="classroom_id" value="<?= (int) $filters['classroom_id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="retour"
                       value="<?= e(url('/finances/impayes', $query)) ?>">
                <button type="submit" class="btn btn-sm btn-info">
                    Imputer ces avances sur les dettes ouvertes
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- =============================== LA LISTE ============================== -->
<?php if ($rows === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-check2-circle display-6 text-success d-block mb-2"></i>
            <p class="mb-0 fw-medium">Aucun impayé pour ce filtre.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Élève</th>
                        <th scope="col" class="d-none d-md-table-cell">Classe</th>
                        <th scope="col" class="text-end">Dû</th>
                        <th scope="col" class="text-end">Encaissé</th>
                        <th scope="col" class="text-end">Reste</th>
                        <th scope="col" class="text-end">Échu</th>
                        <th scope="col" class="d-none d-lg-table-cell">Depuis</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $currency = (string) $row['currency'];
                        $late     = (float) $row['overdue'] > 0.005;
                        $advance  = (float) ($row['advance'] ?? 0);
                        ?>
                        <tr>
                            <td>
                                <span class="fw-medium">
                                    <?= e(full_name($row['last_name'], $row['post_name'], $row['first_name'])) ?>
                                </span>
                                <?php if ($row['enrollment_status'] === 'cancelled'): ?>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">parti</span>
                                <?php endif; ?>
                                <?php if ($advance > 0.005): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis"
                                          title="Déjà encaissé, pas encore imputé">
                                        avance <?= e(finance_amount($advance, $currency)) ?>
                                    </span>
                                <?php endif; ?>
                                <div class="small text-secondary"><?= e((string) $row['matricule']) ?></div>
                            </td>
                            <td class="d-none d-md-table-cell small">
                                <?= e((string) ($row['classroom_name'] ?? '—')) ?>
                            </td>
                            <td class="text-end text-nowrap small text-secondary">
                                <?= e(finance_amount((float) $row['due'], $currency)) ?>
                            </td>
                            <td class="text-end text-nowrap small text-secondary">
                                <?= e(finance_amount((float) $row['paid'], $currency)) ?>
                            </td>
                            <td class="text-end text-nowrap fw-medium">
                                <?= e(finance_amount((float) $row['balance'], $currency)) ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ($late): ?>
                                    <span class="text-danger-emphasis fw-medium">
                                        <?= e(finance_amount((float) $row['overdue'], $currency)) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-lg-table-cell small">
                                <?= $row['oldest_due'] !== null
                                    ? e(date('d/m/Y', strtotime((string) $row['oldest_due'])))
                                    : '<span class="text-secondary">—</span>' ?>
                            </td>
                            <td class="text-end text-nowrap d-print-none">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(url('/finances/eleve/' . (int) $row['enrollment_id'] . '/avis')) ?>">
                                    Avis
                                </a>
                                <?php if (can('payment.record')): ?>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/finances/eleve/' . (int) $row['enrollment_id'] . '/encaisser')) ?>">
                                        Encaisser
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ========================= RECOUVREMENT PAR CLASSE ===================== -->
<?php if ($byClass !== []): ?>
    <div class="card mt-3">
        <div class="card-header fw-medium">Recouvrement par classe</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Classe</th>
                        <th scope="col">Devise</th>
                        <th scope="col" class="text-end">Reste</th>
                        <th scope="col" class="text-end">Échu</th>
                        <th scope="col" class="text-center">Débiteurs</th>
                        <th scope="col" class="text-center">En retard</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($byClass as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['classroom_name'] ?? '—')) ?></td>
                            <td class="small"><?= e((string) $row['currency']) ?></td>
                            <td class="text-end text-nowrap">
                                <?= e(finance_amount((float) $row['balance'], (string) $row['currency'])) ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?= (float) $row['overdue'] > 0.005
                                    ? '<span class="text-danger-emphasis">'
                                        . e(finance_amount((float) $row['overdue'], (string) $row['currency']))
                                        . '</span>'
                                    : '<span class="text-secondary">—</span>' ?>
                            </td>
                            <td class="text-center"><?= (int) $row['debtors'] ?></td>
                            <td class="text-center">
                                <?= (int) $row['late'] > 0
                                    ? '<span class="badge bg-danger-subtle text-danger-emphasis">'
                                        . (int) $row['late'] . '</span>'
                                    : '<span class="text-secondary">—</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
