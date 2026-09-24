# Architecture

Document destiné à qui veut comprendre ou modifier le code. Pour l'installation et l'usage, voir le [README](../README.md).

Le projet est en PHP (8.0+) et MySQL/MariaDB, sans framework, sans Composer et sans étape de compilation. Le code, les noms et les commentaires sont en français.

Le document suit trois niveaux :

1. **l'architecture fonctionnelle** : ce que fait l'application, découpé en fonctions métier qui s'appuient sur des services transverses ;
2. **l'architecture logicielle** : comment ces fonctions sont réparties entre modèle, vue et contrôleur, et comment les composants interagissent ;
3. **ce qui entoure l'application** : installation, documentation, vérification.

## Architecture fonctionnelle

### Vue d'ensemble

```
 FONCTIONS MÉTIER (ce que l'utilisateur voit dans le menu)
 ─────────────────────────────────────────────────────────────────
   F1 Gestion des fiches livres     F4 Statistiques
   F2 Listes de lecture             F5 Export
   F3 Auteurs                       F6 Paramétrage et maintenance
                                │
                                │  s'appuient sur
                                ▼
 SERVICES TRANSVERSES (partagés, jamais appelés seuls)
 ─────────────────────────────────────────────────────────────────
   S1 Recherche (simple / avancée)  S5 Gestion des fichiers envoyés
   S2 Édition en masse              S6 Sécurité
   S3 Import depuis sites externes  S7 Persistance et schéma
   S4 Référentiels (tags, supports, formats numériques)
```

### Fonctions métier

| Fonction | Rôle | Services utilisés |
|---|---|---|
| **F1 Gestion des fiches livres** | Ajouter un livre (par ISBN ou à la main), le consulter, le modifier, le supprimer ; papier ou numérique ; séries et tomes. | S1 (simple), S3 (ISBN), S4, S5 (couverture, fichier numérique), S6, S7 |
| **F2 Listes de lecture** | Créer, modifier, supprimer une liste ; y ajouter ou retirer des livres ; les ordonner par glisser-déposer. | S1 (simple, pour choisir les livres), S5 (couverture de liste), S6, S7 |
| **F3 Auteurs** | Regrouper les livres par auteur, afficher et corriger la biographie, détecter et fusionner les doublons. | S3 (Wikipédia, Wikidata), S5 (photo), S6, S7 |
| **F4 Statistiques** | Répartitions (support, statut, type, format), ajouts par mois, tags et auteurs les plus fréquents, activité récente. | S7 |
| **F5 Export** | Exporter une liste ou le résultat d'une recherche en CSV, Excel, JSON, TXT ou HTML, avec sélection manuelle. | S1 (avancée), S6, S7 |
| **F6 Paramétrage et maintenance** | Gérer les référentiels, corriger des livres en masse, sauvegarder et restaurer, optimiser la base, nettoyer `uploads/`. | S1 (avancée), S2, S4, S5, S6, S7 |

### Services transverses

| Service | Rôle | Utilisé par |
|---|---|---|
| **S1 Recherche** | Deux variantes indépendantes : *simple* (un champ texte et des filtres) et *avancée* (tags en ET/OU, dates, tri, critères « sans couverture » / « sans note »). Voir [Deux chemins de recherche](#deux-chemins-de-recherche). | F1, F2 (simple) ; F5, F6 (avancée) |
| **S2 Édition en masse** | Applique un même changement (support, statut, tags) à tous les livres sélectionnés par une recherche avancée. | F6 |
| **S3 Import externe** | Fiche livre depuis un ISBN (Google Books, puis Open Library, puis BnF) ; biographie, dates et nationalité d'un auteur (Wikipédia en français, Wikidata). | F1, F3 |
| **S4 Référentiels** | Valeurs autorisées et extensibles : types de support (`ENUM` réécrit), tags (catalogue de suggestions), formats numériques (catalogue avec extension attendue). Ils valident les saisies et alimentent les filtres. | F1, F6 (et les filtres de F2, F3, F5) |
| **S5 Fichiers envoyés** | Contrôle et stockage des couvertures, photos d'auteurs et fichiers numériques dans `uploads/` ; téléchargement protégé des fichiers numériques ; détection des fichiers orphelins. | F1, F2, F3, F6 |
| **S6 Sécurité** | Authentification, session, jeton CSRF, échappement HTML, restrictions d'accès aux fichiers. Voir [Sécurité](#sécurité). | Toutes |
| **S7 Persistance et schéma** | Connexion unique à la base, création et migration automatiques des tables, sauvegarde et restauration JSON. | Toutes |

## Architecture logicielle

### Découpage modèle / vue / contrôleur

```
Navigateur
   │  requête HTTP
   ▼
CONTRÔLEUR   pages à la racine : index.php, ajouter.php, listes.php, …
   │           1. includes/bootstrap.php : session, authentification, $bookManager
   │           2. lit $_GET / $_POST, vérifie le jeton CSRF
   │           3. appelle le modèle via $bookManager
   │           4. prépare les variables d'affichage, ou redirige (message flash)
   │
   ├──────────────────────────────►  MODÈLE
   │                                   BookManager.php  point d'entrée unique (façade)
   │                                        │ délègue, sans logique propre
   │                                        ▼
   │                                   src/*.php  règles métier, SQL, API externes
   │                                        │
   │                                        ▼
   │                                   MySQL / MariaDB, dossier uploads/
   ▼
VUE          views/<page>.php : HTML et affichage des variables, via h()
             includes/head.php, includes/menu.php : parties communes
             style.css, tag-input.js : présentation
```

**Modèle** — `BookManager.php` et `src/`. `BookManager` n'est qu'un point d'entrée : il crée une connexion PDO unique, instancie les classes de `src/` en leur passant cette connexion et leurs dépendances, et chaque méthode publique se contente d'appeler la classe responsable (une ligne). Les règles métier, le SQL et les appels aux sites externes sont tous dans `src/` (voir le tableau [Les classes du modèle](#les-classes-du-modèle)). Les données circulent sous forme de tableaux associatifs : il n'y a pas d'objets métier (`Livre`, `Liste`, …).

**Contrôleur** — chaque page `.php` à la racine. Elle ne contient pas de SQL : elle valide les paramètres, appelle le modèle, puis se termine par `require 'views/<page>.php';`. Les pages sans affichage (`export_liste.php`, `get_biographie.php`, `update_order.php`, `telecharger_ebook.php`) renvoient directement un fichier, du JSON ou une redirection. Les fonctions communes aux contrôleurs sont dans `includes/utils.php` (nettoyage des paramètres de recherche, CSRF, messages flash) et `includes/export_functions.php` (génération des formats d'export).

**Vue** — `views/`. Uniquement du HTML et l'affichage des variables préparées par le contrôleur ; aucune vue ne traite `$_POST` ni n'écrit dans la base. Quelques vues lisent encore `$bookManager` pour remplir un menu déroulant (séries, types de support).

**Écarts connus au découpage** : l'envoi de la couverture d'une liste (`listes.php`) et de la couverture d'un livre ajouté à la main (`ajouter.php`) est encore traité dans le contrôleur au lieu d'une classe de `src/`, et les lectures de `$bookManager` depuis les vues mentionnées ci-dessus.

### Correspondance fonctions ↔ code

| Fonction | Contrôleur(s) | Vue(s) | Modèle (`src/`) |
|---|---|---|---|
| F1 Fiches livres | `ajouter.php`, `index.php`, `supprimer.php`, `telecharger_ebook.php` | `ajouter.php`, `index.php`, `supprimer.php` | `LivreRepository`, `IsbnLookup`, `TagRepository`, `SupportRepository`, `FormatNumeriqueRepository` |
| F2 Listes | `listes.php`, `update_order.php` (AJAX) | `listes.php` | `ListeRepository`, `LivreRepository` |
| F3 Auteurs | `auteurs.php`, `get_biographie.php` (AJAX) | `auteurs.php` | `AuteurRepository` |
| F4 Statistiques | `stats.php` | `stats.php` | `Stats` |
| F5 Export | `export.php`, `export_liste.php` | `export.php`, `export_results.php` | `LivreRepository`, `ListeRepository` (+ `includes/export_functions.php`) |
| F6 Paramétrage et maintenance | `outils.php` | `outils.php` | `TagRepository`, `SupportRepository`, `FormatNumeriqueRepository`, `LivreRepository` (édition en masse), `Maintenance`, `Schema` |
| Transverse | `login.php`, `logout.php`, `includes/bootstrap.php`, `includes/auth.php` | `login.php`, `includes/head.php`, `includes/menu.php` | `Schema` |

| Service | Où il est implémenté |
|---|---|
| S1 Recherche | `sanitizeSearchParams()` / `sanitizeAdvancedSearchParams()` (`includes/utils.php`) ; `LivreRepository::getAllBooks()`, `countBooks()`, `searchBooksAdvanced()`, `countBooksAdvanced()` ; `TagRepository::buildTagCondition()` |
| S2 Édition en masse | `LivreRepository::bulkUpdateBooks()` |
| S3 Import externe | `IsbnLookup` ; `AuteurRepository` (méthodes privées `fetchWikipediaBiography()`, `fetchWikidataMetadata()`) |
| S4 Référentiels | `TagRepository`, `SupportRepository`, `FormatNumeriqueRepository` |
| S5 Fichiers envoyés | `LivreRepository::uploadCouverture()`, `uploadFichierNumerique()` ; `AuteurRepository::uploadAuteurPhoto()` ; `Maintenance::getUploadsStorageInfo()`, `deleteOrphanUploads()` ; `telecharger_ebook.php` ; fichiers `.htaccess` de `uploads/` |
| S6 Sécurité | `includes/auth.php`, `includes/utils.php` (`h()`, `generateCSRFToken()`, `validateCSRFToken()`) |
| S7 Persistance et schéma | `BookManager` (connexion PDO), `Schema`, `Maintenance::exportDataBackup()` / `restoreDataBackup()`, `schema.sql` |

### Interactions : trois scénarios

**Ajouter un livre par son ISBN (F1 → S3, S4, S5, S7)**

```
ajouter.php ── validateCSRFToken()                                   S6
     │
     ├─► $bookManager->getBookInfoFromISBN()  ─► IsbnLookup           S3
     │        Google Books ─échec─► Open Library ─échec─► BnF
     │   (le formulaire est pré-rempli, l'utilisateur complète)
     │
     ├─► $bookManager->uploadFichierNumerique() ─► LivreRepository    S5
     │        └─► FormatNumeriqueRepository::getFormatExtension()     S4
     │
     └─► $bookManager->addBook() ─► LivreRepository ─► INSERT livres  S7
              └─► vérifie le format (S4) ; ISBN unique en base
     ▼
redirectWithMessage() ─► views/ajouter.php affiche le message flash
```

**Exporter le résultat d'une recherche (F5 → S1)**

```
export.php ── sanitizeAdvancedSearchParams($_GET)                    S1
     │
     ├─ ajax=1 ─► countBooksAdvanced() + searchBooksAdvanced()
     │            └─► views/export_results.php rendu en JSON
     │                (rafraîchit la liste sans recharger la page)
     │
     └─ format=csv|xlsx|json|txt|html
              ─► searchBooksAdvanced()  (ou les livres cochés)
              ─► exportCSV() / exportExcel() / …   includes/export_functions.php
              ─► en-têtes HTTP + fichier envoyé au navigateur
```

**Corriger des livres en masse (F6 → S1, S2, S4)**

```
outils.php ── sanitizeAdvancedSearchParams()                         S1
     │        searchBooksAdvanced() ─► affiche les livres concernés
     │
     └─ POST + CSRF ─► $bookManager->bulkUpdateBooks()               S2
              └─► LivreRepository : UPDATE dans une transaction
                    ├─ support, statut : valeurs de l'ENUM           S4
                    └─► TagRepository::splitTagsExact() : ajout,
                        retrait ou remplacement des tags             S4
```

### Démarrage d'une page

Toute page authentifiée commence par `require_once 'includes/bootstrap.php'`, qui :

1. démarre la session ;
2. charge `includes/auth.php` et appelle `requireAuth()` (redirige vers `login.php` si la personne n'est pas connectée, ou vers `install/` si `config.php` n'existe pas) ;
3. lit `config.php` et crée l'objet `$bookManager` ;
4. appelle `$bookManager->ensureSchema()` pour créer ou mettre à jour les tables.

Le résultat de `ensureSchema()` est absorbé (`ob_start()` / `ob_end_clean()`) : il peut afficher des messages de migration, or plusieurs pages envoient ensuite des en-têtes HTTP (export CSV, réponses JSON, téléchargements) qui échoueraient si un seul caractère avait déjà été écrit.

`login.php` et `logout.php` n'utilisent pas `bootstrap.php`.

### Les classes du modèle

Le modèle est entièrement dans `src/`. `BookManager.php` n'en fait pas partie au sens strict : c'est un point d'entrée qui crée une seule connexion PDO, la partage avec les classes ci-dessous et leur délègue chaque appel, pour que les contrôleurs n'aient qu'un objet à connaître (`$bookManager`).

| Classe | Fonctions et services servis | Responsabilité | Dépend de |
|---|---|---|---|
| `LivreRepository` | F1, F5, F6 ; S1, S2, S5 | Livres (`livres`) : ajout, modification, suppression, recherche simple et avancée, édition en masse, séries, pagination, couvertures personnalisées, envoi du fichier numérique. | `TagRepository`, `SupportRepository`, `FormatNumeriqueRepository` |
| `ListeRepository` | F2, F5 | Listes de lecture (`listes_lecture`, `livres_listes`) : contenu et ordre. | — |
| `AuteurRepository` | F3 ; S3, S5 | Auteurs (`auteurs`, `auteurs_doublons_ignores`) : biographies (Wikipédia, Wikidata), photos, regroupement, doublons, fusion. | — |
| `TagRepository` | S1, S4 | Tags (chaîne CSV dans `livres.tags`) et catalogue `tags_catalogue` ; conditions SQL sur les tags pour la recherche avancée. | — |
| `SupportRepository` | S4 | Types de support, stockés dans l'`ENUM` de `livres.support`. | — |
| `FormatNumeriqueRepository` | S4 | Formats numériques (PDF, Epub, ...) : catalogue extensible `formats_numeriques` (nom + extension attendue), utilisé pour valider les livres numériques. | — |
| `IsbnLookup` | S3 | Recherche par ISBN auprès de Google Books, Open Library, BnF. N'utilise pas la base. | — |
| `Stats` | F4 | Statistiques. | `LivreRepository` (comptage) |
| `Schema` | S7 | Création et migration des tables, index. | les autres classes (création de leurs tables) |
| `Maintenance` | F6 ; S5, S7 | Sauvegarde et restauration JSON, optimisation, diagnostic de la base et de `uploads/`. | — |

Les classes de `src/` reçoivent leur connexion (et leurs dépendances) dans le constructeur, sans variable globale.

### Le schéma de la base

Le modèle est décrit dans [`schema.sql`](../schema.sql). Il y a 7 tables :

- `livres` : la collection ;
- `listes_lecture` et `livres_listes` : les listes, en relation plusieurs-à-plusieurs, avec un ordre, et suppression en cascade ;
- `auteurs` : cache des biographies. **Pas de clé étrangère** vers `livres` : le lien se fait par le nom, car `livres.auteur` est un texte libre qui peut contenir plusieurs noms séparés par des virgules ;
- `auteurs_doublons_ignores` : mémorise les faux doublons d'auteurs ;
- `tags_catalogue` : suggestions de tags ;
- `formats_numeriques` : catalogue des formats numériques (nom + extension), voir ci-dessous.

Le schéma est créé et mis à jour **par l'application elle-même**, dans `src/Schema.php` : des `CREATE TABLE IF NOT EXISTS`, puis des `ALTER TABLE … ADD COLUMN` gardés par une vérification préalable dans `information_schema`. Ces opérations sont rejouables sans effet de bord. `schema.sql` est un second chemin (création à la main) : **les deux doivent rester synchronisés**.

Les valeurs des `ENUM` `support` et `statut` ne sont pas figées : la page Outils permet d'ajouter et de renommer des supports, ce qui réécrit la définition de l'`ENUM` dans la base.

### Papier vs numérique

`livres.type_livre` (`Papier` ou `Numérique`, `Papier` par défaut) est une dimension indépendante de `support` (qui décrit le type de contenu : Livre, BD, Manga) — un livre est l'un ou l'autre, jamais les deux à la fois. Pour un livre numérique, `livres.format_numerique` contient un nom validé contre le catalogue `formats_numeriques` (`FormatNumeriqueRepository`, extensible depuis Outils → « Formats numériques », dans le même esprit que les tags ou les supports mais avec une simple table catalogue plutôt qu'un `ENUM` réécrit), et `livres.fichier_numerique` le chemin du fichier envoyé. Repasser un livre en `Papier` efface toujours `format_numerique` et `fichier_numerique` (et supprime l'ancien fichier) — un invariant à respecter si vous touchez `LivreRepository::updateBookNotes()`.

Les fichiers numériques sont stockés dans `uploads/ebooks/`, qui a son **propre** `.htaccess` interdisant tout accès direct (contrairement aux sous-dossiers `uploads/auteurs/` et `uploads/listes/`, qui n'ont pas le leur et héritent des règles du `.htaccess` parent, lequel n'autorise que les images) : un livre numérique complet est un contenu plus sensible qu'une simple couverture. Le seul accès possible passe par `telecharger_ebook.php`, une page authentifiée comme les autres (elle charge `includes/bootstrap.php`).

### Deux chemins de recherche

Il existe deux implémentations distinctes, qui ne sont pas interchangeables :

- **Recherche simple** : `sanitizeSearchParams()` puis `getAllBooks()` / `countBooks()`. Un champ texte plus les filtres support, type (papier/numérique), tag, statut, série. Utilisée par `index.php`.
- **Recherche avancée** : `sanitizeAdvancedSearchParams()` puis `searchBooksAdvanced()` / `countBooksAdvanced()`. Ajoute le type et le format numérique, les tags en ET/OU avec correspondance exacte, les dates, « sans couverture » / « sans note » et le tri. Utilisée par `outils.php` (édition en masse) et `export.php`.

Les deux fonctions de nettoyage sont dans `includes/utils.php`.

### Export

`includes/export_functions.php` contient `exportCSV()`, `exportExcel()`, `exportJSON()`, `exportTXT()` et `exportHTML()`. Elles reçoivent des métadonnées (`nom`, `description`) et un tableau de livres, sans savoir d'où viennent les données : elles servent à la fois à `export_liste.php` (une liste) et à `export.php` (résultat d'une recherche avancée). `export.php` peut aussi renvoyer son fragment de résultats en JSON (`ajax=1`) pour se rafraîchir sans recharger la page, et mémorise la sélection manuelle dans le navigateur (`localStorage`).

### Sécurité

Application à un seul utilisateur : le hash bcrypt du mot de passe et le sel de session sont générés par l'assistant d'installation et stockés dans `config.php` (jamais versionné). Les règles suivies dans tout le code :

- **CSRF** : chaque formulaire qui modifie des données porte un jeton (`generateCSRFToken()`), vérifié par `validateCSRFToken()` avant l'action.
- **XSS** : toute donnée saisie est affichée via `h()` (un `htmlspecialchars`).
- **SQL** : uniquement des requêtes préparées PDO.
- **Envois de fichiers** : taille (5 Mo pour les images, 100 Mo pour les fichiers numériques), type MIME réel (`finfo`) et extension vérifiés (l'extension d'un fichier numérique doit correspondre à celle du format choisi dans `formats_numeriques`) ; noms générés ; le dossier `uploads/` interdit l'exécution de scripts (`.htaccess`).
- **Sessions** : expiration après 1 h, verrouillage après 5 échecs de connexion.
- Messages « flash » après une action : `redirectWithMessage()` / `getFlashMessage()`, pour éviter le double envoi d'un formulaire.

## Autour de l'application

Ces éléments ne font pas partie de l'application elle-même, mais servent à l'installer, la comprendre ou la vérifier.

| Élément | Où | Rôle |
|---|---|---|
| Installation | `install/`, `config.example.php`, `schema.sql` | Assistant web qui crée `config.php` (connexion, mot de passe) et les tables ; `schema.sql` permet la création à la main. |
| Documentation utilisateur | `README.md`, `docs/cas-d-usage.md`, captures dans `docs/` | Fonctionnalités, besoins couverts, limites connues. |
| Documentation logicielle | `docs/architecture.md` (ce document) | Architecture fonctionnelle et logicielle. |
| Évolutions | `docs/evolutions.md` | Ce qui a été fait et ce qui est envisagé. |
| Vérification | voir ci-dessous | Il n'y a pas encore de tests de non-régression automatisés. |

### Ajouter une fonctionnalité

1. **Une nouvelle donnée à stocker** : ajoutez la colonne dans `src/Schema.php` (migration rejouable) **et** dans `schema.sql`.
2. **Une nouvelle requête** : écrivez la méthode dans la classe de `src/` concernée, puis ajoutez dans `BookManager` une méthode d'une ligne qui l'appelle.
3. **Une nouvelle page** : commencez par `require_once 'includes/bootstrap.php'`, protégez les formulaires avec le jeton CSRF, n'écrivez pas de SQL dans la page, et terminez le contrôleur par `require 'views/<page>.php';`. La vue affiche avec `h()` et ne fait aucun traitement.

Pensez aussi à situer la fonctionnalité dans l'[architecture fonctionnelle](#architecture-fonctionnelle) : nouvelle fonction métier, ou extension d'une fonction et des services existants, et à mettre à jour les tableaux de correspondance.

### Vérifier une modification

Il n'y a pas de suite de tests de non-régression. La vérification pratique est :

```bash
php -l fichier.php          # contrôle de syntaxe
```

puis l'essai de la page dans le navigateur, ou un petit script PHP jetable qui charge `BookManager.php` et appelle la méthode voulue sur une base de test.
