<?php
/**
 * Page de diagnostic de l'installation.
 *
 * Vérifie en un coup d'œil ce qui sépare une page qui s'affiche d'une
 * page qui s'affiche AVEC ses styles : la valeur de app.url, le
 * DocumentRoot réellement utilisé par Apache, la présence physique des
 * fichiers statiques et la réponse HTTP qu'ils renvoient.
 *
 * SÉCURITÉ : accessible uniquement en mode debug ET depuis la machine
 * locale. À SUPPRIMER avant toute mise en production.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal  = in_array($clientIp, ['127.0.0.1', '::1'], true);

if (!config('app.debug') || !$isLocal) {
    http_response_code(404);
    exit('Introuvable.');
}

/** Ressources que le layout tente de charger. */
$assets = [
    'assets/vendor/bootstrap/css/bootstrap.min.css',
    'assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css',
    'assets/css/app.css',
    'assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
    'assets/js/app.js',
];

/** Racine web réellement utilisée par Apache. */
$documentRoot = str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$publicDir    = str_replace('\\', '/', (string) realpath(__DIR__));
$rootIsPublic = rtrim($documentRoot, '/') === rtrim($publicDir, '/');

/** Cohérence entre app.url et l'adresse réellement utilisée. */
$configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);
$configuredPort = parse_url((string) config('app.url'), PHP_URL_PORT);
$actualHost     = $_SERVER['HTTP_HOST'] ?? '';
$expectedHost   = $configuredHost . ($configuredPort ? ':' . $configuredPort : '');
$urlMatches     = $expectedHost === $actualHost;
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnostic — School SaaS RDC</title>
<style>
    body{font:14px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;margin:0;background:#f6f7f9;color:#1f2933}
    .wrap{max-width:900px;margin:0 auto;padding:2rem 1.25rem}
    h1{font-size:1.3rem;margin:0 0 .25rem}
    .sub{color:#52606d;margin:0 0 1.75rem}
    h2{font-size:.78rem;text-transform:uppercase;letter-spacing:.07em;color:#7b8794;margin:1.75rem 0 .6rem}
    table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e4e7eb;border-radius:8px;overflow:hidden}
    th,td{text-align:left;padding:.6rem .85rem;border-bottom:1px solid #eef1f4;vertical-align:top}
    th{width:230px;color:#52606d;font-weight:500}
    tr:last-child th,tr:last-child td{border-bottom:0}
    code{background:#f1f3f5;padding:.12rem .35rem;border-radius:4px;font-size:12.5px;word-break:break-all}
    .ok{color:#166534;font-weight:600}
    .ko{color:#b91c1c;font-weight:600}
    .warn{color:#92400e;font-weight:600}
    .note{margin-top:1.75rem;padding:.9rem 1.1rem;background:#fff;border-left:3px solid #0f3d3e;border-radius:0 6px 6px 0}
    .note strong{display:block;margin-bottom:.3rem}
    .probe{width:18px;height:18px;border-radius:4px;display:inline-block;vertical-align:middle}
</style>
</head>
<body>
<div class="wrap">

    <h1>Diagnostic de l'installation</h1>
    <p class="sub">Supprimez ce fichier avant toute mise en production.</p>

    <h2>1. Adresse configurée</h2>
    <table>
        <tr>
            <th>app.url (config.local.php)</th>
            <td><code><?= e(config('app.url')) ?></code></td>
        </tr>
        <tr>
            <th>Adresse réellement utilisée</th>
            <td><code><?= e($actualHost) ?></code></td>
        </tr>
        <tr>
            <th>Concordance</th>
            <td>
                <?php if ($urlMatches): ?>
                    <span class="ok">✓ identiques</span>
                <?php else: ?>
                    <span class="ko">✗ DIFFÉRENTES</span> — c'est la cause la plus fréquente
                    d'une page sans style : les liens CSS pointent vers
                    <code><?= e($expectedHost) ?></code> alors que vous naviguez sur
                    <code><?= e($actualHost) ?></code>.
                    Corrigez <code>app.url</code> dans <code>app/config/config.local.php</code>.
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <h2>2. Racine web (DocumentRoot)</h2>
    <table>
        <tr>
            <th>DocumentRoot Apache</th>
            <td><code><?= e($documentRoot) ?></code></td>
        </tr>
        <tr>
            <th>Dossier public du projet</th>
            <td><code><?= e($publicDir) ?></code></td>
        </tr>
        <tr>
            <th>Configuration</th>
            <td>
                <?php if ($rootIsPublic): ?>
                    <span class="ok">✓ optimale</span> — la racine web pointe directement sur <code>public/</code>.
                <?php else: ?>
                    <span class="warn">⚠ acceptable</span> — la racine web pointe ailleurs ;
                    le <code>.htaccess</code> de la racine du projet assure la redirection.
                    Rechargez Apache dans Laragon pour qu'il détecte <code>public/</code>.
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <h2>3. Fichiers statiques</h2>
    <table>
        <tr>
            <th>Fichier</th>
            <td><strong>Présent sur le disque</strong> &nbsp;·&nbsp; <strong>URL générée</strong></td>
        </tr>
        <?php foreach ($assets as $asset): ?>
            <?php
            $diskPath = __DIR__ . '/' . $asset;
            $exists   = is_file($diskPath);
            ?>
            <tr>
                <th><code><?= e($asset) ?></code></th>
                <td>
                    <?php if ($exists): ?>
                        <span class="ok">✓ <?= number_format((int) filesize($diskPath) / 1024, 1, ',', ' ') ?> Ko</span>
                    <?php else: ?>
                        <span class="ko">✗ ABSENT</span>
                    <?php endif; ?>
                    <br>
                    <code><?= e(asset($asset)) ?></code>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>4. Test de chargement réel</h2>
    <p>
        Le carré ci-dessous devient <strong>vert</strong> si la feuille de style est
        bien téléchargée ET appliquée par le navigateur. S'il reste
        <strong>rouge</strong>, ouvrez directement l'URL affichée en face de
        <code>assets/css/app.css</code> ci-dessus : le navigateur vous dira s'il
        s'agit d'un 404 (fichier non servi) ou d'un problème de type MIME.
    </p>
    <p>
        <span class="probe" id="probe" style="background:#b91c1c"></span>
        <span id="probe-text">Feuille de style non appliquée</span>
    </p>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">

    <h2>5. Environnement</h2>
    <table>
        <tr><th>PHP</th><td><code><?= e(PHP_VERSION) ?></code> (<?= e(PHP_SAPI) ?>)</td></tr>
        <tr>
            <th>Extensions requises</th>
            <td>
                <?php foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'json'] as $extension): ?>
                    <?= extension_loaded($extension)
                        ? '<span class="ok">✓ ' . e($extension) . '</span>'
                        : '<span class="ko">✗ ' . e($extension) . '</span>' ?>&nbsp;&nbsp;
                <?php endforeach; ?>
            </td>
        </tr>
        <tr>
            <th>mod_rewrite</th>
            <td>
                <?php
                // Indisponible en FastCGI : l'absence de réponse n'est pas un échec.
                $modules = function_exists('apache_get_modules') ? apache_get_modules() : null;
                ?>
                <?php if ($modules === null): ?>
                    <span class="warn">non détectable</span> — PHP tourne en FastCGI.
                    Si cette page s'affiche à l'adresse <code>/diagnostic.php</code>
                    et que <code>/login</code> fonctionne, mod_rewrite est actif.
                <?php else: ?>
                    <?= in_array('mod_rewrite', $modules, true)
                        ? '<span class="ok">✓ actif</span>'
                        : '<span class="ko">✗ inactif</span>' ?>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Base de données</th>
            <td>
                <?php
                try {
                    $version = db_value('SELECT VERSION()', [], true);
                    $tables  = (int) db_value(
                        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()',
                        [],
                        true
                    );
                    echo '<span class="ok">✓ connectée</span> — ' . e((string) $version)
                        . ' · ' . $tables . ' table' . ($tables > 1 ? 's' : '');

                    if ($tables === 0) {
                        echo '<br><span class="ko">Base vide</span> : exécutez '
                            . '<code>php database\install.php --demo</code>';
                    }
                } catch (Throwable $e) {
                    echo '<span class="ko">✗ ' . e($e->getMessage()) . '</span>';
                }
                ?>
            </td>
        </tr>
    </table>

    <div class="note">
        <strong>Après correction</strong>
        Supprimez ce fichier : <code>public/diagnostic.php</code>.
        Il n'est accessible qu'en mode debug depuis la machine locale, mais il
        n'a rien à faire sur un serveur en ligne.
    </div>

</div>

<script nonce="<?= e(response_csp_nonce()) ?>">
    // La sonde lit une variable CSS définie dans app.css. Si elle a une
    // valeur, la feuille de style a bien été téléchargée ET appliquée.
    window.addEventListener('load', function () {
        var brand = getComputedStyle(document.documentElement)
            .getPropertyValue('--brand').trim();

        if (brand !== '') {
            document.getElementById('probe').style.background = '#166534';
            document.getElementById('probe-text').textContent =
                'Feuille de style chargée et appliquée (--brand = ' + brand + ')';
        }
    });
</script>
</body>
</html>
