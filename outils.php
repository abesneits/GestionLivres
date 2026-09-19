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
?>

<!DOCTYPE html>
<html lang="fr">
<?php
include 'includes/head.php';
renderHead('Outils - Ma Collection');
?>
<body>

    <?php include 'includes/menu.php'; ?>

    <div class="container">

        <?php
        if ($message) {
            echo showMessage($message, $messageType);
        }
        if ($flashMessage) {
            echo $flashMessage;
        }
        ?>

        <div class="page-header">
            <h1>🛠️ Outils</h1>
            <p>Maintenance de la base de données, édition groupée et gestion des tags</p>
        </div>

        <div class="outils-tabs">
            <a href="outils.php?section=bdd" class="outils-tab <?= $section === 'bdd' ? 'active' : '' ?>">🗄️ Base de données</a>
            <a href="outils.php?section=edition" class="outils-tab <?= $section === 'edition' ? 'active' : '' ?>">✏️ Édition groupée</a>
            <a href="outils.php?section=tags" class="outils-tab <?= $section === 'tags' ? 'active' : '' ?>">🏷️ Tags</a>
            <a href="outils.php?section=supports" class="outils-tab <?= $section === 'supports' ? 'active' : '' ?>">📦 Supports</a>
        </div>

        <?php if ($section === 'bdd'): ?>

            <div class="search-section">
                <h3>État de la base de données</h3>
                <p style="color:#666; margin-bottom: 15px;">Version MySQL : <strong><?= h($dbInfo['version']) ?></strong></p>

                <div class="db-table-list">
                    <?php foreach ($dbInfo['tables'] as $nomTable => $infos): ?>
                        <div class="stat-card">
                            <div class="stat-number"><?= number_format($infos['lignes'], 0, ',', ' ') ?></div>
                            <div class="stat-label"><?= h($nomTable) ?></div>
                            <div style="color:#999; font-size:0.85em; margin-top:5px;">
                                <?= formatTailleOctets($infos['taille_octets']) ?>
                                <?php if (!empty($infos['index'])): ?>
                                    · <?= count($infos['index']) ?> index
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="search-section">
                <h3>Stockage des fichiers (uploads/)</h3>
                <div class="db-table-list">
                    <div class="stat-card">
                        <div class="stat-number"><?= formatTailleOctets($storageInfo['taille_totale']) ?></div>
                        <div class="stat-label">Espace utilisé</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= number_format($storageInfo['nb_fichiers'], 0, ',', ' ') ?></div>
                        <div class="stat-label">Fichier(s)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= number_format($storageInfo['nb_orphelins'], 0, ',', ' ') ?></div>
                        <div class="stat-label">Fichier(s) orphelin(s)</div>
                        <?php if ($storageInfo['nb_orphelins'] > 0): ?>
                            <div style="color:#999; font-size:0.85em; margin-top:5px;"><?= formatTailleOctets($storageInfo['taille_orphelins']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($storageInfo['nb_orphelins'] > 0): ?>
                    <p style="color:#666; margin: 15px 0;">
                        Ces fichiers sont présents dans <code>uploads/</code> mais ne sont plus référencés par aucun livre ni auteur
                        (couverture remplacée, livre supprimé...).
                    </p>
                    <form method="POST" onsubmit="return confirm('Supprimer définitivement <?= $storageInfo['nb_orphelins'] ?> fichier(s) orphelin(s) ?');">
                        <input type="hidden" name="action" value="delete_orphans">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <button type="submit" class="btn-danger">🧹 Supprimer les fichiers orphelins</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="search-section">
                <h3>Maintenance</h3>
                <form method="POST" style="display:inline-block; margin-right:10px;" onsubmit="return confirm('Optimiser toutes les tables ?');">
                    <input type="hidden" name="action" value="optimize_tables">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <button type="submit" class="btn-primary">🧹 Optimiser les tables</button>
                </form>

                <a href="outils.php?section=bdd&export=backup" class="btn-secondary">📥 Télécharger une sauvegarde</a>
            </div>

            <div class="search-section danger-zone">
                <h3>⚠️ Restaurer une sauvegarde</h3>
                <p style="color:#666; margin-bottom: 15px;">
                    Cette opération remplace <strong>toutes</strong> les données actuelles (livres, listes, auteurs) par le contenu du fichier envoyé.
                    Téléchargez d'abord une sauvegarde récente avant de continuer.
                </p>
                <form method="POST" enctype="multipart/form-data" id="restoreForm" onsubmit="return confirm('Cette action va remplacer toutes les données actuelles. Continuer ?');">
                    <input type="hidden" name="action" value="restore_backup">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

                    <div class="form-group">
                        <label>Fichier de sauvegarde (.json)</label>
                        <input type="file" name="backup_file" accept=".json" required>
                    </div>

                    <div class="form-group">
                        <label>Tapez <strong>RESTAURER</strong> pour confirmer</label>
                        <input type="text" name="confirm_text" id="confirmRestoreText" autocomplete="off">
                    </div>

                    <button type="submit" class="btn-danger" id="restoreSubmitBtn" disabled>Restaurer la sauvegarde</button>
                </form>
            </div>

            <script>
            (function() {
                const input = document.getElementById('confirmRestoreText');
                const btn = document.getElementById('restoreSubmitBtn');
                if (input && btn) {
                    input.addEventListener('input', function() {
                        btn.disabled = input.value !== 'RESTAURER';
                    });
                }
            })();
            </script>

        <?php elseif ($section === 'edition'): ?>

            <details class="search-section search-section-collapsible" <?= $filtreActif ? 'open' : '' ?>>
                <summary>Recherche avancée<?= $filtreActif ? ' <span class="filtre-actif-badge">filtres actifs</span>' : '' ?></summary>
                <form method="GET">
                    <input type="hidden" name="section" value="edition">

                    <div class="form-group">
                        <label>Recherche (titre, auteur, ISBN)</label>
                        <input type="text" name="search" value="<?= h($criteres['search']) ?>">
                    </div>

                    <div style="display:flex; gap:15px; flex-wrap:wrap;">
                        <div class="form-group" style="flex:1; min-width:150px;">
                            <label>Support</label>
                            <select name="support">
                                <option value="">Tous</option>
                                <?php foreach (array_keys($supportTypes) as $s): ?>
                                    <option value="<?= h($s) ?>" <?= $criteres['support'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1; min-width:150px;">
                            <label>Statut</label>
                            <select name="statut">
                                <option value="">Tous</option>
                                <?php foreach (['À lire', 'En cours', 'Lu', 'Abandonné'] as $s): ?>
                                    <option value="<?= h($s) ?>" <?= $criteres['statut'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1; min-width:150px;">
                            <label>Trier par</label>
                            <select name="sort">
                                <option value="titre_asc" <?= $criteres['sort'] === 'titre_asc' ? 'selected' : '' ?>>Titre A-Z</option>
                                <option value="titre_desc" <?= $criteres['sort'] === 'titre_desc' ? 'selected' : '' ?>>Titre Z-A</option>
                                <option value="date_desc" <?= $criteres['sort'] === 'date_desc' ? 'selected' : '' ?>>Plus récents</option>
                                <option value="date_asc" <?= $criteres['sort'] === 'date_asc' ? 'selected' : '' ?>>Plus anciens</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:flex; gap:15px; flex-wrap:wrap;">
                        <div class="form-group" style="flex:1; min-width:150px;">
                            <label>Ajoutés du</label>
                            <input type="date" name="date_from" value="<?= h($criteres['date_from']) ?>">
                        </div>
                        <div class="form-group" style="flex:1; min-width:150px;">
                            <label>Ajoutés jusqu'au</label>
                            <input type="date" name="date_to" value="<?= h($criteres['date_to']) ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label style="font-weight:normal; display:inline-flex; align-items:center; gap:5px;">
                            <input type="checkbox" name="sans_couverture" value="1" style="width:auto;" <?= $criteres['sans_couverture'] ? 'checked' : '' ?>>
                            Sans couverture
                        </label>
                        &nbsp;&nbsp;
                        <label style="font-weight:normal; display:inline-flex; align-items:center; gap:5px;">
                            <input type="checkbox" name="sans_note" value="1" style="width:auto;" <?= $criteres['sans_note'] ? 'checked' : '' ?>>
                            Sans note personnelle
                        </label>
                    </div>

                    <?php if (!empty($allTagsEdition)): ?>
                        <div class="form-group">
                            <label>Tags
                                <select name="tags_mode" style="width:auto; display:inline-block; margin-left:10px;">
                                    <option value="and" <?= $criteres['tags_mode'] === 'and' ? 'selected' : '' ?>>ET (tous les tags cochés)</option>
                                    <option value="or" <?= $criteres['tags_mode'] === 'or' ? 'selected' : '' ?>>OU (au moins un)</option>
                                </select>
                            </label>
                            <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:10px;">
                                <?php foreach ($allTagsEdition as $tag): ?>
                                    <label style="font-weight:normal; display:inline-flex; align-items:center; gap:5px;">
                                        <input type="checkbox" name="tags[]" value="<?= h($tag) ?>" style="width:auto;" <?= in_array($tag, $criteres['tags']) ? 'checked' : '' ?>>
                                        <?= h($tag) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn-primary">🔍 Filtrer</button>
                    <a href="outils.php?section=edition" class="btn-secondary">Réinitialiser</a>
                </form>
            </details>

            <div class="search-section">
                <p><strong><?= $paginationInfoEdition['total_items'] ?></strong> livre(s) trouvé(s)</p>

                <form method="POST" id="bulkEditForm">
                    <input type="hidden" name="action" value="bulk_update">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="return_qs" value="<?= h(http_build_query($_GET)) ?>">

                    <div class="selection-info">
                        <span id="selectionCount">0 livre sélectionné</span>
                        <button type="button" onclick="selectAllVisibleBooks()" class="btn-secondary btn-small">Tout sélectionner</button>
                        <button type="button" onclick="deselectAllVisibleBooks()" class="btn-secondary btn-small">Tout désélectionner</button>
                        <div class="view-toggle" role="group" aria-label="Mode d'affichage">
                            <button type="button" id="viewToggleList" onclick="setBooksView('list')" class="view-toggle-btn active">☰ Liste</button>
                            <button type="button" id="viewToggleGrid" onclick="setBooksView('grid')" class="view-toggle-btn">▦ Grille</button>
                        </div>
                    </div>

                    <div class="books-list" id="booksListEdition">
                        <?php foreach ($resultatsLivres as $book): ?>
                            <label class="book-item">
                                <input type="checkbox" name="livres_ids[]" value="<?= $book['id'] ?>" onchange="updateBulkSelectionCount()">
                                <div class="book-item-cover">
                                    <?php $imageUrl = getImageUrl($book['couverture'] ?? null); ?>
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
                                        <span class="badge-small"><?= h($book['statut'] ?? 'À lire') ?></span>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($paginationInfoEdition['total_pages'] > 1): ?>
                        <?= generatePagination($paginationInfoEdition) ?>
                        <p style="color:#999; font-size:0.85em;">La sélection ne traverse pas les pages : les actions groupées ci-dessous ne s'appliquent qu'aux livres cochés sur cette page.</p>
                    <?php endif; ?>

                    <div class="bulk-edit-panel">
                        <h3>Actions groupées</h3>

                        <div style="display:flex; gap:15px; flex-wrap:wrap;">
                            <div class="form-group" style="flex:1; min-width:150px;">
                                <label>Nouveau support</label>
                                <select name="new_support">
                                    <option value="">Ne pas changer</option>
                                    <?php foreach (array_keys($supportTypes) as $s): ?>
                                        <option value="<?= h($s) ?>"><?= h($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="flex:1; min-width:150px;">
                                <label>Nouveau statut</label>
                                <select name="new_statut">
                                    <option value="">Ne pas changer</option>
                                    <?php foreach (['À lire', 'En cours', 'Lu', 'Abandonné'] as $s): ?>
                                        <option value="<?= h($s) ?>"><?= h($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Tags</label>
                            <div style="display:flex; gap:15px; margin-bottom:10px; flex-wrap:wrap;">
                                <label style="font-weight:normal;"><input type="radio" name="tag_mode" value="" checked style="width:auto;"> Ne pas changer</label>
                                <label style="font-weight:normal;"><input type="radio" name="tag_mode" value="add" style="width:auto;"> Ajouter</label>
                                <label style="font-weight:normal;"><input type="radio" name="tag_mode" value="remove" style="width:auto;"> Retirer</label>
                                <label style="font-weight:normal;"><input type="radio" name="tag_mode" value="replace" style="width:auto;"> Remplacer</label>
                            </div>
                            <div class="tag-input-container" id="bulkTagsContainer">
                                <input type="text" class="tag-input-field" id="bulkTagsInputField" placeholder="Ajouter un tag...">
                            </div>
                            <input type="hidden" id="tags_value" name="tags_value" value="">
                        </div>

                        <button type="submit" class="btn-primary" id="bulkSubmitBtn" disabled>Appliquer aux livres sélectionnés</button>
                    </div>
                </form>
            </div>

            <script src="tag-input.js"></script>
            <script>
            (function() {
                const container = document.getElementById('bulkTagsContainer');
                const hidden = document.getElementById('tags_value');
                if (container && hidden) {
                    const allTags = <?= json_encode($allTagsEdition, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                    initTagInput(container, hidden, allTags);
                }
            })();

            function updateBulkSelectionCount() {
                const checked = document.querySelectorAll('.books-list input[type="checkbox"]:checked').length;
                document.getElementById('selectionCount').textContent = checked + (checked > 1 ? ' livres sélectionnés' : ' livre sélectionné');
                document.getElementById('bulkSubmitBtn').disabled = checked === 0;
            }

            function selectAllVisibleBooks() {
                document.querySelectorAll('.books-list input[type="checkbox"]').forEach(function(cb) { cb.checked = true; });
                updateBulkSelectionCount();
            }

            function deselectAllVisibleBooks() {
                document.querySelectorAll('.books-list input[type="checkbox"]').forEach(function(cb) { cb.checked = false; });
                updateBulkSelectionCount();
            }

            function setBooksView(mode) {
                const list = document.getElementById('booksListEdition');
                const btnList = document.getElementById('viewToggleList');
                const btnGrid = document.getElementById('viewToggleGrid');
                list.classList.toggle('grid-view', mode === 'grid');
                btnList.classList.toggle('active', mode === 'list');
                btnGrid.classList.toggle('active', mode === 'grid');
                localStorage.setItem('outils_edition_view', mode);
            }

            (function() {
                const modeEnregistre = localStorage.getItem('outils_edition_view');
                if (modeEnregistre === 'grid') {
                    setBooksView('grid');
                }
            })();
            </script>

        <?php elseif ($section === 'tags'): ?>

            <div class="search-section">
                <h3>Créer un nouveau tag</h3>
                <p style="color:#666; margin-bottom: 15px;">Le tag sera aussitôt disponible en suggestion sur les formulaires, même avant d'être attribué à un livre.</p>
                <form method="POST" style="display:flex; gap:10px; align-items:flex-end;">
                    <input type="hidden" name="action" value="create_tag">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Nom du tag</label>
                        <input type="text" name="nom" required style="width:auto;">
                    </div>
                    <button type="submit" class="btn-primary">Créer</button>
                </form>
            </div>

            <div class="search-section">
                <h3>Tags de la collection</h3>
                <?php if (empty($tousLesTags)): ?>
                    <p style="color:#666;">Aucun tag utilisé pour le moment.</p>
                <?php else: ?>
                    <table class="tags-table">
                        <thead>
                            <tr>
                                <th>Tag</th>
                                <th>Livres</th>
                                <th>Renommer</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tousLesTags as $tag): ?>
                                <tr>
                                    <td><span class="badge-small"><?= h($tag) ?></span></td>
                                    <td><?= $bookManager->countBooksByTag($tag) ?></td>
                                    <td>
                                        <form method="POST" style="display:flex; gap:5px;" onsubmit="return confirm('Renommer le tag « <?= h(addslashes($tag)) ?> » partout où il apparaît ?');">
                                            <input type="hidden" name="action" value="rename_tag">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="old_tag" value="<?= h($tag) ?>">
                                            <input type="text" name="new_tag" placeholder="Nouveau nom" required style="width:auto;">
                                            <button type="submit" class="btn-secondary btn-small">Renommer</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('Retirer le tag « <?= h(addslashes($tag)) ?> » de tous les livres ?');">
                                            <input type="hidden" name="action" value="delete_tag">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="tag" value="<?= h($tag) ?>">
                                            <button type="submit" class="btn-danger btn-small">Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php elseif ($section === 'supports'): ?>

            <div class="search-section">
                <h3>Ajouter un nouveau support</h3>
                <p style="color:#666; margin-bottom: 15px;">Par exemple « Magazine » ou « Roman graphique ». Il apparaîtra ensuite dans tous les menus de support.</p>
                <form method="POST" style="display:flex; gap:10px; align-items:flex-end;">
                    <input type="hidden" name="action" value="add_support">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Nom du support</label>
                        <input type="text" name="nom" required style="width:auto;">
                    </div>
                    <button type="submit" class="btn-primary">Ajouter</button>
                </form>
            </div>

            <div class="search-section">
                <h3>Supports existants</h3>
                <table class="tags-table">
                    <thead>
                        <tr>
                            <th>Support</th>
                            <th>Livres</th>
                            <th>Renommer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($supportTypes as $nom => $count): ?>
                            <tr>
                                <td><span class="badge-small"><?= h($nom) ?></span></td>
                                <td><?= $count ?></td>
                                <td>
                                    <form method="POST" style="display:flex; gap:5px;" onsubmit="return confirm('Renommer le support « <?= h(addslashes($nom)) ?> » partout où il apparaît ?');">
                                        <input type="hidden" name="action" value="rename_support">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <input type="hidden" name="old_support" value="<?= h($nom) ?>">
                                        <input type="text" name="new_support_name" placeholder="Nouveau nom" required style="width:auto;">
                                        <button type="submit" class="btn-secondary btn-small">Renommer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>

</body>
</html>
