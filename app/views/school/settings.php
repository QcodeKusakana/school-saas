<?php
/**
 * Mon établissement — ce qui s'imprime sur les documents.
 *
 * L'écran est court à dessein : il ne gouverne que ce qui apparaît sur
 * le papier. Les paramètres pédagogiques vivent dans le référentiel,
 * les comptes dans Utilisateurs, l'abonnement chez l'éditeur.
 *
 * @var array|null $ecole
 * @var array      $reglages
 * @var array      $champs
 */
declare(strict_types=1);

set_title('Mon établissement');
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Mon établissement</h1>
        <p class="page-subtitle">
            Ce que portent vos attestations, certificats et cartes d'élève.
        </p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="row g-4">

    <!-- ============================================================
         LE LOGO
         ============================================================ -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-medium">Logo</div>
            <div class="card-body">

                <div class="text-center mb-3">
                    <?php if (($ecole['logo_path'] ?? null) !== null): ?>
                        <img src="<?= e(url('/ecole/logo')) ?>" alt="Logo de l'établissement"
                             style="max-width:160px;max-height:160px;object-fit:contain;">
                    <?php else: ?>
                        <div style="height:120px;background:#f1f5f9;border:1px dashed #cbd5e1;
                                    border-radius:.25rem;display:flex;align-items:center;
                                    justify-content:center;">
                            <i class="bi bi-image" style="font-size:2rem;color:#94a3b8;"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <form method="post" enctype="multipart/form-data"
                      action="<?= e(url('/ecole/logo')) ?>">
                    <?= csrf_field() ?>
                    <div class="input-group input-group-sm">
                        <input type="file" class="form-control" name="logo" id="logo"
                               accept="image/jpeg,image/png,image/webp" required>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                    <div class="form-text">
                        PNG de préférence, sur fond transparent ou blanc. 5 Mo au maximum.
                        Un logo carré ou légèrement plus large s'imprime le mieux sur une
                        carte d'élève.
                    </div>
                </form>

                <?php if (($ecole['logo_path'] ?? null) !== null): ?>
                    <form method="post" class="mt-2"
                          data-confirm="Retirer le logo ? Les documents déjà délivrés ne changent pas : ils portent le logo qui existait au moment de leur délivrance."
                          action="<?= e(url('/ecole/logo/retirer')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-link text-danger p-0">
                            Retirer le logo
                        </button>
                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- ============================================================
         LES MENTIONS IMPRIMÉES
         ============================================================ -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header fw-medium">Mentions imprimées</div>
            <div class="card-body">

                <div class="alert alert-info small">
                    <i class="bi bi-info-circle me-1"></i>
                    Ces informations apparaissent en tête et au bas des documents
                    officiels. Sans ville renseignée, un certificat imprime
                    « Fait à&nbsp;, » — une mention vide au bas d'une pièce signée.
                </div>

                <form method="post" action="<?= e(url('/ecole/parametres')) ?>">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label small fw-medium">Nom de l'établissement</label>
                        <input type="text" class="form-control" disabled
                               value="<?= e((string) ($ecole['name'] ?? '')) ?>">
                        <div class="form-text">
                            Il identifie votre école dans toute la plateforme et figure sur
                            les documents déjà délivrés. Sa modification relève de l'éditeur.
                        </div>
                    </div>

                    <?php foreach ($champs as $cle => [$libelle, $max, $aide]): ?>
                        <?php $nom = str_replace('.', '_', $cle); ?>
                        <div class="mb-3">
                            <label class="form-label small fw-medium" for="<?= e($nom) ?>">
                                <?= e($libelle) ?>
                            </label>
                            <input type="<?= $cle === 'school.email' ? 'email' : 'text' ?>"
                                   class="form-control" id="<?= e($nom) ?>" name="<?= e($nom) ?>"
                                   maxlength="<?= (int) $max ?>"
                                   value="<?= e($reglages[$cle] ?? '') ?>">
                            <?php if ($aide !== ''): ?>
                                <div class="form-text"><?= $aide ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </form>

            </div>
        </div>
    </div>
</div>

<p class="small text-secondary mt-3">
    <strong>Les documents déjà délivrés ne changent pas.</strong> Chacun porte une
    copie figée de ces informations au moment de sa délivrance : une école qui
    déménage ne réécrit pas les attestations qu'elle a signées à son ancienne
    adresse.
</p>
