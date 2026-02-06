<?php

declare(strict_types=1);

namespace CyrilMarchand\SemanticSuggestionSolr\Service;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Service for querying Solr to find similar documents.
 * Supports MLT (More Like This), KNN (vector/semantic) and hybrid modes.
 */
class SolrMltService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly SiteFinder $siteFinder,
        private readonly FileRepository $fileRepository,
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
     * Routes to the appropriate algorithm based on settings.similarityMode.
     */
    public function findSimilarByType(string $type, int $uid, array $settings, int $languageUid = 0): array
    {
        $mode = $settings['similarityMode'] ?? 'mlt';

        return match ($mode) {
            'knn' => $this->findSimilarByKnn($type, $uid, $settings, $languageUid),
            'hybrid' => $this->findSimilarHybrid($type, $uid, $settings, $languageUid),
            default => $this->findSimilarByMlt($type, $uid, $settings, $languageUid),
        };
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
    // KNN (K-Nearest Neighbors) – semantic similarity via vectors
    // Requires an LLM model configured in Solr's model store.
    // ---------------------------------------------------------------

    private function findSimilarByKnn(string $type, int $uid, array $settings, int $languageUid): array
    {
        try {
            $rootPageId = $this->resolveRootPageId($uid);
            $connection = $this->connectionManager->getConnectionByRootPageId($rootPageId, $languageUid);
            $client = $connection->getReadService()->getClient();

            // Retrieve the source document content to use as KNN query text
            $sourceText = $this->resolveDocumentContent($client, $type, $uid);
            if ($sourceText === null) {
                $this->logger?->info('No content found for KNN query, type={type} uid={uid}', [
                    'type' => $type,
                    'uid' => $uid,
                ]);
                return [];
            }

            $topK = (int)($settings['vectorTopK'] ?? 50);
            $maxResults = (int)($settings['maxResults'] ?? 6);
            $modelName = $settings['vectorModelName'] ?? 'llm';

            $select = $client->createSelect();
            $helper = $select->getHelper();

            // Build the KNN text-to-vector query via Solarium helper
            $knnQueryStr = $helper->knnTextToVector($modelName, 'vector', $sourceText, $topK);
            $select->setQuery($knnQueryStr);
            $select->setRows($maxResults);
            $select->setFields(['*', 'score']);

            // Exclude the source document itself
            $select->createFilterQuery('excludeSelf')
                ->setQuery(sprintf('-(type:%s AND uid:%d)', $this->escapeQueryValue($type), $uid));

            $this->applyTypeFilters($select, $settings);
            $this->applyPidFilter($select, $settings);

            $result = $client->select($select);
            $suggestions = $this->parseResults($result, 'knn');

            if (!empty($settings['showImage'])) {
                $suggestions = $this->enrichWithImages($suggestions);
            }

            return $suggestions;
        } catch (\Throwable $e) {
            $this->logger?->error('Solr KNN query failed: {message}', [
                'message' => $e->getMessage(),
                'type' => $type,
                'uid' => $uid,
            ]);
            return [];
        }
    }

    // ---------------------------------------------------------------
    // Hybrid – combines MLT (lexical) and KNN (semantic) results
    // ---------------------------------------------------------------

    private function findSimilarHybrid(string $type, int $uid, array $settings, int $languageUid): array
    {
        // Request more results from each sub-query to have enough after merging
        $maxResults = (int)($settings['maxResults'] ?? 6);
        $subSettings = $settings;
        $subSettings['maxResults'] = $maxResults * 2;

        $mltResults = $this->findSimilarByMlt($type, $uid, $subSettings, $languageUid);
        $knnResults = $this->findSimilarByKnn($type, $uid, $subSettings, $languageUid);

        return $this->mergeResults($mltResults, $knnResults, $maxResults);
    }

    /**
     * Merge MLT and KNN results with normalized combined scoring.
     * MLT weight = 0.4, KNN weight = 0.6 (semantic similarity weighted higher).
     */
    private function mergeResults(array $mltResults, array $knnResults, int $maxResults): array
    {
        $mltResults = $this->normalizeScores($mltResults);
        $knnResults = $this->normalizeScores($knnResults);

        $merged = [];

        foreach ($mltResults as $r) {
            $key = $r['type'] . ':' . $r['uid'];
            $merged[$key] = $r;
            $merged[$key]['mltScore'] = $r['score'];
            $merged[$key]['knnScore'] = 0.0;
            $merged[$key]['mode'] = 'mlt';
        }

        foreach ($knnResults as $r) {
            $key = $r['type'] . ':' . $r['uid'];
            if (isset($merged[$key])) {
                $merged[$key]['knnScore'] = $r['score'];
                $merged[$key]['mode'] = 'hybrid';
            } else {
                $merged[$key] = $r;
                $merged[$key]['mltScore'] = 0.0;
                $merged[$key]['knnScore'] = $r['score'];
                $merged[$key]['mode'] = 'knn';
            }
        }

        // Combined score: MLT 40% + KNN 60%
        foreach ($merged as &$item) {
            $item['score'] = 0.4 * $item['mltScore'] + 0.6 * $item['knnScore'];
        }

        usort($merged, fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($merged, 0, $maxResults);
    }

    private function normalizeScores(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $maxScore = max(array_column($results, 'score'));
        if ($maxScore <= 0) {
            return $results;
        }

        foreach ($results as &$r) {
            $r['score'] = $r['score'] / $maxScore;
        }

        return $results;
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

    /**
     * Retrieve title + content of a Solr document to use as KNN query text.
     * Truncated to 2000 chars to stay within embedding model limits.
     */
    private function resolveDocumentContent(object $client, string $type, int $uid): ?string
    {
        $select = $client->createSelect();
        $select->setQuery(sprintf('type:%s AND uid:%d', $this->escapeQueryValue($type), $uid));
        $select->setFields('title,content');
        $select->setRows(1);

        $result = $client->select($select);

        if ($result->getNumFound() === 0) {
            return null;
        }

        foreach ($result as $doc) {
            $title = $doc->title ?? '';
            $content = $doc->content ?? '';
            if (is_array($content)) {
                $content = implode(' ', $content);
            }
            $content = strip_tags((string)$content);
            $content = preg_replace('/\s+/', ' ', $content);

            $text = trim($title . ' ' . $content);
            // Truncate for embedding model input limits
            if (mb_strlen($text) > 2000) {
                $text = mb_substr($text, 0, 2000);
            }
            return $text ?: null;
        }

        return null;
    }

    // ---------------------------------------------------------------
    // Filter helpers (shared by MLT and KNN queries)
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

        $content = strip_tags((string)$content);
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
