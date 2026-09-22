<?php
/**
 * Module SYNC — recevoir ce qui a été fait hors connexion.
 *
 * LA RÈGLE QUI TIENT TOUT LE RESTE
 * =================================
 * Une écriture hors connexion N'EMPRUNTE PAS un chemin que l'écriture
 * en ligne n'emprunte pas. La synchronisation REJOUE le service
 * existant — `attendance_service_take()` — elle ne réécrit pas les
 * tables elle-même.
 *
 *   > Un second chemin d'écriture est un second jeu de règles, et
 *   > c'est toujours le plus permissif qui finit par être emprunté.
 *
 * Sans cela, toutes les protections du module Présences — liste
 * blanche des élèves de la classe, refus d'un registre verrouillé,
 * fenêtre de l'année scolaire, interdiction de mêler « journée » et
 * « demi-journée » — seraient contournables en se déclarant hors
 * ligne. La synchronisation deviendrait la porte de service.
 *
 * TROIS GARDES, DANS CET ORDRE
 * =============================
 * 1. IDEMPOTENCE — `client_uuid` est UNIQUE. Un appareil qui renvoie
 *    sa file après une coupure ne produit pas de doublon : l'opération
 *    déjà reçue rend son résultat d'origine, sans rien rejouer.
 *
 * 2. CONCURRENCE OPTIMISTE — l'appareil dit ce qu'il a VU avant de
 *    partir hors ligne. Si le serveur a changé depuis, on ne recouvre
 *    pas : on déclare un CONFLIT, et un humain tranche.
 *
 *    L'horodatage de l'appareil ne sert JAMAIS à arbitrer — le schéma
 *    le dit depuis la phase 1, et il a raison : un téléphone dont
 *    l'heure est fausse de trois jours écraserait tout.
 *
 * 3. LE SERVICE MÉTIER — il garde le dernier mot. Son refus devient un
 *    `rejected` portant SON message, pas une invention de cette
 *    couche.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';
require_once APP_PATH . '/modules/attendance/services.php';

/** Les types d'opération que la synchronisation sait rejouer. */
const SYNC_ENTITIES = ['attendance_session'];

/** Taille maximale d'un envoi — un appareil ne noie pas le serveur. */
const SYNC_MAX_BATCH = 50;

/**
 * Enregistre — ou retrouve — l'appareil de l'utilisateur courant.
 *
 * L'identifiant est généré par le navigateur et conservé dans
 * IndexedDB. Il ne prouve rien : c'est une ÉTIQUETTE, pas une
 * authentification. C'est la session qui authentifie, et chaque
 * opération est rattachée à l'utilisateur connecté au moment de la
 * réception — jamais à celui qui figurerait dans la charge utile.
 *
 * @return array{ok: bool, message: string, device_id?: int}
 */
function sync_service_register_device(string $deviceUuid, string $label = ''): array
{
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceUuid) !== 1) {
        return ['ok' => false, 'message' => 'Identifiant d\'appareil invalide.'];
    }

    $user     = auth_user();
    $schoolId = tenant_require();

    // L'identifiant est unique pour TOUT le produit. On le cherche donc
    // hors périmètre d'établissement, puis on vérifie qu'il appartient
    // bien à cet utilisateur-ci : un identifiant deviné ne doit pas
    // permettre de se greffer sur l'appareil d'un autre.
    $existing = platform_scope_cli_or_identity(static fn (): ?array => db_one(
        'SELECT id, school_id, user_id FROM sync_devices WHERE device_uuid = :u LIMIT 1',
        ['u' => $deviceUuid],
        true
    ));

    if ($existing !== null) {
        if ((int) $existing['user_id'] !== (int) $user['id']
            || (int) $existing['school_id'] !== $schoolId) {
            return [
                'ok'      => false,
                'message' => 'Cet identifiant d\'appareil est déjà rattaché à un autre compte. '
                    . 'Videz les données du site dans votre navigateur pour en obtenir un neuf.',
            ];
        }

        db_query(
            'UPDATE sync_devices SET last_seen_at = NOW(), is_active = 1
              WHERE id = :id AND school_id = :school_id',
            ['id' => (int) $existing['id'], 'school_id' => $schoolId],
            true
        );

        return ['ok' => true, 'message' => 'Appareil reconnu.', 'device_id' => (int) $existing['id']];
    }

    $id = db_insert('sync_devices', [
        'school_id'    => $schoolId,
        'user_id'      => (int) $user['id'],
        'device_uuid'  => strtolower($deviceUuid),
        'device_label' => mb_substr(trim($label), 0, 100) ?: null,
        'last_seen_at' => date('Y-m-d H:i:s'),
        'is_active'    => 1,
    ], true);

    audit_log('sync.device_registered', 'sync_devices', $id, null, ['label' => $label]);

    return ['ok' => true, 'message' => 'Appareil enregistré.', 'device_id' => $id];
}

/**
 * Une lecture d'identité hors périmètre d'école.
 *
 * `sync_devices.device_uuid` est unique pour tout le produit : le
 * chercher exige de sortir du périmètre, exactement comme
 * `users.username`. On emprunte donc le même chemin nommé.
 */
function platform_scope_cli_or_identity(callable $work): mixed
{
    return tenant_scope_identity($work);
}

/**
 * Reçoit un lot d'opérations d'un appareil.
 *
 * @param array<int, array<string, mixed>> $operations
 * @return array{ok: bool, message: string, results: array<int, array<string, mixed>>}
 */
function sync_service_receive(int $deviceId, array $operations): array
{
    $schoolId = tenant_require();
    $user     = auth_user();

    $device = db_one(
        'SELECT id FROM sync_devices
          WHERE id = :id AND school_id = :school_id AND user_id = :user_id AND is_active = 1
          LIMIT 1',
        ['id' => $deviceId, 'school_id' => $schoolId, 'user_id' => (int) $user['id']],
        true
    );

    if ($device === null) {
        return ['ok' => false, 'message' => 'Appareil inconnu ou désactivé.', 'results' => []];
    }

    if (count($operations) > SYNC_MAX_BATCH) {
        return [
            'ok'      => false,
            'message' => 'Envoi trop volumineux : ' . SYNC_MAX_BATCH . ' opérations au maximum par lot.',
            'results' => [],
        ];
    }

    $results = [];

    // AUDIT 8B1 — UNE OPÉRATION QUI ROMPT N'EMPORTE PAS LE LOT.
    //
    // Un lot va jusqu'à 50 opérations. Sans cette barrière, une seule
    // exception faisait échouer la requête entière : les opérations
    // déjà appliquées ne recevaient jamais leur accusé, et l'appareil
    // les gardait en attente pour les renvoyer indéfiniment.
    //
    //   > Un lot qui tombe entier pour une ligne fait payer à
    //   > quarante-neuf saisies la faute d'une seule.
    //
    // Chaque opération est donc isolée. Le détail technique reste au
    // journal ; l'appareil reçoit un refus clair.
    foreach ($operations as $op) {
        try {
            $results[] = sync_apply_one($schoolId, $deviceId, (int) $user['id'], (array) $op);
        } catch (Throwable $e) {
            log_error('sync.operation_failed', [
                'client_uuid' => (string) ($op['client_uuid'] ?? ''),
                'exception'   => $e->getMessage(),
            ]);

            $results[] = [
                'client_uuid' => strtolower(trim((string) ($op['client_uuid'] ?? ''))),
                'status'      => 'rejected',
                'message'     => 'Le serveur n\'a pas pu traiter cette opération. '
                    . 'Signalez-la à la direction avant de vider les données du navigateur.',
                'entity_id'   => null,
            ];
        }
    }

    db_query(
        'UPDATE sync_devices SET last_sync_at = NOW(), last_seen_at = NOW()
          WHERE id = :id AND school_id = :school_id',
        ['id' => $deviceId, 'school_id' => $schoolId],
        true
    );

    return ['ok' => true, 'message' => count($results) . ' opération(s) traitée(s).', 'results' => $results];
}

/**
 * Traite UNE opération.
 *
 * @return array{client_uuid: string, status: string, message: string, entity_id: ?int}
 */
function sync_apply_one(int $schoolId, int $deviceId, int $userId, array $op): array
{
    $clientUuid = strtolower(trim((string) ($op['client_uuid'] ?? '')));
    $entityType = (string) ($op['entity_type'] ?? '');

    $refuse = static fn (string $m): array => [
        'client_uuid' => $clientUuid, 'status' => 'rejected',
        'message' => $m, 'entity_id' => null,
    ];

    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $clientUuid) !== 1) {
        return $refuse('Identifiant d\'opération invalide.');
    }

    if (!in_array($entityType, SYNC_ENTITIES, true)) {
        return $refuse('Type d\'opération non pris en charge : ' . $entityType);
    }

    // ===============================================================
    //  1. IDEMPOTENCE — une opération déjà reçue ne se rejoue jamais
    // ===============================================================
    // L'appareil renvoie sa file entière quand il retrouve le réseau :
    // sans ce contrôle, un appel enregistré avant la coupure serait
    // appliqué une seconde fois. Le rejeu doit être SANS EFFET, pas
    // seulement « sans dégât ».
    // Cette lecture écarte le cas ordinaire — un appareil qui renvoie
    // une file déjà reçue. Elle ne garantit RIEN contre une course :
    // c'est l'index unique, plus bas, qui tranche. Voir le commentaire
    // de l'`INSERT`.
    $deja = sync_outcome_existante($clientUuid, $schoolId);

    if ($deja !== null) {
        return $deja;
    }

    $payload = (array) ($op['payload'] ?? []);

    // AUDIT 8B1 — L'INDEX TRANCHE, PAS LA LECTURE QUI LE PRÉCÈDE.
    //
    // Le `SELECT` ci-dessus écarte le cas ordinaire, mais il ne
    // sérialise rien : entre lui et cet `INSERT`, une autre requête
    // peut insérer le même `client_uuid`. Mesuré à deux sessions
    // simultanées, trois fois sur trois : le perdant prenait un 1062
    // non rattrapé, donc une erreur 500.
    //
    // Le verrou de session PHP masquait le défaut tant que les deux
    // envois venaient de deux ONGLETS du même navigateur. C'est une
    // propriété du gestionnaire de sessions par fichiers, pas une
    // décision d'architecture — elle disparaîtrait sans prévenir le
    // jour où les sessions changent de support.
    //
    //   > L'unicité se démontre par l'index, pas par une lecture qui
    //   > la précède.
    //
    // On tente donc l'écriture et on laisse `uq_sync_client_uuid`
    // arbitrer. Un 23000 signifie exactement ce que le `SELECT`
    // cherchait à savoir : l'opération est déjà là. On relit son
    // résultat et on le rend — c'est la définition même de
    // l'idempotence, et le second envoi redevient sans effet.
    try {
        $queueId = db_insert('sync_queue', [
            'school_id'      => $schoolId,
            'device_id'      => $deviceId,
            'user_id'        => $userId,
            'client_uuid'    => $clientUuid,
            'entity_type'    => $entityType,
            'operation'      => in_array($op['operation'] ?? '', ['create', 'update', 'delete'], true)
                ? (string) $op['operation'] : 'update',
            'payload'        => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'client_version' => max(1, (int) ($op['client_version'] ?? 1)),
            // L'heure de l'appareil est CONSERVÉE — pour le journal, jamais
            // pour arbitrer. Un téléphone mal réglé ne doit pas pouvoir
            // gagner une divergence en prétendant être arrivé plus tard.
            'client_time'    => sync_safe_datetime((string) ($op['client_time'] ?? '')),
            'status'         => 'pending',
        ], true);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }

        $deja = sync_outcome_existante($clientUuid, $schoolId);

        // L'index a bien refusé, mais la ligne reste introuvable : elle
        // appartient à une AUTRE école. `client_uuid` est unique pour
        // tout le produit, et cette lecture est — à raison — bornée à
        // l'établissement courant. On refuse sans rien révéler de
        // l'autre école, et sans prétendre avoir appliqué.
        return $deja ?? [
            'client_uuid' => $clientUuid,
            'status'      => 'rejected',
            'message'     => 'Identifiant d\'opération déjà utilisé. Videz les données du site '
                . 'dans votre navigateur pour obtenir un appareil neuf.',
            'entity_id'   => null,
        ];
    }

    $outcome = sync_apply_attendance($schoolId, $queueId, $payload);

    db_query(
        'UPDATE sync_queue
            SET status = :status, entity_id = :entity_id, error_message = :msg,
                applied_at = ' . ($outcome['status'] === 'applied' ? 'NOW()' : 'NULL') . '
          WHERE id = :id AND school_id = :school_id',
        [
            'status'    => $outcome['status'],
            'entity_id' => $outcome['entity_id'],
            'msg'       => mb_substr($outcome['message'], 0, 255),
            'id'        => $queueId,
            'school_id' => $schoolId,
        ],
        true
    );

    return [
        'client_uuid' => $clientUuid,
        'status'      => $outcome['status'],
        'message'     => $outcome['message'],
        'entity_id'   => $outcome['entity_id'],
    ];
}

/**
 * Le résultat d'une opération DÉJÀ reçue, ou `null` si elle est neuve.
 *
 * LE CAS DÉLICAT : « ENCORE EN COURS »
 * =====================================
 * Quand deux envois se croisent, le perdant relit la ligne alors que
 * le gagnant n'a peut-être pas fini — la ligne est encore `pending`.
 *
 * Rendre `pending` tel quel serait un piège : côté appareil,
 * `envoyerLaFile()` efface sur `applied`, marque sur `conflict`, et
 * traite TOUT LE RESTE comme un refus définitif qu'il ne réessaiera
 * plus. L'opération serait affichée « refusée » à l'enseignant alors
 * que le serveur était en train de l'appliquer.
 *
 *   > Un état transitoire rendu comme un verdict fait mentir
 *   > l'appareil sur ce que le serveur a fait.
 *
 * On rend donc `pending` avec un message explicite, et l'appareil le
 * traite comme « garde-la, on réessaiera » : au prochain envoi, la
 * ligne portera son état définitif.
 *
 * @return array{client_uuid: string, status: string, message: string, entity_id: ?int}|null
 */
function sync_outcome_existante(string $clientUuid, int $schoolId): ?array
{
    $deja = db_one(
        'SELECT id, status, entity_id, error_message
           FROM sync_queue
          WHERE client_uuid = :u AND school_id = :school_id
          LIMIT 1',
        ['u' => $clientUuid, 'school_id' => $schoolId],
        true
    );

    if ($deja === null) {
        return null;
    }

    $status = (string) $deja['status'];

    $message = $status === 'pending'
        ? 'Réception en cours sur le serveur : conservez cette opération, elle sera confirmée '
          . 'au prochain envoi.'
        : ((string) ($deja['error_message'] ?? '') ?: 'Déjà reçue : résultat inchangé.');

    return [
        'client_uuid' => $clientUuid,
        'status'      => $status,
        'message'     => $message,
        'entity_id'   => $deja['entity_id'] !== null ? (int) $deja['entity_id'] : null,
    ];
}

/**
 * Rejoue un appel de présences.
 *
 * @return array{status: string, message: string, entity_id: ?int}
 */
function sync_apply_attendance(int $schoolId, int $queueId, array $payload): array
{
    $classroomId = (int) ($payload['classroom_id'] ?? 0);
    $date        = (string) ($payload['date'] ?? '');
    $slot        = (string) ($payload['slot'] ?? 'day');
    $entries     = (array) ($payload['entries'] ?? []);

    // Ce que l'appareil a VU avant de partir hors ligne. `null` = « il
    // n'y avait pas encore d'appel ».
    $vu = $payload['seen_updated_at'] ?? null;
    $vu = ($vu === null || $vu === '') ? null : (string) $vu;

    if ($classroomId <= 0 || $entries === []) {
        return ['status' => 'rejected', 'message' => 'Opération incomplète.', 'entity_id' => null];
    }

    // La classe appartient-elle à cette école ? `tenant_find` filtre.
    if (tenant_find('classrooms', $classroomId) === null) {
        return ['status' => 'rejected', 'message' => 'Classe introuvable.', 'entity_id' => null];
    }

    $session = attendance_repo_session($classroomId, $date, $slot);

    // ===============================================================
    //  2. CONCURRENCE OPTIMISTE — on ne recouvre pas en silence
    // ===============================================================
    $actuel = $session !== null ? (string) $session['updated_at'] : null;

    if ($actuel !== $vu) {
        $conflictId = db_insert('sync_conflicts', [
            'school_id'     => $schoolId,
            'sync_queue_id' => $queueId,
            'entity_type'   => 'attendance_session',
            'entity_id'     => $session !== null ? (int) $session['id'] : null,
            'server_values' => json_encode([
                'session_id' => $session !== null ? (int) $session['id'] : null,
                'updated_at' => $actuel,
                'is_locked'  => $session !== null ? (int) $session['is_locked'] : 0,
                'taken_by'   => $session !== null ? $session['taken_by'] : null,
            ], JSON_UNESCAPED_UNICODE),
            'client_values' => json_encode([
                'seen_updated_at' => $vu,
                'entries'         => $entries,
            ], JSON_UNESCAPED_UNICODE),
            'resolution'    => 'pending',
        ], true);

        return [
            'status'  => 'conflict',
            'message' => $vu === null
                ? 'Un appel a été enregistré pour cette date pendant que vous étiez hors connexion. '
                  . 'Votre version attend un arbitrage (conflit n° ' . $conflictId . ').'
                : 'L\'appel a été modifié sur le serveur pendant que vous étiez hors connexion. '
                  . 'Votre version attend un arbitrage (conflit n° ' . $conflictId . ').',
            'entity_id' => $session !== null ? (int) $session['id'] : null,
        ];
    }

    // ===============================================================
    //  3. LE SERVICE MÉTIER A LE DERNIER MOT
    // ===============================================================
    $out = attendance_service_take($classroomId, $date, $entries, $slot);

    if (!$out['ok']) {
        return ['status' => 'rejected', 'message' => (string) $out['message'], 'entity_id' => null];
    }

    $apres = attendance_repo_session($classroomId, $date, $slot);

    return [
        'status'    => 'applied',
        'message'   => (string) $out['message'],
        'entity_id' => $apres !== null ? (int) $apres['id'] : null,
    ];
}

/** Une date d'appareil, ramenée à quelque chose de stockable. */
function sync_safe_datetime(string $raw): string
{
    $ts = strtotime($raw);

    // Une heure absurde n'est pas rejetée — elle est seulement bornée.
    // Refuser l'opération pour une horloge mal réglée ferait perdre un
    // appel réel ; et de toute façon, cette valeur n'arbitre rien.
    if ($ts === false || $ts < strtotime('-2 years') || $ts > strtotime('+2 days')) {
        return date('Y-m-d H:i:s');
    }

    return date('Y-m-d H:i:s', $ts);
}

/**
 * Arbitre un conflit.
 *
 * DEUX ISSUES, ET AUCUNE NE SUPPRIME QUOI QUE CE SOIT.
 *  · `server_wins` — la version du serveur reste, celle de l'appareil
 *    est archivée dans le conflit. Elle n'est pas effacée : « qu'avait
 *    noté l'enseignant ? » doit rester lisible.
 *  · `client_wins` — la version de l'appareil est appliquée, EN
 *    PASSANT PAR LE SERVICE, donc soumise à ses règles : liste blanche
 *    des élèves de la classe, classe de l'établissement, fenêtre de
 *    l'année, cohérence journée/demi-journée. Le refus du service
 *    devient le refus de l'arbitrage, avec SON message.
 *
 * CE QUE LE VERROU DU REGISTRE FAIT — ET NE FAIT PAS ICI
 * =======================================================
 * Une version antérieure de ce commentaire affirmait qu'un arbitrage
 * « ne force pas un registre clos ». C'est faux, et la mesure l'a
 * montré : `attendance_service_take()` laisse passer le détenteur de
 * `attendance.justify`, et TOUS les rôles portant `sync.resolve`
 * (DIRECTION, SCHOOL_ADMIN, SUPER_ADMIN) le portent aussi. Le refus
 * annoncé était structurellement inatteignable.
 *
 *   > Un commentaire qui promet une protection que personne ne peut
 *   > déclencher est pire qu'un silence : il fait croire qu'on a
 *   > vérifié.
 *
 * L'état réel, et il se défend : arbitrer un conflit est un acte de
 * direction, exactement comme corriger un registre clos depuis
 * l'écran. La synchronisation n'ouvre donc AUCUN droit nouveau — elle
 * confère au même agent ce qu'il pourrait déjà faire à la main, et
 * l'inscrit au journal (`sync.conflict_resolved`).
 *
 * Le verrou continue de protéger ce pour quoi il existe : un
 * enseignant ne corrige pas un registre clos, et il n'arbitre pas
 * davantage — `sync.resolve` lui est refusée.
 *
 * @return array{ok: bool, message: string}
 */
function sync_service_resolve(int $conflictId, string $decision): array
{
    if (!can('sync.resolve')) {
        return ['ok' => false, 'message' => 'Arbitrer un conflit relève de la direction.'];
    }

    if (!in_array($decision, ['server_wins', 'client_wins'], true)) {
        return ['ok' => false, 'message' => 'Décision inconnue.'];
    }

    $schoolId = tenant_require();

    $conflict = db_one(
        'SELECT c.*, q.payload, q.client_uuid
           FROM sync_conflicts c
           JOIN sync_queue q ON q.id = c.sync_queue_id AND q.school_id = c.school_id
          WHERE c.id = :id AND c.school_id = :school_id AND c.resolution = \'pending\'
          LIMIT 1',
        ['id' => $conflictId, 'school_id' => $schoolId],
        true
    );

    if ($conflict === null) {
        return ['ok' => false, 'message' => 'Conflit introuvable, ou déjà arbitré.'];
    }

    if ($decision === 'client_wins') {
        $payload = (array) json_decode((string) $conflict['payload'], true);

        // ON REPASSE PAR LE SERVICE, et c'est le point entier de cette
        // branche : la version de l'appareil ne s'écrit pas en direct.
        // Elle subit les mêmes contrôles qu'une saisie à l'écran —
        // élèves de la classe, classe de l'école, fenêtre de l'année.
        // Voir l'en-tête pour ce que le verrou fait, et ne fait pas.
        $out = attendance_service_take(
            (int) ($payload['classroom_id'] ?? 0),
            (string) ($payload['date'] ?? ''),
            (array) ($payload['entries'] ?? []),
            (string) ($payload['slot'] ?? 'day')
        );

        if (!$out['ok']) {
            return [
                'ok'      => false,
                'message' => 'La version de l\'appareil n\'a pas pu être appliquée : ' . $out['message'],
            ];
        }

        db_query(
            'UPDATE sync_queue SET status = \'applied\', applied_at = NOW()
              WHERE id = :id AND school_id = :school_id',
            ['id' => (int) $conflict['sync_queue_id'], 'school_id' => $schoolId],
            true
        );
    }

    db_query(
        'UPDATE sync_conflicts
            SET resolution = :r, resolved_by = :by, resolved_at = NOW()
          WHERE id = :id AND school_id = :school_id',
        [
            'r'         => $decision,
            'by'        => (int) auth_user()['id'],
            'id'        => $conflictId,
            'school_id' => $schoolId,
        ],
        true
    );

    audit_log('sync.conflict_resolved', 'sync_conflicts', $conflictId, null, [
        'decision' => $decision,
    ]);

    return [
        'ok'      => true,
        'message' => $decision === 'client_wins'
            ? 'La version de l\'appareil a été appliquée. L\'ancienne reste dans le journal.'
            : 'La version du serveur est conservée. Celle de l\'appareil reste consultable.',
    ];
}
