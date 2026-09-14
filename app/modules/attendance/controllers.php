<?php
/**
 * Module ATTENDANCE — contrôleurs.
 *
 * Deux écrans : le TABLEAU DU JOUR, qui montre à la direction quelles
 * classes n'ont pas encore appelé, et le REGISTRE d'une classe, où se
 * fait l'appel.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/../students/repositories.php';

/** Date demandée, validée. Aujourd'hui à défaut. */
function attendance_requested_date(): string
{
    $date = (string) input('date', '');

    return attendance_valid_date($date) ? $date : date('Y-m-d');
}

/** Moment demandé, validé contre la liste connue. */
function attendance_requested_slot(): string
{
    $slot = (string) input('moment', 'day');

    return isset(ATTENDANCE_SLOTS[$slot]) ? $slot : 'day';
}

// ---------------------------------------------------------------------
//  TABLEAU DU JOUR
// ---------------------------------------------------------------------

function ctrl_attendance_index(): void
{
    $date = attendance_requested_date();
    $slot = attendance_requested_slot();
    $year = tenant_one('academic_years', 'is_current = 1');

    $classrooms = [];

    if ($year !== null) {
        // LE PÉRIMÈTRE, DÈS LA LISTE.
        //
        // attendance.view est accordée à PARENT et ELEVE depuis la
        // phase 1. Sans ce filtre, le tableau du jour livrerait les
        // effectifs et les absents de toute l'école — exactement la
        // fuite corrigée aux phases 3 et 4B.
        foreach (attendance_repo_day_overview((int) $year['id'], $date, $slot) as $row) {
            if (students_can_view_classroom((int) $row['classroom_id'])) {
                $classrooms[] = $row;
            }
        }
    }

    view('attendance/index', [
        'title'      => 'Présences',
        'date'       => $date,
        'slot'       => $slot,
        'slots'      => ATTENDANCE_SLOTS,
        'year'       => $year,
        'classrooms' => $classrooms,
    ]);
}

// ---------------------------------------------------------------------
//  REGISTRE D'UNE CLASSE
// ---------------------------------------------------------------------

function ctrl_attendance_register(string $id): void
{
    $classroomId = (int) $id;
    $classroom   = tenant_find('classrooms', $classroomId);

    if ($classroom === null || !students_can_view_classroom($classroomId)) {
        abort(404, 'Classe introuvable.');
    }

    $date    = attendance_requested_date();
    $slot    = attendance_requested_slot();
    $session = attendance_repo_session($classroomId, $date, $slot);

    view('attendance/register', [
        'title'     => 'Appel — ' . $classroom['name'],
        'classroom' => $classroom,
        'year'      => tenant_find('academic_years', (int) $classroom['academic_year_id']),
        'date'      => $date,
        'slot'      => $slot,
        'slots'     => ATTENDANCE_SLOTS,
        'session'   => $session,
        'students'  => attendance_repo_register($classroomId, $session !== null ? (int) $session['id'] : null),
        'statuses'  => ATTENDANCE_STATUSES,
        'canRecord' => attendance_service_can_record($classroomId, $session),
    ]);
}

function ctrl_attendance_save(string $id): void
{
    $classroomId = (int) $id;
    $date        = attendance_requested_date();
    $slot        = attendance_requested_slot();

    $statuses = (array) input('status', []);
    $minutes  = (array) input('minutes', []);
    $entries  = [];

    foreach ($statuses as $enrollmentId => $status) {
        $entries[(int) $enrollmentId] = [
            'status'       => (string) $status,
            'minutes_late' => $minutes[$enrollmentId] ?? null,
        ];
    }

    $outcome = attendance_service_take($classroomId, $date, $entries, $slot);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
    } else {
        flash_success($outcome['saved'] . ' élève(s) enregistré(s).');

        // Un appel partiel est accepté, jamais tu. Le taire présenterait
        // une saisie incomplète comme terminée.
        if (($outcome['not_covered'] ?? 0) > 0) {
            flash_warning(
                $outcome['not_covered'] . ' élève(s) de cette classe n\'ont été ni vus ni signalés.'
                . ' L\'appel est enregistré comme INCOMPLET.'
            );
        }

        foreach ($outcome['errors'] as $message) {
            flash_warning($message);
        }
    }

    redirect('/presences/classe/' . $classroomId . '?date=' . $date . '&moment=' . $slot);
}

function ctrl_attendance_justify(string $id): void
{
    $recordId = (int) $id;
    $justify  = (string) input('justifier', '1') === '1';

    $outcome = attendance_service_justify($recordId, $justify, (string) input('motif', ''));

    $outcome['ok']
        ? flash_success($justify ? 'Absence justifiée.' : 'Justification retirée.')
        : flash_error($outcome['message']);

    redirect((string) input('retour', '/presences'));
}

function ctrl_attendance_lock(string $id): void
{
    $sessionId = (int) $id;
    $lock      = (string) input('verrouiller', '1') === '1';

    $outcome = attendance_service_lock($sessionId, $lock);

    $outcome['ok']
        ? flash_success($lock ? 'Registre verrouillé.' : 'Registre déverrouillé.')
        : flash_error($outcome['message']);

    redirect((string) input('retour', '/presences'));
}
