<?php
/**
 * Le registre des documents délivrés.
 *
 * IL MONTRE LES RÉVOQUÉS PAR DÉFAUT, et ce n'est pas un oubli. Quand
 * une famille se présente au guichet avec un papier annulé, le
 * secrétariat doit le retrouver et pouvoir dire pourquoi il ne vaut
 * plus. Un registre qui masque ce qu'il a annulé laisse l'agent sans
 * réponse.
 *
 * @var array $filtres
 * @var array $rows
 * @var int   $total
 * @var int   $page
 * @var int   $perPage
 * @var array $counts
 * @var array $types
 */
declare(strict_types=1);

set_title('Documents');

$pages = (int) max(1, (int) ceil($total / $perPage));

$lien = static function (array $extra) use ($filtres, $page): string {
    return url('/documents', array_filter(array_merge([
        'type'   => $filtres['type'],
        'statut' => $filtres['statut'],
        'q'      => $filtres['q'],
        'page'   => $page,
    ], $extra), static fn ($v): bool => $v !== '' && $v !== 0));
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Documents</h1>
        <p class="page-subtitle">
            <?= (int) $counts['total'] ?> document(s) délivré(s)
            <?php if ((int) $counts['revoques'] > 0): ?>
                · <span class="text-danger"><?= (int) $counts['revoques'] ?> révoqué(s)</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="alert alert-info small">
    <i class="bi bi-qr-code me-1"></i>
    Chaque document porte un code de vérification. Toute personne peut
    contrôler son authenticité sur
    <strong><?= e(rtrim((string) config('app.url'), '/') . '/verifier') ?></strong>,
    sans compte. La page ne publie aucun nom d'élève.
</div>

<!-- Filtres -->
<form method="get" action="<?= e(url('/documents')) ?>" class="card mb-3">
    <div class="card-body row g-2 align-items-end">

        <div class="col-md-4">
            <label class="form-label small" for="q">Rechercher</label>
            <input type="search" class="form-control" id="q" name="q"
                   value="<?= e((string) $filtres['q']) ?>"
                   placeholder="numéro, matricule ou nom">
        </div>

        <div class="col-md-4">
            <label class="form-label small" for="type">Nature</label>
            <select class="form-select" id="type" name="type">
                <option value="">Toutes</option>
                <?php foreach ($types as $code => $libelle): ?>
                    <option value="<?= e($code) ?>"
                        <?= $filtres['type'] === $code ? 'selected' : '' ?>>
                        <?= e($libelle) ?> (<?= (int) ($counts[$code] ?? 0) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label small" for="statut">État</label>
            <select class="form-select" id="statut" name="statut">
                <option value="">Tous</option>
                <option value="valides" <?= $filtres['statut'] === 'valides' ? 'selected' : '' ?>>
                    Valides
                </option>
                <option value="revoques" <?= $filtres['statut'] === 'revoques' ? 'selected' : '' ?>>
                    Révoqués
                </option>
            </select>
        </div>

        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-primary w-100">Filtrer</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Numéro</th>
                    <th>Nature</th>
                    <th>Élève</th>
                    <th>Délivré</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="5" class="text-secondary text-center py-4">
                            Aucun document ne correspond.
                            Délivrez-en un depuis le dossier d'un élève.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $r): ?>
                    <tr<?= $r['revoked_at'] !== null ? ' class="table-danger"' : '' ?>>
                        <td class="font-monospace small"><?= e((string) $r['number']) ?></td>
                        <td><?= e($types[(string) $r['type']] ?? (string) $r['type']) ?></td>
                        <td>
                            <?= e(trim(mb_strtoupper((string) $r['last_name'])
                                . ' ' . (string) ($r['post_name'] ?? '')
                                . ' ' . (string) $r['first_name'])) ?>
                            <div class="small text-secondary"><?= e((string) $r['matricule']) ?></div>
                        </td>
                        <td class="small text-secondary">
                            <?= e(date('d/m/Y', strtotime((string) $r['issued_at']))) ?>
                            <?php if (($r['issuer_last'] ?? '') !== ''): ?>
                                <div>par <?= e((string) $r['issuer_last']) ?></div>
                            <?php endif; ?>
                            <?php if ($r['revoked_at'] !== null): ?>
                                <div class="text-danger">
                                    révoqué : <?= e((string) ($r['revoke_reason'] ?? '')) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e(url('/documents/' . (int) $r['id'] . '/imprimer')) ?>">
                                <i class="bi bi-printer"></i>
                            </a>

                            <?php if ($r['revoked_at'] === null && can('document.revoke')): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#rev<?= (int) $r['id'] ?>">
                                    Révoquer
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($r['revoked_at'] === null && can('document.revoke')): ?>
                        <tr class="collapse" id="rev<?= (int) $r['id'] ?>">
                            <td colspan="5" class="bg-light">
                                <form method="post" class="row g-2 align-items-end"
                                      action="<?= e(url('/documents/' . (int) $r['id'] . '/revoquer')) ?>">
                                    <?= csrf_field() ?>
                                    <div class="col-md-9">
                                        <label class="form-label small"
                                               for="motif<?= (int) $r['id'] ?>">
                                            Motif de la révocation — il sera conservé et
                                            servira à répondre à qui présentera ce document
                                        </label>
                                        <input type="text" class="form-control form-control-sm"
                                               id="motif<?= (int) $r['id'] ?>" name="reason"
                                               required minlength="5"
                                               placeholder="ex. : inscription annulée après délivrance">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-sm btn-danger w-100">
                                            Retirer sa valeur
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center mb-0">
            <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e($lien(['page' => $p])) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
