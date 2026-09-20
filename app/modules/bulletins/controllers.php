<?php
/**
 * Module BULLETINS — contrôleurs.
 */

declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/../students/repositories.php';
// grades_format() vient du module NOTES : le gabarit du bulletin publié
// s'en sert pour écrire « 15 » et non « 15,00 ».
require_once __DIR__ . '/../grades/services.php';

/**
 * Regroupement demandé, validé contre la liste connue.
 *
 * Une clé venue de l'URL ne doit jamais atteindre une requête sans
 * avoir été reconnue : BULLETIN_GROUPS fait office de liste blanche.
 */
function bulletins_requested_group(array $groups): string
{
    $key = strtoupper((string) input('periode', 'ANNUAL'));

    return isset($groups[$key]) ? $key : 'ANNUAL';
}

// ---------------------------------------------------------------------
//  CLASSE : état de publication et classement
// ---------------------------------------------------------------------

function ctrl_bulletins_classroom(string $id): void
{
    $classroomId = (int) $id;
    $classroom   = tenant_find('classrooms', $classroomId);

    if ($classroom === null || !students_can_view_classroom($classroomId)) {
        abort(404, 'Classe introuvable.');
    }

    $groups    = bulletins_classroom_groups($classroomId);
    $periodKey = bulletins_requested_group($groups);
    $year      = tenant_find('academic_years', (int) $classroom['academic_year_id']);
    $published = bulletins_repo_classroom($classroomId, $periodKey);

    // Tant que rien n'est publié, on affiche l'état de TRAVAIL, recalculé
    // à chaque fois. Une fois publié, on montre ce qui a été figé : c'est
    // ce document que les parents ont reçu.
    $live = $published === []
        ? bulletins_service_rank_classroom($classroomId, $periodKey)
        : [];

    view('bulletins/classroom', [
        'title'      => 'Bulletins — ' . $classroom['name'],
        'classroom'  => $classroom,
        'year'       => $year,
        'periodKey'  => $periodKey,
        'groups'     => $groups,
        'published'  => $published,
        'live'       => $live,
        'status'     => bulletins_repo_classroom_status($classroomId),
        'threshold'  => bulletins_passing_threshold_safe(),
        'mode'       => bulletins_absence_mode(),
        'decisions'  => BULLETIN_DECISIONS,
    ]);
}

function ctrl_bulletins_publish(string $id): void
{
    $classroomId = (int) $id;
    $periodKey   = bulletins_requested_group(bulletins_classroom_groups($classroomId));

    $outcome = bulletins_service_publish($classroomId, $periodKey);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
    } else {
        flash_success($outcome['published'] . ' bulletin(s) publié(s).');

        foreach ($outcome['warnings'] as $warning) {
            flash_warning($warning);
        }
    }

    redirect('/bulletins/classe/' . $classroomId . '?periode=' . $periodKey);
}

// ---------------------------------------------------------------------
//  BULLETIN D'UN ÉLÈVE
// ---------------------------------------------------------------------

function ctrl_bulletins_show(string $id): void
{
    $enrollmentId = (int) $id;
    $enrollment   = tenant_find('enrollments', $enrollmentId);

    if ($enrollment === null) {
        abort(404, 'Inscription introuvable.');
    }

    // Le périmètre est celui de l'ÉLÈVE, pas celui de la classe.
    //
    // Aujourd'hui la route exige bulletin.generate, que seuls la
    // direction, le préfet et l'administrateur d'école détiennent : aucun
    // parent ne passe ici. Le portail parents relève de la phase 6 et
    // apportera sa propre permission, avec le filtre « mes enfants » —
    // on ne rouvre pas grade.view/bulletin.* aux parents avant, l'audit
    // de la phase 3 ayant précisément retiré une permission accordée sans
    // périmètre.
    //
    // Le contrôle ci-dessous n'est donc pas décoratif pour autant : les
    // rôles sont modifiables par l'établissement, et une école qui
    // accorde bulletin.generate à ses titulaires doit trouver ici la même
    // limite que sur la liste de classe.
    if (!students_can_view((int) $enrollment['student_id'], (int) $enrollment['academic_year_id'])) {
        abort(404, 'Bulletin introuvable.');
    }

    $header = bulletins_repo_header($enrollmentId);

    if ($header === null) {
        abort(404, 'Bulletin introuvable.');
    }

    view('bulletins/show', [
        'title'     => 'Bulletin — ' . full_name($header['last_name'], $header['post_name'], $header['first_name']),
        'header'    => $header,
        'report'    => bulletins_service_compute($enrollmentId),
        'published' => bulletins_repo_for_enrollment($enrollmentId),
        'groups'    => bulletins_classroom_groups((int) $header['classroom_id']),
        // Famille de mise en page : par domaines ou par blocs de maxima.
        'model'     => bulletins_model_for_classroom((int) $header['classroom_id']),
        'threshold' => bulletins_passing_threshold_safe(),
        'decisions' => BULLETIN_DECISIONS,
        'canDecide' => can('bulletin.publish'),
    ], 'print');
}

function ctrl_bulletins_decide(string $id): void
{
    $enrollmentId = (int) $id;
    $decision     = (string) input('decision', '');

    $outcome = bulletins_service_set_decision($enrollmentId, $decision);

    $outcome['ok']
        ? flash_success('Décision enregistrée.')
        : flash_error($outcome['message']);

    redirect('/bulletins/' . $enrollmentId);
}

// ---------------------------------------------------------------------
//  PARAMÈTRE D'ÉTABLISSEMENT
// ---------------------------------------------------------------------

function ctrl_bulletins_settings(): void
{
    $mode      = (string) input('absence_mode', '');
    $threshold = input('passing_threshold');

    if (in_array($mode, ['excluded', 'zero'], true)) {
        school_setting_set('grading.absence_mode', $mode);
    }

    if ($threshold !== null && $threshold !== '' && is_numeric($threshold)) {
        $value = (float) $threshold;

        if ($value >= 1.0 && $value <= 99.0) {
            school_setting_set('grading.passing_threshold', $value);
        } else {
            flash_error('Le seuil de réussite doit être compris entre 1 et 99.');
        }
    }

    flash_success('Paramètres enregistrés.');
    redirect('/bulletins/parametres');
}

function ctrl_bulletins_settings_form(): void
{
    view('bulletins/settings', [
        'title'     => 'Paramètres des bulletins',
        'mode'      => bulletins_absence_mode(),
        'threshold' => bulletins_passing_threshold_safe(),
    ]);
}

/** Seuil de réussite, en chargeant le module qui le porte. */
function bulletins_passing_threshold_safe(): float
{
    require_once APP_PATH . '/modules/teachers/services.php';

    return teachers_passing_threshold();
}

/**
 * Le même document, côté personnel.
 *
 * Le périmètre est celui de la scolarité — un titulaire doit pouvoir
 * réimprimer le bulletin d'un de ses élèves. Deux rendus du même
 * document finiraient par diverger : c'est pourquoi le gabarit est
 * partagé avec le portail.
 */
function ctrl_bulletins_published(string $id): void
{
    $bulletin = bulletins_repo_published((int) $id);

    if ($bulletin === null) {
        abort(404, 'Bulletin introuvable.');
    }

    if (!students_can_view((int) $bulletin['student_id'], (int) $bulletin['academic_year_id'])) {
        abort(404, 'Bulletin introuvable.');
    }

    view('bulletins/published', [
        'title'     => 'Bulletin publié',
        'bulletin'  => $bulletin,
        'report'    => bulletins_repo_lines((int) $id),
        'threshold' => bulletins_passing_threshold_safe(),
        'decisions' => BULLETIN_DECISIONS,
        'backUrl'   => '/bulletins/' . (int) $bulletin['enrollment_id'],
        'backLabel' => 'Retour au relevé',
    ], 'print');
}
