<?php
/**
 * ÉPREUVE DU GÉNÉRATEUR DE QR.
 *
 * Un QR mal encodé ne se lit pas du tout, et cela ne se voit pas à
 * l'œil : le dessin reste un joli damier. Vérifier le générateur avec
 * son propre décodeur ne prouverait rien non plus — deux erreurs
 * symétriques s'annulent.
 *
 *   > Un code qu'on n'a pas relu avec un autre outil que le sien n'est
 *   > pas un code vérifié, c'est un dessin.
 *
 * DEUX NIVEAUX DE CONTRÔLE, ET IL FAUT LES DISTINGUER
 * ====================================================
 *
 * 1. LES VECTEURS FIGÉS — toujours joués, PHP seul.
 *    Les empreintes ci-dessous ont été relevées le jour où deux
 *    décodeurs indépendants ont confirmé que ces codes se lisaient.
 *    Toute modification du générateur qui change une seule case les
 *    fait tomber.
 *
 *      > Un vecteur figé ne prouve pas qu'un code se scanne ; il prouve
 *      > qu'il n'a pas changé depuis le jour où on l'a prouvé.
 *
 * 2. LA RELECTURE VIVANTE — jouée si un décodeur est installé.
 *    C'est elle, et elle seule, qui prouve qu'un code se scanne.
 *
 * POURQUOI LE MANQUE DE DÉCODEUR NE REND PAS ROUGE
 * =================================================
 * Première version de ce fichier : sans Python, la suite échouait. Elle
 * confondait « le code est faux » avec « l'outil n'est pas installé
 * ici » — et sous Windows, où `python3` n'existe pas, elle échouait
 * chez tout le monde.
 *
 * Passer au vert en silence serait pire encore : ce serait la sonde qui
 * ne prouve rien. On distingue donc trois états, et on les NOMME :
 * vérifié, non vérifiable ici, cassé.
 *
 * POURQUOI DEUX DÉCODEURS, QUAND ILS SONT LÀ
 * ===========================================
 * Mesuré : OpenCV refuse certains codes parfaitement valides — il a
 * échoué sur une URL de vérification, ET sur le code produit pour la
 * même URL par la bibliothèque de référence. zbar lit les deux, comme
 * les téléphones.
 *
 *   > Un seul décodeur ne suffit pas : son échec peut être le sien.
 *
 * Usage : php tests/qrcode_decode.php
 *
 * Pour activer la relecture vivante (facultatif) :
 *   pip install cairosvg opencv-python-headless pyzbar
 *   (Linux : apt-get install libzbar0)
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/core/qrcode.php';

$pass = 0;
$fail = 0;
$skip = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
}

function passer(string $label, string $raison): void
{
    global $skip;
    $skip++;
    printf("  · %s — NON VÉRIFIÉ ICI (%s)\n", $label, $raison);
}

echo "\n  GÉNÉRATEUR DE QR CODE\n";
echo "  ──────────────────────────────────────────────────────\n\n";

// ---------------------------------------------------------------------
//  Les cas éprouvés, et leur empreinte de référence.
//
//  Chaque empreinte est le SHA-256 de la matrice sérialisée, relevée le
//  21/09/2026, jour où OpenCV et zbar ont confirmé que ces huit codes
//  se relisaient à l'identique.
//
//  NE PAS régénérer ces valeurs pour faire passer un test. Si elles
//  tombent, c'est que le générateur a changé : il faut refaire la
//  relecture vivante AVANT de les remplacer.
// ---------------------------------------------------------------------
$cas = [
    'url courte'          => 'https://ec.cd/v/A1B2C3',
    'url de vérification' => 'https://saint-joseph.school-saas.cd/verifier/7QK4-M2XR-9PWD',
    'url longue'          => 'https://institut-technique-de-la-gombe.school-saas.cd/verifier/7QK4-M2XR-9PWD-8LZN',
    'un seul caractère'   => 'A',
    'chiffres seuls'      => '20260921',
    'accents'             => 'École Saint-Joseph — attestation n° 42',
    'apostrophe et tiret' => "Certificat d'élève — 2026-2027",
    'capacité haute'      => str_repeat('X', 140),
];

/** [taille attendue, empreinte de la matrice] */
const QR_VECTEURS = [
    'url courte'          => [29, '40fa7ba00625f4309df64454270c6717db4e71f2deaa025996ba726c4ccf1053'],
    'url de vérification' => [37, 'd0c32eab1cde3834b21307fcf35f4ba62da3a8cb66aba8384c0087415b9a836e'],
    'url longue'          => [45, '10f924cec657310d729e0c8f7770d7efa7f28c016177274d16fb575299c746c1'],
    'un seul caractère'   => [21, 'e2e92e239ab4a585e515a4834b7495a1702be32bfdb90f9e0fc0fc2f94b5153b'],
    'chiffres seuls'      => [21, '6e6a0ba2cd307fe61302415520315446270d586f8c59488c122efbb7957c7953'],
    'accents'             => [33, '146ef6d765ce2244717f2cb56121bf7697b9ad2714bc7309be38020e28227498'],
    'apostrophe et tiret' => [33, '3ab591700710e9de91367a0bf40ec3458ab81e0b5acf25e4aca0607481bd962b'],
    'capacité haute'      => [57, 'be5ee6070a754e7926025fd54bf8ac3f56c2417a1d779e3788c6978d6f0bfb49'],
];

/** L'empreinte d'une matrice, pour comparaison avec un vecteur figé. */
function qr_empreinte(array $matrice): string
{
    $plat = '';

    foreach ($matrice as $ligne) {
        foreach ($ligne as $bit) {
            $plat .= $bit ? '1' : '0';
        }
    }

    return hash('sha256', $plat);
}

// =====================================================================
//  1. LA GÉNÉRATION
// =====================================================================
echo "  LA GÉNÉRATION\n";

$svgs = [];

foreach ($cas as $nom => $texte) {
    try {
        $svgs[$nom] = qr_svg($texte, 8, 4);
    } catch (Throwable $e) {
        check("« {$nom} » se génère", false, $e->getMessage());
    }
}

check('Tous les cas produisent un SVG', count($svgs) === count($cas),
    count($svgs) . ' / ' . count($cas));

$refus = false;

try {
    qr_svg(str_repeat('Y', 400));
} catch (InvalidArgumentException $e) {
    $refus = str_contains($e->getMessage(), 'trop long');
}

check('Un texte au-delà de la capacité est REFUSÉ, pas tronqué', $refus);

// =====================================================================
//  2. LES VECTEURS FIGÉS — toujours, sans aucune dépendance
// =====================================================================
echo "\n  LES VECTEURS FIGÉS (relevés le 21/09/2026, après relecture)\n";

foreach ($cas as $nom => $texte) {
    [$taille, $empreinte] = QR_VECTEURS[$nom];

    $m = qr_matrix($texte);

    check("« {$nom} » est inchangé",
        count($m) === $taille && qr_empreinte($m) === $empreinte,
        count($m) === $taille
            ? 'version ' . ((count($m) - 17) / 4) . ', ' . count($m) . ' modules'
            : 'TAILLE ' . count($m) . ' au lieu de ' . $taille);
}

// =====================================================================
//  3. LA RELECTURE VIVANTE — si un décodeur est là
// =====================================================================
echo "\n  LA RELECTURE PAR DES DÉCODEURS TIERS\n";

/**
 * Trouve l'interpréteur Python, quel que soit le système.
 *
 * `python3` n'existe pas sous Windows, où l'appeler déclenche le
 * raccourci du Microsoft Store et son message « Python was not found ».
 * On essaie donc les trois noms usuels.
 */
function qr_python(): ?string
{
    foreach (['python3', 'python', 'py -3'] as $binaire) {
        $sortie = @shell_exec($binaire . ' -c "print(1)" 2>&1');

        if (is_string($sortie) && trim($sortie) === '1') {
            return $binaire;
        }
    }

    return null;
}

$python = qr_python();

if ($python === null) {
    passer('La relecture par décodeur tiers', 'aucun interpréteur Python trouvé');
    echo "\n";
    echo "    Les vecteurs figés ci-dessus garantissent que le générateur n'a pas\n";
    echo "    changé. Ils ne prouvent PAS qu'un code se scanne — cela se prouve\n";
    echo "    une fois, par relecture, puis se préserve par les vecteurs.\n\n";
    echo "    Pour activer la relecture sur ce poste :\n";
    echo "      pip install cairosvg opencv-python-headless pyzbar\n";
} else {
    $dossier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr_epreuve';

    if (!is_dir($dossier)) {
        mkdir($dossier, 0777, true);
    }

    $manifeste = [];

    foreach ($svgs as $nom => $svg) {
        $fichier = $dossier . DIRECTORY_SEPARATOR
            . preg_replace('/[^a-z0-9]+/i', '_', $nom) . '.svg';
        file_put_contents($fichier, $svg);

        $manifeste[$nom] = ['texte' => $cas[$nom], 'svg' => $fichier];
    }

    $cheminManifeste = $dossier . DIRECTORY_SEPARATOR . 'manifeste.json';
    file_put_contents($cheminManifeste, json_encode($manifeste,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $script = <<<'PY'
import json, sys

manifeste = sys.argv[1]
cas = json.load(open(manifeste, encoding='utf-8'))

try:
    import cairosvg
except Exception as e:
    print(json.dumps({'_erreur': 'cairosvg absent : ' + str(e)})); sys.exit(0)

try:
    import cv2
except Exception:
    cv2 = None

try:
    from pyzbar.pyzbar import decode as zbar_decode
    from PIL import Image
except Exception:
    zbar_decode = None

if cv2 is None and zbar_decode is None:
    print(json.dumps({'_erreur': 'ni opencv ni pyzbar'})); sys.exit(0)

resultats = {}

for nom, p in cas.items():
    png = p['svg'][:-4] + '.png'
    cairosvg.svg2png(url=p['svg'], write_to=png, scale=2)

    lectures = {}

    if cv2 is not None:
        img = cv2.imread(png)
        lectures['opencv'] = cv2.QRCodeDetector().detectAndDecode(img)[0] if img is not None else ''

    if zbar_decode is not None:
        r = zbar_decode(Image.open(png))
        lectures['zbar'] = r[0].data.decode('utf-8') if r else ''

    resultats[nom] = {
        'ok': any(v == p['texte'] for v in lectures.values()),
        'moteurs': {m: (v == p['texte']) for m, v in lectures.items()},
    }

print(json.dumps(resultats, ensure_ascii=False))
PY;

    $cheminScript = $dossier . DIRECTORY_SEPARATOR . 'relire.py';
    file_put_contents($cheminScript, $script);

    $sortie = shell_exec($python . ' ' . escapeshellarg($cheminScript) . ' '
        . escapeshellarg($cheminManifeste) . ' 2>&1');

    $resultats = json_decode((string) $sortie, true);

    if (!is_array($resultats) || isset($resultats['_erreur'])) {
        passer('La relecture par décodeur tiers',
            is_array($resultats)
                ? (string) $resultats['_erreur']
                : 'le script de relecture n\'a pas répondu');

        echo "\n    Pour l'activer :\n";
        echo "      pip install cairosvg opencv-python-headless pyzbar\n";
    } else {
        foreach ($resultats as $nom => $r) {
            $moteurs = (array) ($r['moteurs'] ?? []);
            $qui     = implode(', ', array_keys(array_filter($moteurs)));
            $rates   = implode(', ', array_keys(array_filter(
                $moteurs, static fn ($v): bool => !$v
            )));

            check("« {$nom} » se relit à l'identique", (bool) ($r['ok'] ?? false),
                ($r['ok'] ?? false)
                    ? 'lu par ' . $qui . ($rates !== '' ? ' (' . $rates . ' passe son tour)' : '')
                    : 'ILLISIBLE pour ' . implode(', ', array_keys($moteurs)));
        }
    }
}

// =====================================================================
//  4. CE QUI SE VÉRIFIE SANS RIEN INSTALLER
// =====================================================================
echo "\n  LA FORME DU SVG\n";

$svg = qr_svg('https://ec.cd/v/TEST', 4, 4);
preg_match('/viewBox="0 0 (\d+) /', $svg, $m);
$cote = (int) ($m[1] ?? 0);
$n    = count(qr_matrix('https://ec.cd/v/TEST'));

check('La marge silencieuse de 4 modules est présente',
    $cote === ($n + 8) * 4, $cote . ' unités pour ' . $n . ' modules');

check('Le fond blanc est explicite', str_contains($svg, 'fill="#ffffff"'));

check('Le code porte un équivalent accessible',
    str_contains($svg, 'role="img"') && str_contains($svg, '<title>'));

check('La génération est déterministe',
    qr_svg('https://ec.cd/v/TEST') === qr_svg('https://ec.cd/v/TEST'));

// =====================================================================
echo "\n  ──────────────────────────────────────────────────\n";

if ($skip > 0) {
    printf("  %d test(s) réussi(s), %d échec(s), %d NON VÉRIFIÉ(S) ICI\n\n", $pass, $fail, $skip);
} else {
    printf("  %d test(s) réussi(s), %d échec(s)\n\n", $pass, $fail);
}

// Un contrôle qu'on n'a pas pu jouer n'est pas un échec — mais il n'est
// pas un succès non plus, et la ligne ci-dessus le dit.
exit($fail === 0 ? 0 : 1);
