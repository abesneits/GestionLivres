# Architecture

Document destiné à qui veut comprendre ou modifier le code. Pour l'installation et l'usage, voir le [README](../README.md).

Le projet est en PHP (8.0+) et MySQL/MariaDB, sans framework, sans Composer et sans étape de compilation. Le code, les noms et les commentaires sont en français.

## Vue d'ensemble

```
Navigateur ──► page (index.php, ajouter.php, …)      « contrôleur »
                 │  charge includes/bootstrap.php : session, connexion, $bookManager
                 │  traite le formulaire, appelle $bookManager, prépare les variables
                 ▼
               require 'views/<page>.php'             « vue »
                 │  uniquement du HTML, aucun traitement
                 ▼
               BookManager  (façade : aucune logique, une méthode = un appel délégué)
                 ▼
               src/*.php    (classes thématiques : tout le SQL et les appels aux API externes)
                 ▼
               MySQL / MariaDB
```

La couche de données (le modèle) est isolée, et chaque page à la racine est désormais un contrôleur fin : elle traite le formulaire (validation, upload, appels à `$bookManager`), prépare les variables nécessaires à l'affichage, puis se termine par `require 'views/<page>.php';`. La vue correspondante, dans `views/`, ne contient que du HTML et l'affichage de ces variables — elle n'écrit jamais dans la base ni ne traite de `$_POST`. Quelques vues appellent tout de même `$bookManager` directement, mais uniquement pour des données d'affichage en lecture seule (ex. la liste des séries ou des types de support pour remplir un menu déroulant).

## Démarrage d'une page

Toute page authentifiée commence par `require_once 'includes/bootstrap.php'`, qui :

1. démarre la session ;
2. charge `includes/auth.php` et appelle `requireAuth()` (redirige vers `login.php` si la personne n'est pas connectée, ou vers `install/` si `config.php` n'existe pas) ;
3. lit `config.php` et crée l'objet `$bookManager` ;
4. appelle `$bookManager->ensureSchema()` pour créer ou mettre à jour les tables.

Le résultat de `ensureSchema()` est absorbé (`ob_start()` / `ob_end_clean()`) : il peut afficher des messages de migration, or plusieurs pages envoient ensuite des en-têtes HTTP (export CSV, réponses JSON, téléchargements) qui échoueraient si un seul caractère avait déjà été écrit.

`login.php` et `logout.php` n'utilisent pas `bootstrap.php`.

## La couche de données

`BookManager.php` crée une seule connexion PDO et la partage avec les classes de `src/`. Chaque méthode publique de `BookManager` se contente d'appeler la classe responsable.

| Classe | Responsabilité |
|---|---|
| `LivreRepository` | Livres : ajout, modification, suppression, recherche simple et avancée, édition en masse, séries, pagination, couvertures personnalisées. |
| `ListeRepository` | Listes de lecture (`listes_lecture`, `livres_listes`) : contenu et ordre. |
| `AuteurRepository` | Auteurs (`auteurs`, `auteurs_doublons_ignores`) : biographies, photos, regroupement, doublons, fusion. |
| `TagRepository` | Tags (chaîne CSV dans `livres.tags`) et catalogue `tags_catalogue`. |
| `SupportRepository` | Types de support, stockés dans l'`ENUM` de `livres.support`. |
| `IsbnLookup` | Recherche par ISBN auprès de Google Books, Open Library, BnF. N'utilise pas la base. |
| `Stats` | Statistiques. |
| `Schema` | Création et migration des tables, index. |
| `Maintenance` | Sauvegarde et restauration JSON, optimisation, diagnostic de la base et de `uploads/`. |

Les classes de `src/` reçoivent leur connexion (et leurs dépendances) dans le constructeur, sans variable globale.

## Le schéma de la base

Le modèle est décrit dans [`schema.sql`](../schema.sql). Il y a 6 tables :

- `livres` : la collection ;
- `listes_lecture` et `livres_listes` : les listes, en relation plusieurs-à-plusieurs, avec un ordre, et suppression en cascade ;
- `auteurs` : cache des biographies. **Pas de clé étrangère** vers `livres` : le lien se fait par le nom, car `livres.auteur` est un texte libre qui peut contenir plusieurs noms séparés par des virgules ;
- `auteurs_doublons_ignores` : mémorise les faux doublons d'auteurs ;
- `tags_catalogue` : suggestions de tags.

Le schéma est créé et mis à jour **par l'application elle-même**, dans `src/Schema.php` : des `CREATE TABLE IF NOT EXISTS`, puis des `ALTER TABLE … ADD COLUMN` gardés par une vérification préalable dans `information_schema`. Ces opérations sont rejouables sans effet de bord. `schema.sql` est un second chemin (création à la main) : **les deux doivent rester synchronisés**.

Les valeurs des `ENUM` `support` et `statut` ne sont pas figées : la page Outils permet d'ajouter et de renommer des supports, ce qui réécrit la définition de l'`ENUM` dans la base.

## Deux chemins de recherche

Il existe deux implémentations distinctes, qui ne sont pas interchangeables :

- **Recherche simple** : `sanitizeSearchParams()` puis `getAllBooks()` / `countBooks()`. Un champ texte plus les filtres support, tag, statut, série. Utilisée par `index.php`.
- **Recherche avancée** : `sanitizeAdvancedSearchParams()` puis `searchBooksAdvanced()` / `countBooksAdvanced()`. Ajoute les tags en ET/OU avec correspondance exacte, les dates, « sans couverture » / « sans note » et le tri. Utilisée par `outils.php` (édition en masse) et `export.php`.

Les deux fonctions de nettoyage sont dans `includes/utils.php`.

## Export

`includes/export_functions.php` contient `exportCSV()`, `exportExcel()`, `exportJSON()`, `exportTXT()` et `exportHTML()`. Elles reçoivent des métadonnées (`nom`, `description`) et un tableau de livres, sans savoir d'où viennent les données : elles servent à la fois à `export_liste.php` (une liste) et à `export.php` (résultat d'une recherche avancée). `export.php` peut aussi renvoyer son fragment de résultats en JSON (`ajax=1`) pour se rafraîchir sans recharger la page, et mémorise la sélection manuelle dans le navigateur (`localStorage`).

## Sécurité

Application à un seul utilisateur : le hash bcrypt du mot de passe et le sel de session sont générés par l'assistant d'installation et stockés dans `config.php` (jamais versionné). Les règles suivies dans tout le code :

- **CSRF** : chaque formulaire qui modifie des données porte un jeton (`generateCSRFToken()`), vérifié par `validateCSRFToken()` avant l'action.
- **XSS** : toute donnée saisie est affichée via `h()` (un `htmlspecialchars`).
- **SQL** : uniquement des requêtes préparées PDO.
- **Envois de fichiers** : taille (5 Mo), type MIME réel (`finfo`) et extension vérifiés ; noms générés ; le dossier `uploads/` interdit l'exécution de scripts (`.htaccess`).
- **Sessions** : expiration après 1 h, verrouillage après 5 échecs de connexion.
- Messages « flash » après une action : `redirectWithMessage()` / `getFlashMessage()`, pour éviter le double envoi d'un formulaire.

## Ajouter une fonctionnalité

1. **Une nouvelle donnée à stocker** : ajoutez la colonne dans `src/Schema.php` (migration rejouable) **et** dans `schema.sql`.
2. **Une nouvelle requête** : écrivez la méthode dans la classe de `src/` concernée, puis ajoutez dans `BookManager` une méthode d'une ligne qui l'appelle.
3. **Une nouvelle page** : commencez par `require_once 'includes/bootstrap.php'`, protégez les formulaires avec le jeton CSRF, n'écrivez pas de SQL dans la page, et terminez le contrôleur par `require 'views/<page>.php';`. La vue affiche avec `h()` et ne fait aucun traitement.

## Vérifier une modification

Il n'y a pas de suite de tests. La vérification pratique est :

```bash
php -l fichier.php          # contrôle de syntaxe
```

puis l'essai de la page dans le navigateur, ou un petit script PHP jetable qui charge `BookManager.php` et appelle la méthode voulue sur une base de test.
