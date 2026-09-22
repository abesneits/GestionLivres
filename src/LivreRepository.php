<?php
/**
 * Livres de la collection : ajout, modification, suppression, recherche simple
 * et avancée, édition en masse, séries, pagination, couvertures personnalisées.
 * Table : livres.
 */
class LivreRepository {
    private $pdo;
    private $tags;
    private $supports;
    private $formats;
    private $itemsPerPage = 20; // Pour la pagination

    public function __construct(PDO $pdo, TagRepository $tags, SupportRepository $supports, FormatNumeriqueRepository $formats) {
        $this->pdo = $pdo;
        $this->tags = $tags;
        $this->supports = $supports;
        $this->formats = $formats;
    }

    /**
     * Ajouter un livre à la collection avec statut
     */
    public function addBook($bookInfo) {
        try {
            $type_livre = $bookInfo['type_livre'] ?? 'Papier';
            $format_numerique = $type_livre === 'Numérique' ? ($bookInfo['format_numerique'] ?? null) : null;
            if ($format_numerique !== null && $this->formats->getFormatExtension($format_numerique) === null) {
                throw new Exception("Format numérique invalide.");
            }

            $sql = "INSERT INTO livres (isbn, titre, support, type_livre, format_numerique, fichier_numerique, auteur, serie, tome, couverture, description, date_publication, statut, tags)
                    VALUES (:isbn, :titre, :support, :type_livre, :format_numerique, :fichier_numerique, :auteur, :serie, :tome, :couverture, :description, :date_publication, :statut, :tags)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':isbn' => $bookInfo['isbn'],
                ':titre' => $bookInfo['titre'],
                ':support' => $bookInfo['support'],
                ':type_livre' => $type_livre,
                ':format_numerique' => $format_numerique,
                ':fichier_numerique' => $bookInfo['fichier_numerique'] ?? null,
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
    public function getAllBooks($support = null, $search = null, $tag = null, $statut = null, $page = 1, $limit = null, $serie = null, $type = null) {
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

        if ($type) {
            $sql .= " AND type_livre = :type";
            $params[':type'] = $type;
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
    public function countBooks($support = null, $search = null, $tag = null, $statut = null, $serie = null, $type = null) {
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

        if ($type) {
            $sql .= " AND type_livre = :type";
            $params[':type'] = $type;
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
    public function updateBookNotes($id, $note_personnelle, $tags, $statut, $support = null, $description = null, $couverture_perso = null, $supprimer_couverture = false, $titre = null, $auteur = null, $serie = null, $tome = null, $type_livre = null, $format_numerique = null, $fichier_numerique_upload = null, $supprimer_fichier_numerique = false) {
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
        // étendue/renommée depuis la page Outils, voir SupportRepository::getSupportEnumValues())
        if ($support !== null && !in_array($support, $this->supports->getSupportEnumValues(), true)) {
            throw new Exception("Support invalide.");
        }

        $type_effectif = $type_livre ?? $book['type_livre'];
        $nouveau_fichier_numerique = $book['fichier_numerique'];

        if ($type_effectif === 'Papier') {
            // Un livre papier n'a ni format ni fichier numérique associé : on
            // efface l'éventuel fichier existant si le livre bascule de numérique à papier.
            if ($book['fichier_numerique'] && file_exists($book['fichier_numerique'])) {
                unlink($book['fichier_numerique']);
            }
            $nouveau_fichier_numerique = null;
            $format_numerique = '';
        } else {
            // Gestion de la suppression du fichier numérique
            if ($supprimer_fichier_numerique) {
                if ($book['fichier_numerique'] && file_exists($book['fichier_numerique'])) {
                    unlink($book['fichier_numerique']);
                }
                $nouveau_fichier_numerique = null;
            }

            // Gestion de l'upload d'un nouveau fichier numérique
            if ($fichier_numerique_upload && $fichier_numerique_upload['error'] === UPLOAD_ERR_OK) {
                $nouveau_fichier_numerique = $this->uploadFichierNumerique($fichier_numerique_upload, $format_numerique);

                // Supprimer l'ancien fichier
                if ($book['fichier_numerique'] && !$supprimer_fichier_numerique && file_exists($book['fichier_numerique'])) {
                    unlink($book['fichier_numerique']);
                }
            }

            // Valider le format numérique si fourni
            if ($format_numerique !== null && $format_numerique !== '' && $this->formats->getFormatExtension($format_numerique) === null) {
                throw new Exception("Format numérique invalide.");
            }
        }

        // Préparer la requête SQL avec support
        $sql = "UPDATE livres SET
                note_personnelle = :note,
                tags = :tags,
                statut = :statut,
                couverture = :couverture,
                fichier_numerique = :fichier_numerique";

        $params = [
            ':id' => $id,
            ':note' => $note_personnelle,
            ':tags' => $tags,
            ':statut' => $statut,
            ':couverture' => $nouvelle_couverture,
            ':fichier_numerique' => $nouveau_fichier_numerique
        ];

        // Ajouter le support si fourni
        if ($support !== null) {
            $sql .= ", support = :support";
            $params[':support'] = $support;
        }

        // Ajouter le type (papier/numérique) si fourni
        if ($type_livre !== null) {
            $sql .= ", type_livre = :type_livre";
            $params[':type_livre'] = $type_livre;
        }

        // Format numérique : null = ne pas toucher, chaîne vide = effacer
        if ($format_numerique !== null) {
            $sql .= ", format_numerique = :format_numerique";
            $params[':format_numerique'] = $format_numerique === '' ? null : $format_numerique;
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
     * Gérer l'upload du fichier numérique (ebook) d'un livre. Publique (contrairement
     * à uploadCouverture()) car appelée depuis ajouter.php avant l'insertion du livre,
     * quand l'id n'existe pas encore. L'extension attendue est celle enregistrée pour
     * le format choisi dans le catalogue (voir FormatNumeriqueRepository), ce qui rend
     * les formats acceptés extensibles sans modifier ce code.
     */
    public function uploadFichierNumerique($fichier, $format_numerique) {
        $extension_attendue = $this->formats->getFormatExtension($format_numerique);
        if ($extension_attendue === null) {
            throw new Exception("Format numérique invalide.");
        }

        $taille_max = 100 * 1024 * 1024; // 100MB

        $extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));

        if ($extension !== $extension_attendue) {
            throw new Exception("Le fichier doit être au format ." . $extension_attendue . " pour correspondre au format sélectionné (" . $format_numerique . ").");
        }

        if ($fichier['size'] > $taille_max) {
            throw new Exception("Le fichier est trop volumineux (max 100MB)");
        }

        // Vérification best-effort du type MIME pour les formats les plus courants
        $mimesConnus = ['pdf' => 'application/pdf', 'epub' => 'application/epub+zip'];
        if (isset($mimesConnus[$extension])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $fichier['tmp_name']);
            finfo_close($finfo);
            if ($mimeType !== $mimesConnus[$extension]) {
                throw new Exception("Le fichier n'est pas un ." . $extension . " valide");
            }
        }

        $dossier_upload = 'uploads/ebooks/';
        if (!is_dir($dossier_upload)) {
            mkdir($dossier_upload, 0755, true);
        }

        $nom_fichier = 'ebook_' . time() . '_' . uniqid() . '.' . $extension;
        $chemin_destination = $dossier_upload . $nom_fichier;

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

        if (!empty($criteres['type_livre'])) {
            $conditions[] = "type_livre = :type_livre";
            $params[':type_livre'] = $criteres['type_livre'];
        }

        if (!empty($criteres['format_numerique'])) {
            $conditions[] = "format_numerique = :format_numerique";
            $params[':format_numerique'] = $criteres['format_numerique'];
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
                $cond = $this->tags->buildTagCondition($tag, '_t' . $i);
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

        $nouveauxTags = $tagMode ? $this->tags->splitTagsExact($tagsCsv) : [];
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
                    $tagsActuels = $this->tags->splitTagsExact($selectStmt->fetchColumn());

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
}
