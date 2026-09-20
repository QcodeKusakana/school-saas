<?php
/**
 * Module PORTAIL — contrôleurs (phase 6A).
 *
 * Trois écrans, tous en LECTURE SEULE :
 *   · /espace                  — mes enfants ;
 *   · /espace/enfant/{id}      — le dossier d'un enfant ;
 *   · /espace/bulletin/{id}    — un bulletin publié, imprimable.
 *
 * LE PÉRIMÈTRE EST VÉRIFIÉ DANS CHAQUE CONTRÔLEUR
 * -----------------------------------------------
 * `portal_is_guardian_of()` est appelée avant tout affichage. Restreindre
 * la liste ne protège rien si l'accès direct par identifiant reste
 * ouvert : c'est exactement le défaut trouvé à l'audit de la phase 3.
 *
 * Un refus répond 404, jamais 403 : un tuteur n'a pas à apprendre qu'un
 * élève d'identifiant 812 existe dans l'établissement.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/services.php';
require_once APP_PATH . '/modules/finance/repositories.php';
require_once APP_PATH . '/modules/bulletins/repositories.php';
require_once APP_PATH . '/modules/bulletins/services.php';
// bulletins_passing_threshold_safe() y est définie.
require_once APP_PATH . '/modules/bulletins/controllers.php';
require_once APP_PATH . '/modules/students/repositories.php';
// grades_format() est définie dans le module NOTES : le gabarit du
// bulletin publié l'utilise pour n'afficher « 15 » plutôt que « 15,00 ».
require_once APP_PATH . '/modules/grades/services.php';

/** Accueil du portail : la liste de mes enfants. */
function ctrl_portal_index(): void
{
    $students = portal_students();
    $showFees = portal_student_sees_fees();
    $cards    = [];

    foreach ($students as $child) {
        $enrollment = portal_current_enrollment((int) $child['id']);
        $isSelf     = (int) $child['is_self'] === 1;

        // On ne CALCULE pas ce qui ne doit pas être montré : masquer à
        // l'affichage laisserait la donnée voyager jusqu'au gabarit, où
        // une prochaine modification l'exposerait par inadvertance.
        $maySeeFees = !$isSelf || $showFees;

        $cards[] = [
            'student'    => $child,
            'enrollment' => $enrollment,
            'balance'    => ($enrollment !== null && $maySeeFees)
                ? finance_repo_balance((int) $enrollment['id'])
                : [],
            'absences'   => $enrollment !== null
                ? portal_absence_counts((int) $enrollment['id'])
                : ['absences' => 0, 'retards' => 0, 'justifiees' => 0],
            'bulletins'  => count(bulletins_repo_published_for_student((int) $child['id'])),
            'is_self'    => (int) $child['is_self'] === 1,
        ];
    }

    view('portal/index', [
        'title' => 'Mon espace',
        'cards' => $cards,
        // Le solde des frais n'est montré à l'ÉLÈVE que si
        // l'établissement l'a décidé (voir portal_student_sees_fees()).
        'showFees' => portal_student_sees_fees(),
    ]);
}

/** Le dossier d'un enfant : bulletins, absences, situation financière. */
function ctrl_portal_child(string $id): void
{
    $studentId = (int) $id;

    if (!portal_can_view_student($studentId)) {
        abort(404, 'Élève introuvable.');
    }

    $student = tenant_find('students', $studentId);

    if ($student === null || $student['deleted_at'] !== null) {
        abort(404, 'Élève introuvable.');
    }

    $enrollment = portal_current_enrollment($studentId);

    // Le tuteur voit toujours le solde ; l'élève, seulement si l'école
    // l'a autorisé.
    $showFinance = portal_is_guardian_of($studentId) || portal_student_sees_fees();

    view('portal/child', [
        'title'      => full_name($student['last_name'], $student['post_name'], $student['first_name']),
        'student'    => $student,
        'enrollment' => $enrollment,
        'bulletins'  => bulletins_repo_published_for_student($studentId),
        'absences'   => $enrollment !== null ? portal_absences((int) $enrollment['id']) : [],
        'counts'     => $enrollment !== null
            ? portal_absence_counts((int) $enrollment['id'])
            : ['absences' => 0, 'retards' => 0, 'justifiees' => 0],
        // LA DETTE EST UNE AFFAIRE DE PARENTS.
        //
        // Un élève de douze ans n'a pas à porter le poids d'un minerval
        // impayé, et l'école n'a pas à le lui annoncer par un écran. Le
        // solde ne lui est montré que si l'établissement l'a décidé —
        // certaines écoles d'humanités, où les élèves paient eux-mêmes,
        // voudront l'inverse.
        'balance'    => ($enrollment !== null && $showFinance)
            ? finance_repo_balance((int) $enrollment['id'])
            : [],
        'showFinance' => $showFinance,
    ]);
}

/**
 * Un bulletin publié.
 *
 * Rendu UNIQUEMENT depuis le figé : c'est ce qui garantit que la famille
 * rouvre exactement le document qu'elle a reçu, même si une cote a été
 * corrigée depuis.
 */
function ctrl_portal_bulletin(string $id): void
{
    $bulletin = bulletins_repo_published((int) $id);

    if ($bulletin === null) {
        abort(404, 'Bulletin introuvable.');
    }

    if (!portal_can_view_student((int) $bulletin['student_id'])) {
        abort(404, 'Bulletin introuvable.');
    }

    view('bulletins/published', [
        'title'     => 'Bulletin',
        'bulletin'  => $bulletin,
        'report'    => bulletins_repo_lines((int) $id),
        'threshold' => bulletins_passing_threshold_safe(),
        'decisions' => BULLETIN_DECISIONS,
        'backUrl'   => '/espace/enfant/' . (int) $bulletin['student_id'],
        'backLabel' => 'Retour au dossier',
    ], 'print');
}

/**
 * Crée l'accès au portail pour un tuteur, depuis la fiche de l'élève.
 *
 * Le mot de passe initial est affiché UNE SEULE FOIS, dans un message
 * qui survit à la redirection. Il n'est ni journalisé, ni stocké en
 * clair, ni envoyé : l'agent le remet de la main à la main.
 */
function ctrl_portal_guardian_access(string $id, string $guardianId): void
{
    $studentId = (int) $id;

    // Le périmètre de la SCOLARITÉ s'applique ici : c'est le secrétariat
    // qui agit, pas le parent. Sans ce contrôle, un agent au périmètre
    // restreint pourrait créer un accès sur un dossier qu'il n'a pas le
    // droit de consulter.
    if (!students_can_view($studentId)) {
        abort(404, 'Élève introuvable.');
    }

    // Le tuteur doit être rattaché À CET élève : l'identifiant vient de
    // l'URL, donc de l'utilisateur.
    $linked = db_exists(
        'SELECT 1 FROM student_guardians
          WHERE school_id = :school_id AND student_id = :student_id AND guardian_id = :guardian_id
          LIMIT 1',
        [
            'school_id'   => tenant_require(),
            'student_id'  => $studentId,
            'guardian_id' => (int) $guardianId,
        ]
    );

    if (!$linked) {
        abort(404, 'Tuteur introuvable pour cet élève.');
    }

    $outcome = portal_service_create_guardian_access((int) $guardianId);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        redirect('/eleves/' . $studentId);
    }

    flash_success($outcome['message']);
    flash_warning(
        'Identifiant : ' . $outcome['username']
        . ' — Mot de passe initial : ' . $outcome['password']
        . ' . Notez-le maintenant : il ne sera plus jamais affiché, et son '
        . 'changement est imposé à la première connexion.'
    );

    redirect('/eleves/' . $studentId);
}

/**
 * Crée l'accès au portail pour un élève, depuis sa fiche.
 *
 * Même circuit que pour un tuteur : le mot de passe initial est affiché
 * une seule fois, jamais journalisé, et son changement est imposé.
 */
function ctrl_portal_student_access(string $id): void
{
    $studentId = (int) $id;

    // Le périmètre de la SCOLARITÉ s'applique : c'est le secrétariat qui
    // agit. Sans ce contrôle, un agent au périmètre restreint créerait un
    // accès sur un dossier qu'il n'a pas le droit de consulter.
    if (!students_can_view($studentId)) {
        abort(404, 'Élève introuvable.');
    }

    $outcome = portal_service_create_student_access($studentId);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        redirect('/eleves/' . $studentId);
    }

    flash_success($outcome['message']);
    flash_warning(
        'Identifiant : ' . $outcome['username']
        . ' — Mot de passe initial : ' . $outcome['password']
        . ' . Notez-le maintenant : il ne sera plus jamais affiché, et son '
        . 'changement est imposé à la première connexion.'
    );

    redirect('/eleves/' . $studentId);
}

/**
 * Régénère l'accès d'un tuteur ou d'un élève, depuis la fiche de l'élève.
 *
 * Deux chemins, un seul service : `$kind` dit lequel, et le contrôleur
 * vérifie dans les deux cas que la personne est bien rattachée À CET
 * élève — l'identifiant vient de l'URL, donc de l'utilisateur.
 */
function ctrl_portal_reset_access(string $id, string $kind, string $targetId): void
{
    $studentId = (int) $id;

    if (!students_can_view($studentId)) {
        abort(404, 'Élève introuvable.');
    }

    $userId = 0;

    if ($kind === 'tuteur') {
        $linked = db_exists(
            'SELECT 1 FROM student_guardians
              WHERE school_id = :school_id AND student_id = :student_id AND guardian_id = :guardian_id
              LIMIT 1',
            [
                'school_id'   => tenant_require(),
                'student_id'  => $studentId,
                'guardian_id' => (int) $targetId,
            ]
        );

        if (!$linked) {
            abort(404, 'Tuteur introuvable pour cet élève.');
        }

        $userId = (int) db_value(
            'SELECT user_id FROM guardians WHERE id = :id AND school_id = :school_id',
            ['id' => (int) $targetId, 'school_id' => tenant_require()]
        );
    } elseif ($kind === 'eleve') {
        // L'élève visé DOIT être celui de la fiche ouverte.
        if ((int) $targetId !== $studentId) {
            abort(404, 'Élève introuvable.');
        }

        $userId = (int) db_value(
            'SELECT user_id FROM students WHERE id = :id AND school_id = :school_id',
            ['id' => $studentId, 'school_id' => tenant_require()]
        );
    } else {
        abort(404, 'Type d\'accès inconnu.');
    }

    if ($userId <= 0) {
        flash_error('Cette personne n\'a pas encore d\'accès au portail.');
        redirect('/eleves/' . $studentId);
    }

    $outcome = portal_service_reset_access($userId);

    if (!$outcome['ok']) {
        flash_error($outcome['message']);
        redirect('/eleves/' . $studentId);
    }

    flash_success($outcome['message']);
    flash_warning(
        'Identifiant : ' . $outcome['username']
        . ' — Nouveau mot de passe : ' . $outcome['password']
        . ' . Notez-le maintenant : il ne sera plus jamais affiché, et son '
        . 'changement est imposé à la première connexion.'
    );

    redirect('/eleves/' . $studentId);
}
