<?php
/**
 * Dépenses et situation de caisse.
 *
 * L'écran répond à la question que le directeur pose le soir : COMBIEN
 * RESTE-T-IL EN CAISSE. Il la pose en tête, avant tout le reste.
 *
 * La monnaie comptée est celle qui a été REMISE au guichet, jamais
 * celle portée au crédit des dettes : un parent qui remet 140 000 CDF
 * pour un minerval en dollars met bien 140 000 CDF dans le tiroir.
 *
 * @var array  $year
 * @var array  $years
 * @var array  $expenses
 * @var array  $filters
 * @var array  $categories
 * @var array  $currencies
 * @var array  $methods
 * @var array  $totals    Par devise, avec le détail par poste
 * @var array  $position  Entrées, sorties, solde — par devise
 * @var string $today
 * @var string $defaultCurrency
 */
declare(strict_types=1);

set_title('Dépenses');

$cancelled = array_filter($expenses, static fn (array $x): bool => (int) $x['is_cancelled'] === 1);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Dépenses</h1>
        <p class="page-subtitle">
            Année <?= e($year['code']) ?> · <?= count($expenses) ?> bon(s)
            <?php if ($cancelled !== []): ?> · <?= count($cancelled) ?> annulé(s)<?php endif; ?>
        </p>
    </div>

    <button type="button" class="btn btn-outline-secondary d-print-none" data-print>
        <i class="bi bi-printer me-1"></i> Imprimer
    </button>
</div>

<div class="d-print-none"><?php require APP_PATH . '/views/partials/flash.php'; ?></div>

<!-- ========================= LA SITUATION DE CAISSE ====================== -->
<div class="card mb-3">
    <div class="card-header fw-medium d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Situation de caisse au <?= e(date('d/m/Y', strtotime($today))) ?></span>
        <span class="badge bg-secondary-subtle text-secondary-emphasis fw-normal">
            toutes années confondues
        </span>
    </div>
    <div class="card-body">
        <?php if ($position === []): ?>
            <span class="text-secondary">Aucun mouvement enregistré.</span>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="max-width:40rem">
                    <thead>
                        <tr>
                            <th scope="col">Devise</th>
                            <th scope="col" class="text-end">Entré</th>
                            <th scope="col" class="text-end">Sorti</th>
                            <th scope="col" class="text-end">Devrait rester</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($position as $currency => $line): ?>
                            <tr>
                                <td class="fw-medium"><?= e((string) $currency) ?></td>
                                <td class="text-end text-nowrap">
                                    <?= e(finance_amount((float) $line['in'], (string) $currency)) ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?= e(finance_amount((float) $line['out'], (string) $currency)) ?>
                                </td>
                                <td class="text-end text-nowrap fs-5 fw-semibold
                                           <?= (float) $line['balance'] < 0 ? 'text-danger-emphasis' : '' ?>">
                                    <?= e(finance_amount((float) $line['balance'], (string) $currency)) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p class="small text-secondary mb-0 mt-2">
            Le tiroir-caisse ne se vide pas au changement d'exercice : ce solde cumule
            <strong>tous les exercices depuis l'ouverture</strong>, tandis que le tableau
            des dépenses ci-dessous ne montre que l'année choisie. Les deux chiffres ne
            se recoupent donc pas, et c'est normal.
        </p>

        <p class="small text-secondary mb-0 mt-2">
            La monnaie comptée est celle qui a été <strong>remise au guichet</strong>, pas
            celle portée au crédit des dettes : un parent qui remet des francs pour un
            minerval en dollars met bien des francs dans le tiroir. Les devises ne
            s'additionnent jamais entre elles.
        </p>

        <?php
        $negative = array_filter($position, static fn (array $l): bool => (float) $l['balance'] < -0.005);
        ?>
        <?php if ($negative !== []): ?>
            <div class="alert alert-warning mt-3 mb-0 small">
                <strong>Un solde négatif ne veut pas dire que la caisse est vide.</strong>
                Il signale qu'une monnaie a été dépensée sans avoir été encaissée — le
                caissier a converti l'autre devise au guichet pour régler un fournisseur.
                Ce module n'enregistre pas encore les <strong>changes de monnaie</strong> :
                jusque-là, rapprochez les deux lignes à la main. Ce n'est pas une erreur
                de saisie à corriger.
            </div>
        <?php endif; ?>

    </div>
</div>

<div class="row g-3">
    <!-- ============================ LES DÉPENSES =========================== -->
    <div class="col-12 col-xl-8">
        <!-- Filtres -->
        <form method="get" action="<?= e(url('/finances/depenses')) ?>"
              class="card card-body mb-3 d-print-none">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="x-annee">Année</label>
                    <select name="annee" id="x-annee" class="form-select form-select-sm">
                        <?php foreach ($years as $y): ?>
                            <option value="<?= (int) $y['id'] ?>"
                                <?= (int) $y['id'] === (int) $year['id'] ? 'selected' : '' ?>>
                                <?= e($y['code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="x-poste">Poste</label>
                    <select name="poste" id="x-poste" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                <?= (int) ($filters['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="x-du">Du</label>
                    <input type="date" name="du" id="x-du" class="form-control form-control-sm"
                           value="<?= e((string) ($filters['from'] ?? '')) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="x-au">Au</label>
                    <input type="date" name="au" id="x-au" class="form-control form-control-sm"
                           value="<?= e((string) ($filters['to'] ?? '')) ?>">
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Filtrer</button>
                </div>
            </div>
        </form>

        <?php if ($expenses === []): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-arrow-down-circle display-6 text-secondary d-block mb-2"></i>
                    <p class="mb-0 fw-medium">Aucune dépense enregistrée pour ce filtre.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Bon</th>
                                <th scope="col">Bénéficiaire</th>
                                <th scope="col" class="d-none d-md-table-cell">Poste</th>
                                <th scope="col" class="text-end">Montant</th>
                                <th scope="col" class="d-none d-lg-table-cell">Date</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $x): ?>
                                <?php $void = (int) $x['is_cancelled'] === 1; ?>
                                <tr<?= $void ? ' class="opacity-50"' : '' ?>>
                                    <td>
                                        <span class="small"><?= e($x['voucher_no']) ?></span>
                                        <?php if ($void): ?>
                                            <span class="badge bg-danger-subtle text-danger-emphasis">annulé</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-medium"><?= e($x['beneficiary']) ?></span>
                                        <div class="small text-secondary"><?= e($x['description']) ?></div>
                                        <?php if ($void && $x['cancelled_reason'] !== null): ?>
                                            <div class="small text-danger-emphasis">
                                                Motif : <?= e((string) $x['cancelled_reason']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="d-none d-md-table-cell small">
                                        <?= e((string) ($x['category_name'] ?? 'Poste supprimé')) ?>
                                        <div class="text-secondary">
                                            <?= e($methods[$x['method']] ?? (string) $x['method']) ?>
                                            <?php if ($x['supporting_doc'] !== null): ?>
                                                · pièce <?= e((string) $x['supporting_doc']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end text-nowrap fw-medium<?= $void ? ' text-decoration-line-through' : '' ?>">
                                        <?= e(finance_amount((float) $x['amount'], (string) $x['currency'])) ?>
                                    </td>
                                    <td class="d-none d-lg-table-cell small">
                                        <?= e(date('d/m/Y', strtotime((string) $x['spent_on']))) ?>
                                    </td>
                                    <td class="text-end d-print-none">
                                        <?php if (!$void && can('expense.cancel')): ?>
                                            <button class="btn btn-sm btn-outline-danger" type="button"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#x-<?= (int) $x['id'] ?>">
                                                Annuler
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <?php if (!$void && can('expense.cancel')): ?>
                                    <tr class="collapse" id="x-<?= (int) $x['id'] ?>">
                                        <td colspan="6" class="bg-body-tertiary">
                                            <form method="post"
                                                  action="<?= e(url('/finances/depenses/' . (int) $x['id'] . '/annuler')) ?>"
                                                  class="d-flex flex-wrap gap-2 align-items-center">
                                                <?= csrf_field() ?>
                                                <input type="text" name="motif" required minlength="5" maxlength="160"
                                                       class="form-control form-control-sm" style="max-width:24rem"
                                                       placeholder="Motif de l'annulation (obligatoire)">
                                                <button type="submit" class="btn btn-sm btn-danger">Annuler ce bon</button>
                                                <span class="small text-secondary">
                                                    Le bon reste visible, barré, et son numéro reste consommé.
                                                </span>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="small text-secondary mt-2">
                Les bons annulés restent affichés, barrés. Un trou dans la séquence des
                numéros ne signalerait pas une erreur de saisie : il signalerait une ligne
                effacée.
            </p>
        <?php endif; ?>
    </div>

    <!-- ===================== SAISIE ET TOTAUX PAR POSTE ==================== -->
    <div class="col-12 col-xl-4">
        <div class="card d-print-none">
            <div class="card-header fw-medium">Nouvelle sortie de caisse</div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/finances/depenses')) ?>">
                    <?= csrf_field() ?>
                    <?php /* Une enveloppe, un bon : le double clic est refusé. */ ?>
                    <?= form_nonce_field('finance.expense') ?>
                    <input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>">

                    <div class="mb-3">
                        <label class="form-label" for="x-benef">Bénéficiaire</label>
                        <input type="text" class="form-control" id="x-benef" name="beneficiary"
                               required maxlength="160" placeholder="À qui l'argent est remis">
                        <div class="form-text">
                            Obligatoire : une dépense sans destinataire nommé est un trou
                            dans la caisse.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="x-desc">Motif</label>
                        <input type="text" class="form-control" id="x-desc" name="description"
                               required maxlength="255" placeholder="Achat de craies et registres">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="x-cat">Poste</label>
                        <select class="form-select" id="x-cat" name="category_id" required>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label" for="x-amount">Montant</label>
                            <input type="text" class="form-control" id="x-amount" name="amount"
                                   inputmode="decimal" required placeholder="45 000">
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="x-cur">Devise</label>
                            <select class="form-select" id="x-cur" name="currency">
                                <?php foreach ($currencies as $code => $label): ?>
                                    <option value="<?= e($code) ?>"
                                        <?= $code === $defaultCurrency ? 'selected' : '' ?>>
                                        <?= e($code) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="x-date">Date</label>
                            <input type="date" class="form-control" id="x-date" name="spent_on"
                                   value="<?= e($today) ?>" max="<?= e($today) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="x-method">Mode</label>
                            <select class="form-select" id="x-method" name="method">
                                <?php foreach ($methods as $key => $label): ?>
                                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="x-ref">Référence</label>
                            <input type="text" class="form-control" id="x-ref" name="reference"
                                   maxlength="80" placeholder="N° transaction">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="x-doc">Pièce</label>
                            <input type="text" class="form-control" id="x-doc" name="supporting_doc"
                                   maxlength="80" placeholder="N° facture">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-arrow-down-circle me-1"></i> Enregistrer et numéroter le bon
                    </button>
                </form>
            </div>
        </div>

        <?php if ($totals !== []): ?>
            <div class="card mt-3">
                <div class="card-header fw-medium">Dépenses de l'année, par poste</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <?php foreach ($totals as $currency => $block): ?>
                                <tr class="table-light">
                                    <th scope="row"><?= e((string) $currency) ?></th>
                                    <td class="text-end fw-semibold">
                                        <?= e(finance_amount((float) $block['total'], (string) $currency)) ?>
                                    </td>
                                </tr>
                                <?php foreach ($block['categories'] as $name => $amount): ?>
                                    <tr>
                                        <td class="small ps-4"><?= e((string) $name) ?></td>
                                        <td class="text-end small text-nowrap">
                                            <?= e(finance_amount((float) $amount, (string) $currency)) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
