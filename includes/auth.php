<?php
/**
 * Système d'authentification sécurisé pour la bibliothèque
 */

// Tant que l'application n'est pas configurée (config.php absent), on renvoie
// vers l'assistant d'installation s'il est présent.
$configFile = __DIR__ . '/../config.php';
$configPresent = file_exists($configFile);
if (!$configPresent && PHP_SAPI !== 'cli') {
    if (is_dir(__DIR__ . '/../install')) {
        header('Location: install/');
    } else {
        http_response_code(500);
        echo "config.php est introuvable : copiez config.example.php en config.php et renseignez-le.";
    }
    exit();
}

// Le hash (bcrypt) du mot de passe administrateur et le sel de session ne sont pas dans le code :
// ils viennent de config.php (clés admin_password_hash et session_salt), hors dépôt Git.
// (en ligne de commande, par exemple pour tests/run.php, un config.php absent n'est pas une erreur)
$authConfig = $configPresent ? require $configFile : [];
define('ADMIN_PASSWORD_HASH', $authConfig['admin_password_hash'] ?? '');
define('SESSION_SALT', $authConfig['session_salt'] ?? '');
unset($authConfig, $configFile, $configPresent);

define('SESSION_TIMEOUT', 3600); // 1 heure en secondes

define('LOGIN_MAX_ATTEMPTS', 5);    // échecs autorisés avant blocage
define('LOGIN_LOCK_SECONDS', 300);  // durée du blocage (5 minutes), et durée pendant laquelle un échec « compte »

define('JOURNAL_TAILLE_MAX', 1048576); // 1 Mo : au-delà, le journal est archivé en « .1 » (voir ecrireJournal())

/**
 * Adresse d'une ressource statique (CSS, JS) suivie de sa date de modification : le navigateur peut
 * la garder longtemps en cache (voir .htaccess), et la recharge dès que le fichier change.
 */
function urlAsset($fichier) {
    $chemin = __DIR__ . '/../' . $fichier;
    return $fichier . (is_file($chemin) ? '?v=' . filemtime($chemin) : '');
}

/**
 * En-têtes HTTP de sécurité, envoyés depuis PHP (et non par .htaccess) car mod_headers
 * est souvent absent des hébergements mutualisés.
 *
 * La CSP garde 'unsafe-inline' pour les scripts : chaque page a son <script> en fin de fichier
 * et quelques attributs onclick. Elle interdit en revanche les scripts, formulaires, cadres et
 * connexions venant d'autres sites, ce qui limite la portée d'une éventuelle faille XSS.
 * La caméra reste autorisée pour la page du site (scan de l'ISBN).
 */
function envoyerEnTetesSecurite() {
    if (headers_sent()) {
        return;
    }
    $csp = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' https: data: blob:; "
        . "connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    header('Content-Security-Policy: ' . $csp);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

/**
 * Démarrer la session avec un cookie durci :
 * HttpOnly (invisible du JavaScript), SameSite (pas envoyé depuis un autre site),
 * Secure si la connexion est en HTTPS, et identifiants de session non imposables par l'adresse.
 */
function startSecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    envoyerEnTetesSecurite();
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
 * Générer un token de session unique.
 * Il dépend du navigateur (User-Agent) mais pas de l'adresse IP : sur smartphone, passer du
 * Wi-Fi à la 4G (par exemple pendant un scan d'ISBN en librairie) changerait l'IP et déconnecterait.
 */
function getExpectedSessionToken() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return hash('sha256', $userAgent . SESSION_SALT);
}

/**
 * Connecter l'utilisateur avec vérification sécurisée
 */
function login($password) {
    // Vérifier si le hash est configuré
    if (ADMIN_PASSWORD_HASH === '' || SESSION_SALT === '') {
        throw new Exception("Le mot de passe administrateur n'est pas configuré : renseignez admin_password_hash et session_salt dans config.php.");
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
    
    // Un changement d'IP n'est plus bloquant (réseau mobile) : on le note, puis on mémorise la nouvelle
    if (isset($_SESSION['user_ip']) && $_SESSION['user_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        logSecurityEvent('IP_CHANGE', 'Session IP changed from ' . $_SESSION['user_ip']);
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
    }

    // Renouveler la session
    $_SESSION['login_time'] = time();

    // Changer l'identifiant de session toutes les 15 minutes (une session volée a une durée de vie limitée)
    if (time() - ($_SESSION['id_regenere_le'] ?? 0) > 900 && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // false : l'ancien identifiant reste valable un instant, pour ne pas casser une requête AJAX en cours
        session_regenerate_id(false);
        $_SESSION['id_regenere_le'] = time();
    }
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
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    ecrireJournal('security.log', "[$timestamp] [$type] IP:$ip - $message - UserAgent:$userAgent");
}

/**
 * Ajouter UNE ligne à un fichier de logs/.
 * - les retours à la ligne et caractères de contrôle sont remplacés par une espace : une valeur
 *   venue du navigateur (User-Agent, titre saisi...) ne peut pas fabriquer de fausses lignes de journal ;
 * - au-delà de JOURNAL_TAILLE_MAX, le fichier devient « <nom>.1 » (l'ancien « .1 » est écrasé) :
 *   un journal ne grossit donc jamais sans limite.
 */
function ecrireJournal($nomFichier, $ligne) {
    $dossier = __DIR__ . '/../logs';
    if (!is_dir($dossier)) {
        @mkdir($dossier, 0755, true);
    }

    $fichier = $dossier . '/' . $nomFichier;
    clearstatcache(true, $fichier);
    if (is_file($fichier) && filesize($fichier) > JOURNAL_TAILLE_MAX) {
        @unlink($fichier . '.1');
        @rename($fichier, $fichier . '.1');
    }

    $ligne = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$ligne);
    @file_put_contents($fichier, $ligne . PHP_EOL, FILE_APPEND | LOCK_EX);
}

?>