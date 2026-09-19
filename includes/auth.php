<?php
/**
 * Système d'authentification sécurisé pour la bibliothèque
 */

// Tant que l'application n'est pas installée (config.php absent), on renvoie
// vers l'assistant d'installation.
$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    header('Location: install/');
    exit();
}

// Le hash du mot de passe administrateur et le sel de session sont générés
// par l'assistant d'installation et stockés dans config.php.
$authConfig = require $configFile;
define('ADMIN_PASSWORD_HASH', $authConfig['admin_password_hash'] ?? '');
define('SESSION_SALT', $authConfig['session_salt'] ?? 'bibliotheque_secret_salt');
unset($authConfig, $configFile);

define('SESSION_TIMEOUT', 3600); // 1 heure en secondes

/**
 * Vérifier si l'utilisateur est connecté
 */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && 
           $_SESSION['logged_in'] === true && 
           isset($_SESSION['login_time']) && 
           (time() - $_SESSION['login_time']) < SESSION_TIMEOUT &&
           isset($_SESSION['session_token']) &&
           $_SESSION['session_token'] === getExpectedSessionToken();
}

/**
 * Générer un token de session unique
 */
function getExpectedSessionToken() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return hash('sha256', $userAgent . $ip . SESSION_SALT);
}

/**
 * Connecter l'utilisateur avec vérification sécurisée
 */
function login($password) {
    // Vérifier si le hash est configuré
    if (ADMIN_PASSWORD_HASH === '') {
        throw new Exception("Le mot de passe administrateur n'est pas configuré. Relancez l'installation (supprimez config.php).");
    }
    
    // Vérification sécurisée du mot de passe
    if (password_verify($password, ADMIN_PASSWORD_HASH)) {
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['session_token'] = getExpectedSessionToken();
        $_SESSION['login_timestamp'] = time();
        
        // Régénérer l'ID de session pour éviter la fixation de session
        session_regenerate_id(true);
        
        return true;
    }
    
    // Ajouter un délai pour ralentir les attaques par force brute
    usleep(500000); // 0.5 seconde de délai
    
    return false;
}

/**
 * Déconnecter l'utilisateur
 */
function logout() {
    // Détruire toutes les données de session
    $_SESSION = array();
    
    // Supprimer le cookie de session
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
    header('Location: login.php');
    exit();
}

/**
 * Protéger une page (à appeler en début de chaque page)
 */
function requireAuth() {
    if (!isLoggedIn()) {
        // Sauvegarder l'URL demandée pour redirection après login
        if (!isset($_SESSION['redirect_after_login'])) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        }
        header('Location: login.php');
        exit();
    }
    
    // Vérifier que l'IP n'a pas changé (sécurité basique)
    if (isset($_SESSION['user_ip']) && $_SESSION['user_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        logSecurityEvent('IP_CHANGE', 'Session IP changed');
        logout();
    }
    
    // Renouveler la session
    $_SESSION['login_time'] = time();
}

/**
 * Générer un token CSRF pour les formulaires
 */
function getAuthCSRFToken() {
    if (!isset($_SESSION['auth_csrf_token'])) {
        $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['auth_csrf_token'];
}

/**
 * Vérifier le token CSRF
 */
function validateAuthCSRFToken($token) {
    return isset($_SESSION['auth_csrf_token']) && 
           hash_equals($_SESSION['auth_csrf_token'], $token);
}

/**
 * Obtenir le temps restant de session
 */
function getSessionTimeLeft() {
    if (!isLoggedIn()) return 0;
    return SESSION_TIMEOUT - (time() - $_SESSION['login_time']);
}

/**
 * Logger les événements de sécurité
 */
function logSecurityEvent($type, $message) {
    $logDir = 'logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/security.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $logEntry = "[$timestamp] [$type] IP:$ip - $message - UserAgent:$userAgent" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Vérifier la force du mot de passe
 */
function checkPasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Au moins 8 caractères";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Au moins une majuscule";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Au moins une minuscule";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Au moins un chiffre";
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Au moins un caractère spécial";
    }
    
    return $errors;
}
?>