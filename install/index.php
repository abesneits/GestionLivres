<?php
/**
 * Assistant d'installation de « Ma Collection de Livres ».
 *
 * Étapes : 1. Bienvenue / prérequis  2. Base de données  3. Administrateur
 *          4. Récapitulatif et installation
 *
 * Refuse de s'exécuter si config.php existe déjà (supprimez-le pour réinstaller).
 */

session_start();

$racine = dirname(__DIR__);
$fichierConfig = $racine . '/config.php';

function h($valeur) {
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}

// --- Déjà installé : on n'autorise rien d'autre --------------------------------
$dejaInstalle = file_exists($fichierConfig);

// --- Jeton CSRF -----------------------------------------------------------------
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

/**
 * Teste réellement l'écriture dans un dossier (is_writable() est peu fiable
 * sous Windows, notamment dans les dossiers OneDrive).
 */
function dossierEcrivable($chemin) {
    if (!is_dir($chemin)) {
        return false;
    }
    $sonde = $chemin . '/.ecriture_' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($sonde, 'test') === false) {
        return false;
    }
    @unlink($sonde);
    return true;
}

/**
 * Vérifie les prérequis. Chaque entrée : [libellé, ok, bloquant, détail].
 */
function verifierPrerequis($racine) {
    $verifs = [];
    $verifs[] = ['PHP 8.0 ou supérieur', PHP_VERSION_ID >= 80000, true, 'Version détectée : ' . PHP_VERSION];
    foreach (['pdo_mysql' => 'Extension PDO MySQL', 'fileinfo' => 'Extension fileinfo (envoi des couvertures)', 'mbstring' => 'Extension mbstring', 'json' => 'Extension JSON'] as $ext => $libelle) {
        $verifs[] = [$libelle, extension_loaded($ext), true, extension_loaded($ext) ? 'Présente' : 'Manquante : à activer dans php.ini'];
    }
    $curl = extension_loaded('curl');
    $urlFopen = (bool) ini_get('allow_url_fopen');
    $verifs[] = ['Accès aux API externes (cURL ou allow_url_fopen)', $curl || $urlFopen, true,
        $curl ? 'cURL disponible' : ($urlFopen ? 'allow_url_fopen disponible (cURL recommandé)' : 'Ni cURL ni allow_url_fopen : la recherche par ISBN ne fonctionnera pas')];
    $verifs[] = ['Extension SimpleXML (recherche BnF)', extension_loaded('simplexml'), false, extension_loaded('simplexml') ? 'Présente' : 'Absente : la source BnF sera indisponible (facultatif)'];

    $racineOk = dossierEcrivable($racine);
    $verifs[] = ['Écriture dans le dossier du site (config.php)', $racineOk, true, $racineOk ? 'OK' : 'Donnez les droits d\'écriture au dossier ' . $racine];
    foreach (['uploads', 'logs'] as $dossier) {
        $chemin = $racine . '/' . $dossier;
        $ok = is_dir($chemin) ? dossierEcrivable($chemin) : $racineOk;
        $verifs[] = ['Dossier ' . $dossier . '/ inscriptible', $ok, true, $ok ? 'OK' : 'Donnez les droits d\'écriture à ' . $dossier . '/'];
    }
    return $verifs;
}

function prerequisOk(array $verifs) {
    foreach ($verifs as [, $ok, $bloquant]) {
        if (!$ok && $bloquant) {
            return false;
        }
    }
    return true;
}

/**
 * Connexion PDO de test. Retourne [PDO|null, message, baseCreee].
 */
function testerConnexion(array $bdd, $creerSiAbsente = true) {
    $dsn = 'mysql:host=' . $bdd['host'] . ';charset=utf8';
    try {
        $pdo = new PDO($dsn . ';dbname=' . $bdd['dbname'], $bdd['username'], $bdd['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        return [$pdo, 'Connexion réussie.', false];
    } catch (PDOException $e) {
        // 1049 = base inconnue : on tente de la créer
        if ($creerSiAbsente && (int) ($e->errorInfo[1] ?? 0) === 1049) {
            try {
                $pdo = new PDO($dsn, $bdd['username'], $bdd['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $nom = str_replace('`', '``', $bdd['dbname']);
                $pdo->exec("CREATE DATABASE `$nom` CHARACTER SET utf8 COLLATE utf8_general_ci");
                $pdo = new PDO($dsn . ';dbname=' . $bdd['dbname'], $bdd['username'], $bdd['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                return [$pdo, 'La base « ' . $bdd['dbname'] . ' » n\'existait pas : elle a été créée.', true];
            } catch (PDOException $e2) {
                return [null, 'La base « ' . $bdd['dbname'] . ' » n\'existe pas et n\'a pas pu être créée (' . $e2->getMessage() . '). Créez-la depuis votre hébergeur (phpMyAdmin) puis réessayez.', false];
            }
        }
        return [null, 'Connexion impossible : ' . $e->getMessage(), false];
    }
}

// --- Traitement ---------------------------------------------------------------
$verifs = verifierPrerequis($racine);
$etape = 1;
$erreurs = [];
$info = '';
$termine = false;
$_SESSION['install_bdd'] = $_SESSION['install_bdd'] ?? [];
$_SESSION['install_admin'] = $_SESSION['install_admin'] ?? [];

if (!$dejaInstalle && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jeton = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['install_csrf'], $jeton)) {
        $erreurs[] = 'Jeton de sécurité invalide. Rechargez la page et recommencez.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'etape1') {
            if (prerequisOk($verifs)) {
                $etape = 2;
            } else {
                $erreurs[] = 'Corrigez les prérequis marqués en rouge avant de continuer.';
            }
        } elseif ($action === 'etape2') {
            $etape = 2;
            $bdd = [
                'host' => trim($_POST['host'] ?? ''),
                'dbname' => trim($_POST['dbname'] ?? ''),
                'username' => trim($_POST['username'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
            ];
            $_SESSION['install_bdd'] = $bdd;
            if ($bdd['host'] === '' || $bdd['dbname'] === '' || $bdd['username'] === '') {
                $erreurs[] = 'L\'adresse, le nom de la base et l\'identifiant sont obligatoires.';
            } elseif (!preg_match('/^[A-Za-z0-9_.\-$]+$/', $bdd['dbname'])) {
                $erreurs[] = 'Le nom de la base ne doit contenir que des lettres, chiffres, « _ », « - », « . » ou « $ ».';
            } else {
                // Si la base n'existe pas, testerConnexion() tente de la créer.
                [$pdo, $message] = testerConnexion($bdd, true);
                if ($pdo === null) {
                    $erreurs[] = $message;
                } elseif (($_POST['tester_seulement'] ?? '') === '1') {
                    $info = $message;
                } else {
                    $etape = 3;
                }
            }
        } elseif ($action === 'etape3') {
            $etape = 3;
            $mdp = (string) ($_POST['admin_password'] ?? '');
            $mdp2 = (string) ($_POST['admin_password2'] ?? '');
            $cle = trim($_POST['google_key'] ?? '');
            $_SESSION['install_admin'] = ['google_key' => $cle];
            if (strlen($mdp) < 8) {
                $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
            } elseif ($mdp !== $mdp2) {
                $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
            } elseif (empty($_SESSION['install_bdd']['dbname'])) {
                $etape = 2;
                $erreurs[] = 'Les informations de base de données ont été perdues, saisissez-les à nouveau.';
            } else {
                $_SESSION['install_admin']['hash'] = password_hash($mdp, PASSWORD_BCRYPT, ['cost' => 12]);
                $etape = 4;
            }
        } elseif ($action === 'retour') {
            $etape = max(1, min(3, (int) ($_POST['vers'] ?? 1)));
        } elseif ($action === 'installer') {
            $etape = 4;
            $bdd = $_SESSION['install_bdd'];
            $admin = $_SESSION['install_admin'];
            if (empty($bdd['dbname']) || empty($admin['hash'])) {
                $etape = 2;
                $erreurs[] = 'Session expirée : reprenez l\'installation depuis le début.';
            } elseif (!prerequisOk($verifs)) {
                $etape = 1;
                $erreurs[] = 'Un prérequis n\'est plus satisfait.';
            } else {
                [$pdo, $message] = testerConnexion($bdd, true);
                if ($pdo === null) {
                    $erreurs[] = $message;
                } else {
                    try {
                        foreach (['uploads', 'logs'] as $dossier) {
                            if (!is_dir($racine . '/' . $dossier) && !mkdir($racine . '/' . $dossier, 0755, true)) {
                                throw new RuntimeException('Impossible de créer le dossier ' . $dossier . '/.');
                            }
                        }

                        // Création / mise à niveau du schéma (idempotent)
                        require_once $racine . '/BookManager.php';
                        $gestionnaire = new BookManager($bdd['host'], $bdd['dbname'], $bdd['username'], $bdd['password'], $admin['google_key'] ?: null);
                        ob_start();
                        $gestionnaire->ensureSchema();
                        ob_end_clean();

                        $config = [
                            'host' => $bdd['host'],
                            'dbname' => $bdd['dbname'],
                            'username' => $bdd['username'],
                            'password' => $bdd['password'],
                            'charset' => 'utf8',
                            'google_books_api_key' => $admin['google_key'],
                            'admin_password_hash' => $admin['hash'],
                            'session_salt' => bin2hex(random_bytes(32)),
                        ];
                        $contenu = "<?php\n// Fichier généré par l'assistant d'installation le " . date('d/m/Y H:i') . ".\n// Ne le publiez jamais (il contient vos identifiants).\n\nreturn " . var_export($config, true) . ";\n";
                        if (file_put_contents($fichierConfig, $contenu, LOCK_EX) === false) {
                            throw new RuntimeException('Impossible d\'écrire config.php dans ' . $racine . '.');
                        }
                        @chmod($fichierConfig, 0640);

                        unset($_SESSION['install_bdd'], $_SESSION['install_admin']);
                        $termine = true;
                        $dejaInstalle = true;
                    } catch (Throwable $e) {
                        $erreurs[] = 'Échec de l\'installation : ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

$bdd = $_SESSION['install_bdd'] ?? [];
$admin = $_SESSION['install_admin'] ?? [];
$titresEtapes = [1 => 'Bienvenue', 2 => 'Base de données', 3 => 'Administrateur', 4 => 'Installation'];
$csrf = $_SESSION['install_csrf'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - Ma Collection de Livres</title>
    <link rel="icon" href="../favicon.svg" type="image/svg+xml">
    <style>
        :root { --accent: #667eea; --accent2: #764ba2; --ok: #1f8a4c; --ko: #c0392b; --warn: #b7791f; --texte: #2d3748; --gris: #718096; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 24px 16px; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: var(--texte); background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%); }
        .carte { max-width: 720px; margin: 0 auto; background: #fff; border-radius: 14px; box-shadow: 0 12px 40px rgba(0,0,0,.25); overflow: hidden; }
        .entete { padding: 28px 32px 20px; border-bottom: 1px solid #edf2f7; }
        .entete h1 { margin: 0 0 4px; font-size: 1.6rem; }
        .entete p { margin: 0; color: var(--gris); }
        .etapes { display: flex; gap: 8px; padding: 16px 32px; background: #f7fafc; border-bottom: 1px solid #edf2f7; flex-wrap: wrap; }
        .etape { flex: 1; min-width: 120px; padding: 8px 10px; border-radius: 8px; font-size: .85rem; color: var(--gris); background: #edf2f7; text-align: center; }
        .etape.actuelle { background: var(--accent); color: #fff; font-weight: 600; }
        .etape.faite { background: #c6f6d5; color: #22543d; }
        .contenu { padding: 28px 32px 32px; }
        h2 { margin-top: 0; }
        ul.fonctions { padding-left: 20px; line-height: 1.7; }
        table.prerequis { width: 100%; border-collapse: collapse; margin: 12px 0 20px; font-size: .92rem; }
        .prerequis td { padding: 8px 6px; border-bottom: 1px solid #edf2f7; vertical-align: top; }
        .prerequis td:first-child { width: 28px; font-weight: bold; }
        .ok { color: var(--ok); } .ko { color: var(--ko); } .warn { color: var(--warn); }
        .prerequis small { display: block; color: var(--gris); }
        label { display: block; margin: 16px 0 6px; font-weight: 600; }
        label small { font-weight: 400; color: var(--gris); }
        input[type=text], input[type=password] { width: 100%; padding: 11px 12px; border: 1px solid #cbd5e0; border-radius: 8px; font-size: 1rem; }
        input:focus { outline: 2px solid var(--accent); border-color: transparent; }
        .actions { display: flex; gap: 10px; margin-top: 24px; flex-wrap: wrap; }
        button, .bouton { padding: 11px 20px; border: 0; border-radius: 8px; font-size: 1rem; cursor: pointer; background: var(--accent); color: #fff; text-decoration: none; display: inline-block; }
        button.secondaire { background: #e2e8f0; color: var(--texte); }
        button:disabled { opacity: .5; cursor: not-allowed; }
        .alerte { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
        .alerte.erreur { background: #fed7d7; color: #742a2a; }
        .alerte.info { background: #c6f6d5; color: #22543d; }
        .alerte.attention { background: #fefcbf; color: #744210; }
        dl.recap { display: grid; grid-template-columns: 180px 1fr; gap: 8px 12px; }
        dl.recap dt { color: var(--gris); } dl.recap dd { margin: 0; word-break: break-all; }
        code { background: #edf2f7; padding: 1px 6px; border-radius: 4px; }
        @media (max-width: 520px) { .entete, .contenu, .etapes { padding-left: 18px; padding-right: 18px; } dl.recap { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="carte">
    <div class="entete">
        <h1>📚 Ma Collection de Livres</h1>
        <p>Assistant d'installation</p>
    </div>

<?php if ($dejaInstalle && !$termine): ?>
    <div class="contenu">
        <h2>Application déjà installée</h2>
        <div class="alerte attention">Un fichier <code>config.php</code> existe déjà : l'installation est verrouillée.</div>
        <p>Pour réinstaller, supprimez <code>config.php</code> sur le serveur puis rechargez cette page. Pensez aussi à supprimer le dossier <code>install/</code> maintenant que l'application fonctionne.</p>
        <div class="actions"><a class="bouton" href="../login.php">Aller à la connexion</a></div>
    </div>

<?php elseif ($termine): ?>
    <div class="contenu">
        <h2>🎉 Installation terminée</h2>
        <div class="alerte info">La base est prête et <code>config.php</code> a été créé.</div>
        <p>Vous pouvez maintenant vous connecter avec le mot de passe administrateur que vous venez de choisir.</p>
        <div class="alerte attention"><strong>Important :</strong> supprimez le dossier <code>install/</code> de votre serveur. L'assistant est déjà verrouillé, mais il n'a plus d'utilité.</div>
        <div class="actions"><a class="bouton" href="../login.php">Se connecter →</a></div>
    </div>

<?php else: ?>
    <div class="etapes">
        <?php foreach ($titresEtapes as $n => $titre): ?>
            <div class="etape <?= $n === $etape ? 'actuelle' : ($n < $etape ? 'faite' : '') ?>"><?= $n ?>. <?= h($titre) ?></div>
        <?php endforeach; ?>
    </div>
    <div class="contenu">
        <?php foreach ($erreurs as $erreur): ?><div class="alerte erreur"><?= h($erreur) ?></div><?php endforeach; ?>
        <?php if ($info): ?><div class="alerte info"><?= h($info) ?></div><?php endif; ?>

        <?php if ($etape === 1): ?>
            <h2>Bienvenue</h2>
            <p>Cette application web (PHP + MySQL) vous permet de gérer votre collection personnelle de livres, bandes dessinées et mangas :</p>
            <ul class="fonctions">
                <li><strong>Ajout par ISBN</strong> avec récupération automatique du titre, de l'auteur et de la couverture (Google Books, Open Library, BnF)</li>
                <li><strong>Listes de lecture</strong> ordonnables par glisser-déposer</li>
                <li><strong>Fiches auteurs</strong> avec biographie (Wikipédia)</li>
                <li><strong>Statistiques</strong>, tags, statuts de lecture, notes personnelles</li>
                <li><strong>Recherche avancée</strong> et <strong>export</strong> (CSV, Excel, JSON, TXT, HTML)</li>
            </ul>
            <p>L'installation prend une minute : vérification de votre serveur, connexion à la base de données, puis choix du mot de passe de connexion.</p>

            <h3>Vérification du serveur</h3>
            <table class="prerequis">
                <?php foreach ($verifs as [$libelle, $ok, $bloquant, $detail]): ?>
                    <tr>
                        <td class="<?= $ok ? 'ok' : ($bloquant ? 'ko' : 'warn') ?>"><?= $ok ? '✔' : ($bloquant ? '✘' : '!') ?></td>
                        <td><?= h($libelle) ?><small><?= h($detail) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="etape1">
                <div class="actions"><button type="submit" <?= prerequisOk($verifs) ? '' : 'disabled' ?>>Commencer →</button></div>
            </form>

        <?php elseif ($etape === 2): ?>
            <h2>Base de données</h2>
            <p>Saisissez les informations de connexion MySQL / MariaDB, fournies par votre hébergeur ou votre serveur local. La base peut être vide : les tables seront créées automatiquement.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="etape2">
                <label for="host">Adresse du serveur <small>(souvent <code>localhost</code>)</small></label>
                <input type="text" id="host" name="host" required value="<?= h($bdd['host'] ?? 'localhost') ?>">
                <label for="dbname">Nom de la base de données</label>
                <input type="text" id="dbname" name="dbname" required value="<?= h($bdd['dbname'] ?? '') ?>" placeholder="ma_bibliotheque">
                <label for="username">Identifiant</label>
                <input type="text" id="username" name="username" required autocomplete="off" value="<?= h($bdd['username'] ?? '') ?>">
                <label for="password">Mot de passe <small>(peut être vide en local)</small></label>
                <input type="password" id="password" name="password" autocomplete="new-password" value="<?= h($bdd['password'] ?? '') ?>">
                <div class="actions">
                    <button type="submit" name="tester_seulement" value="1" class="secondaire">Tester la connexion</button>
                    <button type="submit">Continuer →</button>
                </div>
            </form>

        <?php elseif ($etape === 3): ?>
            <h2>Compte administrateur</h2>
            <p>L'application est protégée par un mot de passe unique. Choisissez-le maintenant (8 caractères minimum, idéalement avec majuscules, chiffres et symboles).</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="etape3">
                <label for="admin_password">Mot de passe de connexion</label>
                <input type="password" id="admin_password" name="admin_password" required minlength="8" autocomplete="new-password">
                <label for="admin_password2">Confirmation</label>
                <input type="password" id="admin_password2" name="admin_password2" required minlength="8" autocomplete="new-password">
                <label for="google_key">Clé API Google Books <small>(facultatif — <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">comment l'obtenir</a>)</small></label>
                <input type="text" id="google_key" name="google_key" autocomplete="off" value="<?= h($admin['google_key'] ?? '') ?>">
                <div class="actions">
                    <button type="submit" form="retour2" class="secondaire" formnovalidate>← Retour</button>
                    <button type="submit">Continuer →</button>
                </div>
            </form>
            <form method="post" id="retour2">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="retour">
                <input type="hidden" name="vers" value="2">
            </form>

        <?php elseif ($etape === 4): ?>
            <h2>Récapitulatif</h2>
            <dl class="recap">
                <dt>Serveur</dt><dd><?= h($bdd['host'] ?? '') ?></dd>
                <dt>Base de données</dt><dd><?= h($bdd['dbname'] ?? '') ?></dd>
                <dt>Identifiant</dt><dd><?= h($bdd['username'] ?? '') ?></dd>
                <dt>Mot de passe MySQL</dt><dd><?= ($bdd['password'] ?? '') === '' ? '(vide)' : '••••••••' ?></dd>
                <dt>Mot de passe admin</dt><dd>••••••••</dd>
                <dt>Clé Google Books</dt><dd><?= ($admin['google_key'] ?? '') === '' ? '(aucune)' : h($admin['google_key']) ?></dd>
            </dl>
            <p>L'installation va créer les tables de la base, les dossiers <code>uploads/</code> et <code>logs/</code>, puis écrire le fichier <code>config.php</code>.</p>
            <div class="actions">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="retour">
                    <input type="hidden" name="vers" value="3">
                    <button type="submit" class="secondaire">← Retour</button>
                </form>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="installer">
                    <button type="submit">Installer</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
</body>
</html>
