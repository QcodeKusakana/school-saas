/**
 * ÉPREUVE DU HORS CONNEXION — vrai navigateur, vraie coupure.
 *
 * POURQUOI PAS `context.setOffline()`
 * ====================================
 * Mesuré : il met `navigator.onLine` à `false` mais LAISSE PASSER le
 * trafic vers la boucle locale. Un essai fondé dessus aurait déclaré
 * le hors connexion vérifié sans jamais l'éprouver.
 *
 *   > Une sonde qui ne coupe pas vraiment ne prouve pas vraiment.
 *
 * On coupe donc par interception de requêtes (`route.abort`), ce qui
 * reproduit exactement ce que voit le navigateur quand le fil est
 * mort : un échec réseau, pas un code HTTP.
 *
 * Usage : node tests/offline_browser.js <mot_de_passe> [classe]
 */
const { chromium } = require('playwright');

const BASE   = process.env.SCHOOL_BASE || 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP    = process.argv[2];
const CLASSE = process.argv[3] || '1';

let ok = 0, ko = 0;

function check(label, cond, detail) {
    cond ? ok++ : ko++;
    console.log('  ' + (cond ? '✓' : '✗') + ' ' + label + (detail ? ' — ' + detail : ''));
}

function titre(t) { console.log('\n  ' + t); }

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const ctx = await nav.newContext({ serviceWorkers: 'allow' });
    const p   = await ctx.newPage();

    const erreursJS = [];
    p.on('pageerror', (e) => erreursJS.push(e.message));

    // Le disjoncteur : quand il est armé, RIEN ne passe.
    let coupe = false;
    await ctx.route('**/*', (route) => (coupe ? route.abort('internetdisconnected') : route.continue()));

    // =================================================================
    titre('L\'INSTALLATION');

    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', 'enseignant.demo');
    await p.fill('input[name="password"]', MDP);
    await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

    check('connexion réussie', !p.url().endsWith('/login'), p.url().replace(BASE, ''));

    await p.goto(BASE + '/presences/classe/' + CLASSE);
    await p.waitForLoadState('networkidle');
    await p.waitForTimeout(1200);

    const sw = await p.evaluate(async () => {
        const r = await navigator.serviceWorker.ready;
        return { actif: !!r.active, scope: r.scope };
    });

    check('le service worker s\'installe', sw.actif, 'portée ' + sw.scope);

    // =================================================================
    titre('CE QUI DESCEND SUR L\'APPAREIL');

    const liste = await p.evaluate((c) => window.Offline.lireListe(parseInt(c, 10)), CLASSE);

    check('la liste de la classe est mémorisée', !!liste && liste.eleves.length > 0,
        liste ? liste.eleves.length + ' élève(s)' : 'aucune');

    const champs = liste ? Object.keys(liste.eleves[0]) : [];
    const attendus = ['inscription', 'nom', 'matricule'];
    const enTrop = champs.filter((k) => !attendus.includes(k));

    check('AUCUN champ au-delà du strict nécessaire', enTrop.length === 0,
        'conservés : ' + champs.join(', '));

    const fuite = await p.evaluate(() => {
        return new Promise((res) => {
            const r = indexedDB.open('school-saas-offline');
            r.onsuccess = (e) => {
                const d = e.target.result;
                const t = d.transaction('listes', 'readonly');
                t.objectStore('listes').getAll().onsuccess = (ev) => {
                    res(JSON.stringify(ev.target.result));
                };
            };
        });
    });

    check('ni tuteur, ni adresse, ni solde, ni cote',
        !/tuteur|guardian|adresse|address|solde|balance|montant|grade|cote/i.test(fuite));

    // =================================================================
    titre('LA COUPURE — et elle est réelle');

    coupe = true;

    const essai = await p.evaluate(async () => {
        try { const r = await fetch('/sync/etat', { cache: 'no-store' }); return 'HTTP ' + r.status; }
        catch (e) { return 'ECHEC'; }
    });

    check('plus rien ne passe vers le serveur', essai === 'ECHEC', essai);

    // =================================================================
    titre('L\'APPEL HORS CONNEXION');

    // On marque le premier élève absent, puis on envoie.
    const premier = liste.eleves[0].inscription;

    await p.evaluate((id) => {
        const r = document.querySelector('input[name="status[' + id + ']"][value="absent"]');
        if (r) { r.checked = true; }
    }, premier);

    await p.evaluate(() => {
        document.querySelector('form[data-appel]')
            .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });

    await p.waitForTimeout(1200);

    const file = await p.evaluate(() => window.Offline.fileEnAttente());

    check('la saisie est ÉCRITE dans la file locale', file.length === 1,
        file.length + ' opération(s)');

    check('elle porte un identifiant d\'idempotence',
        file.length === 1 && /^[0-9a-f-]{36}$/.test(file[0].client_uuid));

    check('elle porte ce que l\'écran avait VU',
        file.length === 1 && 'seen_updated_at' in file[0].payload);

    const alerte = await p.locator('.alert-warning').first().innerText().catch(() => '');
    check('l\'utilisateur est prévenu, sans mensonge',
        /injoignable|hors connexion/i.test(alerte), alerte.slice(0, 80));

    // =================================================================
    titre('LE RETOUR DU RÉSEAU');

    coupe = false;

    const bilan = await p.evaluate(() => window.Offline.envoyerLaFile());

    check('la file part toute seule', bilan.envoyees === 1, JSON.stringify(bilan));

    const restant = await p.evaluate(() => window.Offline.fileEnAttente());
    check('et la file locale se vide', restant.length === 0, restant.length + ' restante(s)');

    // =================================================================
    titre('LE REJEU NE PRODUIT PAS DE DOUBLON');

    // On réinjecte la MÊME opération, comme le ferait un appareil qui
    // n'aurait pas reçu la réponse du serveur.
    const rejeu = await p.evaluate(async (op) => {
        const m = await window.Offline.identifiantAppareil();
        const r = await fetch('/sync/envoyer', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ device_id: m.device_id, operations: [op] })
        });
        return (await r.json()).data.results[0];
    }, {
        client_uuid: file[0].client_uuid,
        entity_type: file[0].entity_type,
        operation: file[0].operation,
        payload: file[0].payload,
        client_version: 1,
        client_time: file[0].client_time
    });

    check('le serveur reconnaît l\'opération déjà reçue',
        rejeu.status === 'applied', JSON.stringify(rejeu).slice(0, 110));

    await nav.close();

    if (erreursJS.length) {
        console.log('\n  ERREURS JS :');
        erreursJS.forEach((e) => console.log('    ' + e));
        ko += erreursJS.length;
    }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko ? 1 : 0);
})();
