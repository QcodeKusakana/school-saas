<?php
/**
 * Vérification publique d'un document — sans authentification.
 *
 * CE QU'ELLE MONTRE, ET CE QU'ELLE TAIT
 * ======================================
 * Numéro, nature, établissement, date, validité. AUCUN NOM D'ÉLÈVE.
 *
 * Ce sont des mineurs. Un document tombé d'une poche, un papier
 * photographié et partagé, un QR affiché sur un écran dans une salle
 * d'attente : rien de tout cela ne doit apprendre à un inconnu qui est
 * l'élève ni où il étudie… au-delà de ce que le papier qu'il a sous les
 * yeux lui dit déjà.
 *
 * LA CONTREPARTIE EST ÉCRITE SUR LA PAGE, en toutes lettres.
 * Sans le nom, un fraudeur peut coller un QR authentique sur un faux
 * document portant un autre nom. La parade tient en une phrase, et
 * cette page la dit plutôt que de la sous-entendre : COMPAREZ LE
 * NUMÉRO. Une page qui laisserait croire que scanner suffit rendrait la
 * fraude plus facile qu'avant, pas plus difficile.
 *
 *   > Une vérification qui ne dit pas ce qu'elle ne vérifie pas donne
 *   > une confiance qu'elle n'a pas gagnée.
 *
 * LES REÇUS PASSENT PAR ICI AUSSI (phase 11C).
 * Un reçu vérifie autre chose qu'une attestation : ce qui compte n'est
 * pas sa nature mais son MONTANT, parce que c'est ce qu'un fraudeur
 * retoucherait. La page l'affiche, et continue de taire les noms.
 *
 * @var string      $token
 * @var array|null  $resultat
 * @var string|null $nature    'document', 'recu', ou null
 */
declare(strict_types=1);

set_title('Vérification d\'un document');
?>

<h2 class="h5 mb-1">Vérifier un document ou un reçu</h2>
<p class="text-secondary small mb-4">
    Contrôlez l'authenticité d'une attestation, d'un certificat, d'une carte
    d'élève ou d'un <strong>reçu de paiement</strong> délivrés par un
    établissement utilisant cette plateforme.
</p>

<?php if ($nature === 'recu' && $resultat !== null && ($resultat['found'] ?? false)): ?>

    <?php if ($resultat['valid'] ?? false): ?>
        <div class="alert alert-success">
            <div class="fw-semibold mb-1">
                <i class="bi bi-patch-check-fill me-1"></i> Versement enregistré
            </div>
            Ce reçu correspond à un versement bien enregistré par l'établissement.
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1">
                <i class="bi bi-x-octagon-fill me-1"></i> Reçu annulé
            </div>
            Ce versement a été enregistré, puis <strong>annulé par
            l'établissement</strong>
            <?php if ($resultat['cancelled_on'] !== null): ?>
                le <?= e(date('d/m/Y', strtotime((string) $resultat['cancelled_on']))) ?>
            <?php endif; ?>.
            Ce reçu ne vaut plus preuve de paiement.
        </div>
    <?php endif; ?>

    <dl class="row small mb-4">
        <dt class="col-5 text-secondary">Numéro du reçu</dt>
        <dd class="col-7 fw-semibold"><?= e((string) $resultat['number']) ?></dd>

        <dt class="col-5 text-secondary">Montant remis</dt>
        <dd class="col-7 fw-semibold">
            <?= e(number_format((float) $resultat['amount'],
                (string) $resultat['currency'] === 'CDF' ? 0 : 2, ',', ' ')) ?>
            <?= e((string) $resultat['currency']) ?>
        </dd>

        <dt class="col-5 text-secondary">Date du versement</dt>
        <dd class="col-7"><?= e(date('d/m/Y', strtotime((string) $resultat['paid_on']))) ?></dd>

        <dt class="col-5 text-secondary">Établissement</dt>
        <dd class="col-7"><?= e((string) $resultat['school']) ?></dd>

        <?php if (($resultat['cancelled_reason'] ?? null) !== null): ?>
            <dt class="col-5 text-secondary">Motif de l'annulation</dt>
            <dd class="col-7"><?= e((string) $resultat['cancelled_reason']) ?></dd>
        <?php endif; ?>
    </dl>

    <!-- CE QUE CETTE PAGE NE VÉRIFIE PAS, DIT EN TOUTES LETTRES. -->
    <div class="alert alert-warning small mb-0">
        <strong>Comparez le numéro et le montant ci-dessus avec ceux imprimés
        sur le papier que vous tenez.</strong>
        Cette page confirme qu'un versement portant ce numéro a bien été
        enregistré ; elle ne peut pas confirmer que le papier entre vos mains
        est celui-là. Le nom de l'élève et celui du payeur ne sont pas publiés.
        Si le montant affiché ici diffère de celui de votre reçu, gardez votre
        papier et adressez-vous à la direction de l'établissement.
    </div>

    <form method="get" action="<?= e(url('/verifier')) ?>" class="mt-4">
        <label class="form-label small fw-medium" for="jeton">Code de vérification</label>
        <div class="input-group">
            <input type="text" class="form-control" id="jeton" name="jeton"
                   value="<?= e($token) ?>" placeholder="ABCD-EFGH-JKMN"
                   maxlength="14" autocomplete="off" spellcheck="false"
                   style="text-transform:uppercase;letter-spacing:.06em;">
            <button type="submit" class="btn btn-primary">Vérifier</button>
        </div>
    </form>

<?php else: ?>

<?php if ($resultat !== null && ($resultat['found'] ?? false) && ($resultat['valid'] ?? false)): ?>

    <div class="alert alert-success">
        <div class="fw-semibold mb-1">
            <i class="bi bi-patch-check-fill me-1"></i> Document authentique
        </div>
        Ce document a bien été délivré par l'établissement ci-dessous.
    </div>

    <dl class="row small mb-4">
        <dt class="col-5 text-secondary">Numéro</dt>
        <dd class="col-7 fw-semibold"><?= e((string) $resultat['number']) ?></dd>

        <dt class="col-5 text-secondary">Nature</dt>
        <dd class="col-7"><?= e((string) $resultat['type']) ?></dd>

        <dt class="col-5 text-secondary">Établissement</dt>
        <dd class="col-7"><?= e((string) $resultat['school']) ?></dd>

        <dt class="col-5 text-secondary">Délivré le</dt>
        <dd class="col-7"><?= e(date('d/m/Y', strtotime((string) $resultat['issued_on']))) ?></dd>
    </dl>

    <!-- LA PHRASE QUI FAIT TOUT LE TRAVAIL. -->
    <div class="alert alert-warning small mb-0">
        <strong>Comparez le numéro ci-dessus avec celui imprimé sur le papier
        que vous tenez.</strong>
        Cette page confirme qu'un document portant ce numéro a été délivré ;
        elle ne peut pas confirmer que le papier entre vos mains est bien
        celui-là. Le nom de l'élève n'est pas publié, car il s'agit le plus
        souvent d'un mineur. En cas de doute, contactez l'établissement.
    </div>

<?php elseif ($resultat !== null && ($resultat['found'] ?? false)): ?>

    <div class="alert alert-danger">
        <div class="fw-semibold mb-1">
            <i class="bi bi-x-octagon-fill me-1"></i> Document révoqué
        </div>
        Ce document a été délivré, puis <strong>annulé par l'établissement</strong>
        le <?= e(date('d/m/Y', strtotime((string) $resultat['revoked_on']))) ?>.
        Il n'a plus aucune valeur.
    </div>

    <dl class="row small mb-4">
        <dt class="col-5 text-secondary">Numéro</dt>
        <dd class="col-7 fw-semibold"><?= e((string) $resultat['number']) ?></dd>

        <dt class="col-5 text-secondary">Nature</dt>
        <dd class="col-7"><?= e((string) $resultat['type']) ?></dd>

        <dt class="col-5 text-secondary">Établissement</dt>
        <dd class="col-7"><?= e((string) $resultat['school']) ?></dd>
    </dl>

    <p class="small text-secondary mb-0">
        Adressez-vous à l'établissement pour connaître le motif de l'annulation.
    </p>

<?php elseif ($resultat !== null): ?>

    <div class="alert alert-secondary">
        <div class="fw-semibold mb-1">
            <i class="bi bi-question-circle me-1"></i> Aucune pièce ne correspond
        </div>
        Ce code ne correspond ni à un document délivré, ni à un reçu enregistré
        sur cette plateforme.
    </div>

    <!-- On n'accuse personne. Un code mal recopié est infiniment plus
         fréquent qu'un faux, et traiter le premier comme le second
         ferait perdre son temps à une famille de bonne foi. -->
    <p class="small text-secondary">
        Vérifiez d'abord la saisie : le code comporte douze caractères en trois
        groupes de quatre. Il ne contient jamais les lettres I, L, O, U ni les
        chiffres 0 et 1 — s'ils apparaissent, ce sont des 1, des 0 mal lus.
        Si le code est correct et que le document semble authentique,
        contactez l'établissement qui l'a délivré.
    </p>

<?php endif; ?>

<form method="get" action="<?= e(url('/verifier')) ?>" class="mt-4">
    <label class="form-label small fw-medium" for="jeton">
        Code de vérification
    </label>
    <div class="input-group">
        <input type="text" class="form-control" id="jeton" name="jeton"
               value="<?= e($token) ?>" placeholder="ABCD-EFGH-JKMN"
               maxlength="14" autocomplete="off" spellcheck="false"
               style="text-transform:uppercase;letter-spacing:.06em;">
        <button type="submit" class="btn btn-primary">Vérifier</button>
    </div>
    <div class="form-text">
        Il figure sous le code à scanner, au bas du document.
    </div>
</form>

<?php endif; ?>
