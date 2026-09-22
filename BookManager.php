<?php
/**
 * Gestionnaire de Collection de Livres
 * Classe principale pour gérer les livres via ISBN
 */
require_once __DIR__ . '/src/IsbnLookup.php';
require_once __DIR__ . '/src/Stats.php';
require_once __DIR__ . '/src/ListeRepository.php';
require_once __DIR__ . '/src/AuteurRepository.php';
require_once __DIR__ . '/src/TagRepository.php';
require_once __DIR__ . '/src/SupportRepository.php';
require_once __DIR__ . '/src/FormatNumeriqueRepository.php';
require_once __DIR__ . '/src/LivreRepository.php';
require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Maintenance.php';

class BookManager {
    private $pdo;
    private $isbnLookup;
    private $stats;
    private $listes;
    private $auteurs;
    private $tags;
    private $supports;
    private $formats;
    private $livres;
    private $schema;
    private $maintenance;

    public function __construct($host, $dbname, $username, $password, $googleBooksApiKey = null) {
        try {
            $this->pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Erreur de connexion : " . $e->getMessage());
        }
        $this->isbnLookup = new IsbnLookup($googleBooksApiKey);
        $this->listes = new ListeRepository($this->pdo);
        $this->auteurs = new AuteurRepository($this->pdo);
        $this->tags = new TagRepository($this->pdo);
        $this->supports = new SupportRepository($this->pdo);
        $this->formats = new FormatNumeriqueRepository($this->pdo);
        $this->livres = new LivreRepository($this->pdo, $this->tags, $this->supports, $this->formats);
        $this->stats = new Stats($this->pdo, [$this->livres, 'countBooks']);
        $this->schema = new Schema($this->pdo, $this->supports, $this->listes, $this->auteurs, $this->tags, $this->formats);
        $this->maintenance = new Maintenance($this->pdo);
    }
    
    /**
     * Schéma de la base (voir Schema)
     */
    public function createTable() {
        return $this->schema->createTable();
    }

    public function createIndexes() {
        return $this->schema->createIndexes();
    }

    public function ensureSchema() {
        return $this->schema->ensureSchema();
    }
    
    /**
     * Listes de lecture (voir ListeRepository)
     */
    public function createListTables() {
        return $this->listes->createListTables();
    }

    public function createList($nom, $description = '', $couverture = null) {
        return $this->listes->createList($nom, $description, $couverture);
    }

    public function updateList($id, $nom, $description = '', $couverture = null) {
        return $this->listes->updateList($id, $nom, $description, $couverture);
    }

    public function deleteList($id) {
        return $this->listes->deleteList($id);
    }

    public function getAllLists() {
        return $this->listes->getAllLists();
    }

    public function getListById($id) {
        return $this->listes->getListById($id);
    }

    public function getBooksInList($listeId) {
        return $this->listes->getBooksInList($listeId);
    }

    public function addBookToList($listeId, $livreId) {
        return $this->listes->addBookToList($listeId, $livreId);
    }

    public function updateBookOrder($listeId, $orderedBookIds) {
        return $this->listes->updateBookOrder($listeId, $orderedBookIds);
    }

    public function removeBookFromList($listeId, $livreId) {
        return $this->listes->removeBookFromList($listeId, $livreId);
    }

    public function moveBookUp($listeId, $livreId) {
        return $this->listes->moveBookUp($listeId, $livreId);
    }

    public function moveBookDown($listeId, $livreId) {
        return $this->listes->moveBookDown($listeId, $livreId);
    }

    public function reorderBooks($listeId, $orderedBookIds) {
        return $this->listes->reorderBooks($listeId, $orderedBookIds);
    }

    public function getListsForBook($livreId) {
        return $this->listes->getListsForBook($livreId);
    }
    
    /**
     * Récupérer les infos d'un livre via les API externes (voir IsbnLookup)
     */
    public function getBookInfoFromISBN($isbn) {
        return $this->isbnLookup->getBookInfoFromISBN($isbn);
    }
    
    /**
     * Livres (voir LivreRepository)
     */
    public function addBook($bookInfo) {
        return $this->livres->addBook($bookInfo);
    }

    public function getAllBooks($support = null, $search = null, $tag = null, $statut = null, $page = 1, $limit = null, $serie = null, $type = null) {
        return $this->livres->getAllBooks($support, $search, $tag, $statut, $page, $limit, $serie, $type);
    }

    public function countBooks($support = null, $search = null, $tag = null, $statut = null, $serie = null, $type = null) {
        return $this->livres->countBooks($support, $search, $tag, $statut, $serie, $type);
    }

    public function getPaginationInfo($totalItems, $currentPage, $itemsPerPage = null) {
        return $this->livres->getPaginationInfo($totalItems, $currentPage, $itemsPerPage);
    }

    public function updateBookNotes($id, $note_personnelle, $tags, $statut, $support = null, $description = null, $couverture_perso = null, $supprimer_couverture = false, $titre = null, $auteur = null, $serie = null, $tome = null, $type_livre = null, $format_numerique = null, $fichier_numerique_upload = null, $supprimer_fichier_numerique = false) {
        return $this->livres->updateBookNotes($id, $note_personnelle, $tags, $statut, $support, $description, $couverture_perso, $supprimer_couverture, $titre, $auteur, $serie, $tome, $type_livre, $format_numerique, $fichier_numerique_upload, $supprimer_fichier_numerique);
    }

    public function uploadFichierNumerique($fichier, $format_numerique) {
        return $this->livres->uploadFichierNumerique($fichier, $format_numerique);
    }

    public function getBookById($id) {
        return $this->livres->getBookById($id);
    }
    
    /**
     * Tags (voir TagRepository)
     */
    public function getAllTags() {
        return $this->tags->getAllTags();
    }

    public function createTagsCatalogTable() {
        return $this->tags->createTagsCatalogTable();
    }

    public function addTagToCatalog($nom) {
        return $this->tags->addTagToCatalog($nom);
    }
    
    public function deleteBook($id) {
        return $this->livres->deleteBook($id);
    }
    
    /**
     * Obtenir les statistiques de la collection (voir Stats)
     */
    public function getStats() {
        return $this->stats->getStats();
    }
    
    public function getRecentBooks($limit = 5) {
        return $this->livres->getRecentBooks($limit);
    }

    public function setItemsPerPage($items) {
        return $this->livres->setItemsPerPage($items);
    }

    public function getItemsPerPage() {
        return $this->livres->getItemsPerPage();
    }
    
    /**
     * Statistiques (voir Stats)
     */
    public function getDetailedStats() {
        return $this->stats->getDetailedStats();
    }

    public function getMonthlyAdditionStats() {
        return $this->stats->getMonthlyAdditionStats();
    }

    public function getTopTags($limit = 10) {
        return $this->stats->getTopTags($limit);
    }

    public function getAuthorStats($limit = 10) {
        return $this->stats->getAuthorStats($limit);
    }

    public function getRecentActivity($limit = 10) {
        return $this->stats->getRecentActivity($limit);
    }

    public function getFormatNumeriqueStats() {
        return $this->stats->getFormatNumeriqueStats();
    }

    /**
     * Auteurs (voir AuteurRepository)
     */
    public function createAuthorTable() {
        return $this->auteurs->createAuthorTable();
    }

    public function getAllAuthorsWithBooks($search = null, $support = null, $tag = null, $sort = 'alpha', $missingBioOnly = false) {
        return $this->auteurs->getAllAuthorsWithBooks($search, $support, $tag, $sort, $missingBioOnly);
    }

    public function exportAuthorsCSV($authors) {
        return $this->auteurs->exportAuthorsCSV($authors);
    }

    public function setAuthorBiography($nom, $biographie, $imageUrl = null, $photoFile = null, $naissance = null, $deces = null, $nationalite = null) {
        return $this->auteurs->setAuthorBiography($nom, $biographie, $imageUrl, $photoFile, $naissance, $deces, $nationalite);
    }

    public function getAuthorBiography($nom) {
        return $this->auteurs->getAuthorBiography($nom);
    }

    public function findDuplicateAuthorGroups() {
        return $this->auteurs->findDuplicateAuthorGroups();
    }

    public function createIgnoredDuplicatesTable() {
        return $this->auteurs->createIgnoredDuplicatesTable();
    }

    public function ignoreDuplicateGroup($cle) {
        return $this->auteurs->ignoreDuplicateGroup($cle);
    }

    public function authorExists($nom) {
        return $this->auteurs->authorExists($nom);
    }

    public function refreshAuthorBiography($nom) {
        return $this->auteurs->refreshAuthorBiography($nom);
    }

    public function mergeAuthors($sourceNames, $targetName) {
        return $this->auteurs->mergeAuthors($sourceNames, $targetName);
    }


    public function getAllSeries() {
        return $this->livres->getAllSeries();
    }

    /**
     * Maintenance (voir Maintenance)
     */
    public function exportDataBackup() {
        return $this->maintenance->exportDataBackup();
    }

    public function restoreDataBackup($jsonContent) {
        return $this->maintenance->restoreDataBackup($jsonContent);
    }

    public function optimizeTables() {
        return $this->maintenance->optimizeTables();
    }

    public function getDatabaseInfo() {
        return $this->maintenance->getDatabaseInfo();
    }

    public function getUploadsStorageInfo() {
        return $this->maintenance->getUploadsStorageInfo();
    }

    public function deleteOrphanUploads() {
        return $this->maintenance->deleteOrphanUploads();
    }


    public function searchBooksAdvanced(array $criteres, $page = 1, $limit = null) {
        return $this->livres->searchBooksAdvanced($criteres, $page, $limit);
    }

    public function countBooksAdvanced(array $criteres) {
        return $this->livres->countBooksAdvanced($criteres);
    }

    public function bulkUpdateBooks(array $bookIds, $newSupport, $newStatut, $tagMode, $tagsCsv) {
        return $this->livres->bulkUpdateBooks($bookIds, $newSupport, $newStatut, $tagMode, $tagsCsv);
    }

    public function countBooksByTag($tag) {
        return $this->tags->countBooksByTag($tag);
    }

    public function renameTag($oldTag, $newTag) {
        return $this->tags->renameTag($oldTag, $newTag);
    }

    public function deleteTagEverywhere($tag) {
        return $this->tags->deleteTagEverywhere($tag);
    }

    /**
     * Types de support (voir SupportRepository)
     */
    public function getSupportTypesWithCounts() {
        return $this->supports->getSupportTypesWithCounts();
    }

    public function addSupportType($nom) {
        return $this->supports->addSupportType($nom);
    }

    public function renameSupportType($oldNom, $newNom) {
        return $this->supports->renameSupportType($oldNom, $newNom);
    }

    /**
     * Formats numériques (voir FormatNumeriqueRepository)
     */
    public function getFormatsNumeriquesWithCounts() {
        return $this->formats->getFormatsWithCounts();
    }

    public function getAllExtensionsNumeriques() {
        return $this->formats->getAllExtensions();
    }

    public function addFormatNumerique($nom, $extension) {
        return $this->formats->addFormat($nom, $extension);
    }

    public function renameFormatNumerique($oldNom, $newNom) {
        return $this->formats->renameFormat($oldNom, $newNom);
    }

    public function deleteFormatNumerique($nom) {
        return $this->formats->deleteFormat($nom);
    }
}
