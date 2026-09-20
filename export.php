<?php
/**
 * Page d'export de la collection : recherche/filtre/tri avancés,
 * puis export du résultat dans le format choisi (HTML, Excel, CSV, JSON, TXT).
 */

require_once 'includes/bootstrap.php';
require_once 'includes/export_functions.php';

$supportTypes = $bookManager->getSupportTypesWithCounts();
$criteres = sanitizeAdvancedSearchParams($_GET, array_keys($supportTypes));
$formatsValides = ['csv', 'excel', 'json', 'html', 'txt'];
$format = $_GET['format'] ?? '';

$perPageOptions = [20, 50, 100];
$perPageRequested = (int)($_GET['per_page'] ?? 20);
$perPage = in_array($perPageRequested, $perPageOptions, true) ? $perPageRequested : 20;

$filtreActif = $criteres['search'] !== ''
    || $criteres['support'] !== ''
    || $criteres['statut'] !== ''
    || $criteres['serie'] !== ''
    || !empty($criteres['tags'])
    || $criteres['date_from'] !== ''
    || $criteres['date_to'] !== ''
    || $criteres['sans_couverture']
    || $criteres['sans_note'];

// Signature stable des critères de recherche (hors pagination/format), utilisée côté
// client pour savoir si une sélection de livres mémorisée correspond toujours à la
// recherche en cours, ou si elle doit être réinitialisée.
$signatureParams = $_GET;
unset($signatureParams['page'], $signatureParams['per_page'], $signatureParams['livres_ids'], $signatureParams['format'], $signatureParams['ajax']);
ksort($signatureParams);
$filterSignature = http_build_query($signatureParams);

/**
 * Construit une description lisible des filtres actifs pour l'inclure
 * dans les métadonnées du fichier exporté.
 */
function buildFilterSummary($criteres, $filtreActif) {
    if (!$filtreActif) {
        return '';
    }

    $parts = [];
    if ($criteres['search'] !== '') {
        $parts[] = 'Recherche : "' . $criteres['search'] . '"';
    }
    if ($criteres['support'] !== '') {
        $parts[] = 'Support : ' . $criteres['support'];
    }
    if ($criteres['statut'] !== '') {
        $parts[] = 'Statut : ' . $criteres['statut'];
    }
    if ($criteres['serie'] !== '') {
        $parts[] = 'Série : ' . $criteres['serie'];
    }
    if (!empty($criteres['tags'])) {
        $glue = $criteres['tags_mode'] === 'or' ? ' ou ' : ' et ';
        $parts[] = 'Tags : ' . implode($glue, $criteres['tags']);
    }
    if ($criteres['date_from'] !== '' || $criteres['date_to'] !== '') {
        $parts[] = 'Ajoutés entre ' . ($criteres['date_from'] ?: '…') . ' et ' . ($criteres['date_to'] ?: '…');
    }
    if ($criteres['sans_couverture']) {
        $parts[] = 'Sans couverture';
    }
    if ($criteres['sans_note']) {
        $parts[] = 'Sans note personnelle';
    }

    return implode(' | ', $parts);
}

/**
 * Rend le fragment HTML "Résultats de la recherche" (compteur, sélection,
 * liste des livres, pagination). Utilisé à la fois pour le rendu initial de
 * la page et pour les rafraîchissements AJAX, afin de ne pas dupliquer le HTML.
 */
function renderExportResults($previewBooks, $paginationInfo, $totalResultats) {
    ob_start();
    ?>
    <h3>Résultats de la recherche</h3>
    <p style="color:#666; margin-bottom: 15px;">
        <strong><?= number_format($totalResultats) ?></strong> livre<?= $totalResultats > 1 ? 's' : '' ?> correspondant<?= $totalResultats > 1 ? 's' : '' ?> aux critères ci-dessus.
    </p>

    <?php if (empty($previewBooks)): ?>
        <p style="color:#999;">Aucun livre ne correspond à ces critères.</p>
    <?php else: ?>
        <div class="selection-info">
            <span id="exportSelectionCount">Aucune sélection (l'export portera sur tous les résultats filtrés)</span>
            <button type="button" onclick="selectAllExportBooks()" class="btn-secondary btn-small">Tout sélectionner (page)</button>
            <button type="button" onclick="deselectAllExportBooks()" class="btn-secondary btn-small">Désélectionner (page)</button>
            <button type="button" onclick="clearExportSelection()" class="btn-secondary btn-small">Vider toute la sélection</button>
        </div>

        <div class="books-list">
            <?php foreach ($previewBooks as $book): ?>
                <label class="book-item">
                    <input type="checkbox" name="livres_ids[]" value="<?= $book['id'] ?>" onchange="updateExportSelectionCount(this)">
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

        <?= generatePagination($paginationInfo) ?>
        <?php if ($paginationInfo['total_pages'] > 1): ?>
            <p style="color:#999; font-size:0.85em;">La sélection cochée est mémorisée d'une page à l'autre pour cette même recherche.</p>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// IDs cochés manuellement dans la liste de résultats, pour affiner la sélection
// au-delà des seuls critères de recherche (voir buildAdvancedWhere()).
$selectedIds = [];
if (!empty($_GET['livres_ids']) && is_array($_GET['livres_ids'])) {
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $_GET['livres_ids']))));
}

// Génération et téléchargement direct du fichier si un format valide est demandé
if (in_array($format, $formatsValides, true)) {
    $books = $bookManager->searchBooksAdvanced($criteres, 1, 999999);

    if (!empty($selectedIds)) {
        $idsVoulus = array_flip($selectedIds);
        $books = array_values(array_filter($books, function($book) use ($idsVoulus) {
            return isset($idsVoulus[(int)$book['id']]);
        }));
    }

    $sortLabels = [
        'titre_asc' => 'Titre A-Z',
        'titre_desc' => 'Titre Z-A',
        'date_desc' => 'Plus récents',
        'date_asc' => 'Plus anciens',
        'serie_asc' => 'Série puis tome',
    ];

    $descriptionParts = buildFilterSummary($criteres, $filtreActif);
    if (!empty($selectedIds)) {
        $descriptionParts = trim($descriptionParts . ($descriptionParts !== '' ? ' | ' : '') . 'Sélection manuelle : ' . count($books) . ' livre(s)');
    }

    $meta = [
        'nom' => ($filtreActif || !empty($selectedIds)) ? 'Export de recherche - Ma Collection' : 'Ma Collection de Livres',
        'description' => trim($descriptionParts
            . ($descriptionParts !== '' ? ' | ' : '') . 'Tri : ' . ($sortLabels[$criteres['sort']] ?? $criteres['sort']), ' |')
    ];

    $filename = 'export_collection_' . date('Y-m-d');

    switch ($format) {
        case 'csv':
            exportCSV($meta, $books, $filename);
            break;
        case 'excel':
            exportExcel($meta, $books, $filename);
            break;
        case 'json':
            exportJSON($meta, $books, $filename);
            break;
        case 'html':
            exportHTML($meta, $books, $filename);
            break;
        case 'txt':
            exportTXT($meta, $books, $filename);
            break;
    }
    exit;
}

// Sinon, calculer les résultats à afficher (page HTML complète ou fragment AJAX)
$totalResultats = $bookManager->countBooksAdvanced($criteres);
$paginationInfo = $bookManager->getPaginationInfo($totalResultats, $criteres['page'], $perPage);
$previewBooks = $bookManager->searchBooksAdvanced($criteres, $criteres['page'], $perPage);

// Rafraîchissement AJAX : ne renvoyer que le fragment de résultats, en JSON.
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'html' => renderExportResults($previewBooks, $paginationInfo, $totalResultats),
        'total' => $totalResultats,
        'signature' => $filterSignature,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$allTags = $bookManager->getAllTags();
?>

<!DOCTYPE html>
<html lang="fr">
<?php
include 'includes/head.php';
renderHead('Export - Ma Collection');
?>
<body>

    <?php include 'includes/menu.php'; ?>

    <div class="container">

        <div class="page-header">
            <h1>📤 Export de la collection</h1>
            <p>Filtrez et triez votre collection, puis choisissez le format d'export.</p>
        </div>

        <form method="GET" id="exportForm">
        <input type="hidden" id="filterSignature" value="<?= h($filterSignature) ?>">
        <details class="search-section search-section-collapsible" open>
            <summary>Recherche avancée <span id="filtreActifBadge" class="filtre-actif-badge" style="<?= $filtreActif ? '' : 'display:none;' ?>">filtres actifs</span></summary>

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
                        <label>Série</label>
                        <select name="serie">
                            <option value="">Toutes</option>
                            <?php foreach ($bookManager->getAllSeries() as $nomSerie => $nbSerie): ?>
                                <option value="<?= h($nomSerie) ?>" <?= $criteres['serie'] === $nomSerie ? 'selected' : '' ?>><?= h($nomSerie) ?> (<?= (int)$nbSerie ?>)</option>
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
                            <option value="serie_asc" <?= $criteres['sort'] === 'serie_asc' ? 'selected' : '' ?>>Série puis tome</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1; min-width:150px;">
                        <label>Résultats par page</label>
                        <select name="per_page">
                            <?php foreach ($perPageOptions as $pp): ?>
                                <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                            <?php endforeach; ?>
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

                <?php if (!empty($allTags)): ?>
                    <div class="form-group">
                        <label>Tags
                            <select name="tags_mode" style="width:auto; display:inline-block; margin-left:10px;">
                                <option value="and" <?= $criteres['tags_mode'] === 'and' ? 'selected' : '' ?>>ET (tous les tags cochés)</option>
                                <option value="or" <?= $criteres['tags_mode'] === 'or' ? 'selected' : '' ?>>OU (au moins un)</option>
                            </select>
                        </label>
                        <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:10px;">
                            <?php foreach ($allTags as $tag): ?>
                                <label style="font-weight:normal; display:inline-flex; align-items:center; gap:5px;">
                                    <input type="checkbox" name="tags[]" value="<?= h($tag) ?>" style="width:auto;" <?= in_array($tag, $criteres['tags']) ? 'checked' : '' ?>>
                                    <?= h($tag) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary">🔍 Filtrer</button>
                <a href="export.php" class="btn-secondary">Réinitialiser</a>
        </details>

        <div class="search-section" id="exportResults"><?= renderExportResults($previewBooks, $paginationInfo, $totalResultats) ?></div>

        <div class="search-section">
            <h3>Format d'export</h3>
            <p style="color:#666; margin-bottom: 15px;">
                Si vous avez coché des livres ci-dessus, seuls ceux-ci seront exportés. Sinon, l'export portera sur l'ensemble des <span id="formatSectionTotal"><?= number_format($totalResultats) ?></span> résultat(s) filtré(s).
            </p>
            <div class="export-format-choices" id="formatButtonsWrap" style="<?= $totalResultats > 0 ? '' : 'display:none;' ?>">
                <button type="submit" name="format" value="html" class="btn-secondary"><span class="export-icon">📄</span> HTML</button>
                <button type="submit" name="format" value="excel" class="btn-secondary"><span class="export-icon">📊</span> Excel</button>
                <button type="submit" name="format" value="csv" class="btn-secondary"><span class="export-icon">📋</span> CSV</button>
                <button type="submit" name="format" value="json" class="btn-secondary"><span class="export-icon">🔧</span> JSON</button>
                <button type="submit" name="format" value="txt" class="btn-secondary"><span class="export-icon">📝</span> TXT</button>
            </div>
            <p id="formatNoBooksMsg" style="color:#999; <?= $totalResultats > 0 ? 'display:none;' : '' ?>">Aucun livre à exporter avec ces critères.</p>
        </div>
        </form>

    </div>

    <style>
        .export-format-choices {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .export-format-choices button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .export-format-choices .export-icon {
            font-size: 18px;
        }
    </style>

    <script>
    (function() {
        const form = document.getElementById('exportForm');
        const resultsEl = document.getElementById('exportResults');
        const signatureInput = document.getElementById('filterSignature');
        const STORAGE_KEY = 'export_selection_v1';

        function loadState() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY);
                if (!raw) return null;
                const parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object' || !Array.isArray(parsed.ids)) return null;
                return parsed;
            } catch (e) {
                return null;
            }
        }

        function saveState(state) {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
            } catch (e) {
                // Stockage indisponible (navigation privée...) : la sélection ne persistera pas.
            }
        }

        // Récupère la sélection mémorisée si elle correspond aux critères actuels,
        // sinon repart d'une sélection vide (nouvelle recherche = nouvelle sélection).
        function getState() {
            const signature = signatureInput ? signatureInput.value : '';
            let state = loadState();
            if (!state || state.signature !== signature) {
                state = { signature: signature, ids: [] };
                saveState(state);
            }
            return state;
        }

        function setSelected(ids) {
            const signature = signatureInput ? signatureInput.value : '';
            saveState({ signature: signature, ids: Array.from(new Set(ids)) });
        }

        function refreshSelectionCounter() {
            const count = getState().ids.length;
            const el = document.getElementById('exportSelectionCount');
            if (!el) return;
            el.textContent = count > 0
                ? count + ' livre' + (count > 1 ? 's' : '') + ' sélectionné' + (count > 1 ? 's' : '') + " pour l'export (toutes pages confondues)"
                : "Aucune sélection (l'export portera sur tous les résultats filtrés)";
        }

        const CHECKBOX_SELECTOR = '#exportForm input[type="checkbox"][name="livres_ids[]"]';

        function applySelectionToCheckboxes() {
            const idSet = new Set(getState().ids);
            document.querySelectorAll(CHECKBOX_SELECTOR).forEach(cb => {
                cb.checked = idSet.has(cb.value);
            });
            refreshSelectionCounter();
        }

        window.updateExportSelectionCount = function(checkboxEl) {
            const idSet = new Set(getState().ids);
            if (checkboxEl) {
                if (checkboxEl.checked) {
                    idSet.add(checkboxEl.value);
                } else {
                    idSet.delete(checkboxEl.value);
                }
            }
            setSelected(Array.from(idSet));
            refreshSelectionCounter();
        };

        window.selectAllExportBooks = function() {
            const idSet = new Set(getState().ids);
            document.querySelectorAll(CHECKBOX_SELECTOR).forEach(cb => {
                cb.checked = true;
                idSet.add(cb.value);
            });
            setSelected(Array.from(idSet));
            refreshSelectionCounter();
        };

        window.deselectAllExportBooks = function() {
            const idSet = new Set(getState().ids);
            document.querySelectorAll(CHECKBOX_SELECTOR).forEach(cb => {
                cb.checked = false;
                idSet.delete(cb.value);
            });
            setSelected(Array.from(idSet));
            refreshSelectionCounter();
        };

        window.clearExportSelection = function() {
            setSelected([]);
            document.querySelectorAll(CHECKBOX_SELECTOR).forEach(cb => cb.checked = false);
            refreshSelectionCounter();
        };

        function updateFiltreActifBadge() {
            const badge = document.getElementById('filtreActifBadge');
            if (!badge) return;
            const fd = new FormData(form);
            const actif = (fd.get('search') || '').toString().trim() !== ''
                || (fd.get('support') || '').toString() !== ''
                || (fd.get('statut') || '').toString() !== ''
                || (fd.get('date_from') || '').toString() !== ''
                || (fd.get('date_to') || '').toString() !== ''
                || !!fd.get('sans_couverture')
                || !!fd.get('sans_note')
                || fd.getAll('tags[]').length > 0;
            badge.style.display = actif ? '' : 'none';
        }

        function updateFormatSection(total) {
            const totalEl = document.getElementById('formatSectionTotal');
            if (totalEl) totalEl.textContent = new Intl.NumberFormat('fr-FR').format(total);
            const buttons = document.getElementById('formatButtonsWrap');
            const noBooks = document.getElementById('formatNoBooksMsg');
            if (buttons) buttons.style.display = total > 0 ? '' : 'none';
            if (noBooks) noBooks.style.display = total > 0 ? 'none' : '';
        }

        // Valeurs actuelles du formulaire (hors bouton de format et sélection de livres)
        function currentFilterParams(extra) {
            const fd = new FormData(form);
            fd.delete('format');
            fd.delete('livres_ids[]');
            const params = new URLSearchParams();
            for (const pair of fd.entries()) {
                params.append(pair[0], pair[1]);
            }
            Object.keys(extra || {}).forEach(k => params.set(k, extra[k]));
            return params;
        }

        function fetchAndUpdate(page) {
            const params = currentFilterParams({ page: page, ajax: '1' });
            fetch('export.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => {
                    if (!r.ok) throw new Error('Réponse invalide');
                    return r.json();
                })
                .then(data => {
                    if (resultsEl) resultsEl.innerHTML = data.html;
                    if (signatureInput) signatureInput.value = data.signature;
                    updateFormatSection(data.total);
                    applySelectionToCheckboxes();
                    updateFiltreActifBadge();

                    const urlParams = currentFilterParams({ page: page });
                    history.replaceState(null, '', 'export.php?' + urlParams.toString());
                })
                .catch(function() {
                    // Repli si le fetch échoue : navigation classique en rechargement complet.
                    const params2 = currentFilterParams({ page: page });
                    window.location.href = 'export.php?' + params2.toString();
                });
        }

        // Les listes déroulantes (support, statut, tri, résultats par page...) déclenchent
        // un rafraîchissement immédiat des résultats, sans recharger toute la page.
        document.querySelectorAll('#exportForm select').forEach(select => {
            select.addEventListener('change', function() {
                fetchAndUpdate(1);
            });
        });

        // Soumission du formulaire : un clic sur un bouton de format déclenche un vrai
        // téléchargement (soumission classique) ; tout le reste (bouton Filtrer, touche
        // Entrée) ne fait que rafraîchir les résultats en AJAX.
        form.addEventListener('submit', function(e) {
            // Retirer les éventuels champs cachés injectés par un export précédent
            // (le téléchargement d'un fichier ne recharge pas la page).
            form.querySelectorAll('input[data-cross-page-selection]').forEach(el => el.remove());

            const submitter = e.submitter;
            if (submitter && submitter.name === 'format') {
                // Complète avec les livres sélectionnés sur d'autres pages avant l'envoi.
                const state = getState();
                const currentPageIds = new Set(
                    Array.from(document.querySelectorAll(CHECKBOX_SELECTOR)).map(cb => cb.value)
                );
                state.ids.filter(id => !currentPageIds.has(id)).forEach(id => {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'livres_ids[]';
                    hidden.value = id;
                    hidden.dataset.crossPageSelection = '1';
                    form.appendChild(hidden);
                });
                return;
            }
            e.preventDefault();
            fetchAndUpdate(1);
        });

        // Pagination : délégation sur le conteneur (survit aux remplacements de son contenu)
        if (resultsEl) {
            resultsEl.addEventListener('click', function(e) {
                const link = e.target.closest('a.pagination-number, a.pagination-btn');
                if (!link) return;
                e.preventDefault();
                const url = new URL(link.href, window.location.href);
                fetchAndUpdate(url.searchParams.get('page') || '1');
            });
        }

        applySelectionToCheckboxes();
        updateFiltreActifBadge();
    })();
    </script>

</body>
</html>
