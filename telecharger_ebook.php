<?php
/**
 * Téléchargement du fichier numérique (PDF/Epub/...) d'un livre.
 * Le fichier est stocké dans uploads/ebooks/, dont le .htaccess interdit tout
 * accès direct : ce script est le seul point d'accès, protégé par l'authentification
 * de includes/bootstrap.php (requireAuth()).
 */

require_once 'includes/bootstrap.php';

/**
 * Répondre par une erreur HTTP en texte brut puis s'arrêter.
 */
function erreurTelechargement($code, $texte) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $texte;
    exit();
}

if (!isset($_GET['id'])) {
    erreurTelechargement(400, 'Paramètre manquant');
}

$book = $bookManager->getBookById((int)$_GET['id']);

if (!$book || $book['type_livre'] !== 'Numérique' || empty($book['fichier_numerique']) || !file_exists($book['fichier_numerique'])) {
    erreurTelechargement(404, 'Fichier introuvable');
}

$chemin = $book['fichier_numerique'];
$extension = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));

$mimesConnus = ['pdf' => 'application/pdf', 'epub' => 'application/epub+zip'];
$mimeType = $mimesConnus[$extension] ?? 'application/octet-stream';

// Nom ASCII pour les anciens navigateurs, et nom UTF-8 exact (RFC 5987) pour les autres
$titre = trim((string)$book['titre']) !== '' ? $book['titre'] : 'livre';
$nomAscii = preg_replace('/[^a-zA-Z0-9_\-]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titre) ?: 'livre') . '.' . $extension;
$nomUtf8 = rawurlencode(preg_replace('/[\x00-\x1F\x7F\/\\\\"]/', '_', $titre) . '.' . $extension);

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $nomAscii . '"; filename*=UTF-8\'\'' . $nomUtf8);
header('Content-Length: ' . filesize($chemin));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($chemin);
