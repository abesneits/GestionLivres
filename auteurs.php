<?php
/**
 * Page listant les auteurs de la collection, avec leurs livres et leur biographie
 */

require_once 'includes/bootstrap.php';

$message = '';
$messageType = '';
$flashMessage = getFlashMessage();

// Fusionner des auteurs (doublons détectés ou fusion manuelle)
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'merge_authors') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        try {
            $sources = array_filter(array_map('trim', (array)($_POST['sources'] ?? [])));
            $target = trim($_POST['target'] ?? '');
            $cibleExistaitDeja = count($sources) === 1 && $bookManager->authorExists($target) && !in_array($target, $sources, true);
            $count = $bookManager->mergeAuthors($sources, $target);
            $verbe = $cibleExistaitDeja ? "Fusion effectuée avec l'auteur existant" : "Auteur renommé en";
            redirectWithMessage('auteurs.php', "$verbe « " . $target . " » : $count livre(s) mis à jour.", 'success');
        } catch (Exception $e) {
            $message = "Erreur lors de la fusion : " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Ignorer un groupe de doublons suggéré à tort
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'ignore_duplicate') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        $bookManager->ignoreDuplicateGroup($_POST['cle'] ?? '');
        redirectWithMessage('auteurs.php', "Ce groupe ne sera plus signalé comme doublon.", 'success');
    }
}

// Éditer manuellement la biographie d'un auteur
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_biographie') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        try {
            $photoFile = isset($_FILES['photo']) ? $_FILES['photo'] : null;
            $bookManager->setAuthorBiography(
                $_POST['nom'] ?? '',
                $_POST['biographie'] ?? '',
                $_POST['image_url'] ?? '',
                $photoFile,
                $_POST['naissance'] ?? '',
                $_POST['deces'] ?? '',
                $_POST['nationalite'] ?? ''
            );
            redirectWithMessage('auteurs.php', "Biographie mise à jour.", 'success');
        } catch (Exception $e) {
            $message = "Erreur lors de la mise à jour : " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$search = trim($_GET['q'] ?? '');
$filterSupport = trim($_GET['filter'] ?? '');
$filterTag = trim($_GET['tag'] ?? '');
$tri = in_array($_GET['tri'] ?? '', ['alpha', 'count_desc', 'recent']) ? $_GET['tri'] : 'alpha';
$bioManquante = isset($_GET['bio_manquante']) && $_GET['bio_manquante'] === '1';

$authors = $bookManager->getAllAuthorsWithBooks($search ?: null, $filterSupport ?: null, $filterTag ?: null, $tri, $bioManquante);

// Export CSV du résultat filtré, avant tout envoi de HTML
if (($_GET['export'] ?? '') === 'csv') {
    $bookManager->exportAuthorsCSV($authors);
}

$allTags = $bookManager->getAllTags();
$allAuthorNames = array_map(function($a) { return $a['nom']; }, $bookManager->getAllAuthorsWithBooks());
sort($allAuthorNames, SORT_NATURAL | SORT_FLAG_CASE);
$duplicateGroups = $bookManager->findDuplicateAuthorGroups();
require 'views/auteurs.php';
