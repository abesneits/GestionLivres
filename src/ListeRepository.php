<?php
/**
 * Listes de lecture : listes, contenu et ordre des livres.
 * Tables : listes_lecture et livres_listes (liaison ordonnée avec livres).
 */
class ListeRepository {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Créer les tables pour les listes de lecture
     */
    public function createListTables() {
        try {
            // Table des listes de lecture
            $sql = "CREATE TABLE IF NOT EXISTS listes_lecture (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(255) NOT NULL,
                description TEXT,
                couverture VARCHAR(500),
                date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
                date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $this->pdo->exec($sql);
            
            // Table de liaison livres-listes
            $sql = "CREATE TABLE IF NOT EXISTS livres_listes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                livre_id INT NOT NULL,
                liste_id INT NOT NULL,
                ordre INT DEFAULT 0,
                date_ajout DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE,
                FOREIGN KEY (liste_id) REFERENCES listes_lecture(id) ON DELETE CASCADE,
                UNIQUE KEY unique_livre_liste (livre_id, liste_id)
            )";
            $this->pdo->exec($sql);
            
            // Vérifier et ajouter la colonne ordre si elle n'existe pas
            $checkColumn = "SHOW COLUMNS FROM livres_listes LIKE 'ordre'";
            $result = $this->pdo->query($checkColumn);
            
            if ($result->rowCount() == 0) {
                $alterSql = "ALTER TABLE livres_listes ADD COLUMN ordre INT DEFAULT 0 AFTER liste_id";
                $this->pdo->exec($alterSql);
            }
            
        } catch (PDOException $e) {
            error_log("Erreur création tables listes : " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Créer une nouvelle liste
     */
    public function createList($nom, $description = '', $couverture = null) {
        try {
            $sql = "INSERT INTO listes_lecture (nom, description, couverture) VALUES (:nom, :description, :couverture)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':nom' => $nom,
                ':description' => $description,
                ':couverture' => $couverture
            ]);
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("Erreur création liste : " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Mettre à jour une liste
     */
    public function updateList($id, $nom, $description = '', $couverture = null) {
        try {
            $sql = "UPDATE listes_lecture 
                    SET nom = :nom, 
                        description = :description,
                        couverture = :couverture
                    WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':id' => $id,
                ':nom' => $nom,
                ':description' => $description,
                ':couverture' => $couverture
            ]);
        } catch (PDOException $e) {
            error_log("Erreur mise à jour liste : " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Supprimer une liste
     */
    public function deleteList($id) {
        try {
            // Supprimer les associations (normalement géré par CASCADE)
            $sql = "DELETE FROM livres_listes WHERE liste_id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            
            // Supprimer la liste
            $sql = "DELETE FROM listes_lecture WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([':id' => $id]);
            
            return $result && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Erreur suppression liste : " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Récupérer toutes les listes
     */
    public function getAllLists() {
        try {
            $sql = "SELECT l.*, 
                    COUNT(ll.livre_id) as nb_livres
                    FROM listes_lecture l
                    LEFT JOIN livres_listes ll ON l.id = ll.liste_id
                    GROUP BY l.id
                    ORDER BY l.date_creation DESC";
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Erreur récupération listes : " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Récupérer une liste par ID
     */
    public function getListById($id) {
        try {
            $sql = "SELECT l.*, 
                    COUNT(ll.livre_id) as nb_livres
                    FROM listes_lecture l
                    LEFT JOIN livres_listes ll ON l.id = ll.liste_id
                    WHERE l.id = :id
                    GROUP BY l.id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Erreur récupération liste : " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtenir les livres d'une liste (triés par ordre)
     */
    public function getBooksInList($listeId) {
        $sql = "SELECT l.*, ll.ordre as ordre_liste, ll.date_ajout as date_ajout_liste 
                FROM livres l 
                INNER JOIN livres_listes ll ON l.id = ll.livre_id 
                WHERE ll.liste_id = :liste_id 
                ORDER BY ll.ordre ASC, ll.date_ajout DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':liste_id' => $listeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Ajouter un livre à une liste
     */
public function addBookToList($listeId, $livreId) {
    try {
        // Vérifier d'abord si le livre existe déjà
        $checkSql = "SELECT COUNT(*) FROM livres_listes WHERE liste_id = :liste_id AND livre_id = :livre_id";
        $checkStmt = $this->pdo->prepare($checkSql);
        $checkStmt->execute([
            ':liste_id' => $listeId,
            ':livre_id' => $livreId
        ]);
        
        if ($checkStmt->fetchColumn() > 0) {
            return false; // Déjà dans la liste
        }
        
        // Récupérer le prochain ordre
        $maxOrderSql = "SELECT COALESCE(MAX(ordre), -1) + 1 FROM livres_listes WHERE liste_id = :liste_id";
        $maxOrderStmt = $this->pdo->prepare($maxOrderSql);
        $maxOrderStmt->execute([':liste_id' => $listeId]);
        $nextOrder = $maxOrderStmt->fetchColumn();
        
        // Ajouter le livre avec l'ordre
        $sql = "INSERT INTO livres_listes (liste_id, livre_id, ordre) VALUES (:liste_id, :livre_id, :ordre)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':liste_id' => $listeId,
            ':livre_id' => $livreId,
            ':ordre' => $nextOrder
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Erreur addBookToList: " . $e->getMessage());
        throw $e;
    }
} /**
 * Mettre à jour l'ordre des livres dans une liste
 */
public function updateBookOrder($listeId, $orderedBookIds) {
    try {
        $this->pdo->beginTransaction();
        
        $sql = "UPDATE livres_listes SET ordre = :ordre WHERE liste_id = :liste_id AND livre_id = :livre_id";
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($orderedBookIds as $index => $livreId) {
            $stmt->execute([
                ':ordre' => $index,
                ':liste_id' => $listeId,
                ':livre_id' => $livreId
            ]);
        }
        
        $this->pdo->commit();
        return true;
    } catch (PDOException $e) {
        $this->pdo->rollBack();
        error_log("Erreur updateBookOrder: " . $e->getMessage());
        return false;
    }
}   
    /**
     * Retirer un livre d'une liste
     */
    public function removeBookFromList($listeId, $livreId) {
        $sql = "DELETE FROM livres_listes WHERE liste_id = :liste_id AND livre_id = :livre_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':liste_id' => $listeId,
            ':livre_id' => $livreId
        ]);
    }
    
    /**
     * Enregistrer la couverture envoyée pour une liste : taille, extension (liste blanche),
     * type réel de l'image (finfo). Renvoie le chemin enregistré, ou lève une Exception.
     */
    public function uploadCouvertureListe($fichier) {
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $typesMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if ($fichier['size'] > 5 * 1024 * 1024) {
            throw new Exception("La couverture est trop volumineuse (max 5MB)");
        }

        $extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $extensions, true)) {
            throw new Exception("Format de couverture non autorisé. Utilisez : " . implode(', ', $extensions));
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $fichier['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $typesMime, true)) {
            throw new Exception("Le fichier n'est pas une image valide");
        }

        $dossier = 'uploads/listes/';
        if (!is_dir($dossier)) {
            mkdir($dossier, 0755, true);
        }

        $chemin = $dossier . uniqid('liste_') . '.' . $extension;
        if (!move_uploaded_file($fichier['tmp_name'], $chemin)) {
            throw new Exception("Erreur lors de l'enregistrement de la couverture");
        }

        return $chemin;
    }

    /**
     * Obtenir les listes contenant un livre
     */
    public function getListsForBook($livreId) {
        $sql = "SELECT l.* FROM listes_lecture l 
                INNER JOIN livres_listes ll ON l.id = ll.liste_id 
                WHERE ll.livre_id = :livre_id 
                ORDER BY l.nom ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':livre_id' => $livreId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
