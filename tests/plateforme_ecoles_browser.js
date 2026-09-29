/**
 * RECETTE — le cycle de vie d'un établissement, vu de l'écran (11A).
 *
 * Elle se connecte avec le compte de l'ÉDITEUR (`editeur.demo`), seul
 * porteur des permissions de plateforme.
 *
 * CE QUE SEUL UN NAVIGATEUR PEUT DIRE
 * ====================================
 *   · le bouton « Nouvel établissement » existe et mène quelque part ;
 *   · /plateforme/ecoles/nouveau n'est pas avalé par /{id} — l'ordre
 *     des routes est un piège qui ne se voit qu'à l'exécution ;
 *   · le mot de passe initial est affiché UNE FOIS, puis disparaît ;
 *   · il ne passe JAMAIS par l'URL ;
 *   · le bloc d'état propose bien de résilier.
 *
 * CETTE RECETTE CRÉE UN ÉTABLISSEMENT ET LE RETIRE AVANT DE FINIR.
 *
 * Usage : node tests/plateforme_ecoles_browser.js <motdepasse>
 */
const { chromium } = require('playwright');
const BASE = process.env.SCHOOL_BASE || 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP = process.argv[2];

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };
const titre = (t) => console.log('\n  ' + t);

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const p = await (await nav.newContext()).newPage();
    const err = [];
    p.on('pageerror', (e) => err.push(e.message));

    const suffixe = Math.random().toString(36).slice(2, 8);
    let motDePasse = null;

    try {
        titre('L\'ÉDITEUR OUVRE SA CONSOLE');

        await p.goto(BASE + '/login');
        // L'ÉDITEUR, PAS UN DIRECTEUR D'ÉCOLE.
        // Première version : elle se connectait avec `directeur.demo`,
        // qui est le directeur de l'école de démonstration et n'a aucune
        // permission de plateforme. Le bouton manquait à bon droit — la
        // recette accusait un produit correct.
        //
        //   > Une recette qui ouvre la mauvaise porte ne mesure pas le
        //   > produit, elle mesure son propre trousseau.
        await p.fill('input[name="identifier"]', 'editeur.demo');
        await p.fill('input[name="password"]', MDP);
        await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type="submit"]')]);

        await p.goto(BASE + '/plateforme/ecoles');
        check('la liste des établissements s\'ouvre', p.url().includes('/plateforme/ecoles'));
        check('le bouton « Nouvel établissement » est là',
            await p.locator('a[href$="/plateforme/ecoles/nouveau"]').count() === 1);

        titre('LA ROUTE /nouveau N\'EST PAS AVALÉE PAR /{id}');

        await Promise.all([p.waitForLoadState('networkidle'),
            p.locator('a[href$="/plateforme/ecoles/nouveau"]').click()]);

        const corps = await p.locator('body').innerText();
        check('l\'écran de création s\'ouvre', p.url().endsWith('/nouveau'));
        check('… et non « Établissement introuvable »', !corps.includes('introuvable'));
        check('il demande les cycles', await p.locator('input[name="cycles[]"]').count() >= 3);
        check('il demande le premier administrateur',
            await p.locator('input[name="admin_username"]').count() === 1);
        check('il annonce que le mot de passe ne sera affiché qu\'une fois',
            corps.includes('une seule'));

        titre('CRÉER');

        await p.fill('input[name="name"]', 'Recette Navigateur ' + suffixe);
        await p.selectOption('select[name="school_type"]', 'prive');
        await p.fill('input[name="city"]', 'Kinshasa');
        await p.fill('input[name="admin_last_name"]', 'TSHIBANGU');
        await p.fill('input[name="admin_first_name"]', 'Grâce');
        await p.fill('input[name="admin_username"]', 'nav.' + suffixe);

        // ON CIBLE LE FORMULAIRE, PAS « UN BOUTON SUBMIT ».
        // La barre de navigation en porte un autre — la déconnexion, dans
        // un menu replié donc invisible. Playwright le trouvait en
        // premier et attendait trente secondes qu'il devienne cliquable.
        await Promise.all([
            p.waitForLoadState('networkidle'),
            p.locator('form[action$="/plateforme/ecoles"] button[type="submit"]').click(),
        ]);

        check('l\'établissement est créé', /\/plateforme\/ecoles\/\d+$/.test(p.url()), p.url());

        const apres = await p.locator('body').innerText();
        const motif = apres.match(/mot de passe\s*:\s*([A-Za-z0-9]{10,14})/);
        motDePasse = motif ? motif[1] : null;

        check('le mot de passe initial est affiché', motDePasse !== null,
            motDePasse ? motDePasse.replace(/./g, '•') + ' (' + motDePasse.length + ' car.)' : 'absent');
        check('l\'identifiant est affiché', apres.includes('nav.' + suffixe));

        // LE MOT DE PASSE NE DOIT JAMAIS TRANSITER PAR L'URL : une adresse
        // se retrouve dans l'historique, dans les journaux du serveur et
        // dans l'en-tête « Referer » de la page suivante.
        check('il ne passe PAS par l\'URL',
            motDePasse === null || !p.url().includes(motDePasse));

        titre('IL N\'EST AFFICHÉ QU\'UNE FOIS');

        await p.reload({ waitUntil: 'networkidle' });
        const recharge = await p.locator('body').innerText();
        check('après rechargement, il a disparu',
            motDePasse === null || !recharge.includes(motDePasse));

        titre('L\'ÉTAT DE L\'ÉTABLISSEMENT');

        check('le bloc d\'état est présent',
            await p.locator('form[action$="/etat"]').count() === 1);

        const etats = await p.locator('form[action$="/etat"] select[name="status"] option')
            .allInnerTexts();
        check('il propose de résilier', etats.some((t) => t.includes('Résilié')));
        check('il ne propose pas l\'état courant', !etats.some((t) => t.trim() === 'En service'));
        check('il avertit que les sessions seront closes',
            (await p.locator('body').innerText()).includes('sessions en cours'));

        check('le bouton « Modifier » est là',
            await p.locator('a[href$="/modifier"]').count() >= 1);

        check('aucune erreur JavaScript', err.length === 0, err.join(' | '));
    } finally {
        // Remise en état : la recette ne laisse pas d'école derrière elle.
        // Le nettoyage est un FICHIER, pas du PHP en ligne de commande :
        // les apostrophes y sont ingérables et le shell de Windows ne
        // cite pas comme /bin/sh.
        const { execFileSync } = require('child_process');
        try {
            execFileSync('php', ['tests/_nettoyage_recette_ecoles.php'],
                { cwd: process.cwd(), stdio: 'pipe' });
        } catch (e) {
            console.log('  ! nettoyage : ' + String(e.message).split('\n')[0]);
        }

        await nav.close();
    }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko > 0 ? 1 : 0);
})();
