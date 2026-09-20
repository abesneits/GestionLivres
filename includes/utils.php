<?php
/**
 * Fonctions utilitaires partagées
 */

/**
 * Échapper et afficher du texte HTML de manière sécurisée
 */
function h($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Générer l'URL avec les paramètres actuels plus les nouveaux
 */
function buildUrl($newParams = [], $removeParams = []) {
    $currentParams = $_GET;
    
    // Supprimer les paramètres à enlever
    foreach ($removeParams as $param) {
        unset($currentParams[$param]);
    }
    
    // Ajouter/modifier les nouveaux paramètres
    $currentParams = array_merge($currentParams, $newParams);
    
    // Nettoyer les paramètres vides
    $currentParams = array_filter($currentParams, function($value) {
        return $value !== '' && $value !== null;
    });
    
    return '?' . http_build_query($currentParams);
}

/**
 * Afficher un message flash
 */
function showMessage($message, $type = 'info') {
    if (empty($message)) return '';
    
    $classes = [
        'success' => 'message success',
        'error' => 'message error',
        'warning' => 'message warning',
        'info' => 'message info'
    ];
    
    $class = $classes[$type] ?? $classes['info'];
    
    return "<div class=\"$class\">$message</div>";
}

/**
 * Formater une date en français
 */
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '';
    
    try {
        $dateObj = new DateTime($date);
        return $dateObj->format($format);
    } catch (Exception $e) {
        return $date;
    }
}

/**
 * Formater une date relative (il y a X jours)
 */
function formatDateRelative($date) {
    if (empty($date)) return '';
    
    try {
        $dateObj = new DateTime($date);
        $now = new DateTime();
        $interval = $now->diff($dateObj);
        
        if ($interval->days == 0) {
            return "Aujourd'hui";
        } elseif ($interval->days == 1) {
            return "Hier";
        } elseif ($interval->days < 7) {
            return "Il y a " . $interval->days . " jours";
        } elseif ($interval->days < 30) {
            $weeks = floor($interval->days / 7);
            return "Il y a " . $weeks . " semaine" . ($weeks > 1 ? 's' : '');
        } elseif ($interval->days < 365) {
            $months = floor($interval->days / 30);
            return "Il y a " . $months . " mois";
        } else {
            $years = floor($interval->days / 365);
            return "Il y a " . $years . " an" . ($years > 1 ? 's' : '');
        }
    } catch (Exception $e) {
        return formatDate($date);
    }
}

/**
 * Tronquer un texte proprement
 */
function truncateText($text, $length = 200, $suffix = '...') {
    $text = strip_tags($text ?? '');
    
    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }
    
    $truncated = mb_substr($text, 0, $length, 'UTF-8');
    
    // Éviter de couper au milieu d'un mot
    $lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');
    if ($lastSpace !== false && $lastSpace > $length * 0.8) {
        $truncated = mb_substr($truncated, 0, $lastSpace, 'UTF-8');
    }
    
    return $truncated . $suffix;
}

/**
 * Générer une classe CSS pour le statut
 */
function getStatusClass($statut) {
    $statusClasses = [
        'À lire' => 'statut-a-lire',
        'En cours' => 'statut-en-cours',
        'Lu' => 'statut-lu',
        'Abandonné' => 'statut-abandonne'
    ];
    
    return $statusClasses[$statut] ?? 'statut-a-lire';
}

/**
 * Générer une classe CSS pour le support
 */
function getSupportClass($support) {
    $classes = [
        'Livre' => 'support-livre',
        'Bande dessinée' => 'support-bd',
        'Manga' => 'support-manga'
    ];
    return $classes[$support] ?? 'support-livre';
}

/**
 * Sécuriser et afficher les tags
 */
function displayTags($tags, $maxDisplay = null) {
    if (empty($tags)) return '';
    
    $tagArray = explode(',', $tags);
    $tagArray = array_map('trim', $tagArray);
    $tagArray = array_filter($tagArray);
    
    $output = '';
    $displayed = 0;
    
    foreach ($tagArray as $tag) {
        if ($maxDisplay && $displayed >= $maxDisplay) {
            $remaining = count($tagArray) - $displayed;
            $output .= '<span class="tag tag-more">+' . $remaining . '</span>';
            break;
        }
        
        $output .= '<span class="tag">' . h($tag) . '</span>';
        $displayed++;
    }
    
    return $output;
}

/**
 * Valider les données d'entrée
 */
function validateInput($data, $rules) {
    $errors = [];
    
    foreach ($rules as $field => $rule) {
        $value = $data[$field] ?? '';
        
        // Requis
        if (isset($rule['required']) && $rule['required'] && empty($value)) {
            $errors[$field] = "Le champ " . ($rule['label'] ?? $field) . " est requis.";
            continue;
        }
        
        // Longueur minimale
        if (isset($rule['min_length']) && strlen($value) < $rule['min_length']) {
            $errors[$field] = "Le champ " . ($rule['label'] ?? $field) . " doit faire au moins " . $rule['min_length'] . " caractères.";
        }
        
        // Longueur maximale
        if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
            $errors[$field] = "Le champ " . ($rule['label'] ?? $field) . " ne peut pas dépasser " . $rule['max_length'] . " caractères.";
        }
        
        // Format ISBN
        if (isset($rule['isbn']) && $rule['isbn'] && !empty($value)) {
            $cleanIsbn = preg_replace('/[^0-9X]/', '', $value);
            if (!in_array(strlen($cleanIsbn), [10, 13])) {
                $errors[$field] = "Le format ISBN n'est pas valide.";
            }
        }
    }
    
    return $errors;
}

/**
 * Générer les liens de pagination
 */
function generatePagination($paginationInfo, $baseUrl = '') {
    if ($paginationInfo['total_pages'] <= 1) {
        return '';
    }
    
    $current = $paginationInfo['current_page'];
    $total = $paginationInfo['total_pages'];
    $html = '<div class="pagination">';
    
    // Page précédente
    if ($paginationInfo['has_previous']) {
        $html .= '<a href="' . $baseUrl . buildUrl(['page' => $paginationInfo['previous_page']]) . '" class="pagination-btn pagination-prev">‹ Précédent</a>';
    }
    
    // Numéros de pages
    $html .= '<div class="pagination-numbers">';
    
    // Première page
    if ($current > 3) {
        $html .= '<a href="' . $baseUrl . buildUrl(['page' => 1]) . '" class="pagination-number">1</a>';
        if ($current > 4) {
            $html .= '<span class="pagination-ellipsis">...</span>';
        }
    }
    
    // Pages autour de la page courante
    for ($i = max(1, $current - 2); $i <= min($total, $current + 2); $i++) {
        if ($i == $current) {
            $html .= '<span class="pagination-number pagination-current">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $baseUrl . buildUrl(['page' => $i]) . '" class="pagination-number">' . $i . '</a>';
        }
    }
    
    // Dernière page
    if ($current < $total - 2) {
        if ($current < $total - 3) {
            $html .= '<span class="pagination-ellipsis">...</span>';
        }
        $html .= '<a href="' . $baseUrl . buildUrl(['page' => $total]) . '" class="pagination-number">' . $total . '</a>';
    }
    
    $html .= '</div>';
    
    // Page suivante
    if ($paginationInfo['has_next']) {
        $html .= '<a href="' . $baseUrl . buildUrl(['page' => $paginationInfo['next_page']]) . '" class="pagination-btn pagination-next">Suivant ›</a>';
    }
    
    $html .= '</div>';
    
    // Info sur les résultats
    $html .= '<div class="pagination-info">';
    $html .= 'Affichage de ' . $paginationInfo['start_item'] . ' à ' . $paginationInfo['end_item'] . 
             ' sur ' . $paginationInfo['total_items'] . ' résultats';
    $html .= '</div>';
    
    return $html;
}

/**
 * Nettoyer les paramètres de recherche
 */
function sanitizeSearchParams($params, $validSupports = ['Livre', 'Bande dessinée', 'Manga']) {
    $clean = [];

    foreach ($params as $key => $value) {
        switch ($key) {
            case 'search':
                $clean[$key] = trim(strip_tags($value));
                break;
            case 'filter':
                $clean[$key] = in_array($value, $validSupports, true) ? $value : '';
                break;
            case 'statut':
                $clean[$key] = in_array($value, ['À lire', 'En cours', 'Lu', 'Abandonné']) ? $value : '';
                break;
            case 'view':
                $clean[$key] = in_array($value, ['grid', 'table']) ? $value : 'grid';
                break;
            case 'page':
                $clean[$key] = max(1, intval($value));
                break;
            case 'tag':
            case 'serie':
                $clean[$key] = trim(strip_tags($value));
                break;
            default:
                $clean[$key] = strip_tags($value);
        }
    }
    
    return $clean;
}

/**
 * Nettoie et valide les paramètres de la recherche avancée (page outils.php,
 * section édition groupée). Même esprit que sanitizeSearchParams() : whitelist
 * stricte sur les valeurs contraintes (support, statut, tri, mode ET/OU),
 * validation réelle des dates, cast booléen sur les cases à cocher.
 */
function sanitizeAdvancedSearchParams($params, $validSupports = ['Livre', 'Bande dessinée', 'Manga']) {
    $clean = [];

    $clean['search'] = trim(strip_tags($params['search'] ?? ''));

    $clean['support'] = in_array($params['support'] ?? '', $validSupports, true)
        ? $params['support'] : '';

    $clean['statut'] = in_array($params['statut'] ?? '', ['À lire', 'En cours', 'Lu', 'Abandonné'])
        ? $params['statut'] : '';

    $clean['serie'] = trim(strip_tags($params['serie'] ?? ''));

    $clean['tags'] = [];
    if (!empty($params['tags']) && is_array($params['tags'])) {
        foreach ($params['tags'] as $tag) {
            $tag = trim(strip_tags($tag));
            if ($tag !== '') {
                $clean['tags'][] = $tag;
            }
        }
    }

    $clean['tags_mode'] = ($params['tags_mode'] ?? 'and') === 'or' ? 'or' : 'and';

    foreach (['date_from', 'date_to'] as $champDate) {
        $valeur = $params[$champDate] ?? '';
        $date = DateTime::createFromFormat('Y-m-d', $valeur);
        $clean[$champDate] = ($date && $date->format('Y-m-d') === $valeur) ? $valeur : '';
    }

    $clean['sans_couverture'] = !empty($params['sans_couverture']);
    $clean['sans_note'] = !empty($params['sans_note']);

    $clean['sort'] = in_array($params['sort'] ?? '', ['titre_asc', 'titre_desc', 'date_desc', 'date_asc', 'serie_asc'])
        ? $params['sort'] : 'titre_asc';

    $clean['page'] = max(1, intval($params['page'] ?? 1));

    return $clean;
}

/**
 * Générer un token CSRF
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifier un token CSRF
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Rediriger avec un message flash
 */
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit();
}

/**
 * Récupérer et afficher le message flash
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        
        return showMessage($message, $type);
    }
    
    return '';
}

/**
 * Vérifier si un fichier est une image valide
 */
function isValidImage($file) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    if ($file['size'] > $maxSize) {
        return false;
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    return in_array($mimeType, $allowedTypes);
}

/**
 * Formater la taille d'un fichier
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    
    for ($i = 0; $bytes >= 1024 && $i < 3; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Logger une erreur ou information
 */
function logMessage($message, $level = 'INFO') {
    $logFile = 'logs/app.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Générer une URL d'image avec fallback
 */
function getImageUrl($imagePath, $fallback = 'assets/images/no-cover.png') {
    if (empty($imagePath)) {
        return $fallback;
    }
    
    // Si c'est une URL externe (Google Books par exemple)
    if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
        return $imagePath;
    }
    
    // Si c'est un fichier local qui existe
    if (file_exists($imagePath)) {
        return $imagePath;
    }
    
    return $fallback;
}

/**
 * Détecter si la requête est AJAX
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * Retourner une réponse JSON
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}
/**
 * Fonction de logging simple
 * À ajouter dans votre fichier includes/utils.php
 */

if (!function_exists('logMessage')) {
    function logMessage($message, $level = 'INFO') {
        $logFile = 'logs/app.log';
        
        // Créer le dossier logs s'il n'existe pas
        if (!is_dir('logs')) {
            mkdir('logs', 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
        
        // Écrire dans le fichier de log (en mode append)
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

// Alternative plus simple si vous ne voulez pas de fichier de log :
if (!function_exists('logMessage')) {
    function logMessage($message, $level = 'INFO') {
        // Ne rien faire - fonction vide pour éviter les erreurs
        // Ou utiliser error_log() pour les logs système :
        // error_log("[$level] $message");
    }
}