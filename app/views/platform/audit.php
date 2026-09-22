<?php
/**
 * Le journal global — éditeur.
 *
 * CE QU'IL MONTRE DE PLUS QUE LE JOURNAL D'UNE ÉCOLE
 * ===================================================
 * La colonne établissement, et les lignes SANS établissement : les
 * actions de l'éditeur lui-même — entrée dans une école cliente,
 * changement d'offre, encaissement d'abonnement — s'écrivent avec
 * `school_id = NULL` et n'apparaissent dans aucun journal d'école.
 *
 * C'est précisément ce qui rend cet écran nécessaire : sans lui,
 * personne ne relit ce que fait l'éditeur.
 *
 * IL N'AFFICHE PAS LES VALEURS MODIFIÉES, et c'est délibéré. Elles
 * concernent des élèves mineurs d'écoles clientes. L'éditeur voit QUE
 * quelque chose a été fait, par qui et quand ; pour savoir quoi, il
 * entre dans l'école — et cette entrée est elle-même journalisée.
 *
 * @var array $entrees
 * @var int   $total
 * @var int   $pages
 * @var int   $page
 * @var array $filtres
 * @var array $ecoles
 * @var array $actions
 */
declare(strict_types=1);

set_title('Journal global');

$lien = static function (array $extra) use ($filtres, $page): string {
    return url('/plateforme/journal', array_filter(array_merge([
        'ecole'  => $filtres['school'],
        'action' => $filtres['action'],
        'entite' => $filtres['entity'],
        'du'     => $filtres['du'],
        'au'     => $filtres['au'],
        'page'   => $page,
    ], $extra), static fn ($v): bool => $v !== '' && $v !== 0 && $v !== null));
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Journal global</h1>
        <p class="page-subtitle">
            <?= number_format((int) $total, 0, ',', ' ') ?> entrée(s) sur tout le parc
        </p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="alert alert-secondary small">
    <i class="bi bi-info-circle me-1"></i>
    Les lignes <strong>sans établissement</strong> sont les actions de
    l'éditeur : entrée dans une école cliente, changement d'offre,
    encaissement. Elles n'apparaissent dans aucun journal d'école.
    Les valeurs modifiées ne sont pas affichées ici — elles concernent des
    élèves d'établissements clients.
</div>

<form method="get" action="<?= e(url('/plateforme/journal')) ?>" class="card mb-3">
    <div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1" for="f-ecole">Établissement</label>
            <select class="form-select form-select-sm" id="f-ecole" name="ecole">
                <option value="">Tous</option>
                <?php foreach ($ecoles as $ec): ?>
                    <option value="<?= (int) $ec['id'] ?>"
                        <?= (string) $filtres['school'] === (string) $ec['id'] ? 'selected' : '' ?>>
                        <?= e((string) $ec['name']) ?> (<?= e((string) $ec['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12 col-md-3">
            <label class="form-label small mb-1" for="f-action">Action</label>
            <select class="form-select form-select-sm" id="f-action" name="action">
                <option value="">Toutes</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>" <?= $filtres['action'] === $a ? 'selected' : '' ?>>
                        <?= e(audit_action_label($a)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="f-du">Du</label>
            <input type="date" class="form-control form-control-sm" id="f-du"
                   name="du" value="<?= e($filtres['du']) ?>">
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="f-au">Au</label>
            <input type="date" class="form-control form-control-sm" id="f-au"
                   name="au" value="<?= e($filtres['au']) ?>">
        </div>

        <div class="col-12 d-flex gap-2 pt-1">
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-funnel me-1"></i>Filtrer
            </button>
            <a href="<?= e(url('/plateforme/journal')) ?>" class="btn btn-sm btn-outline-secondary">
                Tout afficher
            </a>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:9.5rem;">Quand</th>
                    <th>Établissement</th>
                    <th>Action</th>
                    <th>Objet</th>
                    <th>Auteur</th>
                    <th class="d-none d-lg-table-cell">Détail</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($entrees === []): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            Aucune entrée ne correspond à ces critères.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($entrees as $entree): ?>
                    <tr>
                        <td class="small text-nowrap">
                            <?= e(date('d/m/Y H:i', strtotime((string) $entree['created_at']))) ?>
                        </td>
                        <td class="small">
                            <?php if ($entree['school_id'] === null): ?>
                                <span class="badge text-bg-dark">Éditeur</span>
                            <?php else: ?>
                                <?= e((string) ($entree['school_name'] ?? ('#' . (int) $entree['school_id']))) ?>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <span class="badge bg-light text-dark border">
                                <?= e(audit_action_label((string) $entree['action'])) ?>
                            </span>
                        </td>
                        <td class="small text-muted">
                            <?= e((string) ($entree['entity_type'] ?? '—')) ?>
                        </td>
                        <td class="small">
                            <?php
                            $complet = trim((string) $entree['last_name'] . ' ' . (string) $entree['first_name']);
                            echo e($entree['user_id'] === null
                                ? 'Système'
                                : ($complet !== '' ? $complet : (string) $entree['username']));
                            ?>
                        </td>
                        <td class="small text-muted d-none d-lg-table-cell">
                            <?= e((string) ($entree['description'] ?? '')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center small">
            <span class="text-muted">Page <?= (int) $page ?> sur <?= (int) $pages ?></span>
            <div class="btn-group btn-group-sm">
                <?php if ($page > 1): ?>
                    <a class="btn btn-outline-secondary" href="<?= e($lien(['page' => $page - 1])) ?>">Précédent</a>
                <?php endif; ?>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-outline-secondary" href="<?= e($lien(['page' => $page + 1])) ?>">Suivant</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
