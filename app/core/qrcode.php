<?php
/**
 * GÉNÉRATEUR DE QR CODE — PHP pur, sortie SVG.
 *
 * POURQUOI ÉCRIRE CELA PLUTÔT QUE L'INSTALLER
 * ============================================
 * Le projet tourne sans Composer, sur des hébergements mutualisés où
 * l'on ne contrôle ni les extensions ni les dépôts. Une bibliothèque
 * externe pour dessiner un carré noir et blanc serait une dépendance à
 * maintenir pendant des années, pour un algorithme figé depuis 1994.
 *
 * Le SVG est choisi plutôt que le PNG pour la même raison : il ne
 * demande NI GD NI Imagick, il s'imprime net à toute taille, et il pèse
 * moins qu'une image.
 *
 * CE QU'IL FAUT SAVOIR AVANT DE TOUCHER À CE FICHIER
 * ===================================================
 * Un QR mal encodé ne « fonctionne pas un peu moins bien » : il ne se
 * lit pas du tout, et cela ne se voit pas à l'œil. Le seul contrôle qui
 * vaille est de RELIRE ce qu'on a produit avec un décodeur indépendant.
 *
 *   > Un code qu'on n'a pas relu avec un autre outil que le sien n'est
 *   > pas un code vérifié, c'est un dessin.
 *
 * `tests/qrcode_decode.php` produit une série de codes, les rend en
 * PNG et les fait relire par OpenCV. Toute modification ici doit être
 * suivie de cette épreuve.
 *
 * PÉRIMÈTRE ASSUMÉ
 * ================
 *  · MODE OCTET uniquement. Le mode alphanumérique serait plus compact
 *    mais ne connaît pas les minuscules — or une URL en contient.
 *  · NIVEAU DE CORRECTION Q (≈25 %). Ces codes sont imprimés, pliés,
 *    photocopiés et scannés au téléphone dans une cour d'école ; le
 *    niveau M tiendrait mal ce traitement.
 *  · VERSIONS 1 à 10, soit jusqu'à 152 caractères. Une URL de
 *    vérification en fait une cinquantaine. Au-delà, la fonction refuse
 *    plutôt que de produire un code tronqué — un refus se voit, un code
 *    tronqué ne se voit pas.
 */

declare(strict_types=1);

/** Nombre total de mots de code, par version (tous niveaux confondus). */
const QR_TOTAL_CODEWORDS = [
    1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134,
    6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346,
];

/**
 * Structure des blocs au niveau Q, par version.
 * [mots de correction par bloc, [nb blocs, mots de données par bloc], …]
 */
const QR_BLOCKS_Q = [
    1  => [13, [[1, 13]]],
    2  => [22, [[1, 22]]],
    3  => [18, [[2, 17]]],
    4  => [26, [[2, 24]]],
    5  => [18, [[2, 15], [2, 16]]],
    6  => [24, [[4, 19]]],
    7  => [18, [[2, 14], [4, 15]]],
    8  => [22, [[4, 18], [2, 19]]],
    9  => [20, [[4, 16], [4, 17]]],
    10 => [24, [[6, 19], [2, 20]]],
];

/** Centres des motifs d'alignement, par version. */
const QR_ALIGNMENT = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
    6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
    10 => [6, 28, 50],
];

/** Indicateur de niveau de correction Q dans les bits de format. */
const QR_EC_Q = 0b11;

// =====================================================================
//  L'API PUBLIQUE
// =====================================================================

/**
 * Rend `$texte` en QR code SVG.
 *
 * @param int    $module Côté d'un module, en unités SVG.
 * @param int    $marge  Marge silencieuse, en modules. La norme en exige
 *                       4 : en dessous, beaucoup de lecteurs échouent.
 * @param string $titre  Texte alternatif — un QR sans équivalent
 *                       accessible est une impasse pour qui ne voit pas.
 *
 * @throws InvalidArgumentException si le texte dépasse la capacité.
 */
function qr_svg(string $texte, int $module = 4, int $marge = 4, string $titre = 'Code de vérification'): string
{
    $matrice = qr_matrix($texte);
    $n       = count($matrice);
    $cote    = ($n + 2 * $marge) * $module;

    // Un seul `path` pour tous les modules sombres : un `rect` par module
    // produirait un document plusieurs fois plus lourd, et certains
    // moteurs d'impression rendent mal des milliers de rectangles jointifs.
    $d = '';

    for ($y = 0; $y < $n; $y++) {
        for ($x = 0; $x < $n; $x++) {
            if ($matrice[$y][$x]) {
                $d .= 'M' . (($x + $marge) * $module) . ' ' . (($y + $marge) * $module)
                    . 'h' . $module . 'v' . $module . 'h-' . $module . 'z';
            }
        }
    }

    // CE SVG PORTE DES DIMENSIONS, ET C'EST VOULU.
    //
    // Ouvert seul, un SVG sans `width`/`height` n'a pas de taille : la
    // rastérisation le rend vide. Essayé — `max-width: 100%` a suffi à
    // rendre les huit codes de l'épreuve ILLISIBLES, parce qu'un
    // pourcentage sans parent ne vaut rien.
    //
    // Le `viewBox` le rend néanmoins redimensionnable : c'est à la MISE
    // EN PAGE de le contraindre, par une règle CSS sur son contenant
    // (`.carte-qr svg { width: 100%; height: auto; }`). Le générateur
    // produit un dessin juste ; le gabarit décide de sa taille.
    //
    //   > Une image ne devrait pas deviner la place qu'on lui laisse ;
    //   > c'est la page qui la lui donne.
    //
    // À NOTER pour qui modifiera ceci : les vecteurs figés de
    // `tests/qrcode_decode.php` comparent la MATRICE, pas le rendu. Une
    // régression d'habillage leur échappe — seule la relecture vivante
    // l'attrape. C'est ainsi qu'elle a été trouvée.
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $cote . ' ' . $cote . '"'
        . ' width="' . $cote . '" height="' . $cote . '" role="img"'
        . ' aria-label="' . htmlspecialchars($titre, ENT_QUOTES, 'UTF-8') . '">'
        . '<title>' . htmlspecialchars($titre, ENT_QUOTES, 'UTF-8') . '</title>'
        // Le fond blanc est OBLIGATOIRE : sur un fond coloré ou
        // transparent imprimé sur papier teinté, le contraste tombe et
        // le code devient illisible.
        . '<rect width="' . $cote . '" height="' . $cote . '" fill="#ffffff"/>'
        . '<path d="' . $d . '" fill="#000000"/>'
        . '</svg>';
}

/**
 * La matrice booléenne du code — `true` = module sombre.
 *
 * @return array<int, array<int, bool>>
 */
function qr_matrix(string $texte): array
{
    $version = qr_pick_version($texte);
    $donnees = qr_codewords($texte, $version);

    $meilleure = null;
    $meilleur  = PHP_INT_MAX;

    // LES HUIT MASQUES SONT TOUS ESSAYÉS, et le moins pénalisé gagne.
    // Ce n'est pas un raffinement : un masque mal choisi peut produire
    // de grandes plages uniformes ou de faux motifs de repérage, que le
    // lecteur confondra avec les coins du code.
    for ($masque = 0; $masque < 8; $masque++) {
        $m = qr_build_matrix($version, $donnees, $masque);
        $p = qr_penalty($m);

        if ($p < $meilleur) {
            $meilleur  = $p;
            $meilleure = $m;
        }
    }

    return $meilleure;
}

// =====================================================================
//  ENCODAGE DES DONNÉES
// =====================================================================

/** La plus petite version qui contient `$texte`. */
function qr_pick_version(string $texte): int
{
    $octets = strlen($texte);

    foreach (QR_BLOCKS_Q as $version => [$ecParBloc, $groupes]) {
        $capacite = 0;

        foreach ($groupes as [$nb, $mots]) {
            $capacite += $nb * $mots;
        }

        // 4 bits de mode + l'indicateur de longueur (8 bits jusqu'à la
        // version 9, 16 ensuite).
        $entete = 4 + ($version <= 9 ? 8 : 16);

        if ($octets * 8 + $entete <= $capacite * 8) {
            return $version;
        }
    }

    throw new InvalidArgumentException(
        'Texte trop long pour un QR code de version 10 au niveau Q : '
        . $octets . ' octets. Raccourcissez l\'URL de vérification.'
    );
}

/**
 * Les mots de code finaux — données entrelacées puis correction.
 *
 * @return array<int, int>
 */
function qr_codewords(string $texte, int $version): array
{
    [$ecParBloc, $groupes] = QR_BLOCKS_Q[$version];

    $capacite = 0;

    foreach ($groupes as [$nb, $mots]) {
        $capacite += $nb * $mots;
    }

    // --- Le flux de bits -------------------------------------------
    $bits = '0100';                                    // mode octet
    $bits .= str_pad(decbin(strlen($texte)), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);

    foreach (str_split($texte) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }

    // Terminateur : jusqu'à 4 zéros, et pas un de plus que la place libre.
    $bits .= str_repeat('0', min(4, $capacite * 8 - strlen($bits)));

    // Alignement sur l'octet.
    if (strlen($bits) % 8 !== 0) {
        $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    }

    // Remplissage normalisé, en alternant 0xEC et 0x11.
    $remplissage = ['11101100', '00010001'];
    $i = 0;

    while (strlen($bits) < $capacite * 8) {
        $bits .= $remplissage[$i++ % 2];
    }

    $mots = [];

    foreach (str_split($bits, 8) as $octet) {
        $mots[] = bindec($octet);
    }

    // --- Découpage en blocs ----------------------------------------
    $blocsData = [];
    $blocsEc   = [];
    $curseur   = 0;

    foreach ($groupes as [$nb, $taille]) {
        for ($b = 0; $b < $nb; $b++) {
            $bloc = array_slice($mots, $curseur, $taille);
            $curseur += $taille;

            $blocsData[] = $bloc;
            $blocsEc[]   = qr_reed_solomon($bloc, $ecParBloc);
        }
    }

    // --- Entrelacement ---------------------------------------------
    // Les blocs sont entremêlés mot à mot. C'est ce qui rend le code
    // résistant à une tache : une salissure locale abîme un mot dans
    // chaque bloc plutôt que tout un bloc.
    $sortie = [];
    $maxD   = max(array_map('count', $blocsData));

    for ($i = 0; $i < $maxD; $i++) {
        foreach ($blocsData as $bloc) {
            if (isset($bloc[$i])) {
                $sortie[] = $bloc[$i];
            }
        }
    }

    for ($i = 0; $i < $ecParBloc; $i++) {
        foreach ($blocsEc as $bloc) {
            $sortie[] = $bloc[$i];
        }
    }

    return $sortie;
}

// =====================================================================
//  CORRECTION D'ERREUR — Reed-Solomon sur GF(256)
// =====================================================================

/**
 * Les tables exponentielle et logarithmique du corps de Galois.
 *
 * Le polynôme générateur du corps est 0x11D, fixé par la norme QR.
 *
 * @return array{0: array<int,int>, 1: array<int,int>}
 */
function qr_gf_tables(): array
{
    static $exp = null, $log = null;

    if ($exp !== null) {
        return [$exp, $log];
    }

    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x   = 1;

    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;

        if ($x & 0x100) {
            $x ^= 0x11D;
        }
    }

    // La seconde moitié évite un modulo à chaque multiplication.
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }

    return [$exp, $log];
}

/** Produit de deux éléments du corps. */
function qr_gf_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }

    [$exp, $log] = qr_gf_tables();

    return $exp[$log[$a] + $log[$b]];
}

/**
 * Les `$n` mots de correction d'un bloc de données.
 *
 * @param array<int, int> $donnees
 * @return array<int, int>
 */
function qr_reed_solomon(array $donnees, int $n): array
{
    [$exp] = qr_gf_tables();

    // Polynôme générateur : (x - α⁰)(x - α¹)…(x - α^(n-1)).
    //
    // LE SENS DE RANGEMENT COMPTE, et il a coûté cher.
    //
    // Les coefficients sont rangés du PLUS HAUT DEGRÉ au plus bas :
    // `$gen[0]` est le coefficient dominant, et il vaut toujours 1.
    // Toute la division ci-dessous en dépend — c'est ce 1 qui annule la
    // tête du reste à chaque tour.
    //
    // Première version de ce fichier : les deux lignes étaient
    // inversées, ce qui rangeait le polynôme à l'envers. Pour n = 1 le
    // résultat est [1, 1] dans les deux sens : un test unitaire sur un
    // petit cas serait passé. C'est la relecture d'un code complet par
    // un décodeur tiers qui l'a montré.
    //
    //   > Un polynôme symétrique sur le petit cas ne prouve rien sur le
    //   > grand.
    //
    // Multiplier par (x + αⁱ), c'est ajouter :
    //   · x·gen    → les coefficients gardent leur rang (degré +1)
    //   · αⁱ·gen   → les coefficients descendent d'un rang
    $gen = [1];

    for ($i = 0; $i < $n; $i++) {
        $suivant = array_fill(0, count($gen) + 1, 0);

        foreach ($gen as $j => $coef) {
            $suivant[$j]     ^= $coef;                        // x · gen
            $suivant[$j + 1] ^= qr_gf_mul($coef, $exp[$i]);   // αⁱ · gen
        }

        $gen = $suivant;
    }

    // Division polynomiale : le reste EST le code de correction.
    $reste = array_merge($donnees, array_fill(0, $n, 0));

    for ($i = 0; $i < count($donnees); $i++) {
        $tete = $reste[$i];

        if ($tete === 0) {
            continue;
        }

        foreach ($gen as $j => $coef) {
            $reste[$i + $j] ^= qr_gf_mul($coef, $tete);
        }
    }

    return array_slice($reste, count($donnees));
}

// =====================================================================
//  CONSTRUCTION DE LA MATRICE
// =====================================================================

/**
 * @param array<int, int> $mots
 * @return array<int, array<int, bool>>
 */
function qr_build_matrix(int $version, array $mots, int $masque): array
{
    $n = 21 + 4 * ($version - 1);

    $m        = array_fill(0, $n, array_fill(0, $n, false));
    $reserve  = array_fill(0, $n, array_fill(0, $n, false));

    $poser = static function (int $x, int $y, bool $sombre) use (&$m, &$reserve, $n): void {
        if ($x >= 0 && $y >= 0 && $x < $n && $y < $n) {
            $m[$y][$x]       = $sombre;
            $reserve[$y][$x] = true;
        }
    };

    // --- Motifs de repérage, aux trois coins -----------------------
    foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$dx, $dy]) {
        for ($y = -1; $y <= 7; $y++) {
            for ($x = -1; $x <= 7; $x++) {
                $dansCarre = $x >= 0 && $x <= 6 && $y >= 0 && $y <= 6;
                $sombre    = $dansCarre
                    && ($x === 0 || $x === 6 || $y === 0 || $y === 6
                        || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4));

                // Le séparateur (la bande claire autour) est réservé
                // AUSSI : sans lui, le lecteur ne distingue pas le coin
                // du reste du code.
                $poser($dx + $x, $dy + $y, $sombre);
            }
        }
    }

    // --- Motifs d'alignement ---------------------------------------
    $centres = QR_ALIGNMENT[$version];

    foreach ($centres as $cy) {
        foreach ($centres as $cx) {
            // Pas d'alignement sous un motif de repérage.
            if (($cx === 6 && $cy === 6)
                || ($cx === 6 && $cy === $n - 7)
                || ($cx === $n - 7 && $cy === 6)) {
                continue;
            }

            for ($y = -2; $y <= 2; $y++) {
                for ($x = -2; $x <= 2; $x++) {
                    $poser($cx + $x, $cy + $y, max(abs($x), abs($y)) !== 1);
                }
            }
        }
    }

    // --- Lignes de synchronisation ---------------------------------
    for ($i = 8; $i < $n - 8; $i++) {
        $poser($i, 6, $i % 2 === 0);
        $poser(6, $i, $i % 2 === 0);
    }

    // --- Emplacements du format, réservés avant la pose des données -
    //
    // LA COPIE VERTICALE occupe la colonne 8 : lignes 0-5, 7, 8, puis
    // n-7 à n-1. Quinze modules, pas seize.
    //
    // LA COPIE HORIZONTALE occupe la ligne 8 : colonnes 0-5, 7, puis
    // n-8 à n-1. Quinze aussi.
    for ($i = 0; $i < 9; $i++) {
        if ($i !== 6) {
            $poser($i, 8, false);   // ligne 8, colonnes 0-5, 7, 8
            $poser(8, $i, false);   // colonne 8, lignes 0-5, 7, 8
        }
    }

    for ($i = 0; $i < 8; $i++) {
        $poser($n - 1 - $i, 8, false);   // ligne 8, colonnes n-8 à n-1
    }

    // La colonne 8 ne descend QUE jusqu'à n-7. La ligne n-8 appartient
    // au module sombre — première version de ce fichier : la boucle
    // allait jusqu'à n-8 et effaçait ce module, posé plus haut. Le code
    // devenait illisible sans que rien ne le montre à l'œil.
    for ($i = 0; $i < 7; $i++) {
        $poser(8, $n - 1 - $i, false);   // colonne 8, lignes n-7 à n-1
    }

    // --- Module sombre, toujours au même endroit -------------------
    // Posé APRÈS les réservations, pour qu'aucune d'elles ne l'efface.
    $poser(8, $n - 8, true);

    // --- Information de version (à partir de la version 7) ---------
    if ($version >= 7) {
        $bits = qr_version_bits($version);

        for ($i = 0; $i < 18; $i++) {
            $b = (bool) (($bits >> $i) & 1);
            $poser($i % 3 + $n - 11, intdiv($i, 3), $b);
            $poser(intdiv($i, 3), $i % 3 + $n - 11, $b);
        }
    }

    // --- Pose des données, en zigzag depuis le coin bas-droit ------
    $bits = '';

    foreach ($mots as $mot) {
        $bits .= str_pad(decbin($mot), 8, '0', STR_PAD_LEFT);
    }

    $i       = 0;
    $montant = true;

    for ($colonne = $n - 1; $colonne > 0; $colonne -= 2) {
        // La colonne 6 est celle de la synchronisation : on l'enjambe.
        if ($colonne === 6) {
            $colonne--;
        }

        for ($k = 0; $k < $n; $k++) {
            $y = $montant ? $n - 1 - $k : $k;

            foreach ([$colonne, $colonne - 1] as $x) {
                if ($reserve[$y][$x]) {
                    continue;
                }

                $bit = $i < strlen($bits) && $bits[$i] === '1';
                $i++;

                $m[$y][$x] = $bit !== qr_mask_applies($masque, $x, $y);
            }
        }

        $montant = !$montant;
    }

    // --- Information de format, APRÈS les données ------------------
    $format = qr_format_bits($masque);

    // LES DEUX COPIES SONT DISTINCTES, ET NE SE MÉLANGENT PAS.
    //
    // Première version de ce fichier : les deux chaînes de conditions
    // empruntaient chacune une formule à l'autre copie. Chaque module
    // recevait bien un bit, mais pas le sien. Le code restait un beau
    // damier, et aucun lecteur n'en tirait rien.
    //
    // Le bit d'indice 0 est le POIDS FAIBLE des quinze bits de format.
    for ($i = 0; $i < 15; $i++) {
        $b = (bool) (($format >> $i) & 1);

        // Copie verticale — colonne 8, de haut en bas puis en bas à
        // gauche. Elle enjambe la ligne 6, réservée à la synchronisation.
        if ($i < 6) {
            $m[$i][8] = $b;
        } elseif ($i < 8) {
            $m[$i + 1][8] = $b;
        } else {
            $m[$n - 15 + $i][8] = $b;
        }

        // Copie horizontale — ligne 8, depuis la droite, puis à gauche
        // du repère supérieur. Elle enjambe la colonne 6, pour la même
        // raison.
        if ($i < 8) {
            $m[8][$n - 1 - $i] = $b;
        } elseif ($i === 8) {
            $m[8][7] = $b;
        } else {
            $m[8][14 - $i] = $b;
        }
    }

    return $m;
}

/** Le masque `$k` s'applique-t-il en (x, y) ? */
function qr_mask_applies(int $k, int $x, int $y): bool
{
    return match ($k) {
        0 => ($y + $x) % 2 === 0,
        1 => $y % 2 === 0,
        2 => $x % 3 === 0,
        3 => ($y + $x) % 3 === 0,
        4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
        5 => ($y * $x) % 2 + ($y * $x) % 3 === 0,
        6 => (($y * $x) % 2 + ($y * $x) % 3) % 2 === 0,
        7 => (($y + $x) % 2 + ($y * $x) % 3) % 2 === 0,
    };
}

/**
 * Les 15 bits de format : niveau, masque, et un code correcteur BCH.
 *
 * Calculés plutôt que recopiés d'une table : une table de 32 valeurs
 * hexadécimales se relit mal, et une coquille y serait indétectable.
 */
function qr_format_bits(int $masque): int
{
    $donnees = (QR_EC_Q << 3) | $masque;
    $reste   = $donnees << 10;

    for ($i = 14; $i >= 10; $i--) {
        if ($reste & (1 << $i)) {
            $reste ^= 0b10100110111 << ($i - 10);
        }
    }

    // Le masque 0x5412 évite qu'un format tout à zéro passe pour du vide.
    return (($donnees << 10) | $reste) ^ 0b101010000010010;
}

/** Les 18 bits d'information de version (versions 7 et au-delà). */
function qr_version_bits(int $version): int
{
    $reste = $version << 12;

    for ($i = 17; $i >= 12; $i--) {
        if ($reste & (1 << $i)) {
            $reste ^= 0b1111100100101 << ($i - 12);
        }
    }

    return ($version << 12) | $reste;
}

// =====================================================================
//  PÉNALITÉS — le choix du masque
// =====================================================================

/**
 * Le score de pénalité d'une matrice, selon les quatre règles de la
 * norme. Plus il est bas, plus le code se lit facilement.
 *
 * @param array<int, array<int, bool>> $m
 */
function qr_penalty(array $m): int
{
    $n     = count($m);
    $score = 0;

    // Règle 1 — suites de 5 modules identiques ou plus, en ligne
    // et en colonne.
    foreach ([false, true] as $transpose) {
        for ($y = 0; $y < $n; $y++) {
            $suite   = 1;
            $premier = $transpose ? $m[0][$y] : $m[$y][0];

            for ($x = 1; $x < $n; $x++) {
                $val = $transpose ? $m[$x][$y] : $m[$y][$x];

                if ($val === $premier) {
                    $suite++;
                    continue;
                }

                if ($suite >= 5) {
                    $score += 3 + ($suite - 5);
                }

                $premier = $val;
                $suite   = 1;
            }

            if ($suite >= 5) {
                $score += 3 + ($suite - 5);
            }
        }
    }

    // Règle 2 — blocs 2×2 de même couleur.
    for ($y = 0; $y < $n - 1; $y++) {
        for ($x = 0; $x < $n - 1; $x++) {
            if ($m[$y][$x] === $m[$y][$x + 1]
                && $m[$y][$x] === $m[$y + 1][$x]
                && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                $score += 3;
            }
        }
    }

    // Règle 3 — motifs ressemblant à un repère de coin. C'est la règle
    // qui compte le plus : un tel motif au milieu des données égare le
    // lecteur sur l'orientation du code.
    $motifs = [
        [true, false, true, true, true, false, true, false, false, false, false],
        [false, false, false, false, true, false, true, true, true, false, true],
    ];

    foreach ([false, true] as $transpose) {
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x <= $n - 11; $x++) {
                foreach ($motifs as $motif) {
                    $trouve = true;

                    for ($k = 0; $k < 11; $k++) {
                        $val = $transpose ? $m[$x + $k][$y] : $m[$y][$x + $k];

                        if ($val !== $motif[$k]) {
                            $trouve = false;
                            break;
                        }
                    }

                    if ($trouve) {
                        $score += 40;
                    }
                }
            }
        }
    }

    // Règle 4 — déséquilibre entre modules sombres et clairs.
    $sombres = 0;

    foreach ($m as $ligne) {
        foreach ($ligne as $v) {
            if ($v) {
                $sombres++;
            }
        }
    }

    $pourcent = $sombres * 100 / ($n * $n);
    $score   += (int) (abs($pourcent - 50) / 5) * 10;

    return $score;
}
