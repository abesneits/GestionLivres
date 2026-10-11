<?php
/**
 * Page de connexion pour la bibliothèque
 */

require_once 'includes/auth.php';
startSecureSession();

// Si déjà connecté, rediriger
if (isLoggedIn()) {
    $redirect = safeRedirectTarget($_SESSION['redirect_after_login'] ?? 'index.php');
    unset($_SESSION['redirect_after_login']);
    header('Location: ' . $redirect);
    exit();
}

$error = '';

// Anti-brute force : les échecs sont mémorisés CÔTÉ SERVEUR, par adresse IP
// (pas dans la session : un attaquant pourrait jeter son cookie pour repartir à zéro).
// Après LOGIN_MAX_ATTEMPTS échecs, blocage pendant LOGIN_LOCK_SECONDS ; ensuite, le compteur repart de zéro.
$throttle = getLoginThrottle();
$attempts = $throttle['attempts'];
$lastAttempt = $throttle['last'];

if ($attempts >= LOGIN_MAX_ATTEMPTS && (time() - $lastAttempt) < LOGIN_LOCK_SECONDS) {
    $timeLeft = LOGIN_LOCK_SECONDS - (time() - $lastAttempt);
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
                clearLoginAttempts();

                logSecurityEvent('LOGIN_SUCCESS', 'Successful login');

                $redirect = safeRedirectTarget($_SESSION['redirect_after_login'] ?? 'index.php');
                unset($_SESSION['redirect_after_login']);

                header('Location: ' . $redirect);
                exit();
            } else {
                // Échec de connexion
                $throttle = recordFailedLogin();
                $attempts = $throttle['attempts'];
                $lastAttempt = $throttle['last'];
                if ($attempts >= LOGIN_MAX_ATTEMPTS) {
                    $timeLeft = LOGIN_LOCK_SECONDS;
                    $error = "Trop de tentatives. Réessayez dans " . ceil($timeLeft/60) . " minutes.";
                } else {
                    $error = "Mot de passe incorrect.";
                }

                logSecurityEvent('LOGIN_FAILED', 'Failed login attempt');
            }
        } catch (Exception $e) {
            $error = "Erreur de configuration : " . $e->getMessage();
            logSecurityEvent('LOGIN_ERROR', $e->getMessage());
        }
    }
}

require 'views/login.php';
