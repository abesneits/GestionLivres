<!DOCTYPE html>
<html lang="fr">
<?php
include 'includes/head.php';
renderHead('Achats à effectuer - Ma Collection');
$libellesPriorite = [1 => 'Haute', 2 => 'Normale', 3 => 'Basse'];
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
            <h1><?= icone('panier') ?> Achats à effectuer</h1>
            <p>Les livres que vous souhaitez acheter. Une fois achetés, validez-les pour les ajouter à votre collection.</p>
        </div>

        <!-- Liste des achats -->
        <div class="actions-bar">
            <strong><?= count($achats) ?> livre<?= count($achats) > 1 ? 's' : '' ?> à acheter</strong>
            <?php if ($totalEstime > 0): ?>
                — total estimé : <strong><?= number_format($totalEstime, 2, ',', ' ') ?> €</strong>
            <?php endif; ?>
            <form method="GET" style="display:inline;margin-left:15px;">
                <label for="tri">Trier par</label>
                <select id="tri" name="tri" onchange="this.form.submit()">
                    <option value="priorite" <?= $tri === 'priorite' ? 'selected' : '' ?>>Priorité</option>
                    <option value="titre" <?= $tri === 'titre' ? 'selected' : '' ?>>Titre</option>
                    <option value="auteur" <?= $tri === 'auteur' ? 'selected' : '' ?>>Auteur</option>
                    <option value="date" <?= $tri === 'date' ? 'selected' : '' ?>>Date d'ajout</option>
                </select>
            </form>
            <?php if (!empty($achats)): ?>
                <a href="export_achats.php?tri=<?= h($tri) ?>" target="_blank" class="btn-secondary"><?= icone('imprimante') ?> Version imprimable</a>
            <?php endif; ?>
        </div>

        <?php if (empty($achats)): ?>
            <div class="empty-state">
                <div class="empty-icon"><?= icone('panier') ?></div>
                <h3>Aucun achat prévu</h3>
                <p>Ajoutez ci-dessus les livres que vous voulez acheter.</p>
            </div>
        <?php else: ?>
            <div class="achats-grid">
            <?php foreach ($achats as $a): ?>
                <div class="book-item">
                    <div class="book-item-cover">
                        <?php if ($a['couverture']): ?>
                            <img src="<?= h(getImageUrl($a['couverture'])) ?>" alt="Couverture de <?= h($a['titre']) ?>">
                        <?php else: ?>
                            <div class="cover-placeholder-small"><?= icone('livres') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="book-item-info">
                        <div class="book-item-title"><?= h($a['titre']) ?><?= $a['serie'] ? ' — ' . h($a['serie']) . ($a['tome'] !== null ? ' T' . (int)$a['tome'] : '') : '' ?></div>
                        <div class="book-item-author"><?= h($a['auteur'] ?? '') ?></div>
                        <div class="book-item-meta">
                            <?= $libellesPriorite[(int)$a['priorite']] ?? '' ?> ·
                            <?= h($a['support']) ?> · <?= h($a['type_livre']) ?>
                            <?php if ($a['isbn']): ?> · ISBN <?= h($a['isbn']) ?><?php endif; ?>
                            <?php if ($a['prix_estime'] !== null): ?> · <?= number_format((float)$a['prix_estime'], 2, ',', ' ') ?> €<?php endif; ?>
                            <?php if ($a['lieu_achat']): ?> · <?= h($a['lieu_achat']) ?><?php endif; ?>
                            <?php if ($a['tags']): ?> · <?= icone('etiquette') ?> <?= h($a['tags']) ?><?php endif; ?>
                        </div>
                        <?php if ($a['note']): ?><div class="book-item-meta"><?= icone('note') ?> <?= h($a['note']) ?></div><?php endif; ?>
                        <div class="liste-actions" style="margin-top:8px;">
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Valider l\'achat et ajouter ce livre à la collection ?');">
                                <input type="hidden" name="action" value="valider_achat">
                                <input type="hidden" name="achat_id" value="<?= (int)$a['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <select name="statut" title="Statut dans la collection">
                                    <option value="À lire">À lire</option>
                                    <option value="En cours">En cours</option>
                                    <option value="Lu">Lu</option>
                                </select>
                                <button type="submit" class="btn-primary"><?= icone('valider') ?> Acheté</button>
                            </form>
                            <a href="?edit=<?= (int)$a['id'] ?>#formulaire" class="btn-edit" title="Éditer" aria-label="Éditer"><?= icone('crayon') ?></a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Retirer ce livre des achats ?');">
                                <input type="hidden" name="action" value="delete_achat">
                                <input type="hidden" name="achat_id" value="<?= (int)$a['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <button type="submit" class="btn-danger" title="Supprimer" aria-label="Supprimer"><?= icone('corbeille') ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Formulaire d'ajout / modification -->
        <div class="form-card" id="formulaire">
            <h2><?= $editId ? icone('crayon') . ' Modifier l\'achat' : icone('plus') . ' Nouvel achat' ?></h2>

            <form method="POST" action="achats.php#formulaire" id="formRecherche" style="margin-bottom:15px;">
                <input type="hidden" name="action" value="search_isbn">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="achat_id" value="<?= $editId ?>">
                <?php foreach (['titre', 'auteur', 'support', 'type_livre', 'serie', 'tome', 'couverture', 'description', 'date_publication', 'tags', 'priorite', 'prix_estime', 'lieu_achat', 'note'] as $c): ?>
                    <input type="hidden" name="<?= $c ?>" value="<?= h($form[$c]) ?>">
                <?php endforeach; ?>
                <div class="form-group">
                    <label for="isbn_recherche">ISBN (pré-remplit le formulaire)</label>
                    <input type="text" id="isbn_recherche" name="isbn" value="<?= h($form['isbn']) ?>" placeholder="978-2-07-061275-8">
                </div>
                <button type="submit" class="btn-secondary"><?= icone('loupe') ?> Chercher par ISBN</button>
            </form>

            <form method="POST" action="achats.php#formulaire">
                <input type="hidden" name="action" value="save_achat">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="achat_id" value="<?= $editId ?>">
                <input type="hidden" name="isbn" value="<?= h($form['isbn']) ?>">
                <input type="hidden" name="couverture" value="<?= h($form['couverture']) ?>">
                <input type="hidden" name="description" value="<?= h($form['description']) ?>">
                <input type="hidden" name="date_publication" value="<?= h($form['date_publication']) ?>">

                <?php if ($form['couverture']): ?>
                    <p><img src="<?= h(getImageUrl($form['couverture'])) ?>" alt="Couverture" style="max-height:120px;"></p>
                <?php endif; ?>

                <div class="form-group">
                    <label for="titre">Titre *</label>
                    <input type="text" id="titre" name="titre" value="<?= h($form['titre']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="auteur">Auteur</label>
                    <input type="text" id="auteur" name="auteur" value="<?= h($form['auteur']) ?>" placeholder="Prénom Nom (ex. : Victor Hugo)">
                    <small class="auteur-aide">Écrivez « Prénom Nom ». Plusieurs auteurs : séparez-les par une virgule.</small>
                </div>
                <div class="form-group">
                    <label for="support">Support</label>
                    <select id="support" name="support">
                        <?php foreach ($supportsDispo as $s): ?>
                            <option value="<?= h($s) ?>" <?= $form['support'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="type_livre">Type</label>
                    <select id="type_livre" name="type_livre">
                        <option value="Papier" <?= $form['type_livre'] === 'Papier' ? 'selected' : '' ?>>Papier</option>
                        <option value="Numérique" <?= $form['type_livre'] === 'Numérique' ? 'selected' : '' ?>>Numérique</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="serie">Série</label>
                    <input type="text" id="serie" name="serie" value="<?= h($form['serie']) ?>">
                </div>
                <div class="form-group">
                    <label for="tome">Tome</label>
                    <input type="number" id="tome" name="tome" min="0" value="<?= h($form['tome']) ?>">
                </div>
                <div class="form-group">
                    <label for="tagsInputField">Tags</label>
                    <div class="tag-input-container" id="tagsContainer">
                        <input type="text" class="tag-input-field" id="tagsInputField" placeholder="Ajouter un tag...">
                    </div>
                    <input type="hidden" name="tags" id="tags" value="<?= h($form['tags']) ?>">
                </div>
                <div class="form-group">
                    <label for="priorite">Priorité</label>
                    <select id="priorite" name="priorite">
                        <?php foreach ($libellesPriorite as $val => $lib): ?>
                            <option value="<?= $val ?>" <?= (int)$form['priorite'] === $val ? 'selected' : '' ?>><?= $lib ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="prix_estime">Prix estimé (€)</label>
                    <input type="text" id="prix_estime" name="prix_estime" inputmode="decimal" value="<?= h($form['prix_estime']) ?>" placeholder="12,50">
                </div>
                <div class="form-group">
                    <label for="lieu_achat">Où l'acheter</label>
                    <input type="text" id="lieu_achat" name="lieu_achat" value="<?= h($form['lieu_achat']) ?>" placeholder="Librairie, Fnac, occasion...">
                </div>
                <div class="form-group">
                    <label for="note">Note</label>
                    <textarea id="note" name="note" rows="2"><?= h($form['note']) ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-add-book"><?= $editId ? icone('disquette') . ' Enregistrer' : icone('plus') . ' Ajouter aux achats' ?></button>
                    <?php if ($editId): ?>
                        <a href="achats.php#formulaire" class="btn-secondary">Annuler</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script src="tag-input.js"></script>
    <script src="auteur-input.js"></script>
    <script>initAuteurInput(document.getElementById('auteur'), <?= json_encode($allAuteurs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);</script>
    <script>
        const allTags = <?= json_encode($allTags, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        document.addEventListener('DOMContentLoaded', function () {
            initTagInput(document.getElementById('tagsContainer'), document.getElementById('tags'), allTags);
        });
    </script>
</body>
</html>
