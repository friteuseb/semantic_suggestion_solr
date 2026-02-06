# Semantic Suggestion Solr

Extension TYPO3 qui affiche des suggestions de contenus similaires via Solr More Like This (MLT).

## Principe

L'extension interroge le handler MLT de Solr avec l'identifiant du document courant. Solr calcule la similarite par TF-IDF sur les term vectors et retourne les documents les plus proches. Aucun calcul cote PHP, pas de base de donnees propre, pas de scheduler.

Les contenus multi-types sont pris en charge : pages, actualites (EXT:news), et tout autre contenu indexe dans Solr.

## Prerequis

- TYPO3 13.4+
- EXT:solr 13.0+ (`apache-solr-for-typo3/solr`) avec un index fonctionnel
- Handler `/mlt` actif sur le core Solr (present par defaut dans la configuration EXT:solr)

## Installation

```bash
composer require cyrilmarchand/semantic-suggestion-solr:@dev
```

Vider les caches TYPO3, puis inclure le TypoScript statique de l'extension dans le template du site.

## Utilisation

### En tant qu'element de contenu

Inserer l'element "Suggestions similaires (Solr)" via le backend, dans n'importe quelle page.

### En tant qu'objet TypoScript

Pour integrer directement dans un template Fluid :

```html
<f:cObject typoscriptObjectPath="lib.semantic_suggestion_solr" />
```

### Detection automatique du contexte

Le plugin detecte automatiquement le type de contenu courant :

- **Page standard** : recherche MLT basee sur le `pageUid`
- **Detail actualite** (EXT:news) : recherche MLT basee sur le type `tx_news_domain_model_news` et l'UID de l'actualite (parametre `tx_news_pi1[news]`)

## Configuration TypoScript

Tous les parametres sont modifiables via les constantes TypoScript.

### Parametres MLT

| Constante | Defaut | Description |
|-----------|--------|-------------|
| `maxResults` | `6` | Nombre max de suggestions retournees |
| `minTermFreq` | `1` | Frequence minimale d'un terme pour etre considere (mlt.mintf) |
| `minDocFreq` | `1` | Nombre minimal de documents contenant le terme (mlt.mindf) |
| `mltFields` | `content,title,keywords` | Champs Solr utilises pour le calcul de similarite |
| `boostFields` | `content^0.5,title^1.2,keywords^2.0` | Poids des champs (mlt.qf) |

### Parametres d'affichage

| Constante | Defaut | Description |
|-----------|--------|-------------|
| `excludeContentTypes` | *(vide)* | Types de documents Solr a exclure (separes par virgule) |
| `showScore` | `0` | Afficher le score de similarite MLT |
| `showContentType` | `1` | Afficher le badge de type (Page, Actualite, etc.) |

Chemin des constantes : `plugin.tx_semanticsuggestionsolr_suggestions.settings.*`

### Exemple de surcharge

```typoscript
plugin.tx_semanticsuggestionsolr_suggestions.settings {
    maxResults = 4
    boostFields = title^2.0,keywords^3.0,content^0.3
    showScore = 1
    excludeContentTypes = tx_news_domain_model_news
}
```

## Fonctionnement technique

1. Resolution du document Solr courant via une requete `type:{type} AND uid:{uid}` pour obtenir l'identifiant Solr (format `{siteHash}/{type}/{uid}`)
2. Execution de la requete MLT Solarium (`createMoreLikeThis`) sur cet identifiant
3. Normalisation des resultats : titre, URL, type, label, score, snippet

### Chaine d'acces Solr

```
ConnectionManager -> SolrConnection -> SolrReadService -> Solarium Client
```

### Structure d'une suggestion

```php
[
    'title'     => string,  // Titre du document
    'url'       => string,  // URL absolue (champ Solr)
    'type'      => string,  // Type Solr (pages, tx_news_domain_model_news, ...)
    'typeLabel'  => string,  // Label affiche (Page, Actualite, ...)
    'score'     => float,   // Score de similarite MLT
    'snippet'   => string,  // Extrait du contenu (200 car. max)
    'uid'       => int,     // UID de l'enregistrement
]
```

## Structure de l'extension

```
Classes/
    Controller/SuggestionsController.php    Plugin frontend (listAction)
    Service/SolrMltService.php              Requete MLT via Solarium
Configuration/
    Services.php                            Injection de dependances
    TCA/Overrides/tt_content.php            Enregistrement du plugin
    TypoScript/
        constants.typoscript                Constantes configurables
        setup.typoscript                    Configuration du plugin
Resources/
    Private/Templates/Suggestions/
        List.html                           Template Fluid (cartes Bootstrap)
```

## Personnalisation du template

Surcharger le template via TypoScript :

```typoscript
plugin.tx_semanticsuggestionsolr_suggestions.view.templateRootPaths.10 = EXT:mon_extension/Resources/Private/Templates/SemanticSuggestionSolr/
```

Puis creer `Suggestions/List.html` dans ce repertoire. Les variables disponibles dans le template sont `{suggestions}` (tableau) et `{settings}` (configuration TypoScript).

## Licence

GPL-2.0-or-later
