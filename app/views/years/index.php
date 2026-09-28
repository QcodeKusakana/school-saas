<?php
/**
 * Les années scolaires — création, désignation, clôture, réouverture.
 *
 * CE QUE CET ÉCRAN DOIT DIRE AVANT DE LAISSER CLÔTURER
 * =====================================================
 * Une clôture interdit toute écriture sur l'année, dans sept modules à
 * la fois. Le bouton n'apparaît donc qu'après le compte rendu de ce que
 * l'année contient d'inachevé — bulletins sans décision, cotes
 * manquantes, élèves sans classe, impayés.
 *
 *   > Une décision irréversible qu'on prend sans voir ce qu'elle fige
 *   > n'est pas une décision, c'est un pari.
 *
 * L'écran ne REFUSE pas la clôture pour autant : une école peut avoir de
 * bonnes raisons de clôturer avec des impayés, et ce n'est pas au
 * logiciel d'en juger.
 *
 * @var array $annees
 * @var int   $detail
 * @var array|null $contenu
 * @var array $labels
 */
declare(strict_types=1);

set_title('Années scolaires');

$badge = [
    'draft'    => 'text-bg-secondary',
    'active'   => 'text-bg-success',
    'closed'   => 'text-bg-dark',
    'archived' => 'text-bg-dark',
];
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Années scolaires</h1>
        <p class="page-subtitle"><?= count($annees) ?> année(s) enregistrée(s)</p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($annees === []): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Aucune année scolaire n'est enregistrée. Créez-en une : sans année
        courante, les écrans d'inscription, de classes et de finances
        restent vides.
    </div>
<?php elseif (array_filter($annees, static fn (array $a): bool => (int) $a['is_current'] === 1) === []): ?>
    <div class="alert alert-danger small">
        <i class="bi bi-exclamation-octagon me-1"></i>
        <strong>Aucune année n'est désignée comme courante.</strong>
        Les écrans d'inscription, de classes, de présences et de finances
        ne peuvent pas fonctionner tant qu'une année n'est pas désignée.
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Année</th>
                    <th class="d-none d-md-table-cell">Période</th>
                    <th>État</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($annees as $a): ?>
                    <?php
                    $estCourante = (int) $a['is_current'] === 1;
                    $estClose    = in_array((string) $a['status'], ['closed', 'archived'], true);
                    ?>
                    <tr class="<?= $estCourante ? 'table-success' : '' ?>">
                        <td>
                            <strong><?= e((string) $a['code']) ?></strong>
                            <?php if ($estCourante): ?>
                                <span class="badge text-bg-success ms-1">Année courante</span>
                            <?php endif; ?>
                            <div class="small text-muted"><?= e((string) $a['name']) ?></div>
                        </td>
                        <td class="small text-muted d-none d-md-table-cell">
                            du <?= e(date('d/m/Y', strtotime((string) $a['starts_on']))) ?>
                            au <?= e(date('d/m/Y', strtotime((string) $a['ends_on']))) ?>
                        </td>
                        <td>
                            <span class="badge <?= e($badge[(string) $a['status']] ?? 'text-bg-secondary') ?>">
                                <?= e($labels[(string) $a['status']] ?? (string) $a['status']) ?>
                            </span>
                            <?php if ($estClose && $a['closed_at'] !== null): ?>
                                <div class="small text-muted">
                                    le <?= e(date('d/m/Y', strtotime((string) $a['closed_at']))) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if (!$estClose && !$estCourante && can('academic_year.manage')): ?>
                                <form method="post" class="d-inline"
                                      action="<?= e(url('/annees/' . (int) $a['id'] . '/courante')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-success py-0 px-2">
                                        Désigner courante
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if (!$estClose && can('academic_year.close')): ?>
                                <a class="btn btn-sm btn-outline-secondary py-0 px-2"
                                   href="<?= e(url('/annees', ['detail' => (int) $a['id']])) ?>">
                                    Préparer la clôture
                                </a>
                            <?php endif; ?>

                            <?php if ($estClose && can('academic_year.close')): ?>
                                <a class="btn btn-sm btn-outline-warning py-0 px-2"
                                   href="<?= e(url('/annees', ['detail' => (int) $a['id']])) ?>">
                                    Rouvrir
                                </a>
                            <?php endif; ?>

                            <?php
                            /*
                             * La suppression n'est offerte que pour une année
                             * NI courante NI clôturée. Le service refuse en
                             * outre toute année qui porte des données, et il
                             * dit lesquelles : neuf tables sont en cascade,
                             * et une suppression silencieuse emporterait les
                             * inscriptions.
                             */
                            if (!$estClose && !$estCourante && can('academic_year.manage')):
                                ?>
                                <form method="post" class="d-inline"
                                      action="<?= e(url('/annees/' . (int) $a['id'] . '/supprimer')) ?>"
                                      onsubmit="return confirm('Supprimer l\'année <?= e((string) $a['code']) ?> ? Elle ne sera supprimée que si elle ne contient aucune donnée.');">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-danger py-0 px-2"
                                            title="Ne fonctionne que sur une année vide">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$annee = null;

foreach ($annees as $a) {
    if ((int) $a['id'] === $detail) {
        $annee = $a;
    }
}
?>

<?php if ($annee !== null && $contenu !== null): ?>
    <?php $estClose = in_array((string) $annee['status'], ['closed', 'archived'], true); ?>

    <div class="card mb-3 border-<?= $estClose ? 'warning' : 'dark' ?>-subtle">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <?= $estClose ? 'Rouvrir' : 'Clôturer' ?> l'année <?= e((string) $annee['code']) ?>
            </h2>

            <?php if (!$estClose): ?>
                <p class="small text-muted">
                    La clôture <strong>interdit toute écriture</strong> sur cette année
                    dans sept modules — élèves, classes, enseignants, référentiel,
                    présences, bulletins et finances. Elle ne supprime rien : la
                    consultation, les bulletins et les documents restent accessibles.
                    Voici ce que l'année contient aujourd'hui :
                </p>

                <div class="row g-2 mb-3">
                    <?php foreach ([
                        ['Élèves inscrits',      $contenu['inscrits'],         false],
                        ['Classes',              $contenu['classes'],          false],
                        ['Bulletins',            $contenu['bulletins'],        false],
                        ['Sans classe',          $contenu['sans_classe'],      true],
                        ['Bulletins sans décision', $contenu['sans_decision'], true],
                        ['Cotes manquantes',     $contenu['cotes_manquantes'], true],
                        ['Dossiers avec impayés', $contenu['impayes'],         true],
                    ] as [$libelle, $valeur, $alerte]): ?>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 <?= $alerte && $valeur > 0 ? 'border-warning bg-warning-subtle' : '' ?>">
                                <div class="small text-muted"><?= e($libelle) ?></div>
                                <div class="fs-5 fw-semibold"><?= (int) $valeur ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ((int) $annee['is_current'] === 1): ?>
                    <div class="alert alert-danger small mb-0">
                        <i class="bi bi-exclamation-octagon me-1"></i>
                        Cette année est <strong>l'année courante</strong>. Créez l'année
                        suivante et désignez-la comme courante avant de clôturer
                        celle-ci : sans année courante, l'établissement n'a plus
                        d'écran de travail.
                    </div>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/annees/' . (int) $annee['id'] . '/cloturer')) ?>"
                          onsubmit="return confirm('Clôturer l\'année <?= e((string) $annee['code']) ?> ? Les écritures y seront refusées. La réouverture restera possible, et sera inscrite au journal.');">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-dark">
                            <i class="bi bi-lock me-1"></i>Clôturer l'année <?= e((string) $annee['code']) ?>
                        </button>
                        <a class="btn btn-sm btn-link" href="<?= e(url('/annees')) ?>">Annuler</a>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="small text-muted">
                    Rouvrir cette année rend de nouveau modifiables des bulletins
                    signés et des écritures comptables arrêtées.
                    <strong>Le motif est obligatoire et reste inscrit au journal.</strong>
                </p>

                <form method="post" action="<?= e(url('/annees/' . (int) $annee['id'] . '/rouvrir')) ?>"
                      class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-12 col-md-8">
                        <label class="form-label small mb-1" for="motif">
                            Motif de la réouverture
                        </label>
                        <input type="text" class="form-control form-control-sm" id="motif"
                               name="motif" minlength="10" required
                               placeholder="Ex. : correction d'une cote contestée en 6ème A">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-warning">
                            <i class="bi bi-unlock me-1"></i>Rouvrir
                        </button>
                        <a class="btn btn-sm btn-link" href="<?= e(url('/annees')) ?>">Annuler</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (can('academic_year.manage')): ?>
    <div class="card">
        <div class="card-body">
            <h2 class="h6 mb-3">Créer une année scolaire</h2>
            <form method="post" action="<?= e(url('/annees')) ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="code">Code</label>
                    <input type="text" class="form-control form-control-sm" id="code"
                           name="code" maxlength="20" required placeholder="2027-2028">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label small mb-1" for="name">Libellé</label>
                    <input type="text" class="form-control form-control-sm" id="name"
                           name="name" maxlength="100" required placeholder="Année scolaire 2027-2028">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="starts_on">Début</label>
                    <input type="date" class="form-control form-control-sm" id="starts_on"
                           name="starts_on" required>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="ends_on">Fin</label>
                    <input type="date" class="form-control form-control-sm" id="ends_on"
                           name="ends_on" required>
                </div>
                <div class="col-12 col-md-2">
                    <button class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-plus-lg me-1"></i>Créer
                    </button>
                </div>
            </form>
            <p class="small text-muted mt-2 mb-0">
                La nouvelle année est créée en <strong>préparation</strong> : elle
                ne devient courante que lorsque vous la désignez, ce qui vous laisse
                le temps d'y préparer classes et programmes sans perturber l'année
                en cours. Les périodes ne peuvent pas se chevaucher.
            </p>
        </div>
    </div>
<?php endif; ?>
