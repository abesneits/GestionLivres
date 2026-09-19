<?php
require_once 'includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['liste_id']) && isset($data['order'])) {
        $result = $bookManager->updateBookOrder($data['liste_id'], $data['order']);
        
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