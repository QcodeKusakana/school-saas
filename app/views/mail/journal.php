<?php
/**
 * Le journal des envois.
 *
 * LE CORPS N'Y FIGURE PAS, et c'est délibéré : un journal sert à
 * savoir QUI a reçu QUOI et QUAND, pas à relire le contenu. Les
 * messages sensibles ont d'ailleurs perdu le leur en partant.
 *
 * « Envoyé » veut dire « remis au serveur d'expédition sans erreur ».
 * Pas « lu », pas même « distribué » — l'écran emploie ce mot-là et
 * ne promet rien d'autre.
 *
 * @var array $messages
 * @var int   $total
 * @var int   $pages
 * @var int   $page
 * @var array $filters
 * @var array $statuses
 * @var array $purposes
 * @var array $counts
 */
declare(strict_types=1);

set_title('Journal des envois');

$badge = [
    'queued'    => 'bg-info-subtle text-info-emphasis',
    'sent'      => 'bg-success-subtle text-success-emphasis',
    'failed'    => 'bg-danger-subtle text-danger-emphasis',
    'cancelled' => 'bg-secondary-subtle text-secondary-emphasis',
];

$hasFilter = $filters['status'] !== '' || $filters['purpose'] !== '' || $filters['q'] !== '';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Journal des envois</h1>
        <p class="page-subtitle"><?= (int) $total ?> message(s)</p>
    </div>
    <?php if (can('email.manage')): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/ecole/emails')) ?>">Configuration</a>
    <?php endif; ?>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?= e(url('/ecole/emails/journal')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label form-label-sm" for="q">Destinataire</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['q']) ?>" placeholder="Début de l'adresse…">
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm" for="etat">État</label>
                <select class="form-select form-select-sm" id="etat" name="etat">
                    <option value="">Tous</option>
                    <?php foreach ($statuses as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $filters['status'] === $code ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm" for="motif">Motif</label>
                <select class="form-select form-select-sm" id="motif" name="motif">
                    <option value="">Tous</option>
                    <?php foreach ($purposes as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $filters['purpose'] === $code ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary" type="submit">Filtrer</button>
                <?php if ($hasFilter): ?>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="<?= e(url('/ecole/emails/journal')) ?>">Effacer</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Destinataire</th>
                    <th scope="col" class="d-none d-md-table-cell">Objet</th>
                    <th scope="col">État</th>
                    <th scope="col" class="d-none d-lg-table-cell">Demandé le</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($messages as $m): ?>
                    <tr>
                        <td>
                            <div class="small"><?= e((string) $m['to_email']) ?></div>
                            <div class="small text-secondary">
                                <?= e($purposes[$m['purpose']] ?? (string) $m['purpose']) ?>
                            </div>
                        </td>

                        <td class="small d-none d-md-table-cell"><?= e((string) $m['subject']) ?></td>

                        <td>
                            <span class="badge <?= e($badge[$m['status']] ?? 'bg-secondary-subtle') ?>">
                                <?= e($statuses[$m['status']] ?? (string) $m['status']) ?>
                            </span>
                            <?php if ((int) $m['attempts'] > 1): ?>
                                <span class="small text-secondary">
                                    <?= (int) $m['attempts'] ?> tentatives
                                </span>
                            <?php endif; ?>
                            <?php if ($m['status'] === 'queued' && $m['next_attempt_at'] !== null): ?>
                                <div class="small text-secondary">
                                    rejeu le <?= e(date('d/m H:i', strtotime((string) $m['next_attempt_at']))) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($m['last_error'] !== null): ?>
                                <div class="small text-danger-emphasis"
                                     title="<?= e((string) $m['last_error']) ?>">
                                    <?= e(mb_strimwidth((string) $m['last_error'], 0, 70, '…')) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td class="small d-none d-lg-table-cell">
                            <?= e(date('d/m/Y H:i', strtotime((string) $m['created_at']))) ?>
                            <?php if ($m['sent_at'] !== null): ?>
                                <div class="text-secondary">
                                    parti à <?= e(date('H:i', strtotime((string) $m['sent_at']))) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td class="text-end">
                            <?php if ($m['status'] === 'failed' && can('email.manage')): ?>
                                <?php if ((int) $m['is_sensitive'] === 1): ?>
                                    <span class="small text-secondary"
                                          title="Le lien à usage unique a été effacé après l'échec.">
                                        non rejouable
                                    </span>
                                <?php else: ?>
                                    <form method="post" class="d-inline"
                                          action="<?= e(url('/ecole/emails/journal/' . (int) $m['id'] . '/rejouer')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            Rejouer
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($messages === []): ?>
                    <tr>
                        <td colspan="5" class="text-center text-secondary py-4">
                            <?= $hasFilter
                                ? 'Aucun message ne correspond à ces critères.'
                                : 'Aucun message envoyé pour le moment.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-secondary">Page <?= (int) $page ?> sur <?= (int) $pages ?></span>
            <div class="btn-group btn-group-sm">
                <?php if ($page > 1): ?>
                    <a class="btn btn-outline-secondary"
                       href="<?= e(url('/ecole/emails/journal?page=' . ($page - 1)
                            . '&etat=' . urlencode($filters['status'])
                            . '&motif=' . urlencode($filters['purpose'])
                            . '&q=' . urlencode($filters['q']))) ?>">Précédente</a>
                <?php endif; ?>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-outline-secondary"
                       href="<?= e(url('/ecole/emails/journal?page=' . ($page + 1)
                            . '&etat=' . urlencode($filters['status'])
                            . '&motif=' . urlencode($filters['purpose'])
                            . '&q=' . urlencode($filters['q']))) ?>">Suivante</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<p class="small text-secondary mt-3 mb-0">
    <strong>« Envoyé » veut dire « remis au serveur d'expédition sans erreur ».</strong>
    Ni lu, ni même distribué : un message accepté par le serveur peut encore
    être classé en indésirables chez le destinataire. Le contenu des messages
    n'est pas conservé.
</p>
