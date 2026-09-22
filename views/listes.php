<!DOCTYPE html>
<html lang="fr">
<?php 
include 'includes/head.php'; 
renderHead('Mes Listes - Ma Collection'); 
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
        
        <div class="page-header">
            <h1>📋 Mes Listes de Lecture</h1>
            <p>Organisez vos livres en listes personnalisées</p>
        </div>

        <?php if (!$currentList): ?>
            <!-- Vue liste de toutes les listes -->
            <div class="actions-bar">
                <button onclick="showCreateModal()" class="btn-primary">➕ Nouvelle liste</button>
            </div>

            <?php if (empty($listes)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📚</div>
                    <h3>Aucune liste créée</h3>
                    <p>Créez votre première liste pour organiser vos livres par thème, humeur, ou tout autre critère !</p>
                    <button onclick="showCreateModal()" class="btn-primary">Créer ma première liste</button>
                </div>
            <?php else: ?>
                <div class="listes-grid">
                    <?php foreach ($listes as $liste): ?>
                        <div class="liste-card">
                            <?php if ($liste['couverture'] && file_exists($liste['couverture'])): ?>
                                <div class="liste-cover">
                                    <img src="<?= h($liste['couverture']) ?>" alt="Couverture de <?= h($liste['nom']) ?>">
                                    <div class="liste-cover-overlay">
                                        <div class="liste-count-badge"><?= $liste['nb_livres'] ?> livre<?= $liste['nb_livres'] > 1 ? 's' : '' ?></div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="liste-cover liste-cover-default">
                                    <div class="liste-cover-icon">📚</div>
                                    <div class="liste-count-badge"><?= $liste['nb_livres'] ?> livre<?= $liste['nb_livres'] > 1 ? 's' : '' ?></div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="liste-content">
                                <div class="liste-header">
                                    <h3><?= h($liste['nom']) ?></h3>
                                </div>
                                
                                <?php if ($liste['description']): ?>
                                    <p class="liste-description"><?= h($liste['description']) ?></p>
                                <?php endif; ?>
                                
                                <div class="liste-actions">
                                    <a href="?view=<?= $liste['id'] ?>" class="btn-view">Voir les livres</a>
                                    <a href="?edit=<?= $liste['id'] ?>" class="btn-edit">✏️</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette liste ?');">
                                        <input type="hidden" name="action" value="delete_list">
                                        <input type="hidden" name="liste_id" value="<?= $liste['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <button type="submit" class="btn-danger">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Vue détails d'une liste -->
            <div class="list-detail-header">
                <div>
                    <a href="listes.php" class="back-link">← Retour aux listes</a>
                    <h2><?= h($currentList['nom']) ?></h2>
                    <?php if ($currentList['description']): ?>
                        <p class="list-description"><?= h($currentList['description']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="header-actions">
                    <button onclick="showAddBooksModal(<?= $currentList['id'] ?>)" class="btn-primary">➕ Ajouter des livres</button>
                    <div class="dropdown">
                        <button onclick="toggleExportMenu(event)" class="btn-secondary">📥 Exporter</button>
                        <div id="exportMenu" class="dropdown-content">
                            <a href="export_liste.php?liste_id=<?= $currentList['id'] ?>&format=html" target="_blank">
                                <span class="export-icon">📄</span> HTML
                            </a>
                            <a href="export_liste.php?liste_id=<?= $currentList['id'] ?>&format=excel" target="_blank">
                                <span class="export-icon">📊</span> Excel
                            </a>
                            <a href="export_liste.php?liste_id=<?= $currentList['id'] ?>&format=csv" target="_blank">
                                <span class="export-icon">📋</span> CSV
                            </a>
                            <a href="export_liste.php?liste_id=<?= $currentList['id'] ?>&format=json" target="_blank">
                                <span class="export-icon">🔧</span> JSON
                            </a>
                            <a href="export_liste.php?liste_id=<?= $currentList['id'] ?>&format=txt" target="_blank">
                                <span class="export-icon">📝</span> TXT
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($booksInList)): ?>
                <div class="empty-message">
                    <p>Aucun livre dans cette liste.</p>
                    <button onclick="showAddBooksModal(<?= $currentList['id'] ?>)" class="btn-primary">Ajouter des livres</button>
                </div>
            <?php else: ?>
                <div class="books-grid" id="sortable-books" data-liste-id="<?= $currentList['id'] ?>">
                    <?php foreach ($booksInList as $book): ?>
                        <div class="book-card" data-book-id="<?= $book['id'] ?>">
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
                            
                            <?php if ($book['tags']): ?>
                                <div class="book-card-tags">
                                    <?= displayTags($book['tags'], 3) ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="book-card-actions">
                                <a href="index.php?edit=<?= $book['id'] ?>" class="btn-edit">Éditer</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Retirer ce livre de la liste ?');">
                                    <input type="hidden" name="action" value="remove_book">
                                    <input type="hidden" name="liste_id" value="<?= $currentList['id'] ?>">
                                    <input type="hidden" name="livre_id" value="<?= $book['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                    <button type="submit" class="btn-danger">Retirer</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal création/édition de liste -->
    <?php if (isset($_GET['edit']) || isset($_GET['create'])): ?>
        <div id="listeModal" class="modal-overlay" style="display: flex;">
            <div class="modal-content">
                <button onclick="closeModal()" class="modal-close">×</button>
                
                <h3><?= $editList ? 'Éditer la liste' : 'Nouvelle liste' ?></h3>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?= $editList ? 'update_list' : 'create_list' ?>">
                    <?php if ($editList): ?>
                        <input type="hidden" name="liste_id" value="<?= $editList['id'] ?>">
                        <input type="hidden" name="couverture_actuelle" value="<?= h($editList['couverture'] ?? '') ?>">
                    <?php endif; ?>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="form-group">
                        <label for="nom">Nom de la liste * :</label>
                        <input type="text" 
                               id="nom" 
                               name="nom" 
                               value="<?= $editList ? h($editList['nom']) : '' ?>" 
                               required
                               maxlength="255"
                               placeholder="Ex: À lire cet été, Favoris, SF...">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description :</label>
                        <textarea id="description" 
                                  name="description" 
                                  rows="3" 
                                  maxlength="500"
                                  placeholder="Description optionnelle de la liste..."><?= $editList ? h($editList['description']) : '' ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="couverture">Photo de couverture :</label>
                        <?php if ($editList && $editList['couverture'] && file_exists($editList['couverture'])): ?>
                            <div class="current-cover">
                                <img src="<?= h($editList['couverture']) ?>" alt="Couverture actuelle" style="max-width: 200px; border-radius: 8px; margin-bottom: 10px;">
                                <p style="font-size: 0.9em; color: #666;">Télécharger une nouvelle image pour remplacer</p>
                            </div>
                        <?php endif; ?>
                        <input type="file" 
                               id="couverture" 
                               name="couverture" 
                               accept="image/*"
                               onchange="previewImage(this)">
                        <small style="color: #666; display: block; margin-top: 5px;">
                            Formats acceptés : JPG, PNG, GIF, WebP (max 5Mo)
                        </small>
                        <div id="imagePreview" style="margin-top: 10px;"></div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal()" class="btn-secondary">Annuler</button>
                        <button type="submit" class="btn-primary"><?= $editList ? 'Sauvegarder' : 'Créer' ?></button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal ajout de livres (sélection multiple) -->
    <div id="addBooksModal" class="modal-overlay" style="display:none;">
        <div class="modal-content modal-large">
            <button onclick="closeAddBooksModal()" class="modal-close">×</button>
            
            <h3>Ajouter des livres à la liste</h3>
            <p style="color: #666; margin-bottom: 20px;">Sélectionnez un ou plusieurs livres à ajouter</p>
            
            <div class="book-search">
                <input type="text" 
                       id="bookSearchInput" 
                       placeholder="Rechercher un livre..." 
                       onkeyup="filterBooks()">
            </div>
            
            <div class="selection-info">
                <span id="selectionCount">0 livre sélectionné</span>
                <button type="button" onclick="selectAllBooks()" class="btn-secondary btn-small">Tout sélectionner</button>
                <button type="button" onclick="deselectAllBooks()" class="btn-secondary btn-small">Tout désélectionner</button>
            </div>
            
            <form method="POST" id="addBooksForm">
                <input type="hidden" name="action" value="add_books">
                <input type="hidden" name="liste_id" id="modal_liste_id" value="">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div class="books-list" id="booksListContainer">
                    <?php foreach ($allBooks as $book): ?>
                        <label class="book-item" data-title="<?= strtolower(h($book['titre'])) ?>" data-author="<?= strtolower(h($book['auteur'])) ?>">
                            <input type="checkbox" 
                                   name="livres_ids[]" 
                                   value="<?= $book['id'] ?>" 
                                   onchange="updateSelectionCount()">
                            <div class="book-item-cover">
                                <?php $imageUrl = getImageUrl($book['couverture']); ?>
                                <img src="<?= h($imageUrl) ?>" 
                                     alt="<?= h($book['titre']) ?>"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="cover-placeholder-small" style="display:none;">📚</div>
                            </div>
                            <div class="book-item-info">
                                <div class="book-item-title"><?= h($book['titre']) ?></div>
                                <div class="book-item-author"><?= h($book['auteur']) ?></div>
                                <div class="book-item-meta">
                                    <span class="badge-small"><?= h($book['support']) ?></span>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                
                <div class="modal-actions">
                    <button type="button" onclick="closeAddBooksModal()" class="btn-secondary">Annuler</button>
                    <button type="submit" class="btn-primary" id="submitBooksBtn">Ajouter les livres</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($currentList): ?>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <?php endif; ?>

    <script>
        function toggleExportMenu(e) {
            if (e) e.stopPropagation();
            const menu = document.getElementById('exportMenu');
            if (menu) {
                menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
            }
        }

        // Fermer le menu d'export en cliquant ailleurs
        document.addEventListener('click', function(e) {
            const menu = document.getElementById('exportMenu');
            if (menu && !e.target.closest('.dropdown')) {
                menu.style.display = 'none';
            }
        });

        function showCreateModal() {
            window.location.href = '?create=1';
        }
        
        function closeModal() {
            const params = new URLSearchParams(window.location.search);
            params.delete('create');
            params.delete('edit');
            window.location.href = 'listes.php?' + params.toString();
        }
        
        function showAddBooksModal(listeId) {
            document.getElementById('modal_liste_id').value = listeId;
            document.getElementById('addBooksModal').style.display = 'flex';
            updateSelectionCount();
        }
        
        function closeAddBooksModal() {
            document.getElementById('addBooksModal').style.display = 'none';
            document.getElementById('bookSearchInput').value = '';
            deselectAllBooks();
            filterBooks();
        }
        
        function filterBooks() {
            const search = document.getElementById('bookSearchInput').value.toLowerCase();
            const items = document.querySelectorAll('.book-item');
            
            items.forEach(item => {
                const title = item.dataset.title;
                const author = item.dataset.author;
                
                if (title.includes(search) || author.includes(search)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        function updateSelectionCount() {
            const checked = document.querySelectorAll('.books-list input[type="checkbox"]:checked').length;
            const countEl = document.getElementById('selectionCount');
            const submitBtn = document.getElementById('submitBooksBtn');
            
            countEl.textContent = checked + ' livre' + (checked > 1 ? 's sélectionné' : ' sélectionné') + (checked > 1 ? 's' : '');
            submitBtn.disabled = checked === 0;
        }
        
        function selectAllBooks() {
            const visibleCheckboxes = document.querySelectorAll('.book-item:not([style*="display: none"]) input[type="checkbox"]');
            visibleCheckboxes.forEach(cb => cb.checked = true);
            updateSelectionCount();
        }
        
        function deselectAllBooks() {
            document.querySelectorAll('.books-list input[type="checkbox"]').forEach(cb => cb.checked = false);
            updateSelectionCount();
        }
        
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            preview.innerHTML = '';
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" style="max-width: 200px; border-radius: 8px;">';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Fermer modal avec Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('addBooksModal').style.display === 'flex') {
                    closeAddBooksModal();
                } else if (document.getElementById('listeModal')) {
                    closeModal();
                }
            }
        });

        <?php if ($currentList && !empty($booksInList)): ?>
        // Système de drag & drop pour réorganiser les livres
        document.addEventListener('DOMContentLoaded', function() {
            const sortableList = document.getElementById('sortable-books');
            
            if (sortableList) {
                const sortable = new Sortable(sortableList, {
                    animation: 150,
                    handle: '.book-card',
                    ghostClass: 'sortable-ghost',
                    chosenClass: 'sortable-chosen',
                    dragClass: 'sortable-drag',
                    onEnd: function(evt) {
                        const bookCards = sortableList.querySelectorAll('.book-card');
                        const order = [];
                        
                        bookCards.forEach(card => {
                            const bookId = card.dataset.bookId;
                            if (bookId) {
                                order.push(parseInt(bookId));
                            }
                        });
                        
                        fetch('update_order.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                liste_id: <?= $currentList['id'] ?>,
                                order: order
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const feedback = document.createElement('div');
                                feedback.className = 'order-feedback';
                                feedback.textContent = 'Ordre sauvegardé';
                                document.body.appendChild(feedback);
                                
                                setTimeout(() => feedback.remove(), 2000);
                            } else {
                                alert('Erreur lors de la sauvegarde de l\'ordre');
                            }
                        })
                        .catch(error => {
                            console.error('Erreur:', error);
                            alert('Erreur de connexion');
                        });
                    }
                });
            }
        });
        <?php endif; ?>
    </script>

    <style>
        /* Les styles des modals (.modal-overlay, .modal-content, .modal-close,
           .modal-actions) sont définis globalement dans style.css */

        /* Grille des listes avec couvertures */
        .listes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }

        .liste-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .liste-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .liste-cover {
            position: relative;
            width: 100%;
            height: 200px;
            overflow: hidden;
        }

        .liste-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .liste-cover-default {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .liste-cover-icon {
            font-size: 60px;
            opacity: 0.3;
        }

        .liste-cover-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to bottom, transparent 0%, rgba(0,0,0,0.7) 100%);
            display: flex;
            align-items: flex-end;
            padding: 15px;
        }

        .liste-count-badge {
            background: rgba(255, 255, 255, 0.95);
            color: #333;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .liste-cover-default .liste-count-badge {
            position: absolute;
            bottom: 15px;
            left: 15px;
        }

        .liste-content {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .liste-header {
            margin-bottom: 10px;
        }

        .liste-header h3 {
            margin: 0;
            color: #333;
            font-size: 1.3em;
        }

        .liste-description {
            color: #666;
            font-size: 0.95em;
            margin: 10px 0 15px 0;
            flex: 1;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .liste-actions {
            display: flex;
            gap: 10px;
            margin-top: auto;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }

        .btn-view {
            background: #007bff;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9em;
            transition: background 0.3s ease;
            flex: 1;
            text-align: center;
        }

        .btn-view:hover {
            background: #0056b3;
            text-decoration: none;
            color: white;
        }

        /* Vue détails d'une liste */
        .list-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            gap: 20px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-shrink: 0;
            align-items: center;
        }

        .back-link {
            color: #007bff;
            text-decoration: none;
            font-size: 0.9em;
            display: inline-block;
            margin-bottom: 10px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .list-description {
            color: #666;
            margin-top: 5px;
        }

        /* Menu dropdown pour l'export */
        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background-color: white;
            min-width: 180px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            border-radius: 8px;
            z-index: 1000;
            overflow: hidden;
            margin-top: 5px;
        }

        .dropdown-content a {
            color: #333;
            padding: 12px 16px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background 0.2s ease;
        }

        .dropdown-content a:hover {
            background-color: #f8f9fa;
            color: #007bff;
        }

        .export-icon {
            font-size: 18px;
            width: 24px;
            text-align: center;
        }

        /* Modal pour sélection multiple */
        .modal-large {
            max-width: 800px;
            width: 90%;
        }

        .selection-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .selection-info span {
            flex: 1;
            font-weight: 600;
            color: #333;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 0.85em;
        }

        .book-search {
            margin-bottom: 15px;
        }

        .book-search input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .books-list {
            max-height: 450px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 8px;
            margin-bottom: 20px;
            background: #fafbfc;
        }

        .book-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.2s ease;
            gap: 10px;
            margin-bottom: 6px;
            border: 1px solid transparent;
        }

        .book-item:hover {
            background: #f8f9fa;
            border-color: #e9ecef;
        }

        .book-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .book-item-cover {
            width: 35px;
            height: 50px;
            border-radius: 3px;
            overflow: hidden;
            flex-shrink: 0;
            background: #e9ecef;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .book-item-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cover-placeholder-small {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-size: 16px;
        }

        .book-item-info {
            flex: 1;
            min-width: 0;
        }

        .book-item-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 2px;
            font-size: 0.9em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .book-item-author {
            color: #666;
            font-size: 0.8em;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .book-item-meta {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .badge-small {
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7em;
            font-weight: 600;
            white-space: nowrap;
        }

        /* Drag & Drop */
        .book-card {
            cursor: move;
            cursor: grab;
        }

        .book-card:active {
            cursor: grabbing;
        }

        .sortable-ghost {
            opacity: 0.4;
            background: #f8f9fa;
        }

        .sortable-chosen {
            cursor: grabbing;
        }

        .sortable-drag {
            opacity: 0.8;
            transform: rotate(2deg);
        }

        .order-feedback {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 10000;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        #submitBooksBtn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .list-detail-header {
                flex-direction: column;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
            }

            .header-actions button,
            .dropdown {
                width: 100%;
            }

            .dropdown-content {
                left: 0;
                right: 0;
                width: 100%;
            }

            .listes-grid {
                grid-template-columns: 1fr;
            }

            .liste-actions {
                flex-wrap: wrap;
            }

            .modal-large {
                width: 95%;
            }

            .selection-info {
                flex-wrap: wrap;
            }

            .selection-info span {
                width: 100%;
                margin-bottom: 10px;
            }
        }
    </style>
</body>
</html>