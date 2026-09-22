<?php
/**
 * Téléchargement du fichier numérique (PDF/Epub/...) d'un livre.
 * Le fichier est stocké dans uploads/ebooks/, dont le .htaccess interdit tout
 * accès direct : ce script est le seul point d'accès, protégé par l'authentification
 * de includes/bootstrap.php (requireAuth()).
 */

require_once 'includes/bootstrap.php';

if (!isset($_GET['id'])) {
    die('Paramètre manquant');
}

$book = $bookManager->getBookById((int)$_GET['id']);

if (!$book || $book['type_livre'] !== 'Numérique' || empty($book['fichier_numerique']) || !file_exists($book['fichier_numerique'])) {
    die('Fichier introuvable');
}

$chemin = $book['fichier_numerique'];
$extension = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));

$mimesConnus = ['pdf' => 'application/pdf', 'epub' => 'application/epub+zip'];
$mimeType = $mimesConnus[$extension] ?? 'application/octet-stream';

$nomTelecharge = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $book['titre']) . '.' . $extension;

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $nomTelecharge . '"');
header('Content-Length: ' . filesize($chemin));
header('Pragma: no-cache');
header('Expires: 0');

readfile($chemin);
