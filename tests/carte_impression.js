/**
 * RECETTE — la carte d'élève, à la taille où elle sera imprimée.
 *
 * CE QUE SEULE UNE MESURE PEUT DIRE
 * ==================================
 * Un QR code « présent » n'est pas un QR code lisible. Sa lisibilité
 * dépend de la taille d'un module sur le papier, et rien dans le HTML
 * ne la donne : il faut rendre à la résolution d'impression, puis
 * essayer de lire.
 *
 * Mesuré sur la première version : le code, logé dans le pied de carte
 * sur 13 mm, faisait 0,32 mm par module. zbar y parvenait, OpenCV non.
 * Sur du papier, avec un téléphone et la lumière d'un bureau d'école,
 * c'est trop juste. Remonté dans le corps, il dispose de 17 mm — et
 * les DEUX décodeurs le lisent.
 *
 *   > Un code présent n'est pas un code lisible ; seule la taille
 *   > imprimée en décide.
 *
 * ON ÉMULE AUSSI LE MÉDIA D'IMPRESSION. C'est là que les navigateurs
 * suppriment les fonds : sans `print-color-adjust: exact`, le bandeau
 * et le filigrane disparaîtraient au moment précis où ils servent.
 *
 * Usage : node tests/carte_impression.js <motdepasse>
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

const BASE   = process.env.SCHOOL_BASE || 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP    = process.argv[2];

/** 300 ppp : 96 dpi CSS × 3,125. */
const ECHELLE_IMPRESSION = 3.125;
/** 1 unité CSS = 1/96 pouce. */
const MM_PAR_UNITE = 25.4 / 96;

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };
const titre = (t) => console.log('\n  ' + t);

function python() {
    for (const cmd of ['python3', 'python', 'py']) {
        try {
            const a = cmd === 'py' ? ['-3', '-c', 'print(1)'] : ['-c', 'print(1)'];
            if (execFileSync(cmd, a).toString().trim() === '1') {
                return { cmd, pre: cmd === 'py' ? ['-3'] : [] };
            }
        } catch (e) { /* suivant */ }
    }
    return null;
}

/** Ce que chaque décodeur lit dans une image. @returns {object} */
function decoder(png) {
    const py = python();

    if (!py) { return null; }

    const script = `
import json
r = {}
try:
    import cv2
    v, _, _ = cv2.QRCodeDetector().detectAndDecode(cv2.imread(${JSON.stringify(png)}))
    r['opencv'] = v or ''
except Exception as e:
    r['opencv'] = None
try:
    from pyzbar.pyzbar import decode
    from PIL import Image
    d = decode(Image.open(${JSON.stringify(png)}))
    r['zbar'] = d[0].data.decode('utf-8') if d else ''
except Exception:
    r['zbar'] = None
print(json.dumps(r))
`;
    try {
        return JSON.parse(execFileSync(py.cmd, [...py.pre, '-c', script]).toString());
    } catch (e) {
        return null;
    }
}

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const ctx = await nav.newContext({ deviceScaleFactor: ECHELLE_IMPRESSION });
    const p   = await ctx.newPage();
    const err = [];
    p.on('pageerror', (e) => err.push(e.message));

    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', 'directeur.demo');
    await p.fill('input[name="password"]', MDP);
    await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

    // --- On délivre une carte ---------------------------------------
    await p.goto(BASE + '/eleves');
    await p.waitForLoadState('networkidle');

    const href = await p.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href'))
        .filter((h) => /\/eleves\/\d+$/.test(h || ''))[0] || null);

    await p.goto(BASE + href.replace(/^https?:\/\/[^/]+/, ''));
    await p.waitForLoadState('networkidle');

    const lien = await p.locator('a[href*="/documents/delivrer/"]').first().getAttribute('href');
    const insc = parseInt(lien.match(/(\d+)$/)[1], 10);

    await p.goto(BASE + '/documents/delivrer/' + insc);
    await p.waitForLoadState('networkidle');

    const bouton = p.locator('form:has(input[value="carte_eleve"]) button[type="submit"]');

    if (await bouton.count() === 0) {
        console.log('  · carte refusée (pas de photo) — jouez d\'abord photo_carte_browser.js');
        await nav.close();
        process.exit(0);
    }

    await Promise.all([p.waitForLoadState('networkidle'), bouton.click()]);

    // =================================================================
    titre('LA CARTE A-T-ELLE LE BON FORMAT ?');

    const carte = await p.locator('.carte').boundingBox();
    const lmm = carte.width * MM_PAR_UNITE;
    const hmm = carte.height * MM_PAR_UNITE;

    // ISO/IEC 7810 ID-1 : 85,60 × 53,98 mm. C'est le format des
    // pochettes plastiques du commerce ; s'en écarter rendrait la carte
    // inutilisable avec les accessoires que l'école achètera.
    check('format ISO ID-1 (85,6 × 54 mm)',
        Math.abs(lmm - 85.6) < 1 && Math.abs(hmm - 54) < 1,
        lmm.toFixed(1) + ' × ' + hmm.toFixed(1) + ' mm');

    // =================================================================
    titre('LE CODE EST-IL ASSEZ GRAND POUR ÊTRE LU ?');

    const qr = await p.locator('.carte-qr svg').boundingBox();

    check('le code est présent', qr !== null);

    check('il tient DANS la carte',
        qr.x >= carte.x - 1 && qr.y >= carte.y - 1
        && qr.x + qr.width <= carte.x + carte.width + 1
        && qr.y + qr.height <= carte.y + carte.height + 1);

    // LE viewBox INCLUT DÉJÀ LA MARGE SILENCIEUSE.
    //
    // Première version : elle rajoutait 8 modules à un total qui les
    // contenait, et concluait 0,347 mm là où la carte en offrait 0,415.
    // Une mesure fausse accuse un produit sain.
    //
    // `qr_svg($url, 3, 4)` : chaque module vaut 3 unités de viewBox, et
    // les 4 modules de marge de chaque côté y sont compris.
    const totalModules = await p.locator('.carte-qr svg').evaluate(
        (n) => parseInt(n.getAttribute('viewBox').split(' ')[2], 10) / 3
    );
    const qrMm      = qr.width * MM_PAR_UNITE;
    const parModule = qrMm / totalModules;

    // 0,33 mm est le plancher pratique des lecteurs de téléphone sur du
    // papier. La valeur exacte dépend de la LONGUEUR DE L'URL, donc du
    // nom de domaine de l'école : un domaine long pousse le code à la
    // version supérieure et réduit la taille du module. C'est pourquoi
    // le code pointe sur /v/… et non sur /verifier/….
    check('chaque module fait au moins 0,33 mm', parModule >= 0.33,
        qrMm.toFixed(1) + ' mm pour ' + totalModules + ' modules (marge comprise) → '
        + parModule.toFixed(3) + ' mm/module');

    // =================================================================
    titre('SE LIT-IL VRAIMENT, À 300 PPP ?');

    await p.locator('.carte').screenshot({ path: '/tmp/carte_recette.png' });

    const lu = decoder('/tmp/carte_recette.png');

    if (lu === null) {
        console.log('  · relecture — NON VÉRIFIÉE ICI (aucun décodeur)');
        console.log('    pip install opencv-python-headless pyzbar');
    } else {
        for (const [moteur, valeur] of Object.entries(lu)) {
            if (valeur === null) {
                console.log('  · ' + moteur + ' — absent de ce poste');
                continue;
            }

            // Le code encode la forme COURTE (/v/…) ; la forme longue
            // (/verifier/…) est imprimée en clair à côté. Les deux
            // mènent à la même page.
            check(moteur + ' lit le code sur la carte rendue',
                /^https?:\/\/\S+\/(v|verifier)\/[0-9A-Z-]+$/.test(valeur),
                valeur || 'ILLISIBLE');
        }
    }

    // =================================================================
    titre('QUE DEVIENT-ELLE À L\'IMPRESSION ?');

    await p.emulateMedia({ media: 'print' });
    await p.waitForTimeout(300);

    const fond = await p.locator('.carte-tete').evaluate((n) => getComputedStyle(n).backgroundColor);

    check('le bandeau garde sa couleur', fond !== 'rgba(0, 0, 0, 0)' && fond !== 'transparent',
        fond);

    check('les boutons disparaissent',
        !await p.locator('.no-print').first().isVisible());

    await p.locator('.carte').screenshot({ path: '/tmp/carte_recette_print.png' });

    const luPrint = decoder('/tmp/carte_recette_print.png');

    if (luPrint !== null) {
        const bons = Object.values(luPrint).filter(
            (v) => v && (v.includes('/v/') || v.includes('/verifier/'))
        );

        check('le code reste lisible en média « print »', bons.length > 0,
            bons.length + ' décodeur(s) sur ' + Object.keys(luPrint).length);
    }

    // =================================================================
    titre('LE FILIGRANE ET LE LOGO');

    // LES DEUX FORMES D'URL MÈNENT-ELLES AU MÊME ENDROIT ?
    // Une forme courte qui ne répondrait pas rendrait tous les codes
    // imprimés inutiles, sans que rien ne le signale.
    const jeton = (Object.values(lu || {}).find((v) => v && v.includes('/')) || '')
        .split('/').pop();

    if (jeton) {
        const anon = await nav.newContext();

        for (const forme of ['/v/', '/verifier/']) {
            const r = await anon.request.get(BASE + forme + jeton);
            const t = await r.text();

            check('la forme ' + forme + '… confirme le document',
                r.status() === 200 && /authentique/i.test(t), 'HTTP ' + r.status());
        }
    }

    check('le filigrane porte le nom de l\'école',
        (await p.locator('.carte-filigrane').innerText()).trim().length > 2,
        (await p.locator('.carte-filigrane').innerText()).trim());

    const debord = await p.locator('.carte-filigrane span').evaluate((n) => {
        const s = n.getBoundingClientRect();
        const c = n.closest('.carte').getBoundingClientRect();
        // La diagonale d'une carte : le filigrane peut la frôler, pas
        // la dépasser largement, sinon le nom est tronqué à l'écran.
        return s.width / Math.hypot(c.width, c.height);
    });

    check('il tient dans la diagonale, sans être tronqué', debord <= 1.0,
        (debord * 100).toFixed(0) + ' % de la diagonale');

    // Le logo n'est pas obligatoire : toutes les écoles n'en ont pas.
    const logo = await p.locator('.carte-logo').count();
    console.log('  · logo sur la carte : ' + (logo > 0 ? 'présent' : 'absent (facultatif)'));

    await nav.close();

    if (err.length) { console.log('\n  ERREURS JS :'); err.forEach((e) => console.log('    ' + e)); ko += err.length; }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko ? 1 : 0);
})();
