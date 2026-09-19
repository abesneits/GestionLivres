<?php
/**
 * Page de déconnexion
 */

session_start();
require_once 'includes/auth.php';

// Vérifier le token CSRF si c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (validateAuthCSRFToken($_POST['csrf_token'] ?? '')) {
        logout();
    } else {
        header('Location: index.php');
        exit();
    }
} else {
    // Si c'est un GET, afficher la page de confirmation
    requireAuth(); // S'assurer que l'utilisateur est connecté
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déconnexion - Ma Bibliothèque</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1>Déconnexion</h1>
            <p>Êtes-vous sûr de vouloir vous déconnecter ?</p>
        </div>
        
        <div style="text-align: center; max-width: 400px; margin: 0 auto;">
            <div class="message info" style="margin-bottom: 30px;">
                Vous serez redirigé vers la page de connexion et devrez ressaisir votre mot de passe pour accéder à nouveau à votre bibliothèque.
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: center;">
                <a href="index.php" class="btn-secondary">Annuler</a>
                
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= getAuthCSRFToken() ?>">
                    <button type="submit" class="btn-danger">Se déconnecter</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>