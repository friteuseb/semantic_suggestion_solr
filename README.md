# Semantic Suggestion Solr

TYPO3 extension that displays related content suggestions using Solr. Supports two similarity algorithms: **MLT** (lexical) and **SMLT** (semantic hybrid, combining MLT + KNN vector search server-side).

## Similarity modes

### MLT — More Like This

Solr's built-in MLT compares term frequency (TF-IDF) across configurable fields (title, content, keywords). Fast, no external model needed.

### SMLT — Semantic More Like This

A custom Solr SearchComponent that combines classical MLT with KNN vector search **server-side** in a single Solr request. Normalizes both score sets to [0,1] and merges them:

```
combinedScore = (mltWeight * mltScore) + (vectorWeight * vectorScore)
```

Default weights: 0.3 MLT / 0.7 vector (configurable per request).

Three internal sub-modes: `hybrid` (default), `vector_only`, `mlt_only` (useful for A/B testing).

Results are returned in a separate `semanticMoreLikeThis` response section, independent of the main search query.

### Auto mode (default)

When `similarityMode = auto`, the algorithm is chosen automatically based on EXT:solr's TypoScript:
- `plugin.tx_solr.search.query.type >= 1` -> **SMLT** (embeddings are indexed)
- `plugin.tx_solr.search.query.type = 0` -> **MLT** (no vectors available)

## How it works

### Cross-content-type suggestions

Suggestions can mix **pages** and **news** (or any indexed content type). By default, no type filter is applied, so a page about leather sewing machines may suggest both related pages and news articles about leather.

Use the `allowedTypes` setting (or FlexForm) to restrict suggestions to specific types if needed.

### Language handling

EXT:solr maintains one core per site language. The plugin reads the current frontend language from the request and connects to the matching Solr core via `ConnectionManager->getConnectionByRootPageId($rootPageId, $languageUid)`. Results are always in the same language as the current page.

### Context detection

The plugin detects the current content type automatically:

- **Standard page**: lookup by `type:pages AND uid:{pageUid}`
- **News detail** (EXT:news): if `tx_news_pi1[news]` is present in the request, lookup by `type:tx_news_domain_model_news AND uid:{newsUid}`

### Image enrichment

When `showImage = 1`, the extension resolves the first media file for each suggestion:
- Pages: `pages.media` field (FAL)
- News: `tx_news_domain_model_news.fal_media` field (FAL)

## Requirements

- TYPO3 13.4+
- EXT:solr 13.0+ with a working index
- For SMLT mode: the SMLT JAR deployed in Solr + embeddings indexed via the `textToVector` update chain

## Installation

```bash
composer require cywolf/semantic-suggestion-solr:@dev
```

Flush TYPO3 caches, then include the extension's static TypoScript in the site template.

## SMLT setup (Semantic More Like This)

### 1. Build and deploy the SMLT JAR

The Java source is in `.ddev/typo3-solr/smlt-plugin/`. Build with Maven:

```bash
docker run --rm -v "$(pwd)/.ddev/typo3-solr/smlt-plugin":/build -w /build \
  maven:3.9-eclipse-temurin-17 mvn package -q
```

Copy the JAR to the Solr `typo3lib` directory (alongside `solr-typo3-plugin-6.0.0.jar`):

```bash
cp .ddev/typo3-solr/smlt-plugin/target/solr-smlt-plugin-1.0.0.jar \
   vendor/apache-solr-for-typo3/solr/Resources/Private/Solr/typo3lib/
```

### 2. Register the SearchComponent in solrconfig.xml

Add to `vendor/.../configsets/ext_solr_13_1_0/conf/solrconfig.xml`:

```xml
<!-- SMLT: Semantic More Like This -->
<searchComponent name="smlt"
    class="fr.coconweb.solr.smlt.SemanticMoreLikeThisComponent"/>
```

And add `<str>smlt</str>` to the `/select` handler's `<arr name="last-components">`.

### 3. Set vector field to stored=true

In `general_schema_fields.xml`, ensure the vector field has `stored="true"`:

```xml
<field name="vector" type="knn_vector" indexed="true" stored="true" />
```

### 4. Enable vector indexing and re-index

```typoscript
plugin.tx_solr.search.query.type = 1
```

Re-initialize the Index Queue and run the scheduler. Each document will be sent to the OpenAI embedding API during indexing.

> **Note**: `query.type = 1` also enables native KNN for the frontend search. If KNN doesn't work in your setup, set it back to `0` after re-indexing. The SMLT component works independently — it reads pre-indexed vectors directly, regardless of `query.type`.

### 5. Set the similarity mode

```typoscript
plugin.tx_semanticsuggestionsolr_suggestions.settings.similarityMode = smlt
```

Or use `auto` (default) to let the extension detect `query.type` automatically.

## News indexing

To include news articles in Solr (required for cross-type suggestions), add the index queue configuration in your sitepackage TypoScript:

```typoscript
plugin.tx_solr.index.queue {
    news = 1
    news {
        type = tx_news_domain_model_news
        fields {
            title = title
            abstract = teaser
            content = SOLR_CONTENT
            content.cObject = COA
            content.cObject.10 = TEXT
            content.cObject.10.field = bodytext

            keywords = SOLR_MULTIVALUE
            keywords.field = keywords

            url = TEXT
            url {
                typolink.parameter = {detailPageUid}
                typolink.additionalParams = &tx_news_pi1[controller]=News&tx_news_pi1[action]=detail&tx_news_pi1[news]={field:uid}
                typolink.additionalParams.insertData = 1
                typolink.returnLast = url
            }
        }
    }
}
```

Then initialize the news index queue and run the Solr indexer.

## Usage

### As a content element

Insert the plugin "Similar content (Solr)" via the backend. The FlexForm provides per-instance settings:

- **Similarity algorithm**: Auto, MLT, or SMLT
- **Maximum number of suggestions**
- **Allowed content types** (checkboxes: Pages, News — if none checked, all types shown)
- **Restrict to pages/storage folders** (PID filter)
- **Show/hide content type badge**
- **Show/hide relevance score** (also shows the active mode as a badge)
- **Show/hide image**

### In a Fluid template

```html
<f:cObject typoscriptObjectPath="lib.semantic_suggestion_solr" />
```

### Via TypoScript (auto-render on all pages)

```typoscript
[page["doktype"] == 1]
page.10.variables.semanticSuggestionsSolr = USER
page.10.variables.semanticSuggestionsSolr {
    userFunc = TYPO3\CMS\Extbase\Core\Bootstrap->run
    extensionName = SemanticSuggestionSolr
    pluginName = Suggestions
    vendorName = Cywolf
    view < plugin.tx_semanticsuggestionsolr_suggestions.view
    settings < plugin.tx_semanticsuggestionsolr_suggestions.settings
    settings.maxResults = 3
}
[END]
```

Then render `{semanticSuggestionsSolr -> f:format.raw()}` in your page layout.

## Configuration

### TypoScript constants

All settings under `plugin.tx_semanticsuggestionsolr_suggestions.settings`.

#### Similarity algorithm

| Setting | Default | Description |
|---------|---------|-------------|
| `similarityMode` | `auto` | Algorithm: `auto`, `mlt`, or `smlt` |
| `maxResults` | `6` | Max suggestions returned |

#### MLT parameters

| Setting | Default | Description |
|---------|---------|-------------|
| `minTermFreq` | `1` | Minimum term frequency (mlt.mintf) |
| `minDocFreq` | `1` | Minimum document frequency (mlt.mindf) |
| `mltFields` | `content,title,keywords` | Fields used for similarity |
| `boostFields` | `content^0.5,title^1.2,keywords^2.0` | Field weights (mlt.qf) |

#### SMLT parameters

| Setting | Default | Description |
|---------|---------|-------------|
| `smltMode` | `hybrid` | Internal mode: `hybrid`, `vector_only`, `mlt_only` |
| `smltMltWeight` | `0.3` | Weight for MLT score in hybrid mode (0.0-1.0) |
| `smltVectorWeight` | `0.7` | Weight for vector score in hybrid mode (0.0-1.0) |

#### Display

| Setting | Default | Description |
|---------|---------|-------------|
| `allowedTypes` | *(empty)* | Whitelist of Solr types. Empty = all (pages + news mixed). |
| `excludeContentTypes` | *(empty)* | Blacklist. Only used when `allowedTypes` is empty. |
| `filterByPids` | *(empty)* | Restrict to specific parent pages / storage folders |
| `showScore` | `0` | Display the relevance score + active mode badge |
| `showContentType` | `1` | Display a type badge (Page, News, etc.) |
| `showImage` | `1` | Display the first media image of each suggestion |

### FlexForm vs TypoScript

FlexForm settings (per content element) override TypoScript settings for the same keys.

## Architecture

```
Classes/
    Controller/SuggestionsController.php    Frontend plugin (listAction)
    Service/SolrMltService.php              MLT / SMLT query service
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

### SMLT Solr SearchComponent

```
.ddev/typo3-solr/smlt-plugin/
    src/main/java/fr/coconweb/solr/smlt/
        SemanticMoreLikeThisComponent.java  Solr SearchComponent (Java 17)
    pom.xml                                Maven config (Solr 9.7+)
```

Solr API parameters:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `smlt` | `false` | Enable the component |
| `smlt.id` | *(required)* | Source document Solr ID |
| `smlt.count` | `5` | Number of results |
| `smlt.mode` | `hybrid` | `hybrid`, `vector_only`, `mlt_only` |
| `smlt.mltWeight` | `0.3` | MLT weight in hybrid mode |
| `smlt.vectorWeight` | `0.7` | Vector weight in hybrid mode |
| `smlt.vectorField` | `vector` | DenseVectorField name |
| `smlt.mltFields` | `title,content,keywords` | Fields for MLT |

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
    'typeLabel' => string,  // Fallback display label
    'score'     => float,   // Relevance score (raw for MLT, combined for SMLT)
    'mltScore'  => float,   // MLT sub-score (SMLT mode only)
    'knnScore'  => float,   // Vector sub-score (SMLT mode only)
    'snippet'   => string,  // Content excerpt (200 chars max)
    'uid'       => int,     // Record UID
    'mode'      => string,  // Which algorithm produced this result (mlt/smlt)
    'image'     => ?FileReference, // First media image (if showImage enabled)
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
