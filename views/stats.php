<!DOCTYPE html>
<html lang="fr">
<?php 
include 'includes/head.php'; 
renderHead('Statistiques - Ma Collection'); 
?>
<body>

    <?php include 'includes/menu.php'; ?>
    
    <div class="container">
        
        <?php 
        if ($flashMessage) {
            echo $flashMessage;
        }
        ?>
        
        <div class="page-header">
            <h1>📊 Statistiques de votre collection</h1>
            <p>Découvrez des informations détaillées sur vos livres</p>
        </div>


<!-- Statistiques générales -->
<div class="stats-overview">
    <div class="stat-card-large">
        <div class="stat-icon">📚</div>
        <div class="stat-content">
            <div class="stat-number" style="color:white; font-size:2em;"><?= $stats['total'] ?></div>
            <div class="stat-label">Livres au total</div>
        </div>
    </div>
    
    <div class="stat-card-large">
        <div class="stat-icon">📖</div>
        <div class="stat-content">
            <div class="stat-number" style="color:white; font-size:2em;"><?= $stats['lu'] ?></div>
            <div class="stat-label">Livres lus</div>
            <div class="stat-detail">
                <?= $stats['total'] > 0 ? round(($stats['lu'] / $stats['total']) * 100) : 0 ?>% 
                de votre collection
            </div>
        </div>
    </div>
    
    <div class="stat-card-large">
        <div class="stat-icon">⏳</div>
        <div class="stat-content">
            <div class="stat-number" style="color:white; font-size:2em;"><?= $stats['a_lire'] ?></div>
            <div class="stat-label">À lire</div>
            <div class="stat-detail">
                <?= $stats['total'] > 0 ? round(($stats['a_lire'] / $stats['total']) * 100) : 0 ?>% 
                en attente
            </div>
        </div>
    </div>
    
    <div class="stat-card-large">
        <div class="stat-icon">🚀</div>
        <div class="stat-content">
            <div class="stat-number" style="color:white; font-size:2em;"><?= $stats['en_cours'] ?></div>
            <div class="stat-label">En cours</div>
            <div class="stat-detail">Lectures actuelles</div>
        </div>
    </div>
</div>
        <!-- Graphiques et analyses -->
        <div class="stats-grid">
            
            <!-- Répartition par statut -->
            <div class="stats-section">
                <h3>📊 Répartition par statut de lecture</h3>
                <div class="chart-container">
                    <div class="progress-chart">
                        <div class="progress-item">
                            <div class="progress-label">
                                <span class="status-dot status-lu"></span>
                                Livres lus (<?= $stats['lu'] ?>)
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill status-lu" 
                                     style="width: <?= $stats['total'] > 0 ? ($stats['lu'] / $stats['total']) * 100 : 0 ?>%"></div>
                            </div>
                            <div class="progress-percent">
                                <?= $stats['total'] > 0 ? round(($stats['lu'] / $stats['total']) * 100) : 0 ?>%
                            </div>
                        </div>
                        
                        <div class="progress-item">
                            <div class="progress-label">
                                <span class="status-dot status-a-lire"></span>
                                À lire (<?= $stats['a_lire'] ?>)
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill status-a-lire" 
                                     style="width: <?= $stats['total'] > 0 ? ($stats['a_lire'] / $stats['total']) * 100 : 0 ?>%"></div>
                            </div>
                            <div class="progress-percent">
                                <?= $stats['total'] > 0 ? round(($stats['a_lire'] / $stats['total']) * 100) : 0 ?>%
                            </div>
                        </div>
                        
                        <div class="progress-item">
                            <div class="progress-label">
                                <span class="status-dot status-en-cours"></span>
                                En cours (<?= $stats['en_cours'] ?>)
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill status-en-cours" 
                                     style="width: <?= $stats['total'] > 0 ? ($stats['en_cours'] / $stats['total']) * 100 : 0 ?>%"></div>
                            </div>
                            <div class="progress-percent">
                                <?= $stats['total'] > 0 ? round(($stats['en_cours'] / $stats['total']) * 100) : 0 ?>%
                            </div>
                        </div>
                        
                        <?php if ($stats['abandonne'] > 0): ?>
                        <div class="progress-item">
                            <div class="progress-label">
                                <span class="status-dot status-abandonne"></span>
                                Abandonnés (<?= $stats['abandonne'] ?>)
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill status-abandonne" 
                                     style="width: <?= $stats['total'] > 0 ? ($stats['abandonne'] / $stats['total']) * 100 : 0 ?>%"></div>
                            </div>
                            <div class="progress-percent">
                                <?= $stats['total'] > 0 ? round(($stats['abandonne'] / $stats['total']) * 100) : 0 ?>%
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Support types -->
            <div class="stats-section">
                <h3>📚 Répartition par support</h3>
                <div class="support-stats">
                    <div class="support-item">
                        <div class="support-icon">📖</div>
                        <div class="support-info">
                            <div class="support-name">Livres</div>
                            <div class="support-count"><?= number_format($stats['livres']) ?></div>
                            <div class="support-percent">
                                <?= $stats['total'] > 0 ? round(($stats['livres'] / $stats['total']) * 100) : 0 ?>%
                            </div>
                        </div>
                    </div>
                    <div class="support-item">
                        <div class="support-icon">🎨</div>
                        <div class="support-info">
                            <div class="support-name">Bandes Dessinées</div>
                            <div class="support-count"><?= number_format($stats['bd']) ?></div>
                            <div class="support-percent">
                                <?= $stats['total'] > 0 ? round(($stats['bd'] / $stats['total']) * 100) : 0 ?>%
                            </div>
                        </div>
                    </div>
                    <div class="support-item">
                        <div class="support-icon">🇯🇵</div>
                        <div class="support-info">
                            <div class="support-name">Mangas</div>
                            <div class="support-count"><?= number_format($stats['manga']) ?></div>
                            <div class="support-percent">
                                <?= $stats['total'] > 0 ? round(($stats['manga'] / $stats['total']) * 100) : 0 ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Papier vs Numérique -->
            <div class="stats-section">
                <h3>💾 Papier vs Numérique</h3>
                <div class="support-stats">
                    <div class="support-item">
                        <div class="support-icon">📕</div>
                        <div class="support-info">
                            <div class="support-name">Papier</div>
                            <div class="support-count"><?= number_format($stats['papier']) ?></div>
                            <div class="support-percent">
                                <?= $stats['total'] > 0 ? round(($stats['papier'] / $stats['total']) * 100) : 0 ?>%
                            </div>
                        </div>
                    </div>
                    <div class="support-item">
                        <div class="support-icon">💾</div>
                        <div class="support-info">
                            <div class="support-name">Numérique</div>
                            <div class="support-count"><?= number_format($stats['numerique']) ?></div>
                            <div class="support-percent">
                                <?= $stats['total'] > 0 ? round(($stats['numerique'] / $stats['total']) * 100) : 0 ?>%
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($formatStats)): ?>
                    <div class="tags-cloud" style="margin-top:20px;">
                        <?php foreach ($formatStats as $formatNom => $formatCount): ?>
                            <div class="tag-item">
                                <span class="tag-name"><?= h($formatNom) ?></span>
                                <span class="tag-count">(<?= $formatCount ?>)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Ajouts mensuels -->
            <div class="stats-section">
                <h3>📈 Évolution des ajouts (12 derniers mois)</h3>
                <div class="monthly-chart">
                    <?php if (!empty($monthlyStats)): ?>
                        <?php $maxCount = max(array_column($monthlyStats, 'count')); ?>
                        <?php foreach ($monthlyStats as $month): ?>
                        <div class="month-bar">
                            <div class="bar-fill" 
                                 style="height: <?= $maxCount > 0 ? ($month['count'] / $maxCount) * 100 : 0 ?>%"
                                 title="<?= $month['month_name'] ?> : <?= $month['count'] ?> livres">
                            </div>
                            <div class="month-label"><?= substr($month['month_name'], 0, 3) ?></div>
                            <div class="month-count"><?= $month['count'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Pas encore assez de données pour afficher le graphique mensuel.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top auteurs -->
            <div class="stats-section">
                <h3>✍️ Auteurs les plus représentés</h3>
                <div class="top-list">
                    <?php if (!empty($authorStats)): ?>
                        <?php foreach ($authorStats as $index => $author): ?>
                        <div class="top-item">
                            <div class="top-rank"><?= $index + 1 ?></div>
                            <div class="top-info">
                                <div class="top-name"><?= h($author['auteur']) ?></div>
                                <div class="top-count"><?= $author['count'] ?> livre<?= $author['count'] > 1 ? 's' : '' ?></div>
                            </div>
                            <div class="top-bar">
                                <div class="top-fill" 
                                     style="width: <?= ($author['count'] / $authorStats[0]['count']) * 100 ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Aucun auteur dans votre collection pour le moment.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top tags -->
            <div class="stats-section">
                <h3>🏷️ Tags les plus utilisés</h3>
                <div class="tags-cloud">
                    <?php if (!empty($topTags)): ?>
                        <?php $maxTagCount = $topTags[0]['count']; ?>
                        <?php foreach ($topTags as $tag): ?>
                        <div class="tag-item" 
                             style="font-size: <?= 0.8 + (($tag['count'] / $maxTagCount) * 1.2) ?>em">
                            <span class="tag-name"><?= h($tag['tag']) ?></span>
                            <span class="tag-count">(<?= $tag['count'] ?>)</span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Aucun tag utilisé pour le moment. <a href="index.php">Ajoutez des tags à vos livres</a></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Activité récente -->
            <div class="stats-section full-width">
                <h3>🕒 Activité récente</h3>
                <div class="recent-activity">
                    <?php if (!empty($recentActivity)): ?>
                        <?php foreach ($recentActivity as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <?php if ($activity['action'] === 'added'): ?>
                                    ➕
                                <?php elseif ($activity['action'] === 'updated'): ?>
                                    ✏️
                                <?php else: ?>
                                    📖
                                <?php endif; ?>
                            </div>
                            <div class="activity-content">
                                <div class="activity-text">
                                    <?php if ($activity['action'] === 'added'): ?>
                                        Livre ajouté : <strong><?= h($activity['titre']) ?></strong>
                                    <?php elseif ($activity['action'] === 'updated'): ?>
                                        Livre mis à jour : <strong><?= h($activity['titre']) ?></strong>
                                    <?php else: ?>
                                        <strong><?= h($activity['titre']) ?></strong>
                                    <?php endif; ?>
                                    <br>
                                    <small>par <?= h($activity['auteur']) ?></small>
                                </div>
                                <div class="activity-date">
                                    <?= formatDateRelative($activity['date']) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-activity">
                            <p>Aucune activité récente.</p>
                            <a href="ajouter.php" class="btn-primary">Ajouter votre premier livre</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Actions et export -->
        <div class="stats-actions">
            <h3>📊 Actions</h3>
            <div class="actions-grid">
                <a href="export.php?format=csv" class="action-btn export-btn">
                    <div class="action-icon">📋</div>
                    <div class="action-text">
                        <div class="action-title">Exporter en CSV</div>
                        <div class="action-desc">Télécharger toutes vos données</div>
                    </div>
                </a>

                <a href="export.php" class="action-btn export-btn">
                    <div class="action-icon">📤</div>
                    <div class="action-text">
                        <div class="action-title">Export avancé</div>
                        <div class="action-desc">Filtrer, trier et choisir le format</div>
                    </div>
                </a>
                
                <a href="index.php" class="action-btn view-btn">
                    <div class="action-icon">👁️</div>
                    <div class="action-text">
                        <div class="action-title">Voir la collection</div>
                        <div class="action-desc">Retour à votre bibliothèque</div>
                    </div>
                </a>
                
                <a href="ajouter.php" class="action-btn add-btn">
                    <div class="action-icon">➕</div>
                    <div class="action-text">
                        <div class="action-title">Ajouter un livre</div>
                        <div class="action-desc">Enrichir votre collection</div>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <style>
        /* Styles spécifiques à la page de statistiques */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card-large {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .stat-card-large:hover {
            transform: translateY(-5px);
        }

        .stat-card-large .stat-icon {
            font-size: 3em;
            margin-right: 20px;
        }

        .stat-card-large .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-card-large .stat-label {
            font-size: 1.1em;
            margin-bottom: 5px;
        }

        .stat-card-large .stat-detail {
            font-size: 0.9em;
            opacity: 0.9;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }

        .stats-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .stats-section.full-width {
            grid-column: 1 / -1;
        }

        .stats-section h3 {
            margin: 0 0 20px 0;
            color: #333;
            font-size: 1.3em;
        }

        /* Graphique de progression */
        .progress-chart {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .progress-item {
            display: grid;
            grid-template-columns: 1fr 2fr auto;
            gap: 15px;
            align-items: center;
        }

        .progress-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9em;
        }

        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .status-dot.status-lu { background: #28a745; }
        .status-dot.status-a-lire { background: #17a2b8; }
        .status-dot.status-en-cours { background: #ffc107; }
        .status-dot.status-abandonne { background: #dc3545; }

        .progress-bar {
            background: #e9ecef;
            height: 10px;
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            transition: width 0.5s ease;
        }

        .progress-fill.status-lu { background: #28a745; }
        .progress-fill.status-a-lire { background: #17a2b8; }
        .progress-fill.status-en-cours { background: #ffc107; }
        .progress-fill.status-abandonne { background: #dc3545; }

        .progress-percent {
            font-weight: bold;
            color: #495057;
            font-size: 0.9em;
        }

        /* Support stats */
        .support-stats {
            display: flex;
            gap: 30px;
        }

        .support-item {
            flex: 1;
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .support-icon {
            font-size: 3em;
            margin-bottom: 10px;
        }

        .support-name {
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
        }

        .support-count {
            font-size: 2em;
            font-weight: bold;
            color: #333;
        }

        .support-percent {
            color: #6c757d;
            font-size: 0.9em;
        }

        /* Graphique mensuel */
        .monthly-chart {
            display: flex;
            gap: 8px;
            align-items: end;
            height: 200px;
            padding: 20px 0;
            overflow-x: auto;
        }

        .month-bar {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 60px;
            height: 100%;
        }

        .bar-fill {
            background: linear-gradient(to top, #007bff, #6c5ce7);
            width: 20px;
            border-radius: 3px 3px 0 0;
            min-height: 5px;
            transition: height 0.5s ease;
            margin-bottom: 5px;
        }

        .month-label {
            font-size: 0.8em;
            color: #666;
            margin-bottom: 2px;
        }

        .month-count {
            font-size: 0.7em;
            font-weight: bold;
            color: #333;
        }

        /* Top listes */
        .top-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .top-item {
            display: grid;
            grid-template-columns: 30px 1fr 100px;
            gap: 15px;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .top-item:last-child {
            border-bottom: none;
        }

        .top-rank {
            font-weight: bold;
            color: #666;
            text-align: center;
        }

        .top-name {
            font-weight: 600;
            color: #333;
        }

        .top-count {
            color: #666;
            font-size: 0.9em;
        }

        .top-bar {
            background: #e9ecef;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
        }

        .top-fill {
            background: linear-gradient(90deg, #667eea, #764ba2);
            height: 100%;
            transition: width 0.5s ease;
        }

        /* Cloud de tags */
        .tags-cloud {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .tag-item {
            background: linear-gradient(135deg, #e9ecef, #f8f9fa);
            padding: 8px 15px;
            border-radius: 20px;
            border: 1px solid #dee2e6;
            transition: all 0.3s ease;
        }

        .tag-item:hover {
            background: linear-gradient(135deg, #007bff, #6c5ce7);
            color: white;
        }

        .tag-name {
            font-weight: 600;
        }

        .tag-count {
            font-size: 0.8em;
            opacity: 0.8;
        }

        /* Activité récente */
        .recent-activity {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .activity-item {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }

        .activity-icon {
            font-size: 1.5em;
        }

        .activity-content {
            flex: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .activity-text {
            flex: 1;
        }

        .activity-date {
            color: #666;
            font-size: 0.9em;
            text-align: right;
        }

        .empty-activity {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        /* Actions */
        .stats-actions {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid #e9ecef;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .action-btn {
            display: flex;
            align-items: center;
            padding: 20px;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            border-color: #007bff;
            box-shadow: 0 4px 12px rgba(0,123,255,0.1);
            text-decoration: none;
            color: inherit;
        }

        .action-btn .action-icon {
            font-size: 2em;
            margin-right: 15px;
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .action-desc {
            color: #666;
            font-size: 0.9em;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .support-stats {
                flex-direction: column;
                gap: 15px;
            }
            
            .monthly-chart {
                height: 150px;
                padding: 10px 0;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
            
            .activity-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .activity-date {
                text-align: left;
                margin-top: 5px;
            }
        }
    </style>
</body>
</html>