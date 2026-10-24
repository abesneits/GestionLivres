<?php
// includes/head.php
function renderHead($title = "Ma Collection de Livres", $description = "Gérez votre collection de livres") {
?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($description) ?>">
    
    <!-- Icônes et manifeste : chemins relatifs, pour fonctionner aussi
         quand le site est installé dans un sous-dossier -->
    <link rel="icon" type="image/png" href="favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="favicon.svg" />
    <link rel="shortcut icon" href="favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png" />
    <link rel="manifest" href="site.webmanifest" />
    <meta name="theme-color" content="#4a4a4a">

    <script>
        // Thème choisi (clair/sombre) appliqué avant l'affichage pour éviter un flash
        try {
            var t = localStorage.getItem('theme'); if (t) document.documentElement.setAttribute('data-theme', t);
            if (localStorage.getItem('menu') === 'reduit') document.documentElement.setAttribute('data-menu', 'reduit');
        } catch (e) {}
    </script>
    <link rel="stylesheet" href="style.css">
</head>
<?php
}
?>