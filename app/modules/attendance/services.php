<?php
/**
 * Module ATTENDANCE — règles métier.
 *
 * Quatre principes gouvernent ce module.
 *
 *  1. L'APPEL EST UNE TRACE, PAS UNE LISTE D'ABSENTS
 *     Une journée sans appel et une journée sans absent produisent le
 *     même ensemble vide. Seule la session distingue les deux, et c'est
 *     elle qui permet de demander « quelles classes n'ont pas appelé ».
 *
 *  2. UNE ABSENCE JUSTIFIÉE RESTE UNE ABSENCE
 *     La justification est un attribut, jamais un statut. Un élève
 *     absent trente jours avec motif a manqué trente jours de cours.
 *
 *  3. LA PERMISSION DIT CE QU'ON PEUT FAIRE, JAMAIS SUR QUI
 *     PARENT et ELEVE détiennent attendance.view depuis la phase 1.
 *     Accordée sans périmètre, elle ouvrirait le registre de toute
 *     l'école — la faute exacte que les audits des phases 3 et 4B ont
 *     eu à corriger. Chaque porte de ce module porte donc le périmètre
 *     dès sa première ligne.
 *
 *  4. UN REGISTRE SE CORRIGE, PUIS SE VERROUILLE
 *     Une erreur d'appel se rectifie le jour même. Passé le verrou,
 *     seule la direction intervient : un registre librement réinscriptible
 *     ne vaut rien devant une contestation de famille.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/../students/repositories.php';

const ATTENDANCE_STATUSES = [
    'present' => 'Présent',
    'absent'  => 'Absent',
    'late'    => 'En retard',
];

const ATTENDANCE_SLOTS = [
    'day'       => 'Journée',
    'morning'   => 'Matin',
    'afternoon' => 'Après-midi',
];

/**
 * L'appel de cette classe peut-il être saisi ou corrigé ?
 *
 * @return array{ok: bool, message: string}
 */
function attendance_service_can_record(int $classroomId, ?array $session = null): array
{
    if (!perm_has('attendance.record')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de faire l\'appel.'];
    }

    $classroom = tenant_find('classrooms', $classroomId);

    if ($classroom === null) {
        return ['ok' => false, 'message' => 'Classe introuvable.'];
    }

    // Le périmètre, dès la première porte. Un enseignant n'appelle que
    // ses classes ; la direction appelle partout.
    if (!students_can_view_classroom($classroomId)) {
        return ['ok' => false, 'message' => 'Cette classe ne relève pas de votre périmètre.'];
    }

    $year = tenant_find('academic_years', (int) $classroom['academic_year_id']);

    if ($year === null || in_array($year['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'message' => 'Cette année scolaire est clôturée.'];
    }

    // Un registre verrouillé ne se corrige que par la direction.
    if ($session !== null && (int) $session['is_locked'] === 1 && !perm_has('attendance.justify')) {
        return ['ok' => false, 'message' => 'Ce registre est verrouillé. Seule la direction peut encore le corriger.'];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Enregistre l'appel d'une classe pour une date.
 *
 * Idempotent : rappelé pour la même classe, la même date et le même
 * moment, il met à jour la session existante au lieu d'en créer une
 * seconde.
 *
 * @param array<int, array{status: string, minutes_late?: mixed}> $entries
 *        Indexé par enrollment_id.
 * @return array{ok: bool, saved: int, message: string, errors: array}
 */
function attendance_service_take(
    int $classroomId,
    string $date,
    array $entries,
    string $slot = 'day'
): array {
    if (!isset(ATTENDANCE_SLOTS[$slot])) {
        return ['ok' => false, 'saved' => 0, 'message' => 'Moment de la journée inconnu.', 'errors' => []];
    }

    if (!attendance_valid_date($date)) {
        return ['ok' => false, 'saved' => 0, 'message' => 'Date invalide.', 'errors' => []];
    }

    $existing = attendance_repo_session($classroomId, $date, $slot);

    // « JOURNÉE » ET « DEMI-JOURNÉE » NE COHABITENT PAS.
    //
    // Rien n'empêchait d'appeler la journée entière PUIS le matin : deux
    // sessions pour un même jour, et chaque absence comptée deux fois
    // dans les cumuls. Une école appelle une fois par jour ou deux, pas
    // les deux à la fois.
    if ($existing === null) {
        $conflicting = $slot === 'day' ? ['morning', 'afternoon'] : ['day'];

        foreach ($conflicting as $other) {
            if (attendance_repo_session($classroomId, $date, $other) !== null) {
                return [
                    'ok' => false, 'saved' => 0,
                    'message' => 'Un appel « ' . ATTENDANCE_SLOTS[$other]
                        . ' » existe déjà pour cette date. Une journée ne peut pas être'
                        . ' appelée à la fois en entier et par demi-journée.',
                    'errors' => [],
                ];
            }
        }
    }

    $auth = attendance_service_can_record($classroomId, $existing);

    if (!$auth['ok']) {
        return ['ok' => false, 'saved' => 0, 'message' => $auth['message'], 'errors' => []];
    }

    $classroom = tenant_find('classrooms', $classroomId);
    $year      = tenant_find('academic_years', (int) $classroom['academic_year_id']);

    // L'appel ne peut pas précéder la rentrée ni suivre la clôture : une
    // date hors année scolaire ne relèverait d'aucun registre.
    if ($date < (string) $year['starts_on'] || $date > (string) $year['ends_on']) {
        return [
            'ok' => false, 'saved' => 0,
            'message' => 'Cette date est hors de l\'année scolaire ('
                . $year['starts_on'] . ' à ' . $year['ends_on'] . ').',
            'errors' => [],
        ];
    }

    // Liste blanche : seules les inscriptions de CETTE classe. Sans ce
    // filtrage, un identifiant forgé dans le formulaire marquerait
    // absent l'élève d'une autre classe.
    $allowed = [];

    foreach (attendance_repo_register($classroomId, null) as $row) {
        $allowed[(int) $row['enrollment_id']] = true;
    }

    $errors = [];
    $valid  = [];

    foreach ($entries as $enrollmentId => $entry) {
        $enrollmentId = (int) $enrollmentId;

        if (!isset($allowed[$enrollmentId])) {
            $errors[$enrollmentId] = 'Élève absent de cette classe.';
            continue;
        }

        $status = (string) ($entry['status'] ?? 'present');

        if (!isset(ATTENDANCE_STATUSES[$status])) {
            $errors[$enrollmentId] = 'Statut inconnu.';
            continue;
        }

        // La durée n'a de sens que pour un retard. La conserver sur un
        // « présent » laisserait traîner un chiffre que les statistiques
        // additionneraient.
        $minutes = null;

        if ($status === 'late') {
            $raw = $entry['minutes_late'] ?? null;

            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                $minutes = max(0, min(600, (int) $raw));
            }
        }

        $valid[$enrollmentId] = ['status' => $status, 'minutes_late' => $minutes];
    }

    if ($valid === []) {
        return [
            'ok' => false, 'saved' => 0,
            'message' => 'Aucun élève à enregistrer.',
            'errors'  => $errors,
        ];
    }

    $saved = db_transaction(static function () use ($classroomId, $date, $slot, $valid, $existing): int {
        $sessionId = $existing !== null
            ? (int) $existing['id']
            : tenant_insert('attendance_sessions', [
                'classroom_id' => $classroomId,
                'session_date' => $date,
                'slot'         => $slot,
                'taken_by'     => auth_id(),
            ]);

        if ($existing !== null) {
            tenant_update(
                'attendance_sessions',
                ['taken_by' => auth_id(), 'taken_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $sessionId]
            );
        }

        $count = 0;

        foreach ($valid as $enrollmentId => $entry) {
            // La justification n'est JAMAIS touchée ici : refaire l'appel
            // ne doit pas effacer le motif qu'un secrétariat a saisi.
            db_query(
                'INSERT INTO attendance_records
                    (school_id, session_id, enrollment_id, status, minutes_late)
                 VALUES (:school_id, :session_id, :enrollment_id, :status, :minutes)
                 ON DUPLICATE KEY UPDATE
                    status       = VALUES(status),
                    minutes_late = VALUES(minutes_late)',
                [
                    'school_id'     => tenant_require(),
                    'session_id'    => $sessionId,
                    'enrollment_id' => $enrollmentId,
                    'status'        => $entry['status'],
                    'minutes'       => $entry['minutes_late'],
                ]
            );

            $count++;
        }

        return $count;
    });

    audit_log(
        $existing !== null ? 'update' : 'create',
        'attendance',
        $classroomId,
        null,
        ['date' => $date, 'slot' => $slot, 'count' => $saved],
        'Appel du ' . $date . ' — ' . $classroom['name']
    );

    // COMBIEN D'ÉLÈVES L'APPEL N'A-T-IL PAS COUVERTS ?
    //
    // Un appel partiel est accepté — un élève peut arriver après — mais
    // il ne doit JAMAIS passer pour complet. Les taire reproduirait la
    // faute de la phase 4B : une saisie incomplète présentée comme
    // terminée. Ils sont donc comptés, remontés à l'appelant et affichés.
    $notCovered = max(0, count($allowed) - count(
        db_all(
            'SELECT enrollment_id FROM attendance_records
              WHERE school_id = :school_id AND session_id = :session_id',
            [
                'school_id'  => tenant_require(),
                'session_id' => (int) attendance_repo_session($classroomId, $date, $slot)['id'],
            ]
        )
    ));

    return [
        'ok'          => true,
        'saved'       => $saved,
        'not_covered' => $notCovered,
        'message'     => '',
        'errors'      => $errors,
    ];
}

/**
 * Justifie — ou dé-justifie — une absence.
 *
 * Le statut ne change pas : l'élève reste absent. Seul le motif
 * s'attache, avec son auteur et sa date.
 */
function attendance_service_justify(int $recordId, bool $justified, string $reason = ''): array
{
    if (!perm_has('attendance.justify')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de justifier une absence.'];
    }

    $record = tenant_find('attendance_records', $recordId);

    if ($record === null) {
        return ['ok' => false, 'message' => 'Enregistrement introuvable.'];
    }

    $session = tenant_find('attendance_sessions', (int) $record['session_id']);

    if ($session === null) {
        return ['ok' => false, 'message' => 'Registre introuvable.'];
    }

    // Même règle que partout : le droit ne désigne pas la classe.
    if (!students_can_view_classroom((int) $session['classroom_id'])) {
        return ['ok' => false, 'message' => 'Cette classe ne relève pas de votre périmètre.'];
    }

    if ($record['status'] === 'present') {
        return ['ok' => false, 'message' => 'Une présence n\'a pas à être justifiée.'];
    }

    tenant_update('attendance_records', [
        'is_justified'  => $justified ? 1 : 0,
        'justification' => $justified ? mb_substr(trim($reason), 0, 255) : null,
        'justified_by'  => $justified ? auth_id() : null,
        'justified_at'  => $justified ? date('Y-m-d H:i:s') : null,
    ], 'id = :id', ['id' => $recordId]);

    audit_log(
        'update',
        'attendance_record',
        $recordId,
        null,
        ['justified' => $justified],
        $justified ? 'Absence justifiée' : 'Justification retirée'
    );

    return ['ok' => true, 'message' => ''];
}

/**
 * Verrouille le registre d'une journée.
 *
 * Passé le verrou, seule la direction corrige. Même principe que le
 * verrouillage des périodes de notes : un document qui engage
 * l'établissement cesse d'être librement réinscriptible.
 */
function attendance_service_lock(int $sessionId, bool $locked): array
{
    if (!perm_has('attendance.justify')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de verrouiller un registre.'];
    }

    $session = tenant_find('attendance_sessions', $sessionId);

    if ($session === null) {
        return ['ok' => false, 'message' => 'Registre introuvable.'];
    }

    if (!students_can_view_classroom((int) $session['classroom_id'])) {
        return ['ok' => false, 'message' => 'Cette classe ne relève pas de votre périmètre.'];
    }

    tenant_update('attendance_sessions', [
        'is_locked' => $locked ? 1 : 0,
        'locked_at' => $locked ? date('Y-m-d H:i:s') : null,
        'locked_by' => $locked ? auth_id() : null,
    ], 'id = :id', ['id' => $sessionId]);

    audit_log(
        'update',
        'attendance',
        $sessionId,
        null,
        ['locked' => $locked],
        $locked ? 'Registre verrouillé' : 'Registre déverrouillé'
    );

    return ['ok' => true, 'message' => ''];
}

/** Une date au format ISO, et réellement existante. */
function attendance_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}
