<?php
/**
 * Liste d'achats imprimable (affichée dans le navigateur, pas téléchargée).
 * ?tri=priorite|titre|auteur|date  ?print=1 ouvre directement la boîte d'impression.
 */
require_once 'includes/bootstrap.php';

$tri = $_GET['tri'] ?? 'priorite';
$achats = $bookManager->getAchats($tri);

$libelles = [1 => 'Priorité haute', 2 => 'Priorité normale', 3 => 'Priorité basse'];
$groupes = [];
$total = 0.0;
foreach ($achats as $a) {
    $groupes[(int)$a['priorite']][] = $a;
    $total += (float)$a['prix_estime'];
}
ksort($groupes);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Achats à effectuer - <?= date('d/m/Y') ?></title>
<style>
    @page { margin: 1.5cm; }
    body { font-family: Arial, sans-serif; color: #000; max-width: 800px; margin: 20px auto; padding: 0 15px; }
    h1 { margin-bottom: 0; }
    .meta { color: #555; margin-bottom: 20px; }
    h2 { font-size: 1.1em; border-bottom: 2px solid #000; padding-bottom: 3px; margin-top: 25px; }
    table { width: 100%; border-collapse: collapse; }
    tr { page-break-inside: avoid; }
    td { padding: 6px 4px; border-bottom: 1px solid #ccc; vertical-align: top; font-size: 0.95em; }
    td.case { width: 22px; font-size: 1.3em; }
    td.prix { text-align: right; white-space: nowrap; }
    .petit { color: #555; font-size: 0.85em; }
    .total { text-align: right; font-weight: bold; margin-top: 15px; }
    .actions { margin-bottom: 15px; }
    .actions button { padding: 8px 14px; cursor: pointer; }
    @media print { .actions { display: none; } body { margin: 0; max-width: none; } }
</style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">🖨️ Imprimer</button></div>
    <h1>🛒 Achats à effectuer</h1>
    <div class="meta"><?= count($achats) ?> livre<?= count($achats) > 1 ? 's' : '' ?> — <?= date('d/m/Y') ?></div>

    <?php if (empty($achats)): ?>
        <p>Aucun achat prévu.</p>
    <?php endif; ?>

    <?php foreach ($groupes as $prio => $livres): ?>
        <h2><?= h($libelles[$prio] ?? 'Autres') ?></h2>
        <table>
            <?php foreach ($livres as $a): ?>
                <tr>
                    <td class="case">☐</td>
                    <td>
                        <strong><?= h($a['titre']) ?></strong><?= $a['serie'] ? ' — ' . h($a['serie']) . ($a['tome'] !== null ? ' T' . (int)$a['tome'] : '') : '' ?><br>
                        <?= h($a['auteur'] ?? '') ?>
                        <div class="petit">
                            <?= h($a['support']) ?> · <?= h($a['type_livre']) ?>
                            <?php if ($a['isbn']): ?> · ISBN <?= h($a['isbn']) ?><?php endif; ?>
                            <?php if ($a['lieu_achat']): ?> · <?= h($a['lieu_achat']) ?><?php endif; ?>
                            <?php if ($a['note']): ?><br>📝 <?= h($a['note']) ?><?php endif; ?>
                        </div>
                    </td>
                    <td class="prix"><?= $a['prix_estime'] !== null ? number_format((float)$a['prix_estime'], 2, ',', ' ') . ' €' : '' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endforeach; ?>

    <?php if ($total > 0): ?>
        <div class="total">Total estimé : <?= number_format($total, 2, ',', ' ') ?> €</div>
    <?php endif; ?>

    <?php if (isset($_GET['print'])): ?>
        <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php endif; ?>
</body>
</html>
