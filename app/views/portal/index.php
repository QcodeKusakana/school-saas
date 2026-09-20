<?php
/**
 * PORTAIL — mes enfants.
 *
 * Une carte par enfant, avec les trois choses qu'un parent vient
 * vraiment chercher : où en est la scolarité, combien reste-t-il à
 * payer, combien d'absences.
 *
 * @var array $cards
 * @var bool  $showFees Le réglage de l'établissement pour l'élève lui-même
 */
declare(strict_types=1);

set_title('Mon espace');
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Mon espace</h1>
        <?php
        $selfCount = count(array_filter($cards, static fn (array $c): bool => $c['is_self']));
        $wardCount = count($cards) - $selfCount;
        ?>
        <p class="page-subtitle">
            <?php if ($cards === []): ?>
                Aucun élève rattaché à votre compte
            <?php else: ?>
                <?php if ($selfCount > 0): ?>Mon dossier<?php endif; ?>
                <?php if ($selfCount > 0 && $wardCount > 0): ?> · <?php endif; ?>
                <?php if ($wardCount > 0): ?><?= $wardCount ?> élève(s) sous votre tutelle<?php endif; ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if ($cards === []): ?>
    <!-- UNE ABSENCE DE DONNÉES NE DOIT PAS RESSEMBLER À UNE PANNE.
         Le portail se fonde sur le lien de tutelle, pas sur le rôle : un
         compte non rattaché à une fiche tuteur ne voit rien, et doit
         comprendre pourquoi plutôt que de trouver une page vide. -->
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-people display-6 text-secondary d-block mb-3"></i>
            <p class="fw-medium mb-2">Aucun élève n'est rattaché à votre compte.</p>
            <p class="text-secondary mb-0" style="max-width:34rem;margin:0 auto">
                L'accès à cet espace repose sur un lien enregistré par
                l'établissement — tutelle d'un élève, ou fiche élève rattachée à
                votre compte — et non sur votre rôle. Demandez au secrétariat de
                faire ce rattachement.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($cards as $card): ?>
            <?php
            $student    = $card['student'];
            $enrollment = $card['enrollment'];
            ?>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h2 class="h5 mb-1">
                                    <?= e(full_name($student['last_name'], $student['post_name'], $student['first_name'])) ?>
                                </h2>
                                <div class="small text-secondary">
                                    <?= e((string) $student['matricule']) ?>
                                    <?php if ($enrollment !== null): ?>
                                        · <?= e((string) ($enrollment['classroom_name'] ?? 'Sans classe')) ?>
                                        · <?= e((string) $enrollment['year_code']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($card['is_self']): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis">
                                    Mon dossier
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">
                                    <?= e(ucfirst((string) ($card['student']['relationship'] ?? 'Tuteur'))) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($enrollment === null): ?>
                            <p class="text-secondary small mb-3">
                                Aucune inscription active pour cet élève.
                            </p>
                        <?php else: ?>
                            <div class="row g-2 mb-3">
                                <div class="col-4">
                                    <div class="border rounded p-2 h-100">
                                        <div class="small text-secondary">Bulletins</div>
                                        <div class="fs-5 fw-semibold"><?= (int) $card['bulletins'] ?></div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="border rounded p-2 h-100">
                                        <div class="small text-secondary">Absences</div>
                                        <div class="fs-5 fw-semibold"><?= (int) $card['absences']['absences'] ?></div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="border rounded p-2 h-100">
                                        <div class="small text-secondary">Retards</div>
                                        <div class="fs-5 fw-semibold"><?= (int) $card['absences']['retards'] ?></div>
                                    </div>
                                </div>
                            </div>

                            <?php
                            // LA DETTE EST UNE AFFAIRE DE PARENTS.
                            // L'élève ne voit son solde que si l'établissement
                            // l'a décidé (portal.student_sees_fees).
                            $maySeeFees = !$card['is_self'] || $showFees;
                            ?>
                            <?php if (!$maySeeFees): ?>
                                <p class="small text-secondary mb-3">
                                    Les frais scolaires sont suivis avec vos parents ou tuteurs.
                                </p>
                            <?php elseif ($card['balance'] === []): ?>
                                <p class="small text-secondary mb-3">Aucun frais affecté.</p>
                            <?php else: ?>
                                <div class="mb-3">
                                    <?php foreach ($card['balance'] as $currency => $line): ?>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-secondary small">Reste à payer</span>
                                            <span class="fw-semibold <?= $line['balance'] > 0.005 ? 'text-danger-emphasis' : 'text-success-emphasis' ?>">
                                                <?= e(finance_amount((float) $line['balance'], (string) $currency)) ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <a class="btn btn-outline-primary w-100"
                           href="<?= e(url('/espace/enfant/' . (int) $student['id'])) ?>">
                            Ouvrir le dossier
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
