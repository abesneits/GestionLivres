# Évolutions envisagées

Liste des améliorations identifiées, pour garder trace de ce qui est prévu, de ce qui est écarté et pourquoi. L'ordre reflète une priorité indicative, pas un engagement. Les besoins déjà couverts sont décrits dans [cas-d-usage.md](cas-d-usage.md).

Effort estimé : **faible** (quelques heures), **moyen** (une à plusieurs soirées), **élevé** (chantier de plusieurs jours).

## Déjà réalisé

| Évolution | Détail |
|---|---|
| Notion de série | Série et numéro de tome par livre, filtre et tri par série. |
| Modèle de données lisible | [`schema.sql`](../schema.sql) décrit les tables (l'application continue de les créer et de les mettre à jour seule). |
| Couche de données découpée | `BookManager` délègue à des classes thématiques dans `src/` (voir [architecture.md](architecture.md)). |
| Manifeste web | `site.webmanifest` complet (nom, langue, URL de démarrage, couleurs, icônes) et chemins relatifs, y compris pour les icônes de `includes/head.php` : le site peut donc être installé dans un sous-dossier et ajouté à l'écran d'accueil d'un téléphone (selon les navigateurs, HTTPS est exigé). Le fonctionnement hors connexion reste à faire (ci-dessous). |
| Séparer les vues du traitement | Chaque page à la racine est un contrôleur qui se termine par `require 'views/<page>.php'` ; la vue ne contient que du HTML (voir [architecture.md](architecture.md)). |
| Support papier / numérique | Distinction papier / numérique par livre (migration automatique : la collection existante passe en papier), catalogue de formats numériques extensible (PDF, Epub, ...), envoi et téléchargement authentifié du fichier (voir [architecture.md](architecture.md)). |

## Données et recherche

| Évolution | Effort | Notes |
|---|---|---|
| **Enrichir les fiches** : éditeur, langue, nombre de pages, collection, format (poche / grand format) | Moyen | Les sources interrogées (Google Books, Open Library, BnF) fournissent généralement une partie de ces champs (à vérifier source par source). Il faut de nouvelles colonnes, déclarées dans `src/Schema.php` et `schema.sql`, l'affichage dans l'édition, et de nouvelles statistiques (par langue, par éditeur). |
| **Recherche par titre / auteur** quand il n'y a pas d'ISBN | Moyen | Interroger les mêmes sources par titre, afficher une liste de résultats et laisser choisir. Point d'attention : les résultats approximatifs demandent de la validation manuelle. |
| **Scanner de code-barres** avec l'appareil photo du téléphone | Élevé | Lecture en JavaScript dans la page d'ajout. Nécessite HTTPS et une compatibilité navigateur à vérifier. |
| **Rapprochement de doublons sans ISBN** | Moyen | Aujourd'hui seuls les ISBN sont uniques ; deux saisies manuelles du même livre ne sont pas détectées. |

## Suivi de la collection

| Évolution | Effort | Notes |
|---|---|---|
| **« À acheter » / « emprunté »** (liste de souhaits, prêts, bibliothèque) | Faible à moyen | Non prioritaire pour l'instant. Le plus simple serait de nouveaux statuts (le statut est déjà un champ à valeurs fixes) plutôt qu'un modèle de données séparé. Un suivi des prêts (à qui, depuis quand) demanderait une table dédiée. |
| **Dates de lecture** (début, fin) | Moyen | Permettrait des statistiques de lecture par année. |

## Mobile et hors connexion

| Évolution | Effort | Notes |
|---|---|---|
| **Mode hors connexion** (service worker) | Élevé | Permettrait de consulter la collection sans réseau. En attendant, l'export HTML ou CSV d'une liste répond au besoin. |
| **Export optimisé pour le mobile** | Faible | Version HTML compacte, lisible sur petit écran, pensée pour être emportée en librairie. |
| **Application mobile native** | Non prévu | Hors du périmètre d'un projet PHP simple. |

## Sauvegarde et exploitation

| Évolution | Effort | Notes |
|---|---|---|
| **Sauvegarde complète** incluant `uploads/` et le catalogue de tags | Moyen | Aujourd'hui la sauvegarde JSON ne couvre que les livres, listes et auteurs. |
| **Journal des actions** consultable depuis l'interface | Faible | Les journaux existent (`logs/`) mais ne se lisent qu'en fichier. |

## Qualité du code

| Évolution | Effort | Notes |
|---|---|---|
| **Tests automatisés** (PHPUnit) | Moyen | Aujourd'hui les vérifications sont manuelles ou par scripts jetables. Les classes de `src/` prennent leur connexion en paramètre, ce qui facilite les tests. |
| **Documentation utilisateur** dans `docs/` | Faible | Guide pas à pas avec captures d'écran. |

## Écarté

| Évolution | Raison |
|---|---|
| **Support de PostgreSQL / SQLite** | Le code utilise des fonctions propres à MySQL/MariaDB (types `ENUM`, `SHOW COLUMNS`, `information_schema`, `ON DUPLICATE KEY UPDATE`). L'adapter reviendrait à réécrire la couche de données, sans bénéfice pour un usage personnel. `schema.sql` sert de référence pour ceux qui voudraient le faire. |
| **Comptes multi-utilisateurs** | Hors du besoin : l'application est pensée pour une seule personne. |
