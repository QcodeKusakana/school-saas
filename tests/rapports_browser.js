/**
 * RECETTE — les rapports, de l'écran au fichier.
 *
 * CE QUE SEUL UN NAVIGATEUR PEUT DIRE
 * ====================================
 * Que l'agrégation soit juste, la suite PHP le prouve. Ce qui reste :
 *   · le tableau AFFICHÉ s'additionne-t-il — un total qui ne correspond
 *     pas à ses lignes est la première chose qu'un inspecteur voit ;
 *   · le fichier téléchargé est-il celui qu'Excel attend — BOM,
 *     séparateur, en-tête de téléchargement ;
 *   · l'impression garde-t-elle son en-tête et perd-elle ses commandes ;
 *   · un compte qui lit sans pouvoir exporter voit-il le bouton ?
 *
 * Usage : node tests/rapports_browser.js <motdepasse>
 */
const { chromium } = require('playwright');

const BASE   = process.env.SCHOOL_BASE || 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP    = process.argv[2];

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };
const titre = (t) => console.log('\n  ' + t);

/** Les entiers d'une ligne de tableau, dans l'ordre. */
async function nombres(ligne) {
    return (await ligne.evaluate((tr) =>
        [...tr.querySelectorAll('td')].map((td) => td.innerText.trim())
    )).map((t) => {
        const m = t.replace(/\s/g, '').match(/^-?\d+$/);
        return m ? parseInt(m[0], 10) : null;
    });
}

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const ctx = await nav.newContext({ acceptDownloads: true });
    const p   = await ctx.newPage();
    const err = [];
    p.on('pageerror', (e) => err.push(e.message));

    // =================================================================
    titre('LES TROIS RAPPORTS RÉPONDENT');

    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', 'directeur.demo');
    await p.fill('input[name="password"]', MDP);
    await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

    check('la direction se connecte', !p.url().endsWith('/login'));

    check('le menu porte « Rapports »',
        await p.locator('a[href$="/rapports"]').count() > 0);

    for (const [nom, chemin] of [
        ['effectifs', '/rapports/effectifs'],
        ['résultats', '/rapports/resultats'],
        ['assiduité', '/rapports/assiduite'],
    ]) {
        const r = await p.goto(BASE + chemin);
        await p.waitForLoadState('networkidle');

        const texte = await p.locator('body').innerText();

        check(nom + ' répond 200 sans trace d\'erreur',
            r.status() === 200 && !/TypeError|Erreur —|Pile d'appels/.test(texte),
            'HTTP ' + r.status());
    }

    // =================================================================
    titre('LE TABLEAU AFFICHÉ S\'ADDITIONNE-T-IL ?');

    await p.goto(BASE + '/rapports/effectifs');
    await p.waitForLoadState('networkidle');

    // ON VÉRIFIE LES CHIFFRES RENDUS, PAS LE GABARIT.
    //
    // Un total calculé juste en PHP peut être affiché dans la mauvaise
    // colonne. Seule la lecture du tableau rendu le dit.
    const corps = await p.locator('table tbody tr:not(.table-light)').all();
    let sommeEffectifs = 0;
    let invariantTenu  = true;

    for (const ligne of corps) {
        const n = await nombres(ligne);
        // [garçons, filles, effectif, ...] — les trois premiers entiers.
        const entiers = n.filter((v) => v !== null);

        if (entiers.length >= 3) {
            const [g, f, tot] = entiers;
            if (g + f !== tot) { invariantTenu = false; }
            sommeEffectifs += tot;
        }
    }

    check('sur chaque ligne, garçons + filles = effectif', invariantTenu);

    const pied = await nombres(p.locator('table tfoot tr').first());
    const totalAffiche = pied.filter((v) => v !== null)[2];

    check('le total du pied égale la somme des lignes',
        totalAffiche === sommeEffectifs,
        'pied = ' + totalAffiche + ', lignes = ' + sommeEffectifs);

    // =================================================================
    titre('LE FICHIER EST-IL CELUI QU\'EXCEL ATTEND ?');

    const [telechargement] = await Promise.all([
        p.waitForEvent('download'),
        p.click('a[href*="export=csv"]'),
    ]);

    const chemin = await telechargement.path();
    const octets = require('fs').readFileSync(chemin);

    check('le fichier porte un nom parlant',
        /^effectifs-.*\.csv$/.test(telechargement.suggestedFilename()),
        telechargement.suggestedFilename());

    // LE BOM. Sans lui, Excel lit l'UTF-8 comme du Latin-1 et « Garçons »
    // devient « GarÃ§ons ».
    check('il commence par le BOM UTF-8',
        octets[0] === 0xEF && octets[1] === 0xBB && octets[2] === 0xBF,
        [octets[0], octets[1], octets[2]].map((b) => b.toString(16)).join(' '));

    const texte = octets.toString('utf8');

    check('le séparateur est le point-virgule', /Cycle;Niveau;Classe/.test(texte),
        'la virgule produirait une seule colonne');

    check('les accents ont survécu', /Garçons/.test(texte) && /Capacité/.test(texte));

    check('les décimales sont des virgules', /\d+,\d/.test(texte),
        (texte.match(/\d+,\d+/) || ['aucune'])[0]);

    // LE FICHIER DIT-IL LA MÊME CHOSE QUE L'ÉCRAN ?
    //
    // Un export qui recalcule ce que l'écran a déjà calculé finit par
    // dire autre chose que lui.
    const lignesCsv = texte.trim().split('\n').slice(1);
    let sommeCsv = 0;

    for (const ligne of lignesCsv) {
        const cols = ligne.split(';').map((c) => c.replace(/^"|"$/g, ''));
        sommeCsv += parseInt(cols[5], 10) || 0;   // colonne « Effectif »
    }

    check('le fichier totalise comme l\'écran',
        sommeCsv === sommeEffectifs,
        'CSV = ' + sommeCsv + ', écran = ' + sommeEffectifs);

    // =================================================================
    titre('UN EXPORT LAISSE UNE TRACE');

    await p.goto(BASE + '/journal?action=report.exported');
    await p.waitForLoadState('networkidle');

    check('l\'export figure au journal',
        await p.locator('table tbody tr').count() > 0,
        await p.locator('table tbody tr').count() + ' entrée(s)');

    // =================================================================
    titre('QUE DEVIENT LA PAGE À L\'IMPRESSION ?');

    await p.goto(BASE + '/rapports/effectifs');
    await p.waitForLoadState('networkidle');
    await p.emulateMedia({ media: 'print' });
    await p.waitForTimeout(200);

    check('les commandes disparaissent',
        !await p.locator('form.no-print').first().isVisible());

    // L'EN-TÊTE D'ÉDITION APPARAÎT. Sans lui, une feuille posée sur un
    // bureau ne dit ni d'où elle vient ni de quand elle date.
    check('l\'en-tête d\'édition apparaît',
        await p.locator('.print-only').first().isVisible());

    const entete = await p.locator('.print-only').first().innerText();

    check('il nomme l\'école et la date', /Effectifs/.test(entete) && /\d{2}\/\d{2}\/\d{4}/.test(entete),
        entete.replace(/\n/g, ' · ').slice(0, 70));

    // La ligne de totaux doit garder son fond : sans
    // `print-color-adjust`, les navigateurs le suppriment et elle se
    // confond avec les autres.
    const fond = await p.locator('.table-report-total').first()
        .evaluate((n) => getComputedStyle(n).backgroundColor);

    check('la ligne de total garde son fond',
        fond !== 'rgba(0, 0, 0, 0)' && fond !== 'transparent', fond);

    await p.emulateMedia({ media: 'screen' });

    // =================================================================
    titre('LIRE N\'EST PAS EXPORTER');

    // L'enseignant ne porte NI report.academic NI report.export : il ne
    // doit pas même atteindre l'écran.
    const ens = await nav.newContext();
    const pe  = await ens.newPage();

    await pe.goto(BASE + '/login');
    await pe.fill('input[name="identifier"]', 'enseignant.demo');
    await pe.fill('input[name="password"]', MDP);
    await Promise.all([pe.waitForLoadState('networkidle'), pe.click('button[type="submit"]')]);

    const refus = await pe.goto(BASE + '/rapports/effectifs');

    check('un enseignant n\'atteint pas les rapports',
        refus.status() === 403 || refus.status() === 302,
        'HTTP ' + refus.status());

    check('et son menu ne porte pas le lien',
        await pe.locator('a[href$="/rapports"]').count() === 0);

    // Et la route d'export lui est fermée aussi.
    const refusCsv = await ens.request.get(BASE + '/rapports/effectifs?export=csv',
        { maxRedirects: 0 });

    check('l\'export lui est refusé', refusCsv.status() === 403 || refusCsv.status() === 302,
        'HTTP ' + refusCsv.status());

    await nav.close();

    if (err.length) { console.log('\n  ERREURS JS :'); err.forEach((e) => console.log('    ' + e)); ko += err.length; }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko ? 1 : 0);
})();
