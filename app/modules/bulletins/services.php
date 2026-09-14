<?php
/**
 * Module BULLETINS — calcul et publication.
 *
 * Le bulletin est le document qui décide du passage de classe. Trois
 * principes le gouvernent.
 *
 *  1. LE MAXIMUM VIENT DE LA COTE, JAMAIS DU PROGRAMME
 *     grades.max_points est figé à la saisie. Le bulletin l'additionne
 *     tel quel. Recalculer depuis curriculum_subjects rendrait un
 *     bulletin déjà remis non reproductible.
 *
 *  2. LE RANG SE FIGE À LA PUBLICATION
 *     Le rang dépend de toute la classe. Tant que le bulletin n'est pas
 *     publié, il est recalculé à chaque affichage — c'est l'état de
 *     travail. Une fois publié, il ne bouge plus.
 *
 *  3. UNE ABSENCE N'EST PAS UN ZÉRO, ET LE TRAITEMENT EST UN CHOIX
 *     Exclure du total ou compter zéro : les deux se défendent, aucun
 *     n'est universel en RDC. C'est un paramètre d'établissement.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/../grades/repositories.php';
require_once __DIR__ . '/../students/repositories.php';

/**
 * Regroupements du bulletin, dans l'ordre d'affichage.
 *
 * Les clés des semestres et de l'année ne sont pas des périodes : ce
 * sont des totaux, calculés à partir des périodes qu'elles listent.
 */
/**
 * Regroupements par défaut — deux semestres.
 *
 * Conservé comme repli pour les jeux de périodes hérités, antérieurs à
 * la migration 011, et comme liste blanche minimale. Les regroupements
 * réels se DÉDUISENT des périodes : voir bulletins_groups().
 */
const BULLETIN_GROUPS = [
    'S1'     => ['label' => 'Premier semestre', 'periods' => ['P1', 'P2', 'EX1']],
    'S2'     => ['label' => 'Second semestre',  'periods' => ['P3', 'P4', 'EX2']],
    'ANNUAL' => ['label' => 'Total général',    'periods' => ['P1', 'P2', 'EX1', 'P3', 'P4', 'EX2']],
];

/** Libellés des regroupements, par structure et par rang. */
const BULLETIN_GROUP_LABELS = [
    'semestre'  => ['Premier semestre', 'Second semestre'],
    'trimestre' => ['Premier trimestre', 'Deuxième trimestre', 'Troisième trimestre'],
];

/**
 * Regroupements DÉDUITS des périodes réelles de la classe.
 *
 * Le primaire est en trois trimestres, le CTEB et les humanités en deux
 * semestres. Écrire « S1, S2, ANNUAL » en dur excluait purement et
 * simplement le troisième trimestre du primaire : les cotes de P5, P6 et
 * EX3 n'entraient dans aucun total.
 *
 * La structure n'est pas devinée par un calcul : elle vient de la
 * colonne `semester` des périodes, qui porte le numéro du regroupement.
 * Deux regroupements donnent S1/S2, trois donnent T1/T2/T3.
 *
 * @param array $periods Périodes indexées par code, avec « semester ».
 * @return array<string, array{label: string, periods: array<string>}>
 */
function bulletins_groups(array $periods): array
{
    if ($periods === []) {
        return BULLETIN_GROUPS;
    }

    $byGroup = [];

    foreach ($periods as $code => $period) {
        $byGroup[(int) $period['semester']][] = (string) $code;
    }

    ksort($byGroup);

    $structure = count($byGroup) === 3 ? 'trimestre' : 'semestre';
    $prefix    = $structure === 'trimestre' ? 'T' : 'S';
    $labels    = BULLETIN_GROUP_LABELS[$structure];

    $groups = [];
    $all    = [];
    $rank   = 0;

    foreach ($byGroup as $number => $codes) {
        $groups[$prefix . $number] = [
            'label'   => $labels[$rank] ?? ($prefix . $number),
            'periods' => $codes,
        ];

        $all = array_merge($all, $codes);
        $rank++;
    }

    $groups['ANNUAL'] = ['label' => 'Total général', 'periods' => $all];

    return $groups;
}

/**
 * Regroupements applicables à une classe, d'après son cycle.
 *
 * Utilisé par les chemins qui n'ont pas encore calculé de bulletin :
 * publication, classement, liste blanche des contrôleurs.
 */
function bulletins_classroom_groups(int $classroomId): array
{
    $rows = db_all(
        'SELECT gp.code, gp.semester, gp.order_number
           FROM classrooms c
           JOIN curriculums cu ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           JOIN education_levels l ON l.id = cu.education_level_id
           JOIN grade_periods gp ON gp.academic_year_id = c.academic_year_id
                                AND gp.school_id = c.school_id
                                AND (
                                     gp.cycle_id = l.cycle_id
                                     OR (gp.cycle_id IS NULL AND NOT EXISTS (
                                           SELECT 1 FROM grade_periods gpc
                                            WHERE gpc.school_id = gp.school_id
                                              AND gpc.academic_year_id = gp.academic_year_id
                                              AND gpc.cycle_id = l.cycle_id))
                                )
          WHERE c.school_id = :school_id AND c.id = :classroom_id
          ORDER BY gp.order_number',
        ['school_id' => tenant_require(), 'classroom_id' => $classroomId]
    );

    $periods = [];

    foreach ($rows as $row) {
        $periods[(string) $row['code']] = ['semester' => (int) $row['semester']];
    }

    return bulletins_groups($periods);
}

/**
 * Mises en page disponibles.
 *
 *   domaines — branches rangées sous les cinq en-têtes officiels, chacun
 *              portant une ligne SOUS-TOTAL. Primaire, CTEB, humanités
 *              générales et scientifiques.
 *   maxima   — aucun domaine : les branches sont regroupées par maximum,
 *              chaque bloc ouvert par une ligne MAXIMA. Humanités
 *              techniques.
 */
const BULLETIN_MODELS = [
    'domaines' => 'Par domaines d\'apprentissage',
    'maxima'   => 'Par blocs de maxima',
];

const BULLETIN_MODEL_DEFAULT = 'domaines';

/**
 * Sections dont les bulletins officiels se passent de domaines.
 *
 * Constaté sur trois modèles : construction et mécanique générale
 * (industrielle), secrétariat et administration (commerciale). Les
 * humanités scientifiques, elles, ont bien des domaines — ce n'est donc
 * pas « humanités » qui décide, mais le caractère technique.
 */
const BULLETIN_TECHNICAL_SECTIONS = ['COMMERCIALE', 'INDUSTRIELLE', 'SOCIALE', 'AGRICOLE'];

/**
 * Modèle de bulletin applicable à une classe.
 *
 * Le programme peut l'imposer ; à défaut il se déduit de la section.
 * La déduction reste le comportement normal : la colonne n'existe que
 * pour pouvoir la contredire, sans livraison logicielle.
 */
function bulletins_model_for_classroom(int $classroomId): string
{
    $row = db_one(
        'SELECT cu.bulletin_model, sec.code AS section_code
           FROM classrooms c
           JOIN curriculums cu ON cu.id = c.curriculum_id AND cu.school_id = c.school_id
           LEFT JOIN sections sec ON sec.id = cu.section_id AND sec.school_id = cu.school_id
          WHERE c.school_id = :school_id AND c.id = :classroom_id',
        ['school_id' => tenant_require(), 'classroom_id' => $classroomId]
    );

    if ($row === null) {
        return BULLETIN_MODEL_DEFAULT;
    }

    $explicit = $row['bulletin_model'];

    if ($explicit !== null && isset(BULLETIN_MODELS[$explicit])) {
        return (string) $explicit;
    }

    return in_array((string) $row['section_code'], BULLETIN_TECHNICAL_SECTIONS, true)
        ? 'maxima'
        : BULLETIN_MODEL_DEFAULT;
}

/** Traitement des absences, par défaut. */
const BULLETIN_ABSENCE_DEFAULT = 'excluded';

const BULLETIN_DECISIONS = [
    'passed'      => 'Réussite',
    'failed'      => 'Échec',
    'conditional' => 'Passage conditionnel',
    'excluded'    => 'Exclusion',
    'pending'     => 'En délibération',
];

/**
 * Mode de traitement des absences retenu par l'établissement.
 *
 * 'excluded' — la période absente sort du total ET du maximum ;
 * 'zero'     — l'absence vaut zéro, maximum plein.
 */
function bulletins_absence_mode(): string
{
    $mode = (string) school_setting('grading.absence_mode', BULLETIN_ABSENCE_DEFAULT);

    return in_array($mode, ['excluded', 'zero'], true) ? $mode : BULLETIN_ABSENCE_DEFAULT;
}

// =====================================================================
//  CALCUL
// =====================================================================

/**
 * Calcule le bulletin d'une inscription.
 *
 * Renvoie une structure prête à afficher : une ligne par branche, avec
 * la cote de chaque période, les totaux par semestre et le total
 * général ; puis les totaux de la classe entière.
 *
 * Aucune écriture. Cette fonction est appelée aussi bien pour l'état de
 * travail que pour la publication : le même calcul dans les deux cas,
 * sans quoi le document publié pourrait différer de l'aperçu.
 *
 * @return array{
 *   subjects: array, periods: array, totals: array,
 *   missing: int, absent: int, mode: string
 * }
 */
function bulletins_service_compute(int $enrollmentId): array
{
    $rows = grades_repo_report($enrollmentId);

    if ($rows === []) {
        // MÊME FORME QU'UN RELEVÉ NORMAL, MÊME VIDE.
        //
        // Ce retour omettait « blocks », « domains » et « groups » : tout
        // appelant les lisant sur une classe sans programme tombait sur
        // une clé absente. Une structure de retour à géométrie variable
        // est un piège qui n'attend que le premier cas limite.
        return [
            'subjects' => [], 'periods' => [], 'totals'  => [],
            'blocks'   => [], 'domains' => [], 'groups'  => BULLETIN_GROUPS,
            'missing'  => 0,  'absent'  => 0,  'mode'    => bulletins_absence_mode(),
        ];
    }

    $mode     = bulletins_absence_mode();
    $subjects = [];
    $periods  = [];
    $missing  = 0;
    $absent   = 0;

    foreach ($rows as $row) {
        $subjectId = (int) $row['curriculum_subject_id'];
        $code      = (string) $row['period_code'];

        $periods[$code] = [
            'code'       => $code,
            'name'       => $row['period_name'],
            'type'       => $row['period_type'],
            'semester'   => (int) $row['semester'],
            'order'      => (int) $row['period_order'],
            'multiplier' => (float) $row['max_multiplier'],
        ];

        if (!isset($subjects[$subjectId])) {
            $subjects[$subjectId] = [
                'id'       => $subjectId,
                'name'     => $row['subject_name'],
                'short'    => $row['subject_short'],
                'order'    => (int) $row['order_number'],
                'ranking'  => (int) $row['counts_for_ranking'] === 1,
                // Domaine EFFECTIF, celui du programme quand il déroge.
                'domain'       => $row['domain_code'] !== null ? (string) $row['domain_code'] : null,
                'domain_name'  => $row['domain_name'] !== null ? (string) $row['domain_name'] : null,
                'domain_order' => (int) ($row['domain_order'] ?? 99),
                'subdomain'       => $row['subdomain_code'] !== null ? (string) $row['subdomain_code'] : null,
                'subdomain_name'  => $row['subdomain_short'] ?? $row['subdomain_name'] ?? null,
                'subdomain_order' => (int) ($row['subdomain_order'] ?? 99),
                // Maximum UNITAIRE du programme : celui d'une période
                // simple, avant multiplicateur. C'est lui qui regroupe les
                // branches en blocs sur le bulletin officiel.
                'max_unit' => (float) $row['program_max'],
                'cells'    => [],
                'groups'   => [],
            ];
        }

        // Le maximum vient de la cote figée ; grades_repo_report()
        // retombe sur le programme courant uniquement quand la cote
        // n'existe pas encore.
        $max      = (float) $row['max_points'];
        $isAbsent = (int) $row['is_absent'] === 1;
        $points   = $row['points'] !== null ? (float) $row['points'] : null;

        if ($isAbsent) {
            $absent++;
        } elseif ($points === null) {
            $missing++;
        }

        $subjects[$subjectId]['cells'][$code] = [
            'points'    => $points,
            'max'       => $max,
            'is_absent' => $isAbsent,
            'counted'   => bulletins_cell_counts($points, $isAbsent, $mode),
        ];
    }

    uasort($periods, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
    uasort($subjects, static fn (array $a, array $b): int => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);

    // --- Totaux par branche et par regroupement -----------------------
    //
    // Les regroupements sont DÉDUITS des périodes de cette classe : deux
    // semestres au CTEB et aux humanités, trois trimestres au primaire.
    $groups = bulletins_groups($periods);
    $totals = [];

    foreach ($groups as $key => $group) {
        $totals[$key] = [
            'points' => 0.0, 'max' => 0.0,
            'ranking_points' => 0.0, 'ranking_max' => 0.0,
            'missing' => 0, 'absent' => 0,
        ];
    }

    foreach ($subjects as $subjectId => $subject) {
        foreach ($groups as $key => $group) {
            $sum = 0.0;
            $max = 0.0;

            foreach ($group['periods'] as $code) {
                $cell = $subject['cells'][$code] ?? null;

                // LE MANQUE SE COMPTE PAR REGROUPEMENT, PAS SUR L'ANNÉE.
                //
                // Un décompte global affichait sur un bulletin de premier
                // semestre les cotes de P3, P4 et EX2 — des périodes qui
                // n'ont pas encore eu lieu. Chaque élève portait donc dès
                // septembre un avertissement « 180 cotes manquantes », ce
                // qui rendait l'alerte inutilisable : elle était vraie pour
                // tout le monde, tout le temps, et donc ignorée.
                if ($cell !== null) {
                    if ($cell['is_absent']) {
                        $totals[$key]['absent']++;
                    } elseif ($cell['points'] === null) {
                        $totals[$key]['missing']++;
                    }
                }

                if ($cell === null || !$cell['counted']) {
                    continue;
                }

                $sum += $cell['points'] ?? 0.0;
                $max += $cell['max'];
            }

            // DEUX MAXIMA, ET ILS NE SE CONFONDENT PAS.
            //
            //   max   — le maximum RETENU : seules les cellules qui
            //           entrent au total. C'est le dénominateur du
            //           pourcentage, et lui seul.
            //   scale — le BARÈME : ce que la branche vaut, cotée ou non.
            //           C'est ce que le formulaire officiel imprime.
            //
            // Les confondre faisait disparaître le barème des branches
            // non encore corrigées : la colonne du regroupement restait
            // vide, comme si la branche n'existait pas au programme.
            $scale = 0.0;

            foreach ($group['periods'] as $code) {
                $cell = $subject['cells'][$code] ?? null;

                if ($cell !== null) {
                    $scale += $cell['max'];
                }
            }

            $subjects[$subjectId]['groups'][$key] = [
                'points'     => $sum,
                'max'        => $max,
                'scale'      => $scale,
                'percentage' => $max > 0 ? round($sum / $max * 100, 2) : null,
            ];

            $totals[$key]['points'] += $sum;
            $totals[$key]['max']    += $max;

            // Le classement ignore les branches marquées
            // counts_for_ranking = 0 — conduite, religion par
            // convention. Elles figurent au bulletin mais ne pèsent pas
            // sur le rang.
            if ($subject['ranking']) {
                $totals[$key]['ranking_points'] += $sum;
                $totals[$key]['ranking_max']    += $max;
            }
        }
    }

    foreach ($totals as $key => $total) {
        $totals[$key]['percentage'] = $total['max'] > 0
            ? round($total['points'] / $total['max'] * 100, 2)
            : null;

        // Repli documenté : si AUCUNE branche notée ne compte pour le
        // classement — programme entièrement marqué counts_for_ranking = 0,
        // ou seules des branches exclues ont été corrigées — le classement
        // porte sur le total. Sans ce repli, toute la classe serait « non
        // classée » sans qu'aucun écran ne l'explique.
        $rankingMax    = $total['ranking_max'] > 0 ? $total['ranking_max'] : $total['max'];
        $rankingPoints = $total['ranking_max'] > 0 ? $total['ranking_points'] : $total['points'];

        $totals[$key]['ranking_percentage'] = $rankingMax > 0
            ? round($rankingPoints / $rankingMax * 100, 2)
            : null;

        $totals[$key]['label'] = $groups[$key]['label'];
    }

    return [
        'subjects' => $subjects,
        'periods'  => $periods,
        'totals'   => $totals,
        'blocks'   => bulletins_maxima_blocks($subjects, $periods, $groups),
        'domains'  => bulletins_domain_totals($subjects, $periods, $groups),
        'groups'   => $groups,
        'missing'  => $missing,
        'absent'   => $absent,
        'mode'     => $mode,
    ];
}

/**
 * Regroupe les branches en BLOCS DE MAXIMA, comme le bulletin officiel.
 *
 * Le document du ministère n'aligne pas les branches à la suite : il les
 * regroupe par maximum et ouvre chaque bloc par une ligne « MAXIMA » qui
 * donne, pour ce bloc, le maximum d'une période, celui de l'examen, celui
 * du semestre et celui de l'année. Ainsi, sur un bulletin de 1ère
 * construction :
 *
 *     MAXIMA            10  10  20  40 …      Religion, ECM, Éducation à la vie
 *     MAXIMA            20  20  40  80 …      Anglais, Chimie, Histoire, …
 *     MAXIMA            50  50 100 200 …      Français, Mathématiques, Dessin
 *     MAXIMA           100 100   —  200 …     Pratique professionnelle
 *
 * Les blocs sont classés par maximum croissant — c'est l'ordre constaté
 * sur les six modèles officiels fournis.
 *
 * Le maximum d'une cellule du bloc vaut : maximum unitaire × multiplicateur
 * de la période. C'est exactement la règle EPST, où le maximum EST la
 * pondération.
 *
 * @return array<string, array{unit: float, periods: array<string,float>,
 *                             groups: array<string,float>, subjects: array<int>}>
 */
function bulletins_maxima_blocks(array $subjects, array $periods, ?array $groups = null): array
{
    $groups ??= bulletins_groups($periods);

    $blocks = [];

    foreach ($subjects as $subjectId => $subject) {
        $unit = (float) $subject['max_unit'];

        if ($unit <= 0) {
            continue;
        }

        // Clé textuelle : un maximum de 7,5 et un de 7 ne doivent pas se
        // confondre dans un index de tableau.
        $key = number_format($unit, 2, '.', '');

        if (!isset($blocks[$key])) {
            $periodMaxima = [];

            foreach ($periods as $code => $period) {
                $periodMaxima[$code] = $unit * (float) $period['multiplier'];
            }

            $groupMaxima = [];

            foreach ($groups as $groupKey => $group) {
                $sum = 0.0;

                foreach ($group['periods'] as $code) {
                    $sum += $periodMaxima[$code] ?? 0.0;
                }

                $groupMaxima[$groupKey] = $sum;
            }

            $blocks[$key] = [
                'unit'     => $unit,
                'periods'  => $periodMaxima,
                'groups'   => $groupMaxima,
                'subjects' => [],
            ];
        }

        $blocks[$key]['subjects'][] = $subjectId;
    }

    uasort($blocks, static fn (array $a, array $b): int => $a['unit'] <=> $b['unit']);

    return $blocks;
}

/**
 * Regroupement par DOMAINE D'APPRENTISSAGE, avec ses sous-totaux.
 *
 * C'est l'ossature des bulletins du primaire, du CTEB et des humanités
 * générales : les branches y sont rangées sous cinq en-têtes officiels —
 * langues, mathématiques-sciences-technologie, univers social et
 * environnement, arts, développement personnel — et chaque domaine porte
 * une ligne SOUS-TOTAL.
 *
 * Le sous-total suit exactement la règle du total général : seules les
 * cellules RETENUES y entrent, de sorte qu'une branche non encore cotée
 * ne pèse pas comme un échec. Vérifié sur le bulletin rempli de 3e
 * humanités scientifiques, où le sous-total du premier bloc vaut 10 et
 * non 30 — deux de ses trois branches n'étant pas dispensées.
 *
 * Les branches sans domaine sont rassemblées sous la clé « AUTRES » :
 * les taire ferait disparaître des cotes du document.
 *
 * @return array<string, array{name: string, order: int, subjects: array<int>,
 *                             periods: array<string, array{points: float, max: float}>,
 *                             groups: array<string, array{points: float, max: float, percentage: float|null}>}>
 */
function bulletins_domain_totals(array $subjects, array $periods, array $groups): array
{
    $domains = [];

    foreach ($subjects as $subjectId => $subject) {
        $key = $subject['domain'] ?? 'AUTRES';

        if (!isset($domains[$key])) {
            $domains[$key] = [
                'name'     => $subject['domain_name'] ?? 'Autres branches',
                'order'    => $subject['domain_order'] ?? 99,
                'subjects'   => [],
                'subdomains' => [],
                'periods'    => [],
                'groups'     => [],
            ];

            foreach (array_keys($periods) as $code) {
                $domains[$key]['periods'][$code] = ['points' => 0.0, 'max' => 0.0, 'scale' => 0.0];
            }

            foreach (array_keys($groups) as $groupKey) {
                $domains[$key]['groups'][$groupKey] = ['points' => 0.0, 'max' => 0.0, 'scale' => 0.0];
            }
        }

        $domains[$key]['subjects'][] = $subjectId;

        // STRATE INTERMÉDIAIRE, QUAND LE DOCUMENT L'IMPRIME.
        //
        // Les bulletins du CTEB et des humanités scientifiques rangent
        // les sciences sous trois sous-domaines, chacun portant son
        // propre sous-total. Une branche sans sous-domaine reste
        // directement sous son domaine : le gabarit affiche les deux.
        $subKey = $subject['subdomain'] ?? null;

        if ($subKey !== null) {
            if (!isset($domains[$key]['subdomains'][$subKey])) {
                $domains[$key]['subdomains'][$subKey] = [
                    'name'     => $subject['subdomain_name'] ?? $subKey,
                    'order'    => $subject['subdomain_order'] ?? 99,
                    'subjects' => [],
                    'periods'  => [],
                    'groups'   => [],
                ];

                foreach (array_keys($periods) as $code) {
                    $domains[$key]['subdomains'][$subKey]['periods'][$code] =
                        ['points' => 0.0, 'max' => 0.0, 'scale' => 0.0];
                }

                foreach (array_keys($groups) as $groupKey) {
                    $domains[$key]['subdomains'][$subKey]['groups'][$groupKey] =
                        ['points' => 0.0, 'max' => 0.0, 'scale' => 0.0];
                }
            }

            $domains[$key]['subdomains'][$subKey]['subjects'][] = $subjectId;
        }

        foreach ($periods as $code => $period) {
            $cell = $subject['cells'][$code] ?? null;

            if ($cell === null) {
                continue;
            }

            // Le barème s'additionne toujours ; le maximum retenu ne
            // s'additionne que pour les cellules qui comptent.
            $domains[$key]['periods'][$code]['scale'] += $cell['max'];

            if ($subKey !== null) {
                $domains[$key]['subdomains'][$subKey]['periods'][$code]['scale'] += $cell['max'];
            }

            if (!$cell['counted']) {
                continue;
            }

            $domains[$key]['periods'][$code]['points'] += $cell['points'] ?? 0.0;
            $domains[$key]['periods'][$code]['max']    += $cell['max'];

            if ($subKey !== null) {
                $domains[$key]['subdomains'][$subKey]['periods'][$code]['points'] += $cell['points'] ?? 0.0;
                $domains[$key]['subdomains'][$subKey]['periods'][$code]['max']    += $cell['max'];
            }
        }

        foreach ($groups as $groupKey => $group) {
            $totals = $subject['groups'][$groupKey] ?? null;

            if ($totals === null) {
                continue;
            }

            $domains[$key]['groups'][$groupKey]['points'] += $totals['points'];
            $domains[$key]['groups'][$groupKey]['max']    += $totals['max'];
            $domains[$key]['groups'][$groupKey]['scale']  += $totals['scale'] ?? 0.0;

            if ($subKey !== null) {
                $domains[$key]['subdomains'][$subKey]['groups'][$groupKey]['points'] += $totals['points'];
                $domains[$key]['subdomains'][$subKey]['groups'][$groupKey]['max']    += $totals['max'];
                $domains[$key]['subdomains'][$subKey]['groups'][$groupKey]['scale']  += $totals['scale'] ?? 0.0;
            }
        }
    }

    foreach ($domains as $key => $domain) {
        foreach ($domain['groups'] as $groupKey => $totals) {
            $domains[$key]['groups'][$groupKey]['percentage'] = $totals['max'] > 0
                ? round($totals['points'] / $totals['max'] * 100, 2)
                : null;
        }

        uasort(
            $domains[$key]['subdomains'],
            static fn (array $a, array $b): int => $a['order'] <=> $b['order']
        );
    }

    uasort($domains, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

    return $domains;
}

/**
 * MAXIMA GÉNÉRAUX : le maximum théorique du PROGRAMME.
 *
 * À ne pas confondre avec le dénominateur du pourcentage.
 *
 * Sur le document officiel, les deux coïncident parce qu'en fin d'année
 * tout est coté. En cours d'année, le dénominateur du pourcentage ne
 * retient que les cotes réellement disponibles — sans quoi une branche non
 * encore corrigée pèserait comme un échec. La ligne MAXIMA GÉNÉRAUX, elle,
 * annonce ce que vaut le programme complet : c'est une donnée de
 * référence, pas un calcul sur l'élève.
 *
 * @return array<string, float> Indexé par regroupement (S1, S2, ANNUAL).
 */
function bulletins_general_maxima(array $blocks): array
{
    $totals = [];

    // Les clés viennent des blocs eux-mêmes : ils portent déjà les
    // regroupements de la classe, semestres ou trimestres.
    foreach ($blocks as $block) {
        foreach (array_keys($block['groups']) as $groupKey) {
            $totals[$groupKey] = 0.0;
        }
    }

    foreach ($blocks as $block) {
        $count = count($block['subjects']);

        foreach ($block['groups'] as $groupKey => $max) {
            $totals[$groupKey] += $max * $count;
        }
    }

    return $totals;
}

/**
 * Une cellule entre-t-elle dans le total ?
 *
 *  · cote saisie            → oui ;
 *  · absence, mode 'zero'   → oui, comptée zéro sur maximum plein ;
 *  · absence, mode exclusion→ non, ni au total ni au maximum ;
 *  · cote non saisie        → non. Une branche non encore corrigée ne
 *    doit pas peser comme un échec : le bulletin signale le manque
 *    séparément.
 */
function bulletins_cell_counts(?float $points, bool $isAbsent, string $mode): bool
{
    if ($isAbsent) {
        return $mode === 'zero';
    }

    return $points !== null;
}

// =====================================================================
//  CLASSEMENT
// =====================================================================

/**
 * Calcule le classement d'une classe pour un regroupement donné.
 *
 * Le rang s'appuie sur le pourcentage des branches comptant pour le
 * classement. Deux élèves à égalité reçoivent le MÊME rang, et le rang
 * suivant est décalé d'autant — c'est la règle d'usage des bulletins
 * scolaires (1, 2, 2, 4), et non un rang dense.
 *
 * @return array<int, array{rank: int|null, percentage: float|null, points: float, max: float}>
 *         Indexé par enrollment_id.
 */
function bulletins_service_rank_classroom(int $classroomId, string $periodKey): array
{
    // La liste blanche vient du CYCLE de la classe : « T3 » est valide
    // au primaire et n'existe pas aux humanités.
    if (!isset(bulletins_classroom_groups($classroomId)[$periodKey])) {
        return [];
    }

    $results = [];

    foreach (grades_repo_classroom_enrollments($classroomId) as $enrollment) {
        $enrollmentId = (int) $enrollment['id'];
        $computed     = bulletins_service_compute($enrollmentId);
        $total        = $computed['totals'][$periodKey] ?? null;

        // Deux pourcentages, deux usages :
        //  · 'percentage'         — celui du document, toutes branches ;
        //  · 'ranking_percentage' — celui qui produit le rang.
        // Les confondre reviendrait à imprimer un chiffre et à en
        // archiver un autre.
        $results[$enrollmentId] = [
            'rank'               => null,
            'percentage'         => $total['percentage'] ?? null,
            'ranking_percentage' => $total['ranking_percentage'] ?? null,
            'points'             => $total['points'] ?? 0.0,
            'max'                => $total['max'] ?? 0.0,
            // Le manque et les absences RETENUS sont ceux du regroupement
            // publié : un bulletin de premier semestre ne signale pas les
            // cotes du second, qui n'existent pas encore.
            'missing'            => $total['missing'] ?? 0,
            'absent'             => $total['absent'] ?? 0,
            'student'            => $enrollment,
        ];
    }

    // Les élèves sans aucune cote ne sont pas classés : leur attribuer
    // le dernier rang les présenterait comme les plus faibles alors que
    // rien n'a été corrigé pour eux.
    $ranked = array_filter(
        $results,
        static fn (array $r): bool => $r['ranking_percentage'] !== null
    );

    uasort(
        $ranked,
        static fn (array $a, array $b): int => $b['ranking_percentage'] <=> $a['ranking_percentage']
    );

    $position = 0;
    $rank     = 0;
    $previous = null;

    foreach ($ranked as $enrollmentId => $row) {
        $position++;

        if ($previous === null || abs($row['ranking_percentage'] - $previous) > 0.001) {
            $rank     = $position;
            $previous = $row['ranking_percentage'];
        }

        $results[$enrollmentId]['rank'] = $rank;
    }

    return $results;
}

// =====================================================================
//  PUBLICATION
// =====================================================================

/**
 * Publie les bulletins d'une classe pour un regroupement.
 *
 * La publication FIGE le résultat : total, maximum, pourcentage, rang,
 * effectif. À partir de là, ajouter une cote ou inscrire un élève ne
 * modifie plus le document remis aux parents.
 *
 * @return array{ok: bool, published: int, message: string, warnings: array}
 */
function bulletins_service_publish(int $classroomId, string $periodKey): array
{
    if (!perm_has('bulletin.publish')) {
        return ['ok' => false, 'published' => 0, 'message' => 'Vous n\'avez pas le droit de publier les bulletins.', 'warnings' => []];
    }

    $groups = bulletins_classroom_groups($classroomId);

    if (!isset($groups[$periodKey])) {
        return ['ok' => false, 'published' => 0, 'message' => 'Regroupement inconnu.', 'warnings' => []];
    }

    $classroom = tenant_find('classrooms', $classroomId);

    if ($classroom === null) {
        return ['ok' => false, 'published' => 0, 'message' => 'Classe introuvable.', 'warnings' => []];
    }

    // La permission dit ce qu'on a le droit de FAIRE, jamais SUR QUI.
    //
    // L'écran de classe porte déjà le périmètre ; l'action de publication
    // doit le porter aussi. Sans cette ligne, une école qui accorde
    // bulletin.publish à ses titulaires — configuration parfaitement
    // plausible, les rôles étant modifiables — laissait chacun figer les
    // rangs de TOUTES les classes de l'établissement, y compris celles
    // qu'il n'enseigne pas.
    if (!students_can_view_classroom($classroomId)) {
        return ['ok' => false, 'published' => 0, 'message' => 'Cette classe ne relève pas de votre périmètre.', 'warnings' => []];
    }

    $year = tenant_find('academic_years', (int) $classroom['academic_year_id']);

    if ($year === null || in_array($year['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'published' => 0, 'message' => 'Cette année scolaire est clôturée.', 'warnings' => []];
    }

    $results = bulletins_service_rank_classroom($classroomId, $periodKey);

    // L'effectif imprimé est celui du CLASSEMENT : « 7e sur 42 » doit
    // compter 42 élèves classés, pas 45 inscrits dont trois sans aucune
    // cote. Le dénominateur du rang et le rang lui-même viennent de la
    // même population, sans quoi le dernier classé n'est pas « sur 45 ».
    $classSize = count(array_filter(
        $results,
        static fn (array $r): bool => $r['ranking_percentage'] !== null
    ));

    if ($results === []) {
        return ['ok' => false, 'published' => 0, 'message' => 'Aucun élève inscrit dans cette classe.', 'warnings' => []];
    }

    $warnings = [];
    $totalMissing = 0;

    foreach ($results as $row) {
        $totalMissing += $row['missing'];
    }

    if ($totalMissing > 0) {
        $warnings[] = $totalMissing . ' cote(s) manquante(s) : les bulletins concernés le mentionnent.';
    }

    $published = db_transaction(static function () use ($results, $periodKey, $classSize): int {
        $count  = 0;
        $userId = auth_id();

        foreach ($results as $enrollmentId => $row) {
            // La décision de fin d'année n'est pas prononcée ici : elle
            // relève du conseil de classe. Le bulletin annuel est publié
            // avec « en délibération », et la décision est enregistrée
            // ensuite, élève par élève.
            $decision = $periodKey === 'ANNUAL' ? 'pending' : null;

            db_query(
                'INSERT INTO bulletins
                    (school_id, enrollment_id, period_key, total_points, max_points,
                     percentage, ranking_percentage, class_rank, class_size,
                     missing_grades, absent_count,
                     decision, published_at, published_by)
                 VALUES
                    (:school_id, :enrollment_id, :period_key, :total, :max,
                     :percentage, :ranking_percentage, :rank, :size,
                     :missing, :absent,
                     :decision, NOW(), :publisher)
                 ON DUPLICATE KEY UPDATE
                    total_points       = VALUES(total_points),
                    max_points         = VALUES(max_points),
                    percentage         = VALUES(percentage),
                    ranking_percentage = VALUES(ranking_percentage),
                    class_rank         = VALUES(class_rank),
                    class_size         = VALUES(class_size),
                    missing_grades     = VALUES(missing_grades),
                    absent_count       = VALUES(absent_count),
                    published_at       = NOW(),
                    published_by       = VALUES(published_by)',
                [
                    'school_id'          => tenant_require(),
                    'enrollment_id'      => $enrollmentId,
                    'period_key'         => $periodKey,
                    'total'              => round($row['points'], 2),
                    'max'                => round($row['max'], 2),
                    'percentage'         => $row['percentage'] ?? 0.0,
                    'ranking_percentage' => $row['ranking_percentage'],
                    'rank'               => $row['rank'],
                    'size'               => $classSize,
                    'missing'            => $row['missing'],
                    'absent'             => $row['absent'],
                    'decision'           => $decision,
                    'publisher'          => $userId,
                ]
            );

            $count++;
        }

        return $count;
    });

    audit_log(
        'publish',
        'bulletin',
        $classroomId,
        null,
        ['period_key' => $periodKey, 'count' => $published, 'missing' => $totalMissing],
        'Publication des bulletins — ' . $groups[$periodKey]['label']
    );

    return ['ok' => true, 'published' => $published, 'message' => '', 'warnings' => $warnings];
}

/**
 * Enregistre la décision de fin d'année d'un élève.
 *
 * Elle est portée à la fois par le bulletin annuel et par l'inscription :
 * le bulletin parce que c'est le document, l'inscription parce que la
 * réinscription de l'année suivante la consulte.
 */
function bulletins_service_set_decision(int $enrollmentId, string $decision): array
{
    if (!perm_has('bulletin.publish')) {
        return ['ok' => false, 'message' => 'Vous n\'avez pas le droit de prononcer une décision.'];
    }

    if (!array_key_exists($decision, BULLETIN_DECISIONS)) {
        return ['ok' => false, 'message' => 'Décision inconnue.'];
    }

    $enrollment = tenant_find('enrollments', $enrollmentId);

    if ($enrollment === null) {
        return ['ok' => false, 'message' => 'Inscription introuvable.'];
    }

    // Même règle que pour la publication : la permission ne désigne pas
    // l'élève. Une décision de fin d'année prononcée hors périmètre
    // modifierait l'inscription d'un élève dont on n'a pas la charge.
    if (!students_can_view((int) $enrollment['student_id'], (int) $enrollment['academic_year_id'])) {
        return ['ok' => false, 'message' => 'Cet élève ne relève pas de votre périmètre.'];
    }

    $year = tenant_find('academic_years', (int) $enrollment['academic_year_id']);

    if ($year === null || in_array($year['status'], ['closed', 'archived'], true)) {
        return ['ok' => false, 'message' => 'Cette année scolaire est clôturée.'];
    }

    $bulletin = bulletins_repo_find($enrollmentId, 'ANNUAL');

    if ($bulletin === null) {
        return [
            'ok'      => false,
            'message' => 'Le bulletin annuel doit être publié avant de prononcer une décision.',
        ];
    }

    db_transaction(static function () use ($enrollmentId, $decision, $bulletin): void {
        tenant_update('bulletins', ['decision' => $decision], 'id = :id', ['id' => (int) $bulletin['id']]);

        // final_percentage et class_rank sont recopiés du bulletin, pas
        // recalculés : l'inscription doit porter exactement ce que le
        // document remis à la famille indique.
        tenant_update('enrollments', [
            'decision'         => $decision,
            'decision_date'    => date('Y-m-d'),
            'final_percentage' => $bulletin['percentage'],
            'class_rank'       => $bulletin['class_rank'],
        ], 'id = :id', ['id' => $enrollmentId]);

        audit_log(
            'update',
            'enrollment',
            $enrollmentId,
            null,
            ['decision' => $decision, 'percentage' => $bulletin['percentage']],
            'Décision de fin d\'année : ' . BULLETIN_DECISIONS[$decision]
        );
    });

    return ['ok' => true, 'message' => ''];
}

/**
 * Décision proposée d'après le seuil de réussite de l'établissement.
 *
 * Une PROPOSITION, jamais une décision : le conseil de classe tranche.
 * Le logiciel calcule, il ne délibère pas.
 */
function bulletins_service_suggest_decision(?float $percentage): string
{
    if ($percentage === null) {
        return 'pending';
    }

    require_once APP_PATH . '/modules/teachers/services.php';

    return $percentage >= teachers_passing_threshold() ? 'passed' : 'failed';
}
