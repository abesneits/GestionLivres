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
?>

<!DOCTYPE html>
<html lang="fr">
<?php
include 'includes/head.php';
renderHead('Auteurs - Ma Collection');
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
            <h1>🖋️ Auteurs</h1>
            <p>Les auteurs présents dans votre bibliothèque</p>
        </div>

        <?php if (!empty($duplicateGroups)): ?>
            <div class="search-section duplicates-section">
                <h3>🔍 Doublons potentiels détectés</h3>
                <p class="duplicates-hint">Ces noms semblent désigner le même auteur (variantes de casse ou d'accents). Choisissez le nom à conserver puis fusionnez.</p>
                <?php foreach ($duplicateGroups as $groupe): ?>
                    <form method="POST" class="duplicate-group">
                        <input type="hidden" name="action" value="merge_authors">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <div class="duplicate-options">
                            <?php foreach ($groupe['auteurs'] as $i => $auteur): ?>
                                <label class="duplicate-option">
                                    <input type="radio" name="target" value="<?= h($auteur['nom']) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                                    <?= h($auteur['nom']) ?> <span class="auteur-count"><?= count($auteur['livres']) ?> livre<?= count($auteur['livres']) > 1 ? 's' : '' ?></span>
                                </label>
                                <input type="hidden" name="sources[]" value="<?= h($auteur['nom']) ?>">
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn-primary" onclick="return confirm('Fusionner ces auteurs ?');">Fusionner</button>
                    </form>
                    <form method="POST" style="display:inline-block; margin-bottom:15px;">
                        <input type="hidden" name="action" value="ignore_duplicate">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="cle" value="<?= h($groupe['cle']) ?>">
                        <button type="submit" class="btn-secondary btn-small">Ce n'est pas un doublon, ignorer</button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="search-section">
            <h3>Rechercher et Filtrer</h3>
            <form method="GET" class="search-row">
                <div class="search-group">
                    <label>Auteur</label>
                    <input type="text" name="q" placeholder="Nom d'auteur..." value="<?= h($search) ?>">
                </div>
                <div class="search-group">
                    <label>Support</label>
                    <select name="filter">
                        <option value="">Tous</option>
                        <?php foreach (array_keys($bookManager->getSupportTypesWithCounts()) as $supportOption): ?>
                            <option value="<?= h($supportOption) ?>" <?= $filterSupport === $supportOption ? 'selected' : '' ?>><?= h($supportOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label>Tag</label>
                    <select name="tag">
                        <option value="">Tous les tags</option>
                        <?php foreach ($allTags as $tagOption): ?>
                            <option value="<?= h($tagOption) ?>" <?= $filterTag === $tagOption ? 'selected' : '' ?>>
                                <?= h($tagOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label>Trier par</label>
                    <select name="tri">
                        <option value="alpha" <?= $tri === 'alpha' ? 'selected' : '' ?>>Alphabétique</option>
                        <option value="count_desc" <?= $tri === 'count_desc' ? 'selected' : '' ?>>Nombre de livres</option>
                        <option value="recent" <?= $tri === 'recent' ? 'selected' : '' ?>>Ajout récent</option>
                    </select>
                </div>
                <div class="search-group search-group-checkbox">
                    <label style="font-weight:normal; display:inline-flex; align-items:center; gap:5px;">
                        <input type="checkbox" name="bio_manquante" value="1" style="width:auto;" <?= $bioManquante ? 'checked' : '' ?>>
                        Biographie manquante uniquement
                    </label>
                </div>
                <button type="submit" class="btn-search">Filtrer</button>
            </form>

            <?php if ($search || $filterSupport || $filterTag || $tri !== 'alpha' || $bioManquante): ?>
                <div style="margin-top: 10px;">
                    <a href="auteurs.php" class="btn-secondary" style="font-size: 0.9em;">Réinitialiser les filtres</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($authors)): ?>
            <div class="empty-state">
                <div class="empty-icon">🖋️</div>
                <h3><?= ($search || $filterSupport || $filterTag || $bioManquante) ? 'Aucun auteur trouvé' : 'Aucun auteur pour le moment' ?></h3>
                <p><?= ($search || $filterSupport || $filterTag || $bioManquante) ? 'Essayez d\'autres critères.' : 'Ajoutez des livres à votre collection pour voir apparaître leurs auteurs ici.' ?></p>
            </div>
        <?php else: ?>
            <p class="auteur-total">
                <?= count($authors) ?> auteur<?= count($authors) > 1 ? 's' : '' ?>
                <a href="<?= h('auteurs.php?' . http_build_query(array_filter([
                    'q' => $search, 'filter' => $filterSupport, 'tag' => $filterTag, 'tri' => $tri,
                    'bio_manquante' => $bioManquante ? '1' : '', 'export' => 'csv'
                ]))) ?>" class="btn-secondary btn-small auteur-export-link">📥 Exporter en CSV</a>
            </p>

            <div class="auteurs-grid">
                <?php foreach ($authors as $auteur): ?>
                    <details class="auteur-card">
                        <summary class="auteur-summary">
                            <?php if (!empty($auteur['image_url'])): ?>
                                <img class="auteur-photo-small" src="<?= h($auteur['image_url']) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="auteur-photo-placeholder">🖋️</span>
                            <?php endif; ?>
                            <span class="auteur-nom"><?= h($auteur['nom']) ?></span>
                            <span class="auteur-count"><?= count($auteur['livres']) ?> livre<?= count($auteur['livres']) > 1 ? 's' : '' ?></span>
                        </summary>

                        <div class="auteur-bio" data-nom="<?= h($auteur['nom']) ?>">
                            <p class="auteur-bio-loading">Chargement de la biographie...</p>
                        </div>

                        <button type="button" class="btn-edit-bio" data-nom="<?= h($auteur['nom']) ?>" onclick="openEditAuteurModal(this.dataset.nom)">✏️ Éditer la biographie</button>

                        <div class="auteur-livres">
                            <?php foreach ($auteur['livres'] as $livre): ?>
                                <a href="index.php?edit=<?= $livre['id'] ?>" class="auteur-livre-card">
                                    <span class="auteur-livre-cover">
                                        <?php $imageUrl = getImageUrl($livre['couverture']); ?>
                                        <img src="<?= h($imageUrl) ?>"
                                             alt="Couverture de <?= h($livre['titre']) ?>"
                                             onerror="this.parentElement.innerHTML='<div class=&quot;cover-placeholder&quot;>📚</div>';">
                                    </span>
                                    <span class="auteur-livre-titre"><?= h($livre['titre']) ?></span>
                                    <span class="book-card-statut <?= getStatusClass($livre['statut'] ?? 'À lire') ?>"><?= h($livre['statut'] ?? 'À lire') ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <form method="POST" class="auteur-merge-form" onsubmit="return confirmMergeOrRename(this, '<?= h(addslashes($auteur['nom'])) ?>');">
                            <input type="hidden" name="action" value="merge_authors">
                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                            <input type="hidden" name="sources[]" value="<?= h($auteur['nom']) ?>">
                            <label>Renommer en / fusionner avec
                                <input type="text" name="target" list="auteur-names-list" placeholder="Nouveau nom ou auteur existant...">
                            </label>
                            <button type="submit" class="btn-secondary">Valider</button>
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <datalist id="auteur-names-list">
            <?php foreach ($allAuthorNames as $nom): ?>
                <option value="<?= h($nom) ?>">
            <?php endforeach; ?>
        </datalist>

    </div>

    <!-- Modal édition de biographie -->
    <div id="editAuteurModal" class="modal-overlay" style="display:none;">
        <div class="modal-content">
            <button type="button" onclick="closeEditAuteurModal()" class="modal-close">×</button>

            <h3>Éditer la biographie</h3>
            <p id="editAuteurNomLabel" class="edit-auteur-nom"></p>

            <form method="POST" id="editAuteurForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_biographie">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="nom" id="editAuteurNom" value="">

                <div class="form-group">
                    <label for="editAuteurBio">Biographie :</label>
                    <textarea id="editAuteurBio" name="biographie" rows="8" placeholder="Biographie de l'auteur..."></textarea>
                </div>

                <div style="display:flex; gap:15px; flex-wrap:wrap;">
                    <div class="form-group" style="flex:1; min-width:100px;">
                        <label for="editAuteurNaissance">Naissance :</label>
                        <input type="text" id="editAuteurNaissance" name="naissance" placeholder="Année ex. 1969" maxlength="10">
                    </div>
                    <div class="form-group" style="flex:1; min-width:100px;">
                        <label for="editAuteurDeces">Décès :</label>
                        <input type="text" id="editAuteurDeces" name="deces" placeholder="Année" maxlength="10">
                    </div>
                    <div class="form-group" style="flex:2; min-width:150px;">
                        <label for="editAuteurNationalite">Nationalité :</label>
                        <input type="text" id="editAuteurNationalite" name="nationalite" placeholder="France">
                    </div>
                </div>

                <div class="form-group">
                    <label for="editAuteurImage">Photo (URL) :</label>
                    <input type="url" id="editAuteurImage" name="image_url" placeholder="https://...">
                </div>

                <div class="form-group">
                    <label for="editAuteurPhoto">Ou uploader une photo depuis votre appareil :</label>
                    <div id="editAuteurPhotoPreview"></div>
                    <input type="file" id="editAuteurPhoto" name="photo" accept="image/*" onchange="previewEditAuteurPhoto(this)">
                    <small style="color: #666; display: block; margin-top: 5px;">
                        Formats acceptés : JPG, PNG, GIF, WebP (max 5Mo). Remplace la photo ci-dessus si renseignée.
                    </small>
                </div>

                <div class="modal-actions">
                    <button type="button" onclick="closeEditAuteurModal()" class="btn-secondary">Annuler</button>
                    <button type="submit" class="btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.querySelectorAll('.auteur-card').forEach(function(details) {
        details.addEventListener('toggle', function() {
            if (!details.open) return;

            const bioDiv = details.querySelector('.auteur-bio');
            if (bioDiv.dataset.loaded === '1') return;
            bioDiv.dataset.loaded = '1';

            loadBiographie(bioDiv, bioDiv.dataset.nom, false);
        });
    });

    function loadBiographie(bioDiv, nom, force) {
        bioDiv.innerHTML = '<p class="auteur-bio-loading">Chargement de la biographie...</p>';
        const url = 'get_biographie.php?nom=' + encodeURIComponent(nom) + (force ? '&force=1' : '');

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    bioDiv.innerHTML = '<p class="auteur-bio-empty">Biographie indisponible.</p>';
                    return;
                }

                bioDiv.innerHTML = '';

                if (data.image_url) {
                    const img = document.createElement('img');
                    img.className = 'auteur-bio-photo';
                    img.src = data.image_url;
                    img.alt = '';
                    bioDiv.appendChild(img);
                }

                const infosWrap = document.createElement('div');
                infosWrap.className = 'auteur-bio-content';

                if (data.naissance || data.nationalite) {
                    const meta = document.createElement('p');
                    meta.className = 'auteur-bio-meta';
                    const parts = [];
                    if (data.naissance) {
                        parts.push('🎂 ' + data.naissance + (data.deces ? ' – ' + data.deces : ''));
                    }
                    if (data.nationalite) {
                        parts.push('🌍 ' + data.nationalite);
                    }
                    meta.textContent = parts.join(' · ');
                    infosWrap.appendChild(meta);
                }

                const p = document.createElement('p');
                if (data.biographie) {
                    p.className = 'auteur-bio-text';
                    p.textContent = data.biographie;
                } else {
                    p.className = 'auteur-bio-empty';
                    p.textContent = 'Aucune biographie trouvée pour cet auteur.';
                }
                infosWrap.appendChild(p);
                bioDiv.appendChild(infosWrap);

                const refreshBtn = document.createElement('button');
                refreshBtn.type = 'button';
                refreshBtn.className = 'btn-secondary btn-small btn-refresh-bio';
                refreshBtn.textContent = '🔄 Relancer la recherche Wikipédia';
                refreshBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    loadBiographie(bioDiv, nom, true);
                });
                bioDiv.appendChild(refreshBtn);
            })
            .catch(function() {
                bioDiv.innerHTML = '<p class="auteur-bio-empty">Erreur lors du chargement de la biographie.</p>';
            });
    }

    function confirmMergeOrRename(form, sourceName) {
        const target = form.querySelector('input[name="target"]').value.trim();
        if (!target) return false;

        let exists = false;
        document.querySelectorAll('#auteur-names-list option').forEach(function(opt) {
            if (opt.value === target) exists = true;
        });

        if (exists) {
            return confirm('Un auteur nommé « ' + target + ' » existe déjà. Les deux vont être fusionnés en un seul. Continuer ?');
        }
        return confirm('Renommer « ' + sourceName + ' » en « ' + target + ' » ?');
    }

    // Empêcher qu'un clic dans le formulaire de fusion ne (dé)plie la carte
    document.querySelectorAll('.auteur-merge-form').forEach(function(form) {
        form.addEventListener('click', function(e) { e.stopPropagation(); });
    });

    // Empêcher que le clic sur le bouton d'édition ne (dé)plie la carte
    document.querySelectorAll('.btn-edit-bio').forEach(function(btn) {
        btn.addEventListener('click', function(e) { e.stopPropagation(); });
    });

    function previewEditAuteurPhoto(input) {
        const preview = document.getElementById('editAuteurPhotoPreview');
        preview.innerHTML = '';

        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = '<img src="' + e.target.result + '" style="max-width: 150px; border-radius: 8px; margin-bottom: 10px;">';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function openEditAuteurModal(nom) {
        document.getElementById('editAuteurNom').value = nom;
        document.getElementById('editAuteurNomLabel').textContent = nom;
        document.getElementById('editAuteurBio').value = '';
        document.getElementById('editAuteurImage').value = '';
        document.getElementById('editAuteurPhoto').value = '';
        document.getElementById('editAuteurPhotoPreview').innerHTML = '';
        document.getElementById('editAuteurNaissance').value = '';
        document.getElementById('editAuteurDeces').value = '';
        document.getElementById('editAuteurNationalite').value = '';

        fetch('get_biographie.php?nom=' + encodeURIComponent(nom))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    document.getElementById('editAuteurBio').value = data.biographie || '';
                    document.getElementById('editAuteurImage').value = data.image_url || '';
                    document.getElementById('editAuteurNaissance').value = data.naissance || '';
                    document.getElementById('editAuteurDeces').value = data.deces || '';
                    document.getElementById('editAuteurNationalite').value = data.nationalite || '';
                }
            })
            .catch(function() {});

        document.getElementById('editAuteurModal').style.display = 'flex';
    }

    function closeEditAuteurModal() {
        document.getElementById('editAuteurModal').style.display = 'none';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('editAuteurModal').style.display === 'flex') {
            closeEditAuteurModal();
        }
    });
    </script>
</body>
</html>
