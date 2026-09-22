<?php
/**
 * Statistiques de la collection.
 * Le comptage des livres reste porté par BookManager : il est passé sous forme
 * de callable pour ne pas dépendre directement de cette classe.
 */
class Stats {
    private $pdo;
    private $compterLivres;

    public function __construct(PDO $pdo, callable $compterLivres) {
        $this->pdo = $pdo;
        $this->compterLivres = $compterLivres;
    }

    /**
     * Obtenir les statistiques de la collection
     */
    public function getStats() {
        $total = ($this->compterLivres)();
        $livres = ($this->compterLivres)('Livre');
        $bd = ($this->compterLivres)('Bande dessinée');
        $manga = ($this->compterLivres)('Manga');
        $repartitionType = $this->getTypeLivreCounts();

        return [
            'total' => $total,
            'livres' => $livres,
            'bd' => $bd,
            'manga' => $manga,
            'papier' => $repartitionType['papier'],
            'numerique' => $repartitionType['numerique']
        ];
    }

    /**
     * Compte les livres papier/numérique en une requête directe (comme le
     * comptage par statut dans getDetailedStats()).
     */
    private function getTypeLivreCounts() {
        $stmt = $this->pdo->query("SELECT type_livre, COUNT(*) as count FROM livres GROUP BY type_livre");
        $counts = ['papier' => 0, 'numerique' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['type_livre'] === 'Numérique') {
                $counts['numerique'] = (int)$row['count'];
            } else {
                $counts['papier'] += (int)$row['count'];
            }
        }
        return $counts;
    }

    /**
     * Répartition des livres numériques par format (PDF, Epub, ...), pour la page Stats.
     */
    public function getFormatNumeriqueStats() {
        $sql = "SELECT format_numerique, COUNT(*) as count FROM livres
                WHERE type_livre = 'Numérique' AND format_numerique IS NOT NULL AND format_numerique != ''
                GROUP BY format_numerique ORDER BY count DESC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Obtenir les statistiques détaillées
     */
    public function getDetailedStats() {
        // Stats de base
        $total = ($this->compterLivres)();
        $livres = ($this->compterLivres)('Livre');
        $bd = ($this->compterLivres)('Bande dessinée');
        $manga = ($this->compterLivres)('Manga');
        $repartitionType = $this->getTypeLivreCounts();

        // Requête SQL directe
        $sql = "SELECT statut, COUNT(*) as count FROM livres GROUP BY statut";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Initialiser les compteurs
        $lu = 0;
        $a_lire = 0;
        $en_cours = 0;
        $abandonne = 0;
        
        // Parcourir avec une logique plus flexible
        foreach ($statusCounts as $row) {
            $statut = trim($row['statut']);
            $count = (int)$row['count'];
            
            // Utiliser strpos pour une correspondance partielle
            if (strpos($statut, 'Lu') !== false) {
                $lu = $count;
            } elseif (strpos($statut, 'lire') !== false) {
                $a_lire = $count;
            } elseif (strpos($statut, 'cours') !== false) {
                $en_cours = $count;
            } elseif (strpos($statut, 'bandon') !== false) {
                $abandonne = $count;
            }
        }
        
        return [
            'total' => $total,
            'livres' => $livres,
            'bd' => $bd,
            'manga' => $manga,
            'papier' => $repartitionType['papier'],
            'numerique' => $repartitionType['numerique'],
            'lu' => $lu,
            'a_lire' => $a_lire,
            'en_cours' => $en_cours,
            'abandonne' => $abandonne
        ];
    }
    
    /**
     * Obtenir les statistiques d'ajouts mensuels (12 derniers mois)
     */
    public function getMonthlyAdditionStats() {
        $sql = "
            SELECT 
                DATE_FORMAT(date_ajout, '%Y-%m') as month,
                DATE_FORMAT(date_ajout, '%M %Y') as month_name,
                COUNT(*) as count
            FROM livres 
            WHERE date_ajout >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(date_ajout, '%Y-%m'), DATE_FORMAT(date_ajout, '%M %Y')
            ORDER BY month DESC
            LIMIT 12
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_reverse($results);
    }
    
    /**
     * Obtenir les top tags les plus utilisés
     */
    public function getTopTags($limit = 10) {
        $sql = "SELECT tags FROM livres WHERE tags IS NOT NULL AND tags != ''";
        $stmt = $this->pdo->query($sql);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $tagCounts = [];
        
        foreach ($results as $tagString) {
            $tags = explode(',', $tagString);
            foreach ($tags as $tag) {
                $tag = trim($tag);
                if ($tag) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }
        }
        
        arsort($tagCounts);
        
        $topTags = [];
        $count = 0;
        foreach ($tagCounts as $tag => $occurrences) {
            if ($count >= $limit) break;
            $topTags[] = [
                'tag' => $tag,
                'count' => $occurrences
            ];
            $count++;
        }
        
        return $topTags;
    }
    
    /**
     * Obtenir les statistiques par auteur
     */
    public function getAuthorStats($limit = 10) {
        $sql = "
            SELECT 
                auteur,
                COUNT(*) as count
            FROM livres 
            WHERE auteur IS NOT NULL AND auteur != ''
            GROUP BY auteur 
            ORDER BY count DESC, auteur ASC 
            LIMIT :limit
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtenir l'activité récente (ajouts et modifications)
     */
    public function getRecentActivity($limit = 10) {
        $sql = "
            SELECT 
                id,
                titre,
                auteur,
                date_ajout as date,
                'added' as action
            FROM livres 
            ORDER BY date_ajout DESC 
            LIMIT :limit
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
