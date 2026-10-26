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
            <h1><?= icone('plume') ?> Auteurs</h1>
            <p>Les auteurs présents dans votre bibliothèque</p>
        </div>

        <?php if (!empty($duplicateGroups)): ?>
            <div class="search-section duplicates-section">
                <h3><?= icone('loupe') ?> Doublons potentiels détectés</h3>
                <p class="duplicates-hint">Ces noms semblent désigner le même auteur (variantes de casse ou d'accents, ou prénom et nom inversés). Choisissez le nom à conserver puis fusionnez.</p>
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

        <?php if (!empty($suspects)): ?>
            <details class="search-section verif-section">
                <summary><?= icone('loupe') ?> Noms à vérifier <span class="auteur-count"><?= count($suspects) ?></span></summary>
                <p class="duplicates-hint">Ces noms ressemblent à une erreur de saisie (titre de livre, parenthèses, un seul mot...). Corrigez-les, ou confirmez qu'ils sont justes.</p>
                <?php foreach ($suspects as $suspect): ?>
                    <div class="verif-item">
                        <div class="verif-info">
                            <strong><?= h($suspect['nom']) ?></strong>
                            <span class="auteur-count"><?= $suspect['nb_livres'] ?> livre<?= $suspect['nb_livres'] > 1 ? 's' : '' ?></span>
                            <small><?= h(implode(' · ', $suspect['raisons'])) ?></small>
                        </div>
                        <form method="POST" class="verif-form" onsubmit="return confirmMergeOrRename(this, '<?= h(addslashes($suspect['nom'])) ?>');">
                            <input type="hidden" name="action" value="merge_authors">
                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                            <input type="hidden" name="sources[]" value="<?= h($suspect['nom']) ?>">
                            <input type="text" name="target" list="auteur-names-list" value="<?= h($suspect['suggestion']) ?>" placeholder="Nom corrigé..." aria-label="Nom corrigé pour <?= h($suspect['nom']) ?>">
                            <button type="submit" class="btn-primary btn-small">Corriger</button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="action" value="ignore_suspect">
                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                            <input type="hidden" name="nom" value="<?= h($suspect['nom']) ?>">
                            <button type="submit" class="btn-secondary btn-small">Le nom est correct</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </details>
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
                    <label>Rôle</label>
                    <select name="role">
                        <option value="">Tous les rôles</option>
                        <?php foreach ($allRoles as $roleOption): ?>
                            <option value="<?= h($roleOption) ?>" <?= $filterRole === $roleOption ? 'selected' : '' ?>><?= h($roleOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label>Trier par</label>
                    <select name="tri">
                        <option value="alpha" <?= $tri === 'alpha' ? 'selected' : '' ?>>Nom de famille (A-Z)</option>
                        <option value="prenom" <?= $tri === 'prenom' ? 'selected' : '' ?>>Prénom (A-Z)</option>
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

            <?php if ($search || $filterSupport || $filterTag || $filterRole || $tri !== 'alpha' || $bioManquante): ?>
                <div style="margin-top: 10px;">
                    <a href="auteurs.php" class="btn-secondary" style="font-size: 0.9em;">Réinitialiser les filtres</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($authors)): ?>
            <div class="empty-state">
                <div class="empty-icon"><?= icone('plume') ?></div>
                <h3><?= ($search || $filterSupport || $filterTag || $filterRole || $bioManquante) ? 'Aucun auteur trouvé' : 'Aucun auteur pour le moment' ?></h3>
                <p><?= ($search || $filterSupport || $filterTag || $filterRole || $bioManquante) ? 'Essayez d\'autres critères.' : 'Ajoutez des livres à votre collection pour voir apparaître leurs auteurs ici.' ?></p>
            </div>
        <?php else: ?>
            <p class="auteur-total">
                <?= count($authors) ?> auteur<?= count($authors) > 1 ? 's' : '' ?>
                <a href="<?= h('auteurs.php?' . http_build_query(array_filter([
                    'q' => $search, 'filter' => $filterSupport, 'tag' => $filterTag, 'role' => $filterRole, 'tri' => $tri,
                    'bio_manquante' => $bioManquante ? '1' : '', 'export' => 'csv'
                ]))) ?>" class="btn-secondary btn-small auteur-export-link"><?= icone('telecharger') ?> Exporter en CSV</a>
            </p>

            <?php
            // Index alphabétique : uniquement quand la liste est triée par nom ou par prénom
            $avecIndex = in_array($tri, ['alpha', 'prenom'], true);
            $champTri = $tri === 'prenom' ? 'cle_prenom' : 'cle_nom';
            $initiales = [];
            if ($avecIndex) {
                foreach ($authors as $a) {
                    $initiales[initialeAuteur($a[$champTri])] = true;
                }
            }
            $lettreCourante = null;
            ?>
            <?php if ($avecIndex && count($initiales) > 1): ?>
                <nav class="auteurs-index" aria-label="Index alphabétique">
                    <?php foreach (array_keys($initiales) as $lettre): ?>
                        <a href="#lettre-<?= h($lettre === '#' ? 'autre' : $lettre) ?>"><?= h($lettre) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <div class="auteurs-grid">
                <?php foreach ($authors as $auteur): ?>
                    <?php if ($avecIndex && ($lettre = initialeAuteur($auteur[$champTri])) !== $lettreCourante): $lettreCourante = $lettre; ?>
                        <h2 class="auteur-lettre" id="lettre-<?= h($lettre === '#' ? 'autre' : $lettre) ?>"><?= h($lettre) ?></h2>
                    <?php endif; ?>
                    <details class="auteur-card">
                        <summary class="auteur-summary">
                            <?php if (!empty($auteur['image_url'])): ?>
                                <img class="auteur-photo-small" src="<?= h($auteur['image_url']) ?>" alt="" loading="lazy" decoding="async">
                            <?php else: ?>
                                <span class="auteur-photo-placeholder"><?= icone('plume') ?></span>
                            <?php endif; ?>
                            <?php
                            // Met le nom de famille en gras dans le nom complet (qui reste affiché tel qu'il est enregistré)
                            $posNom = $auteur['nom_famille'] !== '' ? mb_strrpos($auteur['nom'], $auteur['nom_famille']) : false;
                            ?>
                            <span class="auteur-nom"><?php if ($posNom !== false): ?><?= h(mb_substr($auteur['nom'], 0, $posNom)) ?><strong class="auteur-nom-famille"><?= h($auteur['nom_famille']) ?></strong><?= h(mb_substr($auteur['nom'], $posNom + mb_strlen($auteur['nom_famille']))) ?><?php else: ?><?= h($auteur['nom']) ?><?php endif; ?></span>
                            <span class="auteur-count"><?= count($auteur['livres']) ?> livre<?= count($auteur['livres']) > 1 ? 's' : '' ?></span>
                        </summary>

                        <div class="auteur-bio" data-nom="<?= h($auteur['nom']) ?>">
                            <p class="auteur-bio-loading">Chargement de la biographie...</p>
                        </div>

                        <button type="button" class="btn-edit-bio" data-nom="<?= h($auteur['nom']) ?>" onclick="openEditAuteurModal(this.dataset.nom)"><?= icone('crayon') ?> Éditer l'auteur</button>

                        <div class="auteur-livres">
                            <?php foreach ($auteur['livres'] as $livre): ?>
                                <a href="index.php?edit=<?= $livre['id'] ?>" class="auteur-livre-card">
                                    <span class="auteur-livre-cover">
                                        <?php $imageUrl = getImageUrl($livre['couverture']); ?>
                                        <img loading="lazy" decoding="async" src="<?= h($imageUrl) ?>"
                                             alt="Couverture de <?= h($livre['titre']) ?>"
                                             onerror="this.parentElement.innerHTML='<div class=&quot;cover-placeholder&quot;><?= h(icone('livres')) ?></div>';">
                                    </span>
                                    <span class="auteur-livre-titre"><?= h($livre['titre']) ?></span>
                                    <?php if ($livre['roles'] !== [AuteurRepository::ROLE_DEFAUT]): ?>
                                        <span class="auteur-livre-role"><?= h(implode(', ', $livre['roles'])) ?></span>
                                    <?php endif; ?>
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

            <h3>Éditer l'auteur</h3>
            <p id="editAuteurNomLabel" class="edit-auteur-nom"></p>

            <form method="POST" id="editAuteurForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_biographie">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="nom" id="editAuteurNom" value="">

                <div style="display:flex; gap:15px; flex-wrap:wrap;">
                    <div class="form-group" style="flex:1; min-width:150px;">
                        <label for="editAuteurPrenom">Prénom :</label>
                        <input type="text" id="editAuteurPrenom" name="prenom" maxlength="150">
                    </div>
                    <div class="form-group" style="flex:1; min-width:150px;">
                        <label for="editAuteurNomFamille">Nom de famille :</label>
                        <input type="text" id="editAuteurNomFamille" name="nom_famille" maxlength="150">
                    </div>
                </div>
                <small class="edit-auteur-aide">Utilisés pour trier les auteurs par nom de famille. Déterminés automatiquement à partir du nom complet ; corrigez-les si besoin.</small>

                <details class="form-group auteur-roles-section" id="editAuteurRoles">
                    <summary>Rôles sur ses livres <span id="editAuteurRolesResume" class="auteur-roles-resume"></span></summary>
                    <input type="hidden" name="roles_envoyes" id="editAuteurRolesEnvoyes" value="">
                    <div id="editAuteurRolesListe" class="auteur-roles-liste"><p class="auteur-bio-loading">Chargement des livres...</p></div>
                    <small class="edit-auteur-aide">Cochez un ou plusieurs rôles par livre (« Auteur » si rien n'est coché). La ligne « Tous les livres » applique un rôle à toute la liste.</small>
                </details>

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
        // Icônes SVG utilisées par le code JavaScript (générées côté PHP)
        const ICONE_NAISSANCE = <?= json_encode(icone('calendrier')) ?>;
        const ICONE_NATIONALITE = <?= json_encode(icone('globe')) ?>;
        const ICONE_ACTUALISER = <?= json_encode(icone('actualiser')) ?>;
        const ROLES_AUTEURS = <?= json_encode($allRoles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const ROLE_DEFAUT = <?= json_encode(AuteurRepository::ROLE_DEFAUT) ?>;

        // Échappe du texte avant de l'insérer dans du HTML
        function echapper(texte) {
            const div = document.createElement('div');
            div.textContent = texte;
            return div.innerHTML;
        }

    document.querySelectorAll('.auteur-card').forEach(function(details) {
        details.addEventListener('toggle', function() {
            if (!details.open) return;

            const bioDiv = details.querySelector('.auteur-bio');
            if (bioDiv.dataset.loaded === '1') return;
            bioDiv.dataset.loaded = '1';

            loadBiographie(bioDiv, bioDiv.dataset.nom, false);
        });
    });

    const CSRF_TOKEN = <?= json_encode(generateCSRFToken()) ?>;

    function loadBiographie(bioDiv, nom, force) {
        bioDiv.innerHTML = '<p class="auteur-bio-loading">Chargement de la biographie...</p>';
        const url = 'get_biographie.php?nom=' + encodeURIComponent(nom);
        // « Relancer » modifie la base : c'est un POST avec le jeton CSRF ; la simple lecture reste un GET
        const options = force ? { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN } } : {};

        fetch(url, options)
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
                        parts.push(ICONE_NAISSANCE + ' ' + echapper(data.naissance + (data.deces ? ' – ' + data.deces : '')));
                    }
                    if (data.nationalite) {
                        parts.push(ICONE_NATIONALITE + ' ' + echapper(data.nationalite));
                    }
                    meta.innerHTML = parts.join(' · ');
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
                refreshBtn.innerHTML = ICONE_ACTUALISER + ' Relancer la recherche Wikipédia';
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
        document.getElementById('editAuteurPrenom').value = '';
        document.getElementById('editAuteurNomFamille').value = '';
        reinitialiserRolesAuteur();

        fetch('get_biographie.php?nom=' + encodeURIComponent(nom))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    document.getElementById('editAuteurBio').value = data.biographie || '';
                    document.getElementById('editAuteurImage').value = data.image_url || '';
                    document.getElementById('editAuteurNaissance').value = data.naissance || '';
                    document.getElementById('editAuteurDeces').value = data.deces || '';
                    document.getElementById('editAuteurNationalite').value = data.nationalite || '';
                    document.getElementById('editAuteurPrenom').value = data.prenom || '';
                    document.getElementById('editAuteurNomFamille').value = data.nom_famille || '';
                    afficherRolesAuteur(data.livres || []);
                }
            })
            .catch(function() {});

        document.getElementById('editAuteurModal').style.display = 'flex';
    }

    // ----- Rôles de l'auteur sur chacun de ses livres (cases à cocher, une ligne par livre)
    function reinitialiserRolesAuteur() {
        document.getElementById('editAuteurRolesEnvoyes').value = '';
        document.getElementById('editAuteurRolesListe').innerHTML = '<p class="auteur-bio-loading">Chargement des livres...</p>';
        document.getElementById('editAuteurRolesResume').textContent = '';
        document.getElementById('editAuteurRoles').open = false;
    }

    function afficherRolesAuteur(livres) {
        const liste = document.getElementById('editAuteurRolesListe');
        liste.innerHTML = '';

        const ligneRoles = function(titre, estTous) {
            const ligne = document.createElement('div');
            ligne.className = 'auteur-roles-ligne' + (estTous ? ' auteur-roles-tous' : '');
            const libelle = document.createElement('span');
            libelle.className = 'auteur-roles-titre';
            libelle.textContent = titre;
            ligne.appendChild(libelle);
            return ligne;
        };

        const entete = ligneRoles('Tous les livres', true);
        ROLES_AUTEURS.forEach(function(role) {
            const label = document.createElement('label');
            label.className = 'auteur-role-case';
            const caseTous = document.createElement('input');
            caseTous.type = 'checkbox';
            caseTous.addEventListener('change', function() {
                liste.querySelectorAll('input[data-role="' + role + '"]').forEach(function(c) { c.checked = caseTous.checked; });
                mettreAJourResumeRoles();
            });
            label.appendChild(caseTous);
            label.appendChild(document.createTextNode(' ' + role));
            entete.appendChild(label);
        });
        liste.appendChild(entete);

        livres.forEach(function(livre) {
            const ligne = ligneRoles(livre.titre, false);
            const ident = document.createElement('input');
            ident.type = 'hidden';
            ident.name = 'livres_ids[]';
            ident.value = livre.id;
            ligne.appendChild(ident);
            ROLES_AUTEURS.forEach(function(role) {
                const label = document.createElement('label');
                label.className = 'auteur-role-case';
                const c = document.createElement('input');
                c.type = 'checkbox';
                c.name = 'roles[' + livre.id + '][]';
                c.value = role;
                c.dataset.role = role;
                c.checked = livre.roles.indexOf(role) !== -1;
                c.addEventListener('change', mettreAJourResumeRoles);
                label.appendChild(c);
                label.appendChild(document.createTextNode(' ' + role));
                ligne.appendChild(label);
            });
            liste.appendChild(ligne);
        });

        document.getElementById('editAuteurRolesEnvoyes').value = '1';
        mettreAJourResumeRoles();
        // Ouvert d'office quand l'auteur a déjà un rôle autre que « Auteur »
        document.getElementById('editAuteurRoles').open = livres.some(function(l) {
            return l.roles.some(function(r) { return r !== ROLE_DEFAUT; });
        });
    }

    function mettreAJourResumeRoles() {
        const comptes = {};
        document.querySelectorAll('#editAuteurRolesListe input[data-role]:checked').forEach(function(c) {
            comptes[c.dataset.role] = (comptes[c.dataset.role] || 0) + 1;
        });
        document.getElementById('editAuteurRolesResume').textContent = ROLES_AUTEURS
            .filter(function(r) { return comptes[r]; })
            .map(function(r) { return r + ' × ' + comptes[r]; })
            .join(' · ');
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
