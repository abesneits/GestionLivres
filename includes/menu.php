<?php
// Menu de navigation principal avec authentification
$current_page = basename($_SERVER['PHP_SELF']);
require_once 'includes/auth.php';
?>

<nav class="main-menu">
    <div class="menu-container">
        <div class="menu-logo">
            <h1><a href="index.php">📚</a></h1>
        </div>
        
        <button class="menu-toggle" onclick="toggleMenu()" aria-label="Menu">
            <span></span>
            <span></span>
            <span></span>
        </button>
        
        <div class="menu-wrapper">
          <ul class="menu-items">
                <li><a href="index.php" class="<?= $current_page === 'index.php' ? 'active' : '' ?>">
                    🏠 Accueil
                </a></li>
                
                <li><a href="ajouter.php" class="<?= $current_page === 'ajouter.php' ? 'active' : '' ?>">
                    ➕ Ajouter
                </a></li>
                
                <li><a href="listes.php" class="<?= $current_page === 'listes.php' ? 'active' : '' ?>">
                    📋 Listes
                </a></li>

                <li><a href="auteurs.php" class="<?= $current_page === 'auteurs.php' ? 'active' : '' ?>">
                    🖋️ Auteurs
                </a></li>

                <li><a href="stats.php" class="<?= $current_page === 'stats.php' ? 'active' : '' ?>">
                    📊 Stats
                </a></li>

                <li><a href="outils.php" class="<?= $current_page === 'outils.php' ? 'active' : '' ?>">
                    🛠️ Outils
                </a></li>

                <li><a href="export.php" class="<?= $current_page === 'export.php' ? 'active' : '' ?>">
                    📥 Export
                </a></li>
                
                <li><a href="logout.php" class="logout-btn" title="Se déconnecter">
                    🚪 Déconnexion
                </a></li>
            </ul>
            
            <div class="menu-stats">
                <?php
                // Afficher les stats si BookManager est disponible
                if (isset($bookManager)) {
                    $statsmenu = $bookManager->getStats();
                    echo "<span class='stats-badge'>{$statsmenu['total']} livres</span>";
                }
                ?>
            </div>
        </div>
    </div>
</nav>

<script>
function toggleMenu() {
    const menuWrapper = document.querySelector('.menu-wrapper');
    const menuToggle = document.querySelector('.menu-toggle');
    
    menuWrapper.classList.toggle('active');
    menuToggle.classList.toggle('active');
}

// Fermer le menu en cliquant sur un lien (mobile)
document.addEventListener('DOMContentLoaded', function() {
    const menuLinks = document.querySelectorAll('.menu-items a');
    menuLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                const menuWrapper = document.querySelector('.menu-wrapper');
                const menuToggle = document.querySelector('.menu-toggle');
                menuWrapper.classList.remove('active');
                menuToggle.classList.remove('active');
            }
        });
    });
});
</script>

<style>
.main-menu {
    background: white;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 30px;
    border-bottom: 3px solid #007bff;
}

.menu-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 70px;
}

.menu-logo h1 {
    margin: 0;
    font-size: 1.5em;
}

.menu-logo a {
    color: #333;
    text-decoration: none;
    font-weight: 600;
}

.menu-toggle {
    display: none;
    flex-direction: column;
    gap: 5px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 10px;
    z-index: 1001;
}

.menu-toggle span {
    display: block;
    width: 25px;
    height: 3px;
    background: #333;
    transition: all 0.3s ease;
    border-radius: 3px;
}

.menu-toggle.active span:nth-child(1) {
    transform: rotate(45deg) translate(7px, 7px);
}

.menu-toggle.active span:nth-child(2) {
    opacity: 0;
}

.menu-toggle.active span:nth-child(3) {
    transform: rotate(-45deg) translate(7px, -7px);
}

.menu-wrapper {
    display: flex;
    align-items: center;
    gap: 30px;
}

.menu-items {
    display: flex;
    list-style: none;
    margin: 0;
    padding: 0;
    gap: 5px;
}

.menu-items a {
    display: block;
    padding: 12px 20px;
    color: #333;
    text-decoration: none;
    border-radius: 25px;
    transition: all 0.3s ease;
    font-weight: 500;
}

.menu-items a:hover {
    background: #f8f9fa;
    color: #007bff;
    text-decoration: none;
}

.menu-items a.active {
    background: #007bff;
    color: white;
    box-shadow: 0 2px 8px rgba(0,123,255,0.3);
}

.logout-btn:hover {
    background: #dc3545 !important;
    color: white !important;
}

.menu-stats {
    color: #333;
    display: flex;
    gap: 15px;
    align-items: center;
}

.stats-badge {
    background: #e3f2fd;
    color: #1976d2;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 0.9em;
    font-weight: 600;
}

/* Overlay pour mobile */
.menu-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 999;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.menu-overlay.active {
    opacity: 1;
}

/* Responsive - Menu Hamburger */
@media (max-width: 768px) {
    .menu-toggle {
        display: flex;
    }
    
    .menu-overlay {
        display: block;
        pointer-events: none;
    }
    
    .menu-overlay.active {
        pointer-events: all;
    }
    
    .menu-wrapper {
        position: fixed;
        top: 0;
        right: -100%;
        width: 280px;
        height: 100vh;
        background: white;
        box-shadow: -2px 0 10px rgba(0,0,0,0.1);
        flex-direction: column;
        align-items: flex-start;
        padding: 80px 20px 20px 20px;
        transition: right 0.3s ease;
        z-index: 1000;
        gap: 20px;
    }
    
    .menu-wrapper.active {
        right: 0;
    }
    
    .menu-items {
        flex-direction: column;
        width: 100%;
        gap: 0;
    }
    
    .menu-items li {
        width: 100%;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .menu-items a {
        width: 100%;
        padding: 15px 20px;
        border-radius: 0;
        text-align: left;
    }
    
    .menu-stats {
        width: 100%;
        justify-content: center;
        padding-top: 20px;
        border-top: 2px solid #f0f0f0;
    }
}

/* Adaptation pour tablettes */
@media (max-width: 1024px) and (min-width: 769px) {
    .menu-items a {
        padding: 10px 16px;
        font-size: 0.9em;
    }
}
</style>