<?php
/**
 * Module DOCUMENTS — délivrer, vérifier, révoquer.
 *
 * LA RÈGLE QUI TIENT TOUT LE RESTE
 * =================================
 * Un document délivré est FIGÉ. Ce qu'il affirme est recopié dans
 * `snapshot` au moment de la signature, et plus rien ne le recalcule.
 *
 *   > Un document qui change après avoir été signé n'est pas un
 *   > document, c'est un affichage.
 *
 * Sans cela, un certificat de scolarité imprimé en septembre
 * afficherait, rouvert en juin, la classe actuelle de l'élève plutôt
 * que celle qu'il attestait — et la page publique confirmerait un
 * contenu que le papier ne porte pas.
 *
 * C'est la onzième application du principe de figeage dans ce produit,
 * après les bulletins, les reçus, les tarifs et les taux de change.
 *
 * LE JETON N'EST PAS UN IDENTIFIANT
 * ==================================
 * Il est tiré au sort, jamais dérivé de l'identifiant. Douze
 * caractères dans un alphabet de 32 : environ 10^18 combinaisons. C'est
 * ce qui permet une page publique sans authentification — un jeton ne
 * se devine pas, et on n'en découvre pas d'autres à partir d'un.
 *
 * L'alphabet écarte I, L, O, U et les chiffres 0 et 1 : ces caractères
 * se confondent à la lecture, et un jeton se recopie parfois à la main
 * quand la caméra ne veut pas lire le code.
 *
 * CE QUE LA VÉRIFICATION PUBLIQUE RÉVÈLE
 * =======================================
 * Numéro, nature, école, date, validité. PAS LE NOM DE L'ÉLÈVE.
 *
 * Ce sont des mineurs. Un document tombé d'une poche ne doit rien
 * apprendre à qui le ramasse. La contrepartie est assumée et écrite sur
 * la page : le vérificateur doit comparer le NUMÉRO imprimé avec celui
 * affiché, pas se contenter de scanner.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';
require_once APP_PATH . '/core/qrcode.php';
// Le figeage lit les mentions imprimables de l'établissement à leur
// source unique. Dépendance déclarée, jamais supposée.
require_once APP_PATH . '/modules/school/services.php';

/** Les natures de document, et leur libellé. */
const DOCUMENT_TYPES = [
    'attestation_frequentation' => 'Attestation de fréquentation',
    'certificat_scolarite'      => 'Certificat de scolarité',
    'carte_eleve'               => 'Carte d\'élève',
    'attestation_paiement'      => 'Attestation de paiement',
];

/** Le préfixe du numéro, par nature. */
const DOCUMENT_PREFIXES = [
    'attestation_frequentation' => 'ATT',
    'certificat_scolarite'      => 'CRT',
    'carte_eleve'               => 'CAR',
    'attestation_paiement'      => 'PAY',
];

/**
 * L'alphabet du jeton.
 *
 * Ni I, ni L, ni O, ni U, ni 0, ni 1 : à la main comme à l'écran, ces
 * caractères se confondent, et un jeton mal recopié envoie le
 * vérificateur sur « document introuvable » — le pire message possible
 * pour un document authentique.
 */
const DOCUMENT_TOKEN_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

// =====================================================================
//  DÉLIVRER
// =====================================================================

/**
 * Délivre un document pour une inscription donnée.
 *
 * @return array{ok: bool, message: string, id?: int, number?: string}
 */
function document_service_issue(int $enrollmentId, string $type): array
{
    if (!can('document.generate')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de délivrer un document.'];
    }

    if (!isset(DOCUMENT_TYPES[$type])) {
        return ['ok' => false, 'message' => 'Nature de document inconnue.'];
    }

    $contexte = document_repo_context($enrollmentId);

    if ($contexte === null) {
        return ['ok' => false, 'message' => 'Inscription introuvable dans cet établissement.'];
    }

    // UN DOCUMENT NE S'ÉTABLIT PAS SUR UN DOSSIER FERMÉ. Une attestation
    // de fréquentation pour un élève dont l'inscription a été annulée
    // affirmerait quelque chose de faux, et l'école la signerait.
    if (($contexte['enrollment_status'] ?? '') === 'cancelled') {
        return [
            'ok'      => false,
            'message' => 'Cette inscription a été annulée : aucun document ne peut l\'attester.',
        ];
    }

    if ($contexte['student_deleted_at'] !== null) {
        return [
            'ok'      => false,
            'message' => 'Le dossier de cet élève est archivé. Réactivez-le avant de délivrer.',
        ];
    }

    $refus = document_type_refusal($type, $contexte);

    if ($refus !== null) {
        return ['ok' => false, 'message' => $refus];
    }

    $schoolId = tenant_require();
    $user     = auth_user();

    // Tout se fait dans UNE transaction : le compteur et la ligne
    // avancent ensemble, ou pas du tout. Un numéro consommé sans
    // document laisserait un trou dans un registre officiel.
    try {
        $resultat = db_transaction(static function () use (
            $enrollmentId, $type, $contexte, $schoolId, $user
        ): array {
            $anneeCode = (string) $contexte['year_code'];

            document_ensure_counter($type, $anneeCode);
            [$numero, $sequence] = document_next_number($type, $anneeCode);

            $id = db_insert('documents', [
                'school_id'        => $schoolId,
                'number'           => $numero,
                'sequence_number'  => $sequence,
                'type'             => $type,
                'token'            => document_new_token(),
                'student_id'       => (int) $contexte['student_id'],
                'enrollment_id'    => $enrollmentId,
                'academic_year_id' => (int) $contexte['year_id'],
                'snapshot'         => json_encode(
                    document_snapshot($type, $contexte),
                    JSON_UNESCAPED_UNICODE
                ),
                'issued_by'        => (int) $user['id'],
                'issued_at'        => date('Y-m-d H:i:s'),
            ]);

            return ['id' => $id, 'number' => $numero];
        });
    } catch (PDOException $e) {
        // Collision de jeton : un tirage sur 10^18 qui retombe sur un
        // existant. L'index tranche, et on le dit plutôt que de
        // retenter en boucle.
        if ($e->getCode() === '23000') {
            return [
                'ok'      => false,
                'message' => 'Un conflit de numérotation s\'est produit. Réessayez.',
            ];
        }

        throw $e;
    }

    audit_log('document.issued', 'documents', $resultat['id'], null, [
        'type'   => $type,
        'number' => $resultat['number'],
    ]);

    return [
        'ok'      => true,
        'message' => DOCUMENT_TYPES[$type] . ' n° ' . $resultat['number'] . ' délivrée.',
        'id'      => $resultat['id'],
        'number'  => $resultat['number'],
    ];
}

/**
 * Ce qui interdit CETTE nature de document pour CE dossier.
 *
 * Chaque type affirme quelque chose de différent ; il n'a donc pas les
 * mêmes conditions. Un refus ici vaut mieux qu'un document qui ment.
 */
function document_type_refusal(string $type, array $contexte): ?string
{
    if ($type === 'attestation_paiement') {
        // Une attestation de paiement affirme que la famille est en
        // règle. Elle ne s'établit pas quand un solde reste dû — sinon
        // le document sert à contourner le recouvrement.
        if ((float) ($contexte['balance'] ?? 0) > 0.009) {
            return 'Un solde de ' . number_format((float) $contexte['balance'], 2, ',', ' ')
                . ' reste dû : l\'attestation de paiement ne peut pas être délivrée.';
        }
    }

    if ($type === 'carte_eleve' && ($contexte['photo_path'] ?? null) === null) {
        // Une carte sans photo n'identifie personne. Mieux vaut le dire
        // que d'imprimer un rectangle vide.
        //
        // LE MESSAGE DÉSIGNE UN ENDROIT QUI EXISTE. Sa première version
        // disait « ajoutez une photo au dossier » alors qu'aucun écran
        // ne le permettait — `photo_path` dormait depuis la phase 1 sans
        // que rien ne l'écrive. Le secrétariat cherchait une porte
        // absente.
        //
        //   > Un message qui demande une action que le produit ne permet
        //   > pas est une impasse.
        return 'Cet élève n\'a pas de photo : la carte ne pourrait pas l\'identifier. '
            . 'Ajoutez-en une depuis son dossier (bouton « Photo »), puis recommencez. '
            . 'Vous pouvez aussi en voir l\'aperçu sans photo.';
    }

    if ($type === 'certificat_scolarite' && ($contexte['classroom_name'] ?? '') === '') {
        return 'Aucune classe n\'est rattachée à cette inscription : '
            . 'le certificat n\'aurait rien à attester.';
    }

    return null;
}

/**
 * LA COPIE FIGÉE de tout ce que le document affirme.
 *
 * Ce tableau est la SEULE source de ce qui s'imprime et de ce que la
 * page de vérification confirme. Aucune vue ne relit les tables
 * vivantes : c'est ce qui garantit qu'un document dit la même chose
 * dans dix ans.
 *
 * @return array<string, mixed>
 */
function document_snapshot(string $type, array $contexte): array
{
    $mentions = school_repo_print_settings();

    $base = [
        'type_label'   => DOCUMENT_TYPES[$type],
        'school'       => [
            'name'      => (string) $contexte['school_name'],
            'code'      => (string) $contexte['school_code'],
            // LES MENTIONS IMPRIMÉES VIENNENT DE LEUR SOURCE UNIQUE.
            //
            // Elles étaient lues ici sous les clés `setting_school_city`,
            // `setting_school_address`… que la requête de contexte ne
            // produisait PAS — et que rien, dans tout le produit, ne
            // produisait. Le `?? ''` rendait le défaut muet : chaque
            // document délivré figeait une ville vide, une tutelle vide,
            // et imprimait « Fait à , » au bas d'une pièce signée.
            //
            //   > Une clé qu'aucune requête ne produit, lue derrière un
            //   > `?? ''`, ne manque jamais : elle est simplement vide,
            //   > et personne ne l'apprend avant l'impression.
            'city'      => $mentions['school.city'],
            'address'   => $mentions['school.address'],
            'phone'     => $mentions['school.phone'],
            'email'     => $mentions['school.email'],
            'authority' => $mentions['school.authority'],
            'motto'     => $mentions['school.motto'],
            // Le CHEMIN du logo est figé, pas l'image — même compromis
            // que la photo d'élève, et pour la même raison.
            'has_logo'  => ($contexte['school_logo'] ?? null) !== null,
        ],
        'student'      => [
            // L'IDENTIFIANT est figé lui aussi — la carte en a besoin
            // pour aller chercher la photo par la route contrôlée, et
            // un document doit savoir de qui il parle même si le nom
            // change ensuite (mariage, correction d'état civil).
            'id'          => (int) $contexte['student_id'],
            'matricule'   => (string) $contexte['matricule'],
            'last_name'   => (string) $contexte['last_name'],
            'post_name'   => (string) ($contexte['post_name'] ?? ''),
            'first_name'  => (string) $contexte['first_name'],
            'gender'      => (string) $contexte['gender'],
            'birth_date'  => (string) ($contexte['birth_date'] ?? ''),
            'birth_place' => (string) ($contexte['birth_place'] ?? ''),
        ],
        'year'         => (string) $contexte['year_code'],
        'classroom'    => (string) ($contexte['classroom_name'] ?? ''),
        'level'        => (string) ($contexte['level_name'] ?? ''),
        'issued_on'    => date('Y-m-d'),
        'issued_by'    => trim((string) $contexte['issuer_last'] . ' ' . (string) $contexte['issuer_first']),
    ];

    // La photo n'est pas COPIÉE dans le figeage — seul son chemin l'est,
    // et seulement pour la carte. Recopier l'image gonflerait chaque
    // ligne de plusieurs centaines de kilo-octets, et une photo d'élève
    // dupliquée à chaque document délivré est une donnée de mineur
    // multipliée sans raison.
    if ($type === 'carte_eleve') {
        $base['photo_path'] = (string) ($contexte['photo_path'] ?? '');
        $base['valid_until'] = (string) ($contexte['year_ends_on'] ?? '');
    }

    if ($type === 'attestation_paiement') {
        $base['total_due']  = (float) ($contexte['total_due'] ?? 0);
        $base['total_paid'] = (float) ($contexte['total_paid'] ?? 0);
        $base['currency']   = (string) ($contexte['currency'] ?? 'USD');
    }

    if ($type === 'certificat_scolarite') {
        $base['decision'] = (string) ($contexte['decision'] ?? '');
    }

    return $base;
}

// =====================================================================
//  LA NUMÉROTATION
// =====================================================================

/**
 * Garantit la ligne du compteur AVANT de la verrouiller.
 *
 * Hors transaction, délibérément : un INSERT IGNORE à l'intérieur de la
 * transaction qui verrouille produirait un interblocage entre deux
 * secrétariats simultanés. Même raison qu'en finance.
 */
function document_ensure_counter(string $type, string $yearCode): void
{
    db_query(
        'INSERT IGNORE INTO document_counters (school_id, counter_key, last_number)
         VALUES (:school_id, :key, 0)',
        ['school_id' => tenant_require(), 'key' => $type . ':' . $yearCode]
    );
}

/**
 * Le numéro suivant — sans trou ni doublon.
 *
 * Verrouillé par SELECT … FOR UPDATE : deux secrétaires qui délivrent à
 * la même seconde obtiennent deux numéros distincts et consécutifs.
 *
 * À n'appeler QUE dans une transaction déjà ouverte.
 *
 * @return array{0: string, 1: int}
 */
function document_next_number(string $type, string $yearCode): array
{
    $schoolId = tenant_require();
    $cle      = $type . ':' . $yearCode;

    $courant = (int) db_value(
        'SELECT last_number FROM document_counters
          WHERE school_id = :school_id AND counter_key = :key
          FOR UPDATE',
        ['school_id' => $schoolId, 'key' => $cle]
    );

    $suivant = $courant + 1;

    db_query(
        'UPDATE document_counters SET last_number = :next
          WHERE school_id = :school_id AND counter_key = :key',
        ['next' => $suivant, 'school_id' => $schoolId, 'key' => $cle]
    );

    $numero = DOCUMENT_PREFIXES[$type] . '/' . $yearCode . '/'
        . str_pad((string) $suivant, 4, '0', STR_PAD_LEFT);

    return [$numero, $suivant];
}

/**
 * Un jeton public, tiré au sort.
 *
 * `random_int` et non `rand` : un jeton prévisible rendrait toute la
 * page publique inutile, puisqu'on pourrait fabriquer des jetons
 * valides sans posséder de document.
 */
function document_new_token(): string
{
    $alphabet = DOCUMENT_TOKEN_ALPHABET;
    $max      = strlen($alphabet) - 1;
    $brut     = '';

    for ($i = 0; $i < 12; $i++) {
        $brut .= $alphabet[random_int(0, $max)];
    }

    // Groupé par quatre : un jeton se lit et se recopie à voix haute
    // quand la caméra refuse le code.
    return substr($brut, 0, 4) . '-' . substr($brut, 4, 4) . '-' . substr($brut, 8, 4);
}

// =====================================================================
//  VÉRIFIER — la page publique
// =====================================================================

/**
 * Ce que la page publique affiche pour un jeton.
 *
 * ELLE NE RÉVÈLE AUCUN NOM D'ÉLÈVE. Voir l'en-tête du fichier.
 *
 * La lecture traverse les écoles — la page ne sait pas de quel
 * établissement vient le jeton. Elle passe donc par le périmètre nommé
 * d'identité, comme `users.username`, et non par un filtre oublié.
 *
 * @return array{found: bool, valid?: bool, number?: string, type?: string,
 *               school?: string, issued_on?: string, revoked_on?: ?string}
 */
function document_service_verify(string $token): array
{
    $token = strtoupper(trim($token));

    // Un jeton mal formé ne touche même pas la base : c'est une erreur
    // de saisie ou un balayage, pas une recherche.
    if (preg_match('/^[' . DOCUMENT_TOKEN_ALPHABET . ']{4}-[' . DOCUMENT_TOKEN_ALPHABET . ']{4}-['
        . DOCUMENT_TOKEN_ALPHABET . ']{4}$/', $token) !== 1) {
        return ['found' => false];
    }

    $ligne = tenant_scope_identity(static fn (): ?array => db_one(
        'SELECT d.number, d.type, d.issued_at, d.revoked_at, d.snapshot, s.name AS school_name
           FROM documents d
           JOIN schools s ON s.id = d.school_id
          WHERE d.token = :t
          LIMIT 1',
        ['t' => $token],
        true
    ));

    if ($ligne === null) {
        return ['found' => false];
    }

    $fige = (array) json_decode((string) $ligne['snapshot'], true);

    return [
        'found'      => true,
        'valid'      => $ligne['revoked_at'] === null,
        'number'     => (string) $ligne['number'],
        'type'       => DOCUMENT_TYPES[(string) $ligne['type']] ?? (string) $ligne['type'],
        // Le nom de l'école vient du FIGEAGE, pas de la table vivante :
        // une école renommée ne doit pas faire mentir un document
        // qu'elle a signé sous son ancien nom.
        'school'     => (string) ($fige['school']['name'] ?? $ligne['school_name']),
        'issued_on'  => substr((string) $ligne['issued_at'], 0, 10),
        'revoked_on' => $ligne['revoked_at'] !== null
            ? substr((string) $ligne['revoked_at'], 0, 10)
            : null,
    ];
}

/**
 * L'URL publique de vérification, telle qu'un humain la lit.
 *
 * Imprimée en toutes lettres sous le code, elle doit rester
 * compréhensible : « verifier » dit ce qu'on va y faire.
 */
function document_verify_url(string $token): string
{
    return rtrim((string) config('app.url'), '/') . '/verifier/' . $token;
}

/**
 * L'URL COURTE, celle qu'on encode dans le QR.
 *
 * POURQUOI DEUX FORMES POUR LA MÊME PAGE
 * =======================================
 * La densité d'un QR dépend du nombre de caractères. Sept caractères de
 * moins (`/v/` au lieu de `/verifier/`) suffisent à faire passer une
 * URL sous le seuil d'une version de code — donc à grossir chaque
 * module, donc à rendre la carte lisible par un téléphone ordinaire sur
 * du papier usé.
 *
 * Ce n'est pas une micro-optimisation : sur une carte de 85 mm, le code
 * dispose de 17 mm. À cette taille, une version de plus fait descendre
 * le module de 0,41 à 0,38 mm.
 *
 *   > Sur un support contraint, la longueur d'une URL est une décision
 *   > d'ingénierie, pas un détail d'écriture.
 *
 * La forme longue reste imprimée en clair à côté du code, pour qui
 * préfère la saisir.
 */
function document_verify_short_url(string $token): string
{
    return rtrim((string) config('app.url'), '/') . '/v/' . $token;
}

// =====================================================================
//  RÉVOQUER
// =====================================================================

/**
 * Retire sa validité à un document déjà délivré.
 *
 * ON NE SUPPRIME RIEN. Révoquer, c'est dater et motiver : « quel
 * document avions-nous délivré, et pourquoi l'avons-nous retiré ? »
 * doit rester lisible des années plus tard.
 *
 * @return array{ok: bool, message: string}
 */
function document_service_revoke(int $documentId, string $reason): array
{
    if (!can('document.revoke')) {
        return [
            'ok'      => false,
            'message' => 'Retirer sa valeur à un document déjà remis relève de la direction.',
        ];
    }

    $reason = trim($reason);

    // LE MOTIF EST OBLIGATOIRE. Un document révoqué sans raison laisse
    // le secrétariat devant un refus qu'il ne sait pas expliquer à la
    // famille qui se présente au guichet.
    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Indiquez le motif de la révocation.'];
    }

    $schoolId = tenant_require();

    // La vérification et l'écriture dans la même transaction : deux
    // révocations simultanées ne doivent pas se recouvrir en silence.
    $resultat = db_transaction(static function () use ($documentId, $reason, $schoolId): array {
        $doc = db_one(
            'SELECT id, number, revoked_at FROM documents
              WHERE id = :id AND school_id = :school_id
              FOR UPDATE',
            ['id' => $documentId, 'school_id' => $schoolId]
        );

        if ($doc === null) {
            return ['ok' => false, 'message' => 'Document introuvable dans cet établissement.'];
        }

        if ($doc['revoked_at'] !== null) {
            return ['ok' => false, 'message' => 'Ce document est déjà révoqué.'];
        }

        db_query(
            'UPDATE documents
                SET revoked_at = NOW(), revoked_by = :by, revoke_reason = :motif
              WHERE id = :id AND school_id = :school_id',
            [
                'by'        => (int) auth_user()['id'],
                'motif'     => mb_substr($reason, 0, 255),
                'id'        => $documentId,
                'school_id' => $schoolId,
            ]
        );

        return ['ok' => true, 'message' => 'Document n° ' . $doc['number'] . ' révoqué.'];
    });

    if ($resultat['ok']) {
        audit_log('document.revoked', 'documents', $documentId, null, ['reason' => $reason]);
    }

    return $resultat;
}
