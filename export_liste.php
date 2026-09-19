<?php
/**
 * Script d'export de listes de lecture
 * Formats supportés : HTML, CSV, Excel, JSON, TXT
 */

require_once 'includes/bootstrap.php';
require_once 'includes/export_functions.php';

// Vérifier les paramètres
if (!isset($_GET['liste_id']) || !isset($_GET['format'])) {
    die('Paramètres manquants');
}

$listeId = (int)$_GET['liste_id'];
$format = $_GET['format'];

// Récupérer les données de la liste
$liste = $bookManager->getListById($listeId);
if (!$liste) {
    die('Liste introuvable');
}

$books = $bookManager->getBooksInList($listeId);

// Fonction pour nettoyer le nom de fichier
function sanitizeFilename($name) {
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    return substr($name, 0, 50);
}

$filename = sanitizeFilename($liste['nom']) . '_' . date('Y-m-d');

// Export selon le format
switch ($format) {
    case 'csv':
        exportCSV($liste, $books, $filename);
        break;
    case 'excel':
        exportExcel($liste, $books, $filename);
        break;
    case 'json':
        exportJSON($liste, $books, $filename);
        break;
    case 'html':
        exportHTML($liste, $books, $filename);
        break;
    case 'txt':
        exportTXT($liste, $books, $filename);
        break;
    default:
        die('Format non supporté');
}
