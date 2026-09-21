-- ============================================================================
-- Ma Collection de Livres : schéma de la base de données (MySQL / MariaDB)
-- ============================================================================
--
-- Ce fichier décrit le modèle de données complet. Il est utile pour :
--   * lire le modèle en un coup d'œil ;
--   * créer la base à la main (phpMyAdmin, ligne de commande) au lieu de
--     passer par l'assistant d'installation :
--         mysql -u UTILISATEUR -p NOM_DE_LA_BASE < schema.sql
--
-- Il n'est PAS obligatoire : l'application crée et met à jour elle-même ces
-- tables au chargement des pages (BookManager::ensureSchema(), implémenté dans
-- src/Schema.php). Les deux doivent rester synchronisés : toute nouvelle
-- colonne se déclare dans src/Schema.php ET dans ce fichier.
--
-- Le script est rejouable sans danger (CREATE TABLE IF NOT EXISTS).
-- Les tables sont en utf8 car l'application se connecte avec charset=utf8.
-- La base elle-même doit déjà exister (CREATE DATABASE ma_bibliotheque ...).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Livres, bandes dessinées et mangas
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `livres` (
  `id` int NOT NULL AUTO_INCREMENT,
  -- ISBN, ou identifiant temporaire (TEMP_...) pour une saisie sans ISBN
  `isbn` varchar(40) NOT NULL,
  `titre` varchar(255) NOT NULL,
  -- Liste modifiable depuis la page Outils (ajout / renommage de supports)
  `support` enum('Livre','Bande dessinée','Manga') NOT NULL,
  -- Un ou plusieurs auteurs, séparés par des virgules
  `auteur` varchar(255) DEFAULT NULL,
  -- Série (ex. « Cycle de Fondation ») et numéro de tome dans cette série
  `serie` varchar(255) DEFAULT NULL,
  `tome` int DEFAULT NULL,
  -- URL distante ou chemin d'un fichier du dossier uploads/
  `couverture` text,
  `description` text,
  `note_personnelle` text,
  -- Tags séparés par des virgules ; le catalogue est dans tags_catalogue
  `tags` varchar(500) DEFAULT NULL,
  `statut` enum('À lire','En cours','Lu','Abandonné') DEFAULT 'À lire',
  `date_publication` varchar(20) DEFAULT NULL,
  `date_ajout` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `isbn` (`isbn`),
  KEY `idx_support` (`support`),
  KEY `idx_statut` (`statut`),
  KEY `idx_date_ajout` (`date_ajout`),
  -- Index sur un préfixe : évite la limite de longueur de clé des anciens MySQL
  KEY `idx_serie` (`serie`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------------------------------------------------------
-- Listes de lecture
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `listes_lecture` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) NOT NULL,
  `description` text,
  `couverture` varchar(500) DEFAULT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_date_creation` (`date_creation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Livres d'une liste (relation plusieurs-à-plusieurs, ordonnée)
CREATE TABLE IF NOT EXISTS `livres_listes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `livre_id` int NOT NULL,
  `liste_id` int NOT NULL,
  `ordre` int DEFAULT '0',
  `date_ajout` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_livre_liste` (`livre_id`,`liste_id`),
  KEY `liste_id` (`liste_id`),
  CONSTRAINT `livres_listes_ibfk_1` FOREIGN KEY (`livre_id`) REFERENCES `livres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `livres_listes_ibfk_2` FOREIGN KEY (`liste_id`) REFERENCES `listes_lecture` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------------------------------------------------------
-- Auteurs : biographies récupérées sur Wikipédia et mises en cache.
-- Pas de clé étrangère vers livres : le lien se fait par le nom, présent dans
-- la colonne livres.auteur (qui peut contenir plusieurs noms).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auteurs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) NOT NULL,
  `biographie` text,
  `image_url` varchar(500) DEFAULT NULL,
  `naissance` varchar(10) DEFAULT NULL,
  `deces` varchar(10) DEFAULT NULL,
  `nationalite` varchar(150) DEFAULT NULL,
  `date_maj` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Groupes de doublons d'auteurs écartés (faux positifs) par l'utilisateur
CREATE TABLE IF NOT EXISTS `auteurs_doublons_ignores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cle_normalisee` varchar(255) NOT NULL,
  `date_ajout` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cle_normalisee` (`cle_normalisee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ----------------------------------------------------------------------------
-- Catalogue de tags (suggestions), indépendant des livres qui les portent
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tags_catalogue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
