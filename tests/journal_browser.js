/**
 * RECETTE — le journal, tel qu'un directeur le voit.
 *
 * CE QUE SEUL UN NAVIGATEUR PEUT DIRE
 * ====================================
 * Qu'une permission ouvre un écran est une chose ; que l'écran affiche
 * la bonne donnée, que le filtre filtre vraiment, et que le bouton de
 * purge ne s'affiche PAS pour qui n'a pas le droit de purger, en est une
 * autre. Le PHP prouve les règles, la recette prouve l'écran.
 *
 * Le bouton de purge est le point le plus délicat : la DIRECTION lit le
 * journal sans pouvoir le purger — et c'est exactement le genre de
 * distinction qu'un gabarit perd en la recopiant mal.
 *
 * Usage : node tests/journal_browser.js <motdepasse>
 */
const { chromium } = require('playwright');

const BASE   = process.env.SCHOOL_BASE || 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP    = process.argv[2];

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };
const titre = (t) => console.log('\n  ' + t);

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const ctx = await nav.newContext();
    const p   = await ctx.newPage();
    const err = [];
    p.on('pageerror', (e) => err.push(e.message));

    // =================================================================
    titre('LE JOURNAL S\'OUVRE-T-IL ?');

    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', 'directeur.demo');
    await p.fill('input[name="password"]', MDP);
    await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

    check('la direction se connecte', !p.url().endsWith('/login'));

    // Le lien doit être DANS la barre latérale : une route sans lien est
    // une route que personne ne trouve.
    check('le menu porte « Journal des actions »',
        await p.locator('a[href$="/journal"]').count() > 0);

    await p.goto(BASE + '/journal');
    await p.waitForLoadState('networkidle');

    check('l\'écran répond', p.url().endsWith('/journal'));

    const page = await p.locator('body').innerText();

    check('il annonce un volume', /entrée\(s\)/.test(page));

    // =================================================================
    titre('MONTRE-T-IL DE VRAIES ACTIONS ?');

    // ON VÉRIFIE LA DONNÉE, PAS LE GABARIT.
    //
    // Une recette qui cherche « Journal » dans la page passe même si le
    // tableau est vide. On vient de se connecter : la connexion DOIT y
    // figurer, et elle doit porter notre nom.
    const lignes = await p.locator('table tbody tr').count();

    check('le tableau porte des lignes', lignes > 0, lignes + ' ligne(s)');

    check('la connexion qu\'on vient de faire y figure', /Connexion/.test(page));

    // Les libellés doivent être traduits, pas laissés en code brut.
    check('les actions sont en clair, pas en code',
        !/\blogin\b/.test(await p.locator('table tbody').innerText()));

    // =================================================================
    titre('LE FILTRE FILTRE-T-IL VRAIMENT ?');

    const avant = await p.locator('table tbody tr').count();

    await p.goto(BASE + '/journal?action=document.issued');
    await p.waitForLoadState('networkidle');

    const apres = await p.locator('table tbody tr').count();
    const texteFiltre = await p.locator('table tbody').innerText();

    check('le filtre par action réduit la liste', apres <= avant,
        avant + ' → ' + apres);

    check('et ne laisse QUE cette action',
        apres === 0 || !/Connexion/.test(texteFiltre));

    // Une date impossible doit vider la liste, pas la laisser entière.
    await p.goto(BASE + '/journal?du=1990-01-01&au=1990-01-02');
    await p.waitForLoadState('networkidle');

    check('une période sans activité affiche un vide explicite',
        /Aucune entrée/.test(await p.locator('body').innerText()));

    // =================================================================
    titre('LE DÉTAIL D\'UNE ENTRÉE');

    await p.goto(BASE + '/journal');
    await p.waitForLoadState('networkidle');

    const [reponse] = await Promise.all([
        p.waitForNavigation(),
        p.locator('a[href*="/journal/"]').first().click(),
    ]);

    const detail = await p.locator('body').innerText();

    // ON VÉRIFIE LA RÉPONSE, PAS L'URL.
    //
    // La première version testait `/journal/\d+$/.test(p.url())` : elle
    // passait alors que la page était une trace d'erreur 500 — l'URL
    // était bonne, la page ne l'était pas. Le contrôleur typait le
    // paramètre de route en `int` quand le routeur le passe en `string`.
    //
    //   > Une URL correcte ne dit rien de la page qu'elle a rendue.
    check('la fiche de détail répond 200', reponse.status() === 200,
        'HTTP ' + reponse.status() + ' sur ' + p.url().replace(BASE, ''));

    check('et ce n\'est pas une page d\'erreur',
        !/TypeError|Erreur —|Pile d'appels/.test(detail));

    check('elle nomme l\'auteur', /Auteur/.test(detail));

    // L'IP est stockée en varbinary(16). L'afficher brute donnerait des
    // octets illisibles ; elle doit être repassée par inet_ntop.
    const ip = (detail.match(/Adresse IP\s*\n?\s*(\S+)/) || [])[1] || '';

    check('l\'adresse IP est lisible, pas binaire',
        ip === '—' || /^[0-9a-fA-F.:]+$/.test(ip), ip);

    // =================================================================
    titre('LA PURGE N\'EST PAS OFFERTE À QUI LIT');

    await p.goto(BASE + '/journal');
    await p.waitForLoadState('networkidle');

    // LE POINT QUI COMPTE. La DIRECTION porte `audit.view` et PAS
    // `audit.purge` : le chef d'établissement est l'une des personnes
    // que ce journal trace.
    check('la direction ne voit AUCUN bouton de purge',
        await p.locator('form[action$="/journal/purger"]').count() === 0);

    // Et la route elle-même doit refuser, pas seulement le gabarit.
    const jeton = await p.locator('input[name="_token"], input[name="csrf_token"]')
        .first().getAttribute('value').catch(() => null);

    const refus = await ctx.request.post(BASE + '/journal/purger', {
        form: jeton ? { jours: '730', _token: jeton } : { jours: '730' },
        maxRedirects: 0,
    });

    check('et la route de purge lui est refusée',
        refus.status() === 403 || refus.status() === 302,
        'HTTP ' + refus.status());

    // Un refus par redirection doit mener ailleurs qu'à une purge
    // silencieusement effectuée : on revient au journal et on vérifie
    // qu'aucune purge n'y est inscrite.
    await p.goto(BASE + '/journal?action=audit.purged');
    await p.waitForLoadState('networkidle');

    check('aucune purge n\'a été enregistrée',
        /Aucune entrée/.test(await p.locator('body').innerText()));

    // =================================================================
    titre('LE JOURNAL GLOBAL DE L\'ÉDITEUR');

    // `platform.audit.view` était semée depuis la phase 1 et le lien de
    // la barre latérale restait masqué par `route_exists()`.
    const ed  = await nav.newContext();
    const pe  = await ed.newPage();
    const erreursEditeur = [];
    pe.on('pageerror', (e) => erreursEditeur.push(e.message));

    await pe.goto(BASE + '/login');
    await pe.fill('input[name="identifier"]', 'editeur.demo');
    await pe.fill('input[name="password"]', MDP);
    await Promise.all([pe.waitForLoadState('networkidle'), pe.click('button[type="submit"]')]);

    check('l\'éditeur se connecte', !pe.url().endsWith('/login'));

    check('son menu porte « Journal global »',
        await pe.locator('a[href$="/plateforme/journal"]').count() > 0);

    const rep = await pe.goto(BASE + '/plateforme/journal');
    await pe.waitForLoadState('networkidle');

    const pageEditeur = await pe.locator('body').innerText();

    check('le journal global répond 200', rep.status() === 200, 'HTTP ' + rep.status());

    check('et ce n\'est pas une page d\'erreur',
        !/TypeError|Erreur —|Pile d'appels/.test(pageEditeur));

    check('il porte des lignes',
        await pe.locator('table tbody tr').count() > 0,
        await pe.locator('table tbody tr').count() + ' ligne(s)');

    // CE QUE L'ÉCRAN NE DOIT PAS MONTRER : les valeurs modifiées, qui
    // concernent des élèves d'écoles clientes.
    check('il n\'affiche AUCUNE valeur modifiée',
        !/Avant\s*\n?\s*Après/.test(pageEditeur));

    // Le filtre par école doit exister : sans lui, l'écran est illisible
    // dès la deuxième école.
    check('il propose un filtre par établissement',
        await pe.locator('select[name="ecole"] option').count() > 1);

    // Et la DIRECTION, elle, ne doit pas y entrer.
    const interdit = await ctx.request.get(BASE + '/plateforme/journal', { maxRedirects: 0 });

    check('la direction d\'une école n\'y accède pas',
        interdit.status() === 403 || interdit.status() === 302,
        'HTTP ' + interdit.status());

    if (erreursEditeur.length) { err.push(...erreursEditeur); }

    await nav.close();

    if (err.length) { console.log('\n  ERREURS JS :'); err.forEach((e) => console.log('    ' + e)); ko += err.length; }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko ? 1 : 0);
})();
