<?php
/**
 * Schéma de la base : création des tables et migrations idempotentes
 * (CREATE TABLE IF NOT EXISTS + ALTER TABLE gardés), index.
 * ensureSchema() est appelé sur chaque page par includes/bootstrap.php.
 * Les tables listes, auteurs et tags sont créées par leurs propres repositories.
 */
class Schema {
    private $pdo;
    private $supports;
    private $listes;
    private $auteurs;
    private $tags;
    private $formats;

    public function __construct(PDO $pdo, SupportRepository $supports, ListeRepository $listes, AuteurRepository $auteurs, TagRepository $tags, FormatNumeriqueRepository $formats) {
        $this->pdo = $pdo;
        $this->supports = $supports;
        $this->listes = $listes;
        $this->auteurs = $auteurs;
        $this->tags = $tags;
        $this->formats = $formats;
    }

    /**
     * Garantit que le schéma complet (tables, colonnes, index) existe et est à jour.
     * Point d'entrée unique appelé par includes/bootstrap.php sur chaque page.
     */
    public function ensureSchema() {
        $this->createTable();
        $this->listes->createListTables();
        $this->auteurs->createAuthorTable();
        $this->createIndexes();
        $this->auteurs->createIgnoredDuplicatesTable();
        $this->tags->createTagsCatalogTable();
        $this->formats->createFormatsCatalogTable();
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
        $valeursSupport = $this->supports->getSupportEnumValues();
        if (count($valeursSupport) < 3 && !in_array('Manga', $valeursSupport, true)) {
            $valeursSupport[] = 'Manga';
            $this->supports->setSupportEnumValues($valeursSupport);
            echo "<div class='message success'>✅ Support 'Manga' ajouté avec succès !</div>";
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
