/**
 * RECETTE — le reçu vérifiable, vu de l'écran et du papier (11C).
 *
 * CE QUE SEUL UN NAVIGATEUR PEUT DIRE
 * ====================================
 *   · ce que porte VRAIMENT la feuille imprimée — la barre latérale et
 *     l'identifiant du caissier ne doivent pas y figurer ;
 *   · que le code de vérification est imprimé, en image ET en clair ;
 *   · que la page publique répond SANS connexion — c'est tout l'objet :
 *     un parent, un inspecteur, un fournisseur doivent pouvoir vérifier
 *     sans compte ;
 *   · qu'elle ne publie aucun nom.
 *
 * Usage : node tests/recu_browser.js <motdepasse>
 */
const { chromium } = require('playwright');
const BASE = process.env.SCHOOL_BASE || 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP = process.argv[2];

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };
const titre = (t) => console.log('\n  ' + t);

/**
 * Le texte réellement rendu — ce qui est masqué ne compte pas.
 *
 * ON PARCOURT LES NŒUDS TEXTE, PAS LES ÉLÉMENTS SANS ENFANTS.
 * Première version : elle ne gardait que les éléments dépourvus
 * d'enfants. Le nom de l'élève vit dans un <dd> qui contient un <span>
 * pour le matricule, et les signatures dans un <div> qui contient des
 * <br> : les deux étaient écartés, et la recette a conclu que le reçu
 * n'imprimait ni le nom de l'enfant ni les signatures.
 *
 *   > Une recette qui choisit ses éléments par leur forme mesure la
 *   > forme, pas ce qui est lu.
 */
const texteRendu = (page) => page.evaluate(() => {
    const masque = (el) => {
        for (let n = el; n && n !== document.body; n = n.parentElement) {
            const s = getComputedStyle(n);
            if (s.display === 'none' || s.visibility === 'hidden') return true;
        }
        return false;
    };

    const marche = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const out = [];

    while (marche.nextNode()) {
        const noeud = marche.currentNode;
        const t = (noeud.nodeValue || '').trim();

        if (t && noeud.parentElement && !masque(noeud.parentElement)) {
            out.push(t.replace(/\s+/g, ' '));
        }
    }

    return out.join('\n');
});

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const err = [];
    const ctx = await nav.newContext();
    const p = await ctx.newPage();
    p.on('pageerror', (e) => err.push(e.message));

    try {
        titre('LE CAISSIER OUVRE UN REÇU');

        await p.goto(BASE + '/login');
        await p.fill('input[name="identifier"]', 'admin.demo');
        await p.fill('input[name="password"]', MDP);
        await Promise.all([p.waitForLoadState('networkidle'),
            p.locator('form').filter({ has: p.locator('input[name="identifier"]') })
                .locator('button[type="submit"]').click()]);

        // LE JOURNAL DE CAISSE, pas la situation générale.
        // Première version : elle cherchait un lien vers un reçu depuis
        // `/finances`, qui est l'état des dettes de l'école et n'en
        // porte aucun. Elle a conclu que la caisse ne menait nulle part.
        //
        //   > Une recette qui frappe à la mauvaise porte ne mesure pas
        //   > le produit, elle mesure son propre plan.
        await p.goto(BASE + '/finances/journal');

        const lien = p.locator('a[href*="/finances/recu/"]').first();

        check('le journal de caisse mène à un reçu', await lien.count() > 0,
            'chaque encaissement doit rouvrir la pièce qui l\'a produit');

        if (await lien.count() === 0) {
            throw new Error('aucun encaissement dans la démonstration');
        }

        await Promise.all([p.waitForLoadState('networkidle'), lien.click()]);

        check('le reçu s\'ouvre', /\/finances\/recu\/\d+/.test(p.url()), p.url());

        titre('CE QUE LA FAMILLE EMPORTE');

        await p.emulateMedia({ media: 'print' });
        const papier = await texteRendu(p);

        check('le numéro du reçu est imprimé', /REC-\d{4}-\d{4}-\d+/.test(papier));
        check('le nom de l\'élève est imprimé', /KABILA|LUMU|TSHALA/.test(papier),
            'un reçu sans nom ne vaut rien pour la famille');
        check('le nom de l\'école est imprimé', /Complexe Scolaire/.test(papier));
        check('les deux signatures sont prévues',
            /Signature du caissier/.test(papier) && /Signature du payeur/.test(papier));

        // LA BARRE LATÉRALE NE DOIT PAS PARTIR CHEZ LE PARENT.
        check('la navigation ne s\'imprime PAS', !/Tableau de bord/.test(papier));
        check('… ni l\'identifiant du caissier connecté', !/admin\.demo/.test(papier),
            'l\'identifiant d\'un compte du personnel n\'a rien à faire chez une famille');

        titre('LE CODE DE VÉRIFICATION');

        const jeton = (papier.match(/[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}/) || [])[0];

        check('un code est imprimé EN CLAIR', jeton !== undefined, jeton || 'aucun');
        check('… et le code à scanner est là aussi',
            await p.locator('.recu-qr svg').count() === 1,
            'une caméra qui refuse, une photocopie floue : le clair reste');
        check('… avec l\'adresse à taper à la main', /\/verifier\//.test(papier));

        await p.emulateMedia({ media: 'screen' });

        titre('LA PAGE PUBLIQUE RÉPOND SANS COMPTE');

        if (jeton !== undefined) {
            // UN NAVIGATEUR NEUF, SANS SESSION. C'est tout l'objet de la
            // vérification : un parent, un inspecteur ou un fournisseur
            // n'ont pas de compte sur la plateforme.
            const anonyme = await (await nav.newContext()).newPage();
            const r = await anonyme.goto(BASE + '/verifier/' + jeton);

            check('elle répond à un visiteur sans compte', r.status() === 200,
                'HTTP ' + r.status());

            const vue = await anonyme.locator('body').innerText();

            check('… en confirmant le versement', /Versement enregistré/.test(vue));
            check('… avec le numéro du reçu', new RegExp('REC-').test(vue));
            check('… et le montant remis', /\d/.test(vue) && /(CDF|USD)/.test(vue));

            check('elle ne publie AUCUN nom d\'élève', !/KABILA|LUMU|TSHALA/.test(vue),
                'ce sont des mineurs');
            check('… ni le nom du payeur', !/ILUNGA|KABEYA/.test(vue));

            check('elle dit ce qu\'elle NE vérifie PAS',
                /Comparez le numéro et le montant/.test(vue),
                'une page qui laisserait croire que scanner suffit rendrait la fraude plus facile');

            const inconnu = await anonyme.goto(BASE + '/verifier/2345-6789-ABCD');
            const vueInconnue = await anonyme.locator('body').innerText();

            check('un code inconnu répond sans accuser personne',
                inconnu.status() === 200 && /Aucune pièce ne correspond/.test(vueInconnue));
            check('… et explique comment relire le code',
                /jamais les lettres I, L, O, U/.test(vueInconnue));

            await anonyme.close();
        }

        check('aucune erreur JavaScript sur le parcours', err.length === 0,
            err.slice(0, 2).join(' | '));
    } catch (e) {
        check('la recette va jusqu\'au bout', false, e.message);
    } finally {
        await nav.close();
    }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko > 0 ? 1 : 0);
})();
