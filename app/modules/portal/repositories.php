<?php
/**
 * Module PORTAIL — dépôt (phase 6A).
 *
 * LE PORTAIL N'A PAS DE PERMISSION : IL A UN LIEN DE TUTELLE
 * ==========================================================
 * Tous les autres modules commencent par une permission, puis
 * appliquent un périmètre. Le portail fait l'inverse, et c'est
 * délibéré.
 *
 * `students_scope_clause()` réunit DEUX périmètres : celui du tuteur et
 * celui de l'enseignant. Il est juste pour la scolarité — un professeur
 * doit voir les élèves de ses classes. Il serait faux ici : un
 * enseignant n'a rien à lire dans l'espace familial d'un élève, même
 * d'un élève qu'il a en classe.
 *
 * Le portail se fonde donc uniquement sur `guardians.user_id`. La
 * conséquence est forte et voulue : AUCUNE permission, si mal accordée
 * soit-elle, ne peut ouvrir le portail sur l'enfant d'un autre. Un
 * directeur qui a ses propres enfants dans l'école y accède comme
 * n'importe quel parent — et n'y voit que les siens.
 *
 * La contrepartie est qu'un compte non rattaché à une fiche tuteur ne
 * voit rien. L'écran le dit clairement plutôt que d'afficher une page
 * vide : une absence de données ne doit jamais ressembler à une panne.
 *
 *
 * DEUX PUBLICS, UN SEUL PÉRIMÈTRE (phase 6B)
 * ==========================================
 * Le portail sert maintenant les tuteurs ET les élèves. La clé reste de
 * même nature — un LIEN, jamais une permission :
 *   · `guardians.user_id`  → les pupilles du tuteur ;
 *   · `students.user_id`   → l'élève lui-même, et lui seul.
 *
 * Les deux liens se réunissent dans `portal_students()`. Un même compte
 * peut porter les deux (un grand frère majeur, tuteur de sa cadette) :
 * il voit alors son dossier ET celui de sa pupille, sans qu'aucune règle
 * particulière ait été écrite pour ce cas.
 */

declare(strict_types=1);

/**
 * Les élèves que l'utilisateur courant peut voir DANS UN CADRE FAMILIAL.
 *
 * Réunion de deux liens : ses pupilles (tuteur) et lui-même (élève).
 * Chaque ligne porte `is_self` pour que l'écran sache s'il parle à un
 * parent ou à l'élève — les deux ne lisent pas la même chose.
 *
 * @return array<int,array<string,mixed>>
 */
function portal_students(): array
{
    $userId = auth_id();

    if ($userId === null) {
        return [];
    }

    $params = ['school_id' => tenant_require(), 'user_id' => $userId];

    // UNION plutôt qu'un OR sur une jointure : la branche « élève » ne
    // passe pas par student_guardians, et une jointure gauche produirait
    // des doublons dès qu'un élève a plusieurs tuteurs.
    return db_all(
        'SELECT s.id, s.matricule, s.last_name, s.post_name, s.first_name,
                s.gender, s.birth_date, s.status,
                MAX(sg.relationship) AS relationship,
                MAX(sg.is_primary)   AS is_primary,
                MAX(sg.is_payer)     AS is_payer,
                0 AS is_self
           FROM students s
           JOIN student_guardians sg ON sg.student_id = s.id AND sg.school_id = s.school_id
           JOIN guardians g ON g.id = sg.guardian_id AND g.school_id = sg.school_id
          WHERE s.school_id = :school_id
            AND g.user_id = :user_id
            AND g.deleted_at IS NULL
            AND s.deleted_at IS NULL
          GROUP BY s.id

          UNION

         SELECT s.id, s.matricule, s.last_name, s.post_name, s.first_name,
                s.gender, s.birth_date, s.status,
                NULL AS relationship, 0 AS is_primary, 0 AS is_payer,
                1 AS is_self
           FROM students s
          WHERE s.school_id = :self_school
            AND s.user_id = :self_user
            AND s.deleted_at IS NULL

          ORDER BY last_name, first_name',
        $params + ['self_school' => tenant_require(), 'self_user' => $userId]
    );
}

/**
 * Compatibilité : le portail parlait de « children » avant la 6B.
 *
 * Conservée parce qu'elle dit exactement ce qu'elle fait — les pupilles,
 * sans l'élève lui-même — et que l'écran d'accueil distingue les deux.
 */
function portal_children(): array
{
    return array_values(array_filter(
        portal_students(),
        static fn (array $row): bool => (int) $row['is_self'] === 0
    ));
}

/** Le dossier de l'utilisateur courant, s'il est lui-même élève. */
function portal_self_student(): ?array
{
    $userId = auth_id();

    if ($userId === null) {
        return null;
    }

    return db_one(
        'SELECT * FROM students
          WHERE school_id = :school_id AND user_id = :user_id AND deleted_at IS NULL',
        ['school_id' => tenant_require(), 'user_id' => $userId]
    );
}

/**
 * L'utilisateur courant peut-il ouvrir le dossier familial de CET élève ?
 *
 * Source unique du périmètre du portail. Vraie dans deux cas seulement :
 * il est tuteur déclaré de l'élève, ou il EST cet élève.
 *
 * Chaque contrôleur l'appelle avant d'afficher quoi que ce soit :
 * restreindre la liste ne protège rien si l'accès direct par identifiant
 * reste ouvert — leçon de l'audit de la phase 3.
 */
function portal_can_view_student(int $studentId): bool
{
    $userId = auth_id();

    if ($userId === null) {
        return false;
    }

    $schoolId = tenant_require();

    // L'élève lui-même.
    if (db_exists(
        'SELECT 1 FROM students
          WHERE school_id = :school_id AND id = :student_id
            AND user_id = :user_id AND deleted_at IS NULL
          LIMIT 1',
        ['school_id' => $schoolId, 'student_id' => $studentId, 'user_id' => $userId]
    )) {
        return true;
    }

    return portal_is_guardian_of($studentId);
}

/** L'utilisateur courant est-il TUTEUR de cet élève ? */
function portal_is_guardian_of(int $studentId): bool
{
    $userId = auth_id();

    if ($userId === null) {
        return false;
    }

    return db_exists(
        'SELECT 1
           FROM student_guardians sg
           JOIN guardians g ON g.id = sg.guardian_id AND g.school_id = sg.school_id
           JOIN students s ON s.id = sg.student_id AND s.school_id = sg.school_id
          WHERE sg.school_id = :school_id
            AND sg.student_id = :student_id
            AND g.user_id = :user_id
            AND g.deleted_at IS NULL
            AND s.deleted_at IS NULL
          LIMIT 1',
        [
            'school_id'  => tenant_require(),
            'student_id' => $studentId,
            'user_id'    => $userId,
        ]
    );
}

/** L'inscription en cours d'un élève — celle de l'année courante. */
function portal_current_enrollment(int $studentId): ?array
{
    return db_one(
        'SELECT e.*, c.name AS classroom_name, y.code AS year_code, y.name AS year_name
           FROM enrollments e
      LEFT JOIN classrooms c ON c.id = e.classroom_id AND c.school_id = e.school_id
           JOIN academic_years y ON y.id = e.academic_year_id AND y.school_id = e.school_id
          WHERE e.school_id = :school_id
            AND e.student_id = :student_id
            AND e.status <> \'cancelled\'
          ORDER BY y.is_current DESC, y.starts_on DESC
          LIMIT 1',
        ['school_id' => tenant_require(), 'student_id' => $studentId]
    );
}

/**
 * Plafond de lignes d'absence affichées dans le dossier.
 *
 * UN ÉCRAN QUI TRONQUE DOIT LE DIRE.
 * ----------------------------------
 * Le plafond était de 30, silencieusement. Un élève à 45 absences
 * affichait 45 au bandeau et 30 lignes en dessous : le parent comptait
 * les lignes et concluait que le décompte était faux. Le plafond existe
 * toujours — une page ne peut pas porter une année entière d'appels
 * horaires — mais il est désormais large, et l'écran annonce la
 * troncature quand elle se produit.
 */
const PORTAL_ABSENCE_LIMIT = 200;

/**
 * Les absences d'un élève sur une inscription.
 *
 * Seules les absences ENREGISTRÉES sont comptées. Une journée sans
 * appel n'est pas une journée sans absent : le portail n'affiche donc
 * pas de taux d'assiduité, qui serait faux tant que la couverture des
 * appels n'est pas garantie (leçon de la phase 4D).
 */
function portal_absences(int $enrollmentId, int $limit = PORTAL_ABSENCE_LIMIT): array
{
    return db_all(
        'SELECT r.id, r.status, r.is_justified, r.justification, r.minutes_late,
                se.session_date, se.slot, c.name AS classroom_name
           FROM attendance_records r
           JOIN attendance_sessions se ON se.id = r.session_id AND se.school_id = r.school_id
      LEFT JOIN classrooms c ON c.id = se.classroom_id AND c.school_id = se.school_id
          WHERE r.school_id = :school_id
            AND r.enrollment_id = :enrollment_id
            AND r.status <> \'present\'
          ORDER BY se.session_date DESC, se.slot
          LIMIT ' . max(1, min(PORTAL_ABSENCE_LIMIT, $limit)),
        ['school_id' => tenant_require(), 'enrollment_id' => $enrollmentId]
    );
}

/** Décompte des absences et retards d'une inscription. */
function portal_absence_counts(int $enrollmentId): array
{
    $row = db_one(
        'SELECT
            SUM(CASE WHEN r.status = \'absent\' THEN 1 ELSE 0 END) AS absences,
            SUM(CASE WHEN r.status = \'late\'   THEN 1 ELSE 0 END) AS retards,
            SUM(CASE WHEN r.status <> \'present\' AND r.is_justified = 1 THEN 1 ELSE 0 END) AS justifiees
           FROM attendance_records r
          WHERE r.school_id = :school_id AND r.enrollment_id = :enrollment_id',
        ['school_id' => tenant_require(), 'enrollment_id' => $enrollmentId]
    );

    return [
        'absences'   => (int) ($row['absences'] ?? 0),
        'retards'    => (int) ($row['retards'] ?? 0),
        'justifiees' => (int) ($row['justifiees'] ?? 0),
    ];
}

/**
 * L'utilisateur courant a-t-il un espace à ouvrir ?
 *
 * Vrai s'il est tuteur d'au moins un élève, ou s'il EST un élève.
 * Utilisée par le menu : une requête d'existence, jamais la liste
 * complète — la barre latérale s'affiche sur chaque page.
 */
function portal_has_children(): bool
{
    // LE CACHE EST CLÉ PAR UTILISATEUR ET PAR ÉCOLE.
    //
    // Un simple `static $cache` répondait la même chose à tout le monde
    // dès la première question : en test, le titulaire héritait de la
    // réponse du tuteur interrogé juste avant. C'est le défaut des
    // caches statiques déjà rencontré sur auth_user() et perm_all() —
    // à la différence près qu'un paramètre $refresh oblige chaque
    // appelant à y penser, alors qu'une clé est juste par construction.
    static $cache = [];

    $userId   = auth_id();
    $schoolId = tenant_id();

    if ($userId === null || $schoolId === null) {
        return false;
    }

    $key = $schoolId . ':' . $userId;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    return $cache[$key] = db_exists(
        'SELECT 1
           FROM student_guardians sg
           JOIN guardians g ON g.id = sg.guardian_id AND g.school_id = sg.school_id
          WHERE sg.school_id = :school_id AND g.user_id = :user_id AND g.deleted_at IS NULL
          LIMIT 1',
        ['school_id' => $schoolId, 'user_id' => $userId]
    ) || db_exists(
        'SELECT 1 FROM students
          WHERE school_id = :school_id AND user_id = :user_id AND deleted_at IS NULL
          LIMIT 1',
        ['school_id' => $schoolId, 'user_id' => $userId]
    );
}
