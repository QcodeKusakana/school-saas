<?php
/**
 * Erreur 403 — page autonome, sans dépendance à la base de données.
 */
declare(strict_types=1);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accès refusé</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            padding: 2rem 1.25rem; background: #f6f7f9; color: #1f2933;
            font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .box { max-width: 460px; text-align: center; }
        .code { font-size: 3.75rem; font-weight: 700; color: #b45309; line-height: 1; margin: 0 0 .5rem; }
        h1 { font-size: 1.3rem; margin: 0 0 .65rem; font-weight: 600; }
        p { margin: 0 0 1.75rem; color: #52606d; }
        a {
            display: inline-block; padding: .65rem 1.4rem; border-radius: 7px;
            background: #0f3d3e; color: #fff; text-decoration: none; font-weight: 500;
        }
        a:hover { background: #135354; }
    </style>
</head>
<body>
    <div class="box">
        <p class="code">403</p>
        <h1>Accès refusé</h1>
        <p>Vous n'avez pas l'autorisation d'accéder à cette page. Rapprochez-vous de l'administrateur de votre établissement si vous pensez qu'il s'agit d'une erreur.</p>
        <a href="/">Retour à l'accueil</a>
    </div>
</body>
</html>
