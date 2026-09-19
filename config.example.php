<?php
/**
 * Exemple de configuration.
 *
 * Ce fichier est normalement généré automatiquement par l'assistant
 * d'installation (/install/). Pour une installation manuelle, copiez-le
 * sous le nom config.php et renseignez les valeurs ci-dessous.
 */

return [
    'host' => 'localhost',            // Adresse du serveur MySQL / MariaDB
    'dbname' => 'ma_bibliotheque',    // Nom de la base de données (doit exister)
    'username' => 'utilisateur',      // Identifiant MySQL
    'password' => 'mot_de_passe',     // Mot de passe MySQL
    'charset' => 'utf8',              // Encodage des caractères

    // Clé API Google Books (optionnelle mais recommandée).
    // Sans clé, la recherche ISBN partage un quota gratuit très limité avec
    // tous les autres utilisateurs anonymes de l'API.
    // 1. https://console.cloud.google.com/ -> créer un projet
    // 2. Activer l'API "Books API"
    // 3. Identifiants -> Créer des identifiants -> Clé API
    'google_books_api_key' => '',

    // Hash bcrypt du mot de passe de connexion à l'application.
    // Pour en générer un : php -r "echo password_hash('VotreMotDePasse', PASSWORD_BCRYPT, ['cost' => 12]);"
    'admin_password_hash' => '',

    // Chaîne aléatoire propre à votre installation (sel des jetons de session).
    // Pour en générer une : php -r "echo bin2hex(random_bytes(32));"
    'session_salt' => '',
];
