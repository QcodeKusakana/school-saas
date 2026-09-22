<?php
/**
 * Le détail d'une entrée de journal.
 *
 * C'est ici — et seulement ici — que les valeurs modifiées s'affichent.
 * Elles peuvent contenir des données d'élèves ; les étaler dans la liste
 * qu'on parcourt devant un guichet n'aurait aucune raison d'être.
 *
 * Les secrets ne sont pas masqués à cet endroit : ils l'ont été à
 * l'écriture, dans `audit_encode()`. Masquer à l'affichage laisserait le
 * secret en base, où une sauvegarde ou une requête directe le lirait.
 *
 *   > On masque au moment où l'on écrit, pas au moment où l'on montre.
 *
 * @var array $entree
 */
declare(strict_types=1);

set_title('Entrée de journal');

$nom = static function (array $e): string {
    if ($e['user_id'] === null) {
        return 'Système';
    }

    $complet = trim((string) $e['last_name'] . ' ' . (string) $e['first_name']);

    $affiche = $complet !== ''
        ? $complet . ' (' . (string) $e['username'] . ')'
        : (string) $e['username'];

    // Un compte de plateforme porte `school_id IS NULL` : l'école doit
    // savoir que cette action n'est pas celle de son personnel.
    return $e['author_school_id'] === null
        ? $affiche . ' — éditeur de la plateforme'
        : $affiche;
};

/** Affiche une valeur de journal sans jamais la laisser interpréter. */
$valeur = static function (mixed $v): string {
    if (is_bool($v)) {
        return $v ? 'oui' : 'non';
    }

    if ($v === null) {
        return '—';
    }

    if (is_array($v)) {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    return (string) $v;
};

$cles = array_values(array_unique(array_merge(
    array_keys($entree['old']),
    array_keys($entree['new'])
)));
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(audit_action_label((string) $entree['action'])) ?></h1>
        <p class="page-subtitle">
            <?= e(date('d/m/Y à H:i:s', strtotime((string) $entree['created_at']))) ?>
        </p>
    </div>
    <div>
        <a href="<?= e(url('/journal')) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Retour au journal
        </a>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6 mb-3">Contexte</h2>
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">Auteur</dt>
                    <dd class="col-7"><?= e($nom($entree)) ?></dd>

                    <dt class="col-5 text-muted fw-normal">Action</dt>
                    <dd class="col-7"><code><?= e((string) $entree['action']) ?></code></dd>

                    <dt class="col-5 text-muted fw-normal">Objet</dt>
                    <dd class="col-7">
                        <?= e((string) ($entree['entity_type'] ?? '—')) ?>
                        <?php if ($entree['entity_id'] !== null): ?>
                            #<?= (int) $entree['entity_id'] ?>
                        <?php endif; ?>
                    </dd>

                    <?php if (($entree['description'] ?? '') !== ''): ?>
                        <dt class="col-5 text-muted fw-normal">Description</dt>
                        <dd class="col-7"><?= e((string) $entree['description']) ?></dd>
                    <?php endif; ?>

                    <dt class="col-5 text-muted fw-normal">Adresse IP</dt>
                    <dd class="col-7"><code><?= e(audit_ip_display($entree['ip_address'])) ?></code></dd>

                    <?php if (($entree['user_agent'] ?? '') !== ''): ?>
                        <dt class="col-5 text-muted fw-normal">Appareil</dt>
                        <dd class="col-7 text-break text-muted"
                            style="font-size:.78rem;"><?= e((string) $entree['user_agent']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6 mb-3">Valeurs</h2>

                <?php if ($cles === []): ?>
                    <p class="small text-muted mb-0">
                        Cette action ne modifie aucune valeur — c'est un
                        événement, pas une modification.
                    </p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm small mb-0">
                            <thead>
                                <tr>
                                    <th>Champ</th>
                                    <th>Avant</th>
                                    <th>Après</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cles as $cle): ?>
                                    <tr>
                                        <td class="text-muted"><?= e((string) $cle) ?></td>
                                        <td class="text-break">
                                            <?php if (array_key_exists($cle, $entree['old'])): ?>
                                                <?= nl2br(e($valeur($entree['old'][$cle]))) ?>
                                            <?php else: ?>
                                                <span class="text-body-tertiary">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-break fw-medium">
                                            <?php if (array_key_exists($cle, $entree['new'])): ?>
                                                <?= nl2br(e($valeur($entree['new'][$cle]))) ?>
                                            <?php else: ?>
                                                <span class="text-body-tertiary">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
