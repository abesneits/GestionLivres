<?php
/**
 * Formats numériques (PDF, Epub, ...) : catalogue extensible (table
 * formats_numeriques) associant à chaque format son extension de fichier
 * attendue. Un livre numérique référence un format par son nom dans
 * livres.format_numerique (VARCHAR, pas d'ENUM à réécrire).
 */
class FormatNumeriqueRepository {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Crée la table catalogue si elle n'existe pas, et la seed avec les deux
     * formats de départ (PDF, Epub) lors de sa toute première création.
     */
    public function createFormatsCatalogTable() {
        $stmt = $this->pdo->query("SHOW TABLES LIKE 'formats_numeriques'");
        $existeDeja = $stmt->rowCount() > 0;

        $sql = "CREATE TABLE IF NOT EXISTS formats_numeriques (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(50) UNIQUE NOT NULL,
            extension VARCHAR(10) NOT NULL
        )";
        $this->pdo->exec($sql);

        if (!$existeDeja) {
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO formats_numeriques (nom, extension) VALUES (:nom, :extension)");
            $stmt->execute([':nom' => 'PDF', ':extension' => 'pdf']);
            $stmt->execute([':nom' => 'Epub', ':extension' => 'epub']);
        }
    }

    /**
     * Liste des noms de formats du catalogue, triés.
     */
    public function getAllFormats() {
        $stmt = $this->pdo->query("SELECT nom FROM formats_numeriques ORDER BY nom");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Liste les formats disponibles avec le nombre de livres associés à chacun.
     */
    public function getFormatsWithCounts() {
        $noms = $this->getAllFormats();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM livres WHERE format_numerique = :nom");
        $result = [];
        foreach ($noms as $nom) {
            $stmt->execute([':nom' => $nom]);
            $result[$nom] = (int)$stmt->fetchColumn();
        }
        return $result;
    }

    /**
     * Extension de fichier attendue pour un format donné (utilisée pour
     * valider l'upload), ou null si le format est inconnu du catalogue.
     */
    public function getFormatExtension($nom) {
        $stmt = $this->pdo->prepare("SELECT extension FROM formats_numeriques WHERE nom = :nom");
        $stmt->execute([':nom' => $nom]);
        $extension = $stmt->fetchColumn();
        return $extension !== false ? $extension : null;
    }

    /**
     * Toutes les extensions du catalogue (pour l'attribut accept du champ fichier).
     */
    public function getAllExtensions() {
        $stmt = $this->pdo->query("SELECT DISTINCT extension FROM formats_numeriques ORDER BY extension");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Ajoute un nouveau format au catalogue.
     */
    public function addFormat($nom, $extension) {
        $nom = trim($nom);
        $extension = strtolower(trim($extension, ". \t\n\r\0\x0B"));

        if ($nom === '') {
            throw new Exception("Nom de format invalide.");
        }
        if ($extension === '' || !preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            throw new Exception("Extension de fichier invalide.");
        }
        if (in_array($nom, $this->getAllFormats(), true)) {
            throw new Exception("Ce format existe déjà.");
        }

        $stmt = $this->pdo->prepare("INSERT INTO formats_numeriques (nom, extension) VALUES (:nom, :extension)");
        $stmt->execute([':nom' => $nom, ':extension' => $extension]);
    }

    /**
     * Renomme un format partout où il est utilisé (livres.format_numerique),
     * puis met à jour le catalogue. L'extension associée est conservée.
     */
    public function renameFormat($oldNom, $newNom) {
        $oldNom = trim($oldNom);
        $newNom = trim($newNom);
        if ($oldNom === '' || $newNom === '') {
            throw new Exception("Nom de format invalide.");
        }

        $extension = $this->getFormatExtension($oldNom);
        if ($extension === null) {
            throw new Exception("Ce format n'existe pas.");
        }
        if ($newNom !== $oldNom && in_array($newNom, $this->getAllFormats(), true)) {
            throw new Exception("Un format porte déjà ce nom.");
        }

        $stmt = $this->pdo->prepare("UPDATE livres SET format_numerique = :new WHERE format_numerique = :old");
        $stmt->execute([':new' => $newNom, ':old' => $oldNom]);
        $updated = $stmt->rowCount();

        $this->pdo->prepare("UPDATE formats_numeriques SET nom = :new WHERE nom = :old")
            ->execute([':new' => $newNom, ':old' => $oldNom]);

        return $updated;
    }

    /**
     * Supprime un format du catalogue, seulement s'il n'est plus utilisé par
     * aucun livre (sinon, il faut d'abord le renommer vers un autre format).
     */
    public function deleteFormat($nom) {
        $nom = trim($nom);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM livres WHERE format_numerique = :nom");
        $stmt->execute([':nom' => $nom]);
        $count = (int)$stmt->fetchColumn();

        if ($count > 0) {
            throw new Exception("Ce format est utilisé par $count livre(s) : renommez-le plutôt vers un autre format.");
        }

        $this->pdo->prepare("DELETE FROM formats_numeriques WHERE nom = :nom")->execute([':nom' => $nom]);
    }
}
