<?php
/**
 * MON ABONNEMENT — ce que l'offre permet, et où en est l'école.
 *
 * Écran de LECTURE. L'école ne change pas d'offre depuis ici : vendre
 * est le métier de l'éditeur. Ce que cet écran doit faire, c'est qu'un
 * refus d'inscription ne soit jamais une surprise.
 *
 * @var ?array $subscription
 * @var array  $usage
 * @var ?int   $daysLeft
 * @var array  $plans
 */
declare(strict_types=1);

set_title('Mon abonnement');

$statusLabels = [
    'trial'     => 'Essai',
    'active'    => 'Actif',
    'past_due'  => 'Paiement en retard',
    'suspended' => 'Suspendu',
    'cancelled' => 'Résilié',
];

$statusClass = [
    'trial'     => 'bg-info-subtle text-info-emphasis',
    'active'    => 'bg-success-subtle text-success-emphasis',
    'past_due'  => 'bg-warning-subtle text-warning-emphasis',
    'suspended' => 'bg-danger-subtle text-danger-emphasis',
    'cancelled' => 'bg-secondary-subtle text-secondary-emphasis',
];

/**
 * Une jauge honnête.
 *
 * Trois états, jamais confondus :
 *  · un plafond → « 42 / 150 » et une barre ;
 *  · pas de plafond (offre Réseau) → « illimité » ;
 *  · pas d'abonnement qui gouverne → le décompte SEUL. Dire « illimité »
 *    à une école suspendue serait le contraire de la vérité.
 */
$gauge = static function (array $line) use ($usage): array {
    if ($line['limit'] === null && !$usage['governed']) {
        return ['pct' => 0, 'class' => 'bg-secondary', 'text' => (string) $line['used']];
    }

    if ($line['limit'] === null) {
        return ['pct' => 0, 'class' => 'bg-secondary', 'text' => 'illimité'];
    }

    $pct = $line['limit'] > 0
        ? min(100, (int) round($line['used'] / $line['limit'] * 100))
        : 100;

    $class = $pct >= 100 ? 'bg-danger' : ($pct >= 85 ? 'bg-warning' : 'bg-success');

    return ['pct' => $pct, 'class' => $class, 'text' => $line['used'] . ' / ' . $line['limit']];
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Mon abonnement</h1>
        <p class="page-subtitle">Ce que votre offre permet, et où vous en êtes</p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($subscription === null): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="fw-medium mb-2">Aucun abonnement enregistré.</p>
            <p class="text-secondary mb-0">
                Contactez l'éditeur : sans abonnement, aucune nouvelle inscription
                n'est possible. Vos dossiers restent entiers et consultables.
            </p>
        </div>
    </div>
<?php else: ?>
    <?php $status = (string) $subscription['status']; ?>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="fw-medium">Offre <?= e((string) $subscription['plan_name']) ?></span>
                    <span class="badge <?= e($statusClass[$status] ?? 'bg-secondary-subtle') ?>">
                        <?= e($statusLabels[$status] ?? $status) ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-secondary">Période</span>
                        <span>
                            du <?= e(date('d/m/Y', strtotime((string) $subscription['starts_on']))) ?>
                            au <?= e(date('d/m/Y', strtotime((string) $subscription['ends_on']))) ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-secondary">Facturation</span>
                        <span><?= $subscription['billing_cycle'] === 'monthly' ? 'Mensuelle' : 'Annuelle' ?></span>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span class="text-secondary">Tarif</span>
                        <span>
                            <?= e(number_format(
                                (float) ($subscription['billing_cycle'] === 'monthly'
                                    ? $subscription['price_monthly']
                                    : $subscription['price_yearly']),
                                2, ',', ' '
                            )) ?>
                            <?= e((string) $subscription['currency']) ?>
                        </span>
                    </div>

                    <?php if ($daysLeft !== null): ?>
                        <hr>
                        <?php if ($daysLeft < 0): ?>
                            <p class="mb-0 text-danger-emphasis">
                                <strong>Échéance dépassée de <?= abs($daysLeft) ?> jour(s).</strong>
                                Vos données restent entières et consultables ; contactez
                                l'éditeur pour régulariser.
                            </p>
                        <?php elseif ($daysLeft <= 14): ?>
                            <p class="mb-0 text-warning-emphasis">
                                <strong>Plus que <?= (int) $daysLeft ?> jour(s)</strong> avant l'échéance.
                            </p>
                        <?php else: ?>
                            <p class="mb-0 text-secondary small">
                                <?= (int) $daysLeft ?> jours avant l'échéance.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header fw-medium">Où vous en êtes</div>
                <div class="card-body">
                    <?php if (!$usage['governed']): ?>
                        <?php
                        /*
                         * ZÉRO N'EST PAS UN PLAFOND D'OFFRE.
                         * Sans abonnement actif, la limite interne vaut 0 pour
                         * refuser toute création. Affichée telle quelle, elle
                         * donnerait « 1 / 0 élève ». L'état se NOMME, il ne se
                         * chiffre pas.
                         */
                        ?>
                        <div class="alert alert-warning py-2 small">
                            <strong>Aucun abonnement actif.</strong>
                            Aucune inscription ni création de compte n'est possible.
                            Les décomptes ci-dessous restent exacts.
                        </div>
                    <?php endif; ?>

                    <?php
                    /*
                     * UNE JAUGE PAR ANNÉE COMPTÉE.
                     * La limite se mesure sur l'année VISÉE : une école qui
                     * prépare sa rentrée porte deux décomptes vivants. N'en
                     * montrer qu'un faisait annoncer des places libres là où
                     * l'inscription refusait (audit 7A).
                     */
                    ?>
                    <?php foreach ($usage['years'] as $year): ?>
                        <?php $g = $gauge($year); ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>
                                    Élèves — <?= e($year['name']) ?>
                                    <?php if ($year['is_current']): ?>
                                        <span class="badge bg-primary-subtle text-primary-emphasis">en cours</span>
                                    <?php endif; ?>
                                </span>
                                <span class="fw-medium"><?= e($g['text']) ?></span>
                            </div>
                            <?php if ($year['limit'] !== null): ?>
                                <div class="progress" style="height:.5rem">
                                    <div class="progress-bar <?= e($g['class']) ?>"
                                         role="progressbar"
                                         style="width: <?= (int) $g['pct'] ?>%"
                                         aria-valuenow="<?= (int) $g['pct'] ?>"
                                         aria-valuemin="0" aria-valuemax="100"
                                         aria-label="Élèves <?= e($year['name']) ?>"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($usage['years'] === []): ?>
                        <p class="small text-secondary">
                            Aucune année scolaire ouverte : rien à décompter.
                        </p>
                    <?php endif; ?>

                    <?php
                    /*
                     * LE PERSONNEL N'A PAS DE JAUGE, ET C'EST VOULU.
                     *
                     * Aucun écran ne crée de compte du personnel aujourd'hui —
                     * il n'existe pas de module Utilisateurs — donc une barre
                     * qui rougit annoncerait un blocage que rien ne porte.
                     *
                     * Une jauge qui annonce un plafond que rien ne porte est
                     * une promesse creuse : le jour où elle mordra vraiment,
                     * l'école aura appris à ne pas la croire.
                     */
                    $flat = [
                        'staff_users'  => ['Comptes du personnel', true],
                        'family_users' => ['Comptes des familles', false],
                    ];
                    ?>
                    <?php foreach ($flat as $key => [$label, $sayIncluded]): ?>
                        <?php $g = $gauge($usage[$key]); ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span><?= e($label) ?></span>
                                <span class="fw-medium"><?= e($g['text']) ?></span>
                            </div>
                            <?php if ($sayIncluded && $usage[$key]['limit'] !== null): ?>
                                <div class="small text-secondary">
                                    Compris dans l'offre. Les comptes du personnel sont
                                    ouverts par l'éditeur.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php /* LA RÈGLE QUI SURPREND LE PLUS, DONC CELLE QU'IL FAUT DIRE. */ ?>
                    <p class="small text-secondary mb-0">
                        Les comptes des <strong>parents et des élèves</strong> ne comptent pas
                        dans la limite : ouvrir le portail à vos familles ne change pas votre
                        offre.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <?php
    /*
     * L'alerte se déduit des MÊMES lignes que les jauges — pas d'un
     * second décompte. Elle nomme les années réellement fermées, parce
     * qu'une école dont seule la rentrée est pleine doit savoir qu'elle
     * peut encore inscrire sur l'année en cours.
     */
    $full = array_values(array_filter(
        $usage['years'],
        static fn (array $y): bool => $y['limit'] !== null && $y['used'] >= $y['limit']
    ));
    ?>
    <?php if ($full !== []): ?>
        <div class="alert alert-warning">
            <strong>Le plafond d'élèves est atteint
                <?= count($full) === count($usage['years'])
                    ? 'sur toutes vos années ouvertes'
                    : 'sur : ' . e(implode(', ', array_column($full, 'name'))) ?>.</strong>
            Aucune nouvelle inscription ni réinscription n'y est possible sur cette offre.
            <strong>Aucune donnée n'est perdue</strong> : les dossiers, les bulletins et la
            caisse restent entiers et utilisables. Contactez l'éditeur pour changer d'offre.
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ============================ LES OFFRES ============================ -->
<div class="card">
    <div class="card-header fw-medium">Les offres</div>
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
                    <?php $isMine = $subscription !== null
                        && (int) $plan['id'] === (int) $subscription['plan_id']; ?>
                    <tr<?= $isMine ? ' class="table-active"' : '' ?>>
                        <td>
                            <strong><?= e((string) $plan['name']) ?></strong>
                            <?php if ($isMine): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis ms-1">votre offre</span>
                            <?php endif; ?>
                            <?php if ($plan['description'] !== null && $plan['description'] !== ''): ?>
                                <div class="small text-secondary"><?= e((string) $plan['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?= e(number_format((float) $plan['price_monthly'], 2, ',', ' ')) ?>
                            <?= e((string) $plan['currency']) ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?= e(number_format((float) $plan['price_yearly'], 2, ',', ' ')) ?>
                            <?= e((string) $plan['currency']) ?>
                        </td>
                        <td class="text-end">
                            <?= $plan['max_students'] === null ? 'illimité' : (int) $plan['max_students'] ?>
                        </td>
                        <td class="text-end d-none d-md-table-cell">
                            <?= $plan['max_users'] === null ? 'illimité' : (int) $plan['max_users'] ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body">
        <p class="small text-secondary mb-0">
            Le changement d'offre se fait auprès de l'éditeur. Il prend effet immédiatement,
            sans interruption de service et sans perte de données.
        </p>
    </div>
</div>
