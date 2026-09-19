<?php
/**
 * Bootstrap commun à toutes les pages : session, authentification,
 * connexion à la base de données et mise à niveau du schéma.
 */

session_start();

require_once __DIR__ . '/auth.php';
requireAuth();

require_once __DIR__ . '/../BookManager.php';
require_once __DIR__ . '/utils.php';

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
