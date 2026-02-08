<?php

declare(strict_types=1);

namespace CyrilMarchand\SemanticSuggestionSolr\Service;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

/**
 * Service for querying Solr to find similar documents.
 *
 * Supports two modes:
 *  - MLT (More Like This): lexical similarity via TF-IDF. No model needed.
 *  - SMLT (Semantic More Like This): a custom Solr SearchComponent that
 *    combines classical MLT with KNN vector search on pre-indexed embeddings.
 *    Returns results in a separate "semanticMoreLikeThis" response section.
 *    Requires the SMLT JAR deployed in Solr and a DenseVectorField with
 *    stored=true in the schema.
 *
 * In "auto" mode (default), the algorithm is chosen automatically:
 *  - SMLT if vector search is enabled (plugin.tx_solr.search.query.type >= 1)
 *  - MLT otherwise
 *
 * @see https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Configuration/Reference/TxSolrSearch.html
 */
class SolrMltService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly SiteFinder $siteFinder,
        private readonly FileRepository $fileRepository,
        private readonly ConfigurationManagerInterface $configurationManager,
    ) {}

    /**
     * Find similar documents for a page.
     */
    public function findSimilar(int $pageUid, array $settings, int $languageUid = 0): array
    {
        return $this->findSimilarByType('pages', $pageUid, $settings, $languageUid);
    }

    /**
     * Find similar documents for any indexed content type.
     *
     * When similarityMode is "auto" (default), the algorithm is chosen
     * based on EXT:solr's query.type setting:
     *  - query.type >= 1 -> SMLT (vector search is enabled, embeddings are indexed)
     *  - query.type = 0  -> MLT (no vectors available)
     */
    public function findSimilarByType(string $type, int $uid, array $settings, int $languageUid = 0): array
    {
        $mode = $settings['similarityMode'] ?? 'auto';

        if ($mode === 'auto') {
            $mode = $this->isVectorSearchEnabled() ? 'smlt' : 'mlt';
        }

        return match ($mode) {
            'smlt' => $this->findSimilarBySmlt($type, $uid, $settings, $languageUid),
            default => $this->findSimilarByMlt($type, $uid, $settings, $languageUid),
        };
    }

    /**
     * Check if EXT:solr's vector search is enabled (query.type >= 1).
     */
    private function isVectorSearchEnabled(): bool
    {
        $typoScript = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );

        return (int)($typoScript['plugin.']['tx_solr.']['search.']['query.']['type'] ?? 0) > 0;
    }

    // ---------------------------------------------------------------
    // MLT (More Like This) – lexical similarity via TF-IDF
    // ---------------------------------------------------------------

    private function findSimilarByMlt(string $type, int $uid, array $settings, int $languageUid): array
    {
        try {
            $rootPageId = $this->resolveRootPageId($uid);
            $connection = $this->connectionManager->getConnectionByRootPageId($rootPageId, $languageUid);
            $client = $connection->getReadService()->getClient();

            $documentId = $this->resolveDocumentId($client, $type, $uid);
            if ($documentId === null) {
                $this->logger?->info('No Solr document found for type={type} uid={uid}', [
                    'type' => $type,
                    'uid' => $uid,
                ]);
                return [];
            }

            $mltQuery = $client->createMoreLikeThis();
            $mltQuery->setQuery('id:' . $this->escapeQueryValue($documentId));
            $mltQuery->setMltFields($settings['mltFields'] ?? 'content,title,keywords');
            $mltQuery->setMinimumTermFrequency((int)($settings['minTermFreq'] ?? 1));
            $mltQuery->setMinimumDocumentFrequency((int)($settings['minDocFreq'] ?? 1));
            $mltQuery->setBoost(true);
            $mltQuery->setQueryFields(
                $this->normalizeBoostFields($settings['boostFields'] ?? 'content^0.5 title^1.2 keywords^2.0')
            );
            $mltQuery->setRows((int)($settings['maxResults'] ?? 6));
            $mltQuery->setFields('*, score');
            $mltQuery->setMatchInclude(false);

            $this->applyTypeFilters($mltQuery, $settings);
            $this->applyPidFilter($mltQuery, $settings);

            $result = $client->moreLikeThis($mltQuery);
            $suggestions = $this->parseResults($result, 'mlt');
            $suggestions = $this->filterByMinScore($suggestions, $settings);

            if (!empty($settings['showImage'])) {
                $suggestions = $this->enrichWithImages($suggestions);
            }

            return $suggestions;
        } catch (\Throwable $e) {
            $this->logger?->error('Solr MLT query failed: {message}', [
                'message' => $e->getMessage(),
                'type' => $type,
                'uid' => $uid,
            ]);
            return [];
        }
    }

    // ---------------------------------------------------------------
    // SMLT (Semantic More Like This) – Solr SearchComponent combining
    // classical MLT (TF-IDF) with KNN vector search server-side.
    // Requires the SMLT JAR deployed as last-component on the handler.
    // ---------------------------------------------------------------

    private function findSimilarBySmlt(string $type, int $uid, array $settings, int $languageUid): array
    {
        try {
            $rootPageId = $this->resolveRootPageId($uid);
            $connection = $this->connectionManager->getConnectionByRootPageId($rootPageId, $languageUid);
            $client = $connection->getReadService()->getClient();

            $documentId = $this->resolveDocumentId($client, $type, $uid);
            if ($documentId === null) {
                $this->logger?->info('No Solr document found for SMLT, type={type} uid={uid}', [
                    'type' => $type,
                    'uid' => $uid,
                ]);
                return [];
            }

            $maxResults = (int)($settings['maxResults'] ?? 6);
            $smltMode = $settings['smltMode'] ?? 'hybrid';
            $mltWeight = (float)($settings['smltMltWeight'] ?? 0.3);
            $vectorWeight = (float)($settings['smltVectorWeight'] ?? 0.7);

            $select = $client->createSelect();
            // Main query is not relevant; SMLT component provides its own results
            $select->setQuery('*:*');
            $select->setRows(0);

            // SMLT SearchComponent parameters
            $select->addParam('smlt', 'true');
            $select->addParam('smlt.id', $documentId);
            $select->addParam('smlt.count', (string)$maxResults);
            $select->addParam('smlt.mode', $smltMode);
            $select->addParam('smlt.mltWeight', (string)$mltWeight);
            $select->addParam('smlt.vectorWeight', (string)$vectorWeight);
            $select->addParam('smlt.fl', 'id,title,url,type,uid,pid,content');
            $select->addParam('smlt.vectorField', $settings['smltVectorField'] ?? 'vector');
            $select->addParam('smlt.mltFields', $settings['mltFields'] ?? 'content,title,keywords');

            // Apply filter queries so SMLT respects type/pid restrictions
            $this->applyTypeFilters($select, $settings);
            $this->applyPidFilter($select, $settings);

            $result = $client->select($select);
            $responseData = $result->getData();

            $suggestions = $this->parseSmltResponse($responseData, $documentId);
            $suggestions = $this->filterByMinScore($suggestions, $settings);

            if (!empty($settings['showImage'])) {
                $suggestions = $this->enrichWithImages($suggestions);
            }

            return $suggestions;
        } catch (\Throwable $e) {
            $this->logger?->error('Solr SMLT query failed: {message}', [
                'message' => $e->getMessage(),
                'type' => $type,
                'uid' => $uid,
            ]);
            return [];
        }
    }

    /**
     * Parse the semanticMoreLikeThis section from the Solr response.
     *
     * Handles three possible response structures depending on json.nl mode:
     * 1. json.nl=flat (Solarium default): [ "docId", { "numFound": N, "docs": [...] } ]
     * 2. json.nl=map: { "docId": { "numFound": N, "docs": [...] } }
     * 3. Flat: { "numFound": N, "docs": [...] }
     */
    private function parseSmltResponse(array $responseData, string $sourceDocId): array
    {
        $smltSection = $responseData['semanticMoreLikeThis'] ?? [];
        if (empty($smltSection)) {
            $this->logger?->info('No semanticMoreLikeThis section in Solr response');
            return [];
        }

        $docs = null;

        // json.nl=flat format: sequential array [key, value, key, value, ...]
        if (array_is_list($smltSection)) {
            for ($i = 0; $i + 1 < \count($smltSection); $i += 2) {
                if (\is_array($smltSection[$i + 1]) && isset($smltSection[$i + 1]['docs'])) {
                    $docs = $smltSection[$i + 1]['docs'];
                    break;
                }
            }
        } elseif (isset($smltSection[$sourceDocId]['docs'])) {
            // json.nl=map keyed by source document ID
            $docs = $smltSection[$sourceDocId]['docs'];
        } elseif (isset($smltSection['docs'])) {
            // Flat structure (no named list wrapping)
            $docs = $smltSection['docs'];
        } else {
            // Try first key for unexpected ID
            $firstKey = array_key_first($smltSection);
            if (\is_array($smltSection[$firstKey]) && isset($smltSection[$firstKey]['docs'])) {
                $docs = $smltSection[$firstKey]['docs'];
            }
        }

        if ($docs === null) {
            $this->logger?->info('Unexpected SMLT response structure, keys: {keys}', [
                'keys' => implode(', ', array_keys($smltSection)),
            ]);
            return [];
        }

        $suggestions = [];
        foreach ($docs as $doc) {
            $docType = $doc['type'] ?? 'pages';
            $suggestions[] = [
                'title' => $doc['title'] ?? '',
                'url' => $doc['url'] ?? '',
                'type' => $docType,
                'typeLabel' => $this->buildTypeLabel($docType),
                'score' => (float)($doc['combinedScore'] ?? $doc['score'] ?? 0.0),
                'mltScore' => (float)($doc['mltScore'] ?? 0.0),
                'knnScore' => (float)($doc['vectorScore'] ?? 0.0),
                'snippet' => $this->buildSnippetFromString($doc['content'] ?? ''),
                'uid' => (int)($doc['uid'] ?? 0),
                'mode' => 'smlt',
            ];
        }

        return $suggestions;
    }

    // ---------------------------------------------------------------
    // Score filtering
    // ---------------------------------------------------------------

    /**
     * Filter suggestions below the minimum score threshold.
     *
     * Supports two modes:
     * - Absolute threshold (minScore): filters suggestions with score < minScore.
     *   For SMLT (normalized 0-1), typical values: 0.3-0.7.
     *   For MLT (raw TF-IDF), values depend on corpus size.
     * - Relative threshold (minScoreRatio): filters suggestions with score < (topScore * ratio).
     *   E.g. 0.5 = keep only suggestions scoring at least 50% of the best one.
     *   Works consistently across both modes.
     *
     * Both can be combined; a suggestion must pass both thresholds.
     */
    private function filterByMinScore(array $suggestions, array $settings): array
    {
        $minScore = (float)($settings['minScore'] ?? 0.0);
        $minScoreRatio = (float)($settings['minScoreRatio'] ?? 0.0);

        if ($minScore <= 0.0 && $minScoreRatio <= 0.0) {
            return $suggestions;
        }

        $topScore = 0.0;
        if ($minScoreRatio > 0.0 && !empty($suggestions)) {
            $topScore = (float)$suggestions[0]['score'];
        }

        return array_values(array_filter($suggestions, static function (array $s) use ($minScore, $minScoreRatio, $topScore) {
            $score = (float)$s['score'];
            if ($minScore > 0.0 && $score < $minScore) {
                return false;
            }
            if ($minScoreRatio > 0.0 && $topScore > 0.0 && $score < ($topScore * $minScoreRatio)) {
                return false;
            }
            return true;
        }));
    }

    // ---------------------------------------------------------------
    // Document resolution helpers
    // ---------------------------------------------------------------

    private function resolveDocumentId(object $client, string $type, int $uid): ?string
    {
        $select = $client->createSelect();
        $select->setQuery(sprintf('type:%s AND uid:%d', $this->escapeQueryValue($type), $uid));
        $select->setFields('id');
        $select->setRows(1);

        $result = $client->select($select);

        if ($result->getNumFound() === 0) {
            return null;
        }

        foreach ($result as $doc) {
            return (string)$doc->id;
        }

        return null;
    }

    // ---------------------------------------------------------------
    // Filter helpers (shared by MLT and SMLT queries)
    // ---------------------------------------------------------------

    private function applyTypeFilters(object $query, array $settings): void
    {
        $allowedTypes = $this->parseTypeList($settings['allowedTypes'] ?? '');
        if ($allowedTypes !== []) {
            $parts = array_map(
                fn(string $t) => 'type:' . $this->escapeQueryValue($t),
                $allowedTypes
            );
            $query->createFilterQuery('allowedTypes')
                ->setQuery(implode(' OR ', $parts));
            return;
        }

        $excludeTypes = $this->parseTypeList($settings['excludeContentTypes'] ?? '');
        if ($excludeTypes !== []) {
            $parts = array_map(
                fn(string $t) => '-type:' . $this->escapeQueryValue($t),
                $excludeTypes
            );
            $query->createFilterQuery('excludeTypes')
                ->setQuery(implode(' AND ', $parts));
        }
    }

    private function applyPidFilter(object $query, array $settings): void
    {
        $pids = $this->parseTypeList($settings['filterByPids'] ?? '');
        if ($pids === []) {
            return;
        }
        $pidValues = array_map('intval', $pids);
        $query->createFilterQuery('filterByPids')
            ->setQuery('pid:(' . implode(' OR ', $pidValues) . ')');
    }

    // ---------------------------------------------------------------
    // Result parsing
    // ---------------------------------------------------------------

    private function parseResults(object $result, string $mode = 'mlt'): array
    {
        $suggestions = [];

        foreach ($result as $doc) {
            $type = $doc->type ?? 'pages';
            $url = $doc->url ?? '';

            $suggestions[] = [
                'title' => $doc->title ?? '',
                'url' => $url,
                'type' => $type,
                'typeLabel' => $this->buildTypeLabel($type),
                'score' => $doc->score ?? 0.0,
                'snippet' => $this->buildSnippet($doc),
                'uid' => (int)($doc->uid ?? 0),
                'mode' => $mode,
            ];
        }

        return $suggestions;
    }

    private function buildTypeLabel(string $type): string
    {
        $label = str_replace(['tx_', '_domain_model_'], ['', ' '], $type);
        return ucfirst(trim($label));
    }

    private function buildSnippet(object $doc): string
    {
        $content = $doc->content ?? '';
        if (is_array($content)) {
            $content = implode(' ', $content);
        }

        return $this->buildSnippetFromString((string)$content);
    }

    private function buildSnippetFromString(string $content): string
    {
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        if (mb_strlen($content) > 200) {
            $content = mb_substr($content, 0, 200);
            $lastSpace = mb_strrpos($content, ' ');
            if ($lastSpace !== false && $lastSpace > 150) {
                $content = mb_substr($content, 0, $lastSpace);
            }
            $content .= "\u{2026}";
        }

        return $content;
    }

    // ---------------------------------------------------------------
    // Image enrichment
    // ---------------------------------------------------------------

    private function enrichWithImages(array $suggestions): array
    {
        foreach ($suggestions as &$suggestion) {
            $suggestion['image'] = $this->resolveFirstImage(
                $suggestion['type'] ?? 'pages',
                $suggestion['uid'] ?? 0
            );
        }
        return $suggestions;
    }

    private function resolveFirstImage(string $type, int $uid): ?FileReference
    {
        if ($uid <= 0) {
            return null;
        }
        try {
            $tableName = match ($type) {
                'pages' => 'pages',
                default => $type,
            };
            $fieldName = match ($type) {
                'tx_news_domain_model_news' => 'fal_media',
                default => 'media',
            };
            $references = $this->fileRepository->findByRelation($tableName, $fieldName, $uid);
            return $references[0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------
    // Utilities
    // ---------------------------------------------------------------

    private function resolveRootPageId(int $pageUid): int
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
            return $site->getRootPageId();
        } catch (\Throwable) {
            $sites = $this->siteFinder->getAllSites();
            foreach ($sites as $site) {
                return $site->getRootPageId();
            }
            return 1;
        }
    }

    private function normalizeBoostFields(string $boostFields): string
    {
        return str_replace(',', ' ', $boostFields);
    }

    private function parseTypeList(string $types): array
    {
        if (trim($types) === '') {
            return [];
        }

        return array_filter(
            array_map('trim', explode(',', $types)),
            fn(string $v) => $v !== ''
        );
    }

    private function escapeQueryValue(string $value): string
    {
        $specialChars = ['+', '-', '&&', '||', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '\\', '/'];
        $escaped = $value;
        foreach ($specialChars as $char) {
            $escaped = str_replace($char, '\\' . $char, $escaped);
        }
        return $escaped;
    }
}
