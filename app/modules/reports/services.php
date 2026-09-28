<?php
/**
 * Module RAPPORTS — totaux, taux, export.
 *
 * LE FORMAT D'EXPORT, ET POURQUOI CELUI-LÀ
 * =========================================
 * Le cahier des charges demande « export PDF/Excel ». Le produit
 * s'interdit Composer — hébergement cPanel, PHP procédural — donc ni
 * PhpSpreadsheet ni TCPDF.
 *
 * Ce qui est livré :
 *   · EXCEL → un CSV avec BOM UTF-8 et séparateur `;`. Excel francophone
 *     l'ouvre en double-cliquant, colonnes séparées et accents intacts.
 *     C'est le motif déjà éprouvé par l'export des impayés (phase 5).
 *   · PDF   → une feuille imprimable. L'école imprime depuis son
 *     navigateur, qui sait produire un PDF depuis 2015.
 *
 * L'alternative écartée : écrire un générateur XLSX à la main. C'est
 * faisable — l'extension `zip` est présente, un XLSX est une archive de
 * XML — mais cela ferait trois cents lignes de plus à maintenir pour
 * gagner la mise en forme et les formules, dont aucune école n'a
 * exprimé le besoin.
 *
 *   > Une technologie qu'on ajoute « parce qu'elle est plus propre »
 *   > sans besoin exprimé est une dette qu'on choisit.
 *
 * Si des feuilles multiples ou des formules deviennent nécessaires, ce
 * choix se rediscute — il n'est pas gravé.
 */

declare(strict_types=1);

require_once __DIR__ . '/repositories.php';

/**
 * Additionne les lignes d'un rapport.
 *
 * Les totaux se calculent ICI, à partir des lignes déjà lues : une
 * seconde requête d'agrégation coûterait un aller-retour et pourrait
 * voir un autre état de la base.
 *
 * @param array<int, array<string, mixed>> $lignes
 * @param array<int, string>               $colonnes
 * @return array<string, int>
 */
function reports_total(array $lignes, array $colonnes): array
{
    $total = array_fill_keys($colonnes, 0);

    foreach ($lignes as $ligne) {
        foreach ($colonnes as $colonne) {
            $total[$colonne] += (int) ($ligne[$colonne] ?? 0);
        }
    }

    return $total;
}

/**
 * Un pourcentage, ou `null` quand le dénominateur est nul.
 *
 * `null` et `0` ne veulent pas dire la même chose : « aucun bulletin
 * décidé » n'est pas « 0 % de réussite ». L'écran affiche un tiret dans
 * le premier cas, et c'est la seule façon honnête de le dire.
 */
function reports_rate(int|float $numerateur, int|float $denominateur): ?float
{
    return $denominateur > 0 ? round(($numerateur / $denominateur) * 100, 1) : null;
}

/** Un taux prêt à l'affichage. */
function reports_rate_display(?float $taux): string
{
    return $taux === null ? '—' : number_format($taux, 1, ',', ' ') . ' %';
}

/**
 * Construit un CSV lisible par Excel francophone.
 *
 * QUATRE DÉTAILS QUI DÉCIDENT DE TOUT
 * ====================================
 *  · le BOM UTF-8, sans lequel Excel massacre les accents ;
 *  · le séparateur `;`, qu'attend un Excel configuré en français —
 *    la virgule y produit une seule colonne ;
 *  · les quatrième et cinquième arguments de `fputcsv()`, écrits
 *    explicitement : PHP 8.4 émet sinon une dépréciation que le
 *    gestionnaire d'erreurs du projet transforme en exception, et
 *    l'export ne produit plus rien du tout ;
 *  · `csv_safe_cell()` sur CHAQUE cellule — sans quoi un nom de classe
 *    saisi par l'école devient une formule exécutée sur le poste de
 *    celui qui ouvre le fichier.
 *
 * @param array<int, string>              $entetes
 * @param array<int, array<int, string>>  $lignes
 */
function reports_build_csv(array $entetes, array $lignes): string
{
    $flux = fopen('php://temp', 'r+b');

    fwrite($flux, "\xEF\xBB\xBF");
    fputcsv($flux, array_map('csv_safe_cell', $entetes), ';', '"', '');

    foreach ($lignes as $ligne) {
        // CHAQUE CELLULE EST DÉSARMÉE, y compris les en-têtes.
        //
        // Les noms de classes et d'élèves sont saisis par l'école, et
        // Excel évalue toute cellule commençant par `=`, `+`, `-`, `@`
        // ou une tabulation — le guillemet du CSV n'y change rien.
        // Mesuré sur l'export livré : sept charges sur sept passaient.
        fputcsv($flux, array_map('csv_safe_cell', $ligne), ';', '"', '');
    }

    rewind($flux);
    $csv = (string) stream_get_contents($flux);
    fclose($flux);

    return $csv;
}

/**
 * Envoie un CSV en téléchargement, et le journalise.
 *
 * UN EXPORT EST UNE SORTIE DE DONNÉES : il se trace.
 * Un fichier d'effectifs ou de résultats quitte le produit et vit sa
 * vie ; l'école doit pouvoir dire qui l'a tiré, et quand.
 *
 *   > Une donnée qui sort sans laisser de trace est une donnée dont on
 *   > perd la garde au moment précis où elle devient copiable.
 *
 * @param array<string, mixed> $contexte
 */
function reports_send_csv(string $nom, string $csv, array $contexte = []): never
{
    audit_log('report.exported', 'reports', null, null,
        ['rapport' => $nom] + $contexte, 'Export d\'un rapport');

    $fichier = $nom . '-' . date('Ymd-Hi') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fichier . '"');
    header('Content-Length: ' . strlen($csv));

    echo $csv;

    exit;
}

/** Un nombre décimal à la française, pour le CSV. */
function reports_csv_number(mixed $valeur, int $decimales = 1): string
{
    return $valeur === null ? '' : number_format((float) $valeur, $decimales, ',', '');
}
