/**
 * RECETTE — composer un rôle, vu de l'écran (11B).
 *
 * CE QUE SEUL UN NAVIGATEUR PEUT DIRE
 * ====================================
 *   · l'écran est ATTEIGNABLE — la phase 11A a rappelé qu'un écran sans
 *     lien n'est pas un écran, c'est une adresse ;
 *   · /roles/nouveau n'est pas avalé par /roles/{id} ;
 *   · les permissions de PLATEFORME n'apparaissent nulle part dans le
 *     formulaire, même pour un compte qui les détient ;
 *   · un rôle système s'ouvre en LECTURE et dit pourquoi ;
 *   · le directeur, qui n'a que `role.view`, voit la liste sans pouvoir
 *     composer — et le produit le lui dit au lieu de lui montrer un
 *     bouton qui échouera ;
 *   · la saisie refusée est conservée, y compris les cases cochées.
 *
 * CETTE RECETTE CRÉE UN RÔLE ET LE DÉSACTIVE AVANT DE FINIR. Elle ne le
 * supprime pas : le produit ne supprime pas les rôles, et une recette
 * qui ferait autrement mesurerait autre chose que le produit.
 *
 * Usage : node tests/roles_browser.js <motdepasse>
 */
const { chromium } = require('playwright');
const BASE = process.env.SCHOOL_BASE || 'http://127.0.0.1:8099';
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MDP = process.argv[2];

let ok = 0, ko = 0;
const check = (l, c, d) => { c ? ok++ : ko++; console.log('  ' + (c ? '✓' : '✗') + ' ' + l + (d ? ' — ' + d : '')); };
const titre = (t) => console.log('\n  ' + t);

/**
 * Ouvre une session NEUVE pour ce compte, dans son propre contexte.
 *
 * Première version : elle se déconnectait puis se reconnectait dans la
 * même page. Le bouton de déconnexion vit dans un menu replié, et deux
 * contournements successifs n'ont rien donné de fiable. Or deux
 * utilisateurs, dans la vraie vie, ne partagent pas un navigateur : leur
 * donner chacun le sien est à la fois plus simple et plus fidèle.
 */
const ouvrirSession = async (nav, identifiant, err) => {
    const p = await (await nav.newContext()).newPage();
    p.on('pageerror', (e) => err.push(identifiant + ' : ' + e.message));

    await p.goto(BASE + '/login');
    await p.fill('input[name="identifier"]', identifiant);
    await p.fill('input[name="password"]', MDP);
    await Promise.all([
        p.waitForLoadState('networkidle'),
        p.locator('form').filter({ has: p.locator('input[name="identifier"]') })
            .locator('button[type="submit"]').click(),
    ]);

    return p;
};

(async () => {
    const nav = await chromium.launch({ executablePath: CHROME });
    const err = [];
    let p;

    const nom = 'Surveillant recette ' + Math.random().toString(36).slice(2, 7);

    try {
        // =============================================================
        titre('L\'ADMINISTRATEUR D\'ÉCOLE TROUVE L\'ÉCRAN');
        // =============================================================
        p = await ouvrirSession(nav, 'admin.demo', err);

        check('la connexion aboutit', !p.url().includes('/login'), p.url());

        const lien = p.locator('a[href$="/roles"]');
        check('le menu porte « Rôles et permissions »', await lien.count() >= 1);

        await Promise.all([p.waitForLoadState('networkidle'), lien.first().click()]);

        let corps = await p.locator('body').innerText();
        check('la liste des rôles s\'ouvre', p.url().endsWith('/roles'), p.url());
        check('elle montre les rôles livrés avec le produit', corps.includes('Secrétariat'));
        check('le bouton « Nouveau rôle » est là',
            await p.locator('a[href$="/roles/nouveau"]').count() === 1);

        // =============================================================
        titre('/roles/nouveau N\'EST PAS AVALÉ PAR /roles/{id}');
        // =============================================================
        await Promise.all([p.waitForLoadState('networkidle'),
            p.locator('a[href$="/roles/nouveau"]').first().click()]);

        corps = await p.locator('body').innerText();
        check('l\'écran de création s\'ouvre', p.url().endsWith('/roles/nouveau'), p.url());
        check('… et non « Ce rôle n\'existe pas »', !corps.includes('n\'existe pas'));

        const cases = await p.locator('input[name="permissions[]"]').count();
        check('il propose les permissions', cases > 20, cases + ' case(s)');

        // LES PERMISSIONS DE PLATEFORME NE DOIVENT PAS Y ÊTRE.
        const valeurs = await p.locator('input[name="permissions[]"]').evaluateAll(
            (n) => n.map((e) => e.closest('.form-check').innerText));
        const fuite = valeurs.filter((t) => /plateforme|Plateforme/.test(t) && /effac|résili|abonnement global/i.test(t));
        check('aucune permission de plateforme n\'est proposée', fuite.length === 0,
            fuite.slice(0, 2).join(' | '));

        // =============================================================
        titre('LA SAISIE REFUSÉE EST CONSERVÉE');
        // =============================================================
        // Un niveau égal au sien : le service refuse. Les cases cochées
        // doivent revenir — sinon l'utilisateur recommence tout.
        await p.fill('input[name="name"]', nom);
        await p.fill('input[name="level"]', '90');

        const premiere = p.locator('input[name="permissions[]"]:not([disabled])').first();
        const valeurCochee = await premiere.getAttribute('value');
        await premiere.check();

        await Promise.all([p.waitForLoadState('networkidle'),
            p.locator('#form-role button[type="submit"]').first().click()]);

        corps = await p.locator('body').innerText();
        check('le niveau égal au sien est refusé', /strictement inférieur/.test(corps));
        check('le nom saisi est conservé',
            await p.locator('input[name="name"]').inputValue() === nom);
        check('la case cochée est conservée',
            await p.locator('input[name="permissions[]"][value="' + valeurCochee + '"]').isChecked());

        // =============================================================
        titre('LE RÔLE EST CRÉÉ');
        // =============================================================
        await p.fill('input[name="level"]', '30');
        await Promise.all([p.waitForLoadState('networkidle'),
            p.locator('#form-role button[type="submit"]').first().click()]);

        corps = await p.locator('body').innerText();
        check('le rôle est créé', /créé avec/.test(corps), corps.split('\n').find((l) => /créé/.test(l)) || '');
        check('… et l\'écran de modification s\'ouvre', /\/roles\/\d+$/.test(p.url()), p.url());
        check('il porte le nom saisi',
            await p.locator('input[name="name"]').inputValue() === nom);

        const urlRole = p.url();

        await p.goto(BASE + '/roles');
        corps = await p.locator('body').innerText();
        check('il apparaît dans la liste', corps.includes(nom));

        // =============================================================
        titre('UN RÔLE SYSTÈME S\'OUVRE EN LECTURE, ET DIT POURQUOI');
        // =============================================================
        const lienSysteme = p.locator('tr', { hasText: 'Secrétariat' }).locator('a[href*="/roles/"]').first();

        if (await lienSysteme.count() > 0) {
            await Promise.all([p.waitForLoadState('networkidle'), lienSysteme.click()]);
            corps = await p.locator('body').innerText();
            check('l\'écran d\'un rôle système s\'ouvre sans erreur', !/Erreur|Exception/.test(corps));
            check('… et explique qu\'il est livré avec le produit', /livré avec le produit/.test(corps));
            check('… sans bouton d\'enregistrement',
                await p.locator('#form-role button[type="submit"]').count() === 0);
        } else {
            check('un rôle système est consultable depuis la liste', false, 'aucun lien trouvé');
        }

        // =============================================================
        titre('LE DIRECTEUR CONSULTE, IL NE COMPOSE PAS');
        // =============================================================
        const pDirecteur = await ouvrirSession(nav, 'directeur.demo', err);
        await pDirecteur.goto(BASE + '/roles');

        corps = await pDirecteur.locator('body').innerText();
        check('le directeur ouvre bien la liste', pDirecteur.url().endsWith('/roles'), pDirecteur.url());
        check('… mais aucun bouton « Nouveau rôle » ne lui est montré',
            await pDirecteur.locator('a[href$="/roles/nouveau"]').count() === 0);

        // ON MESURE LE CODE HTTP, PAS UN MOT DANS LA PAGE.
        // Première version : elle acceptait la page si elle contenait
        // « refus » quelque part — un contrôle qui serait passé au vert
        // le jour où l'écran de création aurait affiché ce mot pour une
        // toute autre raison.
        const reponse = await pDirecteur.goto(BASE + '/roles/nouveau');

        check('… et l\'adresse tapée à la main est refusée par le serveur',
            reponse.status() === 403, 'HTTP ' + reponse.status());
        check('… sans lui montrer le formulaire',
            await pDirecteur.locator('#form-role').count() === 0);

        // =============================================================
        titre('REMISE EN ÉTAT — le rôle est désactivé, pas supprimé');
        // =============================================================
        await p.goto(BASE + '/roles');

        const idRole = urlRole.split('/').pop();
        const bascule = p.locator('tr', { hasText: nom })
            .locator('button[data-bs-target="#etat-' + idRole + '"]');

        check('la liste propose de le désactiver', await bascule.count() === 1);
        check('… et le bouton porte un libellé, pas seulement une icône',
            /Désactiver/.test(await bascule.innerText().catch(() => '')));

        const formulaire = p.locator('form[action$="/roles/' + idRole + '/etat"]')
            .filter({ has: p.locator('input[name="reason"]') });

        await bascule.click();
        await p.waitForTimeout(400);

        // L'ÉCRAN DIT-IL LA VÉRITÉ SUR L'ENJEU ?
        //
        // Première version : elle soumettait sans motif en attendant un
        // refus. Le refus n'est venu qu'à l'exécution — et à raison : le
        // produit n'exige un motif que si des comptes PORTENT le rôle, et
        // celui-ci vient de naître. La recette accusait un produit correct.
        //
        //   > Une recette qui exige plus que la règle mesure sa propre
        //   > idée de la règle.
        //
        // Ce qui se vérifie ici, c'est que le bloc annonce le bon enjeu :
        // combien de comptes perdront quoi.
        const enjeu = await formulaire.innerText();
        check('le bloc annonce l\'enjeu — ici, aucun porteur',
            /aucun compte ne le porte/.test(enjeu), enjeu.split('\n')[0]);
        check('… et le champ de motif est là', await formulaire.locator('input[name="reason"]').count() === 1);

        await formulaire.locator('input[name="reason"]').fill('Recette automatisee de la phase 11B');

        await Promise.all([p.waitForLoadState('networkidle'),
            formulaire.locator('button[type="submit"]').click()]);

        corps = await p.locator('body').innerText();
        check('il est désactivé', /désactivé/.test(corps),
            corps.split('\n').find((l) => /désactiv/.test(l)) || '');

        // =============================================================
        titre('LE TABLEAU DE BORD NE DÉBORDE PAS DE SON PÉRIMÈTRE');
        // =============================================================
        // Cet écran n'exige que `auth` : c'est le seul du produit ouvert
        // à tout compte connecté. Il montrait l'offre d'abonnement et le
        // nombre de comptes à un enseignant, à qui `/abonnement` et
        // `/utilisateurs` répondent 403.
        const pEnseignant = await ouvrirSession(nav, 'enseignant.demo', err);
        await pEnseignant.goto(BASE + '/tableau-de-bord');

        const vuEnseignant = await pEnseignant.locator('body').innerText();

        check('l\'enseignant ne voit pas l\'offre d\'abonnement',
            !/Abonnement ·/.test(vuEnseignant));
        check('… ni le nombre de comptes de l\'école',
            !/Comptes utilisateurs/.test(vuEnseignant));
        check('… ni les tâches d\'administration',
            !/Créer les comptes du personnel/.test(vuEnseignant));
        check('… mais il garde son année scolaire',
            /Année scolaire en cours/.test(vuEnseignant));

        const refus = await pEnseignant.goto(BASE + '/abonnement');
        check('… et l\'écran d\'abonnement lui reste fermé',
            refus.status() === 403, 'HTTP ' + refus.status());

        await p.goto(BASE + '/tableau-de-bord');
        const vuAdmin = await p.locator('body').innerText();

        check('l\'administrateur, lui, voit toujours l\'abonnement',
            /Abonnement ·/.test(vuAdmin));
        check('… et le nombre de comptes', /Comptes utilisateurs/.test(vuAdmin));

        check('aucun repère de chantier n\'est montré au client',
            !/Modules du logiciel|Phase 1 sur 10/.test(vuAdmin),
            'un produit vendu ne raconte pas son propre chantier');

        check('aucune erreur JavaScript sur le parcours', err.length === 0, err.slice(0, 2).join(' | '));
    } catch (e) {
        check('la recette va jusqu\'au bout', false, e.message);
    } finally {
        await nav.close();
    }

    console.log('\n  ' + ok + ' vérification(s) réussie(s), ' + ko + ' échec(s)\n');
    process.exit(ko > 0 ? 1 : 0);
})();
