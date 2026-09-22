<?php
/**
 * Délivrer un document pour une inscription.
 *
 * POURQUOI LES REFUS SONT AFFICHÉS AVANT L'ESSAI
 * ===============================================
 * Chaque nature a ses conditions : la carte exige une photo,
 * l'attestation de paiement exige un solde nul. Laisser le secrétariat
 * cliquer puis échouer lui ferait perdre du temps devant un parent qui
 * attend au guichet. Le motif du refus est donc calculé en amont et
 * écrit sous la case, avec ce qu'il faut faire pour le lever.
 *
 * LES DOCUMENTS DÉJÀ DÉLIVRÉS sont rappelés, sans être interdits : un
 * parent perd un papier, et l'école doit pouvoir le refaire. On le
 * signale pour éviter le doublon involontaire, pas pour l'empêcher.
 *
 * @var array $contexte
 * @var array $types
 * @var array $existants
 * @var array $refus
 */
declare(strict_types=1);

set_title('Délivrer un document');

$nom = trim(
    mb_strtoupper((string) $contexte['last_name'])
    . ' ' . (string) ($contexte['post_name'] ?? '')
    . ' ' . (string) $contexte['first_name']
);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Délivrer un document</h1>
        <p class="page-subtitle">
            <?= e($nom) ?> · matricule <?= e((string) $contexte['matricule']) ?>
            · <?= e((string) ($contexte['classroom_name'] ?? 'sans classe')) ?>
            · <?= e((string) $contexte['year_code']) ?>
        </p>
    </div>

    <a href="<?= e(url('/documents')) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Les documents
    </a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($existants !== []): ?>
    <div class="alert alert-info">
        <div class="fw-medium mb-1">Déjà délivré pour cette inscription</div>
        <ul class="mb-0 small">
            <?php foreach ($existants as $ex): ?>
                <li>
                    <a href="<?= e(url('/documents/' . (int) $ex['id'] . '/imprimer')) ?>">
                        <?= e($types[(string) $ex['type']] ?? (string) $ex['type']) ?>
                        n° <?= e((string) $ex['number']) ?>
                    </a>
                    le <?= e(date('d/m/Y', strtotime((string) $ex['issued_at']))) ?>
                    <?php if ($ex['revoked_at'] !== null): ?>
                        <span class="badge text-bg-danger">révoqué</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="small text-secondary mt-2 mb-0">
            Un duplicata reste possible — un papier se perd. Il portera son
            propre numéro, et l'ancien restera valable.
        </p>
    </div>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($types as $code => $libelle): ?>
        <?php $bloque = $refus[$code] ?? null; ?>
        <div class="col-md-6">
            <div class="card h-100 <?= $bloque !== null ? 'border-secondary-subtle' : '' ?>">
                <div class="card-body d-flex flex-column">

                    <h2 class="h6 mb-2"><?= e($libelle) ?></h2>

                    <p class="small text-secondary flex-grow-1">
                        <?php
                        echo e(match ($code) {
                            'attestation_frequentation'
                                => 'Atteste que l\'élève est inscrit et fréquente l\'établissement '
                                 . 'cette année. Le document le plus demandé.',
                            'certificat_scolarite'
                                => 'Atteste le parcours accompli : classe suivie, année, et la '
                                 . 'décision de fin d\'année si elle est prise.',
                            'carte_eleve'
                                => 'Format portefeuille, avec photo et matricule. Valable '
                                 . 'jusqu\'à la fin de l\'année scolaire.',
                            'attestation_paiement'
                                => 'Atteste que la famille est en règle des frais exigibles '
                                 . 'à la date de délivrance.',
                            default => '',
                        });
                        ?>
                    </p>

                    <?php
                    /*
                     * L'APERÇU RESTE OFFERT MÊME QUAND LA DÉLIVRANCE EST
                     * BLOQUÉE.
                     *
                     * C'est le cas qui compte : une carte refusée faute
                     * de photo est justement celle qu'on veut voir avant
                     * d'aller chercher un appareil. Le spécimen montre la
                     * mise en page avec un emplacement photo vide.
                     *
                     * Il ne consomme aucun numéro et ne laisse aucune
                     * trace — voir ctrl_documents_preview().
                     */
                    $lienApercu = url('/documents/apercu/' . (int) $contexte['enrollment_id'],
                        ['type' => $code]);
                    ?>

                    <?php if ($bloque !== null): ?>
                        <div class="alert alert-warning small mb-2 py-2">
                            <?= e($bloque) ?>
                            <?php if ($code === 'carte_eleve' && can('student.edit')): ?>
                                <a href="<?= e(url('/eleves/' . (int) $contexte['student_id'])) ?>#bloc-photo"
                                   class="d-block mt-1 fw-medium">
                                    Ouvrir le dossier pour ajouter la photo →
                                </a>
                            <?php endif; ?>
                        </div>
                        <a class="btn btn-outline-secondary w-100" href="<?= e($lienApercu) ?>">
                            <i class="bi bi-eye me-1"></i> Voir l'aperçu
                        </a>
                    <?php else: ?>
                        <form method="post" class="mb-2"
                              action="<?= e(url('/documents/delivrer/' . (int) $contexte['enrollment_id'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="type" value="<?= e($code) ?>">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-file-earmark-check me-1"></i> Délivrer et imprimer
                            </button>
                        </form>
                        <a class="btn btn-outline-secondary btn-sm w-100" href="<?= e($lienApercu) ?>">
                            <i class="bi bi-eye me-1"></i> Aperçu sans délivrer
                        </a>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
