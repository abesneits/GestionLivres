# 📚 Ma Collection de Livres

Application web personnelle pour gérer sa collection de livres, bandes dessinées et mangas. PHP + MySQL, sans framework, sans étape de compilation, entièrement en français.

## Fonctionnalités

- **Ajout par ISBN** : récupération automatique du titre, de l'auteur, de la description et de la couverture via Google Books, avec repli sur Open Library puis sur la BnF
- **Ajout manuel** et envoi de couvertures personnalisées
- **Listes de lecture** réordonnables par glisser-déposer
- **Fiches auteurs** avec biographie issue de Wikipédia, détection et fusion des doublons
- **Tags**, **statuts de lecture** (À lire, En cours, Lu, Abandonné), notes personnelles
- **Statistiques** détaillées de la collection
- **Recherche avancée** (texte, support, statut, tags ET/OU, dates, sans couverture…)
- **Export** de toute la collection ou d'une liste : CSV, Excel, JSON, TXT, HTML
- **Outils** : édition en masse, gestion des types de supports et du catalogue de tags

## Prérequis

- PHP **8.0 ou supérieur** avec les extensions `pdo_mysql`, `fileinfo`, `mbstring`, `json` (et de préférence `curl` et `simplexml`)
- MySQL ou MariaDB
- Un serveur web (Apache recommandé ; Nginx fonctionne mais les fichiers `.htaccess` ne sont pas lus, voir *Sécurité*)

## Installation

1. **Envoyez les fichiers** du projet dans le dossier web de votre serveur (ou clonez le dépôt).
2. **Créez une base de données** MySQL vide (facultatif : l'assistant tente de la créer si votre utilisateur en a le droit).
3. **Ouvrez le site** dans votre navigateur : vous êtes automatiquement redirigé vers l'assistant d'installation (`/install/`).
4. **Suivez les 4 étapes** :
   1. Présentation et vérification du serveur
   2. Adresse de la base de données, nom, identifiant et mot de passe MySQL
   3. Choix du mot de passe de connexion à l'application (+ clé Google Books facultative)
   4. Récapitulatif et installation
5. **Connectez-vous** avec le mot de passe choisi, puis **supprimez le dossier `install/`**.

L'assistant crée les tables, les dossiers `uploads/` et `logs/`, et écrit le fichier `config.php`. Pour réinstaller, supprimez `config.php`.

### Installation manuelle (sans assistant)

Copiez `config.example.php` en `config.php`, renseignez la base, générez le hash du mot de passe et le sel de session :

```bash
php -r "echo password_hash('VotreMotDePasse', PASSWORD_BCRYPT, ['cost' => 12]);"
php -r "echo bin2hex(random_bytes(32));"
```

Les tables sont créées automatiquement au premier chargement d'une page.

### Essayer en local

```bash
php -S localhost:8990 -t .
```

puis ouvrez <http://localhost:8990>.

## Clé API Google Books (facultative)

Sans clé, la recherche par ISBN partage un quota gratuit très limité. Pour en obtenir une : [console Google Cloud](https://console.cloud.google.com/) → créer un projet → activer « Books API » → Identifiants → Clé API. Renseignez-la à l'installation ou dans `config.php` (`google_books_api_key`).

## Sécurité

- `config.php` contient vos identifiants : il est ignoré par git et bloqué par `.htaccess`. Ne le publiez jamais.
- **Supprimez `install/`** après l'installation.
- Sous Nginx (ou si `.htaccess` est désactivé), interdisez l'accès web à `config.php`, `logs/` et l'exécution de PHP dans `uploads/`.
- Utilisez HTTPS. Les sessions expirent après 1 h d'inactivité, 5 échecs de connexion bloquent l'accès 5 minutes.
- Application conçue pour **un seul utilisateur** (un mot de passe unique).

## Licence

[MIT](LICENSE)
