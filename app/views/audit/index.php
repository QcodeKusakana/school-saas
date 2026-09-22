<?php
/**
 * Le journal de l'établissement.
 *
 * CE QU'IL MONTRE, ET CE QU'IL NE MONTRE PAS
 * ===========================================
 * La liste reste volontairement sobre : qui, quoi, quand, sur quoi. Les
 * valeurs modifiées sont sur la fiche de détail, pas ici — elles
 * contiennent des données d'élèves, et une liste qu'on parcourt à
 * l'écran devant un guichet n'a pas à les étaler.
 *
 * Aucun bouton de suppression à l'unité : un journal qu'on peut élaguer
 * ligne à ligne ne prouve plus rien.
 *
 * @var array $entrees
 * @var int   $total
 * @var int   $pages
 * @var int   $page
 * @var array $filtres
 * @var array $actions
 * @var array $entites
 * @var array $auteurs
 * @var array $volume
 * @var int   $plancher
 */
declare(strict_types=1);

set_title('Journal');

$lien = static function (array $extra) use ($filtres, $page): string {
    return url('/journal', array_filter(array_merge([
        'action' => $filtres['action'],
        'entite' => $filtres['entity'],
        'auteur' => $filtres['user'],
        'du'     => $filtres['du'],
        'au'     => $filtres['au'],
        'page'   => $page,
    ], $extra), static fn ($v): bool => $v !== '' && $v !== 0 && $v !== null));
};

$nom = static function (array $e): string {
    if ($e['user_id'] === null) {
        return 'Système';
    }

    $complet = trim((string) $e['last_name'] . ' ' . (string) $e['first_name']);

    return $complet !== '' ? $complet : (string) $e['username'];
};

/**
 * L'auteur appartient-il à l'éditeur de la plateforme ?
 *
 * Un compte de plateforme porte `school_id IS NULL`. Depuis que la trace
 * prend son école dans le contexte, ses actions à l'intérieur de l'école
 * apparaissent ici — et l'école doit savoir que ce n'est pas son
 * personnel.
 */
$estEditeur = static fn (array $e): bool
    => $e['user_id'] !== null && $e['author_school_id'] === null;
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Journal</h1>
        <p class="page-subtitle">
            <?= number_format((int) $volume['total'], 0, ',', ' ') ?> entrée(s)
            <?php if ($volume['oldest'] !== null): ?>
                · depuis le <?= e(date('d/m/Y', strtotime((string) $volume['oldest']))) ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="alert alert-info small">
    <i class="bi bi-shield-check me-1"></i>
    Le journal enregistre les actions sensibles : connexions, notes,
    paiements, documents délivrés, comptes. <strong>Aucune entrée ne peut
    être supprimée à l'unité</strong> — seule une purge par ancienneté est
    possible, elle conserve au minimum <?= (int) $plancher ?> jours, et elle
    s'inscrit elle-même dans le journal. Les mots de passe et les clés ne
    sont jamais recopiés ici.
</div>

<?php
/*
 * Ce bandeau n'apparaît QUE si l'éditeur est réellement intervenu : une
 * mise en garde permanente finirait par ne plus être lue.
 */
$interventions = count(array_filter($entrees, $estEditeur));
?>
<?php if ($interventions > 0): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-person-badge me-1"></i>
        Cette page contient <strong><?= (int) $interventions ?> action(s) de
        l'éditeur de la plateforme</strong>, marquée(s) « Éditeur ». Elles ont
        été faites sur vos données par un compte qui n'appartient pas à votre
        établissement.
    </div>
<?php endif; ?>

<!-- Filtres -->
<form method="get" action="<?= e(url('/journal')) ?>" class="card mb-3">
    <div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-3">
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
            <label class="form-label small mb-1" for="f-entite">Objet</label>
            <select class="form-select form-select-sm" id="f-entite" name="entite">
                <option value="">Tous</option>
                <?php foreach ($entites as $t): ?>
                    <option value="<?= e($t) ?>" <?= $filtres['entity'] === $t ? 'selected' : '' ?>>
                        <?= e($t) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12 col-md-3">
            <label class="form-label small mb-1" for="f-auteur">Auteur</label>
            <select class="form-select form-select-sm" id="f-auteur" name="auteur">
                <option value="">Tous</option>
                <?php foreach ($auteurs as $u): ?>
                    <option value="<?= (int) $u['id'] ?>"
                        <?= (string) $filtres['user'] === (string) $u['id'] ? 'selected' : '' ?>>
                        <?= e(trim((string) $u['last_name'] . ' ' . (string) $u['first_name'])) ?>
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
            <a href="<?= e(url('/journal')) ?>" class="btn btn-sm btn-outline-secondary">
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
                    <th>Action</th>
                    <th>Objet</th>
                    <th>Auteur</th>
                    <th class="d-none d-lg-table-cell">Détail</th>
                    <th style="width:3rem;"></th>
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
                            <span class="badge bg-light text-dark border">
                                <?= e(audit_action_label((string) $entree['action'])) ?>
                            </span>
                        </td>
                        <td class="small text-muted">
                            <?= e((string) ($entree['entity_type'] ?? '—')) ?>
                            <?php if ($entree['entity_id'] !== null): ?>
                                <span class="text-body-tertiary">#<?= (int) $entree['entity_id'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?= e($nom($entree)) ?>
                            <?php if ($estEditeur($entree)): ?>
                                <span class="badge text-bg-dark ms-1"
                                      title="Action de l'éditeur de la plateforme, pas de votre personnel">Éditeur</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted d-none d-lg-table-cell">
                            <?= e((string) ($entree['description'] ?? '')) ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= e(url('/journal/' . (int) $entree['id'])) ?>"
                               class="btn btn-sm btn-outline-secondary py-0 px-1"
                               aria-label="Voir le détail de l'entrée <?= (int) $entree['id'] ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center small">
            <span class="text-muted">
                Page <?= (int) $page ?> sur <?= (int) $pages ?>
                · <?= number_format((int) $total, 0, ',', ' ') ?> entrée(s)
            </span>
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

<?php if (can('audit.purge')): ?>
    <div class="card mt-3 border-warning-subtle">
        <div class="card-body">
            <h2 class="h6 mb-2">Purge par ancienneté</h2>
            <p class="small text-muted mb-3">
                La purge supprime définitivement les entrées antérieures à
                l'ancienneté choisie. Elle ne descend jamais en dessous de
                <strong><?= (int) $plancher ?> jours</strong>, et elle s'inscrit
                elle-même dans le journal, avec le nombre de lignes supprimées.
                <strong>Cette opération est irréversible.</strong>
            </p>
            <form method="post" action="<?= e(url('/journal/purger')) ?>"
                  class="row g-2 align-items-end"
                  onsubmit="return confirm('Supprimer définitivement les entrées les plus anciennes du journal ? Cette action est irréversible.');">
                <?= csrf_field() ?>
                <div class="col-auto">
                    <label class="form-label small mb-1" for="purge-jours">Conserver</label>
                    <select class="form-select form-select-sm" id="purge-jours" name="jours">
                        <option value="365">365 jours (1 an)</option>
                        <option value="730" selected>730 jours (2 ans)</option>
                        <option value="1095">1095 jours (3 ans)</option>
                        <option value="1825">1825 jours (5 ans)</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash3 me-1"></i>Purger le reste
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
