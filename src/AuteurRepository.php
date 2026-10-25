<?php
/**
 * Auteurs : biographies (Wikipédia / Wikidata), photos, regroupement par auteur,
 * détection des doublons et fusion.
 * Tables : auteurs et auteurs_doublons_ignores. Le lien avec les livres se fait
 * uniquement par le champ texte livres.auteur (pas de clé étrangère).
 */
class AuteurRepository {
    // Particules qui font partie du nom de famille (« de Saint-Exupéry », « Le Guin »)
    private const PARTICULES = ['de', 'du', 'des', 'le', 'la', 'les', 'van', 'von', 'der', 'den', 'ten', 'ter', 'da', 'di', 'del', 'della', 'dos', 'das', 'do', 'el', 'al', 'bin', 'ben', 'ibn', "d'", 'd’'];

    private $pdo;
    private $tags;

    public function __construct(PDO $pdo, ?TagRepository $tags = null) {
        $this->pdo = $pdo;
        $this->tags = $tags ?? new TagRepository($pdo);
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

        // Prénom / nom de famille (corrections manuelles ; à défaut ils sont devinés à partir de « nom »)
        // et métadonnées enrichies (Wikidata) : naissance/décès/nationalité
        $newColumns = [
            'prenom' => "ALTER TABLE auteurs ADD COLUMN prenom VARCHAR(150) AFTER nom",
            'nom_famille' => "ALTER TABLE auteurs ADD COLUMN nom_famille VARCHAR(150) AFTER prenom",
            'naissance' =>"ALTER TABLE auteurs ADD COLUMN naissance VARCHAR(10) AFTER image_url",
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
            // Correspondance EXACTE d'un tag (« lu » ne doit pas trouver « relu »)
            $cond = $this->tags->buildTagCondition($tag, '_auteur');
            $sql .= " AND " . $cond['sql'];
            $params = array_merge($params, $cond['params']);
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

            // Prénom / nom de famille corrigés à la main ; sinon ils sont devinés
            $nomsSaisis = [];
            foreach ($this->pdo->query("SELECT nom, prenom, nom_famille FROM auteurs WHERE nom_famille IS NOT NULL AND nom_famille != ''")->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
                $nomsSaisis[$ligne['nom']] = $ligne;
            }

            foreach ($authors as $nom => &$auteur) {
                if (isset($photos[$nom])) {
                    $auteur['image_url'] = $photos[$nom];
                }
                $auteur['a_une_biographie'] = isset($biosPresentes[$nom]);

                $parties = isset($nomsSaisis[$nom])
                    ? ['prenom' => (string)$nomsSaisis[$nom]['prenom'], 'nom_famille' => $nomsSaisis[$nom]['nom_famille']]
                    : $this->devinerPrenomNom($nom);
                $auteur['prenom'] = $parties['prenom'];
                $auteur['nom_famille'] = $parties['nom_famille'];
                $auteur['cle_nom'] = $this->cleTriNom($parties['nom_famille']);
                // Sans prénom (« Voltaire »), le nom unique sert de repère pour le tri par prénom
                $auteur['cle_prenom'] = $this->normalizeAuthorName($parties['prenom'] !== '' ? $parties['prenom'] : $parties['nom_famille']);
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
            case 'prenom':
                uasort($authors, function ($a, $b) {
                    return strnatcmp($a['cle_prenom'], $b['cle_prenom']) ?: strnatcmp($a['cle_nom'], $b['cle_nom']);
                });
                break;
            default:
                // Par nom de famille, puis par prénom
                uasort($authors, function ($a, $b) {
                    return strnatcmp($a['cle_nom'], $b['cle_nom']) ?: strnatcmp($a['cle_prenom'], $b['cle_prenom']);
                });
        }

        return array_values($authors);
    }

    /**
     * Deviner prénom et nom de famille à partir d'un nom complet « Prénom Nom ».
     * Le dernier mot est le nom de famille, précédé de ses éventuelles particules
     * (« Antoine de Saint-Exupéry » -> « Antoine » / « de Saint-Exupéry »).
     * Un nom d'un seul mot (« Voltaire ») n'a pas de prénom. Heuristique : l'utilisateur
     * corrige les cas particuliers (noms composés, auteurs collectifs...) dans la fenêtre d'édition.
     */
    private function devinerPrenomNom($nom) {
        $mots = preg_split('/\s+/u', trim($nom), -1, PREG_SPLIT_NO_EMPTY);
        if (count($mots) <= 1) {
            return ['prenom' => '', 'nom_famille' => trim($nom)];
        }

        $debutNom = count($mots) - 1;
        while ($debutNom > 0 && in_array(mb_strtolower($mots[$debutNom - 1], 'UTF-8'), self::PARTICULES, true)) {
            $debutNom--;
        }

        return [
            'prenom' => implode(' ', array_slice($mots, 0, $debutNom)),
            'nom_famille' => implode(' ', array_slice($mots, $debutNom))
        ];
    }

    /**
     * Clé de tri d'un nom de famille : sans accents ni casse, et sans les particules écrites
     * en minuscules (« de Saint-Exupéry » se range à S, mais « De Gaulle » reste à D).
     */
    private function cleTriNom($nomFamille) {
        $mots = preg_split('/\s+/u', trim($nomFamille), -1, PREG_SPLIT_NO_EMPTY);
        while (count($mots) > 1 && in_array($mots[0], self::PARTICULES, true)) {
            array_shift($mots);
        }
        $nom = implode(' ', $mots);
        $nom = preg_replace('/^d[\'’]/u', '', $nom);
        return $this->normalizeAuthorName($nom);
    }

    /**
     * Prénom et nom de famille d'un auteur : valeurs saisies si elles existent, sinon devinées.
     */
    private function getAuthorNameParts($nom) {
        $stmt = $this->pdo->prepare("SELECT prenom, nom_famille FROM auteurs WHERE nom = :nom");
        $stmt->execute([':nom' => $nom]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['nom_famille'] !== null && $row['nom_famille'] !== '') {
            return ['prenom' => (string)$row['prenom'], 'nom_famille' => $row['nom_famille']];
        }
        return $this->devinerPrenomNom($nom);
    }

    /**
     * Exporter une liste d'auteurs (telle que retournée par getAllAuthorsWithBooks) en CSV.
     * Envoie directement les en-têtes et le fichier, comme les autres exports de l'application.
     */
    public function exportAuthorsCSV($authors) {
        require_once __DIR__ . '/../includes/export_functions.php';
        $filename = 'auteurs_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // BOM UTF-8 pour Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Nom', 'Prénom', 'Nom de famille', 'Nombre de livres', 'Naissance', 'Décès', 'Nationalité', 'Biographie', 'Titres possédés'], ';');

        // Une seule requête pour toutes les fiches, indexées par nom (au lieu d'une requête par auteur)
        $metas = [];
        foreach ($this->pdo->query("SELECT nom, biographie, naissance, deces, nationalite FROM auteurs")->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $metas[$ligne['nom']] = $ligne;
        }

        foreach ($authors as $auteur) {
            $meta = $metas[$auteur['nom']] ?? [];

            $titres = implode(' | ', array_column($auteur['livres'], 'titre'));

            // csvSafe neutralise les cellules qui commenceraient par = + - @ (injection de formule)
            fputcsv($output, array_map('csvSafe', [
                $auteur['nom'],
                $auteur['prenom'] ?? '',
                $auteur['nom_famille'] ?? '',
                count($auteur['livres']),
                $meta['naissance'] ?? '',
                $meta['deces'] ?? '',
                $meta['nationalite'] ?? '',
                $meta['biographie'] ?? '',
                $titres
            ]), ';');
        }

        fclose($output);
        exit;
    }

    /**
     * Définir manuellement la biographie d'un auteur (complète ou remplace le résultat de Wikipédia)
     */
    public function setAuthorBiography($nom, $biographie, $imageUrl = null, $photoFile = null, $naissance = null, $deces = null, $nationalite = null, $prenom = null, $nomFamille = null) {
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
        $prenom = trim($prenom ?? '');
        $nomFamille = trim($nomFamille ?? '');

        // Si le nom de famille est vidé, on revient à la détection automatique (prénom ignoré aussi)
        if ($nomFamille === '') {
            $prenom = '';
        }

        $sql = "INSERT INTO auteurs (nom, prenom, nom_famille, biographie, image_url, naissance, deces, nationalite)
                VALUES (:nom, :prenom, :nom_famille, :bio, :image, :naissance, :deces, :nationalite)
                ON DUPLICATE KEY UPDATE
                    prenom = VALUES(prenom),
                    nom_famille = VALUES(nom_famille),
                    biographie = VALUES(biographie),
                    image_url = VALUES(image_url),
                    naissance = VALUES(naissance),
                    deces = VALUES(deces),
                    nationalite = VALUES(nationalite),
                    date_maj = CURRENT_TIMESTAMP";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':nom' => $nom,
            ':prenom' => $prenom !== '' ? $prenom : null,
            ':nom_famille' => $nomFamille !== '' ? $nomFamille : null,
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

        // Type réel du fichier (et non celui annoncé par l'extension ou le navigateur)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $fichier['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new Exception("Le fichier n'est pas une image valide");
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
            $data = [
                'biographie' => $row['biographie'],
                'image_url' => $row['image_url'],
                'naissance' => $row['naissance'],
                'deces' => $row['deces'],
                'nationalite' => $row['nationalite']
            ];
        } else {
            $data = $this->fetchAndCacheAuthorMetadata($nom);
        }

        return $data + $this->getAuthorNameParts($nom);
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
    public function findDuplicateAuthorGroups($authors = null) {
        // On peut fournir la liste déjà calculée pour éviter de relire toute la collection
        $authors = $authors ?? $this->getAllAuthorsWithBooks();

        $groups = [];
        foreach ($authors as $auteur) {
            $cle = $this->normalizeAuthorName($auteur['nom']);
            if (!isset($groups[$cle])) {
                $groups[$cle] = [];
            }
            $groups[$cle][] = $auteur;
        }

        $ignores = $this->pdo->query("SELECT cle_normalisee FROM auteurs_doublons_ignores")->fetchAll(PDO::FETCH_COLUMN);

        // Rapproche aussi les noms qui ne diffèrent que par l'ordre des mots (« Hugo Victor » / « Victor Hugo »)
        $parOrdre = [];
        foreach ($groups as $cle => $membres) {
            $mots = explode(' ', $cle);
            sort($mots);
            $parOrdre[implode(' ', $mots)][$cle] = $membres;
        }

        $doublons = [];
        foreach ($parOrdre as $cleOrdre => $sousGroupes) {
            $membres = array_merge(...array_values($sousGroupes));
            if (count($membres) < 2) {
                continue;
            }
            // Un groupe de simples variantes garde sa clé d'origine (les groupes déjà ignorés le restent)
            $cle = count($sousGroupes) === 1 ? array_key_first($sousGroupes) : $cleOrdre;
            if (!in_array((string)$cle, $ignores, true)) {
                $doublons[] = ['cle' => (string)$cle, 'auteurs' => $membres];
            }
        }

        return $doublons;
    }

    /**
     * Repérer les noms d'auteurs qui ressemblent à une erreur de saisie ou d'import
     * (titre de livre, parenthèses, majuscules, un seul mot...). Chaque résultat indique
     * les raisons et, quand c'est possible, une correction proposée.
     * Les noms que l'utilisateur a choisi d'ignorer sont mémorisés avec le préfixe « verif: ».
     */
    public function findSuspiciousAuthors($authors = null) {
        $authors = $authors ?? $this->getAllAuthorsWithBooks();
        $ignores = array_flip($this->pdo->query("SELECT cle_normalisee FROM auteurs_doublons_ignores WHERE cle_normalisee LIKE 'verif:%'")->fetchAll(PDO::FETCH_COLUMN));

        $suspects = [];
        foreach ($authors as $auteur) {
            $nom = $auteur['nom'];
            if (isset($ignores['verif:' . $nom])) {
                continue;
            }

            $mots = preg_split('/\s+/u', $nom, -1, PREG_SPLIT_NO_EMPTY);
            $raisons = [];

            if (preg_match('/\d/', $nom)) {
                $raisons[] = "contient un chiffre";
            }
            if (count($mots) >= 5) {
                $raisons[] = "nom très long (titre de livre ?)";
            }
            if (preg_match('/[:;()\/\[\]!?"]/u', $nom)) {
                $raisons[] = "ponctuation inhabituelle";
            }
            if (preg_match('/[\'’]\s/u', $nom) || preg_match('/\s{2,}/u', $nom)) {
                $raisons[] = "espaces en trop";
            }
            if (preg_match('/\b\p{Lu}{3,}\b/u', $nom)) {
                $raisons[] = "mot en majuscules";
            }
            if (preg_match('/^\p{Ll}/u', $nom)) {
                $raisons[] = "commence par une minuscule";
            }
            foreach ($auteur['livres'] as $livre) {
                if (mb_strtolower(trim($livre['titre']), 'UTF-8') === mb_strtolower($nom, 'UTF-8')) {
                    $raisons[] = "identique au titre d'un de ses livres";
                    break;
                }
            }
            if (preg_match('/collectif|éditions?|editions?|magazine|studio|rédaction|anonyme|inconnu|unknown|équipe|auteurs/iu', $nom)) {
                $raisons[] = "auteur collectif ou organisme ?";
            }
            if (count($mots) === 1) {
                $raisons[] = "un seul mot (prénom ou nom manquant ?)";
            }

            if ($raisons) {
                $suspects[] = [
                    'nom' => $nom,
                    'nb_livres' => count($auteur['livres']),
                    'raisons' => $raisons,
                    'suggestion' => $this->proposerNomCorrige($nom)
                ];
            }
        }

        return $suspects;
    }

    /**
     * Nom corrigé proposé pour un nom suspect : parenthèses et ponctuation finale retirées,
     * espaces et apostrophes réparés, majuscules ramenées à une casse normale.
     * Chaîne vide si rien n'est à corriger.
     */
    private function proposerNomCorrige($nom) {
        $corrige = preg_replace('/\s*[\(\[].*$/u', '', $nom);
        $corrige = preg_replace('/[\s.)\]]+$/u', '', $corrige);
        $corrige = preg_replace('/(\b[dDlL][\'’])\s+/u', '$1', $corrige);
        $corrige = preg_replace('/\s+/u', ' ', trim($corrige));
        $corrige = preg_replace_callback('/\b\p{Lu}{3,}\b/u', function ($m) {
            return mb_convert_case($m[0], MB_CASE_TITLE, 'UTF-8');
        }, $corrige);

        return ($corrige !== $nom && $corrige !== '') ? $corrige : '';
    }

    /**
     * Mémoriser qu'un nom signalé « à vérifier » est correct (il ne sera plus signalé).
     */
    public function ignoreSuspiciousAuthor($nom) {
        $nom = trim($nom);
        if ($nom === '') {
            throw new Exception("Nom d'auteur invalide.");
        }
        $this->ignoreDuplicateGroup('verif:' . $nom);
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
        if ($nom === '') {
            return false;
        }

        // La base ne garde que les livres dont le texte « auteur » contient ce nom ;
        // PHP vérifie ensuite la correspondance exacte, nom par nom.
        $stmt = $this->pdo->prepare("SELECT auteur FROM livres WHERE auteur LIKE :motif");
        $stmt->execute([':motif' => '%' . addcslashes($nom, '\\%_') . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $texte) {
            foreach (array_map('trim', explode(',', $texte)) as $candidat) {
                if ($candidat === $nom) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Relance la recherche de biographie sur Wikipédia en court-circuitant le
     * cache, et met à jour la biographie mise en cache avec le résultat.
     */
    public function refreshAuthorBiography($nom) {
        return $this->fetchAndCacheAuthorMetadata($nom) + $this->getAuthorNameParts($nom);
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

        // Tout ou rien : une erreur au milieu ne laisse pas des auteurs à moitié fusionnés
        $this->pdo->beginTransaction();
        try {
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

        $this->pdo->commit();
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $livresModifies;
    }
}
