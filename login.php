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

require 'views/login.php';
