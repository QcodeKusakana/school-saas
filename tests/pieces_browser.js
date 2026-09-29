/**
 * RECETTE — les pièces du dossier, vues de l'écran (11D).
 *
 * CE QUE SEUL UN NAVIGATEUR PEUT DIRE
 * ====================================
 *   · qu'un vrai téléversement passe — `upload_store()` exige
 *     `is_uploaded_file()`, qu'aucun test PHP ne peut satisfaire ;
 *   · quels EN-TÊTES accompagnent le fichier servi : `attachment`,
 *     `nosniff`, `no-store`. Un PDF rendu dans l'onglet exécute son
 *     JavaScript dans plusieurs lecteurs, et hérite alors de la session
 *     de qui l'ouvre ;
 *   · qu'un enseignant ne voit pas le bloc, et que l'adresse directe
 *     lui répond 403.
 *
 * CETTE RECETTE RANGE UNE PIÈCE ET LA RETIRE AVANT DE FINIR.
 *
 * Usage : node tests/pieces_browser.js <motdepasse>
 */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP = process.argv[2];
const fs = require('fs');

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };
const titre = (t) => console.log('\n  ' + t);

const session = async (nav, ident, err) => {
    const p = await (await nav.newContext()).newPage();
    p.on('pageerror', (e) => err.push(ident + ' : ' + e.message));
    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', ident);
    await p.fill('input[name="password"]', MDP);
    await Promise.all([p.waitForLoadState('networkidle'),
        p.locator('form').filter({ has: p.locator('input[name="identifier"]') })
            .locator('button[type="submit"]').click()]);
    return p;
};

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const err = [];

    // Un PDF minimal, mais VALIDE : le produit lit le contenu, pas
    // l'extension. Un fichier bidon serait refusé — à raison.
    const pdf = '/tmp/claude-0/-home-claude/80c5be58-33c6-5fc5-9d09-7b047e095ce8/scratchpad/acte.pdf';
    fs.writeFileSync(pdf, Buffer.from(
        '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n' +
        '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n' +
        '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n' +
        'trailer<</Root 1 0 R>>\n%%EOF\n', 'latin1'));

    // Un faux PDF : du PHP déguisé. Le produit doit le refuser.
    const faux = '/tmp/claude-0/-home-claude/80c5be58-33c6-5fc5-9d09-7b047e095ce8/scratchpad/piege.pdf';
    fs.writeFileSync(faux, '<?php system($_GET["c"]); ?>');

    try {
        titre('LE SECRÉTARIAT RANGE UNE PIÈCE');

        const p = await session(nav, 'admin.demo', err);
        await p.goto(BASE + '/eleves/1');

        check('la fiche élève s\'ouvre', p.url().includes('/eleves/1'), p.url());
        check('le bloc « Pièces du dossier » est là',
            (await p.locator('body').innerText()).includes('Pièces du dossier'));

        // OUVRIR LE BLOC D'ABORD, toujours : il est replié dès qu'une
        // pièce existe. Une sonde qui suppose l'état initial ne mesure
        // que le premier passage.
        const ouvrir = async () => {
            if (!(await p.locator('#piece-fichier').isVisible().catch(() => false))) {
                await p.locator('button[data-bs-target="#bloc-piece"]').click();
                await p.waitForTimeout(400);
            }
        };

        await ouvrir();
        await p.locator('#piece-fichier').setInputFiles(pdf);
        await p.selectOption('#piece-type', 'acte_naissance');
        await p.fill('#piece-label', 'Acte n° 1234/2018, commune de Gombe');

        await Promise.all([p.waitForLoadState('networkidle'),
            p.locator('form[action$="/pieces"] button[type="submit"]').click()]);

        let corps = await p.locator('body').innerText();
        check('la pièce est rangée', /ajoutée au dossier/.test(corps),
            corps.split('\n').find((l) => /ajoutée|refus|autoris/.test(l)) || '');
        check('… et apparaît dans la liste', /Acte n° 1234\/2018/.test(corps));

        titre('LE PRODUIT REFUSE CE QUI N\'EST PAS UNE PIÈCE');

        // Le formulaire se replie dès qu'une pièce existe : on vient
        // d'abord pour les voir. La sonde ouvre le bloc, comme un
        // utilisateur.
        await ouvrir();
        await p.locator('#piece-fichier').setInputFiles(faux);
        await p.selectOption('#piece-type', 'autre');
        await Promise.all([p.waitForLoadState('networkidle'),
            p.locator('form[action$="/pieces"] button[type="submit"]').click()]);

        corps = await p.locator('body').innerText();
        check('un script PHP renommé .pdf est refusé',
            !/ajoutée au dossier/.test(corps),
            corps.split('\n').find((l) => /correspond|autoris|refus/.test(l)) || '');

        titre('LA PIÈCE SE TÉLÉCHARGE, EN PIÈCE JOINTE');

        const lien = p.locator('a[href*="/pieces/"]').first();
        const href = await lien.getAttribute('href');

        const r = await p.request.get(BASE + href);
        check('le fichier est servi', r.status() === 200, 'HTTP ' + r.status());
        check('… en pièce jointe, jamais en ligne',
            /attachment/.test(r.headers()['content-disposition'] || ''),
            r.headers()['content-disposition'] || 'aucun en-tête');
        check('… avec nosniff', (r.headers()['x-content-type-options'] || '') === 'nosniff');
        check('… hors de tout cache partagé',
            /no-store/.test(r.headers()['cache-control'] || ''),
            r.headers()['cache-control'] || '');
        check('… et le contenu est bien le PDF',
            (await r.body()).subarray(0, 4).toString() === '%PDF');

        titre('CE QUE VOIT UN ENSEIGNANT');

        const ens = await session(nav, 'enseignant.demo', err);
        await ens.goto(BASE + '/eleves/1');

        const vuEns = await ens.locator('body').innerText();
        check('il n\'a pas le bloc des pièces', !/Pièces du dossier/.test(vuEns),
            'ce sont des pièces de mineurs');

        const refus = await ens.request.get(BASE + href);
        check('… et l\'adresse directe lui est refusée', refus.status() === 403,
            'HTTP ' + refus.status());

        titre('LE RETRAIT EFFACE LE FICHIER');

        await p.goto(BASE + '/eleves/1');
        const idPiece = href.split('/').pop();

        await p.locator('button[data-bs-target="#retrait-' + idPiece + '"]').click();
        await p.waitForTimeout(400);

        // Sans motif d'abord.
        await Promise.all([p.waitForLoadState('networkidle'),
            p.locator('form[action$="/pieces/' + idPiece + '/retirer"] button[type="submit"]')
                .click()]).catch(() => {});

        await p.goto(BASE + '/eleves/1');
        await p.locator('button[data-bs-target="#retrait-' + idPiece + '"]').click();
        await p.waitForTimeout(300);
        await p.fill('#motif-' + idPiece, 'Sonde 11D : verification du retrait');

        await Promise.all([p.waitForLoadState('networkidle'),
            p.locator('form[action$="/pieces/' + idPiece + '/retirer"] button[type="submit"]').click()]);

        corps = await p.locator('body').innerText();
        check('la pièce est retirée', /retirée du dossier/.test(corps),
            corps.split('\n').find((l) => /retir/.test(l)) || '');

        const apres = await p.request.get(BASE + href);
        check('… et son adresse ne rend plus rien', apres.status() === 404,
            'HTTP ' + apres.status());

        check('aucune erreur JavaScript', err.length === 0, err.slice(0, 2).join(' | '));
    } catch (e) {
        check('la sonde va jusqu\'au bout', false, e.message);
    } finally {
        await nav.close();
    }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko > 0 ? 1 : 0);
})();
