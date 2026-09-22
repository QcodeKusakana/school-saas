<?php
/**
 * Module ÉTABLISSEMENT — l'identité qui s'imprime.
 *
 * POURQUOI CE MODULE EXISTE
 * ==========================
 * `schools.logo_path` était déclaré depuis la phase 1, lu à deux
 * endroits, et JAMAIS écrit. La permission `school.branding` dormait de
 * même. Et `school_settings` était entièrement vide : les documents
 * officiels de la phase 9A imprimaient « Fait à , » — une virgule
 * suivie de rien, au bas d'une pièce signée.
 *
 *   > Une colonne semée sans écran est une promesse que le produit ne
 *   > tient pas, et qu'on ne découvre qu'à l'impression.
 *
 * CE QUE CET ÉCRAN GOUVERNE, ET RIEN D'AUTRE
 * ===========================================
 * Ce qui apparaît sur le papier : logo, ville, adresse, téléphone,
 * courriel, et la ligne de tutelle (« Ministère de l'Enseignement… »),
 * qui varie d'un réseau à l'autre — une école conventionnée catholique
 * n'écrit pas la même chose qu'une école publique.
 *
 * Le NOM de l'établissement n'est pas modifiable ici : il identifie
 * l'école dans toute la plateforme, figure sur les documents déjà
 * délivrés, et relève de l'éditeur.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * Les réglages imprimables, avec leur libellé et leur longueur maximale.
 *
 * Déclarés une fois ici : le formulaire, la validation et la lecture
 * s'en servent tous les trois. Un champ ajouté à cette liste apparaît
 * partout sans autre modification.
 */
const SCHOOL_PRINT_SETTINGS = [
    'school.city'      => ['Ville', 60,
        'Elle complète « Fait à … » au bas des documents.'],
    'school.address'   => ['Adresse', 160,
        'Avenue, numéro, quartier, commune.'],
    'school.phone'     => ['Téléphone', 40, ''],
    'school.email'     => ['Adresse électronique', 120, ''],
    'school.authority' => ['Ligne de tutelle', 160,
        'Imprimée en tête des documents. Par exemple : « République Démocratique '
        . 'du Congo · Ministère de l\'Enseignement Primaire, Secondaire et Technique ».'],
    'school.motto'     => ['Devise', 80,
        'Facultative. Apparaît discrètement sous le nom.'],
];

/**
 * Enregistre les réglages imprimables.
 *
 * @param array<string, mixed> $input
 * @return array{ok: bool, message: string}
 */
function school_service_save_settings(array $input): array
{
    if (!can('school.branding')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de modifier ces informations.'];
    }

    // ON VALIDE TOUT, PUIS ON ÉCRIT — JAMAIS L'INVERSE.
    //
    // La première version écrivait dans la boucle de validation. Deux
    // conséquences, toutes deux constatées à l'exécution :
    //
    //   · une saisie REFUSÉE avait déjà modifié la base — l'adresse et
    //     la tutelle étaient enregistrées, puis l'écran annonçait un
    //     échec ; l'école croyait n'avoir rien changé ;
    //   · un champ ABSENT de l'envoi valait `''` et était écrit comme
    //     tel. Un enregistrement partiel — un formulaire réduit, un
    //     envoi AJAX d'un seul champ — effaçait tous les autres.
    //
    //   > Un champ absent n'est pas un champ vidé, et le confondre fait
    //   > d'un enregistrement partiel un effacement.
    //
    // D'où les deux règles : `array_key_exists` distingue « absent » de
    // « vidé », et rien n'est écrit tant qu'une erreur subsiste.
    $erreurs  = [];
    $aEcrire  = [];

    foreach (SCHOOL_PRINT_SETTINGS as $cle => [$libelle, $max]) {
        $champ = str_replace('.', '_', $cle);

        if (!array_key_exists($champ, $input)) {
            continue;
        }

        $valeur = trim((string) $input[$champ]);

        if (mb_strlen($valeur) > $max) {
            $erreurs[] = $libelle . ' : ' . $max . ' caractères au maximum.';
            continue;
        }

        if ($cle === 'school.email' && $valeur !== ''
            && filter_var($valeur, FILTER_VALIDATE_EMAIL) === false) {
            $erreurs[] = 'L\'adresse électronique n\'est pas valide.';
            continue;
        }

        $aEcrire[$cle] = $valeur;
    }

    if ($erreurs !== []) {
        return ['ok' => false, 'message' => implode(' ', $erreurs)];
    }

    if ($aEcrire === []) {
        return ['ok' => false, 'message' => 'Aucune information à enregistrer.'];
    }

    // Une transaction : les mentions imprimées forment une identité
    // cohérente. Une adresse enregistrée sans sa ville, parce que la
    // connexion a lâché entre les deux, imprimerait un en-tête faux.
    db_transaction(static function () use ($aEcrire): void {
        foreach ($aEcrire as $cle => $valeur) {
            school_setting_set($cle, $valeur);
        }
    });

    audit_log('school.branding_updated', 'schools', tenant_require(), null, null,
        'Informations imprimables mises à jour');

    return ['ok' => true, 'message' => 'Informations enregistrées.'];
}

/**
 * Enregistre le logo de l'établissement.
 *
 * MÊME RÈGLE QUE LA PHOTO D'ÉLÈVE : le fichier vit dans
 * `storage/uploads`, hors racine web, et se sert par une route
 * contrôlée. Un logo est moins sensible qu'un visage de mineur, mais
 * deux chemins de service voudraient dire deux jeux de règles — et
 * c'est toujours le plus permissif qui finit par être emprunté.
 *
 * @param array<string, mixed> $file
 * @return array{ok: bool, message: string}
 */
function school_service_set_logo(array $file): array
{
    if (!can('school.branding')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de modifier le logo.'];
    }

    $schoolId = tenant_require();

    $stored = upload_store($file, 'logos/' . $schoolId, 'image');

    if (!$stored['ok']) {
        return ['ok' => false, 'message' => (string) $stored['error']];
    }

    $ancien = school_repo_logo_path();

    // `schools` N'EST PAS UNE TABLE D'ÉCOLE au sens du garde-fou : elle
    // n'a pas de `school_id`, elle a un `id`. Le garde-fou ne la couvre
    // donc pas, et la seule protection est celle-ci : la clause porte
    // `tenant_require()`, jamais un identifiant venu de la requête.
    //
    // Cette écriture était enveloppée dans une fonction nommée
    // `platform_scope_cli_or_identity_school()` qui ne faisait
    // RIEN — un `return $work();` sous un nom de périmètre.
    //
    //   > Une fonction dont le nom promet un périmètre qu'elle
    //   > n'applique pas est pire qu'un appel direct : elle fait croire
    //   > que la question a été traitée.
    db_query('UPDATE schools SET logo_path = :p WHERE id = :id',
        ['p' => $stored['path'], 'id' => $schoolId], true);

    if ($ancien !== null && $ancien !== $stored['path']) {
        upload_delete($ancien);
    }

    // Le profil en session porte le logo : sans ce rafraîchissement,
    // l'ancien resterait affiché jusqu'à la prochaine connexion.
    auth_user(true);

    audit_log('school.logo_set', 'schools', $schoolId, null, ['path' => $stored['path']],
        'Logo de l\'établissement enregistré');

    return ['ok' => true, 'message' => 'Logo enregistré.'];
}

/**
 * Retire le logo.
 *
 * @return array{ok: bool, message: string}
 */
function school_service_remove_logo(): array
{
    if (!can('school.branding')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de modifier le logo.'];
    }

    $ancien = school_repo_logo_path();

    if ($ancien === null) {
        return ['ok' => false, 'message' => 'Aucun logo n\'est enregistré.'];
    }

    $schoolId = tenant_require();

    // Même remarque que pour l'enregistrement : la borne est
    // `tenant_require()`, dans la clause.
    db_query('UPDATE schools SET logo_path = NULL WHERE id = :id',
        ['id' => $schoolId], true);

    upload_delete($ancien);
    auth_user(true);

    audit_log('school.logo_removed', 'schools', $schoolId, null, null,
        'Logo de l\'établissement retiré');

    return ['ok' => true, 'message' => 'Logo retiré.'];
}

/**
 * Le fichier du logo, après contrôle — pour la route qui le sert.
 *
 * @return array{ok: bool, path?: string, mime?: string}
 */
function school_service_logo_file(): array
{
    $relatif = school_repo_logo_path();

    if ($relatif === null) {
        return ['ok' => false];
    }

    $reel = realpath(storage_path('uploads/' . ltrim($relatif, '/')));
    $base = realpath(storage_path('uploads'));

    if ($reel === false || $base === false || !str_starts_with($reel, $base . DIRECTORY_SEPARATOR)) {
        log_warning('Logo hors périmètre', ['school' => tenant_id(), 'path' => $relatif]);

        return ['ok' => false];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($reel);

    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['ok' => false];
    }

    return ['ok' => true, 'path' => $reel, 'mime' => $mime];
}
