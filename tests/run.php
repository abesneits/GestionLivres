<?php
/**
 * Petits tests automatiques, sans dépendance (pas de PHPUnit ni de Composer) et sans base de données.
 * Ils portent sur les fonctions « pures » : nettoyage des paramètres, redirection, chemins de fichiers,
 * découpage des auteurs et des tags, échappement des messages.
 *
 * Lancer depuis la racine du projet :  php tests/run.php
 * Code de sortie 0 si tout passe, 1 sinon.
 */

// Jamais exécutés depuis un navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit();
}

chdir(__DIR__ . '/..');

// includes/auth.php accepte l'absence de config.php en ligne de commande : aucune connexion n'est ouverte
require_once 'includes/auth.php';
require_once 'includes/utils.php';
require_once 'src/Fichiers.php';
require_once 'src/TagRepository.php';
require_once 'src/AuteurRepository.php';

$total = 0;
$echecs = [];

function verifie($nom, $obtenu, $attendu) {
    global $total, $echecs;
    $total++;
    if ($obtenu !== $attendu) {
        $echecs[] = $nom . "\n    attendu : " . var_export($attendu, true) . "\n    obtenu  : " . var_export($obtenu, true);
    }
}

/** Appelle une méthode privée sans construire l'objet (donc sans base de données). */
function appellePrivee($classe, $methode, ...$args) {
    $objet = (new ReflectionClass($classe))->newInstanceWithoutConstructor();
    $m = new ReflectionMethod($classe, $methode);
    $m->setAccessible(true);
    return $m->invoke($objet, ...$args);
}

// --- safeRedirectTarget : seules les adresses du site sont acceptées
verifie('redirection : chemin du site', safeRedirectTarget('/index.php?page=2'), '/index.php?page=2');
verifie('redirection : autre site (//)', safeRedirectTarget('//evil.example'), 'index.php');
verifie('redirection : autre site (/\)', safeRedirectTarget('/\evil.example'), 'index.php');
verifie('redirection : URL absolue', safeRedirectTarget('https://evil.example'), 'index.php');
verifie('redirection : retour à la ligne', safeRedirectTarget("/ok\r\nSet-Cookie: x=1"), 'index.php');
verifie('redirection : valeur absente', safeRedirectTarget(null), 'index.php');

// --- sanitizeSearchParams : liste blanche et nettoyage
$p = sanitizeSearchParams(['search' => '  <b>Dune</b> ', 'filter' => 'Inconnu', 'type' => 'Numérique', 'statut' => 'Lu', 'page' => '-4', 'view' => 'x']);
verifie('recherche : texte nettoyé', $p['search'], 'Dune');
verifie('recherche : support inconnu écarté', $p['filter'], '');
verifie('recherche : type valide', $p['type'], 'Numérique');
verifie('recherche : statut valide', $p['statut'], 'Lu');
verifie('recherche : page minimale 1', $p['page'], 1);
verifie('recherche : vue par défaut', $p['view'], 'grid');

// --- sanitizeAdvancedSearchParams
$a = sanitizeAdvancedSearchParams([
    'date_from' => '2024-02-30', 'date_to' => '2024-02-29', 'tags' => ['  roman ', '', '<i>x</i>'],
    'tags_mode' => 'OR', 'sort' => 'DROP TABLE', 'sans_note' => '1', 'support' => 'Manga',
]);
verifie('avancée : date impossible écartée', $a['date_from'], '');
verifie('avancée : date valide (bissextile)', $a['date_to'], '2024-02-29');
verifie('avancée : tags nettoyés', $a['tags'], ['roman', 'x']);
verifie('avancée : mode inconnu -> and', $a['tags_mode'], 'and');
verifie('avancée : tri hors liste -> défaut', $a['sort'], 'titre_asc');
verifie('avancée : case à cocher', $a['sans_note'], true);
verifie('avancée : support de la liste', $a['support'], 'Manga');

// --- échappement des messages
verifie('message : texte échappé', showMessage('<script>x</script>', 'error'), '<div class="message error">&lt;script&gt;x&lt;/script&gt;</div>');
verifie('message : balisage voulu conservé', showMessage('<strong>ok</strong>', 'success', true), '<div class="message success"><strong>ok</strong></div>');
verifie('message : vide', showMessage('', 'info'), '');
verifie('erreur : message métier conservé', messageErreur(new Exception('Le titre est obligatoire.')), 'Le titre est obligatoire.');

// --- chemins de fichiers
verifie('chemin : couverture valide', Fichiers::estCheminUpload('uploads/couverture_11_1758701797.jpg'), true);
verifie('chemin : sous-dossier valide', Fichiers::estCheminUpload('uploads/ebooks/ebook_1_abc.pdf'), true);
verifie('chemin : remontée refusée', Fichiers::estCheminUpload('uploads/../config.php'), false);
verifie('chemin : remontée cachée refusée', Fichiers::estCheminUpload('uploads/ebooks/../../config.php'), false);
verifie('chemin : hors uploads refusé', Fichiers::estCheminUpload('config.php'), false);
verifie('chemin : absolu refusé', Fichiers::estCheminUpload('/etc/passwd'), false);
verifie('chemin : .htaccess refusé', Fichiers::estCheminUpload('uploads/.htaccess'), false);
verifie('chemin sûr : config.php refusé', Fichiers::cheminSur('config.php'), null);
verifie('chemin sûr : .htaccess refusé', Fichiers::cheminSur('uploads/.htaccess'), null);
verifie('chemin sûr : fichier inexistant', Fichiers::cheminSur('uploads/n_existe_pas.jpg'), null);
verifie('suppression hors uploads : refusée', Fichiers::supprimer('includes/utils.php'), false);
verifie('suppression hors uploads : fichier intact', is_file('includes/utils.php'), true);

// --- découpage « Nom1, Nom2 (Rôle) »
$noms = function ($texte) {
    return appellePrivee('AuteurRepository', 'parseAuthorNames', $texte);
};
verifie('auteurs : deux noms', array_column($noms('Albert Camus, Jean Giono'), 'nom'), ['Albert Camus', 'Jean Giono']);
verifie('auteurs : rôle entre parenthèses', $noms('Hergé (Dessinateur)'), [['nom' => 'Hergé', 'roles' => ['Dessinateur']]]);
verifie('auteurs : deux rôles', $noms('Uderzo (Scénariste, Dessinateur)')[0]['roles'], ['Scénariste', 'Dessinateur']);
verifie('auteurs : parenthèses qui ne sont pas un rôle', $noms('Claire Marc (médiatrice)')[0]['nom'], 'Claire Marc (médiatrice)');
verifie('auteurs : valeur de remplacement écartée', $noms('Auteur non disponible'), []);
verifie('auteurs : morceaux vides écartés', array_column($noms(', ,Zola,'), 'nom'), ['Zola']);

// --- découpage des tags
$tags = (new ReflectionClass('TagRepository'))->newInstanceWithoutConstructor();
verifie('tags : découpe et nettoie', $tags->splitTagsExact(' sf, , policier ,0'), ['sf', 'policier', '0']);
verifie('tags : null', $tags->splitTagsExact(null), []);

// --- bilan
echo $total - count($echecs), " / $total tests réussis\n";
foreach ($echecs as $e) {
    echo "ÉCHEC : $e\n";
}
exit($echecs ? 1 : 0);
