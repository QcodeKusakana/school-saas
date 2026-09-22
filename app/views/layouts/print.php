<?php
/**
 * Layout des documents imprimables — bulletins, attestations.
 *
 * Pourquoi un layout distinct
 * ---------------------------
 * Un bulletin est remis sur papier. La barre latérale, la barre
 * supérieure et le pied de page n'ont rien à y faire : ils consomment la
 * moitié de la feuille et brouillent un document officiel.
 *
 * Pourquoi pas un PDF
 * -------------------
 * Produire un PDF exigerait une bibliothèque externe, donc Composer, sur
 * un hébergement mutualisé où il n'est pas toujours disponible — et pour
 * un résultat que le navigateur sait déjà faire. « Imprimer → Enregistrer
 * au format PDF » donne le même fichier, sans dépendance à maintenir.
 *
 * Le jour où une génération côté serveur sera nécessaire (envoi par mail
 * en lot, archivage automatique), ce sera une décision à prendre pour
 * elle-même, pas un effet de bord.
 *
 * DEUX RÉGLAGES, POUR NE PAS FORCER TOUS LES DOCUMENTS DANS LE MÊME
 * MOULE
 * ======================================================================
 * `$retour` — la destination du bouton de retour. Elle valait `/notes`
 * en dur : correct pour un bulletin, faux pour une attestation, qui
 * renvoyait le secrétariat dans les notes.
 *
 * `$sansFeuille` — une carte d'élève n'est pas une feuille A4. La poser
 * au milieu d'une page blanche de 21 cm donnerait une carte perdue au
 * centre d'un document qu'on imprimerait en entier.
 *
 *   > Un gabarit qui impose sa forme à tout ce qu'il enveloppe finit
 *   > par déformer ce qu'il devait servir.
 *
 * @var string $content
 * @var string $retour
 * @var bool   $sansFeuille
 */
declare(strict_types=1);

$retour      = $retour      ?? '/notes';
$sansFeuille = $sansFeuille ?? false;
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?= csrf_meta() ?>
    <title><?= e(view_title()) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <style>
        body { background: #f1f5f9; }

        /* UN QR NE DOIT PAS IMPOSER SA TAILLE.
           Le générateur produit un SVG avec ses dimensions propres ;
           c'est la page qui décide de la place qu'elle lui laisse. */
        .qr-a4     { width: 2.6cm; height: 2.6cm; }
        .qr-a4 svg { width: 100%; height: 100%; display: block; }

        .sheet {
            background: #fff;
            max-width: 21cm;
            margin: 1.5rem auto;
            padding: 1.4cm 1.2cm;
            box-shadow: 0 1px 4px rgba(0,0,0,.12);
        }

        .no-print { max-width: 21cm; margin: 1rem auto 0; }

        @media print {
            /* Le navigateur imprime la feuille, et rien d'autre. */
            body      { background: #fff; }
            .no-print { display: none !important; }
            .sheet    { margin: 0; padding: 0; box-shadow: none; max-width: none; }
            @page     { size: A4; margin: 1.2cm; }

            /* Une ligne de branche ne doit jamais être coupée en deux
               par un saut de page : le bulletin deviendrait illisible. */
            tr, .avoid-break { page-break-inside: avoid; }
            thead           { display: table-header-group; }
        }
    </style>
</head>
<body>

<div class="no-print d-flex justify-content-between align-items-center gap-2">
    <a href="<?= e(url($retour)) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Retour
    </a>
    <button type="button" class="btn btn-sm btn-primary" data-print>
        <i class="bi bi-printer me-1"></i> Imprimer
    </button>
</div>

<?php partial('partials/flash'); ?>

<?php if ($sansFeuille): ?>
    <?= $content ?>
<?php else: ?>
    <div class="sheet">
        <?= $content ?>
    </div>
<?php endif; ?>

<?= script_tag('assets/js/app.js') ?>
</body>
</html>
