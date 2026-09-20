<?php
/**
 * Types de support (Livre, Bande dessinée, Manga, ...).
 * La liste est l'ENUM de la colonne livres.support, réécrite à la volée
 * quand on ajoute ou renomme un support.
 */
class SupportRepository {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Lit les valeurs actuellement autorisées par l'ENUM de la colonne support.
     */
    public function getSupportEnumValues() {
        $col = $this->pdo->query("SHOW COLUMNS FROM livres LIKE 'support'")->fetch(PDO::FETCH_ASSOC);
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $col['Type'], $matches);
        return array_map(function ($v) {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $v);
        }, $matches[1]);
    }

    /**
     * Réécrit la liste des valeurs autorisées par l'ENUM de la colonne support.
     */
    public function setSupportEnumValues(array $values) {
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
