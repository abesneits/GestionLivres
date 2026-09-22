<?php
/**
 * Page de suppression de livres - Version avec affichage tableau
 */

require_once 'includes/bootstrap.php';

$message = '';
$messageType = '';
$flashMessage = getFlashMessage();

// Traitement de la suppression
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'delete' && !empty($_POST['book_id'])) {
    // Validation CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Token de sécurité invalide. Veuillez réessayer.";
        $messageType = 'error';
    } else {
        try {
            $book = $bookManager->getBookById($_POST['book_id']);
            if ($book) {
                if ($bookManager->deleteBook($_POST['book_id'])) {
                    $successMessage = "Le livre \"" . h($book['titre']) . "\" a été supprimé avec succès.";
                    logMessage("Book deleted: " . $book['titre'] . " (ID: " . $book['id'] . ")", 'INFO');
                    
                    // Préserver les paramètres de recherche après suppression
                    $redirectParams = array_intersect_key($_GET, array_flip(['search', 'page']));
                    $redirectUrl = 'supprimer.php' . (!empty($redirectParams) ? '?' . http_build_query($redirectParams) : '');
                    
                    redirectWithMessage($redirectUrl, $successMessage, 'success');
                } else {
                    $message = "Erreur lors de la suppression du livre.";
                    $messageType = 'error';
                }
            } else {
                $message = "Livre introuvable.";
                $messageType = 'error';
            }
        } catch (Exception $e) {
            $message = "Erreur lors de la suppression : " . $e->getMessage();
            $messageType = 'error';
            logMessage("Delete error: " . $e->getMessage(), 'ERROR');
        }
    }
}

// Nettoyer et récupérer les paramètres de recherche
$params = sanitizeSearchParams($_GET);
$search = $params['search'] ?? '';
$page = $params['page'] ?? 1;

// Récupérer le nombre total de livres pour la pagination
$totalBooks = $bookManager->countBooks(null, $search ?: null);

// Calculer les informations de pagination
$paginationInfo = $bookManager->getPaginationInfo($totalBooks, $page);

// Récupérer les livres pour la page courante
$books = $bookManager->getAllBooks(null, $search ?: null, null, null, $page);
$stats = $bookManager->getStats();

require 'views/supprimer.php';
