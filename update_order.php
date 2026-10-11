<?php
require_once 'includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Le jeton CSRF arrive dans un en-tête (la requête est en JSON, pas un formulaire)
    if (!validateCSRFToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token de sécurité invalide']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['liste_id']) && isset($data['order']) && is_array($data['order'])) {
        $result = $bookManager->updateBookOrder((int) $data['liste_id'], array_map('intval', $data['order']));

        if ($result) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de la mise à jour']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Données manquantes']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
}
