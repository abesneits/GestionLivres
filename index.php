<?php
/**
 * Page de la collection complète avec recherche, filtres et pagination
 */

require_once 'includes/bootstrap.php';

$message = '';
$messageType = '';

// Récupérer le message flash s'il existe
$flashMessage = getFlashMessage();

// Traitement des actions
if ($_POST) {
    // Vérification CSRF pour les actions importantes
    if (isset($_POST['action']) && in_array($_POST['action'], ['update_notes', 'delete'])) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = "Token de sécurité invalide. Veuillez réessayer.";
            $messageType = 'error';
        }
    }
    
    if (empty($message) && isset($_POST['action']) && $_POST['action'] === 'update_notes' && !empty($_POST['book_id'])) {
        try {
            $couverture_perso = isset($_FILES['couverture_perso']) ? $_FILES['couverture_perso'] : null;
            $supprimer_couverture = isset($_POST['supprimer_couverture']) && $_POST['supprimer_couverture'] === '1';
            $fichier_numerique_perso = isset($_FILES['fichier_numerique_perso']) ? $_FILES['fichier_numerique_perso'] : null;
            $supprimer_fichier_numerique = isset($_POST['supprimer_fichier_numerique']) && $_POST['supprimer_fichier_numerique'] === '1';

            $bookManager->updateBookNotes(
    $_POST['book_id'],
    $_POST['note_personnelle'],
    $_POST['tags'],
    $_POST['statut'],
    $_POST['support'] ?? null,
    $_POST['description'] ?? '',
    $couverture_perso,
    $supprimer_couverture,
    $_POST['titre'] ?? null,
    $_POST['auteur'] ?? null,
    $_POST['serie'] ?? null,
    $_POST['tome'] ?? null,
    $_POST['type_livre'] ?? null,
    $_POST['format_numerique'] ?? null,
    $fichier_numerique_perso,
    $supprimer_fichier_numerique
);
            
            redirectWithMessage(
                buildUrl([], ['edit']),
                "Notes et informations mises à jour avec succès !",
                'success'
            );
            
        } catch (Exception $e) {
            $message = "Erreur lors de la mise à jour : " . $e->getMessage();
            $messageType = 'error';
            logMessage("Erreur update_notes: " . $e->getMessage(), 'ERROR');
        }
    }
    
    if (empty($message) && isset($_POST['action']) && $_POST['action'] === 'delete' && !empty($_POST['book_id'])) {
        try {
            $book = $bookManager->getBookById($_POST['book_id']);
            if ($book && $bookManager->deleteBook($_POST['book_id'])) {
                redirectWithMessage(
                    buildUrl([], ['edit']),
                    "Livre \"" . h($book['titre']) . "\" supprimé avec succès.",
                    'success'
                );
            } else {
                $message = "Erreur lors de la suppression.";
                $messageType = 'error';
            }
        } catch (Exception $e) {
            $message = "Erreur lors de la suppression : " . $e->getMessage();
            $messageType = 'error';
            logMessage("Erreur delete: " . $e->getMessage(), 'ERROR');
        }
    }
}

// Nettoyer et récupérer les paramètres de recherche et filtres
$params = sanitizeSearchParams($_GET, array_keys($bookManager->getSupportTypesWithCounts()));
$search = $params['search'] ?? '';
$filter = $params['filter'] ?? '';
$type = $params['type'] ?? '';
$tag = $params['tag'] ?? '';
$statut = $params['statut'] ?? '';
$serie = $params['serie'] ?? '';
$view = $params['view'] ?? 'grid';
$page = $params['page'] ?? 1;

// Récupérer le nombre total de livres pour la pagination
$totalBooks = $bookManager->countBooks(
    $filter ?: null,
    $search ?: null,
    $tag ?: null,
    $statut ?: null,
    $serie ?: null,
    $type ?: null
);

// Calculer les informations de pagination
$paginationInfo = $bookManager->getPaginationInfo($totalBooks, $page);

// Récupérer les livres pour la page courante
$books = $bookManager->getAllBooks(
    $filter ?: null,
    $search ?: null,
    $tag ?: null,
    $statut ?: null,
    $page,
    null,
    $serie ?: null,
    $type ?: null
);

$stats = $bookManager->getStats();
$allTags = $bookManager->getAllTags();
$allSeries = $bookManager->getAllSeries();
$allFormatsNumeriques = $bookManager->getFormatsNumeriquesWithCounts();
$allExtensionsNumeriques = $bookManager->getAllExtensionsNumeriques();

// Livre à éditer (modal)
$editBook = null;
if (isset($_GET['edit'])) {
    $editBook = $bookManager->getBookById($_GET['edit']);
}

require 'views/index.php';
