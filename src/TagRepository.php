<?php
/**
 * Tags des livres : liste, catalogue (table tags_catalogue), renommage, suppression.
 * Les tags sont stockés en chaîne CSV dans livres.tags.
 */
class TagRepository {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Obtenir tous les tags uniques
     */
    public function getAllTags() {
        $sql = "SELECT DISTINCT tags FROM livres WHERE tags IS NOT NULL AND tags != ''";
        $stmt = $this->pdo->query($sql);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $allTags = [];
        foreach ($results as $tagString) {
            $tags = explode(',', $tagString);
            foreach ($tags as $tag) {
                $tag = trim($tag);
                if ($tag && !in_array($tag, $allTags)) {
                    $allTags[] = $tag;
                }
            }
        }

        // Inclure les tags créés à l'avance (catalogue) même s'ils ne sont
        // encore rattachés à aucun livre.
        $tagsCatalogue = $this->pdo->query("SELECT nom FROM tags_catalogue")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tagsCatalogue as $tag) {
            if ($tag && !in_array($tag, $allTags)) {
                $allTags[] = $tag;
            }
        }

        sort($allTags);
        return $allTags;
    }

    /**
     * Crée la table qui mémorise les tags créés à l'avance, avant d'être
     * rattachés à un livre (permet de les proposer partout en suggestion).
     */
    public function createTagsCatalogTable() {
        $sql = "CREATE TABLE IF NOT EXISTS tags_catalogue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(100) UNIQUE NOT NULL
        )";
        $this->pdo->exec($sql);
    }

    /**
     * Ajoute un tag au catalogue (visible partout en suggestion), sans le
     * rattacher à un livre en particulier.
     */
    public function addTagToCatalog($nom) {
        $nom = trim($nom);
        if ($nom === '') {
            throw new Exception("Nom de tag invalide.");
        }
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO tags_catalogue (nom) VALUES (:nom)");
        $stmt->execute([':nom' => $nom]);
    }

    /**
     * Construit un fragment de condition SQL pour un match EXACT d'un tag
     * dans une liste CSV "tag1,tag2,tag3" (contrairement à un simple LIKE
     * substring, qui ferait matcher "lu" avec "relu").
     */
    public function buildTagCondition($tag, $suffixe) {
        return [
            'sql' => "(tags = :tagExact$suffixe OR tags LIKE :tagStart$suffixe OR tags LIKE :tagEnd$suffixe OR tags LIKE :tagMid$suffixe)",
            'params' => [
                ":tagExact$suffixe" => $tag,
                ":tagStart$suffixe" => $tag . ',%',
                ":tagEnd$suffixe" => '%,' . $tag,
                ":tagMid$suffixe" => '%,' . $tag . ',%',
            ],
        ];
    }

    /**
     * Découpe une chaîne de tags CSV en tags exacts (trim, sans entrées vides).
     */
    public function splitTagsExact($tagsString) {
        return array_values(array_filter(array_map('trim', explode(',', $tagsString ?? ''))));
    }

    /**
     * Compte les livres portant exactement ce tag.
     */
    public function countBooksByTag($tag) {
        $cond = $this->buildTagCondition($tag, '');
        $sql = "SELECT COUNT(*) FROM livres WHERE " . $cond['sql'];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($cond['params']);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Renomme un tag partout où il apparaît. Ne tronque jamais silencieusement :
     * un livre dont la nouvelle chaîne dépasserait 500 caractères est ignoré et
     * listé dans skipped_too_long.
     */
    public function renameTag($oldTag, $newTag) {
        $oldTag = trim($oldTag);
        $newTag = trim($newTag);
        if ($oldTag === '' || $newTag === '') {
            throw new Exception("Nom de tag invalide.");
        }

        $stmt = $this->pdo->query("SELECT id, tags FROM livres WHERE tags IS NOT NULL AND tags != ''");
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $update = $this->pdo->prepare("UPDATE livres SET tags = :tags WHERE id = :id");
        $updated = 0;
        $skipped = [];

        foreach ($books as $book) {
            $tags = $this->splitTagsExact($book['tags']);
            if (!in_array($oldTag, $tags, true)) {
                continue;
            }

            $nouveaux = array_values(array_unique(array_map(function ($t) use ($oldTag, $newTag) {
                return $t === $oldTag ? $newTag : $t;
            }, $tags)));
            $nouvelleChaine = implode(',', $nouveaux);

            if (strlen($nouvelleChaine) > 500) {
                $skipped[] = $book['id'];
                continue;
            }
            if ($nouvelleChaine !== $book['tags']) {
                $update->execute([':tags' => $nouvelleChaine, ':id' => $book['id']]);
                $updated++;
            }
        }

        // Garder le catalogue de tags synchronisé
        $this->pdo->prepare("INSERT IGNORE INTO tags_catalogue (nom) VALUES (:nom)")->execute([':nom' => $newTag]);
        $this->pdo->prepare("DELETE FROM tags_catalogue WHERE nom = :nom")->execute([':nom' => $oldTag]);

        return ['updated' => $updated, 'skipped_too_long' => $skipped];
    }

    /**
     * Retire un tag de tous les livres qui le portent.
     */
    public function deleteTagEverywhere($tag) {
        $tag = trim($tag);
        if ($tag === '') {
            throw new Exception("Nom de tag invalide.");
        }

        $stmt = $this->pdo->query("SELECT id, tags FROM livres WHERE tags IS NOT NULL AND tags != ''");
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $update = $this->pdo->prepare("UPDATE livres SET tags = :tags WHERE id = :id");
        $updated = 0;

        foreach ($books as $book) {
            $tags = $this->splitTagsExact($book['tags']);
            if (!in_array($tag, $tags, true)) {
                continue;
            }

            $nouvelleChaine = implode(',', array_values(array_diff($tags, [$tag])));
            if ($nouvelleChaine !== $book['tags']) {
                $update->execute([':tags' => $nouvelleChaine, ':id' => $book['id']]);
                $updated++;
            }
        }

        $this->pdo->prepare("DELETE FROM tags_catalogue WHERE nom = :nom")->execute([':nom' => $tag]);

        return $updated;
    }
}
