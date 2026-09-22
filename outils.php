<?php
/**
 * Page d'outils : gestion de la base de données, édition groupée et gestion des tags.
 */

require_once 'includes/bootstrap.php';

$section = in_array($_GET['section'] ?? '', ['bdd', 'edition', 'tags', 'supports']) ? $_GET['section'] : 'bdd';
$supportTypes = $bookManager->getSupportTypesWithCounts();

$message = '';
$messageType = '';

// Téléchargement de la sauvegarde (GET, pas d'effet de bord)
if ($section === 'bdd' && ($_GET['export'] ?? '') === 'backup') {
    $bookManager->exportDataBackup();
    exit();
}

if ($_POST && isset($_POST['action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Token de sécurité invalide. Veuillez réessayer.";
        $messageType = 'error';
    } elseif ($_POST['action'] === 'optimize_tables') {
        $resultats = $bookManager->optimizeTables();
        redirectWithMessage('outils.php?section=bdd', "Optimisation effectuée sur " . count($resultats) . " table(s).", 'success');
    } elseif ($_POST['action'] === 'restore_backup') {
        if (($_POST['confirm_text'] ?? '') !== 'RESTAURER') {
            $message = "Veuillez taper RESTAURER pour confirmer la restauration.";
            $messageType = 'error';
        } elseif (empty($_FILES['backup_file']['tmp_name']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $message = "Aucun fichier de sauvegarde valide n'a été envoyé.";
            $messageType = 'error';
        } else {
            try {
                $contenu = file_get_contents($_FILES['backup_file']['tmp_name']);
                $resume = $bookManager->restoreDataBackup($contenu);
                redirectWithMessage(
                    'outils.php?section=bdd',
                    "Restauration effectuée : {$resume['livres']} livre(s), {$resume['auteurs']} auteur(s), {$resume['listes_lecture']} liste(s).",
                    'success'
                );
            } catch (Exception $e) {
                $message = "Erreur lors de la restauration : " . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'bulk_update') {
        try {
            $tagMode = $_POST['tag_mode'] ?? '';
            $tagMode = in_array($tagMode, ['add', 'remove', 'replace']) ? $tagMode : null;

            $resultat = $bookManager->bulkUpdateBooks(
                $_POST['livres_ids'] ?? [],
                $_POST['new_support'] ?: null,
                $_POST['new_statut'] ?: null,
                $tagMode,
                $_POST['tags_value'] ?? ''
            );

            $msg = $resultat['updated'] . " livre(s) mis à jour.";
            if (!empty($resultat['skipped_tags_too_long'])) {
                $msg .= " " . count($resultat['skipped_tags_too_long']) . " livre(s) ignoré(s) pour les tags (limite de 500 caractères dépassée).";
            }

            $retourQs = $_POST['return_qs'] ?? '';
            redirectWithMessage('outils.php?section=edition' . ($retourQs ? '&' . $retourQs : ''), $msg, 'success');
        } catch (Exception $e) {
            $message = "Erreur lors de la mise à jour : " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'create_tag') {
        try {
            $bookManager->addTagToCatalog($_POST['nom'] ?? '');
            redirectWithMessage('outils.php?section=tags', "Tag créé.", 'success');
        } catch (Exception $e) {
            $message = "Erreur : " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'add_support') {
        try {
            $bookManager->addSupportType($_POST['nom'] ?? '');
            redirectWithMessage('outils.php?section=supports', "Support ajouté.", 'success');
        } catch (Exception $e) {
            $message = "Erreur : " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'rename_support') {
        try {
            $count = $bookManager->renameSupportType($_POST['old_support'] ?? '', $_POST['new_support_name'] ?? '');
            redirectWithMessage('outils.php?section=supports', "Support renommé : $count livre(s) mis à jour.", 'success');
        } catch (Exception $e) {
            $message = "Erreur : " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'rename_tag') {
        try {
            $resultat = $bookManager->renameTag($_POST['old_tag'] ?? '', $_POST['new_tag'] ?? '');
            $msg = "Tag renommé : " . $resultat['updated'] . " livre(s) mis à jour.";
            if (!empty($resultat['skipped_too_long'])) {
                $msg .= " " . count($resultat['skipped_too_long']) . " livre(s) ignoré(s) (limite de 500 caractères dépassée).";
            }
            redirectWithMessage('outils.php?section=tags', $msg, 'success');
        } catch (Exception $e) {
            $message = "Erreur : " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'delete_tag') {
        $count = $bookManager->deleteTagEverywhere($_POST['tag'] ?? '');
        redirectWithMessage('outils.php?section=tags', "Tag supprimé de $count livre(s).", 'success');
    } elseif ($_POST['action'] === 'delete_orphans') {
        $count = $bookManager->deleteOrphanUploads();
        redirectWithMessage('outils.php?section=bdd', "$count fichier(s) orphelin(s) supprimé(s).", 'success');
    }
}

$flashMessage = getFlashMessage();
$dbInfo = $section === 'bdd' ? $bookManager->getDatabaseInfo() : null;
$storageInfo = $section === 'bdd' ? $bookManager->getUploadsStorageInfo() : null;

$criteres = null;
$resultatsLivres = [];
$paginationInfoEdition = null;
$allTagsEdition = [];
$tousLesTags = [];
$filtreActif = false;

if ($section === 'edition') {
    $criteres = sanitizeAdvancedSearchParams($_GET, array_keys($supportTypes));
    $totalResultats = $bookManager->countBooksAdvanced($criteres);
    $paginationInfoEdition = $bookManager->getPaginationInfo($totalResultats, $criteres['page']);
    $resultatsLivres = $bookManager->searchBooksAdvanced($criteres, $criteres['page']);
    $allTagsEdition = $bookManager->getAllTags();

    $filtreActif = $criteres['search'] !== ''
        || $criteres['support'] !== ''
        || $criteres['statut'] !== ''
        || !empty($criteres['tags'])
        || $criteres['date_from'] !== ''
        || $criteres['date_to'] !== ''
        || $criteres['sans_couverture']
        || $criteres['sans_note'];
}

if ($section === 'tags') {
    $tousLesTags = $bookManager->getAllTags();
}

function formatTailleOctets($octets) {
    if ($octets >= 1024 * 1024) {
        return round($octets / (1024 * 1024), 2) . ' Mo';
    }
    if ($octets >= 1024) {
        return round($octets / 1024, 1) . ' Ko';
    }
    return $octets . ' o';
}

require 'views/outils.php';
