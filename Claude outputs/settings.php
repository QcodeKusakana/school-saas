<?php
/**
 * Configuration de l'envoi d'e-mails d'un établissement.
 *
 * LE MOT DE PASSE N'EST JAMAIS RÉAFFICHÉ, pas même masqué par des
 * points « vrais » : le champ revient vide, et l'écran dit ce que
 * cela signifie. Un champ prérempli d'un secret finit dans le cache
 * du navigateur, dans un gestionnaire de mots de passe, ou sous les
 * yeux du premier passant.
 *
 * @var ?array $settings
 * @var bool   $hasKey
 * @var array  $counts
 */
declare(strict_types=1);

set_title('Envoi d\'e-mails');

$active   = $settings !== null && (int) $settings['is_active'] === 1;
$hasPwd   = $settings !== null && (int) ($settings['password_set'] ?? 0) === 1;
$enc      = $settings['encryption'] ?? 'tls';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Envoi d'e-mails</h1>
        <p class="page-subtitle">
            <?php if ($active): ?>
                <span class="badge bg-success-subtle text-success-emphasis">actif</span>
                éprouvé le <?= e(date('d/m/Y à H:i', strtotime((string) $settings['verified_at']))) ?>
            <?php elseif ($settings !== null): ?>
                <span class="badge bg-warning-subtle text-warning-emphasis">inactif</span>
                en attente d'un envoi d'essai réussi
            <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary-emphasis">non configuré</span>
            <?php endif; ?>
        </p>
    </div>
    <?php if (can('email.view')): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/ecole/emails/journal')) ?>">
            Journal des envois
            <?php if ($counts['failed'] > 0): ?>
                <span class="badge bg-danger"><?= (int) $counts['failed'] ?></span>
            <?php endif; ?>
        </a>
    <?php endif; ?>
</div>

<?php require APP_PATH . '/views/partials/flash.php'; ?>

<?php if (!$hasKey): ?>
    <div class="alert alert-danger">
        <strong>Aucune clé de chiffrement sur ce serveur.</strong>
        <p class="small mb-1">
            Le mot de passe du serveur d'envoi ne pourrait pas être protégé, et il
            est exclu de l'enregistrer en clair : une sauvegarde qui fuirait
            livrerait le droit d'écrire aux familles au nom de l'établissement.
        </p>
        <p class="small mb-0">
            Renseignez <code>security.encryption_key</code> dans
            <code>app/config/config.local.php</code>. Générez-la avec :
            <code>php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"</code>
        </p>
    </div>
<?php endif; ?>

<?php if ($settings !== null && ($settings['last_error'] ?? null) !== null): ?>
    <div class="alert alert-warning">
        <strong>Dernière erreur rencontrée</strong>
        <div class="small mt-1"><?= e((string) $settings['last_error']) ?></div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header fw-medium">Serveur d'envoi</div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/ecole/emails')) ?>" class="row g-2">
                    <?= csrf_field() ?>

                    <div class="col-12 col-md-8">
                        <label class="form-label small" for="host">
                            Serveur SMTP <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="host" name="host"
                               value="<?= e((string) ($settings['host'] ?? old('host'))) ?>"
                               placeholder="mail.monecole.cd" required maxlength="190"
                               <?= $hasKey ? '' : 'disabled' ?>>
                        <div class="form-text small">
                            Fourni par votre hébergeur. Sur cPanel, souvent
                            <code>mail.votredomaine.cd</code>.
                        </div>
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label small" for="port">Port</label>
                        <input type="number" class="form-control" id="port" name="port"
                               value="<?= e((string) ($settings['port'] ?? 587)) ?>"
                               min="1" max="65535" required <?= $hasKey ? '' : 'disabled' ?>>
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label small" for="encryption">Chiffrement</label>
                        <select class="form-select" id="encryption" name="encryption"
                                <?= $hasKey ? '' : 'disabled' ?>>
                            <option value="tls"  <?= $enc === 'tls'  ? 'selected' : '' ?>>TLS</option>
                            <option value="ssl"  <?= $enc === 'ssl'  ? 'selected' : '' ?>>SSL</option>
                            <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>>Aucun</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="username">Identifiant</label>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= e((string) ($settings['username'] ?? '')) ?>"
                               autocomplete="off" maxlength="190" <?= $hasKey ? '' : 'disabled' ?>>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="password">Mot de passe</label>
                        <input type="password" class="form-control" id="password" name="password"
                               autocomplete="new-password" placeholder="<?= $hasPwd
                                   ? 'inchangé — laissez vide pour le conserver'
                                   : 'aucun mot de passe enregistré' ?>"
                               <?= $hasKey ? '' : 'disabled' ?>>
                        <div class="form-text small">
                            <?= $hasPwd
                                ? 'Un mot de passe est enregistré, chiffré. Il n\'est jamais réaffiché : laissez ce champ vide pour le conserver.'
                                : 'Il sera chiffré avant d\'être enregistré.' ?>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="from_email">
                            Adresse d'expédition <span class="text-danger">*</span>
                        </label>
                        <input type="email" class="form-control" id="from_email" name="from_email"
                               value="<?= e((string) ($settings['from_email'] ?? old('from_email'))) ?>"
                               placeholder="direction@monecole.cd" required maxlength="190"
                               <?= $hasKey ? '' : 'disabled' ?>>
                        <div class="form-text small">
                            Elle doit appartenir au <strong>domaine de l'école</strong>, sinon
                            les messages seront classés en indésirables.
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="from_name">Nom affiché</label>
                        <input type="text" class="form-control" id="from_name" name="from_name"
                               value="<?= e((string) ($settings['from_name'] ?? '')) ?>"
                               placeholder="Direction de l'école" maxlength="120"
                               <?= $hasKey ? '' : 'disabled' ?>>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="reply_to">Adresse de réponse</label>
                        <input type="email" class="form-control" id="reply_to" name="reply_to"
                               value="<?= e((string) ($settings['reply_to'] ?? '')) ?>"
                               maxlength="190" <?= $hasKey ? '' : 'disabled' ?>>
                        <div class="form-text small">Facultatif. Vide = l'adresse d'expédition.</div>
                    </div>

                    <div class="col-12 mt-3">
                        <button class="btn btn-primary" type="submit" <?= $hasKey ? '' : 'disabled' ?>>
                            Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-medium">Envoi d'essai</div>
            <div class="card-body">
                <?php if ($settings === null): ?>
                    <p class="text-secondary small mb-0">
                        Enregistrez d'abord un serveur d'envoi.
                    </p>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/ecole/emails/essai')) ?>">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="to">Envoyer un message à</label>
                        <div class="input-group mb-2">
                            <input type="email" class="form-control" id="to" name="to"
                                   value="<?= e((string) (auth_user()['email'] ?? '')) ?>"
                                   placeholder="vous@exemple.cd" required>
                            <button class="btn btn-outline-primary" type="submit">Éprouver</button>
                        </div>
                    </form>

                    <p class="small text-secondary mb-0">
                        <strong>C'est le seul chemin qui active l'envoi.</strong>
                        Tant qu'un essai n'a pas abouti, aucun message ne part pour cet
                        établissement — une case à cocher « actif » vous laisserait
                        croire que les familles sont prévenues alors que rien ne sort.
                    </p>
                <?php endif; ?>

                <hr>

                <div class="row text-center small">
                    <div class="col-4">
                        <div class="fw-medium fs-5"><?= (int) $counts['sent'] ?></div>
                        <div class="text-secondary">envoyés</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-medium fs-5"><?= (int) $counts['queued'] ?></div>
                        <div class="text-secondary">en attente</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-medium fs-5 <?= $counts['failed'] > 0 ? 'text-danger' : '' ?>">
                            <?= (int) $counts['failed'] ?>
                        </div>
                        <div class="text-secondary">en échec</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info small mt-3 mb-0">
    <strong>Les messages en attente partent par un travail périodique.</strong>
    Un envoi est d'abord <em>écrit</em>, puis tenté immédiatement ; s'il échoue, il
    est rejoué automatiquement (1, 5, 15, 60 puis 240 minutes). Sur l'hébergement,
    une tâche planifiée doit exécuter
    <code>php bin/mail_worker.php</code> — sans elle, seuls les envois qui
    réussissent du premier coup arrivent.
</div>
