<?php
/**
 * Le document officiel, prêt à imprimer.
 *
 * TOUT CE QUI S'AFFICHE ICI VIENT DU FIGEAGE (`$fige`), JAMAIS DES
 * TABLES VIVANTES. C'est la règle du module, et elle se vérifie à l'œil
 * dans ce fichier : aucun appel de dépôt, aucune requête. Un document
 * rouvert dans dix ans doit dire exactement ce qu'il disait le jour où
 * il a été signé.
 *
 * LA CARTE D'ÉLÈVE est un format à part — 8,5 × 5,4 cm, comme une carte
 * bancaire, pour tenir dans un portefeuille. Les trois autres sont des
 * A4 qui se classent dans un dossier.
 *
 * LE BLOC DE SIGNATURE reste vide : le document sort de l'imprimante
 * pour être signé et tamponné à la main. Une signature numérisée
 * imprimée sur chaque exemplaire ne prouverait rien — elle se recopie.
 *
 * UN APERÇU SE TRAHIT, TOUJOURS.
 * `$apercu` fait apparaître un filigrane, remplace le numéro par
 * « SANS VALEUR » et retire le code de vérification. Un aperçu qui ne
 * se distinguerait pas de l'original serait une fabrique de faux.
 *
 * @var array  $doc
 * @var array  $fige   Le figeage — PAS `data` : voir le contrôleur
 * @var string $url
 * @var string $qr     Le SVG, déjà rendu — vide en aperçu
 * @var bool   $apercu
 * @var string $retour
 */
declare(strict_types=1);

$apercu = $apercu ?? false;
$retour = $retour ?? '/documents';

set_title($apercu ? 'Aperçu' : (string) $doc['number']);

$revoque = $doc['revoked_at'] !== null;
$eleve   = $fige['student'] ?? [];
$ecole   = $fige['school'] ?? [];

$nomComplet = trim(
    mb_strtoupper((string) ($eleve['last_name'] ?? ''))
    . ' ' . (string) ($eleve['post_name'] ?? '')
    . ' ' . (string) ($eleve['first_name'] ?? '')
);

$ne = ($eleve['gender'] ?? 'M') === 'F' ? 'née' : 'né';

// Le corps du filigrane de la carte — voir la règle .carte-filigrane.
$nomEcole       = (string) ($ecole['name'] ?? '');
$filigraneCorps = $nomEcole === ''
    ? 5.0
    : max(2.4, min(6.5, round(88 / (max(1, mb_strlen($nomEcole)) * 0.645), 1)));

$dateLongue = static function (string $iso): string {
    if ($iso === '') {
        return '—';
    }

    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($iso);

    return (int) date('j', $t) . ' ' . $mois[(int) date('n', $t)] . ' ' . date('Y', $t);
};

/*
 * LA MENTION DE NAISSANCE SE COMPOSE EN PHP.
 *
 * Écrite en découpant le HTML, elle produisait « à Kinshasa , » —
 * une espace avant la virgule, faute de français sur une pièce
 * officielle. Le gabarit ne sait pas où s'arrête une phrase ; PHP, si.
 */
$naissance = $ne . ' le ' . $dateLongue((string) ($eleve['birth_date'] ?? ''));

if ((string) ($eleve['birth_place'] ?? '') !== '') {
    $naissance .= ' à ' . (string) $eleve['birth_place'];
}

?>

<?php if ($apercu): ?>
    <!-- LE FILIGRANE. Il traverse la page, il survit à l'impression, et
         il est en dessous du texte pour rester lisible sans le masquer.
         `print-color-adjust: exact` force son impression : sans cela,
         beaucoup de navigateurs suppriment les fonds à l'impression et
         le spécimen sortirait indistinguable d'un vrai document. -->
    <style>
        .filigrane {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .filigrane span {
            font-size: 11vw; font-weight: 800; letter-spacing: .1em;
            color: rgba(185, 28, 28, .12);
            transform: rotate(-32deg); white-space: nowrap;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .sheet, .carte { position: relative; z-index: 1; }
        @media print { .apercu-bandeau { display: none; } }
    </style>

    <div class="filigrane" aria-hidden="true"><span>SPÉCIMEN</span></div>

    <div class="apercu-bandeau alert alert-warning"
         style="max-width:21cm;margin:1.5rem auto 0;">
        <strong>Aperçu — ce document n'a aucune valeur.</strong>
        Aucun numéro n'a été attribué, aucun code de vérification n'y figure,
        et rien n'a été enregistré. Pour obtenir un document valable,
        revenez en arrière et choisissez <em>Délivrer</em>.
    </div>
<?php endif; ?>

<?php if ($doc['type'] === 'carte_eleve'): ?>
    <!-- ============================================================
         LA CARTE D'ÉLÈVE — format ISO ID-1 (85,6 × 54 mm)
         ============================================================

         Les proportions sont celles d'une carte bancaire : elle doit
         entrer dans un portefeuille, et les pochettes plastiques du
         commerce sont à ce format. S'en écarter rendrait la carte
         invendable en accessoires.

         LE FILIGRANE PORTE LE NOM DE L'ÉCOLE. Ce n'est pas un
         ornement : c'est ce qui rend une photocopie reconnaissable
         comme telle, et ce qui rattache visuellement la carte à
         l'établissement même sans logo. `print-color-adjust: exact`
         force son impression — sans cela, la plupart des navigateurs
         suppriment les fonds et le filigrane disparaîtrait au moment
         précis où il sert.

         LE QR EST CONTRAINT PAR LA PAGE, pas par lui-même. Le
         générateur produit un SVG avec ses dimensions propres ; sans
         la règle `.carte-qr svg`, il s'affichait à sa taille native,
         débordait de la carte et recouvrait les boutons.
         ============================================================ -->
    <style>
        .carte-zone { display:flex; justify-content:center; padding:1.5rem 1rem; }

        .carte {
            position: relative; overflow: hidden;
            width: 85.6mm; height: 54mm;
            background: #fff;
            border: 1px solid #0f3d3e; border-radius: 3mm;
            font-size: 7.6pt; line-height: 1.3; color: #0f172a;
            display: grid; grid-template-rows: 9mm auto 1fr 6mm;
        }

        /* --- le filigrane : le nom de l'école, en travers --- */
        .carte-filigrane {
            position: absolute; inset: 0; z-index: 0;
            display: flex; align-items: center; justify-content: center;
            pointer-events: none; overflow: hidden;
        }
        .carte-filigrane span {
            /* La taille suit la LONGUEUR DU NOM : « ITM » et « Complexe
               Scolaire de la Sainte-Famille » ne tiennent pas dans la
               même diagonale. Fixée en dur, elle tronquait les noms
               longs et laissait les courts perdus au milieu.
               La diagonale d'une carte 85,6 × 54 fait environ 101 mm ;
               on vise 88 mm utiles pour garder une respiration.

               Le facteur 0,645 n'est pas deviné : il a été MESURÉ dans
               un navigateur, à 10 mm de corps, sur la graisse 800 de la
               police du produit — 219,2 mm pour 34 caractères. Un
               coefficient deviné n'est pas un calcul, c'est un vœu, et
               le premier essai (0,52) tronquait encore le nom. */
            font-size: <?= $filigraneCorps ?>mm; font-weight: 800; letter-spacing: .03em;
            color: rgba(15, 61, 62, .07);
            transform: rotate(-24deg); white-space: nowrap;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }

        /* --- bandeau supérieur : logo + établissement --- */
        .carte-tete {
            position: relative; z-index: 1;
            background: #0f3d3e; color: #fff;
            display: flex; align-items: center; gap: 2mm;
            padding: 0 3mm;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .carte-logo { height: 6.5mm; width: auto; max-width: 12mm; object-fit: contain; }
        .carte-ecole { font-weight: 700; font-size: 7.4pt; line-height: 1.15; }
        .carte-devise { font-size: 5.4pt; opacity: .85; }

        /* --- corps : photo + identité --- */
        /* TROIS COLONNES : photo, identité, code.
           Le code était logé dans le pied, sur 13 mm — soit 0,32 mm par
           module une fois la marge silencieuse comptée. Mesuré à 300 ppp :
           zbar y parvenait, OpenCV non. Sur du papier, avec un téléphone
           et une lumière de bureau d'école, c'est trop juste.
           En le remontant dans le corps il dispose de 17 mm, soit
           0,41 mm par module — au-dessus de ce que les lecteurs
           exigent, et vérifié par les deux décodeurs. */
        /* LE NOM PREND TOUTE LA LARGEUR.
           Serré dans une colonne entre la photo et le code, un nom
           congolais complet — trois éléments, souvent longs — passait à
           la ligne et poussait le reste. C'est l'information principale
           d'une carte : elle ne doit pas être la plus maltraitée. */
        .carte-nom-bande {
            position: relative; z-index: 1;
            padding: 2mm 3mm 0;
        }
        .carte-nom {
            font-weight: 700; font-size: 10pt; line-height: 1.1;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .carte-corps {
            position: relative; z-index: 1;
            display: grid; grid-template-columns: 18mm 1fr 17mm; gap: 2.5mm;
            padding: 1.5mm 3mm 0;
        }
        .carte-photo {
            width: 18mm; height: 23mm; object-fit: cover;
            border: .3mm solid #cbd5e1; border-radius: 1mm; background: #f1f5f9;
        }
        .carte-ligne { display: flex; gap: 1.5mm; line-height: 1.45; }
        .carte-etiq  { color: #64748b; min-width: 13mm; flex: 0 0 13mm; }

        /* --- pied : numéro + QR --- */
        .carte-pied {
            position: relative; z-index: 1;
            display: flex; justify-content: space-between; align-items: flex-end;
            padding: 0 3mm 1.5mm; font-size: 5.4pt; color: #475569;
            white-space: nowrap;
        }
        .carte-qr-legende {
            font-size: 4.6pt; color: #64748b; text-align: center;
            margin-top: .6mm; line-height: 1.1;
        }
        .carte-qr { width: 17mm; height: 17mm; }
        .carte-qr svg { width: 100%; height: 100%; display: block; }

        @media print {
            .carte-zone { padding: 0; }
            .carte { border-radius: 3mm; }
            .no-print { display: none; }
        }
    </style>

    <div class="carte-zone">
        <div class="carte">

            <div class="carte-filigrane" aria-hidden="true">
                <span><?= e((string) ($ecole['name'] ?? '')) ?></span>
            </div>

            <!-- Bandeau -->
            <div class="carte-tete">
                <?php if (($ecole['has_logo'] ?? false) && !$apercu): ?>
                    <img class="carte-logo" src="<?= e(url('/ecole/logo')) ?>" alt="">
                <?php elseif ($apercu && ($ecole['has_logo'] ?? false)): ?>
                    <img class="carte-logo" src="<?= e(url('/ecole/logo')) ?>" alt="">
                <?php endif; ?>

                <div>
                    <div class="carte-ecole"><?= e((string) ($ecole['name'] ?? '')) ?></div>
                    <?php
                    // Devise et téléphone se partagent la seconde ligne du
                    // bandeau. Le téléphone, laissé au pied, faisait
                    // déborder la ligne du numéro sur deux lignes.
                    $sousTitre = array_filter([
                        (string) ($ecole['motto'] ?? ''),
                        ($ecole['phone'] ?? '') !== '' ? 'Tél. ' . $ecole['phone'] : '',
                    ]);
                    ?>
                    <?php if ($sousTitre !== []): ?>
                        <div class="carte-devise"><?= e(implode(' · ', $sousTitre)) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Le nom, pleine largeur -->
            <div class="carte-nom-bande">
                <div class="carte-nom"><?= e($nomComplet) ?></div>
            </div>

            <!-- Corps -->
            <div class="carte-corps">
                <div>
                    <?php if (($eleve['id'] ?? 0) > 0 && ($fige['photo_path'] ?? '') !== ''): ?>
                        <img class="carte-photo" alt=""
                             src="<?= e(url('/eleves/' . (int) $eleve['id'] . '/photo')) ?>">
                    <?php else: ?>
                        <div class="carte-photo d-flex align-items-center justify-content-center">
                            <span style="font-size:5pt;color:#94a3b8;text-align:center;">
                                <?= $apercu ? 'photo<br>à venir' : '' ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="carte-ligne">
                        <span class="carte-etiq">Matricule</span>
                        <strong><?= e((string) ($eleve['matricule'] ?? '')) ?></strong>
                    </div>
                    <div class="carte-ligne">
                        <span class="carte-etiq">Classe</span>
                        <span><?= e((string) ($fige['classroom'] ?? '')) ?></span>
                    </div>
                    <div class="carte-ligne">
                        <span class="carte-etiq">Année</span>
                        <span><?= e((string) ($fige['year'] ?? '')) ?></span>
                    </div>
                    <?php if (($eleve['birth_date'] ?? '') !== ''): ?>
                        <div class="carte-ligne">
                            <span class="carte-etiq">Né<?= $ne === 'née' ? 'e' : '' ?> le</span>
                            <span><?= e(date('d/m/Y', strtotime((string) $eleve['birth_date']))) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Le code, et ce qu'il sert à faire. Sans légende,
                     personne ne devine qu'on peut vérifier la carte. -->
                <div>
                    <div class="carte-qr">
                        <?php if ($apercu): ?>
                            <div style="width:100%;height:100%;border:.3mm dashed #94a3b8;
                                        display:flex;align-items:center;justify-content:center;
                                        font-size:4.4pt;color:#94a3b8;text-align:center;">
                                code de<br>vérification
                            </div>
                        <?php else: ?>
                            <?= $qr ?>
                        <?php endif; ?>
                    </div>
                    <div class="carte-qr-legende">Vérifier<br>cette carte</div>
                </div>
            </div>

            <!-- Pied -->
            <div class="carte-pied">
                <div>
                    <?php if ($apercu): ?>
                        <strong style="color:#b91c1c;">SPÉCIMEN — SANS VALEUR</strong>
                    <?php else: ?>
                        N° <strong><?= e((string) $doc['number']) ?></strong>
                    <?php endif; ?>
                    · Valable jusqu'au
                    <?= e($dateLongue((string) ($fige['valid_until'] ?? ''))) ?>
                </div>

            </div>

        </div>
    </div>

<?php else: ?>
    <!-- ============================================================
         LES DOCUMENTS A4

         PAS DE <div class="sheet"> ICI : le layout d'impression en
         fournit déjà un. En ajouter un second donnait deux feuilles
         imbriquées — une page blanche dans une page blanche, avec
         double marge et double ombre.
         ============================================================ -->

        <?php if ($revoque): ?>
            <!-- Un document révoqué reste consultable — l'école doit
                 pouvoir montrer ce qu'elle avait délivré — mais il ne
                 doit jamais pouvoir passer pour valable. -->
            <div class="alert alert-danger">
                <strong>Document révoqué</strong> le
                <?= e(substr((string) $doc['revoked_at'], 0, 10)) ?>.
                <?= e((string) ($doc['revoke_reason'] ?? '')) ?>
                Cet exemplaire n'a plus aucune valeur.
            </div>
        <?php endif; ?>

        <!-- En-tête de l'établissement -->
        <header style="text-align:center;border-bottom:2px solid #0f3d3e;padding-bottom:.5cm;">
            <?php
            /*
             * LA LIGNE DE TUTELLE EST PARAMÉTRABLE.
             *
             * Elle était écrite en dur. Une école conventionnée
             * catholique, une école publique et une école privée agréée
             * n'écrivent pas la même chose en tête de leurs documents,
             * et se tromper de tutelle sur une pièce officielle est une
             * faute que l'école paie, pas nous.
             */
            $tutelle = (string) ($ecole['authority'] ?? '');
            ?>
            <?php if ($tutelle !== ''): ?>
                <div style="font-size:8pt;letter-spacing:.06em;text-transform:uppercase;color:#475569;">
                    <?= e($tutelle) ?>
                </div>
            <?php endif; ?>
            <?php if (($ecole['has_logo'] ?? false)): ?>
                <img src="<?= e(url('/ecole/logo')) ?>" alt=""
                     style="max-height:1.6cm;max-width:4cm;object-fit:contain;margin:.2cm 0;">
            <?php endif; ?>

            <h1 style="font-size:15pt;margin:.25cm 0 .1cm;color:#0f3d3e;">
                <?= e((string) ($ecole['name'] ?? '')) ?>
            </h1>
            <div style="font-size:8.5pt;color:#475569;">
                <?php if (($ecole['address'] ?? '') !== ''): ?>
                    <?= e((string) $ecole['address']) ?>
                <?php endif; ?>
                <?php if (($ecole['phone'] ?? '') !== ''): ?>
                    · Tél. <?= e((string) $ecole['phone']) ?>
                <?php endif; ?>
            </div>
        </header>

        <p style="text-align:right;font-size:9pt;margin-top:.4cm;">
            <?php if ($apercu): ?>
                <strong style="color:#b91c1c;">SPÉCIMEN — SANS VALEUR</strong>
            <?php else: ?>
                N° <strong><?= e((string) $doc['number']) ?></strong>
            <?php endif; ?>
        </p>

        <h2 style="text-align:center;font-size:13pt;letter-spacing:.08em;
                   text-transform:uppercase;margin:.8cm 0 .9cm;">
            <?= e((string) ($fige['type_label'] ?? '')) ?>
        </h2>

        <div style="font-size:11pt;line-height:1.9;text-align:justify;">
            <?php if ($doc['type'] === 'attestation_frequentation'): ?>
                <p>
                    Je soussigné, Chef d'établissement du
                    <strong><?= e((string) ($ecole['name'] ?? '')) ?></strong>,
                    atteste que l'élève
                    <strong><?= e($nomComplet) ?></strong>,
                    <?= e($naissance) ?>,
                    matricule <strong><?= e((string) ($eleve['matricule'] ?? '')) ?></strong>,
                    est régulièrement inscrit<?= $ne === 'née' ? 'e' : '' ?> et fréquente
                    notre établissement en classe de
                    <strong><?= e((string) ($fige['classroom'] ?? '')) ?></strong>
                    au cours de l'année scolaire
                    <strong><?= e((string) ($fige['year'] ?? '')) ?></strong>.
                </p>
                <p>
                    En foi de quoi la présente attestation lui est délivrée pour servir
                    et valoir ce que de droit.
                </p>

            <?php elseif ($doc['type'] === 'certificat_scolarite'): ?>
                <p>
                    Je soussigné, Chef d'établissement du
                    <strong><?= e((string) ($ecole['name'] ?? '')) ?></strong>,
                    certifie que l'élève
                    <strong><?= e($nomComplet) ?></strong>,
                    <?= e($naissance) ?>,
                    matricule <strong><?= e((string) ($eleve['matricule'] ?? '')) ?></strong>,
                    a suivi les enseignements de la classe de
                    <strong><?= e((string) ($fige['classroom'] ?? '')) ?></strong>
                    <?php if (($fige['level'] ?? '') !== ''): ?>
                        (<?= e((string) $fige['level']) ?>)
                    <?php endif; ?>
                    durant l'année scolaire
                    <strong><?= e((string) ($fige['year'] ?? '')) ?></strong>.
                </p>
                <?php if (($fige['decision'] ?? '') !== ''): ?>
                    <p>
                        Décision de fin d'année :
                        <strong><?= e((string) $fige['decision']) ?></strong>.
                    </p>
                <?php endif; ?>
                <p>
                    En foi de quoi le présent certificat lui est délivré pour servir
                    et valoir ce que de droit.
                </p>

            <?php elseif ($doc['type'] === 'attestation_paiement'): ?>
                <p>
                    Je soussigné, responsable financier du
                    <strong><?= e((string) ($ecole['name'] ?? '')) ?></strong>,
                    atteste que l'élève
                    <strong><?= e($nomComplet) ?></strong>,
                    matricule <strong><?= e((string) ($eleve['matricule'] ?? '')) ?></strong>,
                    inscrit<?= $ne === 'née' ? 'e' : '' ?> en classe de
                    <strong><?= e((string) ($fige['classroom'] ?? '')) ?></strong>,
                    est en règle de tous les frais scolaires exigibles pour l'année
                    <strong><?= e((string) ($fige['year'] ?? '')) ?></strong>.
                </p>
                <p style="font-size:10pt;">
                    Total des frais dus :
                    <strong><?= e(number_format((float) ($fige['total_due'] ?? 0), 2, ',', ' ')) ?>
                    <?= e((string) ($fige['currency'] ?? '')) ?></strong> —
                    total réglé :
                    <strong><?= e(number_format((float) ($fige['total_paid'] ?? 0), 2, ',', ' ')) ?>
                    <?= e((string) ($fige['currency'] ?? '')) ?></strong>.
                </p>
                <p style="font-size:10pt;color:#475569;">
                    La présente atteste de la situation à la date de délivrance. Elle ne
                    couvre pas les frais devenus exigibles ultérieurement.
                </p>
            <?php endif; ?>
        </div>

        <!-- Pied : lieu, date, signature, et le QR -->
        <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:1.2cm;">

            <div style="font-size:8.5pt;max-width:6cm;">
                <?php if ($apercu): ?>
                    <div style="width:2.6cm;height:2.6cm;border:1px dashed #94a3b8;
                                display:flex;align-items:center;justify-content:center;
                                font-size:7pt;color:#94a3b8;text-align:center;">
                        code de<br>vérification
                    </div>
                    <p style="margin-top:.15cm;color:#94a3b8;line-height:1.4;">
                        Le code de vérification n'apparaît que sur un document
                        réellement délivré.
                    </p>
                <?php else: ?>
                    <div class="qr-a4"><?= $qr ?></div>
                    <p style="margin-top:.15cm;color:#475569;line-height:1.4;">
                        Vérifiez l'authenticité de ce document en scannant ce code, ou sur
                        <br><strong><?= e($url) ?></strong>
                    </p>
                <?php endif; ?>
            </div>

            <div style="text-align:center;font-size:10pt;min-width:6cm;">
                <p>
                    <?php
                    /*
                     * « Fait à » PREND LA VILLE, pas l'adresse.
                     *
                     * La première version imprimait l'adresse complète —
                     * et, celle-ci n'étant renseignée nulle part, un
                     * « Fait à , » au bas d'une pièce signée. La ville
                     * est désormais un réglage à part, et son absence se
                     * voit à l'écran des paramètres.
                     */
                    $ville = (string) ($ecole['city'] ?? '');
                    ?>
                    Fait<?= $ville !== '' ? ' à ' . e($ville) : '' ?>,<br>
                    le <?= e($dateLongue((string) ($fige['issued_on'] ?? ''))) ?>
                </p>
                <!-- Délibérément vide : la signature et le cachet se
                     posent à la main sur le papier. -->
                <div style="height:2.2cm;"></div>
                <p style="border-top:1px solid #0f3d3e;padding-top:.15cm;margin:0;">
                    Le Chef d'établissement<br>
                    <span style="font-size:8.5pt;color:#475569;">signature et sceau</span>
                </p>
            </div>
        </div>

<?php endif; ?>

<?php
/*
 * PAS DE BOUTONS ICI NON PLUS : le layout d'impression porte déjà
 * « Retour » et « Imprimer », et il les masque correctement à
 * l'impression. Les redoubler donnait deux jeux de boutons, dont l'un
 * renvoyait au mauvais endroit.
 */
?>
