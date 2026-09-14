<?php
/**
 * Paramètres de calcul des bulletins.
 *
 * Deux réglages, et les deux changent les résultats. Ils sont donc
 * expliqués, pas seulement proposés.
 *
 * @var string $mode
 * @var float  $threshold
 */
declare(strict_types=1);

set_title('Paramètres des bulletins');
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Paramètres des bulletins</h1>
        <p class="page-subtitle">
            Ces deux réglages modifient les résultats affichés. Changez-les
            de préférence avant la première publication de l'année.
        </p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<form method="post" action="<?= e(url('/bulletins/parametres')) ?>">
    <?= csrf_field() ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Traitement des absences</strong></div>
        <div class="card-body">
            <p class="small text-secondary">
                Un élève absent à une interrogation n'a pas échoué : il n'a pas
                composé. Ce que l'établissement en fait relève de sa règle, pas
                du logiciel. Aucune des deux options n'est universelle en RDC.
            </p>

            <div class="form-check mb-3">
                <input class="form-check-input" type="radio" name="absence_mode"
                       id="mode_excluded" value="excluded" <?= $mode === 'excluded' ? 'checked' : '' ?>>
                <label class="form-check-label" for="mode_excluded">
                    <strong>Exclure du total</strong> <span class="badge bg-light text-secondary">recommandé</span>
                    <div class="small text-secondary">
                        La période absente sort du total <em>et</em> du maximum.
                        La moyenne reste juste, mais le dénominateur diffère d'un
                        élève à l'autre — le bulletin l'affiche explicitement.
                    </div>
                </label>
            </div>

            <div class="form-check">
                <input class="form-check-input" type="radio" name="absence_mode"
                       id="mode_zero" value="zero" <?= $mode === 'zero' ? 'checked' : '' ?>>
                <label class="form-check-label" for="mode_zero">
                    <strong>Compter comme zéro</strong>
                    <div class="small text-secondary">
                        L'absence vaut zéro sur le maximum plein. Les totaux
                        restent comparables entre élèves, mais un élève malade
                        une semaine est traité comme s'il avait échoué.
                    </div>
                </label>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Seuil de réussite</strong></div>
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-3">
                    <label for="passing_threshold" class="form-label">Pourcentage</label>
                    <div class="input-group">
                        <input type="number" id="passing_threshold" name="passing_threshold"
                               class="form-control" step="1" min="1" max="99"
                               value="<?= e(number_format($threshold, 0, '.', '')) ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <div class="col-12 col-md-9">
                    <p class="small text-secondary mb-0">
                        La règle nationale est 50 %. Elle est enregistrée comme un
                        paramètre et non écrite dans le code : une école
                        conventionnée peut retenir un autre seuil, et une
                        évolution réglementaire ne doit pas imposer une nouvelle
                        version du logiciel.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i> Enregistrer
    </button>
</form>
