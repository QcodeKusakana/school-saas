<?php
/**
 * Bulletin d'un élève — document imprimable.
 *
 * Structure du bulletin officiel EPST : une ligne par branche, une
 * colonne par période, les totaux par semestre puis le total général.
 *
 * @var array $header
 * @var array $report     subjects, periods, totals, missing, absent, mode
 * @var array $published  bulletins figés, par regroupement
 * @var array $groups
 * @var float $threshold
 * @var array $decisions
 * @var bool  $canDecide
 */
declare(strict_types=1);

require_once APP_PATH . '/modules/grades/services.php';

$fullName = full_name($header['last_name'], $header['post_name'], $header['first_name']);
set_title('Bulletin — ' . $fullName);

$annual   = $published['ANNUAL'] ?? null;

// DERNIÈRE PÉRIODE DE CHAQUE REGROUPEMENT.
//
// La colonne de total s'insère juste après elle. Auparavant le gabarit
// écrivait « après EX1 » et « après EX2 » : au primaire, qui compte trois
// trimestres et se termine par EX3, la colonne du troisième total
// n'apparaissait tout simplement pas.
$closes = [];

foreach ($groups as $groupKey => $group) {
    if ($groupKey === 'ANNUAL' || $group['periods'] === []) {
        continue;
    }

    $closes[(string) end($group['periods'])] = $groupKey;
}
$subjects = $report['subjects'];
$periods  = $report['periods'];
$totals   = $report['totals'];

/** Affiche une cote, ou le motif de son absence. */
$cell = static function (?array $c): string {
    if ($c === null) {
        return '<span class="text-secondary">—</span>';
    }

    if ($c['is_absent']) {
        return '<span class="text-secondary" title="Absent">abs</span>';
    }

    if ($c['points'] === null) {
        return '<span class="text-secondary">—</span>';
    }

    return e(grades_format($c['points']));
};
?>

<!-- ------------------------------------------------------------------
     En-tête officiel
------------------------------------------------------------------- -->
<div class="text-center mb-3">
    <div class="small text-uppercase text-secondary">République Démocratique du Congo</div>
    <div class="fw-semibold"><?= e($header['school_name']) ?></div>
    <div class="small text-secondary">
        <?php if ($header['province'] !== null): ?><?= e($header['province']) ?><?php endif; ?>
        <?php if ($header['city'] !== null): ?> · <?= e($header['city']) ?><?php endif; ?>
        <?php if ($header['commune'] !== null): ?> · <?= e($header['commune']) ?><?php endif; ?>
    </div>
    <h1 class="h5 mt-3 mb-0">BULLETIN SCOLAIRE</h1>
    <div class="small text-secondary">Année scolaire <?= e($header['year_code']) ?></div>
</div>

<div class="row g-2 small mb-3 avoid-break">
    <div class="col-7">
        <div><span class="text-secondary">Élève :</span> <strong><?= e($fullName) ?></strong></div>
        <div><span class="text-secondary">Matricule :</span> <?= e($header['matricule']) ?></div>
        <div>
            <span class="text-secondary">Né(e) le :</span>
            <?= $header['birth_date'] !== null ? e($header['birth_date']) : '—' ?>
            <?php if ($header['birth_place'] !== null): ?> à <?= e($header['birth_place']) ?><?php endif; ?>
        </div>
    </div>
    <div class="col-5">
        <div><span class="text-secondary">Classe :</span> <strong><?= e($header['classroom_name']) ?></strong></div>
        <div><span class="text-secondary">Niveau :</span> <?= e($header['level_name']) ?></div>
        <?php if ($header['option_name'] !== null): ?>
            <div><span class="text-secondary">Option :</span> <?= e($header['option_name']) ?></div>
        <?php endif; ?>
        <?php if ($header['teacher_last_name'] !== null): ?>
            <div>
                <span class="text-secondary">Titulaire :</span>
                <?= e(full_name($header['teacher_last_name'], $header['teacher_post_name'], $header['teacher_first_name'])) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($subjects === []): ?>
    <div class="alert alert-info">
        Aucune branche au programme de cette classe : le bulletin ne peut pas être établi.
    </div>
<?php else: ?>

    <?php
    // On signale le manque du SEMESTRE EN COURS, pas celui de l'année :
    // en septembre, les périodes du second semestre ne sont pas
    // « manquantes », elles n'ont pas encore eu lieu. Un avertissement vrai
    // pour tout le monde toute l'année n'est plus un avertissement.
    $currentGap = 0;

    foreach (array_keys($groups) as $half) {
        if ($half === 'ANNUAL') {
            continue;
        }

        if (($totals[$half]['max'] ?? 0) > 0) {
            $currentGap = (int) ($totals[$half]['missing'] ?? 0);
        }
    }
    ?>

    <?php if ($currentGap > 0): ?>
        <div class="alert alert-warning py-2 small avoid-break">
            <strong><?= $currentGap ?></strong> cote(s) non encore saisie(s) sur le semestre en cours.
            Les totaux ci-dessous ne portent que sur les cotes disponibles.
        </div>
    <?php endif; ?>

    <!-- --------------------------------------------------------------
         Tableau des branches — gabarit propre à la famille de bulletin
    --------------------------------------------------------------- -->
    <?php
    // Les mises en page officielles ne se ramènent pas à une seule. Deux
    // familles s'opposent réellement : par DOMAINES (primaire, CTEB,
    // humanités générales) et par BLOCS DE MAXIMA (humanités techniques).
    // Chacune a son fichier ; ce qui les entoure — en-tête, synthèse,
    // décision, signatures — leur est commun et reste ici.
    $modelFile = APP_PATH . '/views/bulletins/models/' . $model . '.php';

    require is_file($modelFile)
        ? $modelFile
        : APP_PATH . '/views/bulletins/models/domaines.php';
    ?>

    <!-- --------------------------------------------------------------
         Synthèse
    --------------------------------------------------------------- -->
    <div class="row g-2 mb-3 avoid-break">
        <?php foreach (array_keys($groups) as $key): ?>
            <?php
            $t   = $totals[$key];
            $pub = $published[$key] ?? null;

            // UN DOCUMENT PUBLIÉ S'AFFICHE TEL QU'IL A ÉTÉ PUBLIÉ.
            //
            // Le rang venait déjà du figé ; le pourcentage, lui, était
            // recalculé. Une cote ajoutée après la remise des bulletins
            // faisait donc apparaître un rang figé à côté d'un
            // pourcentage à jour — deux chiffres incohérents sur le même
            // document, et un bulletin réimprimé qui ne correspondait plus
            // à celui reçu par la famille.
            $frozen = $pub !== null;
            $pct    = $frozen ? (float) $pub['percentage']   : $t['percentage'];
            $points = $frozen ? (float) $pub['total_points'] : $t['points'];
            $max    = $frozen ? (float) $pub['max_points']   : $t['max'];
            $pass   = $pct !== null && $pct >= $threshold;
            ?>
            <div class="col-4">
                <div class="border rounded p-2 h-100 <?= $key === 'ANNUAL' ? 'border-dark' : '' ?>">
                    <div class="small text-secondary"><?= e($t['label']) ?></div>
                    <div class="fs-6 fw-semibold">
                        <?= $pct !== null ? e(number_format($pct, 1, ',', ' ')) . ' %' : '—' ?>
                    </div>
                    <div class="small text-secondary">
                        <?= e(grades_format($points)) ?> / <?= e(grades_format($max)) ?>
                    </div>
                    <?php if ($pub !== null && $pub['class_rank'] !== null): ?>
                        <div class="small">
                            Rang <strong><?= (int) $pub['class_rank'] ?></strong>
                            sur <?= (int) $pub['class_size'] ?>
                        </div>
                    <?php endif; ?>
                    <?php
                    $gap = $frozen ? (int) $pub['missing_grades'] : (int) ($t['missing'] ?? 0);
                    ?>
                    <?php if ($gap > 0): ?>
                        <div class="small text-warning-emphasis">
                            <?= $gap ?> cote(s) manquante(s)
                        </div>
                    <?php endif; ?>
                    <?php if ($frozen): ?>
                        <div class="small text-secondary">
                            <i class="bi bi-lock-fill"></i>
                            Publié le <?= e(substr((string) $pub['published_at'], 0, 10)) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($pct !== null): ?>
                        <span class="badge <?= $pass ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' ?>">
                            <?= $pass ? 'Seuil atteint' : 'Sous le seuil' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="small text-secondary mb-3">
        Seuil de réussite : <strong><?= e(number_format($threshold, 0, ',', ' ')) ?> %</strong>.
        <?php if ($report['absent'] > 0): ?>
            <?= (int) $report['absent'] ?> absence(s) —
            <?= $report['mode'] === 'zero'
                ? 'comptées comme zéro sur le maximum plein.'
                : 'exclues du total et du maximum.' ?>
        <?php endif; ?>
        <?php if (array_filter($subjects, static fn (array $s): bool => !$s['ranking']) !== []): ?>
            Les branches marquées <strong>*</strong> ne comptent pas dans le classement.
        <?php endif; ?>
    </div>

    <!-- --------------------------------------------------------------
         Décision
    --------------------------------------------------------------- -->
    <div class="border rounded p-2 avoid-break">
        <div class="row g-2 align-items-center">
            <div class="col-6">
                <span class="text-secondary small">Décision du conseil de classe :</span>
                <strong>
                    <?= $annual !== null && $annual['decision'] !== null
                        ? e($decisions[$annual['decision']] ?? $annual['decision'])
                        : 'Non prononcée' ?>
                </strong>
            </div>
            <div class="col-6 text-end small text-secondary">
                <?php if ($annual !== null): ?>
                    Bulletin annuel publié le <?= e(substr((string) $annual['published_at'], 0, 10)) ?>
                <?php else: ?>
                    <?php
                    // Formulation précise : un semestre peut être publié
                    // alors que l'annuel ne l'est pas. Écrire « bulletin
                    // non publié » au bas d'une page affichant déjà un rang
                    // figé se contredirait.
                    ?>
                    Bulletin annuel non publié — total général provisoire
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-3 small avoid-break">
        <div class="col-6">
            <div class="text-secondary">Le titulaire de classe</div>
            <div style="height:2.5rem"></div>
            <div class="border-top pt-1">
                <?= $header['teacher_last_name'] !== null
                    ? e(full_name($header['teacher_last_name'], $header['teacher_post_name'], $header['teacher_first_name']))
                    : '' ?>
            </div>
        </div>
        <div class="col-6">
            <div class="text-secondary">Le chef d'établissement</div>
            <div style="height:2.5rem"></div>
            <div class="border-top pt-1"><?= e((string) $header['director_name']) ?></div>
        </div>
    </div>

    <?php if ($canDecide && $annual !== null): ?>
        <div class="no-print mt-4 pt-3 border-top">
            <form method="post" action="<?= e(url('/bulletins/' . (int) $header['enrollment_id'] . '/decision')) ?>"
                  class="d-flex flex-wrap gap-2 align-items-end">
                <?= csrf_field() ?>
                <div>
                    <label for="decision" class="form-label form-label-sm mb-1">Décision du conseil</label>
                    <select id="decision" name="decision" class="form-select form-select-sm">
                        <?php foreach ($decisions as $code => $label): ?>
                            <option value="<?= e($code) ?>"
                                <?= ($annual['decision'] ?? '') === $code ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-primary">Enregistrer</button>
                <span class="small text-secondary">
                    Le logiciel calcule, il ne délibère pas : la décision reste celle du conseil.
                </span>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>
