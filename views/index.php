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
        // Afficher le message flash ou le message de session
        if ($flashMessage) {
            echo $flashMessage;
        } elseif ($message) {
            echo showMessage($message, $messageType);
        }
        ?>
               
        <!-- Recherche et filtres améliorés -->
        <div class="search-section">
            <h3>Rechercher et Filtrer</h3>
            <form method="GET" class="search-row" id="searchForm">
                <div class="search-group">
                    <label>Recherche</label>
                    <input type="text" 
                           name="search" 
                           value="<?= h($search) ?>" 
                           placeholder="Titre, auteur, ISBN..."
                           maxlength="100">
                </div>
                <div class="search-group">
                    <label>Support</label>
                    <select name="filter">
                        <option value="">Tous</option>
                        <?php foreach (array_keys($bookManager->getSupportTypesWithCounts()) as $supportOption): ?>
                            <option value="<?= h($supportOption) ?>" <?= $filter === $supportOption ? 'selected' : '' ?>><?= h($supportOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label>Statut</label>
                    <select name="statut">
                        <option value="">Tous</option>
                        <option value="À lire" <?= $statut === 'À lire' ? 'selected' : '' ?>>À lire</option>
                        <option value="En cours" <?= $statut === 'En cours' ? 'selected' : '' ?>>En cours</option>
                        <option value="Lu" <?= $statut === 'Lu' ? 'selected' : '' ?>>Lu</option>
                        <option value="Abandonné" <?= $statut === 'Abandonné' ? 'selected' : '' ?>>Abandonné</option>
                    </select>
                </div>
                <div class="search-group">
                    <label>Tag</label>
                    <select name="tag">
                        <option value="">Tous les tags</option>
                        <?php foreach ($allTags as $tagOption): ?>
                            <option value="<?= h($tagOption) ?>" <?= $tag === $tagOption ? 'selected' : '' ?>>
                                <?= h($tagOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($allSeries)): ?>
                <div class="search-group">
                    <label>Série</label>
                    <select name="serie">
                        <option value="">Toutes les séries</option>
                        <?php foreach ($allSeries as $nomSerie => $nbSerie): ?>
                            <option value="<?= h($nomSerie) ?>" <?= $serie === $nomSerie ? 'selected' : '' ?>>
                                <?= h($nomSerie) ?> (<?= (int)$nbSerie ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <input type="hidden" name="view" value="<?= h($view) ?>">
                <button type="submit" class="btn-search">Filtrer</button>
            </form>
            
            <?php if ($search || $filter || $tag || $statut || $serie): ?>
                <div style="margin-top: 10px;">
                    <a href="?" class="btn-secondary" style="font-size: 0.9em;">Réinitialiser les filtres</a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Actions et sélecteur de vue -->
        <div class="actions-bar">
            <div class="view-selector">
                <a href="<?= buildUrl(['view' => 'grid']) ?>" 
                   class="view-btn <?= $view === 'grid' ? 'active' : '' ?>">Grille</a>
                <a href="<?= buildUrl(['view' => 'table']) ?>" 
                   class="view-btn <?= $view === 'table' ? 'active' : '' ?>">Tableau</a>
            </div>
            <div>
                <a href="<?= h('export.php?' . http_build_query(array_filter([
                    'search' => $search,
                    'support' => $filter,
                    'statut' => $statut,
                    'serie' => $serie,
                    'tags' => $tag ? [$tag] : [],
                ]))) ?>" class="btn-export">📤 Exporter</a>
            </div>
        </div>
        
        <!-- Informations sur les résultats -->
        <div class="results-header">
            <h2>
                Résultats 
                <?php if ($search || $filter || $tag || $statut || $serie): ?>
                    filtrés 
                <?php endif; ?>
                (<?= number_format($totalBooks) ?> livre<?= $totalBooks > 1 ? 's' : '' ?>)
            </h2>
            
            <?php if ($paginationInfo['total_pages'] > 1): ?>
                <p class="results-summary">
                    Page <?= $paginationInfo['current_page'] ?> sur <?= $paginationInfo['total_pages'] ?> 
                    - Affichage de <?= $paginationInfo['start_item'] ?> à <?= $paginationInfo['end_item'] ?> résultats
                </p>
            <?php endif; ?>
        </div>
        
        <?php if (empty($books)): ?>
            <div class="empty-message">
                <p>Aucun livre trouvé avec ces critères.</p>
                <?php if ($search || $filter || $tag || $statut || $serie): ?>
                    <p><a href="?" style="color: #007bff;">Réinitialiser les filtres</a></p>
                <?php else: ?>
                    <p><a href="ajouter.php" style="color: #007bff;">Ajouter votre premier livre</a></p>
                <?php endif; ?>
            </div>
        <?php elseif ($view === 'grid'): ?>
            <!-- Vue en grille -->
            <div class="books-grid">
                <?php foreach ($books as $book): ?>
                    <div class="book-card">
                                            
                        <div class="book-card-header">
                            <div class="book-card-cover">
                                <?php $imageUrl = getImageUrl($book['couverture']); ?>
                                <img src="<?= h($imageUrl) ?>" 
                                     alt="Couverture de <?= h($book['titre']) ?>"
                                     onerror="this.parentElement.innerHTML='<div class=&quot;cover-placeholder&quot;>📚</div>';">
                            </div>
                            
                            <div class="book-card-info">
                                <h3 class="book-card-title"><?= h($book['titre']) ?></h3>
                                <div class="book-card-author">par <?= h($book['auteur']) ?></div>
                                <?php if (!empty($book['serie'])): ?>
                                    <div class="book-card-serie">
                                        📖 <a href="<?= h(buildUrl(['serie' => $book['serie'], 'page' => 1])) ?>"><?= h($book['serie']) ?></a><?= $book['tome'] !== null ? ' · tome ' . (int)$book['tome'] : '' ?>
                                    </div>
                                <?php endif; ?>
                                <div class="book-card-meta">
                                    <span class="book-card-support <?= getSupportClass($book['support']) ?>">
                                        <?= h($book['support']) ?>
                                    </span>
                                    <span class="book-card-statut <?= getStatusClass($book['statut'] ?? 'À lire') ?>">
                                        <?= h($book['statut'] ?? 'À lire') ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($book['description']): ?>
                            <div class="book-card-description">
                                <?= truncateText($book['description'], 200) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($book['tags']): ?>
                            <div class="book-card-tags">
                                <?= displayTags($book['tags'], 3) ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="book-card-actions">
                            <a href="<?= buildUrl(['edit' => $book['id']]) ?>" class="btn-edit">Éditer</a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Supprimer ce livre ?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="book_id" value="<?= $book['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <button type="submit" class="btn-danger">🗑️</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Vue tableau -->
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Couverture</th>
                            <th>Titre</th>
                            <th>Auteur</th>
                            <th>Support</th>
                            <th>Statut</th>
                            <th>Tags</th>
                            <th>Ajouté le</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($books as $book): ?>
                            <tr>
                                <td>
                                    <?php $imageUrl = getImageUrl($book['couverture']); ?>
                                    <img src="<?= h($imageUrl) ?>" 
                                         alt="Couverture de <?= h($book['titre']) ?>" 
                                         class="cover-image"
                                         onerror="this.parentElement.innerHTML='<div class=&quot;cover-placeholder&quot;>📚</div>';">
                                </td>
                                <td>
                                    <strong><?= h($book['titre']) ?></strong>
                                    <?php if (!empty($book['serie'])): ?>
                                        <br><small class="book-card-serie">📖 <a href="<?= h(buildUrl(['serie' => $book['serie'], 'page' => 1])) ?>"><?= h($book['serie']) ?></a><?= $book['tome'] !== null ? ' · tome ' . (int)$book['tome'] : '' ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($book['auteur']) ?></td>
                                <td>
                                    <span class="<?= getSupportClass($book['support']) ?>">
                                        <?= h($book['support']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="book-card-statut <?= getStatusClass($book['statut'] ?? 'À lire') ?>">
                                        <?= h($book['statut'] ?? 'À lire') ?>
                                    </span>
                                </td>
                                <td>
                                    <?= displayTags($book['tags'], 2) ?>
                                </td>
                                <td><?= formatDate($book['date_ajout']) ?></td>
                                <td>
                                    <a href="<?= buildUrl(['edit' => $book['id']]) ?>" class="btn-edit">✏️</a>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Supprimer ce livre ?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="book_id" value="<?= $book['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <button type="submit" class="btn-danger">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?= generatePagination($paginationInfo) ?>
    </div>
    
    <!-- Modal d'édition -->
    <?php if ($editBook): ?>
        <div id="editModal" class="modal-overlay">
            <div class="modal-content">
                <button onclick="closeModal()" class="modal-close" title="Fermer">×</button>
                
                <h3>Éditer : <?= h($editBook['titre']) ?></h3>
                
                <form method="POST" enctype="multipart/form-data" id="editForm">
                    <input type="hidden" name="action" value="update_notes">
                    <input type="hidden" name="book_id" value="<?= $editBook['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
<div class="form-group">
    <label for="titre">Titre :</label>
    <input type="text" 
           id="titre" 
           name="titre" 
           value="<?= h($editBook['titre']) ?>" 
           required
           maxlength="255">
</div>

<div class="form-group">
    <label for="auteur">Auteur :</label>
    <input type="text" 
           id="auteur" 
           name="auteur" 
           value="<?= h($editBook['auteur']) ?>"
           required
           maxlength="255">
</div>

<div class="form-group">
    <label for="serie">Série :</label>
    <input type="text"
           id="serie"
           name="serie"
           value="<?= h($editBook['serie'] ?? '') ?>"
           maxlength="255"
           list="liste-series"
           placeholder="Ex : Cycle de Fondation, One Piece...">
    <datalist id="liste-series">
        <?php foreach (array_keys($allSeries) as $nomSerie): ?>
            <option value="<?= h($nomSerie) ?>">
        <?php endforeach; ?>
    </datalist>
</div>

<div class="form-group">
    <label for="tome">Numéro de tome :</label>
    <input type="number"
           id="tome"
           name="tome"
           value="<?= h($editBook['tome'] ?? '') ?>"
           min="0"
           max="999999"
           step="1"
           placeholder="Laisser vide si hors série">
</div>

                    <div class="form-group">
                        <label for="support">Support :</label>
                        <select id="support" name="support" required>
                            <?php foreach (array_keys($bookManager->getSupportTypesWithCounts()) as $supportOption): ?>
                                <option value="<?= h($supportOption) ?>" <?= $editBook['support'] === $supportOption ? 'selected' : '' ?>><?= h($supportOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Vous pouvez modifier le type de support si la détection automatique était incorrecte</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="statut">Statut de lecture :</label>
                        <select id="statut" name="statut" required>
                            <option value="À lire" <?= ($editBook['statut'] ?? 'À lire') === 'À lire' ? 'selected' : '' ?>>À lire</option>
                            <option value="En cours" <?= ($editBook['statut'] ?? '') === 'En cours' ? 'selected' : '' ?>>En cours</option>
                            <option value="Lu" <?= ($editBook['statut'] ?? '') === 'Lu' ? 'selected' : '' ?>>Lu</option>
                            <option value="Abandonné" <?= ($editBook['statut'] ?? '') === 'Abandonné' ? 'selected' : '' ?>>Abandonné</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description :</label>
                        <textarea id="description" 
                                  name="description" 
                                  rows="4" 
                                  maxlength="2000"
                                  placeholder="Résumé, synopsis ou description personnelle..."><?= h(strip_tags($editBook['description'] ?? '')) ?></textarea>
                        <small>Vous pouvez modifier ou compléter la description du livre</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="tagsInputField">Tags :</label>
                        <div class="tag-input-container" id="tagsContainer">
                            <input type="text" class="tag-input-field" id="tagsInputField" placeholder="Ajouter un tag...">
                        </div>
                        <input type="hidden" id="tags" name="tags" maxlength="500" value="<?= h($editBook['tags'] ?? '') ?>">
                        <small>Appuyez sur Entrée ou virgule pour ajouter un tag. Exemples : favori, à relire, prêté, série, coup de cœur</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="couverture_perso">Changer la couverture :</label>
                        <?php if ($editBook['couverture']): ?>
                            <div class="current-cover">
                                <img src="<?= h(getImageUrl($editBook['couverture'])) ?>" 
                                     alt="Couverture actuelle"
                                     onerror="this.style.display='none';">
                                <small>Couverture actuelle</small>
                            </div>
                        <?php endif; ?>
                        <input type="file" 
                               id="couverture_perso" 
                               name="couverture_perso" 
                               accept="image/*">
                        <small>Formats acceptés : JPG, PNG, GIF, WebP (max 5MB)</small>
                        
                        <?php if ($editBook['couverture']): ?>
                            <div class="form-check">
                                <label>
                                    <input type="checkbox" name="supprimer_couverture" value="1"> 
                                    Supprimer la couverture actuelle
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="note_personnelle">Note personnelle :</label>
                        <textarea id="note_personnelle" 
                                  name="note_personnelle" 
                                  rows="4" 
                                  maxlength="2000"
                                  placeholder="Vos impressions, critique, note de lecture..."><?= h($editBook['note_personnelle'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal()" class="btn-secondary">Annuler</button>
                        <button type="submit" class="btn-primary">Sauvegarder</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    
    <script src="tag-input.js"></script>
    <script>
        // Gestion de la modal
        function closeModal() {
            window.location.href = <?= json_encode(buildUrl([], ['edit'])) ?>;
        }

        // Widget de saisie de tags dans la modale d'édition
        (function() {
            const tagsContainer = document.getElementById('tagsContainer');
            const tagsHidden = document.getElementById('tags');
            if (tagsContainer && tagsHidden) {
                const allTags = <?= json_encode($allTags, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                initTagInput(tagsContainer, tagsHidden, allTags);
            }
        })();

        // Fermeture modal avec Escape ou clic extérieur
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                closeModal();
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('editModal')) {
                closeModal();
            }
        });
        
        // Validation côté client pour le formulaire d'édition
        document.getElementById('editForm')?.addEventListener('submit', function(e) {
            const fileInput = document.getElementById('couverture_perso');
            if (fileInput.files[0]) {
                const file = fileInput.files[0];
                const maxSize = 5 * 1024 * 1024; // 5MB
                
                if (file.size > maxSize) {
                    alert('Le fichier est trop volumineux (maximum 5MB)');
                    e.preventDefault();
                    return false;
                }
                
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Format de fichier non autorisé. Utilisez JPG, PNG, GIF ou WebP.');
                    e.preventDefault();
                    return false;
                }
            }
        });
        
        // Amélioration UX : Auto-soumission des filtres après sélection
        const filterSelects = document.querySelectorAll('#searchForm select');
        filterSelects.forEach(select => {
            select.addEventListener('change', function() {
                setTimeout(() => {
                    document.getElementById('searchForm').submit();
                }, 100);
            });
        });
        
        // Validation de recherche côté client
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[<>]/g, '');
            });
        }
    </script>
</body>
</html>