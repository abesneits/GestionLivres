<?php
/**
 * Auteurs : biographies (Wikipédia / Wikidata), photos, regroupement par auteur,
 * détection des doublons et fusion.
 * Tables : auteurs, auteurs_doublons_ignores et livres_auteurs (liaison avec les livres,
 * avec clés étrangères). livres.auteur reste un texte d'affichage « Nom1, Nom2 », régénéré
 * par setBookAuthors(), le seul point d'écriture des auteurs d'un livre.
 */
class AuteurRepository {
    // Particules qui font partie du nom de famille (« de Saint-Exupéry », « Le Guin »)
    private const PARTICULES = ['de', 'du', 'des', 'le', 'la', 'les', 'van', 'von', 'der', 'den', 'ten', 'ter', 'da', 'di', 'del', 'della', 'dos', 'das', 'do', 'el', 'al', 'bin', 'ben', 'ibn', "d'", 'd’'];

    // Rôles d'un auteur sur un livre (« Prénom Nom (Scénariste) » dans la saisie). « Auteur » est le rôle
    // par défaut et n'apparaît pas dans le texte affiché. Ajouter un rôle ici suffit : la colonne est un texte libre.
    public const ROLES = ['Auteur', 'Scénariste', 'Dessinateur', 'Illustrateur', 'Coloriste', 'Traducteur'];
    public const ROLE_DEFAUT = 'Auteur';
    // Formes féminines reconnues à la saisie (clé sans accents ni majuscules => rôle)
    private const ROLES_SYNONYMES = ['autrice' => 'Auteur', 'dessinatrice' => 'Dessinateur', 'illustratrice' => 'Illustrateur', 'traductrice' => 'Traducteur'];

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
     * Créer la table de liaison livres <-> auteurs (un livre peut avoir plusieurs auteurs, dans un ordre,
     * chacun avec un ou plusieurs rôles : une ligne par livre, auteur et rôle). À appeler après la création
     * des tables livres et auteurs. Les liens sont supprimés automatiquement avec le livre ou l'auteur.
     * Une table créée avant l'introduction des rôles reçoit la colonne (rôle « Auteur ») et la nouvelle clé.
     */
    public function createBookAuthorsTable() {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS livres_auteurs (
            livre_id INT NOT NULL,
            auteur_id INT NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'Auteur',
            ordre SMALLINT NOT NULL DEFAULT 0,
            PRIMARY KEY (livre_id, auteur_id, role),
            KEY idx_livres_auteurs_auteur (auteur_id),
            CONSTRAINT fk_livres_auteurs_livre FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE,
            CONSTRAINT fk_livres_auteurs_auteur FOREIGN KEY (auteur_id) REFERENCES auteurs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        if ($this->pdo->query("SHOW COLUMNS FROM livres_auteurs LIKE 'role'")->rowCount() === 0) {
            $this->pdo->exec("ALTER TABLE livres_auteurs ADD COLUMN role VARCHAR(40) NOT NULL DEFAULT 'Auteur' AFTER auteur_id");
        }
        // Un même auteur peut avoir plusieurs rôles sur un livre : le rôle fait partie de la clé primaire
        // (une seule instruction : la clé de remplacement garde l'index exigé par la clé étrangère livre_id)
        if ($this->pdo->query("SHOW INDEX FROM livres_auteurs WHERE Key_name = 'PRIMARY' AND Column_name = 'role'")->rowCount() === 0) {
            $this->pdo->exec("ALTER TABLE livres_auteurs DROP PRIMARY KEY, ADD PRIMARY KEY (livre_id, auteur_id, role)");
        }
    }

    /**
     * Rôles proposés à la saisie.
     */
    public function getAuthorRoles() {
        return self::ROLES;
    }

    /**
     * Rôle reconnu (forme canonique) pour un mot saisi, ou null : insensible à la casse et aux accents,
     * avec les formes féminines courantes (« dessinatrice »).
     */
    private function canonicalRole($mot) {
        $cle = $this->normalizeAuthorName($mot);
        foreach (self::ROLES as $role) {
            if ($this->normalizeAuthorName($role) === $cle) {
                return $role;
            }
        }
        return self::ROLES_SYNONYMES[$cle] ?? null;
    }

    /**
     * Expression SQL du texte d'affichage des auteurs d'un livre (dans l'ordre), à utiliser comme
     * colonne : « SELECT l.*, <expression> AS auteur FROM livres l ». Format : « Nom1, Nom2 (Dessinateur) » ;
     * le rôle par défaut n'est pas écrit, un auteur qui cumule deux rôles apparaît une fois par rôle.
     * $alias est l'alias de la table livres dans la requête. Chaîne vide si le livre n'a pas d'auteur.
     * Ce texte est celui que la saisie accepte en retour (setBookAuthors).
     */
    public static function sqlTexteAuteurs($alias = 'l') {
        $defaut = self::ROLE_DEFAUT;
        return "COALESCE((SELECT GROUP_CONCAT(CONCAT(a.nom, IF(la.role = '$defaut', '', CONCAT(' (', la.role, ')')))
                                              ORDER BY la.ordre, la.role SEPARATOR ', ')
                          FROM livres_auteurs la JOIN auteurs a ON a.id = la.auteur_id
                          WHERE la.livre_id = $alias.id), '')";
    }
    /**
     * Condition SQL « un des auteurs du livre contient le motif $parametre » (ex. ':search'),
     * pour les recherches textuelles ; ignore casse et accents comme le reste de la base.
     */
    public static function sqlRechercheAuteur($parametre, $alias = 'l') {
        return "EXISTS (SELECT 1 FROM livres_auteurs la JOIN auteurs a ON a.id = la.auteur_id
                        WHERE la.livre_id = $alias.id AND a.nom LIKE $parametre)";
    }

    /**
     * Migration depuis l'ancien stockage : relie les livres qui n'ont encore aucun lien à leurs
     * auteurs d'après le texte de la colonne livres.auteur (noms séparés par des virgules).
     * Ne sert que tant que cette colonne existe (voir Schema::migrerColonneAuteur()). Idempotent.
     * Retourne false en cas d'erreur (rien n'est alors modifié).
     */
    public function backfillBookAuthors() {
        try {
            $livres = $this->pdo->query(
                "SELECT l.id, l.auteur FROM livres l
                 WHERE l.auteur IS NOT NULL AND l.auteur != ''
                   AND NOT EXISTS (SELECT 1 FROM livres_auteurs la WHERE la.livre_id = l.id)"
            )->fetchAll(PDO::FETCH_KEY_PAIR);

            $this->linkBooksFromTexts($livres);
            return true;
        } catch (Exception $e) {
            error_log("Reprise des auteurs des livres impossible : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Relie des livres à leurs auteurs d'après des textes « Nom1, Nom2 » (id du livre => texte).
     * Sert à la migration et à la restauration d'une sauvegarde qui n'a pas de liens.
     * Tout ou rien (rejoint la transaction en cours, sinon en ouvre une).
     */
    public function linkBooksFromTexts(array $textesParLivre) {
        if (!$textesParLivre) {
            return;
        }

        $propreTransaction = !$this->pdo->inTransaction();
        if ($propreTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($textesParLivre as $livreId => $texte) {
                $this->linkBookToAuthors($livreId, $this->parseAuthorNames($texte));
            }
            if ($propreTransaction) {
                $this->pdo->commit();
            }
        } catch (Exception $e) {
            if ($propreTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Nombre de livres dont le texte livres.auteur contient des noms mais qui n'ont aucun lien
     * (0 = la table de liaison est complète : la colonne peut être supprimée sans perte).
     */
    public function countBooksWithUnlinkedAuthorText() {
        $livres = $this->pdo->query(
            "SELECT l.id, l.auteur FROM livres l
             WHERE l.auteur IS NOT NULL AND l.auteur != ''
               AND NOT EXISTS (SELECT 1 FROM livres_auteurs la WHERE la.livre_id = l.id)"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $nb = 0;
        foreach ($livres as $texte) {
            if ($this->parseAuthorNames($texte)) {
                $nb++;
            }
        }
        return $nb;
    }

    /**
     * Point d'entrée UNIQUE pour définir les auteurs d'un livre : tout ajout ou modification
     * d'un livre passe par ici. Remplace les liens du livre par ceux du texte « Nom1, Nom2 »
     * (dans l'ordre saisi) ; les auteurs inconnus sont créés, les connus réutilisés quelle que
     * soit la casse ou les accents. Un texte sans nom exploitable (vide, « Auteur non
     * disponible ») laisse le livre sans auteur.
     * S'intègre à la transaction en cours, sinon en ouvre une.
     */
    public function setBookAuthors($livreId, $texte) {
        $propreTransaction = !$this->pdo->inTransaction();
        if ($propreTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->linkBookToAuthors($livreId, $this->parseAuthorNames($texte));

            if ($propreTransaction) {
                $this->pdo->commit();
            }
        } catch (Exception $e) {
            if ($propreTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Découpe un texte « Nom1, Nom2 (Rôle) » en auteurs avec leurs rôles. Une virgule sépare deux auteurs,
     * sauf entre parenthèses : « Nom (Scénariste, Dessinateur) » ou « Nom (Scénariste/Dessinateur) » donne deux
     * rôles au même auteur. Des parenthèses qui ne contiennent pas que des rôles connus font partie du nom
     * (« Claire Marc (médiatrice) »). Sans rôle, c'est « Auteur ». Les morceaux vides et la valeur de
     * remplacement « Auteur non disponible » sont écartés.
     * Retourne une liste de ['nom' => ..., 'roles' => [...]].
     */
    private function parseAuthorNames($texte) {
        $auteurs = [];
        foreach ($this->splitOutsideParentheses((string)$texte) as $morceau) {
            $morceau = trim($morceau);
            if (preg_match('/^\([^()]*\)$/u', $morceau)) {
                continue; // « (Scénariste) » seul : pas de nom
            }
            $nom = $morceau;
            $roles = [];

            if (preg_match('/^(.*\S)\s*\(([^()]*)\)$/u', $morceau, $m)) {
                $candidats = array_values(array_filter(array_map('trim', preg_split('/[,\/;]/u', $m[2])), 'strlen'));
                $reconnus = array_map([$this, 'canonicalRole'], $candidats);
                if ($candidats && !in_array(null, $reconnus, true)) {
                    $nom = trim($m[1]);
                    $roles = array_values(array_unique($reconnus));
                }
            }

            if ($nom === '' || mb_strtolower($nom, 'UTF-8') === 'auteur non disponible') {
                continue;
            }
            $auteurs[] = ['nom' => $nom, 'roles' => $roles ?: [self::ROLE_DEFAUT]];
        }
        return $auteurs;
    }

    /**
     * Découpe sur les virgules qui ne sont pas entre parenthèses.
     */
    private function splitOutsideParentheses($texte) {
        $morceaux = [];
        $courant = '';
        $profondeur = 0;
        foreach (preg_split('//u', $texte, -1, PREG_SPLIT_NO_EMPTY) as $car) {
            if ($car === '(') {
                $profondeur++;
            } elseif ($car === ')' && $profondeur > 0) {
                $profondeur--;
            }
            if ($car === ',' && $profondeur === 0) {
                $morceaux[] = $courant;
                $courant = '';
            } else {
                $courant .= $car;
            }
        }
        $morceaux[] = $courant;
        return $morceaux;
    }

    /**
     * Remplace les liens d'un livre par ceux de la liste d'auteurs (les auteurs inconnus sont créés).
     * Un auteur cité plusieurs fois (même nom à la casse ou aux accents près : la comparaison de la
     * base les confond) n'est lié qu'une fois, avec l'ensemble de ses rôles.
     * Retourne les noms enregistrés, dans l'ordre.
     */
    private function linkBookToAuthors($livreId, array $auteurs) {
        $this->pdo->prepare("DELETE FROM livres_auteurs WHERE livre_id = :id")->execute([':id' => $livreId]);

        $cherche = $this->pdo->prepare("SELECT id, nom FROM auteurs WHERE nom = :nom");
        $cree = $this->pdo->prepare("INSERT INTO auteurs (nom) VALUES (:nom)");
        $lie = $this->pdo->prepare("INSERT INTO livres_auteurs (livre_id, auteur_id, role, ordre) VALUES (:livre, :auteur, :role, :ordre)");

        $ordre = [];    // id de l'auteur => position
        $noms = [];     // id de l'auteur => nom enregistré
        $roles = [];    // id de l'auteur => rôles
        foreach ($auteurs as $entree) {
            $cherche->execute([':nom' => $entree['nom']]);
            $auteur = $cherche->fetch(PDO::FETCH_ASSOC);
            if (!$auteur) {
                $cree->execute([':nom' => $entree['nom']]);
                $auteur = ['id' => $this->pdo->lastInsertId(), 'nom' => $entree['nom']];
            }
            $id = (string)$auteur['id'];
            if (!isset($ordre[$id])) {
                $ordre[$id] = count($ordre);
                $noms[$id] = $auteur['nom'];
                $roles[$id] = [];
            }
            $roles[$id] = array_values(array_unique(array_merge($roles[$id], $entree['roles'])));
        }

        foreach ($ordre as $id => $position) {
            foreach ($roles[$id] as $role) {
                $lie->execute([':livre' => $livreId, ':auteur' => $id, ':role' => $role, ':ordre' => $position]);
            }
        }

        return array_values($noms);
    }
    /**
     * Regrouper tous les livres de la collection par auteur (via la table de liaison livres_auteurs)
     */
    public function getAllAuthorsWithBooks($search = null, $support = null, $tag = null, $sort = 'alpha', $missingBioOnly = false, $role = null) {
        $sql = "SELECT l.id, l.titre, l.couverture, l.statut, l.support, l.tags, l.date_ajout, la.role AS auteur_role,
                       a.id AS auteur_id, a.nom AS auteur_nom, a.image_url AS auteur_image,
                       a.prenom AS auteur_prenom, a.nom_famille AS auteur_nom_famille,
                       (a.biographie IS NOT NULL AND a.biographie != '') AS auteur_a_bio
                FROM livres_auteurs la
                JOIN livres l ON l.id = la.livre_id
                JOIN auteurs a ON a.id = la.auteur_id
                WHERE 1=1";
        $params = [];

        if ($support) {
            $sql .= " AND l.support = :support";
            $params[':support'] = $support;
        }

        if ($role) {
            $sql .= " AND la.role = :role";
            $params[':role'] = $role;
        }

        if ($tag) {
            // Correspondance EXACTE d'un tag (« lu » ne doit pas trouver « relu »)
            $cond = $this->tags->buildTagCondition($tag, '_auteur');
            $sql .= " AND " . $cond['sql'];
            $params = array_merge($params, $cond['params']);
        }

        $sql .= " ORDER BY l.titre ASC, la.role ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $colonnesLivre = array_flip(['id', 'titre', 'couverture', 'statut', 'support', 'tags', 'date_ajout']);
        $authors = [];
        foreach ($lignes as $ligne) {
            $nom = $ligne['auteur_nom'];
            if (!isset($authors[$nom])) {
                // Prénom / nom de famille corrigés à la main ; sinon ils sont devinés
                $parties = ($ligne['auteur_nom_famille'] !== null && $ligne['auteur_nom_famille'] !== '')
                    ? ['prenom' => (string)$ligne['auteur_prenom'], 'nom_famille' => $ligne['auteur_nom_famille']]
                    : $this->devinerPrenomNom($nom);

                $authors[$nom] = [
                    'id' => (int)$ligne['auteur_id'],
                    'nom' => $nom,
                    'livres' => [],
                    'roles' => [],
                    'image_url' => $ligne['auteur_image'] !== null && $ligne['auteur_image'] !== '' ? $ligne['auteur_image'] : null,
                    'a_une_biographie' => (bool)$ligne['auteur_a_bio'],
                    'prenom' => $parties['prenom'],
                    'nom_famille' => $parties['nom_famille'],
                    // Sans prénom (« Voltaire »), le nom unique sert de repère pour le tri par prénom
                    'cle_nom' => $this->cleTriNom($parties['nom_famille']),
                    'cle_prenom' => $this->normalizeAuthorName($parties['prenom'] !== '' ? $parties['prenom'] : $parties['nom_famille']),
                ];
            }
            // Un livre n'apparaît qu'une fois par auteur, avec tous les rôles que cet auteur y a
            $idLivre = $ligne['id'];
            if (!isset($authors[$nom]['livres'][$idLivre])) {
                $authors[$nom]['livres'][$idLivre] = array_intersect_key($ligne, $colonnesLivre) + ['roles' => []];
            }
            $authors[$nom]['livres'][$idLivre]['roles'][] = $ligne['auteur_role'];
            if (!in_array($ligne['auteur_role'], $authors[$nom]['roles'], true)) {
                $authors[$nom]['roles'][] = $ligne['auteur_role'];
            }
        }
        foreach ($authors as &$auteurRegroupe) {
            $auteurRegroupe['livres'] = array_values($auteurRegroupe['livres']);
        }
        unset($auteurRegroupe);

        if ($search) {
            $search = mb_strtolower($search, 'UTF-8');
            $authors = array_filter($authors, function($a) use ($search) {
                return mb_strpos(mb_strtolower($a['nom'], 'UTF-8'), $search) !== false;
            });
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

        fputcsv($output, ['Nom', 'Prénom', 'Nom de famille', 'Nombre de livres', 'Rôles', 'Naissance', 'Décès', 'Nationalité', 'Biographie', 'Titres possédés'], ';');

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
                implode(', ', $auteur['roles'] ?? []),
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
     * Indique si un auteur de ce nom existe dans la collection (au moins un livre lui est rattaché).
     * La comparaison ignore la casse et les accents, comme l'unicité des noms en base.
     */
    public function authorExists($nom) {
        $nom = trim($nom);
        if ($nom === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM auteurs a JOIN livres_auteurs la ON la.auteur_id = a.id WHERE a.nom = :nom LIMIT 1"
        );
        $stmt->execute([':nom' => $nom]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Fiches d'auteurs sans aucun livre (reliquats de livres supprimés ou d'auteurs renommés) : elles ne
     * s'affichent nulle part. Chaque fiche : id, nom, a_bio (biographie présente), photo_locale (photo envoyée).
     */
    public function getOrphanAuthors() {
        $lignes = $this->pdo->query(
            "SELECT a.id, a.nom, a.image_url,
                    (a.biographie IS NOT NULL AND a.biographie != '') AS a_bio
             FROM auteurs a
             WHERE NOT EXISTS (SELECT 1 FROM livres_auteurs la WHERE la.auteur_id = a.id)
             ORDER BY a.nom ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($ligne) {
            return [
                'id' => (int)$ligne['id'],
                'nom' => $ligne['nom'],
                'a_bio' => (bool)$ligne['a_bio'],
                'photo_locale' => strpos((string)$ligne['image_url'], 'uploads/auteurs/') === 0,
            ];
        }, $lignes);
    }

    /**
     * Supprime les fiches d'auteurs sans livre, ainsi que leur photo envoyée (uploads/auteurs/).
     * Chaque suppression revérifie qu'aucun livre n'est rattaché à la fiche. Retourne le nombre de fiches supprimées.
     */
    public function deleteOrphanAuthors() {
        $supprimees = 0;
        $photos = [];

        $this->pdo->beginTransaction();
        try {
            $lignes = $this->pdo->query(
                "SELECT a.id, a.image_url FROM auteurs a
                 WHERE NOT EXISTS (SELECT 1 FROM livres_auteurs la WHERE la.auteur_id = a.id)"
            )->fetchAll(PDO::FETCH_ASSOC);

            $supprime = $this->pdo->prepare(
                "DELETE FROM auteurs WHERE id = :id AND NOT EXISTS (SELECT 1 FROM livres_auteurs la WHERE la.auteur_id = :id2)"
            );
            foreach ($lignes as $ligne) {
                $supprime->execute([':id' => $ligne['id'], ':id2' => $ligne['id']]);
                if ($supprime->rowCount() > 0) {
                    $supprimees++;
                    $photos[] = (string)$ligne['image_url'];
                }
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Photos envoyées par l'utilisateur uniquement (jamais une URL distante), une fois la suppression validée
        foreach ($photos as $chemin) {
            if (strpos($chemin, 'uploads/auteurs/') === 0 && strpos($chemin, '..') === false && is_file($chemin)) {
                $encoreUtilisee = $this->pdo->prepare("SELECT COUNT(*) FROM auteurs WHERE image_url = :chemin");
                $encoreUtilisee->execute([':chemin' => $chemin]);
                if ((int)$encoreUtilisee->fetchColumn() === 0) {
                    @unlink($chemin);
                }
            }
        }

        return $supprimees;
    }

    /**
     * Livres d'un auteur avec les rôles qu'il y a (tous, sans filtre) : [['id', 'titre', 'roles' => [...]], ...],
     * triés par titre. Sert à la fenêtre « Éditer l'auteur ».
     */
    public function getAuthorBookRoles($nom) {
        $stmt = $this->pdo->prepare(
            "SELECT l.id, l.titre, la.role
             FROM livres_auteurs la
             JOIN livres l ON l.id = la.livre_id
             JOIN auteurs a ON a.id = la.auteur_id
             WHERE a.nom = :nom
             ORDER BY l.titre ASC, la.role ASC"
        );
        $stmt->execute([':nom' => trim($nom)]);

        $livres = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $id = (int)$ligne['id'];
            if (!isset($livres[$id])) {
                $livres[$id] = ['id' => $id, 'titre' => $ligne['titre'], 'roles' => []];
            }
            $livres[$id]['roles'][] = $ligne['role'];
        }
        return array_values($livres);
    }

    /**
     * Définit les rôles d'un auteur sur ses livres : $rolesParLivre = [id du livre => [rôles...]].
     * Seuls les livres de cet auteur cités dans le tableau sont modifiés ; les rôles inconnus sont ignorés,
     * et un livre sans aucun rôle valide garde le rôle par défaut (cette méthode ne retire jamais un livre
     * à un auteur). Retourne le nombre de livres dont les rôles ont changé.
     */
    public function setAuthorRoles($nom, array $rolesParLivre) {
        $stmt = $this->pdo->prepare("SELECT id FROM auteurs WHERE nom = :nom");
        $stmt->execute([':nom' => trim($nom)]);
        $idAuteur = $stmt->fetchColumn();
        if ($idAuteur === false) {
            throw new Exception("Auteur introuvable.");
        }

        $actuels = [];
        $stmt = $this->pdo->prepare("SELECT livre_id, role, ordre FROM livres_auteurs WHERE auteur_id = :id ORDER BY livre_id, role");
        $stmt->execute([':id' => $idAuteur]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $livre = (int)$ligne['livre_id'];
            $actuels[$livre]['roles'][] = $ligne['role'];
            $actuels[$livre]['ordre'] = isset($actuels[$livre]['ordre']) ? min($actuels[$livre]['ordre'], (int)$ligne['ordre']) : (int)$ligne['ordre'];
        }

        $propreTransaction = !$this->pdo->inTransaction();
        if ($propreTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $supprime = $this->pdo->prepare("DELETE FROM livres_auteurs WHERE livre_id = :livre AND auteur_id = :auteur");
            $ajoute = $this->pdo->prepare("INSERT INTO livres_auteurs (livre_id, auteur_id, role, ordre) VALUES (:livre, :auteur, :role, :ordre)");
            $modifies = 0;

            foreach ($actuels as $livre => $etat) {
                if (!array_key_exists($livre, $rolesParLivre)) {
                    continue;
                }
                $roles = [];
                foreach ((array)$rolesParLivre[$livre] as $role) {
                    $canonique = $this->canonicalRole((string)$role);
                    if ($canonique !== null && !in_array($canonique, $roles, true)) {
                        $roles[] = $canonique;
                    }
                }
                if (!$roles) {
                    $roles = [self::ROLE_DEFAUT];
                }

                $avant = $etat['roles'];
                sort($avant);
                $apres = $roles;
                sort($apres);
                if ($avant === $apres) {
                    continue;
                }

                $supprime->execute([':livre' => $livre, ':auteur' => $idAuteur]);
                foreach ($roles as $role) {
                    $ajoute->execute([':livre' => $livre, ':auteur' => $idAuteur, ':role' => $role, ':ordre' => $etat['ordre']]);
                }
                $modifies++;
            }

            if ($propreTransaction) {
                $this->pdo->commit();
            }
            return $modifies;
        } catch (Exception $e) {
            if ($propreTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Noms de tous les auteurs qui ont au moins un livre (triés), pour les suggestions de saisie.
     */
    public function getAuthorNames() {
        return $this->pdo->query(
            "SELECT a.nom FROM auteurs a
             WHERE EXISTS (SELECT 1 FROM livres_auteurs la WHERE la.auteur_id = a.id)
             ORDER BY a.nom ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Deux noms désignent-ils le même auteur en base ? (« victor hugo » et « Victor Hugo » : oui)
     */
    public function isSameAuthor($nomA, $nomB) {
        $stmt = $this->pdo->prepare(
            "SELECT (SELECT id FROM auteurs WHERE nom = :a) = (SELECT id FROM auteurs WHERE nom = :b)"
        );
        $stmt->execute([':a' => trim($nomA), ':b' => trim($nomB)]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Relance la recherche de biographie sur Wikipédia en court-circuitant le
     * cache, et met à jour la biographie mise en cache avec le résultat.
     */
    public function refreshAuthorBiography($nom) {
        return $this->fetchAndCacheAuthorMetadata($nom) + $this->getAuthorNameParts($nom);
    }

    /**
     * Renommer ou fusionner des auteurs : tous les livres des anciens noms sont rattachés
     * à l'auteur cible, qui garde sa fiche (biographie, photo, métadonnées).
     * - si l'auteur cible existe déjà, sa fiche est conservée et celles des anciens noms sont supprimées ;
     * - sinon la fiche d'un ancien nom (de préférence avec biographie) est renommée au lieu d'être perdue.
     * Retourne le nombre de livres rattachés aux anciens noms (ceux dont l'auteur change ou est renommé).
     */
    public function mergeAuthors($sourceNames, $targetName) {
        $sourceNames = array_values(array_filter(array_map('trim', (array)$sourceNames)));
        $targetName = trim($targetName);

        if (empty($sourceNames) || $targetName === '') {
            throw new Exception("Noms invalides pour la fusion.");
        }

        $cherche = $this->pdo->prepare("SELECT id, biographie FROM auteurs WHERE nom = :nom");

        // Tout ou rien : une erreur au milieu ne laisse pas des auteurs à moitié fusionnés
        $this->pdo->beginTransaction();
        try {
            $cherche->execute([':nom' => $targetName]);
            $cible = $cherche->fetch(PDO::FETCH_ASSOC);
            $idCible = $cible ? (int)$cible['id'] : null;

            // Fiches des anciens noms (la comparaison de MySQL ignore la casse : « victor hugo » = « Victor Hugo »)
            $fichesSources = [];
            foreach ($sourceNames as $nom) {
                $cherche->execute([':nom' => $nom]);
                $fiche = $cherche->fetch(PDO::FETCH_ASSOC);
                if ($fiche) {
                    $fichesSources[(int)$fiche['id']] = $fiche;
                }
            }

            // Fiche conservée : celle du nom cible, sinon la meilleure fiche d'un ancien nom
            $idGarde = $idCible;
            if ($idGarde === null) {
                foreach ($fichesSources as $id => $fiche) {
                    if ($idGarde === null || (trim((string)$fiche['biographie']) !== '' && trim((string)$fichesSources[$idGarde]['biographie']) === '')) {
                        $idGarde = $id;
                    }
                }
            }
            if ($idGarde === null) {
                $this->pdo->commit();
                return 0;
            }

            // Livres concernés (comptés avant le rattachement)
            $livresModifies = 0;
            if ($fichesSources) {
                $marqueurs = implode(',', array_fill(0, count($fichesSources), '?'));
                $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT livre_id) FROM livres_auteurs WHERE auteur_id IN ($marqueurs)");
                $stmt->execute(array_keys($fichesSources));
                $livresModifies = (int)$stmt->fetchColumn();
            }

            // Rattacher les livres des autres fiches à la fiche conservée, puis les supprimer
            // (INSERT IGNORE : un livre qui avait déjà les deux auteurs n'en garde qu'un)
            $rattache = $this->pdo->prepare(
                "INSERT IGNORE INTO livres_auteurs (livre_id, auteur_id, role, ordre)
                 SELECT livre_id, :garde, role, ordre FROM livres_auteurs WHERE auteur_id = :source"
            );
            $supprime = $this->pdo->prepare("DELETE FROM auteurs WHERE id = :id");
            foreach (array_keys($fichesSources) as $idSource) {
                if ($idSource !== $idGarde) {
                    $rattache->execute([':garde' => $idGarde, ':source' => $idSource]);
                    $supprime->execute([':id' => $idSource]);
                }
            }

            // Un auteur fusionné apparaît à sa première position dans chaque livre (ses rôles restent côte à côte)
            $stmt = $this->pdo->prepare(
                "UPDATE livres_auteurs la
                 JOIN (SELECT livre_id, MIN(ordre) AS premier FROM livres_auteurs WHERE auteur_id = :garde GROUP BY livre_id) m
                   ON m.livre_id = la.livre_id
                 SET la.ordre = m.premier
                 WHERE la.auteur_id = :garde2"
            );
            $stmt->execute([':garde' => $idGarde, ':garde2' => $idGarde]);

            // La fiche conservée prend exactement le nom cible (casse comprise)
            $stmt = $this->pdo->prepare("UPDATE auteurs SET nom = :nom WHERE id = :id");
            $stmt->execute([':nom' => $targetName, ':id' => $idGarde]);

            // Un prénom / nom de famille corrigé à la main ne vaut plus s'il ne figure plus dans le nom :
            // on revient alors à la détection automatique
            $stmt = $this->pdo->prepare("SELECT nom_famille FROM auteurs WHERE id = :id");
            $stmt->execute([':id' => $idGarde]);
            $nomFamille = (string)$stmt->fetchColumn();
            if ($nomFamille !== '' && mb_strpos($targetName, $nomFamille, 0, 'UTF-8') === false) {
                $stmt = $this->pdo->prepare("UPDATE auteurs SET prenom = NULL, nom_famille = NULL WHERE id = :id");
                $stmt->execute([':id' => $idGarde]);
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
