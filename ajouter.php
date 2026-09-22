<?php
/**
 * Page d'ajout de livre - Version avec formulaire pré-rempli et upload
 * Version corrigée pour résoudre les problèmes de session et recherche ISBN
 */

require_once 'includes/bootstrap.php';

$message = '';
$messageType = '';
$flashMessage = getFlashMessage();
$bookData = null;
$step = 1; // 1 = recherche ISBN, 2 = formulaire pré-rempli

// Vérifier si nous devons passer directement à l'étape 2 (retour depuis une erreur)
if (isset($_POST['step']) && $_POST['step'] == '2') {
    $step = 2;
    $bookData = [
        'isbn' => $_POST['isbn'] ?? '',
        'titre' => $_POST['titre'] ?? '',
        'auteur' => $_POST['auteur'] ?? '',
        'serie' => $_POST['serie'] ?? '',
        'tome' => $_POST['tome'] ?? '',
        'support' => $_POST['support'] ?? 'Livre',
        'type_livre' => $_POST['type_livre'] ?? 'Papier',
        'format_numerique' => $_POST['format_numerique'] ?? '',
        'description' => $_POST['description'] ?? '',
        'date_publication' => $_POST['date_publication'] ?? '',
        'couverture' => $_POST['couverture_url'] ?? null,
        'statut' => $_POST['statut'] ?? 'À lire',
        'tags' => $_POST['tags'] ?? ''
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Token de sécurité invalide. Veuillez réessayer.";
        $messageType = 'error';
    } else {

        // Étape 1 : Recherche par ISBN
        if (isset($_POST['action']) && $_POST['action'] === 'search_isbn') {
            if (!empty($_POST['isbn']) && $_POST['isbn'] !== 'MANUAL') {
                $validationRules = [
                    'isbn' => [
                        'required' => true,
                        'label' => 'ISBN',
                        'isbn' => true,
                        'min_length' => 10,
                        'max_length' => 13
                    ]
                ];

                $errors = validateInput($_POST, $validationRules);

                if (!empty($errors)) {
                    $message = "Erreur de validation : " . implode(' ', $errors);
                    $messageType = 'error';
                } else {
                    try {
                        // Nettoyer l'ISBN (enlever les tirets et espaces)
                        $cleanISBN = preg_replace('/[-\s]/', '', $_POST['isbn']);

                        // Debug : afficher l'ISBN recherché
                        if (function_exists('logMessage')) {
                            logMessage("Searching ISBN: " . $cleanISBN, 'DEBUG');
                        }

                        $bookData = $bookManager->getBookInfoFromISBN($cleanISBN);

                        if ($bookData) {
                            $step = 2;
                            $message = "Informations trouvées ! Vous pouvez maintenant les modifier si nécessaire.";
                            $messageType = 'success';
                            if (function_exists('logMessage')) {
                                logMessage("ISBN search successful for: " . $cleanISBN, 'INFO');
                            }
                        } else {
                            $message = "Impossible de trouver les informations pour cet ISBN : " . h($cleanISBN) . ". Vous pouvez les saisir manuellement.";
                            $messageType = 'warning';
                            if (function_exists('logMessage')) {
                                logMessage("ISBN not found: " . $cleanISBN, 'INFO');
                            }

                            // Créer des données vides pour le formulaire manuel
                            $bookData = [
                                'isbn' => $cleanISBN,
                                'titre' => '',
                                'auteur' => '',
                                'support' => 'Livre',
                                'description' => '',
                                'date_publication' => '',
                                'couverture' => null,
                                'statut' => 'À lire',
                                'tags' => ''
                            ];
                            $step = 2;
                        }
                    } catch (Exception $e) {
                        $message = "Erreur lors de la recherche : " . $e->getMessage();
                        $messageType = 'error';
                        if (function_exists('logMessage')) {
                            logMessage("ISBN search error: " . $e->getMessage(), 'ERROR');
                        }

                        // En cas d'erreur, passer au formulaire manuel avec l'ISBN
                        $cleanISBN = preg_replace('/[-\s]/', '', $_POST['isbn']);
                        $bookData = [
                            'isbn' => $cleanISBN,
                            'titre' => '',
                            'auteur' => '',
                            'support' => 'Livre',
                            'description' => '',
                            'date_publication' => '',
                            'couverture' => null,
                            'statut' => 'À lire',
                            'tags' => ''
                        ];
                        $step = 2;
                    }
                }
            } else {
                // Saisie manuelle sans ISBN
                $bookData = [
                    'isbn' => 'MANUAL_' . time(), // ISBN temporaire
                    'titre' => '',
                    'auteur' => '',
                    'support' => 'Livre',
                    'description' => '',
                    'date_publication' => '',
                    'couverture' => null,
                    'statut' => 'À lire',
                    'tags' => ''
                ];
                $step = 2;
                $message = "Saisie manuelle activée. Veuillez remplir tous les champs.";
                $messageType = 'info';
            }
        }

        // Étape 2 : Validation et ajout du livre
        if (isset($_POST['action']) && $_POST['action'] === 'add_book_final') {
            $validationRules = [
                'titre' => ['required' => true, 'label' => 'Titre', 'min_length' => 1],
                'auteur' => ['required' => true, 'label' => 'Auteur', 'min_length' => 1],
                'support' => ['required' => true, 'label' => 'Support']
            ];

            // ISBN obligatoire seulement si pas en mode manuel
            if (!empty($_POST['isbn']) && !str_starts_with($_POST['isbn'], 'MANUAL_')) {
                $validationRules['isbn'] = ['required' => true, 'label' => 'ISBN'];
            }

            $errors = validateInput($_POST, $validationRules);

            if (!empty($errors)) {
                $message = "Erreur de validation : " . implode(' ', $errors);
                $messageType = 'error';
                // Reconstituer les données pour rester à l'étape 2
                $bookData = [
                    'isbn' => $_POST['isbn'],
                    'titre' => $_POST['titre'],
                    'auteur' => $_POST['auteur'],
                    'serie' => $_POST['serie'] ?? '',
                    'tome' => $_POST['tome'] ?? '',
                    'support' => $_POST['support'],
                    'type_livre' => $_POST['type_livre'] ?? 'Papier',
                    'format_numerique' => $_POST['format_numerique'] ?? '',
                    'description' => $_POST['description'],
                    'date_publication' => $_POST['date_publication'],
                    'couverture' => $_POST['couverture_url'] ?? null,
                    'statut' => $_POST['statut'] ?? 'À lire',
                    'tags' => $_POST['tags'] ?? ''
                ];
                $step = 2;
            } else {
                try {
                    // Gestion de l'upload de fichier
                    $couverture_finale = $_POST['couverture_url'] ?? null;

                    // Gestion de l'upload du fichier numérique (ebook)
                    $type_livre = ($_POST['type_livre'] ?? 'Papier') === 'Numérique' ? 'Numérique' : 'Papier';
                    $format_numerique = $type_livre === 'Numérique' ? ($_POST['format_numerique'] ?? '') : '';
                    $fichier_numerique_final = null;

                    if ($type_livre === 'Numérique' && isset($_FILES['fichier_numerique']) && $_FILES['fichier_numerique']['error'] === UPLOAD_ERR_OK) {
                        $fichier_numerique_final = $bookManager->uploadFichierNumerique($_FILES['fichier_numerique'], $format_numerique);
                    }

                    if (isset($_FILES['couverture_fichier']) && $_FILES['couverture_fichier']['error'] === UPLOAD_ERR_OK) {
                        // Créer le dossier uploads s'il n'existe pas
                        $uploadDir = 'uploads/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }

                        // Valider le fichier
                        if (isValidImage($_FILES['couverture_fichier'])) {
                            $extension = strtolower(pathinfo($_FILES['couverture_fichier']['name'], PATHINFO_EXTENSION));
                            $nomFichier = 'couverture_' . time() . '_' . uniqid() . '.' . $extension;
                            $cheminFichier = $uploadDir . $nomFichier;

                            if (move_uploaded_file($_FILES['couverture_fichier']['tmp_name'], $cheminFichier)) {
                                $couverture_finale = $cheminFichier;
                            } else {
                                throw new Exception("Erreur lors de l'upload du fichier");
                            }
                        } else {
                            throw new Exception("Fichier image invalide");
                        }
                    }

                    // Préparer les données du livre
                    $finalBookData = [
                        'isbn' => str_starts_with($_POST['isbn'], 'MANUAL_') ? '' : $_POST['isbn'],
                        'titre' => $_POST['titre'],
                        'auteur' => $_POST['auteur'],
                        'serie' => $_POST['serie'] ?? '',
                        'tome' => $_POST['tome'] ?? '',
                        'support' => $_POST['support'],
                        'type_livre' => $type_livre,
                        'format_numerique' => $format_numerique,
                        'fichier_numerique' => $fichier_numerique_final,
                        'description' => $_POST['description'],
                        'date_publication' => $_POST['date_publication'],
                        'couverture' => $couverture_finale,
                        'statut' => $_POST['statut'] ?? 'À lire',
                        'tags' => $_POST['tags'] ?? ''
                    ];

                    // Si pas d'ISBN, en générer un temporaire
                    if (empty($finalBookData['isbn'])) {
                        $finalBookData['isbn'] = 'TEMP_' . time() . '_' . uniqid();
                    }

                    $result = $bookManager->addBook($finalBookData);

                    if ($result) {
                        $successMessage = "Livre ajouté avec succès : <strong>" . h($finalBookData['titre']) . "</strong>";
                        if ($finalBookData['couverture']) {
                            $successMessage .= "<br>Couverture incluse";
                        }
                        if ($finalBookData['fichier_numerique']) {
                            $successMessage .= "<br>Fichier numérique (" . h($finalBookData['format_numerique']) . ") inclus";
                        }

                        // Redirection avec message flash
                        redirectWithMessage('ajouter.php', $successMessage, 'success');
                        exit(); // Important : arrêter l'exécution après redirection
                    } else {
                        throw new Exception("Erreur lors de l'enregistrement du livre");
                    }

                } catch (Exception $e) {
                    $message = "Erreur lors de l'ajout : " . $e->getMessage();
                    $messageType = 'error';
                    if (function_exists('logMessage')) {
                        logMessage("Add book error: " . $e->getMessage(), 'ERROR');
                    }

                    // Reconstituer les données pour rester à l'étape 2
                    $bookData = [
                        'isbn' => $_POST['isbn'],
                        'titre' => $_POST['titre'],
                        'auteur' => $_POST['auteur'],
                        'serie' => $_POST['serie'] ?? '',
                        'tome' => $_POST['tome'] ?? '',
                        'support' => $_POST['support'],
                        'type_livre' => $_POST['type_livre'] ?? 'Papier',
                        'format_numerique' => $_POST['format_numerique'] ?? '',
                        'description' => $_POST['description'],
                        'date_publication' => $_POST['date_publication'],
                        'couverture' => $_POST['couverture_url'] ?? null,
                        'statut' => $_POST['statut'] ?? 'À lire',
                        'tags' => $_POST['tags'] ?? ''
                    ];
                    $step = 2;
                }
            }
        }
    }
}

// Récupérer les statistiques pour l'affichage
try {
    $stats = $bookManager->getStats();
} catch (Exception $e) {
    if (function_exists('logMessage')) {
        logMessage("Stats error: " . $e->getMessage(), 'ERROR');
    }
    $stats = ['total' => 0]; // Valeurs par défaut en cas d'erreur
}

// Récupérer les tags existants pour les suggestions de saisie
try {
    $allTags = $bookManager->getAllTags();
} catch (Exception $e) {
    $allTags = [];
}

// Formats numériques disponibles (pour le champ Type = Numérique)
try {
    $allFormatsNumeriques = $bookManager->getFormatsNumeriquesWithCounts();
    $allExtensionsNumeriques = $bookManager->getAllExtensionsNumeriques();
} catch (Exception $e) {
    $allFormatsNumeriques = [];
    $allExtensionsNumeriques = [];
}

require 'views/ajouter.php';
