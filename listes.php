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
                    $couverture = $bookManager->uploadCouvertureListe($_FILES['couverture']);
                }

                $listId = $bookManager->createList($_POST['nom'], $_POST['description'] ?? '', $couverture);
                redirectWithMessage('listes.php', "Liste créée avec succès !", 'success');
            } catch (Exception $e) {
                $message = "Erreur lors de la création : " . messageErreur($e);
                $messageType = 'error';
            }
        }

        // Modifier une liste
        if (isset($_POST['action']) && $_POST['action'] === 'update_list') {
            try {
                $couverture = $_POST['couverture_actuelle'] ?? null;

                // Gestion de l'upload de la nouvelle couverture
                if (isset($_FILES['couverture']) && $_FILES['couverture']['error'] === UPLOAD_ERR_OK) {
                    $nouvelleCouverture = $bookManager->uploadCouvertureListe($_FILES['couverture']);
                    // Supprimer l'ancienne couverture une fois la nouvelle bien enregistrée
                    Fichiers::supprimer($couverture);
                    $couverture = $nouvelleCouverture;
                }

                $bookManager->updateList($_POST['liste_id'], $_POST['nom'], $_POST['description'] ?? '', $couverture);
                redirectWithMessage('listes.php', "Liste mise à jour !", 'success');
            } catch (Exception $e) {
                $message = "Erreur lors de la mise à jour : " . messageErreur($e);
                $messageType = 'error';
            }
        }

        // Supprimer une liste
        if (isset($_POST['action']) && $_POST['action'] === 'delete_list') {
            try {
                $liste = $bookManager->getListById($_POST['liste_id']);
                if ($liste) {
                    Fichiers::supprimer($liste['couverture']);
                }
                $bookManager->deleteList($_POST['liste_id']);
                redirectWithMessage('listes.php', "Liste supprimée.", 'success');
            } catch (Exception $e) {
                $message = "Erreur lors de la suppression : " . messageErreur($e);
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
                $message = "Erreur : " . messageErreur($e);
                $messageType = 'error';
            }
        }

        // Retirer un livre d'une liste
        if (isset($_POST['action']) && $_POST['action'] === 'remove_book') {
            try {
                $bookManager->removeBookFromList($_POST['liste_id'], $_POST['livre_id']);
                redirectWithMessage('listes.php?view=' . $_POST['liste_id'], "Livre retiré de la liste.", 'success');
            } catch (Exception $e) {
                $message = "Erreur : " . messageErreur($e);
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
$allBooks = $bookManager->getBooksForPicker();

require 'views/listes.php';
