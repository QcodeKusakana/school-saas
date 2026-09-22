/**
 * AUDIT 8B1 — les deux promesses non encore éprouvées.
 *
 * 1. LA PURGE À LA DÉCONNEXION
 *    Tout l'édifice du hors connexion repose dessus. Si elle ne part
 *    pas, des noms d'élèves restent sur un appareil partagé après le
 *    départ de l'enseignant, et la minimisation ne sert plus à rien.
 *
 * 2. LE CSRF SUR LES POINTS D'ENTRÉE JSON
 *    L'en-tête du contrôleur l'affirme. On le vérifie en envoyant SANS
 *    jeton : un point d'entrée de synchronisation dispensé de CSRF
 *    serait une porte ouverte à la falsification de requête.
 */
const { chromium } = require('playwright');

const BASE   = 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP    = process.argv[2];

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };
const titre = (t) => console.log('\n  ' + t);

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const ctx = await nav.newContext({ serviceWorkers: 'allow' });
    const p   = await ctx.newPage();
    const err = [];
    p.on('pageerror', (e) => err.push(e.message));

    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', 'enseignant.demo');
    await p.fill('input[name="password"]', MDP);
    await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

    await p.goto(BASE + '/presences/classe/1');
    await p.waitForLoadState('networkidle');
    await p.waitForTimeout(1200);

    // =================================================================
    titre('LE CSRF EST-IL RÉELLEMENT EXIGÉ ?');

    for (const [route, corps] of [
        ['/sync/appareil', { device_uuid: '11111111-2222-3333-4444-555555555555' }],
        ['/sync/envoyer',  { device_id: 1, operations: [] }],
    ]) {
        // `redirect: 'manual'` N'EST PAS UN DÉTAIL.
        //
        // Première version de cette sonde : un `fetch` ordinaire, et les
        // deux routes rendaient 200. Faux verdict — `fetch` SUIT les
        // redirections, et le refus CSRF répond par un 302 vers la page
        // de connexion. Je mesurais la page d'arrivée, pas la décision.
        //
        //   > Un `fetch` qui suit la redirection mesure la page
        //   > d'arrivée, pas le verdict.
        const verdict = await p.evaluate(async (a) => {
            const r = await fetch(a[0], {
                method: 'POST', credentials: 'same-origin', redirect: 'manual',
                headers: { 'Content-Type': 'application/json' },   // PAS de jeton
                body: JSON.stringify(a[1])
            });
            return { status: r.status, type: r.type };
        }, [route, corps]);

        // Un refus se présente soit comme une redirection non suivie
        // (`opaqueredirect`), soit comme un 419 pour un appel AJAX
        // reconnu comme tel.
        const refuse = verdict.type === 'opaqueredirect' || verdict.status === 419;

        check('POST ' + route + ' sans jeton est refusé', refuse,
            verdict.type + ' / HTTP ' + verdict.status);
    }

    // =================================================================
    titre('CE QUI EST SUR L\'APPAREIL AVANT LA DÉCONNEXION');

    const avant = await p.evaluate(async () => {
        const liste  = await window.Offline.lireListe(1);
        const caches_ = await caches.keys();
        let entrees = 0;
        for (const n of caches_) { entrees += (await (await caches.open(n)).keys()).length; }
        return { eleves: liste ? liste.eleves.length : 0, caches: caches_.length, entrees };
    });

    check('des noms d\'élèves sont bien mémorisés', avant.eleves > 0,
        avant.eleves + ' élève(s), ' + avant.entrees + ' entrée(s) en cache');

    // On met aussi une opération en file, pour vérifier qu'elle part.
    await p.evaluate(() => window.Offline.mettreEnFile({
        classroom_id: 1, date: '2026-01-01', slot: 'day',
        seen_updated_at: null, entries: { 1: { status: 'present' } }
    }));

    check('et une opération attend dans la file',
        (await p.evaluate(() => window.Offline.fileEnAttente())).length === 1);

    // =================================================================
    titre('LA DÉCONNEXION');

    // On emprunte le VRAI chemin : le lien de déconnexion de l'interface,
    // pas un appel direct à la purge. C'est ce chemin-là que l'école
    // empruntera, et c'est donc lui qu'il faut éprouver.
    const formulaire = p.locator('form[data-deconnexion]').first();

    check('l\'interface porte bien un point de déconnexion',
        await formulaire.count() > 0);

    // Le formulaire vit dans un menu déroulant : on l'ouvre d'abord,
    // exactement comme le ferait un enseignant.
    await p.locator('[data-bs-toggle="dropdown"]').last().click();
    await p.waitForTimeout(400);

    const bouton = formulaire.locator('button[type="submit"]');

    check('et son bouton est atteignable après ouverture du menu',
        await bouton.isVisible());

    await bouton.click();
    await p.waitForTimeout(600);
    await p.waitForLoadState('networkidle').catch(() => {});
    await p.waitForTimeout(900);

    check('on est bien déconnecté', /\/login/.test(p.url()), p.url().replace(BASE, ''));

    // =================================================================
    titre('CE QUI RESTE APRÈS');

    const apres = await p.evaluate(async () => {
        const noms = await caches.keys();
        let entrees = 0;
        const chemins = [];
        for (const n of noms) {
            for (const req of await (await caches.open(n)).keys()) {
                entrees++;
                chemins.push(new URL(req.url).pathname);
            }
        }

        const contenu = await new Promise((res) => {
            const r = indexedDB.open('school-saas-offline');
            r.onsuccess = (e) => {
                const d = e.target.result;
                if (!d.objectStoreNames.contains('listes')) { return res({ listes: [], outbox: [] }); }
                const t = d.transaction(['listes', 'outbox'], 'readonly');
                const a = t.objectStore('listes').getAll();
                const b = t.objectStore('outbox').getAll();
                t.oncomplete = () => res({ listes: a.result, outbox: b.result });
            };
            r.onerror = () => res({ listes: [], outbox: [] });
        });

        return { entrees, chemins, listes: contenu.listes.length,
                 outbox: contenu.outbox.length, brut: JSON.stringify(contenu) };
    });

    check('PLUS AUCUN nom d\'élève sur l\'appareil', apres.listes === 0,
        apres.listes + ' liste(s) restante(s)');

    check('la file d\'attente est vidée', apres.outbox === 0,
        apres.outbox + ' opération(s) restante(s)');

    // CE QU'ON JUGE ICI, ET CE QU'ON NE JUGE PAS.
    //
    // Première version : « le cache est vide ». Elle échouait, et à
    // tort. La purge part bien, mais la page de connexion se charge
    // aussitôt après et le service worker remet en cache la coquille
    // dont elle a besoin — feuilles de style, scripts. C'est son
    // travail, et cela ne contient aucune donnée d'école.
    //
    //   > Une purge se juge sur ce qui reste, pas sur le compteur.
    //
    // La propriété qui compte est donc : RIEN D'APPLICATIF ne subsiste.
    const horsCoquille = apres.chemins.filter(
        (c) => !/^\/(assets\/|offline\.html|manifest\.webmanifest)/.test(c)
    );

    check('aucune page ni route applicative ne subsiste en cache',
        horsCoquille.length === 0,
        apres.entrees + ' entrée(s), toutes de la coquille : '
            + apres.chemins.map((c) => c.split('/').pop()).join(', '));

    check('rien qui ressemble à un nom d\'élève ne subsiste',
        !/MOSI|MBILA|NKOSI|matricule/i.test(apres.brut), apres.brut.slice(0, 80));

    await nav.close();

    if (err.length) { console.log('\n  ERREURS JS :'); err.forEach((e) => console.log('    ' + e)); ko += err.length; }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko ? 1 : 0);
})();
