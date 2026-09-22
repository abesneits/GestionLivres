<?php
/**
 * Page de statistiques détaillées de la collection
 */

require_once 'includes/bootstrap.php';

// Récupérer les messages flash
$flashMessage = getFlashMessage();

// Calculer les statistiques détaillées
$stats = $bookManager->getDetailedStats();
$monthlyStats = $bookManager->getMonthlyAdditionStats();
$topTags = $bookManager->getTopTags(10);
$authorStats = $bookManager->getAuthorStats(10);
$recentActivity = $bookManager->getRecentActivity(10);

require 'views/stats.php';
