<?php
// Menu de navigation principal (barre latérale gauche) avec authentification
$current_page = basename($_SERVER['PHP_SELF']);
require_once 'includes/auth.php';
?>

<button class="menu-toggle" onclick="toggleMenu()" aria-label="Menu">
    <span></span>
    <span></span>
    <span></span>
</button>
<div class="menu-overlay" onclick="toggleMenu()"></div>

<nav class="main-menu">
    <div class="menu-logo">
        <a href="index.php" title="Accueil"><span class="menu-icon">📚</span><span class="menu-label">Ma Collection</span></a>
    </div>

    <div class="menu-wrapper">
        <ul class="menu-items">
            <li><a href="index.php" title="Accueil" class="<?= $current_page === 'index.php' ? 'active' : '' ?>">
                <span class="menu-icon">🏠</span><span class="menu-label">Accueil</span>
            </a></li>

            <li><a href="ajouter.php" title="Ajouter" class="<?= $current_page === 'ajouter.php' ? 'active' : '' ?>">
                <span class="menu-icon">➕</span><span class="menu-label">Ajouter</span>
            </a></li>

            <li><a href="achats.php" title="Achats" class="<?= $current_page === 'achats.php' ? 'active' : '' ?>">
                <span class="menu-icon">🛒</span><span class="menu-label">Achats</span>
            </a></li>

            <li><a href="listes.php" title="Listes" class="<?= $current_page === 'listes.php' ? 'active' : '' ?>">
                <span class="menu-icon">📋</span><span class="menu-label">Listes</span>
            </a></li>

            <li><a href="auteurs.php" title="Auteurs" class="<?= $current_page === 'auteurs.php' ? 'active' : '' ?>">
                <span class="menu-icon">🖋️</span><span class="menu-label">Auteurs</span>
            </a></li>

            <li><a href="stats.php" title="Stats" class="<?= $current_page === 'stats.php' ? 'active' : '' ?>">
                <span class="menu-icon">📊</span><span class="menu-label">Stats</span>
            </a></li>

            <li><a href="outils.php" title="Outils" class="<?= $current_page === 'outils.php' ? 'active' : '' ?>">
                <span class="menu-icon">🛠️</span><span class="menu-label">Outils</span>
            </a></li>

            <li><a href="export.php" title="Export" class="<?= $current_page === 'export.php' ? 'active' : '' ?>">
                <span class="menu-icon">📥</span><span class="menu-label">Export</span>
            </a></li>
        </ul>

        <div class="menu-bottom">
            <div class="menu-stats">
                <?php
                // Afficher les stats si BookManager est disponible
                if (isset($bookManager)) {
                    $statsmenu = $bookManager->getStats();
                    echo "<span class='stats-badge'>{$statsmenu['total']} livres</span>";
                }
                ?>
            </div>
            <button type="button" class="theme-btn collapse-btn" onclick="toggleReduit()" title="Réduire / agrandir le menu"><span class="menu-icon collapse-icon">◀</span><span class="menu-label">Réduire</span></button>
            <button type="button" class="theme-btn" onclick="toggleTheme()" title="Changer de thème"><span class="menu-icon">🌓</span><span class="menu-label">Thème</span></button>
            <a href="logout.php" class="logout-btn" title="Se déconnecter"><span class="menu-icon">🚪</span><span class="menu-label">Déconnexion</span></a>
        </div>
    </div>
</nav>

<script>
// Bascule clair/sombre, choix mémorisé dans le navigateur (appliqué au chargement par head.php)
function toggleTheme() {
    const racine = document.documentElement;
    const sombre = racine.getAttribute('data-theme') === 'dark'
        || (!racine.getAttribute('data-theme') && matchMedia('(prefers-color-scheme: dark)').matches);
    const choix = sombre ? 'light' : 'dark';
    racine.setAttribute('data-theme', choix);
    try { localStorage.setItem('theme', choix); } catch (e) {}
}

// Menu réduit aux icônes (choix mémorisé, appliqué au chargement par head.php)
function toggleReduit() {
    const racine = document.documentElement;
    const reduit = racine.getAttribute('data-menu') !== 'reduit';
    if (reduit) { racine.setAttribute('data-menu', 'reduit'); } else { racine.removeAttribute('data-menu'); }
    try { localStorage.setItem('menu', reduit ? 'reduit' : 'etendu'); } catch (e) {}
}

function toggleMenu() {
    document.querySelector('.menu-wrapper').classList.toggle('active');
    document.querySelector('.main-menu').classList.toggle('active');
    document.querySelector('.menu-toggle').classList.toggle('active');
    document.querySelector('.menu-overlay').classList.toggle('active');
}

// Fermer le menu en cliquant sur un lien (mobile)
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.menu-items a').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                document.querySelector('.main-menu').classList.remove('active');
                document.querySelector('.menu-wrapper').classList.remove('active');
                document.querySelector('.menu-toggle').classList.remove('active');
                document.querySelector('.menu-overlay').classList.remove('active');
            }
        });
    });
});
</script>

<style>
/* Le contenu est décalé pour laisser la place à la barre latérale */
body:has(> .main-menu) {
    padding-left: 240px;
}

.main-menu {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: 240px;
    box-sizing: border-box;
    background: var(--surface);
    box-shadow: 2px 0 12px rgba(0,0,0,0.1);
    border-right: 3px solid var(--accent);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    z-index: 1000;
}

.menu-logo {
    padding: 22px 20px 14px;
    font-size: 1.25em;
}

.menu-logo a {
    color: var(--text);
    text-decoration: none;
    font-weight: 600;
}

.menu-wrapper {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 0 12px 16px;
}

.menu-items {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.menu-items a,
.menu-bottom .logout-btn {
    display: block;
    padding: 11px 16px;
    color: var(--text);
    text-decoration: none;
    border-radius: 10px;
    transition: all 0.2s ease;
    font-weight: 500;
}

.menu-items a:hover {
    background: var(--surface-2);
    color: var(--accent-text);
}

.menu-items a.active {
    background: var(--accent);
    color: white;
    box-shadow: 0 2px 8px rgba(55,65,81,0.3);
}

.menu-bottom {
    margin-top: auto;
    padding-top: 16px;
    border-top: 2px solid var(--border-soft);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.menu-bottom .logout-btn:hover {
    background: #dc3545;
    color: white;
}

.menu-bottom .theme-btn {
    display: block;
    width: 100%;
    padding: 11px 16px;
    background: none;
    border: none;
    border-radius: 10px;
    text-align: left;
    cursor: pointer;
    font: inherit;
    font-weight: 500;
    color: var(--text);
    transition: all 0.2s ease;
}

.menu-bottom .theme-btn:hover {
    background: var(--surface-2);
}

.menu-stats {
    text-align: center;
}

.stats-badge {
    display: inline-block;
    background: var(--accent-soft);
    color: var(--accent-text);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.9em;
    font-weight: 600;
}

.menu-toggle,
.menu-overlay {
    display: none;
}

.menu-icon {
    display: inline-block;
    width: 1.5em;
    margin-right: 8px;
    text-align: center;
}

.collapse-icon {
    transition: transform 0.2s ease;
}

/* Menu réduit : seules les icônes restent visibles (ordinateur uniquement) */
@media (min-width: 769px) {
    html[data-menu="reduit"] body:has(> .main-menu) {
        padding-left: 72px;
    }

    html[data-menu="reduit"] .main-menu {
        width: 72px;
    }

    html[data-menu="reduit"] .menu-label,
    html[data-menu="reduit"] .menu-stats {
        display: none;
    }

    html[data-menu="reduit"] .menu-logo {
        padding: 22px 0 14px;
        text-align: center;
    }

    html[data-menu="reduit"] .menu-wrapper {
        padding: 0 8px 16px;
    }

    html[data-menu="reduit"] .menu-items a,
    html[data-menu="reduit"] .menu-bottom .logout-btn,
    html[data-menu="reduit"] .menu-bottom .theme-btn {
        padding: 11px 0;
        text-align: center;
    }

    html[data-menu="reduit"] .menu-icon {
        margin-right: 0;
    }

    html[data-menu="reduit"] .collapse-icon {
        transform: rotate(180deg);
    }
}

@media (max-width: 768px) {
    .collapse-btn {
        display: none !important;
    }
}

/* Mobile : la barre devient un tiroir à gauche */
@media (max-width: 768px) {
    body:has(> .main-menu) {
        padding-left: 0;
        padding-top: 56px;
    }

    .menu-toggle {
        display: flex;
        flex-direction: column;
        gap: 5px;
        position: fixed;
        top: 8px;
        left: 8px;
        background: var(--surface);
        border: none;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        cursor: pointer;
        padding: 12px;
        z-index: 1002;
    }

    .menu-toggle span {
        display: block;
        width: 24px;
        height: 3px;
        background: #333;
        transition: all 0.3s ease;
        border-radius: 3px;
    }

    .menu-toggle.active span:nth-child(1) { transform: rotate(45deg) translate(6px, 6px); }
    .menu-toggle.active span:nth-child(2) { opacity: 0; }
    .menu-toggle.active span:nth-child(3) { transform: rotate(-45deg) translate(6px, -6px); }

    .menu-overlay {
        display: block;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 999;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s ease;
    }

    .menu-overlay.active {
        opacity: 1;
        pointer-events: all;
    }

    .main-menu {
        left: -100%;
        width: 260px;
        padding-top: 56px;
        transition: left 0.3s ease;
    }

    .main-menu.active {
        left: 0;
    }
}
</style>
