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
    // Accents -> lettres simples (« Été » devient « Ete »), avant de nettoyer le reste
    $name = strtr($name, [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','î'=>'i','ï'=>'i','ì'=>'i','ñ'=>'n','ó'=>'o','ô'=>'o','ö'=>'o','ò'=>'o','õ'=>'o','œ'=>'oe',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','æ'=>'ae',
        'À'=>'A','Â'=>'A','Ä'=>'A','Á'=>'A','Ã'=>'A','Å'=>'A','Ç'=>'C','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'Í'=>'I','Î'=>'I','Ï'=>'I','Ì'=>'I','Ñ'=>'N','Ó'=>'O','Ô'=>'O','Ö'=>'O','Ò'=>'O','Õ'=>'O','Œ'=>'OE',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','Æ'=>'AE',
    ]);
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
