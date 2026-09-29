<?php
/**
 * ÉLÈVES — les pièces du dossier (phase 11D).
 *
 * ════════════════════════════════════════════════════════════════════
 *  CE QUE L'ÉCOLE REÇOIT, PAS CE QU'ELLE ÉMET
 * ════════════════════════════════════════════════════════════════════
 *
 * La phase 9A gère les pièces que l'école DÉLIVRE : attestations,
 * certificats, cartes — numérotées, figées, vérifiables. Ici, ce sont
 * celles qu'elle REÇOIT et range : l'acte de naissance, le bulletin de
 * l'établissement précédent, la pièce du tuteur.
 *
 * C'est le geste qui manquait au « dossier numérique unique » du
 * projet. Sans lui, l'école continue de gérer des chemises en carton
 * pour les seules pièces qu'elle ne peut pas perdre.
 *
 * TROIS PRÉCAUTIONS, ET AUCUNE N'EST DE CONFORT
 * ----------------------------------------------
 *
 *  1. CE SONT DES PIÈCES DE MINEURS. Un acte de naissance porte la
 *     filiation, une date et un lieu de naissance. Le fichier vit hors
 *     de la racine web, il n'est servi que par PHP, et chaque lecture
 *     passe par le périmètre de l'élève — un enseignant ne lit pas le
 *     dossier d'un élève qui n'est pas dans ses classes.
 *
 *  2. UN FICHIER SE SERT EN PIÈCE JOINTE, JAMAIS EN LIGNE. Un PDF
 *     affiché dans l'onglet exécute son JavaScript dans certains
 *     lecteurs, et hérite alors de l'origine du site. `attachment` le
 *     ferme.
 *
 *  3. LE RETRAIT EFFACE LE FICHIER. Une ligne supprimée qui laisserait
 *     l'acte de naissance sur le disque ne serait pas un retrait : ce
 *     serait un masquage, et le RGPD ne s'en satisfait pas.
 */

declare(strict_types=1);

require_once APP_PATH . '/modules/students/repositories.php';

/**
 * Les natures de pièce proposées.
 *
 * POURQUOI UNE ÉNUMÉRATION, ET PAS UNE TABLE DE RÉFÉRENCE.
 * Le projet impose de ne pas coder en dur ce qui doit évoluer —
 * matières, options, coefficients, programmes. Les pièces d'un dossier
 * scolaire congolais, elles, ne bougent pas : ce sont celles que le
 * secrétariat réclame à l'inscription depuis toujours. Une table pour
 * six valeurs coûterait une jointure à chaque affichage sans rien
 * rendre de configurable.
 *
 * Ce qui varie d'une école à l'autre, c'est le DÉTAIL — « Bulletin 5ème
 * primaire, EP Lumumba » — et il est saisi librement dans le libellé.
 */
const STUDENT_DOCUMENT_TYPES = [
    'acte_naissance'    => 'Acte de naissance',
    'bulletin_anterieur' => 'Bulletin de l\'établissement précédent',
    'certificat_transfert' => 'Certificat de transfert ou de sortie',
    'piece_tuteur'      => 'Pièce d\'identité du tuteur',
    'fiche_medicale'    => 'Fiche médicale',
    'autre'             => 'Autre pièce',
];

/** Le sous-dossier de rangement, déjà couvert par l'effacement RGPD (10C). */
function student_documents_dossier(): string
{
    return 'documents/' . tenant_require() . '/eleves';
}

/**
 * Les pièces rangées au dossier d'un élève.
 *
 * @return array<int, array<string, mixed>>
 */
function student_documents_all(int $studentId): array
{
    return db_all(
        'SELECT sd.*, u.last_name AS par_nom, u.first_name AS par_prenom
           FROM student_documents sd
           LEFT JOIN users u ON u.id = sd.uploaded_by
          WHERE sd.school_id = :school_id AND sd.student_id = :student_id
          ORDER BY sd.created_at DESC, sd.id DESC',
        ['school_id' => tenant_require(), 'student_id' => $studentId]
    );
}

/** Une pièce, ou null si elle n'est pas de cet établissement. */
function student_documents_find(int $documentId): ?array
{
    return db_one(
        'SELECT * FROM student_documents WHERE id = :id AND school_id = :school_id LIMIT 1',
        ['id' => $documentId, 'school_id' => tenant_require()]
    );
}

/**
 * Pourquoi cet élève n'est pas accessible, ou null.
 *
 * LE PÉRIMÈTRE AVANT LA PERMISSION.
 * `student.document` dit qu'on a le droit de gérer des pièces ; elle ne
 * dit pas SUR QUI. C'est la leçon de l'audit de la phase 3, où
 * `student.view` accordé à un enseignant ouvrait le dossier complet de
 * n'importe quel mineur de l'établissement.
 */
function student_documents_refus(int $studentId): ?string
{
    if (!can('student.document')) {
        return 'Les pièces du dossier relèvent du secrétariat et de la direction.';
    }

    if (tenant_find('students', $studentId) === null) {
        return 'Élève introuvable dans cet établissement.';
    }

    if (!students_can_view($studentId)) {
        return 'Cet élève n\'est pas dans votre périmètre.';
    }

    return null;
}

/**
 * Range une pièce au dossier.
 *
 * @param array<string, mixed> $fichier   l'entrée de $_FILES
 * @return array{ok: bool, message: string, id: ?int}
 */
function student_documents_store(int $studentId, string $type, ?string $libelle, array $fichier): array
{
    $refus = student_documents_refus($studentId);

    if ($refus !== null) {
        return ['ok' => false, 'message' => $refus, 'id' => null];
    }

    if (!isset(STUDENT_DOCUMENT_TYPES[$type])) {
        return ['ok' => false, 'message' => 'Nature de pièce inconnue.', 'id' => null];
    }

    // LE CONTENU DÉCIDE, PAS L'EXTENSION.
    // `upload_store()` lit le type réel par `finfo`, vérifie la
    // cohérence avec l'extension, refuse un script renommé, et regénère
    // le nom du fichier. Rien de tout cela n'est réécrit ici : un
    // second chemin de téléversement serait un second jeu de règles.
    $depot = upload_store($fichier, student_documents_dossier(), 'document');

    if (!$depot['ok']) {
        return ['ok' => false, 'message' => (string) $depot['error'], 'id' => null];
    }

    $chemin = (string) $depot['path'];
    $absolu = storage_path('uploads/' . $chemin);

    $id = db_insert('student_documents', [
        'school_id'   => tenant_require(),
        'student_id'  => $studentId,
        'type'        => $type,
        'label'       => sanitize_string($libelle, 150),
        'file_path'   => $chemin,
        'mime'        => (string) (new finfo(FILEINFO_MIME_TYPE))->file($absolu),
        'size_bytes'  => (int) filesize($absolu),
        'uploaded_by' => auth_id(),
    ]);

    // LE JOURNAL NE PORTE PAS LE CONTENU, seulement le geste.
    audit_log('student.document.add', 'student_documents', $id, null, [
        'student_id' => $studentId,
        'type'       => $type,
    ], 'Pièce ajoutée au dossier : ' . STUDENT_DOCUMENT_TYPES[$type]);

    return [
        'ok'      => true,
        'id'      => $id,
        'message' => STUDENT_DOCUMENT_TYPES[$type] . ' ajoutée au dossier.',
    ];
}

/**
 * Retire une pièce — la ligne ET le fichier.
 *
 * @return array{ok: bool, message: string}
 */
function student_documents_delete(int $documentId, string $motif): array
{
    $piece = student_documents_find($documentId);

    if ($piece === null) {
        return ['ok' => false, 'message' => 'Cette pièce n\'existe pas dans cet établissement.'];
    }

    $refus = student_documents_refus((int) $piece['student_id']);

    if ($refus !== null) {
        return ['ok' => false, 'message' => $refus];
    }

    // UN MOTIF EST EXIGÉ, comme pour toute suppression définitive.
    // Retirer une pièce peut être une correction (mauvais élève) ou une
    // demande de la famille ; six mois plus tard, personne ne s'en
    // souviendra si rien ne l'a écrit.
    if (mb_strlen(trim($motif)) < 5) {
        return ['ok' => false, 'message' => 'Indiquez brièvement pourquoi cette pièce est retirée.'];
    }

    db_transaction(static function () use ($documentId): void {
        db_query('DELETE FROM student_documents WHERE id = :id AND school_id = :school_id',
            ['id' => $documentId, 'school_id' => tenant_require()]);
    });

    // LE FICHIER PART APRÈS LA LIGNE, ET HORS TRANSACTION.
    // Un `unlink()` ne se défait pas : le placer dans la transaction
    // aurait effacé le fichier même si l'écriture était annulée ensuite.
    // Dans l'autre sens, un fichier orphelin est rattrapable ; une ligne
    // qui pointe vers un fichier disparu ne l'est pas.
    upload_delete((string) $piece['file_path']);

    audit_log('student.document.remove', 'student_documents', $documentId, [
        'type'  => (string) $piece['type'],
        'label' => $piece['label'],
    ], ['reason' => trim($motif)], 'Pièce retirée du dossier');

    return ['ok' => true, 'message' => 'Pièce retirée du dossier.'];
}

/**
 * Le fichier d'une pièce, prêt à être servi.
 *
 * @return array{ok: bool, path?: string, mime?: string, nom?: string, message?: string}
 */
function student_documents_file(int $documentId): array
{
    $piece = student_documents_find($documentId);

    if ($piece === null) {
        return ['ok' => false, 'message' => 'Pièce introuvable.'];
    }

    $refus = student_documents_refus((int) $piece['student_id']);

    if ($refus !== null) {
        return ['ok' => false, 'message' => $refus];
    }

    $absolu = storage_path('uploads/' . (string) $piece['file_path']);

    // LE CHEMIN EST RECONFINÉ AVANT LECTURE.
    // Il vient de la base, donc il est déjà sûr — mais une reprise
    // manuelle en SQL, une restauration venue d'ailleurs ou un défaut
    // futur pourraient y glisser « ../ ». Le contrôle coûte un
    // `realpath` et ferme la question.
    $base = realpath(storage_path('uploads'));
    $reel = realpath($absolu);

    if ($base === false || $reel === false || !str_starts_with($reel, $base) || !is_file($reel)) {
        log_warning('Pièce de dossier introuvable ou hors du dossier des téléversements', [
            'document_id' => $documentId,
        ]);

        return ['ok' => false, 'message' => 'Fichier introuvable.'];
    }

    $extension = pathinfo($reel, PATHINFO_EXTENSION);

    return [
        'ok'   => true,
        'path' => $reel,
        'mime' => (string) $piece['mime'],
        // Le nom proposé au téléchargement est RECONSTRUIT : celui du
        // client n'a jamais été conservé, et c'est voulu.
        'nom'  => strtolower(str_replace(' ', '-', (string) $piece['type'])) . '.' . $extension,
    ];
}

/** Un poids lisible — « 1,4 Mo » plutôt que 1468006. */
function student_documents_poids(int $octets): string
{
    if ($octets >= 1048576) {
        return number_format($octets / 1048576, 1, ',', ' ') . ' Mo';
    }

    return number_format(max(1, (int) round($octets / 1024)), 0, ',', ' ') . ' Ko';
}
