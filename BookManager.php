<?php
/**
 * Gestionnaire de Collection de Livres
 * Classe principale pour gérer les livres via ISBN
 */
class BookManager {
    private $pdo;
    private $itemsPerPage = 20; // Pour la pagination
    private $googleBooksApiKey;

    public function __construct($host, $dbname, $username, $password, $googleBooksApiKey = null) {
        try {
            $this->pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Erreur de connexion : " . $e->getMessage());
        }
        $this->googleBooksApiKey = $googleBooksApiKey ?: null;
    }
    
    /**
     * Créer la table si elle n'existe pas et vérifier/ajouter les nouvelles colonnes
     */
    public function createTable() {
        // Créer la table avec la structure de base si elle n'existe pas
        $sql = "CREATE TABLE IF NOT EXISTS livres (
            id INT AUTO_INCREMENT PRIMARY KEY,
            isbn VARCHAR(13) UNIQUE NOT NULL,
            titre VARCHAR(255) NOT NULL,
            support ENUM('Livre', 'Bande dessinée', 'Manga') NOT NULL,
            auteur VARCHAR(255),
            date_publication VARCHAR(20),
            date_ajout TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
        
        // Vérifier si la colonne couverture existe, sinon l'ajouter
        $checkColumn = "SHOW COLUMNS FROM livres LIKE 'couverture'";
        $result = $this->pdo->query($checkColumn);
        
        if ($result->rowCount() == 0) {
            $alterSql = "ALTER TABLE livres ADD COLUMN couverture TEXT AFTER auteur";
            $this->pdo->exec($alterSql);
            echo "<div class='message success'>✅ Colonne 'couverture' ajoutée avec succès à la base de données !</div>";
        }
        
        // Vérifier et ajouter les nouvelles colonnes
        $newColumns = [
            'description' => "ALTER TABLE livres ADD COLUMN description TEXT AFTER couverture",
            'note_personnelle' => "ALTER TABLE livres ADD COLUMN note_personnelle TEXT AFTER description",
            'tags' => "ALTER TABLE livres ADD COLUMN tags VARCHAR(500) AFTER note_personnelle",
            'statut' => "ALTER TABLE livres ADD COLUMN statut ENUM('À lire', 'En cours', 'Lu', 'Abandonné') DEFAULT 'À lire' AFTER tags",
            'serie' => "ALTER TABLE livres ADD COLUMN serie VARCHAR(255) NULL AFTER auteur",
            'tome' => "ALTER TABLE livres ADD COLUMN tome INT NULL AFTER serie"
        ];
        
        foreach ($newColumns as $columnName => $alterSql) {
            $checkColumn = "SHOW COLUMNS FROM livres LIKE '$columnName'";
            $result = $this->pdo->query($checkColumn);
            
            if ($result->rowCount() == 0) {
                $this->pdo->exec($alterSql);
                echo "<div class='message success'>✅ Colonne '$columnName' ajoutée avec succès !</div>";
            }
        }
        
        // Supprimer la colonne editeur si elle existe encore
        $checkEditorColumn = "SHOW COLUMNS FROM livres LIKE 'editeur'";
        $result = $this->pdo->query($checkEditorColumn);

        if ($result->rowCount() > 0) {
            $dropSql = "ALTER TABLE livres DROP COLUMN editeur";
            $this->pdo->exec($dropSql);
            echo "<div class='message success'>✅ Colonne 'editeur' supprimée avec succès !</div>";
        }

        // Élargir la colonne isbn : les ISBN temporaires générés pour la saisie
        // manuelle (ex: TEMP_1755600000_64f...) dépassent VARCHAR(13)
        $isbnColumn = $this->pdo->query("SHOW COLUMNS FROM livres LIKE 'isbn'")->fetch(PDO::FETCH_ASSOC);
        if ($isbnColumn && preg_match('/varchar\((\d+)\)/i', $isbnColumn['Type'], $matches) && (int)$matches[1] < 40) {
            $this->pdo->exec("ALTER TABLE livres MODIFY COLUMN isbn VARCHAR(40) NOT NULL");
            echo "<div class='message success'>✅ Colonne 'isbn' élargie avec succès !</div>";
        }

        // Migration ponctuelle : sur une très ancienne base qui n'a encore que les
        // 2 supports d'origine (avant l'introduction de 'Manga'), ajouter 'Manga'.
        // Ne se déclenche qu'une seule fois : dès que la liste compte 3 valeurs ou
        // plus (que ce soit 'Manga' ou un support personnalisé ajouté/renommé
        // depuis la page Outils), on ne touche plus jamais à la liste ici.
        $valeursSupport = $this->getSupportEnumValues();
        if (count($valeursSupport) < 3 && !in_array('Manga', $valeursSupport, true)) {
            $valeursSupport[] = 'Manga';
            $this->setSupportEnumValues($valeursSupport);
            echo "<div class='message success'>✅ Support 'Manga' ajouté avec succès !</div>";
        }
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
     * Déplacer un livre vers le haut dans la liste
     */
    public function moveBookUp($listeId, $livreId) {
        try {
            // Récupérer l'ordre actuel du livre
            $sql = "SELECT ordre FROM livres_listes WHERE liste_id = :liste_id AND livre_id = :livre_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':liste_id' => $listeId, ':livre_id' => $livreId]);
            $currentOrdre = $stmt->fetchColumn();
            
            if ($currentOrdre === false || $currentOrdre <= 1) {
                return false; // Déjà en première position
            }
            
            // Trouver le livre au-dessus
            $sql = "SELECT livre_id, ordre FROM livres_listes 
                    WHERE liste_id = :liste_id AND ordre < :ordre 
                    ORDER BY ordre DESC LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':liste_id' => $listeId, ':ordre' => $currentOrdre]);
            $previousBook = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$previousBook) {
                return false;
            }
            
            // Échanger les ordres
            $this->pdo->beginTransaction();
            
            // Mettre le livre actuel à l'ordre du précédent
            $sql = "UPDATE livres_listes SET ordre = :new_ordre 
                    WHERE liste_id = :liste_id AND livre_id = :livre_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':new_ordre' => $previousBook['ordre'],
                ':liste_id' => $listeId,
                ':livre_id' => $livreId
            ]);
            
            // Mettre le livre précédent à l'ordre de l'actuel
            $stmt->execute([
                ':new_ordre' => $currentOrdre,
                ':liste_id' => $listeId,
                ':livre_id' => $previousBook['livre_id']
            ]);
            
            $this->pdo->commit();
            return true;
            
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Erreur moveBookUp : " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Déplacer un livre vers le bas dans la liste
     */
    public function moveBookDown($listeId, $livreId) {
        try {
            // Récupérer l'ordre actuel du livre
            $sql = "SELECT ordre FROM livres_listes WHERE liste_id = :liste_id AND livre_id = :livre_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':liste_id' => $listeId, ':livre_id' => $livreId]);
            $currentOrdre = $stmt->fetchColumn();
            
            if ($currentOrdre === false) {
                return false;
            }
            
            // Trouver le livre en dessous
            $sql = "SELECT livre_id, ordre FROM livres_listes 
                    WHERE liste_id = :liste_id AND ordre > :ordre 
                    ORDER BY ordre ASC LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':liste_id' => $listeId, ':ordre' => $currentOrdre]);
            $nextBook = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$nextBook) {
                return false; // Déjà en dernière position
            }
            
            // Échanger les ordres
            $this->pdo->beginTransaction();
            
            // Mettre le livre actuel à l'ordre du suivant
            $sql = "UPDATE livres_listes SET ordre = :new_ordre 
                    WHERE liste_id = :liste_id AND livre_id = :livre_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':new_ordre' => $nextBook['ordre'],
                ':liste_id' => $listeId,
                ':livre_id' => $livreId
            ]);
            
            // Mettre le livre suivant à l'ordre de l'actuel
            $stmt->execute([
                ':new_ordre' => $currentOrdre,
                ':liste_id' => $listeId,
                ':livre_id' => $nextBook['livre_id']
            ]);
            
            $this->pdo->commit();
            return true;
            
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Erreur moveBookDown : " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Réorganiser l'ordre des livres (pour drag & drop)
     */
    public function reorderBooks($listeId, $orderedBookIds) {
        try {
            $this->pdo->beginTransaction();
            
            $ordre = 1;
            $sql = "UPDATE livres_listes SET ordre = :ordre 
                    WHERE liste_id = :liste_id AND livre_id = :livre_id";
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($orderedBookIds as $livreId) {
                $stmt->execute([
                    ':ordre' => $ordre,
                    ':liste_id' => $listeId,
                    ':livre_id' => $livreId
                ]);
                $ordre++;
            }
            
            $this->pdo->commit();
            return true;
            
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Erreur reorderBooks : " . $e->getMessage());
            throw $e;
        }
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
    
    /**
     * Récupérer les infos d'un livre via l'API - Version corrigée
     */
    public function getBookInfoFromISBN($isbn) {
        // Nettoyer l'ISBN - garder seulement chiffres et X
        $cleanISBN = preg_replace('/[^0-9X]/i', '', $isbn);
        
        if (function_exists('logMessage')) {
            logMessage("Cleaned ISBN: " . $cleanISBN, 'DEBUG');
        }
        
        // Validation ISBN
        if (!$this->validateISBN($cleanISBN)) {
            if (function_exists('logMessage')) {
                logMessage("Invalid ISBN format: " . $cleanISBN, 'ERROR');
            }
            return null;
        }
        
        // Vérifier le cache (optionnel)
        $cachedInfo = $this->getCachedBookInfo($cleanISBN);
        if ($cachedInfo) {
            return $cachedInfo;
        }
        
        // Essayer plusieurs APIs dans l'ordre
        $bookData = $this->tryGoogleBooksAPI($cleanISBN);
        if ($bookData) {
            $this->cacheBookInfo($cleanISBN, $bookData);
            return $bookData;
        }
        
        $bookData = $this->tryOpenLibraryAPI($cleanISBN);
        if ($bookData) {
            $this->cacheBookInfo($cleanISBN, $bookData);
            return $bookData;
        }

        // BnF : bien meilleure couverture que Open Library pour les livres francophones
        $bookData = $this->tryBnFAPI($cleanISBN);
        if ($bookData) {
            $this->cacheBookInfo($cleanISBN, $bookData);
            return $bookData;
        }

        // Fallback avec file_get_contents
        $bookData = $this->tryFileGetContents($cleanISBN);
        if ($bookData) {
            $this->cacheBookInfo($cleanISBN, $bookData);
            return $bookData;
        }
        
        if (function_exists('logMessage')) {
            logMessage("No book found in any API for ISBN: " . $cleanISBN, 'INFO');
        }
        
        return null;
    }
    
    /**
     * Essayer l'API Google Books avec cURL amélioré
     */
    private function tryGoogleBooksAPI($isbn) {
        $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . $isbn;
        if ($this->googleBooksApiKey) {
            $url .= "&key=" . urlencode($this->googleBooksApiKey);
        }

        if (function_exists('logMessage')) {
            logMessage("Trying Google Books API: " . $url, 'DEBUG');
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BookManager/2.0)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            if (function_exists('logMessage')) {
                logMessage("cURL error for Google Books: " . $error, 'ERROR');
            }
            return null;
        }
        
        if ($httpCode !== 200) {
            if (function_exists('logMessage')) {
                logMessage("Google Books HTTP error: " . $httpCode, 'ERROR');
            }
            return null;
        }
        
        if (!$response) {
            if (function_exists('logMessage')) {
                logMessage("Empty response from Google Books", 'ERROR');
            }
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['items']) || empty($data['items'])) {
            if (function_exists('logMessage')) {
                logMessage("No items found in Google Books response", 'DEBUG');
            }
            return null;
        }
        
        $book = $data['items'][0]['volumeInfo'];
        
        if (function_exists('logMessage')) {
            logMessage("Google Books found: " . ($book['title'] ?? 'Unknown title'), 'INFO');
        }
        
        // Déterminer le support (Livre ou BD)
        $support = $this->determineSupport($book);
        
        // Récupérer l'image de couverture
        $couverture = $this->extractCoverImage($book);
        
        return [
            'isbn' => $isbn,
            'titre' => $book['title'] ?? 'Titre non disponible',
            'support' => $support,
            'auteur' => isset($book['authors']) ? implode(', ', $book['authors']) : 'Auteur non disponible',
            'couverture' => $couverture,
            'description' => $book['description'] ?? null,
            'date_publication' => $book['publishedDate'] ?? 'Date non disponible',
            'statut' => 'À lire'
        ];
    }
    
    /**
     * Essayer l'API Open Library comme alternative
     */
    private function tryOpenLibraryAPI($isbn) {
        $url = "https://openlibrary.org/api/books?bibkeys=ISBN:" . $isbn . "&format=json&jscmd=data";
        
        if (function_exists('logMessage')) {
            logMessage("Trying Open Library API: " . $url, 'DEBUG');
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BookManager/2.0)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode !== 200 || !$response) {
            if (function_exists('logMessage')) {
                logMessage("Open Library API failed - HTTP: $httpCode, Error: $error", 'DEBUG');
            }
            return null;
        }
        
        $data = json_decode($response, true);
        $key = "ISBN:$isbn";
        
        if (!$data || !isset($data[$key])) {
            if (function_exists('logMessage')) {
                logMessage("No data found for ISBN in Open Library", 'DEBUG');
            }
            return null;
        }
        
        $book = $data[$key];
        
        if (function_exists('logMessage')) {
            logMessage("Open Library found: " . ($book['title'] ?? 'Unknown title'), 'INFO');
        }
        
        // Extraire les auteurs
        $auteurs = [];
        if (isset($book['authors'])) {
            foreach ($book['authors'] as $author) {
                $auteurs[] = $author['name'] ?? '';
            }
        }
        
        return [
            'isbn' => $isbn,
            'titre' => $book['title'] ?? 'Titre non disponible',
            'support' => 'Livre', // Open Library ne distingue pas facilement les BD
            'auteur' => !empty($auteurs) ? implode(', ', $auteurs) : 'Auteur non disponible',
            'couverture' => $book['cover']['medium'] ?? $book['cover']['small'] ?? null,
            'description' => null, // Cette API n'a pas toujours de description
            'date_publication' => $book['publish_date'] ?? 'Date non disponible',
            'statut' => 'À lire'
        ];
    }

    /**
     * Essayer le catalogue de la BnF (API SRU) - bien plus complet que
     * Open Library pour les livres publiés en France
     */
    private function tryBnFAPI($isbn) {
        $query = 'bib.isbn all "' . $isbn . '"';
        $url = "https://catalogue.bnf.fr/api/SRU?version=1.2&operation=searchRetrieve&query="
            . urlencode($query) . "&recordSchema=dublincore&maximumRecords=1";

        if (function_exists('logMessage')) {
            logMessage("Trying BnF API: " . $url, 'DEBUG');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BookManager/2.0)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200 || !$response) {
            if (function_exists('logMessage')) {
                logMessage("BnF API failed - HTTP: $httpCode, Error: $error", 'DEBUG');
            }
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);
        if ($xml === false) {
            return null;
        }

        $xml->registerXPathNamespace('srw', 'http://www.loc.gov/zing/srw/');
        $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');

        $numberOfRecords = $xml->xpath('//srw:numberOfRecords');
        if (empty($numberOfRecords) || (int)$numberOfRecords[0] < 1) {
            return null;
        }

        $titleNodes = $xml->xpath('//dc:title');
        if (empty($titleNodes)) {
            return null;
        }

        // Format BnF : "Titre / Auteur ; rôle" -> on ne garde que le titre
        $titre = trim(preg_replace('/\s*\/.*$/', '', (string)$titleNodes[0]));

        $creatorNodes = $xml->xpath('//dc:creator');
        $auteur = 'Auteur non disponible';
        if (!empty($creatorNodes)) {
            // Format BnF : "Nom, Prénom (dates). Fonction" -> on nettoie
            $auteur = (string)$creatorNodes[0];
            // Retirer les dates entre parenthèses, ex: "(1948-....)"
            $auteur = preg_replace('/\s*\([^)]*\)/', '', $auteur);
            // Retirer la fonction indiquée après le nom
            $auteur = preg_replace(
                '/\.\s*(Auteur du texte|Illustrateur(?:trice)?|Traducteur(?:trice)?|Éditeur scientifique|Directeur de publication|Photographe|Préfacier|Adaptateur).*$/i',
                '',
                $auteur
            );
            $auteur = trim($auteur, " .");
            $auteur = $auteur !== '' ? $auteur : 'Auteur non disponible';
        }

        $dateNodes = $xml->xpath('//dc:date');
        $date = !empty($dateNodes) ? (string)$dateNodes[0] : 'Date non disponible';

        if (function_exists('logMessage')) {
            logMessage("BnF found: " . $titre, 'INFO');
        }

        // Le champ dc:description de la BnF contient des infos de catalogage
        // (code-barres, collection...) et non un résumé, on ne le récupère pas
        $support = $this->determineSupport(['title' => $titre]);

        return [
            'isbn' => $isbn,
            'titre' => $titre ?: 'Titre non disponible',
            'support' => $support,
            'auteur' => $auteur,
            'couverture' => null,
            'description' => null,
            'date_publication' => $date,
            'statut' => 'À lire'
        ];
    }

    /**
     * Version fallback avec file_get_contents si cURL ne fonctionne pas
     */
    private function tryFileGetContents($isbn) {
        if (!ini_get('allow_url_fopen')) {
            if (function_exists('logMessage')) {
                logMessage("allow_url_fopen is disabled", 'ERROR');
            }
            return null;
        }
        
        $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . $isbn;
        if ($this->googleBooksApiKey) {
            $url .= "&key=" . urlencode($this->googleBooksApiKey);
        }

        if (function_exists('logMessage')) {
            logMessage("Trying file_get_contents fallback: " . $url, 'DEBUG');
        }
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'Mozilla/5.0 (compatible; BookManager/2.0)',
                'header' => [
                    'Accept: application/json',
                    'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8'
                ]
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if (!$response) {
            if (function_exists('logMessage')) {
                logMessage("file_get_contents failed for Google Books", 'ERROR');
            }
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['items']) || empty($data['items'])) {
            if (function_exists('logMessage')) {
                logMessage("No items found in file_get_contents response", 'DEBUG');
            }
            return null;
        }
        
        $book = $data['items'][0]['volumeInfo'];
        
        if (function_exists('logMessage')) {
            logMessage("file_get_contents found: " . ($book['title'] ?? 'Unknown title'), 'INFO');
        }
        
        return [
            'isbn' => $isbn,
            'titre' => $book['title'] ?? 'Titre non disponible',
            'support' => $this->determineSupport($book),
            'auteur' => isset($book['authors']) ? implode(', ', $book['authors']) : 'Auteur non disponible',
            'couverture' => $this->extractCoverImage($book),
            'description' => $book['description'] ?? null,
            'date_publication' => $book['publishedDate'] ?? 'Date non disponible',
            'statut' => 'À lire'
        ];
    }
    
    /**
     * Valider un ISBN - Version améliorée
     */
    private function validateISBN($isbn) {
        // Enlever tous les caractères non-alphanumériques
        $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
        
        $length = strlen($isbn);
        
        // ISBN-10 ou ISBN-13
        if ($length === 10 || $length === 13) {
            return true;
        }
        
        if (function_exists('logMessage')) {
            logMessage("Invalid ISBN length: $length for ISBN: $isbn", 'DEBUG');
        }
        
        return false;
    }
    
    /**
     * Extraire l'image de couverture - Version améliorée
     */
    private function extractCoverImage($book) {
        if (!isset($book['imageLinks'])) {
            return null;
        }
        
        // Essayer dans l'ordre de préférence (de la meilleure qualité à la moins bonne)
        $imageTypes = ['large', 'medium', 'small', 'thumbnail', 'smallThumbnail'];
        
        foreach ($imageTypes as $type) {
            if (isset($book['imageLinks'][$type])) {
                $couverture = $book['imageLinks'][$type];
                // Forcer HTTPS et zoom=1 pour une meilleure qualité
               $couverture = str_replace('http://', 'https://', $couverture);
                if (strpos($couverture, 'zoom=') === false) {
                    $couverture .= (strpos($couverture, '?') === false ? '?' : '&') . 'zoom=1';
                }
                return $couverture;
            }
        }
        
        return null;
    }
    
    /**
     * Cache simple pour les infos de livres (optionnel)
     */
    private function getCachedBookInfo($isbn) {
        // Implémentation simple - à améliorer
        return null;
    }
    
    private function cacheBookInfo($isbn, $bookInfo) {
        // Implémentation simple - à améliorer
        return;
    }
    
    /**
     * Déterminer si c'est un livre ou une BD
     */
    private function determineSupport($book) {
        $titre = strtolower($book['title'] ?? '');
        $categories = isset($book['categories']) ? strtolower(implode(' ', $book['categories'])) : '';
        $description = strtolower($book['description'] ?? '');

        $texteComplete = $titre . ' ' . $categories . ' ' . $description;

        // Mots-clés pour détecter les mangas (à vérifier avant les BD génériques)
        $motsManga = ['manga', 'shonen', 'shōnen', 'shojo', 'shōjo', 'seinen'];
        foreach ($motsManga as $mot) {
            if (strpos($texteComplete, $mot) !== false) {
                return 'Manga';
            }
        }

        // Mots-clés pour détecter les BD
        $motsBD = ['comic', 'bande dessinée', 'bd', 'comics', 'graphic novel', 'roman graphique'];
        foreach ($motsBD as $mot) {
            if (strpos($texteComplete, $mot) !== false) {
                return 'Bande dessinée';
            }
        }

        return 'Livre';
    }
    
    /**
     * Ajouter un livre à la collection avec statut
     */
    public function addBook($bookInfo) {
        try {
            $sql = "INSERT INTO livres (isbn, titre, support, auteur, serie, tome, couverture, description, date_publication, statut, tags)
                    VALUES (:isbn, :titre, :support, :auteur, :serie, :tome, :couverture, :description, :date_publication, :statut, :tags)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':isbn' => $bookInfo['isbn'],
                ':titre' => $bookInfo['titre'],
                ':support' => $bookInfo['support'],
                ':auteur' => $bookInfo['auteur'],
                ':serie' => $this->normalizeSerie($bookInfo['serie'] ?? null),
                ':tome' => $this->normalizeTome($bookInfo['tome'] ?? null),
                ':couverture' => $bookInfo['couverture'],
                ':description' => $bookInfo['description'],
                ':date_publication' => $bookInfo['date_publication'],
                ':statut' => $bookInfo['statut'] ?? 'À lire',
                ':tags' => $bookInfo['tags'] ?? ''
            ]);
            
            return true;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Code pour duplicate entry
                throw new Exception("Ce livre est déjà dans votre collection !");
            }
            throw new Exception("Erreur lors de l'ajout : " . $e->getMessage());
        }
    }
    
    /**
     * Récupérer tous les livres avec pagination
     */
    public function getAllBooks($support = null, $search = null, $tag = null, $statut = null, $page = 1, $limit = null, $serie = null) {
        $limit = $limit ?? $this->itemsPerPage;
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT * FROM livres WHERE 1=1";
        $params = [];

        if ($serie) {
            $sql .= " AND serie = :serie";
            $params[':serie'] = $serie;
        }
        
        if ($support) {
            $sql .= " AND support = :support";
            $params[':support'] = $support;
        }
        
        if ($search) {
            $sql .= " AND (titre LIKE :search OR auteur LIKE :search OR isbn LIKE :search OR serie LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        if ($tag) {
            $sql .= " AND tags LIKE :tag";
            $params[':tag'] = '%' . $tag . '%';
        }
        
        if ($statut) {
            $sql .= " AND statut = :statut";
            $params[':statut'] = $statut;
        }
        
        // Dans une série filtrée, on lit les tomes dans l'ordre
        $sql .= $serie
            ? " ORDER BY tome IS NULL, tome ASC, titre ASC LIMIT :limit OFFSET :offset"
            : " ORDER BY titre ASC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->pdo->prepare($sql);
        
        // Bind des paramètres
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Compter le nombre total de livres (pour pagination)
     */
    public function countBooks($support = null, $search = null, $tag = null, $statut = null, $serie = null) {
        $sql = "SELECT COUNT(*) FROM livres WHERE 1=1";
        $params = [];

        if ($serie) {
            $sql .= " AND serie = :serie";
            $params[':serie'] = $serie;
        }
        
        if ($support) {
            $sql .= " AND support = :support";
            $params[':support'] = $support;
        }
        
        if ($search) {
            $sql .= " AND (titre LIKE :search OR auteur LIKE :search OR isbn LIKE :search OR serie LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        if ($tag) {
            $sql .= " AND tags LIKE :tag";
            $params[':tag'] = '%' . $tag . '%';
        }
        
        if ($statut) {
            $sql .= " AND statut = :statut";
            $params[':statut'] = $statut;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchColumn();
    }
    
    /**
     * Calculer les informations de pagination
     */
    public function getPaginationInfo($totalItems, $currentPage, $itemsPerPage = null) {
        $itemsPerPage = $itemsPerPage ?? $this->itemsPerPage;
        $totalPages = ceil($totalItems / $itemsPerPage);
        
        return [
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'total_items' => $totalItems,
            'items_per_page' => $itemsPerPage,
            'has_previous' => $currentPage > 1,
            'has_next' => $currentPage < $totalPages,
            'previous_page' => $currentPage - 1,
            'next_page' => $currentPage + 1,
            'start_item' => (($currentPage - 1) * $itemsPerPage) + 1,
            'end_item' => min($currentPage * $itemsPerPage, $totalItems)
        ];
    }
    
    /**
     * Mettre à jour les notes, tags et support d'un livre
     */
    public function updateBookNotes($id, $note_personnelle, $tags, $statut, $support = null, $description = null, $couverture_perso = null, $supprimer_couverture = false, $titre = null, $auteur = null, $serie = null, $tome = null) {
        $book = $this->getBookById($id);
        if (!$book) {
            throw new Exception("Livre non trouvé");
        }
        
        $nouvelle_couverture = $book['couverture'];
        
        // Gestion de la suppression de couverture
        if ($supprimer_couverture) {
            if ($book['couverture'] && strpos($book['couverture'], 'uploads/') !== false) {
                $chemin_fichier = $book['couverture'];
                if (file_exists($chemin_fichier)) {
                    unlink($chemin_fichier);
                }
            }
            $nouvelle_couverture = null;
        }
        
        // Gestion de l'upload d'une nouvelle couverture
        if ($couverture_perso && $couverture_perso['error'] === UPLOAD_ERR_OK) {
            $nouvelle_couverture = $this->uploadCouverture($couverture_perso, $id);
            
            // Supprimer l'ancienne couverture locale
            if ($book['couverture'] && strpos($book['couverture'], 'uploads/') !== false && !$supprimer_couverture) {
                $ancien_fichier = $book['couverture'];
                if (file_exists($ancien_fichier)) {
                    unlink($ancien_fichier);
                }
            }
        }
        
        // Valider le support si fourni (la liste des supports valides peut être
        // étendue/renommée depuis la page Outils, voir getSupportEnumValues())
        if ($support !== null && !in_array($support, $this->getSupportEnumValues(), true)) {
            throw new Exception("Support invalide.");
        }
        
        // Préparer la requête SQL avec support
        $sql = "UPDATE livres SET 
                note_personnelle = :note, 
                tags = :tags, 
                statut = :statut, 
                couverture = :couverture";
        
        $params = [
            ':id' => $id,
            ':note' => $note_personnelle,
            ':tags' => $tags,
            ':statut' => $statut,
            ':couverture' => $nouvelle_couverture
        ];
        
        // Ajouter le support si fourni
        if ($support !== null) {
            $sql .= ", support = :support";
            $params[':support'] = $support;
        }
        
        // Ajouter la description si fournie
        if ($description !== null) {
            $sql .= ", description = :description";
            $params[':description'] = $description;
        }
        
        // Ajouter le titre si fourni
        if ($titre !== null) {
            $sql .= ", titre = :titre";
            $params[':titre'] = $titre;
        }
        
        // Ajouter l'auteur si fourni
        if ($auteur !== null) {
            $sql .= ", auteur = :auteur";
            $params[':auteur'] = $auteur;
        }

        // Série et tome : null = ne pas toucher, chaîne vide = effacer
        if ($serie !== null) {
            $sql .= ", serie = :serie";
            $params[':serie'] = $this->normalizeSerie($serie);
        }
        if ($tome !== null) {
            $sql .= ", tome = :tome";
            $params[':tome'] = $this->normalizeTome($tome);
        }
        
        $sql .= " WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Gérer l'upload d'une couverture personnalisée
     */
    private function uploadCouverture($fichier, $book_id) {
        // Vérifications de sécurité
        $extensions_autorisees = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $taille_max = 5 * 1024 * 1024; // 5MB
        
        $extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $extensions_autorisees)) {
            throw new Exception("Format de fichier non autorisé. Utilisez : " . implode(', ', $extensions_autorisees));
        }
        
        if ($fichier['size'] > $taille_max) {
            throw new Exception("Le fichier est trop volumineux (max 5MB)");
        }
        
        // Vérifier que c'est bien une image
        $info_image = getimagesize($fichier['tmp_name']);
        if ($info_image === false) {
            throw new Exception("Le fichier n'est pas une image valide");
        }
        
        // Créer le dossier uploads s'il n'existe pas
        $dossier_upload = 'uploads/';
        if (!is_dir($dossier_upload)) {
            mkdir($dossier_upload, 0755, true);
        }
        
        // Générer un nom de fichier unique et sécurisé
        $nom_fichier = 'couverture_' . $book_id . '_' . time() . '.' . $extension;
        $chemin_destination = $dossier_upload . $nom_fichier;
        
        // Déplacer le fichier uploadé
        if (!move_uploaded_file($fichier['tmp_name'], $chemin_destination)) {
            throw new Exception("Erreur lors de l'upload du fichier");
        }
        
        return $chemin_destination;
    }
    
    /**
     * Obtenir un livre par ID
     */
    public function getBookById($id) {
        $sql = "SELECT * FROM livres WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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
     * Supprimer un livre
     */
    public function deleteBook($id) {
        // Récupérer les infos du livre pour supprimer la couverture si nécessaire
        $book = $this->getBookById($id);
        if ($book && $book['couverture'] && strpos($book['couverture'], 'uploads/') !== false) {
            if (file_exists($book['couverture'])) {
                unlink($book['couverture']);
            }
        }
        
        $sql = "DELETE FROM livres WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
    
    /**
     * Obtenir les statistiques de la collection
     */
    public function getStats() {
        $total = $this->countBooks();
        $livres = $this->countBooks('Livre');
        $bd = $this->countBooks('Bande dessinée');
        $manga = $this->countBooks('Manga');

        return [
            'total' => $total,
            'livres' => $livres,
            'bd' => $bd,
            'manga' => $manga
        ];
    }
    
    /**
     * Obtenir les livres récents
     */
    public function getRecentBooks($limit = 5) {
        return $this->getAllBooks(null, null, null, null, 1, $limit);
    }
    
    /**
     * Définir le nombre d'éléments par page
     */
    public function setItemsPerPage($items) {
        $this->itemsPerPage = max(1, intval($items));
    }
    
    /**
     * Obtenir le nombre d'éléments par page
     */
    public function getItemsPerPage() {
        return $this->itemsPerPage;
    }
    
    /**
     * Obtenir les statistiques détaillées
     */
    public function getDetailedStats() {
        // Stats de base
        $total = $this->countBooks();
        $livres = $this->countBooks('Livre');
        $bd = $this->countBooks('Bande dessinée');
        $manga = $this->countBooks('Manga');

        // Requête SQL directe
        $sql = "SELECT statut, COUNT(*) as count FROM livres GROUP BY statut";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Initialiser les compteurs
        $lu = 0;
        $a_lire = 0;
        $en_cours = 0;
        $abandonne = 0;
        
        // Parcourir avec une logique plus flexible
        foreach ($statusCounts as $row) {
            $statut = trim($row['statut']);
            $count = (int)$row['count'];
            
            // Utiliser strpos pour une correspondance partielle
            if (strpos($statut, 'Lu') !== false) {
                $lu = $count;
            } elseif (strpos($statut, 'lire') !== false) {
                $a_lire = $count;
            } elseif (strpos($statut, 'cours') !== false) {
                $en_cours = $count;
            } elseif (strpos($statut, 'bandon') !== false) {
                $abandonne = $count;
            }
        }
        
        return [
            'total' => $total,
            'livres' => $livres,
            'bd' => $bd,
            'manga' => $manga,
            'lu' => $lu,
            'a_lire' => $a_lire,
            'en_cours' => $en_cours,
            'abandonne' => $abandonne
        ];
    }
    
    /**
     * Obtenir les statistiques d'ajouts mensuels (12 derniers mois)
     */
    public function getMonthlyAdditionStats() {
        $sql = "
            SELECT 
                DATE_FORMAT(date_ajout, '%Y-%m') as month,
                DATE_FORMAT(date_ajout, '%M %Y') as month_name,
                COUNT(*) as count
            FROM livres 
            WHERE date_ajout >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(date_ajout, '%Y-%m'), DATE_FORMAT(date_ajout, '%M %Y')
            ORDER BY month DESC
            LIMIT 12
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_reverse($results);
    }
    
    /**
     * Obtenir les top tags les plus utilisés
     */
    public function getTopTags($limit = 10) {
        $sql = "SELECT tags FROM livres WHERE tags IS NOT NULL AND tags != ''";
        $stmt = $this->pdo->query($sql);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $tagCounts = [];
        
        foreach ($results as $tagString) {
            $tags = explode(',', $tagString);
            foreach ($tags as $tag) {
                $tag = trim($tag);
                if ($tag) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }
        }
        
        arsort($tagCounts);
        
        $topTags = [];
        $count = 0;
        foreach ($tagCounts as $tag => $occurrences) {
            if ($count >= $limit) break;
            $topTags[] = [
                'tag' => $tag,
                'count' => $occurrences
            ];
            $count++;
        }
        
        return $topTags;
    }
    
    /**
     * Obtenir les statistiques par auteur
     */
    public function getAuthorStats($limit = 10) {
        $sql = "
            SELECT 
                auteur,
                COUNT(*) as count
            FROM livres 
            WHERE auteur IS NOT NULL AND auteur != ''
            GROUP BY auteur 
            ORDER BY count DESC, auteur ASC 
            LIMIT :limit
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtenir l'activité récente (ajouts et modifications)
     */
    public function getRecentActivity($limit = 10) {
        $sql = "
            SELECT 
                id,
                titre,
                auteur,
                date_ajout as date,
                'added' as action
            FROM livres 
            ORDER BY date_ajout DESC 
            LIMIT :limit
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Créer la table de cache des biographies d'auteurs
     */
    public function createAuthorTable() {
        $sql = "CREATE TABLE IF NOT EXISTS auteurs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(255) UNIQUE NOT NULL,
            biographie TEXT,
            image_url VARCHAR(500),
            date_maj TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);

        // Métadonnées enrichies (Wikidata) : naissance/décès/nationalité
        $newColumns = [
            'naissance' => "ALTER TABLE auteurs ADD COLUMN naissance VARCHAR(10) AFTER image_url",
            'deces' => "ALTER TABLE auteurs ADD COLUMN deces VARCHAR(10) AFTER naissance",
            'nationalite' => "ALTER TABLE auteurs ADD COLUMN nationalite VARCHAR(150) AFTER deces",
        ];
        foreach ($newColumns as $columnName => $alterSql) {
            $result = $this->pdo->query("SHOW COLUMNS FROM auteurs LIKE '$columnName'");
            if ($result->rowCount() == 0) {
                $this->pdo->exec($alterSql);
            }
        }
    }

    /**
     * Regrouper tous les livres de la collection par auteur
     * (le champ auteur est un texte libre, éventuellement plusieurs noms séparés par des virgules)
     */
    public function getAllAuthorsWithBooks($search = null, $support = null, $tag = null, $sort = 'alpha', $missingBioOnly = false) {
        $sql = "SELECT id, titre, auteur, couverture, statut, support, tags, date_ajout
                FROM livres
                WHERE auteur IS NOT NULL AND auteur != ''";
        $params = [];

        if ($support) {
            $sql .= " AND support = :support";
            $params[':support'] = $support;
        }

        if ($tag) {
            $sql .= " AND tags LIKE :tag";
            $params[':tag'] = '%' . $tag . '%';
        }

        $sql .= " ORDER BY titre ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $authors = [];
        foreach ($books as $book) {
            $noms = array_filter(array_map('trim', explode(',', $book['auteur'])));
            foreach ($noms as $nom) {
                if (!isset($authors[$nom])) {
                    $authors[$nom] = [
                        'nom' => $nom,
                        'livres' => [],
                        'image_url' => null,
                    ];
                }
                $authors[$nom]['livres'][] = $book;
            }
        }

        if ($search) {
            $search = mb_strtolower($search, 'UTF-8');
            $authors = array_filter($authors, function($a) use ($search) {
                return mb_strpos(mb_strtolower($a['nom'], 'UTF-8'), $search) !== false;
            });
        }

        if (!empty($authors)) {
            $photos = $this->pdo->query("SELECT nom, image_url FROM auteurs WHERE image_url IS NOT NULL AND image_url != ''")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            $biosPresentes = $this->pdo->query("SELECT nom FROM auteurs WHERE biographie IS NOT NULL AND biographie != ''")
                ->fetchAll(PDO::FETCH_COLUMN);
            $biosPresentes = array_flip($biosPresentes);

            foreach ($authors as $nom => &$auteur) {
                if (isset($photos[$nom])) {
                    $auteur['image_url'] = $photos[$nom];
                }
                $auteur['a_une_biographie'] = isset($biosPresentes[$nom]);
            }
            unset($auteur);
        }

        if ($missingBioOnly) {
            $authors = array_filter($authors, function($a) { return !$a['a_une_biographie']; });
        }

        switch ($sort) {
            case 'count_desc':
                uasort($authors, function ($a, $b) {
                    return count($b['livres']) <=> count($a['livres']);
                });
                break;
            case 'recent':
                uasort($authors, function ($a, $b) {
                    $dateA = max(array_column($a['livres'], 'date_ajout'));
                    $dateB = max(array_column($b['livres'], 'date_ajout'));
                    return strcmp($dateB, $dateA);
                });
                break;
            default:
                ksort($authors, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return array_values($authors);
    }

    /**
     * Exporter une liste d'auteurs (telle que retournée par getAllAuthorsWithBooks) en CSV.
     * Envoie directement les en-têtes et le fichier, comme les autres exports de l'application.
     */
    public function exportAuthorsCSV($authors) {
        $filename = 'auteurs_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // BOM UTF-8 pour Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Nom', 'Nombre de livres', 'Naissance', 'Décès', 'Nationalité', 'Biographie', 'Titres possédés'], ';');

        foreach ($authors as $auteur) {
            $stmt = $this->pdo->prepare("SELECT biographie, naissance, deces, nationalite FROM auteurs WHERE nom = :nom");
            $stmt->execute([':nom' => $auteur['nom']]);
            $meta = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $titres = implode(' | ', array_column($auteur['livres'], 'titre'));

            fputcsv($output, [
                $auteur['nom'],
                count($auteur['livres']),
                $meta['naissance'] ?? '',
                $meta['deces'] ?? '',
                $meta['nationalite'] ?? '',
                $meta['biographie'] ?? '',
                $titres
            ], ';');
        }

        fclose($output);
        exit;
    }

    /**
     * Définir manuellement la biographie d'un auteur (complète ou remplace le résultat de Wikipédia)
     */
    public function setAuthorBiography($nom, $biographie, $imageUrl = null, $photoFile = null, $naissance = null, $deces = null, $nationalite = null) {
        $nom = trim($nom);
        if ($nom === '') {
            throw new Exception("Nom d'auteur invalide.");
        }

        $imageUrl = trim($imageUrl ?? '');

        if ($photoFile && $photoFile['error'] === UPLOAD_ERR_OK) {
            // Supprimer l'ancienne photo locale si elle existe, avant d'enregistrer la nouvelle
            $stmtAncien = $this->pdo->prepare("SELECT image_url FROM auteurs WHERE nom = :nom");
            $stmtAncien->execute([':nom' => $nom]);
            $ancienneImage = $stmtAncien->fetchColumn();
            if ($ancienneImage && strpos($ancienneImage, 'uploads/auteurs/') !== false && file_exists($ancienneImage)) {
                unlink($ancienneImage);
            }

            $imageUrl = $this->uploadAuteurPhoto($photoFile);
        }

        $naissance = trim($naissance ?? '');
        $deces = trim($deces ?? '');
        $nationalite = trim($nationalite ?? '');

        $sql = "INSERT INTO auteurs (nom, biographie, image_url, naissance, deces, nationalite)
                VALUES (:nom, :bio, :image, :naissance, :deces, :nationalite)
                ON DUPLICATE KEY UPDATE
                    biographie = VALUES(biographie),
                    image_url = VALUES(image_url),
                    naissance = VALUES(naissance),
                    deces = VALUES(deces),
                    nationalite = VALUES(nationalite),
                    date_maj = CURRENT_TIMESTAMP";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':nom' => $nom,
            ':bio' => trim($biographie ?? ''),
            ':image' => $imageUrl !== '' ? $imageUrl : null,
            ':naissance' => $naissance !== '' ? $naissance : null,
            ':deces' => $deces !== '' ? $deces : null,
            ':nationalite' => $nationalite !== '' ? $nationalite : null
        ]);
    }

    /**
     * Gérer l'upload d'une photo d'auteur
     */
    private function uploadAuteurPhoto($fichier) {
        $extensions_autorisees = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $taille_max = 5 * 1024 * 1024; // 5MB

        $extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, $extensions_autorisees)) {
            throw new Exception("Format de fichier non autorisé. Utilisez : " . implode(', ', $extensions_autorisees));
        }

        if ($fichier['size'] > $taille_max) {
            throw new Exception("Le fichier est trop volumineux (max 5MB)");
        }

        $info_image = getimagesize($fichier['tmp_name']);
        if ($info_image === false) {
            throw new Exception("Le fichier n'est pas une image valide");
        }

        $dossier_upload = 'uploads/auteurs/';
        if (!is_dir($dossier_upload)) {
            mkdir($dossier_upload, 0755, true);
        }

        $nom_fichier = 'auteur_' . uniqid() . '.' . $extension;
        $chemin_destination = $dossier_upload . $nom_fichier;

        if (!move_uploaded_file($fichier['tmp_name'], $chemin_destination)) {
            throw new Exception("Erreur lors de l'upload du fichier");
        }

        return $chemin_destination;
    }

    /**
     * Obtenir la biographie d'un auteur, en cache local ou récupérée depuis Wikipédia
     */
    public function getAuthorBiography($nom) {
        $stmt = $this->pdo->prepare("SELECT biographie, image_url, naissance, deces, nationalite FROM auteurs WHERE nom = :nom");
        $stmt->execute([':nom' => $nom]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Biographie déjà en cache (même une recherche infructueuse, stockée comme chaîne vide)
        if ($row && $row['biographie'] !== null) {
            return [
                'biographie' => $row['biographie'],
                'image_url' => $row['image_url'],
                'naissance' => $row['naissance'],
                'deces' => $row['deces'],
                'nationalite' => $row['nationalite']
            ];
        }

        return $this->fetchAndCacheAuthorMetadata($nom);
    }

    /**
     * Récupère la biographie (Wikipédia) et les métadonnées structurées (Wikidata)
     * d'un auteur, et met le résultat en cache dans la table auteurs.
     */
    private function fetchAndCacheAuthorMetadata($nom) {
        $data = $this->fetchWikipediaBiography($nom);
        $wikidata = $this->fetchWikidataMetadata($data['wikidata_id'] ?? null);

        $sql = "INSERT INTO auteurs (nom, biographie, image_url, naissance, deces, nationalite)
                VALUES (:nom, :bio, :image, :naissance, :deces, :nationalite)
                ON DUPLICATE KEY UPDATE
                    biographie = VALUES(biographie),
                    image_url = VALUES(image_url),
                    naissance = VALUES(naissance),
                    deces = VALUES(deces),
                    nationalite = VALUES(nationalite),
                    date_maj = CURRENT_TIMESTAMP";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':nom' => $nom,
            ':bio' => $data['biographie'],
            ':image' => $data['image_url'],
            ':naissance' => $wikidata['naissance'],
            ':deces' => $wikidata['deces'],
            ':nationalite' => $wikidata['nationalite']
        ]);

        return [
            'biographie' => $data['biographie'],
            'image_url' => $data['image_url'],
            'naissance' => $wikidata['naissance'],
            'deces' => $wikidata['deces'],
            'nationalite' => $wikidata['nationalite']
        ];
    }

    /**
     * Récupérer un résumé biographique depuis l'API Wikipédia (français)
     */
    private function fetchWikipediaBiography($nom) {
        $url = "https://fr.wikipedia.org/api/rest_v1/page/summary/" . rawurlencode(str_replace(' ', '_', $nom));
        $data = $this->httpGetJson($url);

        // Pages introuvables, d'homonymie ou autres pages non biographiques
        if (!$data || ($data['type'] ?? '') === 'disambiguation') {
            return ['biographie' => '', 'image_url' => null, 'wikidata_id' => null];
        }

        return [
            'biographie' => trim($data['extract'] ?? ''),
            'image_url' => $data['thumbnail']['source'] ?? ($data['originalimage']['source'] ?? null),
            'wikidata_id' => $data['wikibase_item'] ?? null
        ];
    }

    /**
     * Récupérer naissance/décès/nationalité depuis Wikidata, à partir de l'identifiant
     * (ex: Q3247957) fourni par le résumé Wikipédia de l'auteur.
     */
    private function fetchWikidataMetadata($wikidataId) {
        $metadata = ['naissance' => null, 'deces' => null, 'nationalite' => null];

        if (!$wikidataId) {
            return $metadata;
        }

        $url = "https://www.wikidata.org/w/api.php?action=wbgetentities&ids=" . urlencode($wikidataId) . "&props=claims&format=json";
        $reponse = $this->httpGetJson($url);
        $claims = $reponse['entities'][$wikidataId]['claims'] ?? null;

        if (!$claims) {
            return $metadata;
        }

        $metadata['naissance'] = $this->extractWikidataYear($claims, 'P569');
        $metadata['deces'] = $this->extractWikidataYear($claims, 'P570');

        $paysId = $claims['P27'][0]['mainsnak']['datavalue']['value']['id'] ?? null;
        if ($paysId) {
            $urlLabel = "https://www.wikidata.org/w/api.php?action=wbgetentities&ids=" . urlencode($paysId) . "&props=labels&languages=fr&format=json";
            $reponseLabel = $this->httpGetJson($urlLabel);
            $metadata['nationalite'] = $reponseLabel['entities'][$paysId]['labels']['fr']['value'] ?? null;
        }

        return $metadata;
    }

    /**
     * Extraire l'année d'une date Wikidata (format "+1969-08-01T00:00:00Z") pour une propriété donnée.
     */
    private function extractWikidataYear($claims, $propriete) {
        $valeur = $claims[$propriete][0]['mainsnak']['datavalue']['value']['time'] ?? null;
        if ($valeur && preg_match('/^([+-]\d{4})/', $valeur, $m)) {
            return ltrim($m[1], '+');
        }
        return null;
    }

    /**
     * Effectuer une requête GET et décoder la réponse JSON, avec les mêmes réglages
     * cURL que le reste de l'application (timeouts courts, échec silencieux).
     */
    private function httpGetJson($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BookManager/2.0)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Normaliser un nom d'auteur pour comparaison (minuscules, sans accents, espaces réduits)
     */
    private function normalizeAuthorName($nom) {
        $nom = mb_strtolower(trim($nom), 'UTF-8');
        $sansAccents = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom);
        if ($sansAccents !== false && $sansAccents !== '') {
            $nom = $sansAccents;
        }
        // iconv//TRANSLIT laisse des marques diacritiques résiduelles (ex: "É" -> "'e") : on les supprime
        // sans les remplacer par un espace, pour ne pas couper le mot en deux
        $nom = preg_replace('/[\'`^~"]/', '', $nom);
        $nom = preg_replace('/[^a-z0-9]+/', ' ', $nom);
        return trim($nom);
    }

    /**
     * Repérer des groupes d'auteurs probablement identiques (variantes de casse/accents)
     */
    public function findDuplicateAuthorGroups() {
        $authors = $this->getAllAuthorsWithBooks();

        $groups = [];
        foreach ($authors as $auteur) {
            $cle = $this->normalizeAuthorName($auteur['nom']);
            if (!isset($groups[$cle])) {
                $groups[$cle] = [];
            }
            $groups[$cle][] = $auteur;
        }

        $ignores = $this->pdo->query("SELECT cle_normalisee FROM auteurs_doublons_ignores")->fetchAll(PDO::FETCH_COLUMN);

        $doublons = [];
        foreach ($groups as $cle => $membres) {
            if (count($membres) > 1 && !in_array($cle, $ignores, true)) {
                $doublons[] = ['cle' => $cle, 'auteurs' => $membres];
            }
        }

        return $doublons;
    }

    /**
     * Crée la table qui mémorise les groupes de doublons d'auteurs
     * que l'utilisateur a choisi d'ignorer (faux positifs).
     */
    public function createIgnoredDuplicatesTable() {
        $sql = "CREATE TABLE IF NOT EXISTS auteurs_doublons_ignores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cle_normalisee VARCHAR(255) UNIQUE NOT NULL,
            date_ajout TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
    }

    /**
     * Marque un groupe de doublons comme faux positif : il ne sera plus
     * proposé par findDuplicateAuthorGroups().
     */
    public function ignoreDuplicateGroup($cle) {
        $cle = trim($cle);
        if ($cle === '') {
            throw new Exception("Clé de groupe invalide.");
        }
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO auteurs_doublons_ignores (cle_normalisee) VALUES (:cle)");
        $stmt->execute([':cle' => $cle]);
    }

    /**
     * Indique si un auteur de ce nom existe déjà dans la collection.
     */
    public function authorExists($nom) {
        $nom = trim($nom);
        $noms = array_column($this->getAllAuthorsWithBooks(), 'nom');
        return in_array($nom, $noms, true);
    }

    /**
     * Relance la recherche de biographie sur Wikipédia en court-circuitant le
     * cache, et met à jour la biographie mise en cache avec le résultat.
     */
    public function refreshAuthorBiography($nom) {
        return $this->fetchAndCacheAuthorMetadata($nom);
    }

    /**
     * Fusionner un ou plusieurs noms d'auteur vers un nom canonique
     * Met à jour le champ auteur de tous les livres concernés
     */
    public function mergeAuthors($sourceNames, $targetName) {
        $sourceNames = array_values(array_filter(array_map('trim', (array)$sourceNames)));
        $targetName = trim($targetName);

        if (empty($sourceNames) || $targetName === '') {
            throw new Exception("Noms invalides pour la fusion.");
        }

        $stmt = $this->pdo->query("SELECT id, auteur FROM livres WHERE auteur IS NOT NULL AND auteur != ''");
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $update = $this->pdo->prepare("UPDATE livres SET auteur = :auteur WHERE id = :id");
        $livresModifies = 0;

        foreach ($books as $book) {
            $noms = array_filter(array_map('trim', explode(',', $book['auteur'])));
            $nouveauxNoms = [];

            foreach ($noms as $nom) {
                if (in_array($nom, $sourceNames, true)) {
                    $nom = $targetName;
                }
                $nouveauxNoms[] = $nom;
            }

            $nouveauxNoms = array_values(array_unique($nouveauxNoms));
            $nouvelAuteur = implode(', ', $nouveauxNoms);

            if ($nouvelAuteur !== $book['auteur']) {
                $update->execute([
                    ':auteur' => $nouvelAuteur,
                    ':id' => $book['id']
                ]);
                $livresModifies++;
            }
        }

        // Nettoyer le cache de biographies des anciens noms fusionnés
        $delStmt = $this->pdo->prepare("DELETE FROM auteurs WHERE nom = :nom");
        foreach ($sourceNames as $nom) {
            if ($nom !== $targetName) {
                $delStmt->execute([':nom' => $nom]);
            }
        }

        return $livresModifies;
    }

    /**
     * Garantit que le schéma complet (tables, colonnes, index) existe et est à jour.
     * Point d'entrée unique appelé par includes/bootstrap.php sur chaque page.
     */
    public function ensureSchema() {
        $this->createTable();
        $this->createListTables();
        $this->createAuthorTable();
        $this->createIndexes();
        $this->createIgnoredDuplicatesTable();
        $this->createTagsCatalogTable();
    }

    /**
     * Nettoie un nom de série : vide => NULL, sinon tronqué à 255 caractères.
     */
    private function normalizeSerie($serie) {
        $serie = trim((string)$serie);
        return $serie === '' ? null : mb_substr($serie, 0, 255);
    }

    /**
     * Nettoie un numéro de tome : vide => NULL, sinon entier positif (jusqu'à 6 chiffres).
     */
    private function normalizeTome($tome) {
        $tome = trim((string)$tome);
        if ($tome === '') {
            return null;
        }
        if (!ctype_digit($tome) || strlen($tome) > 6) {
            throw new Exception("Le numéro de tome doit être un entier positif (6 chiffres maximum).");
        }
        return (int)$tome;
    }

    /**
     * Liste des séries de la collection avec leur nombre de livres,
     * triée par nom : ['Fondation' => 3, 'One Piece' => 12, ...].
     */
    public function getAllSeries() {
        $stmt = $this->pdo->query("SELECT serie, COUNT(*) AS nb FROM livres
                                   WHERE serie IS NOT NULL AND serie <> ''
                                   GROUP BY serie ORDER BY serie ASC");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Crée les index recommandés sur les colonnes fréquemment filtrées, de façon idempotente.
     */
    public function createIndexes() {
        $indexes = [
            'livres' => [
                'idx_support' => "CREATE INDEX idx_support ON livres(support)",
                'idx_statut' => "CREATE INDEX idx_statut ON livres(statut)",
                'idx_date_ajout' => "CREATE INDEX idx_date_ajout ON livres(date_ajout)",
                // Index sur un préfixe : évite la limite de longueur de clé des anciens MySQL en utf8mb4
                'idx_serie' => "CREATE INDEX idx_serie ON livres(serie(100))",
            ],
            'listes_lecture' => [
                'idx_date_creation' => "CREATE INDEX idx_date_creation ON listes_lecture(date_creation)",
            ],
        ];

        foreach ($indexes as $table => $defs) {
            foreach ($defs as $name => $sql) {
                $check = $this->pdo->query("SHOW INDEX FROM $table WHERE Key_name = '$name'");
                if ($check->rowCount() == 0) {
                    $this->pdo->exec($sql);
                }
            }
        }
    }

    /**
     * Exporte l'intégralité des données (hors config/identifiants) au format JSON
     * et déclenche le téléchargement du fichier.
     */
    public function exportDataBackup() {
        $data = [
            'meta' => ['version' => 1, 'date' => date('c')],
            'auteurs' => $this->pdo->query("SELECT * FROM auteurs")->fetchAll(PDO::FETCH_ASSOC),
            'livres' => $this->pdo->query("SELECT * FROM livres")->fetchAll(PDO::FETCH_ASSOC),
            'listes_lecture' => $this->pdo->query("SELECT * FROM listes_lecture")->fetchAll(PDO::FETCH_ASSOC),
            'livres_listes' => $this->pdo->query("SELECT * FROM livres_listes")->fetchAll(PDO::FETCH_ASSOC),
        ];

        $filename = 'sauvegarde_donnees_' . date('Y-m-d_H-i-s') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Restaure les données depuis un fichier JSON produit par exportDataBackup().
     * Opération transactionnelle : en cas d'erreur, la base retrouve son état d'origine.
     * Retourne le nombre de lignes restaurées par table.
     */
    public function restoreDataBackup($jsonContent) {
        $data = json_decode($jsonContent, true);

        if (!is_array($data) || !isset($data['meta']['version'])
            || !isset($data['auteurs']) || !isset($data['livres'])
            || !isset($data['listes_lecture']) || !isset($data['livres_listes'])) {
            throw new Exception("Fichier de sauvegarde invalide ou incomplet.");
        }

        try {
            $this->pdo->beginTransaction();

            // Ordre enfants -> parents pour respecter les contraintes de clé étrangère
            $this->pdo->exec("DELETE FROM livres_listes");
            $this->pdo->exec("DELETE FROM livres");
            $this->pdo->exec("DELETE FROM listes_lecture");
            $this->pdo->exec("DELETE FROM auteurs");

            // Ordre parents -> enfants pour la réinsertion
            $this->insertRows('auteurs', $data['auteurs']);
            $this->insertRows('livres', $data['livres']);
            $this->insertRows('listes_lecture', $data['listes_lecture']);
            $this->insertRows('livres_listes', $data['livres_listes']);

            $this->pdo->commit();
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'auteurs' => count($data['auteurs']),
            'livres' => count($data['livres']),
            'listes_lecture' => count($data['listes_lecture']),
            'livres_listes' => count($data['livres_listes']),
        ];
    }

    /**
     * Réinsère un tableau de lignes dans une table, en ne gardant que les colonnes
     * qui existent réellement dans le schéma actuel (les colonnes inconnues du
     * fichier de sauvegarde sont ignorées plutôt qu'interpolées telles quelles).
     */
    private function insertRows($table, $rows) {
        if (empty($rows)) {
            return;
        }

        $colonnesReelles = [];
        $stmtColonnes = $this->pdo->query("SHOW COLUMNS FROM $table");
        foreach ($stmtColonnes->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $colonnesReelles[$col['Field']] = true;
        }

        $premiereLigne = array_intersect_key($rows[0], $colonnesReelles);
        $colonnes = array_keys($premiereLigne);
        if (empty($colonnes)) {
            return;
        }

        $placeholders = implode(', ', array_map(function ($c) { return ":$c"; }, $colonnes));
        $listeColonnes = implode(', ', $colonnes);
        $stmt = $this->pdo->prepare("INSERT INTO $table ($listeColonnes) VALUES ($placeholders)");

        foreach ($rows as $row) {
            $ligne = array_intersect_key($row, $colonnesReelles);
            $valeurs = [];
            foreach ($colonnes as $c) {
                $valeurs[":$c"] = $ligne[$c] ?? null;
            }
            $stmt->execute($valeurs);
        }
    }

    /**
     * Lance OPTIMIZE TABLE sur chacune des tables de l'application.
     */
    public function optimizeTables() {
        $tables = ['livres', 'listes_lecture', 'livres_listes', 'auteurs'];
        $resultats = [];

        foreach ($tables as $table) {
            $stmt = $this->pdo->query("OPTIMIZE TABLE $table");
            $ligne = $stmt->fetch(PDO::FETCH_ASSOC);
            $resultats[$table] = $ligne['Msg_text'] ?? 'OK';
        }

        return $resultats;
    }

    /**
     * Rassemble des informations de diagnostic sur la base (taille des tables,
     * version du serveur, index existants) pour la page outils.
     */
    public function getDatabaseInfo() {
        $tables = ['livres', 'listes_lecture', 'livres_listes', 'auteurs'];
        $info = [
            'version' => $this->pdo->query("SELECT VERSION()")->fetchColumn(),
            'tables' => [],
        ];

        foreach ($tables as $table) {
            $stmt = $this->pdo->prepare(
                "SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
            );
            $stmt->execute([':table' => $table]);
            $ligne = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['TABLE_ROWS' => 0, 'DATA_LENGTH' => 0, 'INDEX_LENGTH' => 0];

            $index = $this->pdo->query("SHOW INDEX FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            $nomsIndex = array_values(array_unique(array_column($index, 'Key_name')));

            $info['tables'][$table] = [
                'lignes' => (int)$ligne['TABLE_ROWS'],
                'taille_octets' => (int)$ligne['DATA_LENGTH'] + (int)$ligne['INDEX_LENGTH'],
                'index' => $nomsIndex,
            ];
        }

        return $info;
    }

    /**
     * Analyse le dossier uploads/ : taille totale, nombre de fichiers, et
     * détection des fichiers orphelins (présents sur le disque mais dont
     * aucune ligne de la base - livre ou auteur - ne pointe plus vers eux).
     */
    public function getUploadsStorageInfo() {
        $dossier = 'uploads/';
        $referenced = [];

        foreach ($this->pdo->query("SELECT couverture FROM livres WHERE couverture IS NOT NULL AND couverture != ''")->fetchAll(PDO::FETCH_COLUMN) as $chemin) {
            $referenced[$chemin] = true;
        }

        foreach ($this->pdo->query("SELECT image_url FROM auteurs WHERE image_url IS NOT NULL AND image_url != ''")->fetchAll(PDO::FETCH_COLUMN) as $chemin) {
            $referenced[$chemin] = true;
        }

        $tailleTotale = 0;
        $nbFichiers = 0;
        $orphelins = [];

        if (is_dir($dossier)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fichier) {
                if (!$fichier->isFile()) {
                    continue;
                }
                $chemin = str_replace('\\', '/', $fichier->getPathname());
                $taille = $fichier->getSize();
                $tailleTotale += $taille;
                $nbFichiers++;

                if (!isset($referenced[$chemin])) {
                    $orphelins[] = ['chemin' => $chemin, 'taille' => $taille];
                }
            }
        }

        return [
            'taille_totale' => $tailleTotale,
            'nb_fichiers' => $nbFichiers,
            'orphelins' => $orphelins,
            'nb_orphelins' => count($orphelins),
            'taille_orphelins' => array_sum(array_column($orphelins, 'taille')),
        ];
    }

    /**
     * Supprime du disque tous les fichiers actuellement orphelins dans uploads/.
     * Le calcul des orphelins est refait ici (jamais à partir d'une liste
     * fournie par le client) pour ne jamais risquer de supprimer un fichier
     * référencé ou situé hors du dossier uploads/.
     */
    public function deleteOrphanUploads() {
        $orphelins = $this->getUploadsStorageInfo()['orphelins'];
        $supprimes = 0;

        foreach ($orphelins as $orphelin) {
            if (@unlink($orphelin['chemin'])) {
                $supprimes++;
            }
        }

        return $supprimes;
    }

    /**
     * Construit un fragment de condition SQL pour un match EXACT d'un tag
     * dans une liste CSV "tag1,tag2,tag3" (contrairement à un simple LIKE
     * substring, qui ferait matcher "lu" avec "relu").
     */
    private function buildTagCondition($tag, $suffixe) {
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
    private function splitTagsExact($tagsString) {
        return array_values(array_filter(array_map('trim', explode(',', $tagsString ?? ''))));
    }

    /**
     * Construit la clause WHERE partagée par searchBooksAdvanced() et countBooksAdvanced().
     */
    private function buildAdvancedWhere(array $criteres) {
        $conditions = ["1=1"];
        $params = [];

        if (!empty($criteres['search'])) {
            $conditions[] = "(titre LIKE :search OR auteur LIKE :search OR isbn LIKE :search OR serie LIKE :search)";
            $params[':search'] = '%' . $criteres['search'] . '%';
        }

        if (!empty($criteres['support'])) {
            $conditions[] = "support = :support";
            $params[':support'] = $criteres['support'];
        }

        if (!empty($criteres['statut'])) {
            $conditions[] = "statut = :statut";
            $params[':statut'] = $criteres['statut'];
        }

        if (!empty($criteres['serie'])) {
            $conditions[] = "serie = :serie";
            $params[':serie'] = $criteres['serie'];
        }

        if (!empty($criteres['date_from'])) {
            $conditions[] = "date_ajout >= :date_from";
            $params[':date_from'] = $criteres['date_from'] . ' 00:00:00';
        }

        if (!empty($criteres['date_to'])) {
            $conditions[] = "date_ajout <= :date_to";
            $params[':date_to'] = $criteres['date_to'] . ' 23:59:59';
        }

        if (!empty($criteres['sans_couverture'])) {
            $conditions[] = "(couverture IS NULL OR couverture = '')";
        }

        if (!empty($criteres['sans_note'])) {
            $conditions[] = "(note_personnelle IS NULL OR note_personnelle = '')";
        }

        if (!empty($criteres['tags']) && is_array($criteres['tags'])) {
            $tagConds = [];
            $i = 0;
            foreach ($criteres['tags'] as $tag) {
                $tag = trim($tag);
                if ($tag === '') {
                    continue;
                }
                $cond = $this->buildTagCondition($tag, '_t' . $i);
                $tagConds[] = $cond['sql'];
                $params = array_merge($params, $cond['params']);
                $i++;
            }
            if (!empty($tagConds)) {
                $glue = ($criteres['tags_mode'] ?? 'and') === 'or' ? ' OR ' : ' AND ';
                $conditions[] = '(' . implode($glue, $tagConds) . ')';
            }
        }

        return ['sql' => implode(' AND ', $conditions), 'params' => $params];
    }

    /**
     * Traduit une clé de tri whitelistée en clause ORDER BY sûre.
     */
    private function advancedSortClause($sort) {
        $map = [
            'titre_asc' => 'titre ASC',
            'titre_desc' => 'titre DESC',
            'date_desc' => 'date_ajout DESC',
            'date_asc' => 'date_ajout ASC',
            'serie_asc' => 'serie IS NULL, serie ASC, tome IS NULL, tome ASC, titre ASC',
        ];
        return $map[$sort] ?? 'titre ASC';
    }

    /**
     * Recherche avancée de livres : texte, support, statut, tags (ET/OU),
     * plage de dates, "sans couverture"/"sans note", tri.
     */
    public function searchBooksAdvanced(array $criteres, $page = 1, $limit = null) {
        $limit = $limit ?? $this->itemsPerPage;
        $offset = ($page - 1) * $limit;
        $where = $this->buildAdvancedWhere($criteres);
        $sort = $this->advancedSortClause($criteres['sort'] ?? 'titre_asc');

        $sql = "SELECT * FROM livres WHERE {$where['sql']} ORDER BY $sort LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($where['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Compte les livres correspondant aux mêmes critères que searchBooksAdvanced().
     */
    public function countBooksAdvanced(array $criteres) {
        $where = $this->buildAdvancedWhere($criteres);
        $sql = "SELECT COUNT(*) FROM livres WHERE {$where['sql']}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($where['params']);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Modifie en masse le support, le statut et/ou les tags d'une liste de livres.
     * Ne touche que les colonnes réellement demandées. Ne tronque jamais un tag
     * qui dépasserait la limite de 500 caractères : le livre concerné est ignoré
     * et listé dans skipped_tags_too_long.
     */
    public function bulkUpdateBooks(array $bookIds, $newSupport, $newStatut, $tagMode, $tagsCsv) {
        $bookIds = array_values(array_filter(array_map('intval', $bookIds)));
        if (empty($bookIds)) {
            return ['updated' => 0, 'skipped_tags_too_long' => []];
        }

        $nouveauxTags = $tagMode ? $this->splitTagsExact($tagsCsv) : [];
        $updated = 0;
        $skipped = [];

        try {
            $this->pdo->beginTransaction();
            $selectStmt = $this->pdo->prepare("SELECT tags FROM livres WHERE id = :id");

            foreach ($bookIds as $id) {
                $sets = [];
                $params = [':id' => $id];

                if (!empty($newSupport)) {
                    $sets[] = "support = :support";
                    $params[':support'] = $newSupport;
                }
                if (!empty($newStatut)) {
                    $sets[] = "statut = :statut";
                    $params[':statut'] = $newStatut;
                }

                if ($tagMode) {
                    $selectStmt->execute([':id' => $id]);
                    $tagsActuels = $this->splitTagsExact($selectStmt->fetchColumn());

                    if ($tagMode === 'replace') {
                        $tagsFinal = array_values(array_unique($nouveauxTags));
                    } elseif ($tagMode === 'add') {
                        $tagsFinal = array_values(array_unique(array_merge($tagsActuels, $nouveauxTags)));
                    } elseif ($tagMode === 'remove') {
                        $tagsFinal = array_values(array_diff($tagsActuels, $nouveauxTags));
                    } else {
                        $tagsFinal = $tagsActuels;
                    }

                    $chaineFinale = implode(',', $tagsFinal);
                    if (strlen($chaineFinale) > 500) {
                        $skipped[] = $id;
                    } else {
                        $sets[] = "tags = :tags";
                        $params[':tags'] = $chaineFinale;
                    }
                }

                if (empty($sets)) {
                    continue;
                }

                $sql = "UPDATE livres SET " . implode(', ', $sets) . " WHERE id = :id";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $updated++;
            }

            $this->pdo->commit();
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['updated' => $updated, 'skipped_tags_too_long' => $skipped];
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

    /**
     * Lit les valeurs actuellement autorisées par l'ENUM de la colonne support.
     */
    private function getSupportEnumValues() {
        $col = $this->pdo->query("SHOW COLUMNS FROM livres LIKE 'support'")->fetch(PDO::FETCH_ASSOC);
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $col['Type'], $matches);
        return array_map(function ($v) {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $v);
        }, $matches[1]);
    }

    /**
     * Réécrit la liste des valeurs autorisées par l'ENUM de la colonne support.
     */
    private function setSupportEnumValues(array $values) {
        $quoted = implode(', ', array_map(function ($v) {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
        }, $values));
        $this->pdo->exec("ALTER TABLE livres MODIFY COLUMN support ENUM($quoted) NOT NULL");
    }

    /**
     * Liste les supports disponibles (Livre, Bande dessinée, Manga, ...) avec
     * le nombre de livres associés à chacun.
     */
    public function getSupportTypesWithCounts() {
        $values = $this->getSupportEnumValues();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM livres WHERE support = :support");
        $result = [];
        foreach ($values as $v) {
            $stmt->execute([':support' => $v]);
            $result[$v] = (int)$stmt->fetchColumn();
        }
        return $result;
    }

    /**
     * Ajoute un nouveau type de support (par exemple "Magazine"), sans toucher
     * aux livres existants.
     */
    public function addSupportType($nom) {
        $nom = trim($nom);
        if ($nom === '') {
            throw new Exception("Nom de support invalide.");
        }

        $values = $this->getSupportEnumValues();
        if (in_array($nom, $values, true)) {
            throw new Exception("Ce support existe déjà.");
        }

        $values[] = $nom;
        $this->setSupportEnumValues($values);
    }

    /**
     * Renomme un type de support partout où il est utilisé (met à jour tous
     * les livres concernés, puis retire l'ancien nom de la liste autorisée).
     */
    public function renameSupportType($oldNom, $newNom) {
        $oldNom = trim($oldNom);
        $newNom = trim($newNom);
        if ($oldNom === '' || $newNom === '') {
            throw new Exception("Nom de support invalide.");
        }

        $values = $this->getSupportEnumValues();
        if (!in_array($oldNom, $values, true)) {
            throw new Exception("Ce support n'existe pas.");
        }

        // S'assurer que le nouveau nom est autorisé avant de basculer les livres
        if (!in_array($newNom, $values, true)) {
            $values[] = $newNom;
            $this->setSupportEnumValues($values);
        }

        $stmt = $this->pdo->prepare("UPDATE livres SET support = :new WHERE support = :old");
        $stmt->execute([':new' => $newNom, ':old' => $oldNom]);
        $updated = $stmt->rowCount();

        // Retirer l'ancien nom de la liste autorisée (plus aucun livre ne l'utilise)
        $values = array_values(array_diff($this->getSupportEnumValues(), [$oldNom]));
        $this->setSupportEnumValues($values);

        return $updated;
    }
}