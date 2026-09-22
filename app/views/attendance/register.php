<?php
/**
 * Registre d'appel d'une classe, pour une date.
 *
 * Trois boutons par élève plutôt qu'une liste déroulante : l'appel se
 * fait debout, parfois sur téléphone, et doit tenir en un geste par
 * ligne. La durée du retard n'apparaît que lorsqu'elle a un sens.
 *
 * @var array      $classroom
 * @var array|null $year
 * @var string     $date
 * @var string     $slot
 * @var array      $slots
 * @var array|null $session
 * @var array      $students
 * @var array      $statuses
 * @var array      $canRecord
 */
declare(strict_types=1);

set_title('Appel — ' . $classroom['name']);

$editable = $canRecord['ok'];
$locked   = $session !== null && (int) $session['is_locked'] === 1;
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($classroom['name']) ?></h1>
        <p class="page-subtitle">
            <?= e(date('d/m/Y', strtotime($date))) ?> · <?= e($slots[$slot]) ?>
            · <?= count($students) ?> élève(s)
            <?php if ($session !== null): ?>
                · appel fait le <?= e(substr((string) $session['taken_at'], 0, 16)) ?>
            <?php endif; ?>
        </p>
    </div>

    <a href="<?= e(url('/presences', ['date' => $date, 'moment' => $slot])) ?>"
       class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Le jour
    </a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($session === null): ?>
    <div class="alert alert-secondary d-flex align-items-start gap-2">
        <i class="bi bi-pencil"></i>
        <div>
            <strong>Appel non fait.</strong>
            Tant qu'il n'est pas enregistré, cette classe apparaît comme
            « à faire » — et non comme une classe sans absent.
        </div>
    </div>
<?php elseif ($locked): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-lock-fill"></i>
        <div>
            <strong>Registre verrouillé.</strong>
            Seule la direction peut encore le corriger.
        </div>
    </div>
<?php endif; ?>

<?php if (!$editable && !$locked): ?>
    <div class="alert alert-info"><?= e($canRecord['message']) ?></div>
<?php endif; ?>

<?php if ($students === []): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-people display-6 text-secondary d-block mb-2"></i>
            <p class="mb-0 fw-medium">Aucun élève inscrit dans cette classe.</p>
        </div>
    </div>
<?php else: ?>
    <?php
    /* HORS CONNEXION (phase 8B).
       Le formulaire porte ce qu'il faut pour être rejoué plus tard :
       la classe, la date, le moment, et surtout `seen-updated` —
       l'état du registre AU MOMENT OÙ IL A ÉTÉ OUVERT. C'est lui qui
       permettra au serveur de distinguer « personne n'y a touché »
       de « quelqu'un l'a modifié pendant ma coupure », au lieu
       d'écraser en silence. */
    ?>
    <form method="post"
          action="<?= e(url('/presences/classe/' . (int) $classroom['id'], ['date' => $date, 'moment' => $slot])) ?>"
          data-appel
          data-classe="<?= (int) $classroom['id'] ?>"
          data-date="<?= e($date) ?>"
          data-moment="<?= e($slot) ?>"
          data-seen-updated="<?= e((string) ($session['updated_at'] ?? '')) ?>">
        <?= csrf_field() ?>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Élève</th>
                            <th scope="col" class="d-none d-md-table-cell">Matricule</th>
                            <th scope="col" class="text-center" style="min-width:14rem">Statut</th>
                            <th scope="col" class="text-center" style="width:8rem">Retard</th>
                            <th scope="col">Justification</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $row): ?>
                            <?php
                            $id      = (int) $row['enrollment_id'];
                            $nomComplet = trim((string) $row['last_name'] . ' '
                                . (string) ($row['post_name'] ?? '') . ' '
                                . (string) $row['first_name']);
                            // Par défaut « présent » : l'appel consiste à
                            // signaler les manquants, pas à cocher 45 fois.
                            $current = $row['status'] ?? 'present';
                            ?>
                            <tr data-eleve
                                data-inscription="<?= $id ?>"
                                data-nom="<?= e($nomComplet) ?>"
                                data-matricule="<?= e((string) ($row['matricule'] ?? '')) ?>">
                                <td>
                                    <span class="fw-medium">
                                        <?= e(full_name($row['last_name'], $row['post_name'], $row['first_name'])) ?>
                                    </span>
                                </td>
                                <td class="d-none d-md-table-cell small text-secondary">
                                    <?= e((string) $row['matricule']) ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group"
                                         aria-label="Statut de <?= e($row['last_name']) ?>">
                                        <?php foreach ($statuses as $key => $label): ?>
                                            <input type="radio" class="btn-check"
                                                   name="status[<?= $id ?>]"
                                                   id="s<?= $id ?>-<?= e($key) ?>"
                                                   value="<?= e($key) ?>"
                                                   <?= $current === $key ? 'checked' : '' ?>
                                                   <?= $editable ? '' : 'disabled' ?>>
                                            <label class="btn btn-outline-<?= $key === 'present' ? 'success' : ($key === 'absent' ? 'danger' : 'warning') ?>"
                                                   for="s<?= $id ?>-<?= e($key) ?>"><?= e($label) ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control text-end"
                                               name="minutes[<?= $id ?>]" min="0" max="600" step="5"
                                               value="<?= $row['minutes_late'] !== null ? (int) $row['minutes_late'] : '' ?>"
                                               <?= $editable ? '' : 'disabled' ?>>
                                        <span class="input-group-text">min</span>
                                    </div>
                                </td>
                                <td class="small">
                                    <?php if ($row['status'] === 'absent'): ?>
                                        <?php if ((int) $row['is_justified'] === 1): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis">justifiée</span>
                                            <span class="text-secondary"><?= e((string) $row['justification']) ?></span>
                                        <?php elseif (can('attendance.justify')): ?>
                                            <span class="text-secondary">non justifiée</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($editable): ?>
            <div class="mt-3 d-flex flex-wrap gap-2 align-items-center">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>
                    <?= $session === null ? 'Enregistrer l\'appel' : 'Corriger l\'appel' ?>
                </button>
                <span class="small text-secondary">
                    Chaque élève doit porter un statut : un élève omis n'est
                    ni présent ni absent, et l'appel est alors signalé incomplet.
                </span>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($session !== null && can('attendance.justify')): ?>
        <form method="post"
              action="<?= e(url('/presences/registre/' . (int) $session['id'] . '/verrou')) ?>"
              class="mt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="verrouiller" value="<?= $locked ? '0' : '1' ?>">
            <input type="hidden" name="retour"
                   value="<?= e('/presences/classe/' . (int) $classroom['id'] . '?date=' . $date . '&moment=' . $slot) ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-<?= $locked ? 'unlock' : 'lock' ?> me-1"></i>
                <?= $locked ? 'Déverrouiller le registre' : 'Verrouiller le registre' ?>
            </button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php
/* L'appel hors connexion. Chargé UNIQUEMENT sur cet écran : le reste
   du produit n'a rien à faire d'une file d'attente locale. */
?>
<?= script_tag('assets/js/attendance-offline.js') ?>
