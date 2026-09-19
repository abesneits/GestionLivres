<?php
/**
 * Fonctions d'export partagées (CSV, Excel, JSON, TXT, HTML).
 * Utilisées par export_liste.php (export d'une liste de lecture) et
 * export.php (export d'une recherche/sélection dans la collection).
 *
 * $meta attend les clés 'nom' et 'description' (mêmes clés que la table
 * liste_lecture, pour rester compatible avec les deux appelants).
 */

/**
 * Export CSV
 */
function exportCSV($meta, $books, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $output = fopen('php://output', 'w');

    // BOM UTF-8 pour Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // En-tête
    fputcsv($output, [$meta['nom']], ';');
    if (!empty($meta['description'])) {
        fputcsv($output, ['Description', $meta['description']], ';');
    }
    fputcsv($output, ['Nombre de livres', count($books)], ';');
    fputcsv($output, ['Date d\'export', date('d/m/Y H:i')], ';');
    fputcsv($output, [], ';');

    // Colonnes
    fputcsv($output, [
        'Titre',
        'Auteur',
        'ISBN',
        'Éditeur',
        'Date de publication',
        'Support',
        'Statut',
        'Genre',
        'Tags',
        'Note',
        'Commentaire'
    ], ';');

    // Données
    foreach ($books as $book) {
        fputcsv($output, [
            $book['titre'],
            $book['auteur'],
            $book['isbn'] ?? '',
            $book['editeur'] ?? '',
            $book['date_publication'] ?? '',
            $book['support'],
            $book['statut'] ?? 'À lire',
            $book['genre'] ?? '',
            $book['tags'] ?? '',
            $book['note'] ?? '',
            $book['commentaire'] ?? ''
        ], ';');
    }

    fclose($output);
    exit;
}

/**
 * Export Excel (HTML table compatible)
 */
function exportExcel($meta, $books, $filename) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
    echo 'th { background-color: #4CAF50; color: white; font-weight: bold; }';
    echo '.header-info { background-color: #e3f2fd; font-weight: bold; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';

    echo '<h2>' . htmlspecialchars($meta['nom']) . '</h2>';

    // Informations
    echo '<table>';
    if (!empty($meta['description'])) {
        echo '<tr class="header-info"><td>Description</td><td>' . htmlspecialchars($meta['description']) . '</td></tr>';
    }
    echo '<tr class="header-info"><td>Nombre de livres</td><td>' . count($books) . '</td></tr>';
    echo '<tr class="header-info"><td>Date d\'export</td><td>' . date('d/m/Y H:i') . '</td></tr>';
    echo '</table>';

    echo '<br><br>';

    // Tableau des livres
    echo '<table>';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Titre</th>';
    echo '<th>Auteur</th>';
    echo '<th>ISBN</th>';
    echo '<th>Éditeur</th>';
    echo '<th>Date publication</th>';
    echo '<th>Support</th>';
    echo '<th>Statut</th>';
    echo '<th>Genre</th>';
    echo '<th>Tags</th>';
    echo '<th>Note</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($books as $book) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($book['titre']) . '</td>';
        echo '<td>' . htmlspecialchars($book['auteur']) . '</td>';
        echo '<td>' . htmlspecialchars($book['isbn'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($book['editeur'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($book['date_publication'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($book['support']) . '</td>';
        echo '<td>' . htmlspecialchars($book['statut'] ?? 'À lire') . '</td>';
        echo '<td>' . htmlspecialchars($book['genre'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($book['tags'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($book['note'] ?? '') . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit;
}

/**
 * Export JSON
 */
function exportJSON($meta, $books, $filename) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');

    $data = [
        'export' => [
            'nom' => $meta['nom'],
            'description' => $meta['description'] ?? '',
            'nb_livres' => count($books),
            'date_export' => date('c')
        ],
        'livres' => array_map(function($book) {
            return [
                'titre' => $book['titre'],
                'auteur' => $book['auteur'],
                'isbn' => $book['isbn'] ?? null,
                'editeur' => $book['editeur'] ?? null,
                'date_publication' => $book['date_publication'] ?? null,
                'support' => $book['support'],
                'statut' => $book['statut'] ?? 'À lire',
                'genre' => $book['genre'] ?? null,
                'tags' => $book['tags'] ? explode(',', $book['tags']) : [],
                'note' => $book['note'] ?? null,
                'commentaire' => $book['commentaire'] ?? null
            ];
        }, $books)
    ];

    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Export TXT (format lisible)
 */
function exportTXT($meta, $books, $filename) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.txt"');

    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║  " . mb_strtoupper($meta['nom']) . str_repeat(' ', max(0, 58 - mb_strlen($meta['nom']))) . "║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";

    if (!empty($meta['description'])) {
        echo "Description : " . $meta['description'] . "\n\n";
    }

    echo "Nombre de livres : " . count($books) . "\n";
    echo "Date d'export : " . date('d/m/Y à H:i') . "\n";
    echo "\n" . str_repeat("─", 70) . "\n\n";

    $counter = 1;
    foreach ($books as $book) {
        echo "📚 Livre #" . $counter . "\n";
        echo "   Titre    : " . $book['titre'] . "\n";
        echo "   Auteur   : " . $book['auteur'] . "\n";

        if (!empty($book['isbn'])) {
            echo "   ISBN     : " . $book['isbn'] . "\n";
        }
        if (!empty($book['editeur'])) {
            echo "   Éditeur  : " . $book['editeur'] . "\n";
        }
        if (!empty($book['date_publication'])) {
            echo "   Publication : " . $book['date_publication'] . "\n";
        }

        echo "   Support  : " . $book['support'] . "\n";
        echo "   Statut   : " . ($book['statut'] ?? 'À lire') . "\n";

        if (!empty($book['genre'])) {
            echo "   Genre    : " . $book['genre'] . "\n";
        }
        if (!empty($book['tags'])) {
            echo "   Tags     : " . $book['tags'] . "\n";
        }
        if (!empty($book['note'])) {
            echo "   Note     : " . $book['note'] . "/5\n";
        }
        if (!empty($book['commentaire'])) {
            echo "   Commentaire :\n   " . str_replace("\n", "\n   ", $book['commentaire']) . "\n";
        }

        echo "\n" . str_repeat("─", 70) . "\n\n";
        $counter++;
    }

    echo "Fin de l'export - Exporté depuis Ma Collection de Livres\n";
    exit;
}

/**
 * Export HTML (page imprimable)
 */
function exportHTML($meta, $books, $filename) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.html"');

    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>' . htmlspecialchars($meta['nom']) . '</title>
    <style>
        @page { margin: 2cm; }
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .meta {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .book {
            page-break-inside: avoid;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
            padding-left: 15px;
        }
        .book-title {
            font-weight: bold;
            font-size: 14pt;
            color: #333;
            margin-bottom: 5px;
        }
        .book-author {
            font-style: italic;
            color: #666;
            margin-bottom: 10px;
        }
        .book-info {
            font-size: 10pt;
            color: #555;
            margin-top: 5px;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 9pt;
            margin-right: 5px;
        }
        .badge-support { background: #e3f2fd; color: #1976d2; }
        .badge-statut { background: #e8f5e9; color: #388e3c; }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 9pt;
            color: #999;
        }
    </style>
</head>
<body>';

    echo '<h1>📚 ' . htmlspecialchars($meta['nom']) . '</h1>';

    echo '<div class="meta">';
    if (!empty($meta['description'])) {
        echo '<p><strong>Description :</strong> ' . htmlspecialchars($meta['description']) . '</p>';
    }
    echo '<p><strong>Nombre de livres :</strong> ' . count($books) . '</p>';
    echo '<p><strong>Date d\'export :</strong> ' . date('d/m/Y à H:i') . '</p>';
    echo '</div>';

    $counter = 1;
    foreach ($books as $book) {
        echo '<div class="book">';
        echo '<div class="book-title">' . $counter . '. ' . htmlspecialchars($book['titre']) . '</div>';
        echo '<div class="book-author">par ' . htmlspecialchars($book['auteur']) . '</div>';

        echo '<div class="book-info">';
        echo '<span class="badge badge-support">' . htmlspecialchars($book['support']) . '</span>';
        echo '<span class="badge badge-statut">' . htmlspecialchars($book['statut'] ?? 'À lire') . '</span>';

        if (!empty($book['isbn'])) {
            echo '<br>ISBN : ' . htmlspecialchars($book['isbn']);
        }
        if (!empty($book['editeur'])) {
            echo ' • Éditeur : ' . htmlspecialchars($book['editeur']);
        }
        if (!empty($book['date_publication'])) {
            echo ' • ' . htmlspecialchars($book['date_publication']);
        }
        if (!empty($book['genre'])) {
            echo '<br>Genre : ' . htmlspecialchars($book['genre']);
        }
        if (!empty($book['tags'])) {
            echo ' • Tags : ' . htmlspecialchars($book['tags']);
        }
        if (!empty($book['note'])) {
            echo '<br>Note : ' . str_repeat('⭐', (int)$book['note']);
        }
        if (!empty($book['commentaire'])) {
            echo '<br><em>' . nl2br(htmlspecialchars($book['commentaire'])) . '</em>';
        }
        echo '</div>';

        echo '</div>';
        $counter++;
    }

    echo '<div class="footer">Exporté depuis Ma Collection de Livres - ' . date('d/m/Y') . '</div>';
    echo '</body></html>';

    exit;
}
