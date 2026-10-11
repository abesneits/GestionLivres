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

define('LOGIN_MAX_ATTEMPTS', 5);    // échecs autorisés avant blocage
define('LOGIN_LOCK_SECONDS', 300);  // durée du blocage (5 minutes), et durée pendant laquelle un échec « compte »

/**
 * Démarrer la session avec un cookie durci :
 * HttpOnly (invisible du JavaScript), SameSite (pas envoyé depuis un autre site),
 * Secure si la connexion est en HTTPS, et identifiants de session non imposables par l'adresse.
 */
function startSecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $params = session_get_cookie_params();
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * N'accepter comme destination de redirection qu'une adresse du site :
 * un seul « / » au début (pas « // » ni « /\ », qui désigneraient un autre site) et aucun caractère de contrôle.
 */
function safeRedirectTarget($url, $default = 'index.php') {
    if (is_string($url) && preg_match('#^/(?![/\\\\])#', $url) && !preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return $url;
    }
    return $default;
}

/**
 * Fichier qui mémorise, CÔTÉ SERVEUR et par adresse IP, les échecs de connexion
 * (un compteur dans la session se remettrait à zéro en jetant le cookie).
 */
function getLoginAttemptsFile() {
    return __DIR__ . '/../logs/login_attempts.json';
}

/**
 * Lire/modifier de façon exclusive la table des échecs (verrou de fichier).
 * $callback reçoit le tableau [ip => ['attempts' => n, 'last' => timestamp]] par référence.
 * Les entrées plus anciennes que la durée de blocage sont oubliées.
 */
function withLoginAttempts(callable $callback) {
    $file = getLoginAttemptsFile();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        logSecurityEvent('THROTTLE_ERROR', 'Impossible d\'ouvrir le fichier des tentatives de connexion');
        $vide = [];
        return $callback($vide);
    }

    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true);
    if (!is_array($data)) {
        $data = [];
    }

    $now = time();
    foreach ($data as $ip => $entree) {
        if ($now - ($entree['last'] ?? 0) >= LOGIN_LOCK_SECONDS) {
            unset($data[$ip]);
        }
    }

    $resultat = $callback($data);

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $resultat;
}

/**
 * Échecs récents de l'adresse IP courante : ['attempts' => n, 'last' => timestamp].
 */
function getLoginThrottle() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return withLoginAttempts(function (&$data) use ($ip) {
        return $data[$ip] ?? ['attempts' => 0, 'last' => 0];
    });
}

/**
 * Enregistrer un échec de connexion pour l'adresse IP courante. Retourne le nouvel état.
 */
function recordFailedLogin() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return withLoginAttempts(function (&$data) use ($ip) {
        $data[$ip] = ['attempts' => ($data[$ip]['attempts'] ?? 0) + 1, 'last' => time()];
        return $data[$ip];
    });
}

/**
 * Effacer les échecs de l'adresse IP courante (après une connexion réussie).
 */
function clearLoginAttempts() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    withLoginAttempts(function (&$data) use ($ip) {
        unset($data[$ip]);
    });
}

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

?>