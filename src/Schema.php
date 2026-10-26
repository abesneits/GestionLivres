<?php
/**
 * Schéma de la base : création des tables et migrations idempotentes
 * (CREATE TABLE IF NOT EXISTS + ALTER TABLE gardés), index.
 * ensureSchema() est appelé sur chaque page par includes/bootstrap.php.
 * Les tables listes, auteurs (et leur liaison avec les livres) et tags sont créées par leurs propres repositories.
 */
class Schema {
    private $pdo;
    private $supports;
    private $listes;
    private $auteurs;
    private $tags;
    private $formats;
    private $achats;
    private $maintenance;

    public function __construct(PDO $pdo, SupportRepository $supports, ListeRepository $listes, AuteurRepository $auteurs, TagRepository $tags, FormatNumeriqueRepository $formats, AchatRepository $achats, Maintenance $maintenance) {
        $this->pdo = $pdo;
        $this->supports = $supports;
        $this->listes = $listes;
        $this->auteurs = $auteurs;
        $this->tags = $tags;
        $this->formats = $formats;
        $this->achats = $achats;
        $this->maintenance = $maintenance;
    }

    /**
     * Garantit que le schéma complet (tables, colonnes, index) existe et est à jour.
     * Point d'entrée unique appelé par includes/bootstrap.php sur chaque page.
     *
     * Les ~30 vérifications ne sont faites qu'une fois par « version du code de
     * schéma » : l'empreinte des fichiers qui définissent le schéma est mémorisée
     * dans la table schema_meta. Tant qu'elle ne change pas (aucun fichier mis à
     * jour), une page ne coûte qu'UNE requête. Dès qu'un de ces fichiers est
     * remplacé, la version change et les vérifications repartent une fois.
     * $force = true les rejoue de toute façon (ex. colonne supprimée à la main).
     */
    public function ensureSchema($force = false) {
        $version = $this->computeVersion();
        if (!$force && $this->isUpToDate($version)) {
            return;
        }

        $this->createTable();
        $this->listes->createListTables();
        $this->auteurs->createAuthorTable();
        $this->createIndexes();
        $this->auteurs->createIgnoredDuplicatesTable();
        $this->tags->createTagsCatalogTable();
        $this->formats->createFormatsCatalogTable();
        $this->achats->createAchatsTable();

        // Liaison livres <-> auteurs, puis migration de l'ancienne colonne livres.auteur (idempotente). En cas d'échec, l'erreur est seulement journalisée
        // pour ne pas bloquer toutes les pages, et la version n'est pas mémorisée : tout est
        // retenté à la page suivante.
        $liaisonPrete = false;
        try {
            $this->auteurs->createBookAuthorsTable();
            $liaisonPrete = $this->migrerColonneAuteur();
        } catch (Exception $e) {
            error_log("Table livres_auteurs impossible à créer : " . $e->getMessage());
        }

        if ($liaisonPrete) {
            $this->markUpToDate($version);
        }
    }

    /**
     * Empreinte des fichiers qui définissent le schéma : si l'un d'eux change,
     * la version change et le schéma est revérifié.
     */
    private function computeVersion() {
        $empreinte = '';
        foreach (['Schema', 'SupportRepository', 'ListeRepository', 'AuteurRepository', 'TagRepository', 'FormatNumeriqueRepository', 'AchatRepository'] as $classe) {
            $empreinte .= (string)@md5_file(__DIR__ . '/' . $classe . '.php');
        }
        return md5($empreinte);
    }

    /**
     * La version mémorisée en base est-elle celle du code actuel ?
     * Si la table schema_meta n'existe pas encore (première fois), c'est « non ».
     */
    private function isUpToDate($version) {
        try {
            $stmt = $this->pdo->prepare("SELECT valeur FROM schema_meta WHERE cle = 'version'");
            $stmt->execute();
            return $stmt->fetchColumn() === $version;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function markUpToDate($version) {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_meta (
            cle VARCHAR(50) PRIMARY KEY,
            valeur VARCHAR(64) NOT NULL
        )");
        $stmt = $this->pdo->prepare("REPLACE INTO schema_meta (cle, valeur) VALUES ('version', :v)");
        $stmt->execute([':v' => $version]);
    }

    /**
     * Migration de l'ancien stockage des auteurs : le texte libre livres.auteur est remplacé par la
     * table de liaison livres_auteurs. Tant que la colonne existe : on relie les livres qui n'ont pas
     * encore de lien d'après leur texte, on vérifie qu'aucun nom n'est perdu, on enregistre une copie
     * des textes dans backups/, puis on supprime la colonne. Sans copie ou sans lien complet, la colonne
     * est conservée et tout sera retenté. Idempotent. Retourne false si la migration n'est pas terminée.
     */
    private function migrerColonneAuteur() {
        if ($this->pdo->query("SHOW COLUMNS FROM livres LIKE 'auteur'")->rowCount() === 0) {
            return true;
        }

        if (!$this->auteurs->backfillBookAuthors()) {
            return false;
        }

        $manquants = $this->auteurs->countBooksWithUnlinkedAuthorText();
        if ($manquants > 0) {
            error_log("Colonne livres.auteur conservée : $manquants livre(s) ne sont pas reliés à leurs auteurs.");
            return false;
        }

        try {
            $textes = $this->pdo->query("SELECT id, auteur FROM livres WHERE auteur IS NOT NULL AND auteur != ''")->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->maintenance->writeBackupFile('colonne_auteur_' . date('Y-m-d_H-i-s') . '.json', [
                'meta' => ['description' => "Copie du texte de livres.auteur avant suppression de la colonne (id du livre => texte)", 'date' => date('c')],
                'livres_auteur' => $textes,
            ]);
        } catch (Exception $e) {
            error_log("Colonne livres.auteur conservée : copie de sécurité impossible (" . $e->getMessage() . ")");
            return false;
        }

        $this->pdo->exec("ALTER TABLE livres DROP COLUMN auteur");
        return true;
    }

    /**
     * Créer la table si elle n'existe pas et vérifier/ajouter les nouvelles colonnes
     */
    public function createTable() {
        // Créer la table avec la structure de base si elle n'existe pas
        $sql = "CREATE TABLE IF NOT EXISTS livres (
            id INT AUTO_INCREMENT PRIMARY KEY,
            isbn VARCHAR(40) UNIQUE NOT NULL,
            titre VARCHAR(255) NOT NULL,
            support ENUM('Livre', 'Bande dessinée', 'Manga') NOT NULL,
            date_publication VARCHAR(20),
            date_ajout TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
        
        // Vérifier si la colonne couverture existe, sinon l'ajouter
        $checkColumn = "SHOW COLUMNS FROM livres LIKE 'couverture'";
        $result = $this->pdo->query($checkColumn);
        
        if ($result->rowCount() == 0) {
            $alterSql = "ALTER TABLE livres ADD COLUMN couverture TEXT AFTER titre";
            $this->pdo->exec($alterSql);
            echo "<div class='message success'>Colonne 'couverture' ajoutée avec succès à la base de données !</div>";
        }
        
        // Vérifier et ajouter les nouvelles colonnes
        $newColumns = [
            'description' => "ALTER TABLE livres ADD COLUMN description TEXT AFTER couverture",
            'note_personnelle' => "ALTER TABLE livres ADD COLUMN note_personnelle TEXT AFTER description",
            'tags' => "ALTER TABLE livres ADD COLUMN tags VARCHAR(500) AFTER note_personnelle",
            'statut' => "ALTER TABLE livres ADD COLUMN statut ENUM('À lire', 'En cours', 'Lu', 'Abandonné') DEFAULT 'À lire' AFTER tags",
            'serie' => "ALTER TABLE livres ADD COLUMN serie VARCHAR(255) NULL AFTER titre",
            'tome' => "ALTER TABLE livres ADD COLUMN tome INT NULL AFTER serie",
            // Papier/Numérique : DEFAULT 'Papier' fait que MySQL affecte automatiquement
            // cette valeur à tous les livres existants dès l'ajout de la colonne.
            'type_livre' => "ALTER TABLE livres ADD COLUMN type_livre ENUM('Papier', 'Numérique') NOT NULL DEFAULT 'Papier' AFTER support",
            'format_numerique' => "ALTER TABLE livres ADD COLUMN format_numerique VARCHAR(50) NULL AFTER type_livre",
            'fichier_numerique' => "ALTER TABLE livres ADD COLUMN fichier_numerique VARCHAR(500) NULL AFTER format_numerique"
        ];
        
        foreach ($newColumns as $columnName => $alterSql) {
            $checkColumn = "SHOW COLUMNS FROM livres LIKE '$columnName'";
            $result = $this->pdo->query($checkColumn);
            
            if ($result->rowCount() == 0) {
                $this->pdo->exec($alterSql);
                echo "<div class='message success'>Colonne '$columnName' ajoutée avec succès !</div>";
            }
        }
        
        // Supprimer la colonne editeur si elle existe encore
        $checkEditorColumn = "SHOW COLUMNS FROM livres LIKE 'editeur'";
        $result = $this->pdo->query($checkEditorColumn);

        if ($result->rowCount() > 0) {
            $dropSql = "ALTER TABLE livres DROP COLUMN editeur";
            $this->pdo->exec($dropSql);
            echo "<div class='message success'>Colonne 'editeur' supprimée avec succès !</div>";
        }

        // Anciennes bases : élargir la colonne isbn (nouvelles bases : déjà à 40). Les ISBN temporaires générés pour la saisie
        // manuelle (ex: TEMP_1755600000_64f...) dépassent VARCHAR(13)
        $isbnColumn = $this->pdo->query("SHOW COLUMNS FROM livres LIKE 'isbn'")->fetch(PDO::FETCH_ASSOC);
        if ($isbnColumn && preg_match('/varchar\((\d+)\)/i', $isbnColumn['Type'], $matches) && (int)$matches[1] < 40) {
            $this->pdo->exec("ALTER TABLE livres MODIFY COLUMN isbn VARCHAR(40) NOT NULL");
            echo "<div class='message success'>Colonne 'isbn' élargie avec succès !</div>";
        }

        // Migration ponctuelle : sur une très ancienne base qui n'a encore que les
        // 2 supports d'origine (avant l'introduction de 'Manga'), ajouter 'Manga'.
        // Ne se déclenche qu'une seule fois : dès que la liste compte 3 valeurs ou
        // plus (que ce soit 'Manga' ou un support personnalisé ajouté/renommé
        // depuis la page Outils), on ne touche plus jamais à la liste ici.
        $valeursSupport = $this->supports->getSupportEnumValues();
        if (count($valeursSupport) < 3 && !in_array('Manga', $valeursSupport, true)) {
            $valeursSupport[] = 'Manga';
            $this->supports->setSupportEnumValues($valeursSupport);
            echo "<div class='message success'>Support 'Manga' ajouté avec succès !</div>";
        }
    }

    /**
     * Crée les index recommandés sur les colonnes fréquemment filtrées, de façon idempotente.
     */
    public function createIndexes() {
        $indexes = [
            'livres' => [
                'idx_support' => "CREATE INDEX idx_support ON livres(support)",
                'idx_type_livre' => "CREATE INDEX idx_type_livre ON livres(type_livre)",
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
}
