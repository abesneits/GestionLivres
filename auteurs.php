<?php
/**
 * Page listant les auteurs de la collection, avec leurs livres et leur biographie
 */

require_once 'includes/bootstrap.php';

// Initiale (A-Z) d'une clé de tri d'auteur, « # » pour tout autre caractère
function initialeAuteur($cle) {
    $initiale = strtoupper(substr($cle, 0, 1));
    return ctype_alpha($initiale) ? $initiale : '#';
}

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
            $cibleExistaitDeja = count($sources) === 1 && $bookManager->authorExists($target) && !$bookManager->isSameAuthor($sources[0] ?? '', $target);
            $count = $bookManager->mergeAuthors($sources, $target);
            $verbe = $cibleExistaitDeja ? "Fusion effectuée avec l'auteur existant" : "Auteur renommé en";
            redirectWithMessage('auteurs.php', "$verbe « " . $target . " » : $count livre(s) mis à jour.", 'success');
        } catch (Exception $e) {
            $message = "Erreur lors de la fusion : " . messageErreur($e);
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

// Confirmer qu'un nom signalé « à vérifier » est correct
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'ignore_suspect') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        try {
            $bookManager->ignoreSuspiciousAuthor($_POST['nom'] ?? '');
            redirectWithMessage('auteurs.php', "Ce nom ne sera plus signalé.", 'success');
        } catch (Exception $e) {
            $message = "Erreur : " . messageErreur($e);
            $messageType = 'error';
        }
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
                $_POST['nationalite'] ?? '',
                $_POST['prenom'] ?? '',
                $_POST['nom_famille'] ?? ''
            );
            $message = "Auteur mis à jour.";
            // Les rôles ne sont envoyés qu'une fois la liste des livres chargée dans la fenêtre
            if (($_POST['roles_envoyes'] ?? '') === '1') {
                $rolesParLivre = [];
                foreach ((array)($_POST['roles'] ?? []) as $livreId => $roles) {
                    $rolesParLivre[(int)$livreId] = (array)$roles;
                }
                // Un livre dont toutes les cases sont décochées n'envoie rien : il revient au rôle par défaut
                foreach ((array)($_POST['livres_ids'] ?? []) as $livreId) {
                    $rolesParLivre += [(int)$livreId => []];
                }
                $nbRoles = $bookManager->setAuthorRoles($_POST['nom'] ?? '', $rolesParLivre);
                if ($nbRoles > 0) {
                    $message .= " Rôles modifiés sur $nbRoles livre(s).";
                }
            }
            redirectWithMessage('auteurs.php', $message, 'success');
        } catch (Exception $e) {
            $message = "Erreur lors de la mise à jour : " . messageErreur($e);
            $messageType = 'error';
        }
    }
}

$search = trim($_GET['q'] ?? '');
$filterSupport = trim($_GET['filter'] ?? '');
$filterTag = trim($_GET['tag'] ?? '');
$tri = in_array($_GET['tri'] ?? '', ['alpha', 'prenom', 'count_desc', 'recent']) ? $_GET['tri'] : 'alpha';
$bioManquante = isset($_GET['bio_manquante']) && $_GET['bio_manquante'] === '1';
$allRoles = $bookManager->getAuthorRoles();
$filterRole = in_array($_GET['role'] ?? '', $allRoles, true) ? $_GET['role'] : '';

// La liste complète est calculée UNE seule fois, puis réutilisée (noms, doublons, et affichage s'il n'y a pas de filtre)
$tousLesAuteurs = $bookManager->getAllAuthorsWithBooks();
if (!$search && !$filterSupport && !$filterTag && !$bioManquante && !$filterRole && $tri === 'alpha') {
    $authors = $tousLesAuteurs;
} else {
    $authors = $bookManager->getAllAuthorsWithBooks($search ?: null, $filterSupport ?: null, $filterTag ?: null, $tri, $bioManquante, $filterRole ?: null);
}

// Export CSV du résultat filtré, avant tout envoi de HTML
if (($_GET['export'] ?? '') === 'csv') {
    $bookManager->exportAuthorsCSV($authors);
}

$allTags = $bookManager->getAllTags();
$allAuthorNames = array_column($tousLesAuteurs, 'nom');
sort($allAuthorNames, SORT_NATURAL | SORT_FLAG_CASE);
$duplicateGroups = $bookManager->findDuplicateAuthorGroups($tousLesAuteurs);
$suspects = $bookManager->findSuspiciousAuthors($tousLesAuteurs);
require 'views/auteurs.php';
