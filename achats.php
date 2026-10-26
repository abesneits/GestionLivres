<?php
/**
 * Achats à effectuer : liste de livres à acheter, validation (ajout à la collection)
 */

require_once 'includes/bootstrap.php';

$message = '';
$messageType = '';
$flashMessage = getFlashMessage();

$champsAchat = ['isbn', 'titre', 'auteur', 'support', 'type_livre', 'serie', 'tome', 'couverture', 'description',
    'date_publication', 'tags', 'priorite', 'prix_estime', 'lieu_achat', 'note'];

// Données du formulaire (vide par défaut, pré-rempli par la recherche ISBN ou la modification)
$form = array_fill_keys($champsAchat, '');
$form['support'] = 'Livre';
$form['type_livre'] = 'Papier';
$form['priorite'] = 2;
$editId = 0;

$tri = $_GET['tri'] ?? 'priorite';
if (!in_array($tri, ['priorite', 'titre', 'auteur', 'date'], true)) {
    $tri = 'priorite';
}

// Retourne les champs du formulaire à partir de $_POST
$lireFormulaire = function () use ($champsAchat) {
    $d = [];
    foreach ($champsAchat as $c) {
        $d[$c] = $_POST[$c] ?? '';
    }
    return $d;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Token de sécurité invalide. Veuillez réessayer.";
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'search_isbn') {
                $form = array_merge($form, $lireFormulaire());
                $isbn = strtoupper(preg_replace('/[-\s]/', '', $_POST['isbn'] ?? ''));
                $erreurs = validateInput(['isbn' => $isbn], [
                    'isbn' => ['required' => true, 'label' => 'ISBN', 'isbn' => true, 'min_length' => 10, 'max_length' => 13]
                ]);
                if (!empty($erreurs)) {
                    $message = "Erreur de validation : " . implode(' ', $erreurs);
                    $messageType = 'error';
                } else {
                    $info = $bookManager->getBookInfoFromISBN($isbn);
                    $form['isbn'] = $isbn;
                    if ($info) {
                        // Les valeurs de repli de l'API ne sont pas de vraies données
                        foreach (['titre' => 'Titre non disponible', 'auteur' => 'Auteur non disponible', 'date_publication' => 'Date non disponible'] as $cle => $repli) {
                            if (($info[$cle] ?? '') !== $repli) {
                                $form[$cle] = $info[$cle] ?? '';
                            }
                        }
                        foreach (['support', 'couverture', 'description'] as $cle) {
                            if (!empty($info[$cle])) {
                                $form[$cle] = $info[$cle];
                            }
                        }
                        $message = "Informations trouvées ! Complétez puis enregistrez.";
                        $messageType = 'success';
                    } else {
                        $message = "ISBN introuvable : saisissez les informations manuellement.";
                        $messageType = 'warning';
                    }
                }
            } elseif ($action === 'save_achat') {
                $data = $lireFormulaire();
                $id = (int)($_POST['achat_id'] ?? 0);
                if (trim($data['titre']) === '') {
                    $form = array_merge($form, $data);
                    $editId = $id;
                    $message = "Le titre est obligatoire.";
                    $messageType = 'error';
                } elseif ($id > 0) {
                    $bookManager->updateAchat($id, $data);
                    redirectWithMessage('achats.php', "Achat modifié : <strong>" . h($data['titre']) . "</strong>", 'success', true);
                } else {
                    $bookManager->addAchat($data);
                    redirectWithMessage('achats.php', "Ajouté aux achats : <strong>" . h($data['titre']) . "</strong>", 'success', true);
                }
            } elseif ($action === 'valider_achat') {
                $statut = in_array($_POST['statut'] ?? '', ['À lire', 'En cours', 'Lu'], true) ? $_POST['statut'] : 'À lire';
                $achat = $bookManager->validerAchat((int)($_POST['achat_id'] ?? 0), $statut);
                redirectWithMessage('achats.php', "Achat validé, ajouté à votre collection : <strong>" . h($achat['titre']) . "</strong>", 'success', true);
            } elseif ($action === 'delete_achat') {
                $bookManager->deleteAchat((int)($_POST['achat_id'] ?? 0));
                redirectWithMessage('achats.php', "Achat supprimé de la liste.", 'success');
            }
        } catch (Exception $e) {
            $message = "Erreur : " . messageErreur($e);
            $messageType = 'error';
            if (function_exists('logMessage')) {
                logMessage("Achats error: " . $e->getMessage(), 'ERROR');
            }
        }
    }
}

// Mode modification (?edit=ID), sans écraser un formulaire déjà renvoyé en erreur
if (isset($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $achatEdit = $bookManager->getAchat((int)$_GET['edit']);
    if ($achatEdit) {
        $editId = (int)$achatEdit['id'];
        foreach ($champsAchat as $c) {
            $form[$c] = $achatEdit[$c] ?? '';
        }
    }
}

try {
    $achats = $bookManager->getAchats($tri);
} catch (Exception $e) {
    $achats = [];
}
$totalEstime = 0.0;
foreach ($achats as $a) {
    $totalEstime += (float)$a['prix_estime'];
}

try {
    $allTags = $bookManager->getAllTags();
} catch (Exception $e) {
    $allTags = [];
}
try {
    $supportsDispo = array_keys($bookManager->getSupportTypesWithCounts());
} catch (Exception $e) {
    $supportsDispo = ['Livre', 'Bande dessinée', 'Manga'];
}
if ($form['support'] !== '' && !in_array($form['support'], $supportsDispo, true)) {
    $supportsDispo[] = $form['support'];
}

$allAuteurs = $bookManager->getAuthorNames();
$rolesAuteurs = $bookManager->getAuthorRoles();
require 'views/achats.php';
