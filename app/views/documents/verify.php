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
 * @var string     $token
 * @var array|null $resultat
 */
declare(strict_types=1);

set_title('Vérification d\'un document');
?>

<h2 class="h5 mb-1">Vérifier un document</h2>
<p class="text-secondary small mb-4">
    Contrôlez l'authenticité d'une attestation, d'un certificat ou d'une carte
    d'élève délivrés par un établissement utilisant cette plateforme.
</p>

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
            <i class="bi bi-question-circle me-1"></i> Aucun document ne correspond
        </div>
        Ce code ne correspond à aucun document délivré sur cette plateforme.
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
