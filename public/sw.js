/**
 * SERVICE WORKER — School SaaS RDC
 *
 * CE QU'IL FAIT, ET CE QU'IL SE REFUSE À FAIRE
 * =============================================
 * Il met en cache la COQUILLE de l'application : feuilles de style,
 * polices, scripts. Ces fichiers ne changent qu'à un déploiement, et
 * sans eux une page hors connexion est illisible.
 *
 * IL NE MET EN CACHE AUCUNE PAGE HTML, ET C'EST LE POINT IMPORTANT.
 * Une page du produit contient des noms d'élèves, des cotes, des
 * soldes. La garder dans le cache du navigateur reviendrait à en
 * laisser une copie sur un appareil souvent partagé, hors de toute
 * gestion de session : se déconnecter n'effacerait rien.
 *
 *   > Un cache qui survit à la déconnexion n'est plus un cache, c'est
 *   > une fuite.
 *
 * Les données dont l'appel hors connexion a besoin vivent donc dans
 * IndexedDB, sous le contrôle de l'application — qui les efface à la
 * déconnexion et les borne dans le temps. Voir offline.js.
 *
 * UNE REQUÊTE DE NAVIGATION QUI ÉCHOUE rend la page de repli, laquelle
 * explique ce qui reste possible. Elle ne prétend jamais que la page
 * demandée est disponible.
 *
 * AUCUNE REQUÊTE POST N'EST INTERCEPTÉE. Rejouer une écriture depuis
 * le service worker doublerait ce que la file d'attente fait déjà, et
 * les deux mécanismes finiraient par se contredire. Une seule file.
 *
 * ON DÉCIDE PAR APPARTENANCE, JAMAIS PAR MÉTHODE
 * ===============================================
 * Première version de ce fichier : tout GID de même origine était
 * intercepté, et toute réponse `ok` recopiée dans le cache. Mesuré en
 * navigateur réel : `/sync/etat` — une route JSON authentifiée — s'y
 * retrouvait. Toute route AJAX du produit y serait allée de même :
 * recherche d'élève, données de graphique, listes paginées. Autrement
 * dit des noms, des cotes et des soldes, déposés hors de toute session.
 *
 * Plus grave que la fuite : la stratégie était « cache d'abord ». Sur
 * une tablette partagée, le deuxième enseignant aurait reçu le JSON du
 * premier, EN LIGNE, sans requête réseau. Ce n'est plus une fuite,
 * c'est une réponse fausse servie comme fraîche.
 *
 *   > Un cache qui n'énumère pas ce qu'il garde garde tout — et un
 *   > cache-d'abord qui garde tout finit par répondre à la place du
 *   > serveur.
 *
 * Désormais : une requête est servie depuis le cache UNIQUEMENT si son
 * chemin appartient à la coquille (`appartientALaCoquille`). Tout le
 * reste sort du champ du service worker — on ne rend pas la main avec
 * `respondWith`, le navigateur fait son travail habituel.
 */

// v2 : la version change pour que l'`activate` SUPPRIME le cache `v1`.
// Celui-ci peut contenir des réponses applicatives mises en cache par
// erreur avant la correction de la frontière (voir l'en-tête). Un
// correctif qui laisse en place les données déjà déposées ne corrige
// que l'avenir.
const VERSION = 'v2';
const SHELL   = 'coquille-' + VERSION;

/**
 * La coquille. Volontairement courte : chaque fichier ici est un
 * fichier qui ne se rafraîchira qu'au changement de VERSION.
 */
const FICHIERS = [
  '/offline.html',
  '/assets/css/app.css',
  '/assets/js/app.js',
  '/assets/js/offline.js',
  '/assets/js/offline-retry.js',
  '/assets/js/attendance-offline.js',
  '/assets/vendor/bootstrap/css/bootstrap.min.css',
  '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
  '/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css',
  '/manifest.webmanifest'
];

/**
 * LA FRONTIÈRE DE LA COQUILLE.
 *
 * Elle est délibérément étroite et fondée sur des PRÉFIXES DE CHEMIN,
 * pas sur un type de contenu ni sur une extension : un chemin est une
 * propriété de la ROUTE, qu'un contrôleur ne peut pas changer par
 * accident, alors qu'un `content-type` dépend de ce que renvoie le
 * serveur ce jour-là.
 *
 * Ajouter une entrée ici, c'est accepter que le fichier survive à la
 * déconnexion. Ne l'élargir qu'à des ressources statiques et publiques.
 */
const COQUILLE = ['/assets/', '/manifest.webmanifest', '/offline.html'];

function appartientALaCoquille(chemin) {
  return COQUILLE.some((p) => (p.endsWith('/') ? chemin.startsWith(p) : chemin === p));
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL)
      // `addAll` échoue en bloc si UN fichier manque. On préfère une
      // installation partielle à pas d'installation du tout : un
      // fichier de police absent ne doit pas priver l'école du mode
      // hors connexion.
      .then((cache) => Promise.allSettled(FICHIERS.map((f) => cache.add(f))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((noms) => Promise.all(
        noms.filter((n) => n !== SHELL).map((n) => caches.delete(n))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // On ne touche qu'aux lectures, et seulement sur notre propre origine.
  if (req.method !== 'GET' || new URL(req.url).origin !== self.location.origin) {
    return;
  }

  // NAVIGATION : le réseau d'abord, la page de repli si rien ne vient.
  // Jamais de HTML servi depuis le cache — voir l'en-tête de ce fichier.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/offline.html'))
    );
    return;
  }

  // TOUT CE QUI N'EST PAS LA COQUILLE SORT DE NOTRE CHAMP.
  // On ne rend pas la main avec `respondWith` : le navigateur traite la
  // requête comme si ce fichier n'existait pas. Rien n'est lu du cache,
  // rien n'y est écrit. C'est la seule façon de garantir qu'une route
  // applicative — présente aujourd'hui ou ajoutée demain — ne laisse
  // aucune trace sur l'appareil.
  if (!appartientALaCoquille(new URL(req.url).pathname)) {
    return;
  }

  // RESSOURCES DE LA COQUILLE : le cache d'abord, c'est leur raison
  // d'être. Une mise à jour en arrière-plan garde le cache frais sans
  // faire attendre la page.
  event.respondWith(
    caches.match(req).then((enCache) => {
      const surLeReseau = fetch(req)
        .then((reponse) => {
          if (reponse && reponse.ok && reponse.type === 'basic') {
            const copie = reponse.clone();
            caches.open(SHELL).then((c) => c.put(req, copie));
          }
          return reponse;
        })
        .catch(() => enCache);

      return enCache || surLeReseau;
    })
  );
});

/**
 * L'application demande la purge — à la déconnexion, notamment.
 * Le service worker n'en décide pas seul : c'est la page qui sait
 * quand la session se termine.
 */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'purger-cache') {
    event.waitUntil(
      caches.keys().then((noms) => Promise.all(noms.map((n) => caches.delete(n))))
    );
  }
});
