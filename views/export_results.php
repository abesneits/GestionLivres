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
                            <span class="badge-small"><?= h($book['type_livre'] ?? 'Papier') ?></span>
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
