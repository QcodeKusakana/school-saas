<?php
/**
 * Module DOCUMENTS — lectures.
 *
 * `document_repo_context()` rassemble EN UNE REQUÊTE tout ce que le
 * figeage va recopier. Huit lectures séparées coûteraient huit
 * allers-retours à chaque délivrance, et surtout : entre la première et
 * la dernière, une valeur pourrait changer. Un document doit être la
 * photographie d'UN instant, pas d'une suite d'instants.
 *
 *   > Un instantané pris en huit fois n'est pas un instantané.
 *
 * Le solde fait exception : il se calcule par devise, à travers les
 * allocations, et le module Finances le fait déjà correctement. On
 * l'appelle plutôt que de le réécrire — un second calcul de solde
 * finirait par diverger du premier.
 */

declare(strict_types=1);

require_once APP_PATH . '/modules/finance/repositories.php';
require_once APP_PATH . '/modules/school/services.php';
require_once APP_PATH . '/modules/school/repositories.php';

/**
 * Tout ce qu'il faut savoir pour délivrer un document, en une lecture.
 *
 * @return array<string, mixed>|null
 */
function document_repo_context(int $enrollmentId): ?array
{
    $schoolId = tenant_require();
    $user     = auth_user();

    $ligne = db_one(
        'SELECT e.id              AS enrollment_id,
                e.status          AS enrollment_status,
                e.decision,
                e.classroom_id,
                st.id             AS student_id,
                st.matricule, st.last_name, st.post_name, st.first_name,
                st.gender, st.birth_date, st.birth_place, st.photo_path,
                st.deleted_at     AS student_deleted_at,
                y.id              AS year_id,
                y.code            AS year_code,
                y.ends_on         AS year_ends_on,
                c.name            AS classroom_name,
                lv.name           AS level_name,
                sc.name           AS school_name,
                sc.code           AS school_code
           FROM enrollments e
           JOIN students st       ON st.id = e.student_id AND st.school_id = e.school_id
           JOIN academic_years y  ON y.id = e.academic_year_id AND y.school_id = e.school_id
           JOIN schools sc        ON sc.id = e.school_id
           LEFT JOIN classrooms c ON c.id = e.classroom_id AND c.school_id = e.school_id
           LEFT JOIN curriculums cu    ON cu.id = c.curriculum_id AND cu.school_id = e.school_id
           LEFT JOIN education_levels lv ON lv.id = cu.education_level_id
          WHERE e.id = :id AND e.school_id = :school_id
          LIMIT 1',
        ['id' => $enrollmentId, 'school_id' => $schoolId],
        // `schools` et `education_levels` sont des tables globales : la
        // jointure est bornée par `e.school_id`, qui reste la liaison
        // exigée par le garde-fou.
        true
    );

    if ($ligne === null) {
        return null;
    }

    // L'adresse, la ville et la tutelle vivent dans les réglages, pas
    // dans `schools` : ils changent sans que l'établissement change.
    // Une seule source — `school_repo_print_settings()` — sert l'écran
    // de paramètres ET le figeage, pour que ce qu'on règle soit
    // exactement ce qui s'imprime.
    foreach (school_repo_print_settings() as $cle => $valeur) {
        $ligne['setting_' . str_replace('.', '_', $cle)] = $valeur;
    }

    $ligne['school_logo'] = school_repo_logo_path();

    // Le solde, calculé par le module qui en a la charge.
    $soldes  = finance_repo_balance($enrollmentId);
    $duTotal = 0.0;
    $paye    = 0.0;
    $reste   = 0.0;
    $devise  = '';

    foreach ($soldes as $code => $t) {
        $duTotal += (float) $t['due'];
        $paye    += (float) $t['paid'];
        $reste   += max(0.0, (float) $t['due'] - (float) $t['paid']);
        $devise   = $devise === '' ? $code : $devise;
    }

    $ligne['total_due']  = $duTotal;
    $ligne['total_paid'] = $paye;
    $ligne['balance']    = $reste;
    $ligne['currency']   = $devise !== '' ? $devise : 'USD';

    $ligne['issuer_last']  = (string) ($user['last_name'] ?? '');
    $ligne['issuer_first'] = (string) ($user['first_name'] ?? '');

    return $ligne;
}

/**
 * Un document de l'établissement, avec son figeage décodé.
 *
 * @return array<string, mixed>|null
 */
function document_repo_find(int $id): ?array
{
    $ligne = db_one(
        'SELECT d.*, u.last_name AS issuer_last, u.first_name AS issuer_first,
                r.last_name AS revoker_last, r.first_name AS revoker_first
           FROM documents d
           LEFT JOIN users u ON u.id = d.issued_by AND u.school_id = d.school_id
           LEFT JOIN users r ON r.id = d.revoked_by AND r.school_id = d.school_id
          WHERE d.id = :id AND d.school_id = :school_id
          LIMIT 1',
        ['id' => $id, 'school_id' => tenant_require()],
        true
    );

    if ($ligne === null) {
        return null;
    }

    $ligne['data'] = (array) json_decode((string) $ligne['snapshot'], true);

    return $ligne;
}

/**
 * Les documents délivrés, les plus récents d'abord.
 *
 * @param array<string, mixed> $filtres
 * @return array{rows: array<int, array<string, mixed>>, total: int}
 */
function document_repo_search(array $filtres = [], int $page = 1, int $parPage = 25): array
{
    $where  = ['d.school_id = :school_id'];
    $params = ['school_id' => tenant_require()];

    if (($filtres['type'] ?? '') !== '' && isset(DOCUMENT_TYPES[$filtres['type']])) {
        $where[]        = 'd.type = :type';
        $params['type'] = (string) $filtres['type'];
    }

    if (($filtres['student'] ?? 0) > 0) {
        $where[]           = 'd.student_id = :student';
        $params['student'] = (int) $filtres['student'];
    }

    // Par défaut on montre TOUT, révoqués compris : masquer un document
    // révoqué rendrait invisible ce que l'école a pourtant délivré, et
    // le guichet ne saurait pas quoi répondre à qui se présente avec.
    if (($filtres['statut'] ?? '') === 'valides') {
        $where[] = 'd.revoked_at IS NULL';
    } elseif (($filtres['statut'] ?? '') === 'revoques') {
        $where[] = 'd.revoked_at IS NOT NULL';
    }

    if (($filtres['q'] ?? '') !== '') {
        $where[]     = '(d.number LIKE :q OR st.matricule LIKE :q OR st.last_name LIKE :q)';
        $params['q'] = '%' . trim((string) $filtres['q']) . '%';
    }

    $clause = implode(' AND ', $where);

    $total = (int) db_value(
        'SELECT COUNT(*) FROM documents d
           JOIN students st ON st.id = d.student_id AND st.school_id = d.school_id
          WHERE ' . $clause,
        $params,
        true
    );

    $page    = max(1, $page);
    $offset  = ($page - 1) * $parPage;

    $rows = db_all(
        'SELECT d.id, d.number, d.type, d.token, d.issued_at, d.revoked_at, d.revoke_reason,
                st.matricule, st.last_name, st.post_name, st.first_name,
                u.last_name AS issuer_last, u.first_name AS issuer_first
           FROM documents d
           JOIN students st ON st.id = d.student_id AND st.school_id = d.school_id
           LEFT JOIN users u ON u.id = d.issued_by AND u.school_id = d.school_id
          WHERE ' . $clause . '
          ORDER BY d.issued_at DESC, d.id DESC
          LIMIT ' . $parPage . ' OFFSET ' . $offset,
        $params,
        true
    );

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $parPage];
}

/**
 * Le décompte par nature — pour l'en-tête de l'écran.
 *
 * @return array<string, int>
 */
function document_repo_counts(): array
{
    $rows = db_all(
        'SELECT type, COUNT(*) AS n,
                SUM(revoked_at IS NOT NULL) AS revoques
           FROM documents WHERE school_id = :school_id GROUP BY type',
        ['school_id' => tenant_require()],
        true
    );

    $out = ['total' => 0, 'revoques' => 0];

    foreach (array_keys(DOCUMENT_TYPES) as $type) {
        $out[$type] = 0;
    }

    foreach ($rows as $r) {
        $out[(string) $r['type']] = (int) $r['n'];
        $out['total']            += (int) $r['n'];
        $out['revoques']         += (int) $r['revoques'];
    }

    return $out;
}

/**
 * Les documents déjà délivrés pour une inscription.
 *
 * Sert à prévenir le secrétariat : « une attestation a déjà été
 * délivrée le 3 septembre ». On ne l'interdit pas — un parent perd un
 * papier, et l'école doit pouvoir le refaire — mais on le signale.
 *
 * @return array<int, array<string, mixed>>
 */
function document_repo_for_enrollment(int $enrollmentId): array
{
    return db_all(
        'SELECT id, number, type, issued_at, revoked_at
           FROM documents
          WHERE school_id = :school_id AND enrollment_id = :e
          ORDER BY issued_at DESC',
        ['school_id' => tenant_require(), 'e' => $enrollmentId],
        true
    );
}
