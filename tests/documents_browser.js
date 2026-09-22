/**
 * RECETTE — la chaîne complète d'un document officiel.
 *
 * CE QUI EST ÉPROUVÉ, ET POURQUOI EN NAVIGATEUR
 * ==============================================
 * On délivre, on imprime, ON LIT LE QR CODE DU DOCUMENT RENDU avec un
 * décodeur tiers, puis on suit l'URL qu'il contient jusqu'à la page
 * publique. C'est la seule façon de prouver que le code imprimé mène
 * vraiment quelque part : un QR qui contient la bonne URL mais qui ne
 * se scanne pas est un QR inutile, et rien en PHP ne le dirait.
 *
 * On vérifie aussi ce que la page publique NE DIT PAS — aucun nom
 * d'élève — en cherchant dans son texte les noms de l'élève concerné.
 *
 * Usage : node tests/documents_browser.js <motdepasse>
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

/**
 * Lit un QR dans un SVG, avec DEUX décodeurs que nous n'avons pas
 * écrits.
 *
 * Deux, parce qu'un seul ne suffit pas : OpenCV refuse certains codes
 * parfaitement valides — y compris ceux produits par la bibliothèque de
 * référence. zbar les lit, comme le font les téléphones.
 *
 *   > Un seul décodeur ne suffit pas : son échec peut être le sien.
 */
/**
 * L'interpréteur Python, quel que soit le système.
 *
 * `python3` n'existe pas sous Windows, où l'appeler déclenche le
 * raccourci du Microsoft Store et son « Python was not found ».
 */
function pythonBinaire() {
    for (const cmd of ['python3', 'python', 'py']) {
        try {
            const args = cmd === 'py' ? ['-3', '-c', 'print(1)'] : ['-c', 'print(1)'];
            if (execFileSync(cmd, args).toString().trim() === '1') {
                return { cmd, prefixe: cmd === 'py' ? ['-3'] : [] };
            }
        } catch (e) { /* on essaie le suivant */ }
    }
    return null;
}

function lireQr(svg) {
    const py3 = pythonBinaire();

    if (!py3) {
        return '__SANS_DECODEUR__';
    }

    fs.writeFileSync('/tmp/doc_qr.svg', svg);

    const py = `
import cairosvg
cairosvg.svg2png(url='/tmp/doc_qr.svg', write_to='/tmp/doc_qr.png', scale=4)

lu = ''
try:
    import cv2
    v, _, _ = cv2.QRCodeDetector().detectAndDecode(cv2.imread('/tmp/doc_qr.png'))
    lu = v or ''
except Exception:
    pass

if not lu:
    try:
        from pyzbar.pyzbar import decode
        from PIL import Image
        r = decode(Image.open('/tmp/doc_qr.png'))
        lu = r[0].data.decode('utf-8') if r else ''
    except Exception:
        pass

print(lu)
`;
    return execFileSync(py3.cmd, [...py3.prefixe, '-c', py]).toString().trim();
}

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const ctx = await nav.newContext();
    const p   = await ctx.newPage();
    const err = [];
    p.on('pageerror', (e) => err.push(e.message));

    // =================================================================
    titre('DÉLIVRER');

    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', 'directeur.demo');
    await p.fill('input[name="password"]', MDP);
    await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

    check('la direction se connecte', !p.url().endsWith('/login'));

    await p.goto(BASE + '/documents');
    await p.waitForLoadState('networkidle');

    check('l\'écran des documents répond', p.url().endsWith('/documents'));

    // On part du dossier de l'élève — le chemin réel du secrétariat.
    await p.goto(BASE + '/eleves');
    await p.waitForLoadState('networkidle');

    // On vise un DOSSIER, pas le bouton « Inscrire un élève » : les
    // deux commencent par /eleves/. Le dossier se reconnaît à son
    // identifiant numérique.
    const href = await p.$$eval('a[href]', (as) => {
        const m = as.map((a) => a.getAttribute('href'))
                    .filter((h) => /\/eleves\/\d+$/.test(h || ''));
        return m[0] || null;
    });

    if (!href) { console.log('  ✗ aucun dossier d\'élève dans la liste'); process.exit(1); }

    await p.goto(BASE + href.replace(/^https?:\/\/[^/]+/, ''));
    await p.waitForLoadState('networkidle');

    const nomEleve = (await p.locator('h1').first().innerText()).trim();

    check('un dossier d\'élève s\'ouvre', nomEleve.length > 0, nomEleve);

    const lienDelivrer = p.locator('a[href*="/documents/delivrer/"]').first();

    check('le dossier porte « Délivrer un document »', await lienDelivrer.count() > 0);

    await Promise.all([p.waitForLoadState('networkidle'), lienDelivrer.click()]);

    const texteChoix = await p.locator('body').innerText();

    check('les quatre natures sont proposées',
        /Attestation de fréquentation/.test(texteChoix)
        && /Certificat de scolarité/.test(texteChoix)
        && /Carte d'élève/.test(texteChoix)
        && /Attestation de paiement/.test(texteChoix));

    // CETTE VÉRIFICATION NE DOIT PAS DÉPENDRE DE L'ÉTAT DU DÉCOR.
    //
    // Première version : elle exigeait que la carte soit refusée faute
    // de photo. Elle passait tant qu'aucun élève n'en avait — puis la
    // recette de la photo en a déposé une, et celle-ci est devenue
    // rouge sur un produit parfaitement sain.
    //
    //   > Une sonde qui dépend de l'état laissé par une autre n'est pas
    //   > indépendante.
    //
    // Le refus lui-même est verrouillé par documents_isolation.php, qui
    // maîtrise son décor. Ici on vérifie ce que seul un navigateur
    // montre : que l'écran reste COHÉRENT dans les deux cas.
    const carteRefusee = /pas de photo/i.test(texteChoix);

    if (carteRefusee) {
        check('la carte refusée mène au dossier pour ajouter la photo',
            await p.locator('a[href*="#bloc-photo"]').count() > 0);
    } else {
        check('la carte, non refusée, est délivrable',
            await p.locator('form:has(input[value="carte_eleve"]) button[type="submit"]')
                .count() > 0);
    }

    // Dans les deux cas, l'aperçu doit être offert — c'est justement
    // quand la délivrance est bloquée qu'on veut voir la mise en page.
    check('chaque nature propose un aperçu',
        await p.locator('a[href*="/documents/apercu/"]').count() === 4,
        await p.locator('a[href*="/documents/apercu/"]').count() + ' lien(s)');

    // =================================================================
    titre('IMPRIMER');

    const bouton = p.locator('form:has(input[value="attestation_frequentation"]) button[type="submit"]');
    await Promise.all([p.waitForLoadState('networkidle'), bouton.click()]);

    check('la délivrance mène directement à l\'impression',
        /\/documents\/\d+\/imprimer$/.test(p.url()), p.url().replace(BASE, ''));

    const doc = await p.locator('body').innerText();

    check('le document porte son numéro', /ATT\/\d{4}-\d{4}\/\d{4}/.test(doc),
        (doc.match(/ATT\/\S+/) || [''])[0]);

    // ON VÉRIFIE LES DONNÉES, PAS LE GABARIT.
    //
    // Première version : elle cherchait « atteste que l'élève » et
    // « année scolaire » — deux phrases du modèle, présentes même quand
    // TOUS les champs sont vides. Et ils l'étaient : une collision de
    // nom de variable avec le moteur de vues faisait rendre des
    // documents entièrement blancs, et cette sonde les déclarait bons.
    //
    //   > Une vérification qui ne regarde que le gabarit ne dit rien
    //   > sur les données.
    //
    // On exige donc le NOM de l'élève tel que son dossier l'affiche.
    const nomFamille = nomEleve.split(/\s+/).filter((m) => m.length > 3)[0] || '';

    check('il nomme RÉELLEMENT l\'élève',
        nomFamille !== '' && doc.toUpperCase().includes(nomFamille.toUpperCase()),
        nomFamille);

    check('il porte une classe et une année, pas des trous',
        /en classe de\s+\S/i.test(doc) && /année scolaire\s+\d{4}/i.test(doc));

    check('il nomme l\'établissement',
        /Je soussigné, Chef d'établissement du\s+\S/i.test(doc));

    check('le bloc de signature reste vide pour la main',
        /signature et sceau/i.test(doc));

    // =================================================================
    titre('LE CODE IMPRIMÉ MÈNE-T-IL QUELQUE PART ?');

    const svg = await p.locator('svg[role="img"]').first().evaluate((n) => n.outerHTML);
    const lu  = lireQr(svg);

    if (lu === '__SANS_DECODEUR__') {
        console.log('  · la relecture du QR — NON VÉRIFIÉE ICI (aucun Python trouvé)');
        console.log('    pip install cairosvg opencv-python-headless pyzbar');
        console.log('\n  Le reste de la recette exige l\'URL du code : arrêt ici.');
        await nav.close();
        process.exit(0);
    }

    check('le QR du document se lit avec un décodeur tiers',
        lu.startsWith('http'), lu);

    // Le code encode la forme COURTE `/v/…` : sept caractères de moins
    // font gagner une version de QR, donc grossissent chaque module sur
    // un support contraint. La forme longue `/verifier/…` reste
    // imprimée en clair à côté, pour qui la saisit.
    check('et il pointe sur la page de vérification',
        /\/(v|verifier)\/[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/.test(lu), lu);

    const numero = (doc.match(/ATT\/\d{4}-\d{4}\/\d{4}/) || [''])[0];

    // =================================================================
    titre('LA PAGE PUBLIQUE — sans compte');

    // Un contexte NEUF, sans cookie : c'est un inconnu qui scanne.
    const anonyme = await nav.newContext();
    const pa      = await anonyme.newPage();

    const chemin = lu.replace(/^https?:\/\/[^/]+/, '');
    await pa.goto(BASE + chemin);
    await pa.waitForLoadState('networkidle');

    const pub = await pa.locator('body').innerText();

    check('elle répond sans authentification', !pa.url().includes('/login'),
        pa.url().replace(BASE, ''));

    check('elle déclare le document authentique', /authentique/i.test(pub));
    check('elle affiche le numéro', pub.includes(numero), numero);
    check('elle nomme l\'établissement', /Complexe Scolaire|École|Institut/i.test(pub));

    // CE QU'ELLE NE DOIT PAS DIRE.
    const morceaux = nomEleve.split(/\s+/).filter((m) => m.length > 3);
    const fuite    = morceaux.filter((m) => pub.toUpperCase().includes(m.toUpperCase()));

    check('elle ne nomme AUCUN élève', fuite.length === 0,
        fuite.length ? 'trouvé : ' + fuite.join(', ') : 'aucun fragment du nom');

    check('elle dit ce qu\'elle ne vérifie pas',
        /Comparez le numéro/i.test(pub));

    check('et pourquoi le nom est tu', /mineur/i.test(pub));

    // Un jeton inventé ne doit rien confirmer.
    await pa.goto(BASE + '/verifier/ZZZZ-ZZZZ-ZZZZ');
    await pa.waitForLoadState('networkidle');

    check('un code inconnu ne confirme rien',
        /Aucun document ne correspond/i.test(await pa.locator('body').innerText()));

    // Un jeton mal formé non plus, et sans erreur serveur.
    await pa.goto(BASE + '/verifier/nimportequoi');
    check('un code mal formé est traité proprement',
        /Aucun document ne correspond/i.test(await pa.locator('body').innerText()));

    // =================================================================
    titre('RÉVOQUER');

    await p.goto(BASE + '/documents');
    await p.waitForLoadState('networkidle');

    await p.locator('button:has-text("Révoquer")').first().click();
    await p.waitForTimeout(400);

    await p.locator('input[name="reason"]').first().fill('inscription annulée après délivrance');
    await Promise.all([
        p.waitForLoadState('networkidle'),
        p.locator('button:has-text("Retirer sa valeur")').first().click(),
    ]);

    check('la révocation est enregistrée',
        /révoqué/i.test(await p.locator('body').innerText()));

    await pa.goto(BASE + chemin);
    await pa.waitForLoadState('networkidle');

    const apres = await pa.locator('body').innerText();

    check('la page publique dit désormais « révoqué »', /révoqué/i.test(apres));
    check('et ne dit plus « authentique »', !/document authentique/i.test(apres));
    check('elle ne nomme toujours aucun élève',
        morceaux.filter((m) => apres.toUpperCase().includes(m.toUpperCase())).length === 0);

    // =================================================================
    titre('CE QUE L\'ÉCOLE SAISIT ARRIVE-T-IL SUR LE PAPIER ?');

    // LE CONTRÔLE QUI MANQUAIT, ET CE QU'IL A LAISSÉ PASSER.
    //
    // Cette recette délivrait, imprimait, scannait, révoquait — et
    // n'avait jamais regardé si la VILLE et la TUTELLE saisies par
    // l'école figuraient sur le document. Elles n'y figuraient pas :
    // le figeage lisait des clés qu'aucune requête ne produisait, et
    // chaque document sortait avec « Fait à , ».
    //
    //   > Une recette qui suit la chaîne sans regarder la donnée
    //   > prouve que la chaîne tourne, pas qu'elle transporte.
    const VILLE    = 'Mbuji-Mayi';
    const TUTELLE  = 'RDC · Ministère de l\'EPST · Province éducationnelle test';

    await p.goto(BASE + '/ecole/parametres');
    await p.waitForLoadState('networkidle');

    check('l\'écran « Mon établissement » est accessible à la direction',
        p.url().includes('/ecole/parametres'));

    await p.fill('input[name="school_city"]', VILLE);
    await p.fill('input[name="school_authority"], textarea[name="school_authority"]', TUTELLE);
    await Promise.all([
        p.waitForLoadState('networkidle'),
        p.locator('form[action="/ecole/parametres"] button[type="submit"]').first().click(),
    ]);

    check('les mentions sont enregistrées',
        (await p.locator('input[name="school_city"]').inputValue()) === VILLE);

    // On délivre un document NEUF, après la saisie.
    await p.goto(BASE + href.replace(/^https?:\/\/[^/]+/, ''));
    await p.waitForLoadState('networkidle');
    await Promise.all([p.waitForLoadState('networkidle'),
        p.locator('a[href*="/documents/delivrer/"]').first().click()]);
    await p.waitForLoadState('networkidle');

    await Promise.all([
        p.waitForLoadState('networkidle'),
        p.locator('form:has(input[value="attestation_frequentation"]) button[type="submit"]')
            .first().click(),
    ]);

    const papier = await p.locator('body').innerText();

    check('le document imprime la ville saisie', papier.includes(VILLE),
        (papier.match(/Fait à[^\n]*/) || ['(aucune mention « Fait à »)'])[0]);

    // `text-transform: uppercase` en tête de document : `innerText()`
    // rend le texte AFFICHÉ, pas le texte source. La comparaison doit
    // donc ignorer la casse, sans quoi elle accuse un produit sain.
    check('et la ligne de tutelle',
        papier.toUpperCase().includes('PROVINCE ÉDUCATIONNELLE TEST'));

    check('plus aucun « Fait à , » vide', !/Fait à\s*,\s*(le)?\s*$/m.test(papier));

    await nav.close();

    if (err.length) { console.log('\n  ERREURS JS :'); err.forEach((e) => console.log('    ' + e)); ko += err.length; }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko ? 1 : 0);
})();
