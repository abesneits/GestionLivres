<?php
/**
 * Recherche d'informations sur un livre à partir de son ISBN
 * (Google Books, Open Library, BnF). N'utilise pas la base de données.
 */
class IsbnLookup {
    private $googleBooksApiKey;

    public function __construct($googleBooksApiKey = null) {
        $this->googleBooksApiKey = $googleBooksApiKey ?: null;
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
}
