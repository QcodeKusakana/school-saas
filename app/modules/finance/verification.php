<?php
/**
 * Module FINANCE — la vérification publique d'un reçu (phase 11C).
 *
 * ════════════════════════════════════════════════════════════════════
 *  POURQUOI UN REÇU A BESOIN D'ÊTRE VÉRIFIABLE
 * ════════════════════════════════════════════════════════════════════
 *
 * Le reçu est le seul justificatif qu'une famille possède d'avoir payé.
 * Deux fraudes le visent, et la seconde est la plus fréquente :
 *
 *  · le FAUX reçu, fabriqué de toutes pièces ;
 *  · le reçu AUTHENTIQUE remis par un caissier qui n'a pas enregistré
 *    l'encaissement. L'argent reste dans sa poche ; l'école, qui ne voit
 *    aucun paiement, réclame la somme une seconde fois — à une famille
 *    de bonne foi qui a son papier en main.
 *
 * Contre la seconde, un numéro imprimé ne vaut rien : il est vrai. Ce
 * qu'il faut, c'est que le papier cesse de prouver quoi que ce soit à
 * lui seul, et que la preuve soit la ligne en base. Le jeton renvoie
 * vers une page publique qui répond : ce versement existe-t-il, de
 * combien, et n'a-t-il pas été annulé.
 *
 * CE QUE LA PAGE PUBLIQUE NE DIT PAS
 * -----------------------------------
 * Ni le nom de l'élève, ni sa classe, ni le nom du payeur. Même
 * discipline qu'en 9A : ce sont des mineurs, et un reçu photographié ne
 * doit rien apprendre de plus que ce que le papier montre déjà.
 *
 * Elle dit en revanche le MONTANT, qui est précisément ce qu'un
 * fraudeur retoucherait, et elle dit ce qu'elle ne vérifie pas.
 *
 * L'UNICITÉ EST GLOBALE, DOCUMENTS COMPRIS
 * -----------------------------------------
 * La page `/verifier` sert déjà les documents de la 9A. Un même jeton
 * présent dans les deux tables rendrait la réponse ambiguë, et une
 * ambiguïté sur une preuve de paiement est pire qu'une absence de
 * preuve. Le tirage vérifie donc les DEUX tables, et l'index unique
 * ferme la course.
 */

declare(strict_types=1);

require_once APP_PATH . '/modules/documents/services.php';

/**
 * Un jeton libre, dans l'alphabet sans ambiguïté des documents.
 *
 * Même alphabet et même forme que la 9A, à dessein : la page publique
 * est commune, et une personne qui recopie un code à la main ne doit pas
 * avoir à savoir si elle tient un reçu ou une attestation.
 */
function receipt_new_token(): string
{
    for ($essai = 0; $essai < 12; $essai++) {
        $jeton = document_new_token();

        // Le SELECT n'est pas la garantie d'unicité — l'index l'est.
        // Il évite seulement l'échec ordinaire, et couvre le cas qu'un
        // index ne peut pas voir : la collision avec un DOCUMENT, qui
        // vit dans une autre table.
        $pris = tenant_scope_identity(static fn (): bool => (bool) db_value(
            'SELECT 1 FROM (
                 SELECT token AS t FROM documents WHERE token = :t1
                 UNION ALL
                 SELECT verify_token FROM payments WHERE verify_token = :t2
             ) x LIMIT 1',
            ['t1' => $jeton, 't2' => $jeton],
            true
        ));

        if (!$pris) {
            return $jeton;
        }
    }

    // Douze collisions d'affilée sur 30^12 possibilités ne se produisent
    // pas par hasard : quelque chose ne va pas dans le tirage. Mieux
    // vaut refuser l'encaissement que délivrer un reçu invérifiable.
    throw new RuntimeException(
        'Impossible de tirer un jeton de vérification libre pour ce reçu.'
    );
}

/**
 * Ce que la page publique affiche pour le jeton d'un reçu.
 *
 * AUCUN NOM — ni élève, ni payeur. Voir l'en-tête du fichier.
 *
 * La lecture traverse les écoles : la page ne sait pas de quel
 * établissement vient le jeton. Elle passe donc par le périmètre nommé
 * d'identité, comme la vérification des documents.
 *
 * @return array{found: bool, valid?: bool, number?: string, school?: string,
 *               paid_on?: string, amount?: string, currency?: string,
 *               cancelled_on?: ?string, cancelled_reason?: ?string}
 */
function receipt_service_verify(string $token): array
{
    $token = strtoupper(trim($token));

    if (!document_token_is_wellformed($token)) {
        return ['found' => false];
    }

    $ligne = tenant_scope_identity(static fn (): ?array => db_one(
        'SELECT p.receipt_no, p.paid_on, p.tendered_currency, p.tendered_amount,
                p.is_cancelled, p.cancelled_at, p.cancelled_reason,
                s.name AS school_name
           FROM payments p
           JOIN schools s ON s.id = p.school_id
          WHERE p.verify_token = :t
          LIMIT 1',
        ['t' => $token],
        true
    ));

    if ($ligne === null) {
        return ['found' => false];
    }

    return [
        'found'    => true,
        'valid'    => (int) $ligne['is_cancelled'] === 0,
        'number'   => (string) $ligne['receipt_no'],
        'school'   => (string) $ligne['school_name'],
        'paid_on'  => (string) $ligne['paid_on'],
        // LE MONTANT REMIS, PAS LE MONTANT CRÉDITÉ.
        // C'est ce que la famille a sorti de sa poche, et c'est ce
        // qu'elle lit sur son papier. Le crédit, lui, dépend d'un taux
        // qui ne regarde pas un vérificateur de passage.
        'amount'   => (string) $ligne['tendered_amount'],
        'currency' => (string) $ligne['tendered_currency'],
        'cancelled_on' => $ligne['cancelled_at'] !== null
            ? substr((string) $ligne['cancelled_at'], 0, 10)
            : null,
        // LE MOTIF D'ANNULATION EST MONTRÉ.
        // Un reçu annulé sans explication laisse la famille devant une
        // page rouge qu'elle ne peut pas contester. Le motif est saisi
        // par l'école, qui sait qu'il sera lu.
        'cancelled_reason' => $ligne['cancelled_reason'] !== null
            ? (string) $ligne['cancelled_reason']
            : null,
    ];
}

/** L'URL publique de vérification d'un reçu, telle qu'un humain la lit. */
function receipt_verify_url(string $token): string
{
    return document_verify_url($token);
}

/** La forme courte, celle qui entre dans le QR. */
function receipt_verify_short_url(string $token): string
{
    return document_verify_short_url($token);
}
