<?php
/**
 * Fonctions de sécurité complémentaires : téléversement de fichiers,
 * nettoyage de saisie, comparaisons sûres.
 */

declare(strict_types=1);

/**
 * Valide et enregistre un fichier téléversé.
 *
 * Contrôles appliqués, dans cet ordre :
 *   1. code d'erreur PHP
 *   2. is_uploaded_file — le fichier vient bien d'un POST
 *   3. taille réelle
 *   4. type MIME détecté par finfo (le contenu, pas l'extension ni le
 *      champ type envoyé par le navigateur, tous deux falsifiables)
 *   5. cohérence extension / MIME
 *   6. pour les images : vérification que le fichier est décodable
 *   7. nom de fichier régénéré aléatoirement — jamais celui du client
 *
 * @return array{ok: bool, path: string|null, error: string|null}
 */
function upload_store(array $file, string $subdirectory, string $kind = 'image'): array
{
    $errors = [
        UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse la taille autorisée par le serveur.',
        UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la taille autorisée par le formulaire.',
        UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement envoyé.',
        UPLOAD_ERR_NO_FILE    => 'Aucun fichier n\'a été envoyé.',
        UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant sur le serveur.',
        UPLOAD_ERR_CANT_WRITE => 'Écriture impossible sur le disque du serveur.',
        UPLOAD_ERR_EXTENSION  => 'Envoi interrompu par une extension PHP.',
    ];

    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($code !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => $errors[$code] ?? 'Échec du téléversement.'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');

    // Sans ce contrôle, un chemin forgé pourrait désigner un fichier
    // arbitraire du serveur (/etc/passwd, un fichier de configuration).
    if (!is_uploaded_file($tmpPath)) {
        log_warning('Tentative de téléversement invalide', ['tmp_name' => $tmpPath]);

        return ['ok' => false, 'path' => null, 'error' => 'Fichier invalide.'];
    }

    $maxSize = (int) config('uploads.max_size', 5242880);

    if ((int) $file['size'] > $maxSize) {
        return [
            'ok'    => false,
            'path'  => null,
            'error' => 'Le fichier ne doit pas dépasser ' . round($maxSize / 1048576, 1) . ' Mo.',
        ];
    }

    // Type MIME réel, déduit du contenu du fichier.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($tmpPath);

    if (!in_array($mime, (array) config('uploads.allowed_mimes', []), true)) {
        return ['ok' => false, 'path' => null, 'error' => 'Ce type de fichier n\'est pas autorisé.'];
    }

    $allowedExtensions = $kind === 'image'
        ? (array) config('uploads.allowed_images', [])
        : (array) config('uploads.allowed_documents', []);

    $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'Extension de fichier non autorisée.'];
    }

    // Cohérence entre l'extension annoncée et le contenu réel : bloque
    // le script PHP renommé en .jpg.
    $mimeByExtension = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',  'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    if (($mimeByExtension[$extension] ?? null) !== $mime) {
        log_warning('Incohérence extension / type MIME au téléversement', [
            'extension' => $extension,
            'mime'      => $mime,
        ]);

        return ['ok' => false, 'path' => null, 'error' => 'Le contenu du fichier ne correspond pas à son extension.'];
    }

    // Une image doit être réellement décodable : un fichier contenant du
    // code PHP avec un en-tête d'image valide échoue ici.
    if ($kind === 'image' && @getimagesize($tmpPath) === false) {
        return ['ok' => false, 'path' => null, 'error' => 'Ce fichier n\'est pas une image valide.'];
    }

    // Nom généré : aucune donnée du client n'entre dans le chemin final.
    $filename  = date('Ymd') . '-' . str_random(24) . '.' . $extension;
    $relative  = trim($subdirectory, '/') . '/' . $filename;
    $directory = storage_path('uploads/' . trim($subdirectory, '/'));

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['ok' => false, 'path' => null, 'error' => 'Impossible de créer le dossier de destination.'];
    }

    if (!move_uploaded_file($tmpPath, $directory . '/' . $filename)) {
        return ['ok' => false, 'path' => null, 'error' => 'Enregistrement du fichier impossible.'];
    }

    @chmod($directory . '/' . $filename, 0644);

    audit_log('upload', 'file', null, null, ['path' => $relative, 'mime' => $mime], 'Fichier téléversé');

    return ['ok' => true, 'path' => $relative, 'error' => null];
}

/**
 * Supprime un fichier téléversé.
 * Le chemin est confiné à storage/uploads : une traversée est impossible.
 */
function upload_delete(?string $relativePath): bool
{
    if (!$relativePath) {
        return false;
    }

    $base = realpath(storage_path('uploads'));
    $full = realpath(storage_path('uploads/' . ltrim($relativePath, '/')));

    if ($base === false || $full === false || !str_starts_with($full, $base)) {
        log_warning('Tentative de suppression hors du dossier des téléversements', ['path' => $relativePath]);

        return false;
    }

    return is_file($full) && unlink($full);
}

/**
 * Nettoie une chaîne destinée à être stockée.
 * Supprime les caractères de contrôle, normalise les espaces.
 * N'échappe PAS le HTML : l'échappement se fait à l'affichage, via e().
 */
function sanitize_string(?string $value, int $maxLength = 255): ?string
{
    if ($value === null) {
        return null;
    }

    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    $value = trim($value);

    return $value === '' ? null : mb_substr($value, 0, $maxLength);
}

/** Normalise un numéro de téléphone RDC (indicatif +243). */
function sanitize_phone(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $digits = preg_replace('/[^0-9+]/', '', $value) ?? '';

    if ($digits === '') {
        return null;
    }

    // 0812345678 -> +243812345678
    if (str_starts_with($digits, '0') && strlen($digits) === 10) {
        return '+243' . substr($digits, 1);
    }

    return mb_substr($digits, 0, 20);
}

/** Comparaison de chaînes à durée constante. */
function secure_equals(string $known, string $given): bool
{
    return hash_equals($known, $given);
}
