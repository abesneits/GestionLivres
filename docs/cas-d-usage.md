# Cas d'usage et besoins couverts

Ce document décrit à quoi sert l'application, ce qu'elle sait faire aujourd'hui et ce qu'elle ne fait pas. Les évolutions envisagées sont dans [evolutions.md](evolutions.md).

## À qui s'adresse-t-elle ?

À **une seule personne** qui veut tenir le catalogue de sa propre bibliothèque (livres, bandes dessinées, mangas) sur un hébergement qu'elle contrôle. Il n'y a ni comptes multiples, ni partage entre utilisateurs : un mot de passe unique protège l'accès.

L'objectif est de répondre à quelques questions simples :

- *Qu'est-ce que je possède déjà ?* (pour ne pas racheter un tome en double)
- *Qu'est-ce que j'ai lu, qu'est-ce qu'il me reste à lire ?*
- *Où en suis-je dans telle série ?*

## Besoins couverts

| Besoin | Comment l'application y répond | Où |
|---|---|---|
| **Ajouter un livre rapidement** | Saisie de l'ISBN : titre, auteur, description, couverture et date sont récupérés automatiquement (Google Books, puis Open Library, puis BnF). | Ajouter |
| **Ajouter un livre sans ISBN** | Saisie manuelle de tous les champs. Un identifiant temporaire (`TEMP_…`) est généré à la place de l'ISBN. | Ajouter |
| **Retrouver un livre** | Recherche par titre, auteur, ISBN ou série ; filtres par support, statut, tag et série ; pagination. | Ma collection |
| **Éviter les doublons** | L'ISBN est unique : ajouter deux fois le même livre est refusé avec un message clair. | Ajouter |
| **Suivre mes lectures** | Statuts (À lire, En cours, Lu, Abandonné), note personnelle, tags libres. | Ma collection |
| **Suivre une série** | Rattachement d'un livre à une série avec son numéro de tome ; filtre par série et tri par série puis par tome. | Ma collection, Édition |
| **Préparer des lectures** | Listes de lecture nommées, dont l'ordre se change par glisser-déposer. | Listes |
| **Connaître un auteur** | Fiche avec biographie, dates et nationalité (Wikipédia et Wikidata), photo ; regroupement des livres par auteur ; détection et fusion des doublons dus à la casse ou aux accents (« victor hugo » / « Victor Hugo »). | Auteurs |
| **Avoir une vue d'ensemble** | Répartition par support et par statut, ajouts par mois, tags et auteurs les plus fréquents. | Statistiques |
| **Emporter une liste hors connexion** | Export de toute la collection ou d'une liste au format CSV, Excel, JSON, TXT ou HTML, avec recherche avancée et sélection manuelle des livres. | Export, Listes |
| **Corriger beaucoup de livres d'un coup** | Édition en masse du support, du statut et des tags sur le résultat d'une recherche. | Outils |
| **Organiser mes catégories** | Ajout et renommage des types de support (Livre, BD, Manga, ou les vôtres) ; renommage et suppression des tags. | Outils |
| **Sauvegarder mes données** | Export et restauration complète au format JSON. | Outils |
| **Garder le contrôle du stockage** | Diagnostic de la base, optimisation des tables, détection des images inutilisées dans `uploads/`. | Outils |

## Données conservées pour chaque livre

ISBN (ou identifiant temporaire), titre, support, auteur(s), série, numéro de tome, couverture, description, date de publication, statut, tags, note personnelle, date d'ajout.

Le modèle complet est dans [`schema.sql`](../schema.sql).

## Ce que l'application ne fait pas (limites connues)

- **Pas de recherche par titre** : sans ISBN, la fiche se saisit à la main.
- **Pas de scan de code-barres** : l'ISBN se tape ou se colle.
- **Données récupérées limitées** : pas d'éditeur, de langue, de nombre de pages, de collection ni de format (poche, grand format). Les statistiques ne peuvent donc pas s'appuyer dessus.
- **Pas de notion d'« à acheter » ou d'« emprunté »** : la collection décrit ce que l'on possède ou a possédé, pas une liste de souhaits.
- **Pas d'application mobile ni de mode hors connexion** : l'accès depuis un téléphone se fait par le navigateur (le site peut être ajouté à l'écran d'accueil), et il faut une connexion. Pour consulter une liste sans réseau, il faut l'exporter (HTML ou CSV) avant.
- **Un seul utilisateur** et un seul mot de passe.
- **MySQL / MariaDB uniquement** : le code utilise des fonctions propres à ces serveurs (voir [architecture.md](architecture.md)).
- **Dépendance à des services tiers** : si Google Books, Open Library ou la BnF sont indisponibles ou ne connaissent pas un ISBN, l'ajout automatique échoue. La BnF ne fournit pas de couverture.
- **La sauvegarde JSON ne contient pas les fichiers** du dossier `uploads/` (couvertures et photos d'auteurs envoyées) ni le catalogue de tags : ces éléments doivent être copiés séparément.
