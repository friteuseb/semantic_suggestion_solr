# Semantic Suggestion Solr

TYPO3 extension that displays related content suggestions using Solr. Supports three similarity algorithms: **MLT** (lexical), **KNN** (semantic vector search via OpenAI or other LLM), and **Hybrid** (both combined).

## Similarity modes

### MLT — More Like This (default)

Solr's built-in MLT compares term frequency (TF-IDF) across configurable fields (title, content, keywords). Fast, no external model needed.

### KNN — K-Nearest Neighbors (vector search)

Uses dense vector embeddings to compare the **meaning** of documents, not just their words. Requires a text-to-vector model configured in Solr's model store (e.g. OpenAI `text-embedding-3-small`).

The extension retrieves the source document's content from Solr, sends it as a KNN query via the `knn_text_to_vector` parser, and Solr returns the nearest vectors.

### Hybrid — MLT + KNN combined

Runs both algorithms, normalizes their scores, and merges results with weighted scoring (40% MLT + 60% KNN). Documents found by both algorithms are ranked higher.

## How it works

### Language handling

EXT:solr maintains one core per site language. The plugin reads the current frontend language from the request and connects to the matching Solr core via `ConnectionManager->getConnectionByRootPageId($rootPageId, $languageUid)`. Results are always in the same language as the current page.

### Context detection

The plugin detects the current content type automatically:

- Standard page: lookup by `type:pages AND uid:{pageUid}`
- News detail (EXT:news): if `tx_news_pi1[news]` is present in the request, lookup by `type:tx_news_domain_model_news AND uid:{newsUid}`

## Requirements

- TYPO3 13.4+
- EXT:solr 13.0+ with a working index
- For KNN/Hybrid modes: Solr 9.7+ with the `llm` module enabled and a model configured

## Installation

```bash
composer require cyrilmarchand/semantic-suggestion-solr:@dev
```

Flush TYPO3 caches, then include the extension's static TypoScript in the site template.

## Vector search setup (KNN/Hybrid)

### 1. Enable the Solr LLM module

Add to your Docker/ddev Solr service environment:

```yaml
# .ddev/docker-compose.solr-llm.yaml
services:
  typo3-solr:
    environment:
      - SOLR_MODULES=llm
      - SOLR_OPTS=-Dsolr.vector.dimension=1536
```

Then restart ddev (`ddev restart`).

### 2. Register an embedding model

```bash
curl -X POST "http://localhost:8983/solr/core_en/schema/text-to-vector-model-store" \
  -H 'Content-type:application/json' \
  --data-binary '{
    "class": "dev.langchain4j.model.openai.OpenAiEmbeddingModel",
    "name": "llm",
    "params": {
      "apiKey": "sk-...",
      "modelName": "text-embedding-3-small"
    }
  }'
```

Supported providers (via langchain4j): OpenAI, Ollama, HuggingFace, Mistral, Cohere.

### 3. Enable vector search in TypoScript

```typoscript
plugin.tx_solr.search.query.type = 1
```

### 4. Re-index all content

Re-initialize the Index Queue and run the scheduler. Each document will be sent to the embedding API during indexing.

### 5. Switch the similarity mode

In TypoScript constants or the plugin FlexForm, set:

```typoscript
plugin.tx_semanticsuggestionsolr_suggestions.settings.similarityMode = knn
```

## Usage

### As a content element

Insert the plugin "Similar content (Solr)" via the backend. The FlexForm provides per-instance settings:

- **Similarity algorithm**: MLT, KNN, or Hybrid
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

## Configuration

### TypoScript constants

All settings under `plugin.tx_semanticsuggestionsolr_suggestions.settings`.

#### Similarity algorithm

| Setting | Default | Description |
|---------|---------|-------------|
| `similarityMode` | `mlt` | Algorithm: `mlt`, `knn`, or `hybrid` |
| `maxResults` | `6` | Max suggestions returned |

#### MLT-specific parameters

| Setting | Default | Description |
|---------|---------|-------------|
| `minTermFreq` | `1` | Minimum term frequency (mlt.mintf) |
| `minDocFreq` | `1` | Minimum document frequency (mlt.mindf) |
| `mltFields` | `content,title,keywords` | Fields used for similarity |
| `boostFields` | `content^0.5,title^1.2,keywords^2.0` | Field weights (mlt.qf) |

#### KNN-specific parameters

| Setting | Default | Description |
|---------|---------|-------------|
| `vectorTopK` | `50` | Number of nearest neighbors retrieved before filtering |
| `vectorMinSimilarity` | `0.5` | Minimum cosine similarity threshold (0.0–1.0) |
| `vectorModelName` | `llm` | Name of the model in Solr's model store |

#### Display

| Setting | Default | Description |
|---------|---------|-------------|
| `allowedTypes` | *(empty)* | Whitelist of Solr types. Empty = all. Overridden by FlexForm. |
| `excludeContentTypes` | *(empty)* | Blacklist. Only used when `allowedTypes` is empty. |
| `filterByPids` | *(empty)* | Restrict to specific parent pages / storage folders |
| `showScore` | `0` | Display the relevance score + active mode badge |
| `showContentType` | `1` | Display a type badge (Page, News, etc.) |
| `showImage` | `1` | Display the first media image of each suggestion |

### FlexForm vs TypoScript

FlexForm settings (per content element) override TypoScript settings for the same keys.

### Example override

```typoscript
plugin.tx_semanticsuggestionsolr_suggestions.settings {
    similarityMode = hybrid
    maxResults = 4
    boostFields = title^2.0,keywords^3.0,content^0.3
    vectorTopK = 100
    showScore = 1
}
```

## Localization

All frontend labels are in XLIFF files. Shipped translations: English (default), French.

Type badges use the key pattern `type.{solr_type}`. To add labels for custom indexed types, override the XLIFF or add keys in your site package.

## Architecture

```
Classes/
    Controller/SuggestionsController.php    Frontend plugin (listAction)
    Service/SolrMltService.php              MLT / KNN / Hybrid query service
Configuration/
    FlexForms/Suggestions.xml               Per-instance backend settings
    Services.php                            Dependency injection
    TCA/Overrides/tt_content.php            Plugin + FlexForm registration
    TypoScript/
        constants.typoscript                Configurable defaults (FR labels)
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
    'typeLabel' => string,  // Fallback display label
    'score'     => float,   // Relevance score (raw for MLT/KNN, combined for Hybrid)
    'snippet'   => string,  // Content excerpt (200 chars max)
    'uid'       => int,     // Record UID
    'mode'      => string,  // Which algorithm produced this result (mlt/knn/hybrid)
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
