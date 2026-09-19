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
        'support' => $_POST['support'] ?? 'Livre',
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
                    'support' => $_POST['support'],
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
                        'support' => $_POST['support'],
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
                        'support' => $_POST['support'],
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
?>

<!DOCTYPE html>
<html lang="fr">
<?php 
include 'includes/head.php'; 
renderHead('Ajouter un livre - Ma Collection'); 
?>
<body>
    <?php include 'includes/menu.php'; ?>
    
    <div class="container">
                
        <?php 
        if ($flashMessage) {
            echo $flashMessage;
        } elseif ($message) {
            echo showMessage($message, $messageType);
        }
        ?>
        
        <!-- Indicateur d'étape -->
        <div class="steps-indicator">
            <div class="step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'completed' : '' ?>">
                <span class="step-number">1</span>
                <span class="step-label">Recherche ISBN</span>
            </div>
            <div class="step <?= $step >= 2 ? 'active' : '' ?>">
                <span class="step-number">2</span>
                <span class="step-label">Informations du livre</span>
            </div>
        </div>
        
        <?php if ($step === 1): ?>
            <!-- Étape 1: Recherche par ISBN -->
            <div class="add-form-section">
                <div class="form-card">
                    <h2>Recherche par ISBN</h2>
                    <p class="form-description">Saisissez l'ISBN pour rechercher automatiquement les informations du livre.</p>
                    
                    <form method="POST" class="add-form" id="searchForm">
                        <input type="hidden" name="action" value="search_isbn">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        
                        <div class="form-group">
                            <label for="isbn">Code ISBN :</label>
                            <input type="text" 
                                   id="isbn" 
                                   name="isbn" 
                                   placeholder="Ex: 9782070564123 ou 978-2-07-056-412-3" 
                                   maxlength="17"
                                   pattern="[0-9X\-\s]{10,17}"
                                   class="isbn-input"
                                   value="<?= h($_POST['isbn'] ?? '') ?>">
                            <small>Vous pouvez saisir l'ISBN avec ou sans tirets (10 ou 13 chiffres)</small>
                            <span id="isbn-error" class="form-validation-error" style="display: none;"></span>
                        </div>
                        
                        <button type="submit" class="btn-add-book" id="searchBtn">
                            <span class="btn-text">Rechercher les informations</span>
                            <span class="loading" style="display: none;">Recherche en cours...</span>
                        </button>
                    </form>
                    
                    <div class="manual-add-option">
                        <p>ou</p>
                        <button type="button" class="btn-secondary" onclick="goToManualAdd()">Ajouter sans ISBN (saisie manuelle)</button>
                    </div>
                </div>
                
                <!-- Exemples d'ISBN -->
                <div class="isbn-examples">
                    <h3>Exemples d'ISBN populaires pour tester :</h3>
                    <div class="example-books">
                        <div class="example-item">
                            <strong>Le Petit Prince</strong><br>
                            <code class="example-isbn" onclick="fillISBN('9782070612758')">9782070612758</code>
                        </div>
                        <div class="example-item">
                            <strong>Les Misérables</strong><br>
                            <code class="example-isbn" onclick="fillISBN('9782253002864')">9782253002864</code>
                        </div>
                        <div class="example-item">
                            <strong>L'Étranger</strong><br>
                            <code class="example-isbn" onclick="fillISBN('9782070360024')">9782070360024</code>
                        </div>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Étape 2: Formulaire complet -->
            <div class="add-form-section">
                <div class="form-card-large">
                    <div class="form-header">
                        <h2>Informations du livre</h2>
                        <?php if (isset($bookData['isbn']) && !str_starts_with($bookData['isbn'], 'MANUAL_')): ?>
                            <p class="form-description">Informations récupérées pour l'ISBN <strong><?= h($bookData['isbn']) ?></strong>. Vous pouvez les modifier.</p>
                        <?php else: ?>
                            <p class="form-description">Saisissez manuellement les informations du livre.</p>
                        <?php endif; ?>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="book-details-form" id="addBookForm">
                        <input type="hidden" name="action" value="add_book_final">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="isbn" value="<?= h($bookData['isbn'] ?? '') ?>">
                        <input type="hidden" name="step" value="2">
                        
                        <div class="form-layout">
                            <div class="form-left">
                                <div class="form-group">
                                    <label for="titre">Titre * :</label>
                                    <input type="text" 
                                           id="titre" 
                                           name="titre" 
                                           value="<?= h($bookData['titre'] ?? '') ?>" 
                                           required
                                           maxlength="255"
                                           placeholder="Titre du livre">
                                </div>
                                
                                <div class="form-group">
                                    <label for="auteur">Auteur * :</label>
                                    <input type="text" 
                                           id="auteur" 
                                           name="auteur" 
                                           value="<?= h($bookData['auteur'] ?? '') ?>" 
                                           required
                                           maxlength="255"
                                           placeholder="Nom de l'auteur">
                                </div>
                                
                                <div class="form-group">
                                    <label for="support">Support * :</label>
                                    <select id="support" name="support" required>
                                        <?php foreach (array_keys($bookManager->getSupportTypesWithCounts()) as $supportOption): ?>
                                            <option value="<?= h($supportOption) ?>" <?= ($bookData['support'] ?? 'Livre') === $supportOption ? 'selected' : '' ?>><?= h($supportOption) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="statut">Statut de lecture :</label>
                                    <select id="statut" name="statut">
                                        <option value="À lire" <?= ($bookData['statut'] ?? 'À lire') === 'À lire' ? 'selected' : '' ?>>À lire</option>
                                        <option value="En cours" <?= ($bookData['statut'] ?? '') === 'En cours' ? 'selected' : '' ?>>En cours</option>
                                        <option value="Lu" <?= ($bookData['statut'] ?? '') === 'Lu' ? 'selected' : '' ?>>Lu</option>
                                        <option value="Abandonné" <?= ($bookData['statut'] ?? '') === 'Abandonné' ? 'selected' : '' ?>>Abandonné</option>
                                    </select>
                                    <small>Vous pouvez définir votre statut de lecture dès l'ajout</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="date_publication">Date de publication :</label>
                                    <input type="text" 
                                           id="date_publication" 
                                           name="date_publication" 
                                           value="<?= h($bookData['date_publication'] ?? '') ?>" 
                                           placeholder="Ex: 2023, 2023-05, 2023-05-15">
                                    <small>Format libre : année, mois-année, ou date complète</small>
                                </div>
                            </div>
                            
                            <div class="form-right">
                                <div class="cover-section">
                                    <label>Couverture :</label>
                                    <?php if (!empty($bookData['couverture'])): ?>
                                        <div class="current-cover-preview">
                                            <img src="<?= h($bookData['couverture']) ?>" 
                                                 alt="Couverture" 
                                                 id="coverPreview"
                                                 onerror="showNoCover();">
                                        </div>
                                    <?php else: ?>
                                        <div class="no-cover" id="noCover">Aucune couverture</div>
                                    <?php endif; ?>
                                    
                                    <input type="hidden" 
                                           name="couverture_url" 
                                           value="<?= h($bookData['couverture'] ?? '') ?>" 
                                           id="couvertureUrl">
                                    
                                    <div class="cover-actions">
                                        <label for="couverture_fichier" class="upload-label">
                                            Choisir un fichier depuis votre ordinateur
                                        </label>
                                        <input type="file" 
                                               id="couverture_fichier" 
                                               name="couverture_fichier" 
                                               accept="image/*" 
                                               onchange="previewFile(this)">
                                        
                                        <div class="separator">ou</div>
                                        
                                        <input type="url" 
                                               id="custom_cover_url" 
                                               placeholder="URL d'une couverture en ligne..." 
                                               class="cover-url-input">
                                        <button type="button" 
                                                class="btn-secondary" 
                                                onclick="updateCoverFromURL()">Charger depuis URL</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="description">Description :</label>
                            <textarea id="description" 
                                      name="description" 
                                      rows="4" 
                                      maxlength="2000"
                                      placeholder="Résumé, synopsis ou description du livre..."><?= h($bookData['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group full-width">
                            <label for="tagsInputField">Tags :</label>
                            <div class="tag-input-container" id="tagsContainer">
                                <input type="text" class="tag-input-field" id="tagsInputField" placeholder="Ajouter un tag...">
                            </div>
                            <input type="hidden" name="tags" id="tags" value="<?= h($bookData['tags'] ?? '') ?>">
                            <small>Appuyez sur Entrée ou virgule pour ajouter un tag. Exemples : favori, à relire, série...</small>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn-secondary" onclick="backToSearch()">Retour</button>
                            <button type="submit" class="btn-add-book">Ajouter le livre</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Actions rapides -->
        <div class="quick-actions-bottom">
            <a href="index.php" class="btn-secondary">
                Voir ma collection (<?= number_format($stats['total'] ?? 0) ?> livres)
            </a>
        </div>
    </div>

    <script src="tag-input.js"></script>
    <script>
        let currentStep = <?= $step ?>;
        const allTags = <?= json_encode($allTags, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        
        function fillISBN(isbn) {
            const input = document.getElementById('isbn');
            if (input) {
                input.value = isbn;
                input.focus();
            }
        }
        
        function goToManualAdd() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="search_isbn">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="isbn" value="MANUAL">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        
        function backToSearch() {
            window.location.href = 'ajouter.php';
        }
        
        // Preview du fichier uploadé
        function previewFile(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    showCoverPreview(e.target.result);
                    // Vider l'URL si on upload un fichier
                    document.getElementById('couvertureUrl').value = '';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Charger depuis URL
        function updateCoverFromURL() {
            const urlInput = document.getElementById('custom_cover_url');
            const newUrl = urlInput.value.trim();
            
            if (newUrl) {
                const testImg = new Image();
                testImg.onload = function() {
                    showCoverPreview(newUrl);
                    document.getElementById('couvertureUrl').value = newUrl;
                    // Reset du fichier
                    document.getElementById('couverture_fichier').value = '';
                    urlInput.value = '';
                };
                testImg.onerror = function() {
                    alert('Impossible de charger cette image. Vérifiez l\'URL.');
                };
                testImg.src = newUrl;
            } else {
                alert('Veuillez saisir une URL valide');
            }
        }
        
        // Afficher preview de couverture
        function showCoverPreview(src) {
            const coverSection = document.querySelector('.cover-section');
            const existingPreview = coverSection.querySelector('.current-cover-preview');
            const noCoverDiv = coverSection.querySelector('.no-cover');
            
            if (existingPreview) {
                existingPreview.querySelector('img').src = src;
            } else if (noCoverDiv) {
                noCoverDiv.outerHTML = `<div class="current-cover-preview"><img src="${src}" alt="Couverture" id="coverPreview"></div>`;
            }
        }
        
        // Fallback si image ne charge pas
        function showNoCover() {
            const preview = document.querySelector('.current-cover-preview');
            if (preview) {
                preview.outerHTML = '<div class="no-cover" id="noCover">Couverture indisponible</div>';
            }
        }
        
        // Auto-focus approprié
        document.addEventListener('DOMContentLoaded', function() {
            if (currentStep === 1) {
                const isbnInput = document.getElementById('isbn');
                if (isbnInput && !isbnInput.value) {
                    isbnInput.focus();
                }
            } else if (currentStep === 2) {
                const titreInput = document.getElementById('titre');
                if (titreInput && !titreInput.value.trim()) {
                    titreInput.focus();
                }

                const tagsContainer = document.getElementById('tagsContainer');
                const tagsHidden = document.getElementById('tags');
                if (tagsContainer && tagsHidden) {
                    initTagInput(tagsContainer, tagsHidden, allTags);
                }
            }
        });
        
        // Validation ISBN améliorée
        const isbnInput = document.getElementById('isbn');
        if (isbnInput) {
            isbnInput.addEventListener('input', function(e) {
                // Permettre chiffres, X/x, tirets et espaces
                let value = e.target.value.replace(/[^0-9X\-\s]/gi, '').replace(/x/g, 'X');
                // Limiter la longueur (17 caractères max avec tirets)
                if (value.replace(/[-\s]/g, '').length > 13) {
                    value = value.substring(0, value.lastIndexOf(value.charAt(value.length - 1)));
                }
                e.target.value = value;
                
                // Validation visuelle
                const cleanISBN = value.replace(/[-\s]/g, '');
                const errorSpan = document.getElementById('isbn-error');
                if (cleanISBN.length > 0 && cleanISBN.length < 10) {
                    errorSpan.textContent = 'ISBN trop court (minimum 10 caractères)';
                    errorSpan.style.display = 'block';
                } else if (cleanISBN.length > 13) {
                    errorSpan.textContent = 'ISBN trop long (maximum 13 caractères)';
                    errorSpan.style.display = 'block';
                } else {
                    errorSpan.style.display = 'none';
                }
            });
        }
        
        // Validation finale du formulaire
        document.getElementById('addBookForm')?.addEventListener('submit', function(e) {
            const titre = document.getElementById('titre').value.trim();
            const auteur = document.getElementById('auteur').value.trim();
            
            if (!titre || !auteur) {
                e.preventDefault();
                alert('Veuillez remplir au minimum le titre et l\'auteur.');
                return false;
            }
        });
        
        // Loading state amélioré
        document.getElementById('searchForm')?.addEventListener('submit', function(e) {
            const btn = document.getElementById('searchBtn');
            const btnText = btn.querySelector('.btn-text');
            const loading = btn.querySelector('.loading');
            const isbn = document.getElementById('isbn').value.trim();
            
            // Validation côté client
            if (isbn && isbn !== 'MANUAL') {
                const cleanISBN = isbn.replace(/[-\s]/g, '');
                if (cleanISBN.length < 10 || cleanISBN.length > 13) {
                    e.preventDefault();
                    alert('Veuillez saisir un ISBN valide (10 ou 13 caractères)');
                    return false;
                }
            }
            
            // Afficher l'état de chargement
            btn.disabled = true;
            btnText.style.display = 'none';
            loading.style.display = 'inline-block';
        });
        
        // Réactiver le bouton en cas d'erreur de validation côté client
        window.addEventListener('beforeunload', function() {
            const btn = document.getElementById('searchBtn');
            if (btn && btn.disabled) {
                btn.disabled = false;
                const btnText = btn.querySelector('.btn-text');
                const loading = btn.querySelector('.loading');
                if (btnText && loading) {
                    btnText.style.display = 'inline';
                    loading.style.display = 'none';
                }
            }
        });
    </script>
</body>
</html>