<?php
/**
 * Page de gestion des listes de lecture - Version améliorée
 */

require_once 'includes/bootstrap.php';

$message = '';
$messageType = '';
$flashMessage = getFlashMessage();

// Traitement des actions
if ($_POST) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        // Créer une nouvelle liste
        if (isset($_POST['action']) && $_POST['action'] === 'create_list') {
            try {
                $couverture = null;

                // Gestion de l'upload de la couverture
                if (isset($_FILES['couverture']) && $_FILES['couverture']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = 'uploads/listes/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $extension = strtolower(pathinfo($_FILES['couverture']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                    if (in_array($extension, $allowedExtensions)) {
                        $filename = uniqid('liste_') . '.' . $extension;
                        $filepath = $uploadDir . $filename;

                        if (move_uploaded_file($_FILES['couverture']['tmp_name'], $filepath)) {
                            $couverture = $filepath;
                        }
                    }
                }

                $listId = $bookManager->createList($_POST['nom'], $_POST['description'] ?? '', $couverture);
                redirectWithMessage('listes.php', "Liste créée avec succès !", 'success');
            } catch (Exception $e) {
                $message = "Erreur lors de la création : " . $e->getMessage();
                $messageType = 'error';
            }
        }

        // Modifier une liste
        if (isset($_POST['action']) && $_POST['action'] === 'update_list') {
            try {
                $couverture = $_POST['couverture_actuelle'] ?? null;

                // Gestion de l'upload de la nouvelle couverture
                if (isset($_FILES['couverture']) && $_FILES['couverture']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = 'uploads/listes/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $extension = strtolower(pathinfo($_FILES['couverture']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                    if (in_array($extension, $allowedExtensions)) {
                        // Supprimer l'ancienne couverture
                        if ($couverture && file_exists($couverture)) {
                            unlink($couverture);
                        }

                        $filename = uniqid('liste_') . '.' . $extension;
                        $filepath = $uploadDir . $filename;

                        if (move_uploaded_file($_FILES['couverture']['tmp_name'], $filepath)) {
                            $couverture = $filepath;
                        }
                    }
                }

                $bookManager->updateList($_POST['liste_id'], $_POST['nom'], $_POST['description'] ?? '', $couverture);
                redirectWithMessage('listes.php', "Liste mise à jour !", 'success');
            } catch (Exception $e) {
                $message = "Erreur lors de la mise à jour : " . $e->getMessage();
                $messageType = 'error';
            }
        }

        // Supprimer une liste
        if (isset($_POST['action']) && $_POST['action'] === 'delete_list') {
            try {
                $liste = $bookManager->getListById($_POST['liste_id']);
                if ($liste && $liste['couverture'] && file_exists($liste['couverture'])) {
                    unlink($liste['couverture']);
                }
                $bookManager->deleteList($_POST['liste_id']);
                redirectWithMessage('listes.php', "Liste supprimée.", 'success');
            } catch (Exception $e) {
                $message = "Erreur lors de la suppression : " . $e->getMessage();
                $messageType = 'error';
            }
        }

        // Ajouter plusieurs livres à une liste
        if (isset($_POST['action']) && $_POST['action'] === 'add_books') {
            try {
                $livresIds = $_POST['livres_ids'] ?? [];
                $addedCount = 0;
                $alreadyInList = 0;

                foreach ($livresIds as $livreId) {
                    $result = $bookManager->addBookToList($_POST['liste_id'], $livreId);
                    if ($result === true) {
                        $addedCount++;
                    } elseif ($result === false) {
                        $alreadyInList++;
                    }
                }

                if ($addedCount > 0 && $alreadyInList === 0) {
                    redirectWithMessage('listes.php?view=' . $_POST['liste_id'], "$addedCount livre(s) ajouté(s) à la liste !", 'success');
                } elseif ($addedCount > 0 && $alreadyInList > 0) {
                    redirectWithMessage('listes.php?view=' . $_POST['liste_id'], "$addedCount livre(s) ajouté(s), $alreadyInList déjà présent(s).", 'success');
                } else {
                    redirectWithMessage('listes.php?view=' . $_POST['liste_id'], "Tous les livres sont déjà dans cette liste.", 'warning');
                }
            } catch (Exception $e) {
                $message = "Erreur : " . $e->getMessage();
                $messageType = 'error';
            }
        }

        // Retirer un livre d'une liste
        if (isset($_POST['action']) && $_POST['action'] === 'remove_book') {
            try {
                $bookManager->removeBookFromList($_POST['liste_id'], $_POST['livre_id']);
                redirectWithMessage('listes.php?view=' . $_POST['liste_id'], "Livre retiré de la liste.", 'success');
            } catch (Exception $e) {
                $message = "Erreur : " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Récupérer toutes les listes
$listes = $bookManager->getAllLists();

// Récupérer les livres d'une liste si demandé
$currentList = null;
$booksInList = [];
if (isset($_GET['view'])) {
    $currentList = $bookManager->getListById($_GET['view']);
    if ($currentList) {
        $booksInList = $bookManager->getBooksInList($_GET['view']);
    }
}

// Liste à éditer
$editList = null;
if (isset($_GET['edit'])) {
    $editList = $bookManager->getListById($_GET['edit']);
}

// Récupérer tous les livres pour l'ajout
$allBooks = $bookManager->getAllBooks(null, null, null, null, 1, 999999);

require 'views/listes.php';
