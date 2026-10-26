<?php
/**
 * Bootstrap commun à toutes les pages : session, authentification,
 * connexion à la base de données et mise à niveau du schéma.
 */

// Aucune erreur PHP dans la page (chemins, requêtes...) : elles vont dans le journal d'erreurs du serveur
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
startSecureSession();
requireAuth();

require_once __DIR__ . '/../BookManager.php';
require_once __DIR__ . '/utils.php';

// Exception non rattrapée par une page : journal + page d'erreur neutre
set_exception_handler(function ($e) {
    logMessage('Exception non rattrapée : ' . get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'ERROR');
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo "Une erreur est survenue. Le détail est dans logs/app.log.";
});

$config = require __DIR__ . '/../config.php';
$bookManager = new BookManager($config['host'], $config['dbname'], $config['username'], $config['password'], $config['google_books_api_key'] ?? null);

// ensureSchema() peut afficher des messages de migration la toute première
// fois qu'une colonne/table manquante est ajoutée (voir BookManager::createTable()).
// On les absorbe systématiquement ici : plusieurs pages envoient ensuite des
// en-têtes HTTP (export CSV/JSON, réponses AJAX) qui échoueraient si le moindre
// caractère avait déjà été affiché avant.
ob_start();
$bookManager->ensureSchema();
ob_end_clean();
