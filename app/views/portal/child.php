<?php
/**
 * PORTAIL — le dossier d'un enfant.
 *
 * Lecture seule. Trois sections : les bulletins publiés, les absences
 * enregistrées, la situation financière.
 *
 * CE QUI N'EST PAS AFFICHÉ, ET POURQUOI
 * -------------------------------------
 * · aucun taux d'assiduité : une journée sans appel n'est pas une
 *   journée sans absent, et un pourcentage calculé sur une couverture
 *   incomplète serait faux (leçon de la phase 4D) ;
 * · aucun bulletin non publié : un document de travail n'est pas un
 *   document remis ;
 * · aucun autre élève, aucun classement de classe.
 *
 * @var array  $student
 * @var ?array $enrollment
 * @var array  $bulletins
 * @var array  $absences
 * @var array  $counts
 * @var array  $balance
 * @var bool   $showFinance Le solde est-il montré à ce lecteur ?
 */
declare(strict_types=1);

set_title('Dossier de l\'élève');

$slots = ['day' => 'Journée', 'morning' => 'Matin', 'afternoon' => 'Après-midi'];
?>

<div class="page-head">
    <div>
        <h1 class="page-title">
            <?= e(full_name($student['last_name'], $student['post_name'], $student['first_name'])) ?>
        </h1>
        <p class="page-subtitle">
            <?= e((string) $student['matricule']) ?>
            <?php if ($enrollment !== null): ?>
                · <?= e((string) ($enrollment['classroom_name'] ?? 'Sans classe')) ?>
                · Année <?= e((string) $enrollment['year_code']) ?>
            <?php endif; ?>
        </p>
    </div>

    <a class="btn btn-outline-secondary" href="<?= e(url('/espace')) ?>">
        <i class="bi bi-arrow-left me-1"></i> Mes enfants
    </a>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<div class="row g-3">
    <!-- ======================== LES BULLETINS ======================== -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header fw-medium">Bulletins publiés</div>

            <?php if ($bulletins === []): ?>
                <div class="card-body text-center py-4">
                    <p class="mb-1 fw-medium">Aucun bulletin publié.</p>
                    <p class="small text-secondary mb-0">
                        Les bulletins apparaissent ici dès que l'établissement les publie.
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Période</th>
                                <th scope="col" class="d-none d-md-table-cell">Classe</th>
                                <th scope="col" class="text-end">Résultat</th>
                                <th scope="col" class="text-end d-none d-sm-table-cell">Rang</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bulletins as $b): ?>
                                <tr>
                                    <td>
                                        <strong><?= e((string) $b['period_key']) ?></strong>
                                        <div class="small text-secondary">
                                            <?= e((string) ($b['year_code'] ?? '')) ?>
                                            · publié le
                                            <?= e(date('d/m/Y', strtotime((string) $b['published_at']))) ?>
                                        </div>
                                    </td>
                                    <td class="d-none d-md-table-cell small">
                                        <?= e((string) ($b['classroom_name'] ?? '—')) ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?= $b['percentage'] !== null
                                            ? e(number_format((float) $b['percentage'], 1, ',', ' ')) . ' %'
                                            : '—' ?>
                                    </td>
                                    <td class="text-end text-nowrap d-none d-sm-table-cell">
                                        <?php if ($b['class_rank'] !== null): ?>
                                            <?= (int) $b['class_rank'] ?>/<?= (int) $b['class_size'] ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="<?= e(url('/espace/bulletin/' . (int) $b['id'])) ?>">
                                            Voir
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===================== LA SITUATION FINANCIÈRE ================= -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-medium">Frais scolaires</div>
            <div class="card-body">
                <?php if (!$showFinance): ?>
                    <!-- LA DETTE EST UNE AFFAIRE DE PARENTS.
                         L'élève ne voit son solde que si l'établissement l'a
                         décidé (portal.student_sees_fees, faux par défaut). -->
                    <p class="text-secondary mb-0">
                        Les frais scolaires sont suivis avec vos parents ou tuteurs.
                    </p>
                <?php elseif ($balance === []): ?>
                    <p class="text-secondary mb-0">Aucun frais affecté pour cette année.</p>
                <?php else: ?>
                    <?php foreach ($balance as $currency => $line): ?>
                        <div class="border rounded p-2 mb-2">
                            <div class="d-flex justify-content-between small">
                                <span class="text-secondary">Dû</span>
                                <span><?= e(finance_amount((float) $line['due'], (string) $currency)) ?></span>
                            </div>
                            <div class="d-flex justify-content-between small">
                                <span class="text-secondary">Payé</span>
                                <span><?= e(finance_amount((float) $line['paid'], (string) $currency)) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span class="fw-medium">Reste à payer</span>
                                <span class="fw-semibold <?= $line['balance'] > 0.005 ? 'text-danger-emphasis' : 'text-success-emphasis' ?>">
                                    <?= e(finance_amount((float) $line['balance'], (string) $currency)) ?>
                                </span>
                            </div>
                            <?php if ((float) $line['advance'] > 0.005): ?>
                                <div class="d-flex justify-content-between small text-success-emphasis">
                                    <span>Avance versée</span>
                                    <span><?= e(finance_amount((float) $line['advance'], (string) $currency)) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <p class="small text-secondary mb-2">
                        Chaque monnaie se solde dans sa propre devise : les montants ne
                        s'additionnent jamais entre eux.
                    </p>
                <?php endif; ?>

                <?php if ($showFinance && $enrollment !== null && can('finance.view')): ?>
                    <a class="btn btn-outline-secondary btn-sm w-100"
                       href="<?= e(url('/finances/eleve/' . (int) $enrollment['id'] . '/avis')) ?>">
                        Avis de paiement imprimable
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ========================== LES ABSENCES =========================== -->
<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-medium">Absences et retards</span>
        <span class="small text-secondary">
            <?= (int) $counts['absences'] ?> absence(s)
            · <?= (int) $counts['retards'] ?> retard(s)
            · <?= (int) $counts['justifiees'] ?> justifié(s)
        </span>
    </div>

    <?php if ($absences === []): ?>
        <div class="card-body text-center py-4">
            <p class="mb-1 fw-medium">Aucune absence enregistrée.</p>
            <p class="small text-secondary mb-0">
                Seuls les appels effectivement faits sont comptés : une journée sans
                appel n'apparaît pas comme une journée sans absent.
            </p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col" class="d-none d-sm-table-cell">Moment</th>
                        <th scope="col">Statut</th>
                        <th scope="col">Justification</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($absences as $a): ?>
                        <tr>
                            <td class="text-nowrap">
                                <?= e(date('d/m/Y', strtotime((string) $a['session_date']))) ?>
                            </td>
                            <td class="d-none d-sm-table-cell small">
                                <?= e($slots[(string) $a['slot']] ?? (string) $a['slot']) ?>
                            </td>
                            <td>
                                <?php if ((string) $a['status'] === 'late'): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis">
                                        Retard<?= $a['minutes_late'] !== null ? ' (' . (int) $a['minutes_late'] . ' min)' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis">Absent</span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ((int) $a['is_justified'] === 1): ?>
                                    <span class="text-success-emphasis">Justifié</span>
                                    <?php if ($a['justification'] !== null && $a['justification'] !== ''): ?>
                                        — <?= e((string) $a['justification']) ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-secondary">Non justifié</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-body pt-2">
            <?php
            // UN ÉCRAN QUI TRONQUE DOIT LE DIRE.
            //
            // Le décompte du bandeau porte sur TOUTE l'inscription ; la
            // liste, elle, s'arrête au plafond. Sans cette phrase, un
            // parent comptait les lignes et concluait que le décompte
            // était faux (audit 6A).
            $shown = count($absences);
            $total = (int) $counts['absences'] + (int) $counts['retards'];
            ?>
            <?php if ($shown < $total): ?>
                <p class="small text-warning-emphasis mb-1">
                    Les <?= (int) $shown ?> événements les plus récents sont affichés,
                    sur <?= (int) $total ?> enregistrés. Pour la liste complète,
                    demandez-la au secrétariat.
                </p>
            <?php endif; ?>

            <p class="small text-secondary mb-0">
                Pour justifier une absence, adressez-vous au secrétariat : la
                justification est enregistrée par l'établissement.
            </p>
        </div>
    <?php endif; ?>
</div>
