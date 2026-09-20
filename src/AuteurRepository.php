<?php
/**
 * Auteurs : biographies (Wikipédia / Wikidata), photos, regroupement par auteur,
 * détection des doublons et fusion.
 * Tables : auteurs et auteurs_doublons_ignores. Le lien avec les livres se fait
 * uniquement par le champ texte livres.auteur (pas de clé étrangère).
 */
class AuteurRepository {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
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
}
