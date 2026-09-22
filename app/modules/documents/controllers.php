<?php
/**
 * Module DOCUMENTS — contrôleurs.
 *
 * DEUX MONDES DANS UN SEUL FICHIER, ET IL FAUT LES DISTINGUER
 * ============================================================
 * Les écrans de délivrance vivent derrière `auth` + `school` + une
 * permission. La PAGE DE VÉRIFICATION, elle, est publique : c'est le
 * but même du QR code — un employeur, une banque ou une autre école
 * doit pouvoir contrôler un papier sans posséder de compte.
 *
 * Cette page ne reçoit donc aucune confiance et n'accorde aucun accès :
 * elle lit un jeton non devinable et rend quatre lignes de texte qui ne
 * nomment personne. Voir `document_service_verify()`.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';
require_once APP_PATH . '/modules/students/repositories.php';

/** La liste des documents délivrés. */
function ctrl_documents_index(): void
{
    $filtres = [
        'type'    => (string) input('type', ''),
        'statut'  => (string) input('statut', ''),
        'q'       => (string) input('q', ''),
        'student' => (int) input('eleve', 0),
    ];

    $resultat = document_repo_search($filtres, (int) input('page', 1));

    view('documents/index', [
        'title'    => 'Documents',
        'filtres'  => $filtres,
        'rows'     => $resultat['rows'],
        'total'    => $resultat['total'],
        'page'     => $resultat['page'],
        'perPage'  => $resultat['per_page'],
        'counts'   => document_repo_counts(),
        'types'    => DOCUMENT_TYPES,
    ]);
}

/** L'écran de délivrance pour une inscription. */
function ctrl_documents_new(string $enrollmentId): void
{
    $contexte = document_repo_context((int) $enrollmentId);

    if ($contexte === null) {
        flash_error('Inscription introuvable dans cet établissement.');
        redirect('/documents');
    }

    view('documents/new', [
        'title'     => 'Délivrer un document',
        'contexte'  => $contexte,
        'types'     => DOCUMENT_TYPES,
        'existants' => document_repo_for_enrollment((int) $enrollmentId),
        // Ce que CHAQUE nature refuserait pour CE dossier, calculé
        // AVANT l'écran : le secrétariat voit tout de suite pourquoi
        // une case est grisée, plutôt que d'essayer et d'échouer.
        'refus'     => array_map(
            static fn (string $t): ?string => document_type_refusal($t, $contexte),
            array_combine(array_keys(DOCUMENT_TYPES), array_keys(DOCUMENT_TYPES))
        ),
    ]);
}

/** Délivre, puis mène directement à l'impression. */
function ctrl_documents_issue(string $enrollmentId): void
{
    $out = document_service_issue((int) $enrollmentId, (string) input('type', ''));

    if (!$out['ok']) {
        flash_error($out['message']);
        redirect('/documents/delivrer/' . (int) $enrollmentId);
    }

    flash_success($out['message']);

    // On enchaîne sur l'impression : le secrétariat délivre pour
    // imprimer, pas pour consulter une liste.
    redirect('/documents/' . $out['id'] . '/imprimer');
}

/** Le document imprimable. */
function ctrl_documents_print(string $id): void
{
    $doc = document_repo_find((int) $id);

    if ($doc === null) {
        flash_error('Document introuvable dans cet établissement.');
        redirect('/documents');
    }

    $url = document_verify_url((string) $doc['token']);

    // Le CODE encode la forme courte, le TEXTE imprime la longue :
    // l'une doit être compacte, l'autre lisible.
    $urlCourte = document_verify_short_url((string) $doc['token']);

    view('documents/print', [
        'title' => $doc['number'],
        'doc'   => $doc,
        // LA CLÉ NE PEUT PAS S'APPELER `data`.
        //
        // `view_capture()` fait `extract($data, EXTR_SKIP)` : une clé
        // nommée `data` n'écrase PAS la variable locale du noyau, et la
        // vue recevait donc le tableau de paramètres entier au lieu du
        // figeage. Les documents s'imprimaient avec tous leurs champs
        // vides — sans la moindre erreur, ce qui les rendait
        // invisibles.
        //
        //   > Une variable de gabarit qui porte le nom d'une variable
        //   > du moteur ne vaut rien, et ne prévient pas.
        'fige'  => $doc['data'],
        'url'   => $url,
        // Le QR est calculé ici, une fois, et passé à la vue : une vue
        // qui appellerait le générateur ferait du calcul dans un
        // gabarit.
        'qr'    => qr_svg($urlCourte, 3, 4, 'Vérifier ce document en ligne'),
        // Le layout d'impression renvoie vers /notes par défaut — bon
        // pour un bulletin, égarant pour une attestation.
        'retour' => '/documents',
        // Une carte d'élève n'est pas une feuille A4 : on sort du
        // gabarit de page, sans quoi elle se retrouverait seule au
        // milieu de 21 cm de blanc.
        'sansFeuille' => $doc['type'] === 'carte_eleve',
    ], 'print');
}

/** Révoque un document. */
function ctrl_documents_revoke(string $id): void
{
    $out = document_service_revoke((int) $id, (string) input('reason', ''));

    $out['ok'] ? flash_success($out['message']) : flash_error($out['message']);

    redirect('/documents');
}

// =====================================================================
//  LA PAGE PUBLIQUE
// =====================================================================

/**
 * Vérifie un document — SANS AUTHENTIFICATION.
 *
 * Elle ne nomme aucun élève, et elle le DIT : sans cette phrase, un
 * vérificateur croirait que scanner suffit, alors qu'il doit comparer
 * le numéro imprimé avec celui de l'écran.
 */
function ctrl_documents_verify(string $token = ''): void
{
    $token = $token !== '' ? $token : (string) input('jeton', '');

    view('documents/verify', [
        'title'    => 'Vérification d\'un document',
        'token'    => $token,
        'resultat' => $token !== '' ? document_service_verify($token) : null,
    ], 'auth');
}

/**
 * APERÇU d'un document — un spécimen, jamais une pièce.
 *
 * POURQUOI CET ÉCRAN, ET POURQUOI IL DOIT SE TRAHIR
 * ==================================================
 * Le secrétariat veut voir à quoi ressemblera une carte ou une
 * attestation avant de la délivrer — en particulier une carte, dont la
 * mise en page dépend de la longueur du nom et de la présence d'une
 * photo. Le lui refuser l'obligerait à délivrer pour voir, donc à
 * consommer un numéro officiel pour un essai.
 *
 * MAIS un aperçu qui ressemblerait exactement au document réel serait
 * une fabrique de faux : photographié ou imprimé, il passerait pour
 * authentique auprès de qui ne sait pas.
 *
 *   > Un aperçu qui ne se distingue pas de l'original n'est pas un
 *   > aperçu, c'est un blanc-seing.
 *
 * Trois marques le trahissent, et elles sont cumulatives :
 *  · un filigrane « SPÉCIMEN » en travers de la page ;
 *  · aucun numéro — la mention « SANS VALEUR » à sa place ;
 *  · AUCUN code de vérification, remplacé par un cadre vide qui
 *    explique où il apparaîtra.
 *
 * Rien n'est écrit : pas de ligne dans `documents`, pas de compteur
 * avancé, pas de jeton tiré. Un aperçu ne laisse aucune trace parce
 * qu'il ne s'est rien passé.
 */
function ctrl_documents_preview(string $enrollmentId): void
{
    $type     = (string) input('type', '');
    $contexte = document_repo_context((int) $enrollmentId);

    if ($contexte === null) {
        flash_error('Inscription introuvable dans cet établissement.');
        redirect('/documents');
    }

    if (!isset(DOCUMENT_TYPES[$type])) {
        flash_error('Nature de document inconnue.');
        redirect('/documents/delivrer/' . (int) $enrollmentId);
    }

    // On emprunte le MÊME figeage que la délivrance : l'aperçu montre
    // exactement ce que le document dirait. Un aperçu construit à côté
    // finirait par diverger de la pièce réelle, et ne servirait plus à
    // rien.
    $data = document_snapshot($type, $contexte);

    view('documents/print', [
        'title' => 'Aperçu — ' . DOCUMENT_TYPES[$type],
        'doc'   => [
            'number'        => 'SPÉCIMEN',
            'type'          => $type,
            'token'         => '',
            'revoked_at'    => null,
            'revoke_reason' => null,
        ],
        'fige'    => $data,
        'url'     => '',
        'qr'      => '',
        // Le drapeau que la vue lit pour se trahir.
        'apercu'      => true,
        'retour'      => '/documents/delivrer/' . (int) $enrollmentId,
        'sansFeuille' => $type === 'carte_eleve',
    ], 'print');
}
