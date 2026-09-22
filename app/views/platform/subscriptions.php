<?php
/**
 * CONSOLE — la veille des abonnements.
 *
 * CET ÉCRAN NE LISTE PAS LE PARC.
 * Il ne montre que ce qui demande une décision : ce qui expire, ce qui
 * n'a plus d'abonnement, ce qui dépasse son plafond. Une console qui
 * affiche tout n'affiche rien — l'éditeur la parcourt le lundi matin et
 * doit pouvoir la vider.
 *
 * @var array  $watch
 * @var array  $conflicts
 * @var array  $plans
 * @var ?array $visiting
 */
declare(strict_types=1);

set_title('Abonnements');

/** Pourquoi cette école est-elle dans la liste ? */
$reasonOf = static function (array $row): array {
    if ($row['subscription_id'] === null) {
        return ['Aucun abonnement', 'bg-danger-subtle text-danger-emphasis'];
    }

    $limit = $row['max_students_override'] !== null
        ? (int) $row['max_students_override']
        : ($row['plan_max_students'] !== null ? (int) $row['plan_max_students'] : null);

    if ($limit !== null && (int) $row['students'] >= $limit) {
        return ['Plafond atteint', 'bg-warning-subtle text-warning-emphasis'];
    }

    $days = (int) $row['days_left'];

    if ($days < 0) {
        return ['Échéance dépassée de ' . abs($days) . ' j', 'bg-danger-subtle text-danger-emphasis'];
    }

    return ['Expire dans ' . $days . ' j', 'bg-info-subtle text-info-emphasis'];
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Abonnements</h1>
        <p class="page-subtitle">Ce qui demande une décision — pas le parc entier</p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($conflicts !== []): ?>
    <?php
    /*
     * L'INVARIANT EST VÉRIFIÉ, PAS SEULEMENT ESPÉRÉ.
     *
     * MySQL ne sait pas exprimer un UNIQUE conditionnel sur
     * `status IN ('trial','active','past_due')`. L'invariant est donc
     * porté par le service — et une école qui en porterait deux
     * viendrait forcément d'une écriture faite HORS de cette console.
     * Ce bloc est là pour que ce jour-là se voie.
     */
    ?>
    <div class="alert alert-danger">
        <strong>Incohérence détectée :
            <?= count($conflicts) ?> établissement<?= count($conflicts) > 1 ? 's portent' : ' porte' ?>
            plusieurs abonnements en cours.</strong>
        Un abonnement n'a pu être créé hors de cette console qu'en écrivant
        directement en base. À corriger en appliquant une offre depuis la fiche,
        ce qui clôture toutes les lignes en cours avant d'en ouvrir une.
        <ul class="mb-0 mt-2">
            <?php foreach ($conflicts as $c): ?>
                <li>
                    <a href="<?= e(url('/plateforme/ecoles/' . (int) $c['id'])) ?>">
                        <?= e((string) $c['name']) ?>
                    </a>
                    — <?= (int) $c['n'] ?> abonnements
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($watch === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="fw-medium mb-1">Rien à traiter.</p>
            <p class="text-secondary mb-0">
                Aucun abonnement n'expire dans les 30 jours, aucun plafond n'est atteint,
                et chaque établissement porte une offre en cours.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Établissement</th>
                        <th scope="col">À traiter</th>
                        <th scope="col">Offre</th>
                        <th scope="col" class="text-end">Élèves</th>
                        <th scope="col" class="d-none d-md-table-cell">Échéance</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($watch as $row): ?>
                        <?php [$reason, $reasonClass] = $reasonOf($row); ?>
                        <?php
                        $limit = $row['max_students_override'] !== null
                            ? (int) $row['max_students_override']
                            : ($row['plan_max_students'] !== null ? (int) $row['plan_max_students'] : null);
                        ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('/plateforme/ecoles/' . (int) $row['id'])) ?>"
                                   class="fw-medium text-decoration-none">
                                    <?= e((string) $row['name']) ?>
                                </a>
                                <div class="small text-secondary"><?= e((string) $row['code']) ?></div>
                            </td>
                            <td>
                                <span class="badge <?= e($reasonClass) ?>"><?= e($reason) ?></span>
                            </td>
                            <td class="small">
                                <?= $row['plan_name'] === null ? '—' : e((string) $row['plan_name']) ?>
                            </td>
                            <td class="text-end small">
                                <?= (int) $row['students'] ?><?= $limit !== null ? ' / ' . $limit : '' ?>
                            </td>
                            <td class="small d-none d-md-table-cell">
                                <?= $row['ends_on'] !== null
                                    ? e(date('d/m/Y', strtotime((string) $row['ends_on'])))
                                    : '—' ?>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="<?= e(url('/plateforme/ecoles/' . (int) $row['id'])) ?>">
                                    Traiter
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card mt-3">
    <div class="card-header fw-medium">Le catalogue</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Offre</th>
                    <th scope="col" class="text-end">Par mois</th>
                    <th scope="col" class="text-end">Par an</th>
                    <th scope="col" class="text-end">Élèves</th>
                    <th scope="col" class="text-end d-none d-md-table-cell">Personnel</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td><strong><?= e((string) $plan['name']) ?></strong></td>
                        <td class="text-end text-nowrap small">
                            <?= e(number_format((float) $plan['price_monthly'], 2, ',', ' ')) ?>
                            <?= e((string) $plan['currency']) ?>
                        </td>
                        <td class="text-end text-nowrap small">
                            <?= e(number_format((float) $plan['price_yearly'], 2, ',', ' ')) ?>
                            <?= e((string) $plan['currency']) ?>
                        </td>
                        <td class="text-end small">
                            <?= $plan['max_students'] === null ? 'illimité' : (int) $plan['max_students'] ?>
                        </td>
                        <td class="text-end small d-none d-md-table-cell">
                            <?= $plan['max_users'] === null ? 'illimité' : (int) $plan['max_users'] ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body">
        <p class="small text-secondary mb-0">
            Le catalogue se modifie en base : <code>platform.plan.manage</code> n'a pas
            encore d'écran. Créer une offre est rare et engage tout le parc.
        </p>
    </div>
</div>
