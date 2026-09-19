<?php
/**
 * Endpoint AJAX : récupère (et met en cache) la biographie d'un auteur
 */
require_once 'includes/bootstrap.php';

$nom = trim($_GET['nom'] ?? '');

if ($nom === '') {
    jsonResponse(['success' => false, 'error' => 'Nom manquant'], 400);
}

$force = isset($_GET['force']) && $_GET['force'] === '1';
$data = $force ? $bookManager->refreshAuthorBiography($nom) : $bookManager->getAuthorBiography($nom);

jsonResponse([
    'success' => true,
    'biographie' => $data['biographie'],
    'image_url' => $data['image_url'],
    'naissance' => $data['naissance'] ?? null,
    'deces' => $data['deces'] ?? null,
    'nationalite' => $data['nationalite'] ?? null
]);
