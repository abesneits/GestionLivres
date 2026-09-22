<?php
/**
 * Page d'export de la collection : recherche/filtre/tri avancés,
 * puis export du résultat dans le format choisi (HTML, Excel, CSV, JSON, TXT).
 */

require_once 'includes/bootstrap.php';
require_once 'includes/export_functions.php';

$supportTypes = $bookManager->getSupportTypesWithCounts();
$formatTypes = $bookManager->getFormatsNumeriquesWithCounts();
$criteres = sanitizeAdvancedSearchParams($_GET, array_keys($supportTypes), array_keys($formatTypes));
$formatsValides = ['csv', 'excel', 'json', 'html', 'txt'];
$format = $_GET['format'] ?? '';

$perPageOptions = [20, 50, 100];
$perPageRequested = (int)($_GET['per_page'] ?? 20);
$perPage = in_array($perPageRequested, $perPageOptions, true) ? $perPageRequested : 20;

$filtreActif = $criteres['search'] !== ''
    || $criteres['support'] !== ''
    || $criteres['type_livre'] !== ''
    || $criteres['format_numerique'] !== ''
    || $criteres['statut'] !== ''
    || $criteres['serie'] !== ''
    || !empty($criteres['tags'])
    || $criteres['date_from'] !== ''
    || $criteres['date_to'] !== ''
    || $criteres['sans_couverture']
    || $criteres['sans_note'];

// Signature stable des critères de recherche (hors pagination/format), utilisée côté
// client pour savoir si une sélection de livres mémorisée correspond toujours à la
// recherche en cours, ou si elle doit être réinitialisée.
$signatureParams = $_GET;
unset($signatureParams['page'], $signatureParams['per_page'], $signatureParams['livres_ids'], $signatureParams['format'], $signatureParams['ajax']);
ksort($signatureParams);
$filterSignature = http_build_query($signatureParams);

/**
 * Construit une description lisible des filtres actifs pour l'inclure
 * dans les métadonnées du fichier exporté.
 */
function buildFilterSummary($criteres, $filtreActif) {
    if (!$filtreActif) {
        return '';
    }

    $parts = [];
    if ($criteres['search'] !== '') {
        $parts[] = 'Recherche : "' . $criteres['search'] . '"';
    }
    if ($criteres['support'] !== '') {
        $parts[] = 'Support : ' . $criteres['support'];
    }
    if ($criteres['type_livre'] !== '') {
        $parts[] = 'Type : ' . $criteres['type_livre'];
    }
    if ($criteres['format_numerique'] !== '') {
        $parts[] = 'Format : ' . $criteres['format_numerique'];
    }
    if ($criteres['statut'] !== '') {
        $parts[] = 'Statut : ' . $criteres['statut'];
    }
    if ($criteres['serie'] !== '') {
        $parts[] = 'Série : ' . $criteres['serie'];
    }
    if (!empty($criteres['tags'])) {
        $glue = $criteres['tags_mode'] === 'or' ? ' ou ' : ' et ';
        $parts[] = 'Tags : ' . implode($glue, $criteres['tags']);
    }
    if ($criteres['date_from'] !== '' || $criteres['date_to'] !== '') {
        $parts[] = 'Ajoutés entre ' . ($criteres['date_from'] ?: '…') . ' et ' . ($criteres['date_to'] ?: '…');
    }
    if ($criteres['sans_couverture']) {
        $parts[] = 'Sans couverture';
    }
    if ($criteres['sans_note']) {
        $parts[] = 'Sans note personnelle';
    }

    return implode(' | ', $parts);
}

/**
 * Rend le fragment HTML "Résultats de la recherche" (compteur, sélection,
 * liste des livres, pagination). Utilisé à la fois pour le rendu initial de
 * la page et pour les rafraîchissements AJAX, afin de ne pas dupliquer le HTML.
 */
function renderExportResults($previewBooks, $paginationInfo, $totalResultats) {
    ob_start();
    require 'views/export_results.php';
    return ob_get_clean();
}
// IDs cochés manuellement dans la liste de résultats, pour affiner la sélection
// au-delà des seuls critères de recherche (voir buildAdvancedWhere()).
$selectedIds = [];
if (!empty($_GET['livres_ids']) && is_array($_GET['livres_ids'])) {
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $_GET['livres_ids']))));
}

// Génération et téléchargement direct du fichier si un format valide est demandé
if (in_array($format, $formatsValides, true)) {
    $books = $bookManager->searchBooksAdvanced($criteres, 1, 999999);

    if (!empty($selectedIds)) {
        $idsVoulus = array_flip($selectedIds);
        $books = array_values(array_filter($books, function($book) use ($idsVoulus) {
            return isset($idsVoulus[(int)$book['id']]);
        }));
    }

    $sortLabels = [
        'titre_asc' => 'Titre A-Z',
        'titre_desc' => 'Titre Z-A',
        'date_desc' => 'Plus récents',
        'date_asc' => 'Plus anciens',
        'serie_asc' => 'Série puis tome',
    ];

    $descriptionParts = buildFilterSummary($criteres, $filtreActif);
    if (!empty($selectedIds)) {
        $descriptionParts = trim($descriptionParts . ($descriptionParts !== '' ? ' | ' : '') . 'Sélection manuelle : ' . count($books) . ' livre(s)');
    }

    $meta = [
        'nom' => ($filtreActif || !empty($selectedIds)) ? 'Export de recherche - Ma Collection' : 'Ma Collection de Livres',
        'description' => trim($descriptionParts
            . ($descriptionParts !== '' ? ' | ' : '') . 'Tri : ' . ($sortLabels[$criteres['sort']] ?? $criteres['sort']), ' |')
    ];

    $filename = 'export_collection_' . date('Y-m-d');

    switch ($format) {
        case 'csv':
            exportCSV($meta, $books, $filename);
            break;
        case 'excel':
            exportExcel($meta, $books, $filename);
            break;
        case 'json':
            exportJSON($meta, $books, $filename);
            break;
        case 'html':
            exportHTML($meta, $books, $filename);
            break;
        case 'txt':
            exportTXT($meta, $books, $filename);
            break;
    }
    exit;
}

// Sinon, calculer les résultats à afficher (page HTML complète ou fragment AJAX)
$totalResultats = $bookManager->countBooksAdvanced($criteres);
$paginationInfo = $bookManager->getPaginationInfo($totalResultats, $criteres['page'], $perPage);
$previewBooks = $bookManager->searchBooksAdvanced($criteres, $criteres['page'], $perPage);

// Rafraîchissement AJAX : ne renvoyer que le fragment de résultats, en JSON.
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'html' => renderExportResults($previewBooks, $paginationInfo, $totalResultats),
        'total' => $totalResultats,
        'signature' => $filterSignature,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$allTags = $bookManager->getAllTags();

require 'views/export.php';
