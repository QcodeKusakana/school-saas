<?php
/**
 * Point d'entrée unique de l'application.
 *
 * Tout le trafic passe par ce fichier (voir public/.htaccess). C'est ce
 * qui garantit que la session, la vérification CSRF et le contrôle des
 * permissions ne peuvent jamais être contournés en appelant directement
 * un fichier PHP interne.
 *
 * DOCUMENT ROOT : ce dossier « public » doit être la racine web.
 * Sur cPanel, voir docs/DEPLOIEMENT.md — le .htaccess à la racine du
 * projet assure la redirection quand la racine web ne peut pas être changée.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

// ---------------------------------------------------------------------
// Filet de sécurité pour les fichiers statiques.
//
// Si une requête vers /assets/… parvient jusqu'ici, c'est qu'Apache ne
// l'a pas servie lui-même — racine web mal positionnée, .htaccess
// ignoré, mod_rewrite absent. On sert le fichier plutôt que de renvoyer
// un 404 qui afficherait la page sans aucun style.
//
// Placé AVANT les en-têtes de sécurité : un fichier statique n'a pas
// besoin de la politique de sécurité du contenu, qui ne vise que les
// documents HTML.
// ---------------------------------------------------------------------
if (str_starts_with(request_path(), '/assets/')) {
    serve_static_file(request_path());
}

// ---------------------------------------------------------------------
// En-têtes de sécurité, sur chaque réponse.
// ---------------------------------------------------------------------
response_security_headers();

// ---------------------------------------------------------------------
// Vérification CSRF globale.
//
// Appliquée ici, et non formulaire par formulaire : il devient impossible
// d'oublier de protéger une écriture.
// ---------------------------------------------------------------------
if (csrf_method_requires_check(request_method()) && !csrf_verify()) {
    log_warning('Jeton CSRF invalide ou absent', [
        'path'    => request_path(),
        'user_id' => $_SESSION['user_id'] ?? null,
    ]);

    if (is_ajax()) {
        json_error('Votre session a expiré. Veuillez recharger la page.', 419);
    }

    flash_error('Votre session a expiré. Merci de renvoyer le formulaire.');
    redirect_back('/login');
}

// ---------------------------------------------------------------------
// Chargement des routes puis résolution.
// ---------------------------------------------------------------------
require APP_PATH . '/config/routes.php';

router_dispatch();
