# Semantic Suggestion Solr

TYPO3 extension that displays related content suggestions using Solr More Like This (MLT). Multi-content: pages, news (EXT:news), and any other indexed record type.

## How it works

The plugin sends the current document's Solr ID to the MLT request handler. Solr computes similarity via TF-IDF on stored term vectors and returns the closest documents. No PHP-side computation, no database, no scheduler.

### Language handling

EXT:solr maintains one core per site language. The plugin reads the current frontend language from the request and connects to the matching Solr core via `ConnectionManager->getConnectionByRootPageId($rootPageId, $languageUid)`. MLT results are therefore always in the same language as the current page.

### Context detection

The plugin detects the current content type automatically:

- Standard page: MLT lookup by `type:pages AND uid:{pageUid}`
- News detail (EXT:news): if `tx_news_pi1[news]` is present in the request, MLT lookup by `type:tx_news_domain_model_news AND uid:{newsUid}`

## Requirements

- TYPO3 13.4+
- EXT:solr 13.0+ with a working index
- The `/mlt` request handler enabled on the Solr core (default in EXT:solr configurations)

## Installation

```bash
composer require cyrilmarchand/semantic-suggestion-solr:@dev
```

Flush TYPO3 caches, then include the extension's static TypoScript in the site template.

## Usage

### As a content element

Insert the plugin "Similar content (Solr)" via the backend. The FlexForm provides per-instance settings:

- Maximum number of suggestions
- Allowed content types (checkboxes: Pages, News -- if none checked, all types are shown)
- Show/hide content type badge
- Show/hide relevance score

### In a Fluid template

```html
<f:cObject typoscriptObjectPath="lib.semantic_suggestion_solr" />
```

## Configuration

### TypoScript constants

All settings live under `plugin.tx_semanticsuggestionsolr_suggestions.settings`.

#### MLT query parameters

| Setting | Default | Description |
|---------|---------|-------------|
| `maxResults` | `6` | Max suggestions returned |
| `minTermFreq` | `1` | Minimum term frequency (mlt.mintf) |
| `minDocFreq` | `1` | Minimum document frequency (mlt.mindf) |
| `mltFields` | `content,title,keywords` | Fields used for similarity |
| `boostFields` | `content^0.5,title^1.2,keywords^2.0` | Field weights (mlt.qf) |

#### Display

| Setting | Default | Description |
|---------|---------|-------------|
| `allowedTypes` | *(empty)* | Whitelist of Solr types (comma-separated). Empty = all types. Overridden by FlexForm. |
| `excludeContentTypes` | *(empty)* | Blacklist of Solr types. Only used when `allowedTypes` is empty. |
| `showScore` | `0` | Display the MLT relevance score |
| `showContentType` | `1` | Display a type badge (Page, News, etc.) |

### FlexForm vs TypoScript

FlexForm settings (set per content element instance) override TypoScript settings for the same keys. The `allowedTypes` whitelist from FlexForm checkboxes takes precedence over the `excludeContentTypes` blacklist from TypoScript.

### Example override

```typoscript
plugin.tx_semanticsuggestionsolr_suggestions.settings {
    maxResults = 4
    boostFields = title^2.0,keywords^3.0,content^0.3
    showScore = 1
}
```

## Localization

All frontend labels (heading, button, type badges, score label) are defined in XLIFF files and resolved via `f:translate`. Shipped translations:

- English (default)
- French

Type badges use the key pattern `type.{solr_type}` (e.g. `type.pages`, `type.tx_news_domain_model_news`). To add labels for custom indexed types, override the XLIFF or add keys in your site package.

## Architecture

```
Classes/
    Controller/SuggestionsController.php    Frontend plugin (listAction)
    Service/SolrMltService.php              MLT query via Solarium client
Configuration/
    FlexForms/Suggestions.xml               Per-instance backend settings
    Services.php                            Dependency injection
    TCA/Overrides/tt_content.php            Plugin + FlexForm registration
    TypoScript/
        constants.typoscript                Configurable defaults
        setup.typoscript                    Plugin setup + rendering definition
Resources/Private/
    Language/
        locallang.xlf                       English labels
        fr.locallang.xlf                    French labels
    Templates/Suggestions/
        List.html                           Fluid template (Bootstrap cards)
```

### Solr access chain

```
ConnectionManager -> SolrConnection -> SolrReadService -> Solarium Client
```

### Suggestion data structure

Each suggestion returned by the service:

```php
[
    'title'     => string,  // Document title
    'url'       => string,  // Absolute URL from Solr
    'type'      => string,  // Solr type (pages, tx_news_domain_model_news, ...)
    'typeLabel'  => string,  // Fallback display label
    'score'     => float,   // MLT relevance score
    'snippet'   => string,  // Content excerpt (200 chars max)
    'uid'       => int,     // Record UID
]
```

## Template customization

Override the template path via TypoScript:

```typoscript
plugin.tx_semanticsuggestionsolr_suggestions.view.templateRootPaths.10 = EXT:my_sitepackage/Resources/Private/Templates/SemanticSuggestionSolr/
```

Then create `Suggestions/List.html` in that directory. Available template variables: `{suggestions}` (array) and `{settings}` (merged TypoScript + FlexForm).

## License

GPL-2.0-or-later
