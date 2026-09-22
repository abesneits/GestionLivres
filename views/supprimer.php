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
        
        <!-- Recherche pour faciliter la suppression -->
        <div class="search-section">
            <h3>Rechercher un livre à supprimer</h3>
            <form method="GET" class="search-row" id="searchForm">
                <div class="search-group" style="flex: 1;">
                    <input type="text" 
                           name="search" 
                           value="<?= h($search) ?>" 
                           placeholder="Rechercher par titre, auteur ou ISBN..." 
                           maxlength="100"
                           style="width: 100%;">
                </div>
                <button type="submit" class="btn-search">Rechercher</button>
            </form>
            <?php if ($search): ?>
                <p style="margin-top: 10px;">
                    <a href="supprimer.php" style="color: #007bff;">← Afficher tous les livres</a>
                </p>
            <?php endif; ?>
        </div>
        
        <!-- Informations sur les résultats -->
        <div class="results-info">
            <p>
                <?php if ($search): ?>
                    <strong><?= number_format($totalBooks) ?></strong> résultat(s) trouvé(s) pour "<?= h($search) ?>"
                <?php else: ?>
                    <strong><?= number_format($totalBooks) ?></strong> livre(s) dans votre collection
                <?php endif; ?>
            </p>
            
            <?php if ($paginationInfo['total_pages'] > 1): ?>
                <p class="results-summary">
                    Page <?= $paginationInfo['current_page'] ?> sur <?= $paginationInfo['total_pages'] ?> 
                    - Affichage de <?= $paginationInfo['start_item'] ?> à <?= $paginationInfo['end_item'] ?> résultats
                </p>
            <?php endif; ?>
        </div>
        
        <?php if (empty($books)): ?>
            <div class="empty-message">
                <?php if ($search): ?>
                    <p>Aucun livre trouvé avec la recherche "<?= h($search) ?>"</p>
                    <p><a href="supprimer.php" style="color: #007bff;">Voir tous les livres</a></p>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📚</div>
                        <h3>Votre collection est vide</h3>
                        <p>Vous n'avez aucun livre à supprimer.</p>
                        <p><a href="ajouter.php" class="btn-primary">Ajouter votre premier livre</a></p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            
            <!-- Tableau des livres avec option de suppression -->
            <div class="table-responsive">
                <table class="delete-table">
                    <thead>
                        <tr>
                            <th>Couverture</th>
                            <th>Titre</th>
                            <th>Auteur</th>
                            <th>Support</th>
                            <th>Statut</th>
                            <th>Tags</th>
                            <th>Ajouté le</th>
                            <th>Note</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($books as $book): ?>
                            <tr class="delete-row">
                                <td class="cover-cell">
                                    <?php $imageUrl = getImageUrl($book['couverture']); ?>
                                    <img src="<?= h($imageUrl) ?>" 
                                         alt="Couverture de <?= h($book['titre']) ?>"
                                         class="cover-image-small"
                                         onerror="this.parentElement.innerHTML='<div class=&quot;cover-placeholder-small&quot;>📚</div>';">
                                </td>
                                <td class="title-cell">
                                    <strong><?= h($book['titre']) ?></strong>
                                </td>
                                <td class="author-cell">
                                    <?= h($book['auteur']) ?>
                                </td>
                                <td class="support-cell">
                                    <span class="<?= getSupportClass($book['support']) ?>">
                                        <?= h($book['support']) ?>
                                    </span>
                                    <span class="<?= getTypeLivreClass($book['type_livre'] ?? 'Papier') ?>">
                                        <?= h($book['type_livre'] ?? 'Papier') ?>
                                    </span>
                                </td>
                                <td class="status-cell">
                                    <span class="book-card-statut <?= getStatusClass($book['statut'] ?? 'À lire') ?>">
                                        <?= h($book['statut'] ?? 'À lire') ?>
                                    </span>
                                </td>
                                <td class="tags-cell">
                                    <?= displayTags($book['tags'], 2) ?>
                                </td>
                                <td class="date-cell">
                                    <?= formatDate($book['date_ajout']) ?>
                                </td>
                                <td class="note-cell">
                                    <?php if ($book['note_personnelle']): ?>
                                        <span class="note-preview" title="<?= h($book['note_personnelle']) ?>">
                                            <?= truncateText($book['note_personnelle'], 30) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="no-note">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-cell">
                                    <div class="table-actions">
                                        <form method="POST" 
                                              class="delete-form inline-form" 
                                              onsubmit="return confirmDelete(this)" 
                                              data-title="<?= h($book['titre']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="book_id" value="<?= $book['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <button type="submit" class="btn-delete-small" title="Supprimer ce livre">
                                                🗑️
                                            </button>
                                        </form>
                                        
                                        <a href="index.php?edit=<?= $book['id'] ?>" 
                                           class="btn-edit-small" 
                                           title="Éditer plutôt que supprimer">
                                            ✏️
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?= generatePagination($paginationInfo) ?>
            
        <?php endif; ?>
        
        <!-- Actions de groupe et conseils -->
        <div class="bulk-actions-info">
            <div class="info-card">
                <h3>Conseil</h3>
                <p>Avant de supprimer un livre, vous pouvez :</p>
                <ul>
                    <li><strong>L'éditer</strong> pour corriger les informations</li>
                    <li><strong>Ajouter des tags</strong> comme "à donner", "abîmé"</li>
                    <li><strong>Changer le statut</strong> vers "Abandonné" au lieu de supprimer</li>
                    <li><strong>Exporter votre collection</strong> en CSV avant suppression massive</li>
                </ul>
                
                <div style="margin-top: 15px;">
                    <a href="export.php?format=csv" class="btn-secondary">Exporter en CSV</a>
                    <a href="index.php" class="btn-secondary">Retour à la collection</a>
                </div>
            </div>
            
            <!-- Statistiques de suppression -->
            <?php if ($search): ?>
                <div class="info-card">
                    <h3>Informations sur la recherche</h3>
                    <p><strong><?= number_format($totalBooks) ?></strong> livre(s) correspondent à votre recherche sur un total de <strong><?= number_format($stats['total']) ?></strong> livres.</p>
                    
                    <?php if ($totalBooks > 20): ?>
                        <p class="warning-text">
                            <strong>Attention :</strong> Vous avez beaucoup de résultats. Utilisez la pagination pour naviguer ou affinez votre recherche.
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Confirmation de suppression améliorée
        function confirmDelete(form) {
            const titre = form.getAttribute('data-title');
            const confirmation = confirm(
                '⚠️ ATTENTION ⚠️\n\n' +
                'Êtes-vous sûr de vouloir supprimer définitivement le livre :\n' +
                '"' + titre + '" ?\n\n' +
                'Cette action ne peut pas être annulée.'
            );
            
            if (confirmation) {
                // Afficher un indicateur de chargement
                const submitBtn = form.querySelector('.btn-delete-small');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '⏳';
                submitBtn.disabled = true;
                
                // Restaurer le bouton en cas d'erreur
                setTimeout(function() {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 5000);
            }
            
            return confirmation;
        }
        
        // Auto-focus sur le champ de recherche
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && !searchInput.value) {
                searchInput.focus();
            }
        });
        
        // Validation de recherche côté client
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                // Limiter les caractères spéciaux
                this.value = this.value.replace(/[<>]/g, '');
            });
        }
        
        // Double confirmation pour suppressions multiples
        let deleteCount = 0;
        document.querySelectorAll('.delete-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                deleteCount++;
                if (deleteCount >= 3) {
                    const doubleConfirm = confirm(
                        'Vous avez déjà supprimé ' + (deleteCount - 1) + ' livre(s) dans cette session.\n' +
                        'Êtes-vous vraiment sûr de continuer ?'
                    );
                    if (!doubleConfirm) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        });
        
        // Raccourci clavier pour la recherche
        document.addEventListener('keydown', function(e) {
            // Ctrl+F ou Cmd+F pour focus sur la recherche
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
        });
        
        // Auto-soumission de la recherche avec délai
        let searchTimeout;
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const form = this.closest('form');
                
                // Auto-recherche après 1 seconde d'inactivité
                searchTimeout = setTimeout(function() {
                    if (searchInput.value.length >= 3 || searchInput.value.length === 0) {
                        form.submit();
                    }
                }, 1000);
            });
        }
        
        // Gestion des erreurs réseau
        window.addEventListener('offline', function() {
            const forms = document.querySelectorAll('.delete-form');
            forms.forEach(form => {
                const btn = form.querySelector('.btn-delete-small');
                btn.disabled = true;
                btn.title = 'Suppression indisponible hors ligne';
            });
        });
        
        window.addEventListener('online', function() {
            const forms = document.querySelectorAll('.delete-form');
            forms.forEach(form => {
                const btn = form.querySelector('.btn-delete-small');
                btn.disabled = false;
                btn.title = 'Supprimer ce livre';
            });
        });
    </script>
    
    <!-- CSS supplémentaire pour le mode tableau -->
    <style>
        .delete-table {
            margin-top: 20px;
        }
        
        .delete-row:hover {
            background-color: #fff5f5;
        }
        
        .cover-cell {
            width: 60px;
            text-align: center;
        }
        
        .cover-image-small {
            width: 40px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .cover-placeholder-small {
            width: 40px;
            height: 60px;
            background: linear-gradient(135deg, #f0f0f0 0%, #e0e0e0 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            color: #999;
            font-size: 14px;
            margin: 0 auto;
        }
        
        .title-cell {
            min-width: 200px;
            font-weight: 600;
        }
        
        .author-cell {
            min-width: 150px;
            color: #666;
        }
        
        .support-cell {
            white-space: nowrap;
        }
        
        .status-cell {
            white-space: nowrap;
        }
        
        .tags-cell {
            min-width: 120px;
        }
        
        .date-cell {
            white-space: nowrap;
            font-size: 0.9em;
            color: #666;
        }
        
        .note-cell {
            max-width: 150px;
        }
        
        .note-preview {
            font-style: italic;
            color: #666;
            font-size: 0.85em;
            cursor: help;
        }
        
        .no-note {
            color: #ccc;
            text-align: center;
        }
        
        .actions-cell {
            width: 100px;
        }
        
        .table-actions {
            display: flex;
            gap: 5px;
            align-items: center;
            justify-content: center;
        }
        
        .inline-form {
            margin: 0;
            display: inline;
        }
        
        .btn-delete-small {
            background: #dc3545;
            color: white;
            border: none;
            padding: 6px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: background-color 0.3s ease;
        }
        
        .btn-delete-small:hover {
            background: #c82333;
        }
        
        .btn-delete-small:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        .btn-edit-small {
            background: #17a2b8;
            color: white;
            padding: 6px 8px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            transition: background-color 0.3s ease;
            display: inline-block;
        }
        
        .btn-edit-small:hover {
            background: #138496;
            color: white;
            text-decoration: none;
        }
        
        /* Responsive pour le tableau de suppression */
        @media (max-width: 1024px) {
            .note-cell {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .tags-cell,
            .date-cell {
                display: none;
            }
            
            .table-actions {
                flex-direction: column;
                gap: 3px;
            }
            
            .btn-delete-small,
            .btn-edit-small {
                font-size: 10px;
                padding: 4px 6px;
            }
        }
        
        @media (max-width: 480px) {
            .support-cell,
            .status-cell {
                display: none;
            }
            
            .title-cell {
                min-width: 120px;
            }
            
            .author-cell {
                min-width: 100px;
            }
        }
    </style>
</body>
</html>