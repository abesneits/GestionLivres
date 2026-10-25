<?php
/**
 * Endpoint AJAX : récupère (et met en cache) la biographie d'un auteur de la collection.
 * - GET  : lecture (utilise le cache, ne contacte Wikipédia que s'il est vide)
 * - POST : « Relancer la recherche » : nouvel appel externe, protégé par jeton CSRF
 */
require_once 'includes/bootstrap.php';

$nom = trim($_GET['nom'] ?? '');

if ($nom === '' || mb_strlen($nom) > 255) {
    jsonResponse(['success' => false, 'error' => 'Nom manquant ou trop long'], 400);
}

// Seuls les auteurs réellement présents dans la collection peuvent être consultés
if (!$bookManager->authorExists($nom)) {
    jsonResponse(['success' => false, 'error' => 'Auteur inconnu'], 404);
}

$force = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($force && !validateCSRFToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    jsonResponse(['success' => false, 'error' => 'Token de sécurité invalide'], 403);
}

$data = $force ? $bookManager->refreshAuthorBiography($nom) : $bookManager->getAuthorBiography($nom);

jsonResponse([
    'success' => true,
    'biographie' => $data['biographie'],
    'image_url' => $data['image_url'],
    'naissance' => $data['naissance'] ?? null,
    'deces' => $data['deces'] ?? null,
    'nationalite' => $data['nationalite'] ?? null,
    'prenom' => $data['prenom'] ?? '',
    'nom_famille' => $data['nom_famille'] ?? ''
]);
