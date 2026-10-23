<?php
/**
 * Achats à effectuer : liste de livres à acheter, validation d'un achat
 * (le livre est alors ajouté à la collection) et suppression.
 * Table : achats. Les achats ne sont jamais mélangés à la table livres tant
 * qu'ils ne sont pas validés.
 */
class AchatRepository {
    private $pdo;
    private $livres;

    public function __construct(PDO $pdo, LivreRepository $livres) {
        $this->pdo = $pdo;
        $this->livres = $livres;
    }

    public function createAchatsTable() {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS achats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                isbn VARCHAR(40) NULL,
                titre VARCHAR(255) NOT NULL,
                auteur VARCHAR(255) NULL,
                support VARCHAR(50) NOT NULL DEFAULT 'Livre',
                type_livre ENUM('Papier','Numérique') NOT NULL DEFAULT 'Papier',
                serie VARCHAR(255) NULL,
                tome INT NULL,
                couverture TEXT NULL,
                description TEXT NULL,
                date_publication VARCHAR(20) NULL,
                tags VARCHAR(500) NULL,
                priorite TINYINT NOT NULL DEFAULT 2,
                prix_estime DECIMAL(8,2) NULL,
                lieu_achat VARCHAR(150) NULL,
                note TEXT NULL,
                date_ajout TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_achats_priorite (priorite)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci");
        } catch (PDOException $e) {
            error_log("Erreur création table achats : " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Liste des achats. $tri : priorite (défaut), titre, auteur, date.
     */
    public function getAchats($tri = 'priorite') {
        $ordres = [
            'priorite' => 'priorite ASC, titre ASC',
            'titre' => 'titre ASC',
            'auteur' => 'auteur ASC, titre ASC',
            'date' => 'date_ajout DESC',
        ];
        $ordre = $ordres[$tri] ?? $ordres['priorite'];
        return $this->pdo->query("SELECT * FROM achats ORDER BY $ordre")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAchat($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM achats WHERE id = :id");
        $stmt->execute([':id' => (int)$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function addAchat(array $d) {
        $stmt = $this->pdo->prepare("INSERT INTO achats
            (isbn, titre, auteur, support, type_livre, serie, tome, couverture, description, date_publication, tags, priorite, prix_estime, lieu_achat, note)
            VALUES (:isbn, :titre, :auteur, :support, :type_livre, :serie, :tome, :couverture, :description, :date_publication, :tags, :priorite, :prix_estime, :lieu_achat, :note)");
        $stmt->execute($this->params($d));
        return (int)$this->pdo->lastInsertId();
    }

    public function updateAchat($id, array $d) {
        $stmt = $this->pdo->prepare("UPDATE achats SET isbn = :isbn, titre = :titre, auteur = :auteur, support = :support,
            type_livre = :type_livre, serie = :serie, tome = :tome, couverture = :couverture, description = :description,
            date_publication = :date_publication, tags = :tags, priorite = :priorite, prix_estime = :prix_estime,
            lieu_achat = :lieu_achat, note = :note WHERE id = :id");
        $stmt->execute($this->params($d) + [':id' => (int)$id]);
        return true;
    }

    public function deleteAchat($id) {
        $stmt = $this->pdo->prepare("DELETE FROM achats WHERE id = :id");
        $stmt->execute([':id' => (int)$id]);
        return true;
    }

    /**
     * Valide l'achat : le livre est ajouté à la collection (même contrôle de
     * doublon que l'ajout normal) puis retiré de la liste d'achats.
     * Si l'ajout échoue (ex. déjà dans la collection), l'achat est conservé.
     */
    public function validerAchat($id, $statut = 'À lire') {
        $achat = $this->getAchat($id);
        if (!$achat) {
            throw new Exception("Achat introuvable.");
        }

        $this->pdo->beginTransaction();
        try {
            $isbn = trim((string)$achat['isbn']);
            $this->livres->addBook([
                'isbn' => $isbn !== '' ? $isbn : 'TEMP_' . time() . '_' . uniqid(),
                'titre' => $achat['titre'],
                'auteur' => (string)$achat['auteur'],
                'support' => $achat['support'],
                'type_livre' => $achat['type_livre'],
                'serie' => $achat['serie'],
                'tome' => $achat['tome'],
                'couverture' => $achat['couverture'],
                'description' => $achat['description'],
                'date_publication' => (string)$achat['date_publication'],
                'statut' => $statut,
                'tags' => (string)$achat['tags'],
            ]);
            $this->deleteAchat($id);
            $this->pdo->commit();
            return $achat;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Normalise les données du formulaire pour la requête préparée.
     */
    private function params(array $d) {
        $texte = function ($cle) use ($d) {
            $v = trim((string)($d[$cle] ?? ''));
            return $v === '' ? null : $v;
        };
        $prix = str_replace(',', '.', trim((string)($d['prix_estime'] ?? '')));
        $tome = trim((string)($d['tome'] ?? ''));
        $priorite = (int)($d['priorite'] ?? 2);

        return [
            ':isbn' => $texte('isbn'),
            ':titre' => trim((string)($d['titre'] ?? '')),
            ':auteur' => $texte('auteur'),
            ':support' => trim((string)($d['support'] ?? '')) ?: 'Livre',
            ':type_livre' => ($d['type_livre'] ?? 'Papier') === 'Numérique' ? 'Numérique' : 'Papier',
            ':serie' => $texte('serie'),
            ':tome' => ctype_digit($tome) && strlen($tome) <= 6 ? (int)$tome : null,
            ':couverture' => $texte('couverture'),
            ':description' => $texte('description'),
            ':date_publication' => $texte('date_publication'),
            ':tags' => $texte('tags'),
            ':priorite' => in_array($priorite, [1, 2, 3], true) ? $priorite : 2,
            ':prix_estime' => is_numeric($prix) && $prix >= 0 ? round((float)$prix, 2) : null,
            ':lieu_achat' => $texte('lieu_achat'),
            ':note' => $texte('note'),
        ];
    }
}
