<?php
/**
 * CONSOLE — le détail d'un établissement.
 *
 * Trois choses, et rien d'autre : qui est cette école, sur quelle offre
 * elle est, et comment la changer. L'historique en dessous, parce que
 * « depuis quand payons-nous ce tarif ? » est une vraie question.
 *
 * @var array  $school
 * @var array  $subscriptions
 * @var array  $plans
 * @var ?array $visiting
 */
declare(strict_types=1);

set_title($school['name']);

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

$current = null;

foreach ($subscriptions as $sub) {
    if (in_array((string) $sub['status'], ['trial', 'active', 'past_due'], true)) {
        $current = $sub;
        break;
    }
}
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e((string) $school['name']) ?></h1>
        <p class="page-subtitle">
            <?= e((string) $school['code']) ?>
            <?php if ($school['city'] !== null && $school['city'] !== ''): ?>
                · <?= e((string) $school['city']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div>
        <?php if (can('platform.billing.manage')): ?>
            <a class="btn btn-outline-primary"
               href="<?= e(url('/plateforme/ecoles/' . (int) $school['id'] . '/facturation')) ?>">
                Facturation
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/plateforme/ecoles')) ?>">
            Retour à la liste
        </a>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="row g-3">
    <!-- ==================== L'OFFRE EN COURS ==================== -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-medium">Offre en cours</div>
            <div class="card-body">
                <?php if ($current === null): ?>
                    <p class="text-danger-emphasis fw-medium mb-1">Aucun abonnement en cours.</p>
                    <p class="small text-secondary mb-0">
                        Cet établissement ne peut inscrire aucun élève ni créer aucun compte.
                        Ses dossiers restent entiers et consultables, et sa caisse reste ouverte.
                    </p>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-medium"><?= e((string) $current['plan_name']) ?></span>
                        <span class="badge <?= e($statusClass[$current['status']] ?? 'bg-secondary-subtle') ?>">
                            <?= e($statusLabels[$current['status']] ?? (string) $current['status']) ?>
                        </span>
                    </div>

                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-secondary">Période</span>
                        <span>
                            du <?= e(date('d/m/Y', strtotime((string) $current['starts_on']))) ?>
                            au <?= e(date('d/m/Y', strtotime((string) $current['ends_on']))) ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-secondary">Facturation</span>
                        <span><?= $current['billing_cycle'] === 'monthly' ? 'Mensuelle' : 'Annuelle' ?></span>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-secondary">Plafond d'élèves</span>
                        <span>
                            <?php if ($current['max_students_override'] !== null): ?>
                                <strong><?= (int) $current['max_students_override'] ?></strong>
                                <span class="text-secondary">(négocié)</span>
                            <?php else: ?>
                                <?= $current['max_students'] === null ? 'illimité' : (int) $current['max_students'] ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span class="text-secondary">Renouvellement</span>
                        <span><?= (int) $current['auto_renew'] === 1 ? 'automatique' : 'manuel' ?></span>
                    </div>

                    <hr>

                    <!-- CHANGER LE STATUT SANS CHANGER L'OFFRE -->
                    <form method="post"
                          action="<?= e(url('/plateforme/ecoles/' . (int) $school['id'] . '/statut')) ?>">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="status">Statut de l'abonnement</label>
                        <div class="input-group input-group-sm mb-2">
                            <select class="form-select" id="status" name="status">
                                <option value="active">Actif</option>
                                <option value="past_due">Paiement en retard</option>
                                <option value="suspended">Suspendu</option>
                            </select>
                            <button class="btn btn-outline-secondary" type="submit">Appliquer</button>
                        </div>
                        <input type="text" class="form-control form-control-sm" name="reason"
                               placeholder="Motif (obligatoire pour une suspension)">
                        <p class="small text-secondary mt-2 mb-0">
                            Un retard de paiement <strong>ne coupe rien</strong> : c'est la
                            suspension, décidée ici, qui empêche les nouvelles inscriptions.
                            Ni l'une ni l'autre ne ferme la caisse.
                        </p>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==================== APPLIQUER UNE OFFRE ==================== -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header fw-medium">Appliquer une offre</div>
            <div class="card-body">
                <form method="post"
                      action="<?= e(url('/plateforme/ecoles/' . (int) $school['id'] . '/offre')) ?>"
                      class="row g-2">
                    <?= csrf_field() ?>

                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="plan_code">Offre</label>
                        <select class="form-select" id="plan_code" name="plan_code" required>
                            <?php foreach ($plans as $plan): ?>
                                <option value="<?= e((string) $plan['code']) ?>"
                                    <?= $current !== null && $current['plan_code'] === $plan['code'] ? 'selected' : '' ?>>
                                    <?= e((string) $plan['name']) ?>
                                    — <?= $plan['max_students'] === null ? 'illimité' : (int) $plan['max_students'] ?> élèves
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="billing_cycle">Facturation</label>
                        <select class="form-select" id="billing_cycle" name="billing_cycle">
                            <option value="yearly">Annuelle</option>
                            <option value="monthly">Mensuelle</option>
                        </select>
                    </div>

                    <div class="col-6 col-md-4">
                        <label class="form-label small" for="starts_on">Départ</label>
                        <input type="date" class="form-control" id="starts_on" name="starts_on"
                               value="<?= e(date('Y-m-d')) ?>" required>
                    </div>

                    <div class="col-6 col-md-4">
                        <label class="form-label small" for="ends_on">Échéance</label>
                        <input type="date" class="form-control" id="ends_on" name="ends_on">
                        <div class="form-text small">Vide = un an (ou un mois).</div>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label small" for="max_students_override">Plafond négocié</label>
                        <input type="number" class="form-control" id="max_students_override"
                               name="max_students_override" min="1" placeholder="—">
                        <div class="form-text small">Vide = le plafond de l'offre.</div>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label small" for="price_amount">Tarif négocié</label>
                        <input type="number" step="0.01" min="0" class="form-control"
                               id="price_amount" name="price_amount" placeholder="—">
                        <div class="form-text small">
                            Vide = le tarif du catalogue. <strong>0 = gratuit</strong>
                            (école pilote, partenariat). Le tarif est <strong>figé</strong>
                            sur la période.
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label small" for="reason">Motif</label>
                        <input type="text" class="form-control" id="reason" name="reason"
                               placeholder="Ex. : dépassement constaté, accord commercial du 20/09">
                        <div class="form-text small">
                            Journalisé avec le changement. « Passée en Pro » ne dira pas
                            pourquoi dans six mois.
                        </div>
                    </div>

                    <div class="col-12 form-check ms-2 mt-2">
                        <input class="form-check-input" type="checkbox" value="1"
                               id="auto_renew" name="auto_renew">
                        <label class="form-check-label small" for="auto_renew">
                            Renouvellement automatique
                        </label>
                    </div>

                    <div class="col-12 mt-3">
                        <button class="btn btn-primary" type="submit">Appliquer l'offre</button>
                    </div>
                </form>

                <?php if ($current !== null): ?>
                    <div class="alert alert-info small mt-3 mb-0">
                        L'abonnement en cours sera <strong>clôturé</strong> et remplacé.
                        Il reste dans l'historique ci-dessous : aucune ligne n'est supprimée.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ==================== L'HISTORIQUE ==================== -->
<div class="card mt-3">
    <div class="card-header fw-medium">Historique des abonnements</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Offre</th>
                    <th scope="col">Statut</th>
                    <th scope="col">Du</th>
                    <th scope="col">Au</th>
                    <th scope="col" class="d-none d-md-table-cell">Facturation</th>
                    <th scope="col" class="text-end d-none d-md-table-cell">Plafond</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subscriptions as $sub): ?>
                    <tr>
                        <td><?= e((string) $sub['plan_name']) ?></td>
                        <td>
                            <span class="badge <?= e($statusClass[$sub['status']] ?? 'bg-secondary-subtle') ?>">
                                <?= e($statusLabels[$sub['status']] ?? (string) $sub['status']) ?>
                            </span>
                        </td>
                        <td class="small"><?= e(date('d/m/Y', strtotime((string) $sub['starts_on']))) ?></td>
                        <td class="small"><?= e(date('d/m/Y', strtotime((string) $sub['ends_on']))) ?></td>
                        <td class="small d-none d-md-table-cell">
                            <?= $sub['billing_cycle'] === 'monthly' ? 'Mensuelle' : 'Annuelle' ?>
                        </td>
                        <td class="text-end small d-none d-md-table-cell">
                            <?php if ($sub['max_students_override'] !== null): ?>
                                <?= (int) $sub['max_students_override'] ?> <span class="text-secondary">(négocié)</span>
                            <?php else: ?>
                                <?= $sub['max_students'] === null ? 'illimité' : (int) $sub['max_students'] ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($subscriptions === []): ?>
                    <tr>
                        <td colspan="6" class="text-center text-secondary py-4">
                            Aucun abonnement n'a jamais été enregistré pour cet établissement.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
