<?php
/**
 * CONSOLE — les soldes de tout le parc.
 *
 * DEUX LISTES, PAS UNE.
 * Ce qui nous est dû, et ce que NOUS devons. Un écran qui ne montrerait
 * que les dettes ferait disparaître les trop-perçus — or ils engagent
 * l'éditeur autant : il doit du service ou un remboursement.
 *
 * @var array  $balances
 * @var ?array $visiting
 */
declare(strict_types=1);

set_title('Soldes');

$owed    = array_values(array_filter($balances, static fn (array $b): bool => (float) $b['balance'] > 0));
$credits = array_values(array_filter($balances, static fn (array $b): bool => (float) $b['balance'] < 0));

/** Totaux PAR DEVISE — jamais un total unique. */
$sumByCurrency = static function (array $rows): array {
    $out = [];

    foreach ($rows as $r) {
        $cur = (string) $r['currency'];
        $out[$cur] = ($out[$cur] ?? 0.0) + abs((float) $r['balance']);
    }

    return $out;
};

$table = static function (array $rows, string $emptyLabel): void {
    if ($rows === []) {
        echo '<div class="card-body text-secondary small">' . e($emptyLabel) . '</div>';

        return;
    }
    ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Établissement</th>
                    <th scope="col" class="text-end">Dû</th>
                    <th scope="col" class="text-end">Versé</th>
                    <th scope="col" class="text-end">Solde</th>
                    <th scope="col" class="d-none d-md-table-cell">Depuis</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $b): ?>
                    <?php $cur = (string) $b['currency']; ?>
                    <tr>
                        <td>
                            <a class="fw-medium text-decoration-none"
                               href="<?= e(url('/plateforme/ecoles/' . (int) $b['id'] . '/facturation')) ?>">
                                <?= e((string) $b['name']) ?>
                            </a>
                            <?php if ((int) $b['archived'] === 1): ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">archivée</span>
                            <?php endif; ?>
                            <div class="small text-secondary"><?= e((string) $b['code']) ?></div>
                        </td>
                        <td class="text-end small"><?= e(billing_format((float) $b['due'], $cur)) ?></td>
                        <td class="text-end small"><?= e(billing_format((float) $b['paid'], $cur)) ?></td>
                        <td class="text-end fw-medium">
                            <?= e(billing_format(abs((float) $b['balance']), $cur)) ?>
                        </td>
                        <td class="small d-none d-md-table-cell">
                            <?= e(date('d/m/Y', strtotime((string) $b['since']))) ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary"
                               href="<?= e(url('/plateforme/ecoles/' . (int) $b['id'] . '/facturation')) ?>">
                                Ouvrir
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Soldes</h1>
        <p class="page-subtitle">Ce qui reste dû, et ce qui a été trop versé</p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($balances === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="fw-medium mb-1">Tout est soldé.</p>
            <p class="text-secondary mb-0">
                Aucun établissement ne doit d'argent, et aucun n'a trop versé.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-medium">Ce qui reste dû</span>
            <span class="small text-secondary">
                <?php foreach ($sumByCurrency($owed) as $cur => $total): ?>
                    <span class="ms-2"><?= e(billing_format($total, $cur)) ?></span>
                <?php endforeach; ?>
            </span>
        </div>
        <?php $table($owed, 'Aucun impayé.'); ?>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-medium">Trop-perçus</span>
            <span class="small text-secondary">
                <?php foreach ($sumByCurrency($credits) as $cur => $total): ?>
                    <span class="ms-2"><?= e(billing_format($total, $cur)) ?></span>
                <?php endforeach; ?>
            </span>
        </div>
        <?php $table($credits, 'Aucun trop-perçu.'); ?>
        <?php if ($credits !== []): ?>
            <div class="card-body">
                <p class="small text-secondary mb-0">
                    Ces établissements ont versé plus que dû. C'est un engagement de
                    l'éditeur : du service à rendre, ou un remboursement.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <p class="small text-secondary mt-3 mb-0">
        Les totaux sont donnés <strong>par devise</strong> : additionner des dollars
        et des francs produit un nombre qui ne veut rien dire.
        Les établissements <strong>archivés</strong> restent listés tant qu'ils
        doivent ou ont trop versé — ranger un dossier n'éteint pas une dette.
    </p>
<?php endif; ?>
