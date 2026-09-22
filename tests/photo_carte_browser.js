/**
 * RECETTE — la photo de l'élève et l'aperçu spécimen.
 *
 * CE QUI EST ÉPROUVÉ
 * ==================
 *  · le dossier d'élève permet VRAIMENT d'ajouter une photo — le refus
 *    de la carte désignait auparavant une porte qui n'existait pas ;
 *  · la photo est servie par une route AUTHENTIFIÉE, jamais par une URL
 *    publique : un visiteur sans session ne l'obtient pas ;
 *  · la carte l'affiche réellement (première version : un chemin vers
 *    `storage/`, hors racine web, qui aurait fait 404) ;
 *  · l'aperçu se TRAHIT — filigrane, pas de numéro, pas de code — et
 *    ne laisse aucune trace en base.
 *
 * Usage : node tests/photo_carte_browser.js <motdepasse>
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');

const BASE   = process.env.SCHOOL_BASE || 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP    = process.argv[2];

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };
const titre = (t) => console.log('\n  ' + t);

/** Un PNG minuscule mais valide, pour ne pas dépendre d'un fichier du dépôt. */
function fabriquerPhoto(chemin) {
    // 2×2 pixels, écrit octet par octet : aucune bibliothèque requise.
    const png = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAF0lEQVQI12P8//8/'
        + 'AzbAxMDAwMDAwAAAIAUDAUn6iHAAAAAASUVORK5CYII=', 'base64');
    fs.writeFileSync(chemin, png);
    return chemin;
}

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const ctx = await nav.newContext();
    const p   = await ctx.newPage();
    const err = [];
    p.on('pageerror', (e) => err.push(e.message));

    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', 'directeur.demo');
    await p.fill('input[name="password"]', MDP);
    await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

    // --- On prend un élève ------------------------------------------
    await p.goto(BASE + '/eleves');
    await p.waitForLoadState('networkidle');

    const href = await p.$$eval('a[href]', (as) => {
        const m = as.map((a) => a.getAttribute('href'))
                    .filter((h) => /\/eleves\/\d+$/.test(h || ''));
        return m[0] || null;
    });

    if (!href) { console.log('  ✗ aucun dossier d\'élève'); process.exit(1); }

    const idEleve = parseInt(href.match(/(\d+)$/)[1], 10);

    // =================================================================
    titre('LE DOSSIER PERMET-IL VRAIMENT D\'AJOUTER UNE PHOTO ?');

    await p.goto(BASE + '/eleves/' + idEleve);
    await p.waitForLoadState('networkidle');

    check('le dossier porte un bouton « Ajouter une photo »',
        await p.locator('button[data-bs-target="#bloc-photo"]').count() > 0);

    // ON S'ASSURE QUE LE BLOC EST OUVERT — on ne le BASCULE pas.
    //
    // Il s'ouvre de lui-même quand une photo existe déjà. Un clic
    // inconditionnel le refermait donc au second passage, et la sonde
    // échouait sur un produit parfaitement sain.
    //
    //   > Une sonde qui bascule un état au lieu de l'exiger dépend de
    //   > l'ordre dans lequel on la joue.
    if (!await p.locator('input[name="photo"]').isVisible()) {
        await p.locator('button[data-bs-target="#bloc-photo"]').first().click();
        await p.waitForTimeout(500);
    }

    check('il contient un champ de fichier',
        await p.locator('input[type="file"][name="photo"]').isVisible());

    check('et il dit que la photo n\'a pas d\'adresse publique',
        /pas d'adresse publique/i.test(await p.locator('#bloc-photo').innerText()));

    // On dépose une photo.
    const fichier = fabriquerPhoto('/tmp/photo_test.png');

    await p.locator('input[name="photo"]').setInputFiles(fichier);
    await Promise.all([
        p.waitForLoadState('networkidle'),
        p.locator('form[action*="/photo"] button[type="submit"]').first().click(),
    ]);

    check('la photo s\'enregistre',
        /Photo enregistrée/i.test(await p.locator('body').innerText()));

    // =================================================================
    titre('LA PHOTO EST-ELLE SERVIE — ET SEULEMENT AUX AYANTS DROIT ?');

    const rep = await p.request.get(BASE + '/eleves/' + idEleve + '/photo');

    check('elle est servie à un compte autorisé',
        rep.status() === 200 && (rep.headers()['content-type'] || '').startsWith('image/'),
        'HTTP ' + rep.status() + ' ' + (rep.headers()['content-type'] || ''));

    check('le cache la déclare privée',
        /private/.test(rep.headers()['cache-control'] || ''),
        rep.headers()['cache-control'] || 'absent');

    // Un inconnu, sans session.
    const anonyme = await nav.newContext();
    const repAnon = await anonyme.request.get(BASE + '/eleves/' + idEleve + '/photo',
        { maxRedirects: 0 });

    check('un visiteur SANS SESSION ne l\'obtient pas',
        repAnon.status() !== 200
        || !(repAnon.headers()['content-type'] || '').startsWith('image/'),
        'HTTP ' + repAnon.status());

    // Et le fichier n'est pas joignable par son chemin de stockage.
    const repDirect = await anonyme.request.get(BASE + '/storage/uploads/');

    check('le dossier de stockage n\'est pas exposé', repDirect.status() !== 200,
        'HTTP ' + repDirect.status());

    // =================================================================
    titre('LA CARTE AFFICHE-T-ELLE LA PHOTO ?');

    await p.goto(BASE + '/eleves/' + idEleve);
    await p.waitForLoadState('networkidle');

    const lienDelivrer = await p.locator('a[href*="/documents/delivrer/"]').first()
        .getAttribute('href');
    const inscription = parseInt(lienDelivrer.match(/(\d+)$/)[1], 10);

    await p.goto(BASE + '/documents/delivrer/' + inscription);
    await p.waitForLoadState('networkidle');

    const choix = await p.locator('body').innerText();

    check('la carte n\'est plus refusée', !/pas de photo/i.test(choix));

    const formCarte = p.locator('form:has(input[value="carte_eleve"]) button[type="submit"]');
    await Promise.all([p.waitForLoadState('networkidle'), formCarte.click()]);

    check('la carte se délivre', /\/documents\/\d+\/imprimer$/.test(p.url()),
        p.url().replace(BASE, ''));

    const img = p.locator('img.carte-photo');

    check('la carte contient bien une image', await img.count() > 0);

    // LA VÉRIFICATION QUI COMPTE : l'image a-t-elle CHARGÉ ?
    const chargee = await img.first().evaluate((n) => n.complete && n.naturalWidth > 0);

    check('et cette image CHARGE vraiment', chargee,
        chargee ? '' : 'naturalWidth = 0 — le chemin ne mène nulle part');

    check('elle passe par la route contrôlée, pas par un fichier',
        /\/eleves\/\d+\/photo$/.test(await img.first().getAttribute('src')),
        await img.first().getAttribute('src'));

    // =================================================================
    titre('L\'APERÇU SE TRAHIT-IL ?');

    await p.goto(BASE + '/documents/apercu/' + inscription + '?type=attestation_frequentation');
    await p.waitForLoadState('networkidle');

    const apercu = await p.locator('body').innerText();

    check('l\'aperçu répond', !p.url().includes('/documents?'), p.url().replace(BASE, ''));
    check('il porte un filigrane SPÉCIMEN',
        await p.locator('.filigrane').count() > 0);
    check('il annonce n\'avoir aucune valeur', /aucune valeur/i.test(apercu));
    check('il ne porte AUCUN numéro', /SANS VALEUR/.test(apercu) && !/ATT\/\d{4}/.test(apercu));
    check('il ne porte AUCUN code de vérification',
        await p.locator('svg[role="img"]').count() === 0);
    check('et il dit où le code apparaîtrait',
        /n'apparaît que sur un document réellement délivré/i.test(apercu));

    // Le texte du document est bien là — c'est le but de l'aperçu.
    check('mais le corps du document est bien rendu',
        /atteste que l'élève/i.test(apercu));

    // --- L'aperçu de la carte, même sans photo ----------------------
    await p.goto(BASE + '/documents/apercu/' + inscription + '?type=carte_eleve');
    await p.waitForLoadState('networkidle');

    check('l\'aperçu de la carte s\'affiche',
        await p.locator('.carte').count() > 0);

    check('avec son filigrane', await p.locator('.filigrane').count() > 0);

    // =================================================================
    titre('L\'APERÇU LAISSE-T-IL UNE TRACE ?');

    // C'est la promesse centrale du spécimen : rien n'est écrit, aucun
    // numéro n'est consommé. On la mesure sur le registre lui-même.
    const compter = async () => {
        await p.goto(BASE + '/documents');
        await p.waitForLoadState('networkidle');
        const t = await p.locator('body').innerText();
        return (t.match(/(\d+) document\(s\) délivré/) || [0, '0'])[1];
    };

    const avant = await compter();

    for (let i = 0; i < 6; i++) {
        await p.goto(BASE + '/documents/apercu/' + inscription + '?type=attestation_frequentation');
        await p.goto(BASE + '/documents/apercu/' + inscription + '?type=carte_eleve');
    }

    const apres = await compter();

    check('douze aperçus n\'ajoutent aucun document', avant === apres,
        avant + ' avant, ' + apres + ' après');

    await nav.close();

    if (err.length) { console.log('\n  ERREURS JS :'); err.forEach((e) => console.log('    ' + e)); ko += err.length; }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko ? 1 : 0);
})();
