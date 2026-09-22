<?php
/**
 * TRAVAIL PÉRIODIQUE — rejoue les messages en attente.
 *
 *   php bin/mail_worker.php            un passage, 25 messages au plus
 *   php bin/mail_worker.php --lot=100  un passage plus large
 *   php bin/mail_worker.php --silence  n'écrit rien s'il n'y a rien à faire
 *
 * À PLANIFIER SUR L'HÉBERGEMENT. Sans lui, seuls les envois qui
 * réussissent du premier coup arrivent : tout ce qui échoue reste en
 * file indéfiniment. Sur cPanel, tâche cron toutes les minutes :
 *
 *   * * * * * /usr/local/bin/php /home/COMPTE/school-saas/bin/mail_worker.php --silence
 *
 * EN LIGNE DE COMMANDE UNIQUEMENT. La file traverse les écoles par
 * nature : `mail_worker_run()` passe par `platform_scope_cli()`, qui
 * lève si le script est appelé depuis le web. Un travail transversal
 * accessible par URL serait une porte ouverte sur le parc entier.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script ne s'exécute qu'en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['lot::', 'silence', 'help']);

if (isset($options['help'])) {
    echo <<<TXT

    Travail périodique d'envoi — School SaaS RDC
    --------------------------------------------
      php bin/mail_worker.php              un passage (25 messages au plus)
      php bin/mail_worker.php --lot=100    un passage plus large
      php bin/mail_worker.php --silence    silencieux s'il n'y a rien à faire

    À planifier toutes les minutes. Sans lui, un message qui échoue
    au premier essai n'est jamais rejoué.

    TXT;
    exit(0);
}

$batch  = isset($options['lot']) ? (int) $options['lot'] : MAIL_WORKER_BATCH;
$silent = isset($options['silence']);

try {
    $result = mail_worker_run($batch);
} catch (Throwable $e) {
    // UN ÉCHEC DU TRAVAIL LUI-MÊME DOIT SE VOIR.
    // Sortir en code 1 permet à la tâche planifiée de le signaler,
    // au lieu de laisser la file s'accumuler en silence.
    fwrite(STDERR, "  ✗ Travail interrompu : " . $e->getMessage() . "\n");
    log_error('Travail d\'envoi interrompu', ['error' => $e->getMessage()]);
    exit(1);
}

if ($silent && $result['traites'] === 0) {
    exit(0);
}

printf(
    "  %d message(s) traité(s) — %d envoyé(s), %d en échec.\n",
    $result['traites'],
    $result['envoyes'],
    $result['echoues']
);

// Le code de sortie ne signale PAS un message en échec : un serveur
// distant momentanément indisponible est un cas normal, que le rejeu
// couvre. Seule une panne du travail lui-même mérite une alerte.
exit(0);
