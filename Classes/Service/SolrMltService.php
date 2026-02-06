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

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * Find similar documents for a page.
     *
     * @param int $pageUid The current page UID
     * @param array $settings TypoScript settings (merged with FlexForm)
     * @param int $languageUid Current frontend language UID
     * @return array Normalized suggestion array
     */
    public function findSimilar(int $pageUid, array $settings, int $languageUid = 0): array
    {
        return $this->findSimilarByType('pages', $pageUid, $settings, $languageUid);
    }

    /**
     * Find similar documents for any indexed content type.
     *
     * @param string $type Solr document type (e.g. 'pages', 'tx_news_domain_model_news')
     * @param int $uid UID of the record
     * @param array $settings TypoScript settings (merged with FlexForm)
     * @param int $languageUid Current frontend language UID
     * @return array Normalized suggestion array
     */
    public function findSimilarByType(string $type, int $uid, array $settings, int $languageUid = 0): array
    {
        try {
            $rootPageId = $this->resolveRootPageId($uid);
            $connection = $this->connectionManager->getConnectionByRootPageId($rootPageId, $languageUid);
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

            // Step 3: Apply type filters (allowedTypes whitelist or excludeContentTypes blacklist)
            $this->applyTypeFilters($mltQuery, $settings);

            $result = $client->moreLikeThis($mltQuery);

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
     * Apply content type filter queries based on settings.
     * allowedTypes (whitelist from FlexForm) takes precedence over excludeContentTypes (TypoScript blacklist).
     */
    private function applyTypeFilters(object $mltQuery, array $settings): void
    {
        $allowedTypes = $this->parseTypeList($settings['allowedTypes'] ?? '');
        if ($allowedTypes !== []) {
            $parts = array_map(
                fn(string $t) => 'type:' . $this->escapeQueryValue($t),
                $allowedTypes
            );
            $mltQuery->createFilterQuery('allowedTypes')
                ->setQuery(implode(' OR ', $parts));
            return;
        }

        $excludeTypes = $this->parseTypeList($settings['excludeContentTypes'] ?? '');
        if ($excludeTypes !== []) {
            $parts = array_map(
                fn(string $t) => '-type:' . $this->escapeQueryValue($t),
                $excludeTypes
            );
            $mltQuery->createFilterQuery('excludeTypes')
                ->setQuery(implode(' AND ', $parts));
        }
    }

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
                'typeLabel' => $this->buildTypeLabel($type),
                'score' => $doc->score ?? 0.0,
                'snippet' => $this->buildSnippet($doc),
                'uid' => (int)($doc->uid ?? 0),
            ];
        }

        return $suggestions;
    }

    /**
     * Build a fallback type label from the Solr type field.
     * The template should prefer f:translate with key "type.{type}" over this value.
     */
    private function buildTypeLabel(string $type): string
    {
        // Strip common TYPO3 prefixes to produce a readable fallback
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

    /**
     * Normalize boost fields from comma-separated to space-separated.
     * Solarium expects: "content^0.5 title^1.2 keywords^2.0"
     */
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
