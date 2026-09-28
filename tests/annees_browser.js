/**
 * RECETTE — l'année scolaire, de la création à la réouverture.
 *
 * CE QUE SEUL UN NAVIGATEUR PEUT DIRE
 * ====================================
 * Que les règles tiennent, la suite PHP le prouve. Ce qui reste :
 *   · l'écran dit-il, AVANT de laisser clôturer, ce que la clôture fige ;
 *   · le refus de clôturer l'année courante est-il lisible, ou faut-il
 *     le deviner ;
 *   · le motif de réouverture est-il vraiment exigé, côté serveur et pas
 *     seulement par l'attribut `minlength` du navigateur ;
 *   · un compte qui consulte sans pouvoir gérer voit-il les boutons ?
 *
 * CETTE RECETTE MODIFIE L'ANNÉE COURANTE DU DÉCOR.
 * Elle la rend donc à son état initial avant de finir — sept autres
 * recettes en dépendent, et une sonde qui laisse le décor déplacé casse
 * celles qui suivent.
 *
 * Usage : node tests/annees_browser.js <motdepasse>
 */
const { chromium } = require('playwright');

const BASE   = process.env.SCHOOL_BASE || 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP    = process.argv[2];

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };
const titre = (t) => console.log('\n  ' + t);

/** Le jeton CSRF de la page courante. */
async function jeton(p) {
    return p.locator('input[name="_token"]').first().getAttribute('value');
}

/** La ligne du tableau portant ce code d'année. */
function ligne(p, code) {
    return p.locator('table tbody tr').filter({ hasText: code }).first();
}

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const ctx = await nav.newContext();
    const p   = await ctx.newPage();
    const err = [];
    p.on('pageerror', (e) => err.push(e.message));

    const NOUVELLE = '2099-2100';
    let courantInitial = null;

    try {
        // =============================================================
        titre('L\'ÉCRAN S\'OUVRE');

        await p.goto(BASE + '/login');
        await p.fill('input[name="identifier"]', 'directeur.demo');
        await p.fill('input[name="password"]', MDP);
        await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

        check('la direction se connecte', !p.url().endsWith('/login'));

        check('le menu porte « Années scolaires »',
            await p.locator('a[href$="/annees"]').count() > 0);

        const r = await p.goto(BASE + '/annees');
        await p.waitForLoadState('networkidle');

        check('l\'écran répond 200 sans trace d\'erreur',
            r.status() === 200
            && !/TypeError|Erreur —|Pile d'appels/.test(await p.locator('body').innerText()),
            'HTTP ' + r.status());

        courantInitial = (await p.locator('table tbody tr:has(.text-bg-success)').first()
            .locator('strong').first().innerText()).trim();

        check('une année est désignée courante', courantInitial.length > 0, courantInitial);

        // =============================================================
        titre('CRÉER UNE ANNÉE');

        await p.fill('input[name="code"]', NOUVELLE);
        await p.fill('input[name="name"]', 'Année de recette ' + NOUVELLE);
        await p.fill('input[name="starts_on"]', '2099-09-01');
        await p.fill('input[name="ends_on"]', '2100-07-31');
        await Promise.all([
            p.waitForLoadState('networkidle'),
            p.locator('form[action$="/annees"] button').first().click(),
        ]);

        check('l\'année est créée', await ligne(p, NOUVELLE).count() > 0);

        check('…en PRÉPARATION, pas courante',
            /Préparation/.test(await ligne(p, NOUVELLE).innerText()),
            (await ligne(p, NOUVELLE).innerText()).replace(/\n/g, ' · ').slice(0, 60));

        // Le chevauchement doit être refusé AVEC UN MESSAGE, pas
        // silencieusement.
        await p.fill('input[name="code"]', 'CHEVAUCHE');
        await p.fill('input[name="name"]', 'Chevauchante');
        await p.fill('input[name="starts_on"]', '2099-11-01');
        await p.fill('input[name="ends_on"]', '2100-02-28');
        await Promise.all([
            p.waitForLoadState('networkidle'),
            p.locator('form[action$="/annees"] button').first().click(),
        ]);

        check('un chevauchement est refusé, et l\'écran le dit',
            /chevauche/i.test(await p.locator('body').innerText()));

        check('…et l\'année n\'a pas été créée',
            await ligne(p, 'CHEVAUCHE').count() === 0);

        // =============================================================
        titre('L\'ÉCRAN DIT CE QUE LA CLÔTURE FIGE');

        const idCourante = await p.locator('table tbody tr:has(.text-bg-success)')
            .first().locator('a[href*="detail="]').first().getAttribute('href');

        await p.goto(BASE + idCourante.replace(/^https?:\/\/[^/]+/, ''));
        await p.waitForLoadState('networkidle');

        const compteRendu = await p.locator('body').innerText();

        // ON VÉRIFIE LES CHIFFRES, PAS LE GABARIT.
        for (const attendu of ['Élèves inscrits', 'Bulletins sans décision',
                               'Cotes manquantes', 'Dossiers avec impayés']) {
            check('le compte rendu annonce « ' + attendu + ' »',
                compteRendu.includes(attendu));
        }

        check('il annonce ce que la clôture interdit',
            /interdit toute écriture/i.test(compteRendu));

        // LE REFUS DOIT ÊTRE LISIBLE, pas deviné.
        check('clôturer l\'année COURANTE est refusé à l\'écran',
            /année courante/i.test(compteRendu)
            && await p.locator('form[action$="/cloturer"]').count() === 0);

        // =============================================================
        titre('DÉSIGNER, PUIS CLÔTURER');

        await Promise.all([
            p.waitForLoadState('networkidle'),
            ligne(p, NOUVELLE).locator('button:has-text("Désigner courante")').click(),
        ]);

        check('la nouvelle année devient courante',
            /Année courante/.test(await ligne(p, NOUVELLE).innerText()));

        check('…et l\'ancienne ne l\'est plus',
            !/Année courante/.test(await ligne(p, courantInitial).innerText()));

        await Promise.all([
            p.waitForLoadState('networkidle'),
            ligne(p, courantInitial).locator('a:has-text("Préparer la clôture")').click(),
        ]);

        check('le bouton de clôture apparaît maintenant',
            await p.locator('form[action$="/cloturer"]').count() > 0);

        p.on('dialog', (d) => d.accept());

        await Promise.all([
            p.waitForLoadState('networkidle'),
            p.locator('form[action$="/cloturer"] button').click(),
        ]);

        check('l\'année est clôturée',
            /Clôturée/.test(await ligne(p, courantInitial).innerText()),
            (await ligne(p, courantInitial).innerText()).replace(/\n/g, ' · ').slice(0, 60));

        check('et l\'écran confirme que la lecture reste ouverte',
            /consultation reste ouverte/i.test(await p.locator('body').innerText()));

        // =============================================================
        titre('LA RÉOUVERTURE EXIGE UN MOTIF, CÔTÉ SERVEUR');

        await Promise.all([
            p.waitForLoadState('networkidle'),
            ligne(p, courantInitial).locator('a:has-text("Rouvrir")').click(),
        ]);

        check('le formulaire de réouverture demande un motif',
            await p.locator('input[name="motif"]').count() > 0);

        // L'ATTRIBUT `minlength` NE PROTÈGE RIEN : un client qui ne
        // l'applique pas enverrait le formulaire vide. On contourne donc
        // le navigateur et on poste directement.
        const action = await p.locator('form[action$="/rouvrir"]').getAttribute('action');
        const t = await jeton(p);

        const vide = await ctx.request.post(BASE + action.replace(/^https?:\/\/[^/]+/, ''), {
            form: { motif: '', _token: t }, maxRedirects: 0,
        });

        check('un motif vide posté directement est refusé',
            vide.status() === 302 || vide.status() === 403, 'HTTP ' + vide.status());

        await p.goto(BASE + '/annees');
        await p.waitForLoadState('networkidle');

        check('…et l\'année est TOUJOURS clôturée',
            /Clôturée/.test(await ligne(p, courantInitial).innerText()));

        await Promise.all([
            p.waitForLoadState('networkidle'),
            ligne(p, courantInitial).locator('a:has-text("Rouvrir")').click(),
        ]);

        await p.fill('input[name="motif"]', 'Recette automatisée : vérification du cycle de vie');
        await Promise.all([
            p.waitForLoadState('networkidle'),
            p.locator('form[action$="/rouvrir"] button').click(),
        ]);

        check('avec un motif, la réouverture passe',
            /En cours/.test(await ligne(p, courantInitial).innerText()),
            (await ligne(p, courantInitial).innerText()).replace(/\n/g, ' · ').slice(0, 50));

        // LA TRACE. Une réouverture qui ne se journalise pas est
        // indiscernable d'une année jamais clôturée.
        await p.goto(BASE + '/journal?action=academic_year.reopened');
        await p.waitForLoadState('networkidle');

        check('la réouverture figure au journal',
            await p.locator('table tbody tr').count() > 0);

        await Promise.all([
            p.waitForLoadState('networkidle'),
            p.locator('a[href*="/journal/"]').first().click(),
        ]);

        check('…avec son motif',
            /Recette automatisée/.test(await p.locator('body').innerText()));

        // =============================================================
        titre('CONSULTER N\'EST PAS GÉRER');

        const ens = await nav.newContext();
        const pe  = await ens.newPage();

        await pe.goto(BASE + '/login');
        await pe.fill('input[name="identifier"]', 'enseignant.demo');
        await pe.fill('input[name="password"]', MDP);
        await Promise.all([pe.waitForLoadState('networkidle'), pe.click('button[type="submit"]')]);

        const vue = await pe.goto(BASE + '/annees');
        await pe.waitForLoadState('networkidle');

        check('un enseignant consulte les années', vue.status() === 200, 'HTTP ' + vue.status());

        check('…mais ne voit aucun formulaire de création',
            await pe.locator('form[action$="/annees"] button').count() === 0);

        check('…ni aucun bouton de clôture',
            await pe.locator('a:has-text("Préparer la clôture")').count() === 0);

        const refus = await ens.request.post(BASE + '/annees', {
            form: { code: 'PIRATE', name: 'x', starts_on: '2098-09-01', ends_on: '2099-07-31' },
            maxRedirects: 0,
        });

        check('et la route de création lui est refusée',
            refus.status() === 403 || refus.status() === 302, 'HTTP ' + refus.status());
    } finally {
        // =============================================================
        // ON REND LE DÉCOR INTACT.
        //
        // Sept autres recettes s'appuient sur l'année courante du décor.
        // Une sonde qui la laisse déplacée les casse toutes.
        if (courantInitial !== null) {
            await p.goto(BASE + '/annees');
            await p.waitForLoadState('networkidle');

            const aRendre = ligne(p, courantInitial)
                .locator('button:has-text("Désigner courante")');

            if (await aRendre.count() > 0) {
                await Promise.all([p.waitForLoadState('networkidle'), aRendre.click()]);
            }

            // ET ON RETIRE L'ANNÉE QU'ON A CRÉÉE.
            //
            // Sans cela, le SECOND passage trouvait l'année déjà là :
            // « l'année est créée » passait sur une ligne laissée par le
            // passage précédent, et l'assertion suivante tombait. Une
            // recette qui ne nettoie pas ce qu'elle crée finit par se
            // mentir à elle-même.
            const aRetirer = ligne(p, NOUVELLE).locator('form[action$="/supprimer"] button');

            if (await aRetirer.count() > 0) {
                await Promise.all([p.waitForLoadState('networkidle'), aRetirer.click()]);
            }

            const resteLa = await ligne(p, NOUVELLE).count() > 0;

            console.log('\n  · décor : année courante rendue à ' + courantInitial);
            console.log('  · année de recette ' + NOUVELLE
                + (resteLa ? ' — ✗ TOUJOURS LÀ' : ' — retirée'));

            if (resteLa) { ko++; }
        }

        await nav.close();
    }

    if (err.length) { console.log('\n  ERREURS JS :'); err.forEach((e) => console.log('    ' + e)); ko += err.length; }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko ? 1 : 0);
})();
