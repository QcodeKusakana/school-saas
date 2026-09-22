/**
 * RECETTE HTTP — l'écran d'arbitrage, vu par un vrai navigateur.
 *
 * On fabrique un conflit réel (appel en ligne, puis envoi d'une
 * version fondée sur un état périmé), puis on ouvre /synchronisation
 * et on arbitre pour de bon.
 */
const { chromium } = require('playwright');

const BASE   = 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP    = process.argv[2];

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const ctx = await nav.newContext();
    const p   = await ctx.newPage();
    const err = [];
    p.on('pageerror', (e) => err.push(e.message));

    // --- La direction, qui seule arbitre ---------------------------
    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', 'directeur.demo');
    await p.fill('input[name="password"]', MDP);
    await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

    check('la direction se connecte', !p.url().endsWith('/login'));

    // --- Un conflit, fabriqué proprement ---------------------------
    await p.goto(BASE + '/presences/classe/1');
    await p.waitForLoadState('networkidle');

    const fait = await p.evaluate(async () => {
        const jeton = document.querySelector('meta[name="csrf-token"]').content;
        const form  = document.querySelector('form[data-appel]');

        const dev = await fetch('/sync/appareil', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': jeton },
            body: JSON.stringify({ device_uuid: crypto.randomUUID(), label: 'recette' })
        }).then((r) => r.json());

        const inscription = parseInt(
            document.querySelector('[data-eleve]').getAttribute('data-inscription'), 10
        );

        const envoyer = (vu) => fetch('/sync/envoyer', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': jeton },
            body: JSON.stringify({
                device_id: dev.data.device_id,
                operations: [{
                    client_uuid: crypto.randomUUID(),
                    entity_type: 'attendance_session',
                    operation: 'update', client_version: 1,
                    client_time: '2026-09-21 08:00:00',
                    payload: {
                        classroom_id: parseInt(form.getAttribute('data-classe'), 10),
                        date: form.getAttribute('data-date'),
                        slot: form.getAttribute('data-moment'),
                        seen_updated_at: vu,
                        entries: { [inscription]: { status: 'absent', minutes_late: null } }
                    }
                }]
            })
        }).then((r) => r.json());

        // Un état VOLONTAIREMENT périmé : le serveur doit refuser
        // d'écraser et ouvrir un conflit.
        return (await envoyer('2020-01-01 00:00:00')).data.results[0];
    });

    check('un état périmé produit un conflit, pas un écrasement',
        fait.status === 'conflict', fait.message.slice(0, 90));

    // --- L'écran ---------------------------------------------------
    await p.goto(BASE + '/synchronisation');
    await p.waitForLoadState('networkidle');

    check('l\'écran d\'arbitrage répond', p.url().endsWith('/synchronisation'));

    const texte = await p.locator('body').innerText();

    check('il annonce le conflit', /À arbitrer/.test(texte));
    check('il nomme l\'auteur de la saisie', /Conflit n°/.test(texte));
    check('il montre les DEUX versions',
        /Version du serveur/.test(texte) && /Version de l'appareil/.test(texte));
    check('il dit que rien ne sera effacé', /l'arbitrage n'efface rien/i.test(texte));
    check('l\'appareil figure dans la liste', /recette/.test(texte));

    const menu = await p.locator('.app-shell').innerText();
    check('la barre latérale porte l\'entrée et sa pastille',
        /Synchronisation/.test(menu));

    // --- On arbitre pour de bon ------------------------------------
    const boutons = p.locator('button:has-text("Conserver la version du serveur")');
    const avant   = await boutons.count();

    check('les deux décisions sont offertes',
        avant > 0 && await p.locator('button:has-text("Appliquer la version de l\'appareil")').count() > 0);

    await Promise.all([p.waitForLoadState('networkidle'), boutons.first().click()]);

    const apres = await p.locator('body').innerText();

    check('la décision est prise et annoncée',
        /version du serveur est conservée/i.test(apres), apres.slice(0, 0));

    check('et elle apparaît dans les arbitrages passés',
        /Arbitrages passés/.test(apres) && /Serveur conservé/.test(apres));

    await nav.close();

    if (err.length) { console.log('\n  ERREURS JS :'); err.forEach((e) => console.log('    ' + e)); ko += err.length; }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko ? 1 : 0);
})();
