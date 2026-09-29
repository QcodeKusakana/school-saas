<?php
/**
 * Retire le décor de `tests/roles_isolation.php`.
 *
 * FICHIER SÉPARÉ, ET APPELABLE SEUL.
 * `abort()` et le garde multi-école terminent le processus par `exit()`,
 * qui saute les blocs `finally`. Un nettoyage écrit là serait sauté
 * précisément les jours où il sert — ceux où un test échoue.
 *
 * Usage direct : php tests/_nettoyage_roles.php
 */
declare(strict_types=1);

if (!defined('APP_PATH')) {
    require dirname(__DIR__) . '/app/bootstrap.php';
}

platform_scope_cli(static function (): void {
    $ids = array_map('intval', array_column(
        db_all("SELECT id FROM schools WHERE name LIKE 'Recette 11B%'", [], true),
        'id'
    ));

    foreach ($ids as $id) {
        db_query(
            'DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE school_id = :s)',
            ['s' => $id],
            true
        );
        db_query("DELETE FROM audit_logs WHERE entity_type = 'schools' AND entity_id = :s",
            ['s' => $id], true);
        db_query('DELETE FROM schools WHERE id = :s', ['s' => $id], true);
    }

    db_query("DELETE FROM login_attempts WHERE identifier LIKE 'recette11b.%'", [], true);

    // LA RECETTE NAVIGATEUR TRAVAILLE DANS L'ÉCOLE DE DÉMONSTRATION.
    // Elle y compose un rôle réel, puis le désactive — le produit ne
    // supprimant pas les rôles, il resterait un « Surveillant recette »
    // de plus à chaque passage, et l'écran de démonstration finirait
    // encombré de résidus de tests.
    $roles = db_all(
        "SELECT id FROM roles WHERE school_id IS NOT NULL AND name LIKE 'Surveillant recette %'",
        [],
        true
    );

    foreach ($roles as $role) {
        db_query('DELETE FROM roles WHERE id = :i', ['i' => (int) $role['id']], true);
    }

    db_query("DELETE FROM audit_logs WHERE entity_type = 'roles'", [], true);

    printf(
        "\n  Décor retiré : %d école(s) de recette, %d rôle(s) de démonstration.\n",
        count($ids),
        count($roles)
    );
});
