<?php
/**
 * Page de connexion pour la bibliothèque
 */

session_start();
require_once 'includes/auth.php';

// Si déjà connecté, rediriger
if (isLoggedIn()) {
    $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
    unset($_SESSION['redirect_after_login']);
    header('Location: ' . $redirect);
    exit();
}

$error = '';
$attempts = $_SESSION['login_attempts'] ?? 0;
$lastAttempt = $_SESSION['last_attempt'] ?? 0;

// Anti-brute force : bloquer après 5 tentatives pendant 5 minutes
if ($attempts >= 5 && (time() - $lastAttempt) < 300) {
    $timeLeft = 300 - (time() - $lastAttempt);
    $error = "Trop de tentatives. Réessayez dans " . ceil($timeLeft/60) . " minutes.";
}

if ($_POST && empty($error)) {
    if (!validateAuthCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Token de sécurité invalide.";
        logSecurityEvent('CSRF_INVALID', 'Invalid CSRF token on login');
    } else {
        $password = $_POST['password'] ?? '';
        
        try {
            if (login($password)) {
                // Connexion réussie
                unset($_SESSION['login_attempts']);
                unset($_SESSION['last_attempt']);
                
                logSecurityEvent('LOGIN_SUCCESS', 'Successful login');
                
                $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
                unset($_SESSION['redirect_after_login']);
                
                header('Location: ' . $redirect);
                exit();
            } else {
                // Échec de connexion
                $_SESSION['login_attempts'] = $attempts + 1;
                $_SESSION['last_attempt'] = time();
                $error = "Mot de passe incorrect.";
                
                logSecurityEvent('LOGIN_FAILED', 'Failed login attempt');
            }
        } catch (Exception $e) {
            $error = "Erreur de configuration : " . $e->getMessage();
            logSecurityEvent('LOGIN_ERROR', $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Ma Bibliothèque</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        
        .login-header {
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 2em;
        }
        
        .login-header p {
            color: #666;
            margin: 0;
        }
        
        .login-form {
            margin-bottom: 20px;
        }
        
        .login-form .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        .login-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        
        .login-form input[type="password"] {
            width: 100%;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }
        
        .login-form input[type="password"]:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }
        
        .login-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
        }
        
        .login-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        
        .login-error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
        }
        
        .login-info {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            font-size: 14px;
        }
        
        .security-note {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Ma Bibliothèque</h1>
            <p>Veuillez vous connecter pour accéder à votre collection</p>
        </div>
        
        <?php if ($error): ?>
            <div class="login-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($attempts > 0 && $attempts < 5): ?>
            <div class="login-info">
                Tentative <?= $attempts ?>/5. Attention aux tentatives répétées.
            </div>
        <?php endif; ?>
        
        <form method="POST" class="login-form" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?= getAuthCSRFToken() ?>">
            
            <div class="form-group">
                <label for="password">Mot de passe :</label>
                <input type="password" 
                       id="password" 
                       name="password" 
                       required 
                       autocomplete="current-password"
                       <?= $attempts >= 5 && (time() - $lastAttempt) < 300 ? 'disabled' : '' ?>>
            </div>
            
            <button type="submit" 
                    class="login-btn" 
                    <?= $attempts >= 5 && (time() - $lastAttempt) < 300 ? 'disabled' : '' ?>>
                Se connecter
            </button>
        </form>
        
        <div class="security-note">
            <strong>Sécurité :</strong> Votre session expirera après 1 heure d'inactivité.
            <?php if ($attempts >= 3): ?>
                <br><strong>Attention :</strong> Compte temporairement bloqué après 5 tentatives.
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Auto-focus sur le champ mot de passe
        document.addEventListener('DOMContentLoaded', function() {
            const passwordField = document.getElementById('password');
            if (passwordField && !passwordField.disabled) {
                passwordField.focus();
            }
        });
        
        // Actualiser la page si bloqué (pour mettre à jour le timer)
        <?php if ($attempts >= 5 && (time() - $lastAttempt) < 300): ?>
            setTimeout(function() {
                window.location.reload();
            }, <?= ($timeLeft ?? 60) * 1000 ?>);
        <?php endif; ?>
        
        // Validation côté client
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            if (password.length < 1) {
                e.preventDefault();
                alert('Veuillez saisir le mot de passe');
            }
        });
        
        // Sécurité : vider le champ après tentative échouée
        <?php if ($error && !empty($_POST)): ?>
            document.getElementById('password').value = '';
        <?php endif; ?>
    </script>
</body>
</html>