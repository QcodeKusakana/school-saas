<?php
/**
 * Synchronisation — arbitrer ce que les appareils rapportent.
 *
 * CE QUE CET ÉCRAN DOIT RENDRE LISIBLE
 * =====================================
 * Un conflit n'est pas une erreur technique : c'est DEUX PERSONNES qui
 * ont travaillé sur le même appel, l'une hors connexion. La direction
 * doit voir en un coup d'œil ce que chacune a noté, puis trancher.
 *
 * On affiche donc les deux versions CÔTE À CÔTE, avec le nom de
 * l'auteur, l'appareil et l'heure. Un écran qui se contenterait de
 * dire « conflit » forcerait à rouvrir le registre dans un autre
 * onglet pour comprendre, et l'arbitrage se ferait à l'aveugle.
 *
 * LES DEUX BOUTONS SONT DÉLIBÉRÉMENT SYMÉTRIQUES. Rien ne suggère que
 * « garder le serveur » soit le choix par défaut : l'enseignant hors
 * connexion était souvent celui qui avait la classe devant lui.
 *
 * AUCUNE DES DEUX VERSIONS N'EST DÉTRUITE par l'arbitrage — l'écran le
 * dit, pour que la décision ne soit pas vécue comme irréversible.
 *
 * @var array $conflicts
 * @var array $devices
 * @var array $counts
 * @var array $statuses
 * @var array $resolutions
 */
declare(strict_types=1);

set_title('Synchronisation');

$aArbitrer = array_values(array_filter(
    $conflicts,
    static fn (array $c): bool => (string) $c['resolution'] === 'pending'
));

$arbitres = array_values(array_filter(
    $conflicts,
    static fn (array $c): bool => (string) $c['resolution'] !== 'pending'
));

/** Le nom de l'auteur, ou son identifiant à défaut. */
$auteur = static function (array $c): string {
    $nom = trim((string) ($c['last_name'] ?? '') . ' ' . (string) ($c['first_name'] ?? ''));

    return $nom !== '' ? $nom : (string) ($c['username'] ?? 'compte supprimé');
};

/** Les statuts d'une charge utile, comptés par nature. */
$resume = static function (array $entries): string {
    $n = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];

    foreach ($entries as $e) {
        $s = (string) ($e['status'] ?? '');

        if (isset($n[$s])) {
            $n[$s]++;
        }
    }

    $parts = [];

    foreach (['present' => 'présent', 'absent' => 'absent', 'late' => 'retard', 'excused' => 'justifié'] as $k => $mot) {
        if ($n[$k] > 0) {
            $parts[] = $n[$k] . ' ' . $mot . ($n[$k] > 1 ? 's' : '');
        }
    }

    return $parts === [] ? 'aucune saisie' : implode(' · ', $parts);
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Synchronisation</h1>
        <p class="page-subtitle">
            Ce que les appareils ont enregistré hors connexion.
            <?php if ($counts['a_arbitrer'] > 0): ?>
                <span class="text-danger fw-medium">
                    <?= (int) $counts['a_arbitrer'] ?> conflit(s) à arbitrer.
                </span>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Appliquées', (int) $counts['applied'], 'text-success', 'bi-check2-circle'],
        ['À arbitrer', (int) $counts['a_arbitrer'], 'text-danger', 'bi-exclamation-triangle'],
        ['Refusées', (int) $counts['rejected'], 'text-secondary', 'bi-x-circle'],
        ['En attente', (int) $counts['pending'], 'text-secondary', 'bi-hourglass-split'],
    ] as $carte): ?>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-secondary"><?= e($carte[0]) ?></div>
                    <div class="h4 mb-0 <?= e($carte[2]) ?>">
                        <i class="bi <?= e($carte[3]) ?> me-1"></i><?= $carte[1] ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ================================================================
     LES CONFLITS À ARBITRER
     ================================================================ -->
<h2 class="h5 mb-3">À arbitrer</h2>

<?php if ($aArbitrer === []): ?>
    <div class="card mb-4">
        <div class="card-body text-secondary">
            Aucun conflit en attente. Les appels faits hors connexion se sont
            appliqués sans rencontrer de version concurrente.
        </div>
    </div>
<?php else: ?>
    <?php foreach ($aArbitrer as $c): ?>
        <?php
        $serveur = (array) json_decode((string) $c['server_values'], true);
        $client  = (array) json_decode((string) $c['client_values'], true);
        $charge  = (array) json_decode((string) $c['payload'], true);
        ?>
        <div class="card mb-3 border-warning">
            <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <span class="fw-medium">
                    Conflit n° <?= (int) $c['id'] ?> ·
                    <?= e($auteur($c)) ?>
                    <?php if (($c['device_label'] ?? '') !== ''): ?>
                        <span class="text-secondary">(<?= e((string) $c['device_label']) ?>)</span>
                    <?php endif; ?>
                </span>
                <span class="small text-secondary">
                    saisi le <?= e(substr((string) $c['client_time'], 0, 16)) ?>
                    · reçu le <?= e(substr((string) $c['created_at'], 0, 16)) ?>
                </span>
            </div>

            <div class="card-body">
                <p class="small text-secondary">
                    Classe n° <?= (int) ($charge['classroom_id'] ?? 0) ?>,
                    <?= e((string) ($charge['date'] ?? '')) ?>.
                    L'appel a changé sur le serveur pendant que cet appareil était
                    hors connexion.
                </p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-medium mb-1">
                                <i class="bi bi-hdd-network me-1"></i> Version du serveur
                            </div>
                            <div class="small text-secondary">
                                <?php if (($serveur['updated_at'] ?? null) === null): ?>
                                    Aucun appel n'existait à ce moment.
                                <?php else: ?>
                                    Modifiée le <?= e(substr((string) $serveur['updated_at'], 0, 16)) ?>.
                                    <?php if ((int) ($serveur['is_locked'] ?? 0) === 1): ?>
                                        <span class="text-danger">Registre verrouillé.</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-medium mb-1">
                                <i class="bi bi-phone me-1"></i> Version de l'appareil
                            </div>
                            <div class="small text-secondary">
                                <?= e($resume((array) ($client['entries'] ?? []))) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="small text-secondary mt-3 mb-2">
                    Quelle que soit votre décision, <strong>les deux versions restent
                    consultables</strong> : l'arbitrage n'efface rien.
                </p>

                <div class="d-flex flex-wrap gap-2">
                    <form method="post"
                          action="<?= e(url('/synchronisation/conflit/' . (int) $c['id'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="server_wins">
                        <button type="submit" class="btn btn-outline-primary">
                            Conserver la version du serveur
                        </button>
                    </form>

                    <form method="post"
                          action="<?= e(url('/synchronisation/conflit/' . (int) $c['id'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="client_wins">
                        <button type="submit" class="btn btn-outline-primary">
                            Appliquer la version de l'appareil
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ================================================================
     LES APPAREILS
     ================================================================ -->
<h2 class="h5 mb-3 mt-4">Appareils</h2>

<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Appareil</th>
                    <th>Compte</th>
                    <th>Dernière synchronisation</th>
                    <th class="text-end">Conflits</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($devices === []): ?>
                    <tr>
                        <td colspan="4" class="text-secondary py-4 text-center">
                            Aucun appareil ne s'est encore annoncé.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($devices as $d): ?>
                    <tr>
                        <td><?= e((string) ($d['device_label'] ?? 'sans nom')) ?></td>
                        <td>
                            <?= e(trim((string) $d['last_name'] . ' ' . (string) $d['first_name'])
                                ?: (string) ($d['username'] ?? '—')) ?>
                        </td>
                        <td class="text-secondary">
                            <?= $d['last_sync_at'] !== null
                                ? e(substr((string) $d['last_sync_at'], 0, 16))
                                : '<span class="text-secondary">jamais</span>' ?>
                        </td>
                        <td class="text-end">
                            <?php if ((int) $d['conflits'] > 0): ?>
                                <span class="badge text-bg-warning"><?= (int) $d['conflits'] ?></span>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================================================================
     L'HISTORIQUE DES ARBITRAGES
     ================================================================ -->
<?php if ($arbitres !== []): ?>
    <h2 class="h5 mb-3">Arbitrages passés</h2>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Conflit</th>
                        <th>Auteur de la saisie</th>
                        <th>Décision</th>
                        <th>Arbitré le</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($arbitres as $c): ?>
                        <tr>
                            <td>n° <?= (int) $c['id'] ?></td>
                            <td><?= e($auteur($c)) ?></td>
                            <td><?= e($resolutions[(string) $c['resolution']] ?? (string) $c['resolution']) ?></td>
                            <td class="text-secondary">
                                <?= e(substr((string) $c['resolved_at'], 0, 16)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
