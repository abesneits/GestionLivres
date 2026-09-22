<?php
/**
 * Maintenance : sauvegarde/restauration JSON, optimisation des tables,
 * diagnostic de la base et du dossier uploads/ (fichiers orphelins).
 */
class Maintenance {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
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
            // TABLE_ROWS d'information_schema n'est qu'une estimation pour les
            // tables InnoDB (rafraîchie périodiquement, pas à chaque écriture) :
            // elle peut dériver du vrai total après beaucoup d'insertions/suppressions.
            // On garde information_schema seulement pour la taille (DATA_LENGTH/
            // INDEX_LENGTH), qui n'a pas besoin d'être exacte, et on compte les
            // lignes avec un vrai COUNT(*).
            $stmt = $this->pdo->prepare(
                "SELECT DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
            );
            $stmt->execute([':table' => $table]);
            $ligne = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['DATA_LENGTH' => 0, 'INDEX_LENGTH' => 0];

            $nbLignes = (int)$this->pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();

            $index = $this->pdo->query("SHOW INDEX FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            $nomsIndex = array_values(array_unique(array_column($index, 'Key_name')));

            $info['tables'][$table] = [
                'lignes' => $nbLignes,
                'taille_octets' => (int)$ligne['DATA_LENGTH'] + (int)$ligne['INDEX_LENGTH'],
                'index' => $nomsIndex,
            ];
        }

        return $info;
    }

    /**
     * Analyse le dossier uploads/ : taille totale, nombre de fichiers, et
     * détection des fichiers orphelins (présents sur le disque mais dont
     * aucune ligne de la base - livre, auteur ou liste - ne pointe plus vers eux).
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

        // Couvertures des listes de lecture : sans cette vérification, toutes
        // les images de listes étaient considérées comme orphelines et donc
        // supprimables par erreur (voir historique de ce fichier).
        foreach ($this->pdo->query("SELECT couverture FROM listes_lecture WHERE couverture IS NOT NULL AND couverture != ''")->fetchAll(PDO::FETCH_COLUMN) as $chemin) {
            $referenced[$chemin] = true;
        }

        $tailleTotale = 0;
        $nbFichiers = 0;
        $orphelins = [];

        if (is_dir($dossier)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fichier) {
                // Ignorer les fichiers cachés (ex: .htaccess, qui protège le dossier) :
                // ce ne sont pas des uploads, et ils ne doivent jamais être supprimés
                if (!$fichier->isFile() || $fichier->getFilename()[0] === '.') {
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
}
