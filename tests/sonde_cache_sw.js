/**
 * SONDE — que garde réellement le service worker ?
 *
 * L'en-tête de sw.js jure qu'il ne met en cache que la coquille.
 * Cette sonde le vérifie au lieu de le croire : on visite un écran,
 * on interroge une route JSON, puis on ÉNUMÈRE le cache.
 */
const { chromium } = require('playwright');

const BASE   = 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP    = process.argv[2];

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const ctx = await nav.newContext({ serviceWorkers: 'allow' });
    const p   = await ctx.newPage();

    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', 'enseignant.demo');
    await p.fill('input[name="password"]', MDP);
    await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

    await p.goto(BASE + '/presences/classe/1');
    await p.waitForLoadState('networkidle');
    await p.waitForTimeout(1000);

    // Une route JSON de l'application, en GET, comme en produit.
    await p.evaluate(() => fetch('/sync/etat', { cache: 'no-store' }).then((r) => r.text()));
    await p.waitForTimeout(800);

    const contenu = await p.evaluate(async () => {
        const noms = await caches.keys();
        const out  = [];

        for (const n of noms) {
            const c = await caches.open(n);
            for (const req of await c.keys()) {
                const rep  = await c.match(req);
                const type = rep ? (rep.headers.get('content-type') || '') : '';
                out.push({ cache: n, url: new URL(req.url).pathname, type: type.split(';')[0] });
            }
        }
        return out;
    });

    console.log('\n  CONTENU RÉEL DU CACHE\n');
    contenu.forEach((e) => console.log('    ' + e.url.padEnd(58) + e.type));

    const horsCoquille = contenu.filter((e) => !/^\/(assets\/|offline\.html|manifest\.webmanifest)/.test(e.url));

    console.log('\n  ' + contenu.length + ' entrée(s), dont ' + horsCoquille.length + ' HORS COQUILLE');
    horsCoquille.forEach((e) => console.log('    ⚠ ' + e.url + '  (' + e.type + ')'));
    console.log('');

    await nav.close();
    process.exit(horsCoquille.length ? 1 : 0);
})();
