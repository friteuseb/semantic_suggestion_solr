<?php

declare(strict_types=1);

namespace CyrilMarchand\SemanticSuggestionSolr\Service;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Service for querying Solr More Like This (MLT) to find similar documents.
 */
class SolrMltService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const TYPE_LABELS = [
        'pages' => 'Page',
        'tx_news_domain_model_news' => 'Actualit\u00e9',
    ];

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * Find similar documents for a page.
     *
     * @param int $pageUid The current page UID
     * @param array $settings TypoScript settings
     * @return array Normalized suggestion array
     */
    public function findSimilar(int $pageUid, array $settings): array
    {
        return $this->findSimilarByType('pages', $pageUid, $settings);
    }

    /**
     * Find similar documents for any indexed content type.
     *
     * @param string $type Solr document type (e.g. 'pages', 'tx_news_domain_model_news')
     * @param int $uid UID of the record
     * @param array $settings TypoScript settings
     * @return array Normalized suggestion array
     */
    public function findSimilarByType(string $type, int $uid, array $settings): array
    {
        try {
            $rootPageId = $this->resolveRootPageId($uid, $type);
            $connection = $this->connectionManager->getConnectionByRootPageId($rootPageId);
            $client = $connection->getReadService()->getClient();

            // Step 1: Find the Solr document ID for this record
            $documentId = $this->resolveDocumentId($client, $type, $uid);
            if ($documentId === null) {
                $this->logger?->info('No Solr document found for type={type} uid={uid}', [
                    'type' => $type,
                    'uid' => $uid,
                ]);
                return [];
            }

            // Step 2: Execute MLT query
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

            // Step 3: Add filter for excluded content types
            $excludeTypes = $this->parseExcludeTypes($settings['excludeContentTypes'] ?? '');
            if ($excludeTypes !== []) {
                $filterParts = array_map(
                    fn(string $t) => '-type:' . $this->escapeQueryValue($t),
                    $excludeTypes
                );
                $mltQuery->createFilterQuery('excludeTypes')
                    ->setQuery(implode(' AND ', $filterParts));
            }

            $result = $client->moreLikeThis($mltQuery);

            // Step 4: Parse results into normalized suggestions
            return $this->parseResults($result);
        } catch (\Throwable $e) {
            $this->logger?->error('Solr MLT query failed: {message}', [
                'message' => $e->getMessage(),
                'type' => $type,
                'uid' => $uid,
            ]);
            return [];
        }
    }

    /**
     * Resolve the Solr document ID for a given record.
     */
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
     * Parse MLT result into normalized suggestion array.
     */
    private function parseResults(object $result): array
    {
        $suggestions = [];

        foreach ($result as $doc) {
            $type = $doc->type ?? 'pages';
            $url = $doc->url ?? '';

            $suggestions[] = [
                'title' => $doc->title ?? '',
                'url' => $url,
                'type' => $type,
                'typeLabel' => self::TYPE_LABELS[$type] ?? ucfirst(
                    str_replace(['tx_', '_domain_model_'], ['', ' '], $type)
                ),
                'score' => $doc->score ?? 0.0,
                'snippet' => $this->buildSnippet($doc),
                'uid' => (int)($doc->uid ?? 0),
            ];
        }

        return $suggestions;
    }

    /**
     * Build a text snippet from the document content.
     */
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

    /**
     * Resolve the root page ID for a given page UID.
     */
    private function resolveRootPageId(int $pageUid, string $type): int
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
            return $site->getRootPageId();
        } catch (\Throwable) {
            // Fallback: use the first available site
            $sites = $this->siteFinder->getAllSites();
            foreach ($sites as $site) {
                return $site->getRootPageId();
            }
            return 1;
        }
    }

    /**
     * Normalize boost fields from comma-separated to space-separated format.
     * Solarium expects: "content^0.5 title^1.2 keywords^2.0"
     */
    private function normalizeBoostFields(string $boostFields): string
    {
        return str_replace(',', ' ', $boostFields);
    }

    /**
     * Parse comma-separated exclude types into array, filtering empty values.
     */
    private function parseExcludeTypes(string $excludeTypes): array
    {
        if (trim($excludeTypes) === '') {
            return [];
        }

        return array_filter(
            array_map('trim', explode(',', $excludeTypes)),
            fn(string $v) => $v !== ''
        );
    }

    /**
     * Escape special Solr query characters in a value.
     */
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
