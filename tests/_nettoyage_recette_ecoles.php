<?php
/**
 * Retire les établissements laissés par la recette navigateur de la 11A.
 *
 * FICHIER PLUTÔT QUE COMMANDE EN LIGNE.
 * La première version passait ce PHP en argument de `php -r` depuis
 * Node : illisible, fragile aux apostrophes, et impossible sous Windows
 * où le shell ne cite pas comme /bin/sh. La leçon de la correction 10A
 * vaut ici aussi — un outil destiné à la machine du développeur n'a pas
 * le droit de supposer le système.
 *
 * Usage : php tests/_nettoyage_recette_ecoles.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ligne de commande uniquement.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$retirees = platform_scope_cli(static function (): int {
    $ecoles = db_all(
        "SELECT id FROM schools WHERE slug LIKE 'recette-navigateur-%'",
        [],
        true
    );

    foreach ($ecoles as $ecole) {
        $id = (int) $ecole['id'];

        db_query(
            'DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE school_id = :s)',
            ['s' => $id],
            true
        );
        db_query(
            "DELETE FROM audit_logs WHERE entity_type = 'schools' AND entity_id = :s",
            ['s' => $id],
            true
        );
        db_query('DELETE FROM schools WHERE id = :s', ['s' => $id], true);
    }

    db_query("DELETE FROM login_attempts WHERE identifier LIKE 'nav.%'", [], true);

    return count($ecoles);
});

printf("  %d établissement(s) de recette retiré(s).\n", $retirees);
