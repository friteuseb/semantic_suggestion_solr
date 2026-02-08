<?php

declare(strict_types=1);

namespace CyrilMarchand\SemanticSuggestionSolr\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persists Solr-based similarity results to tx_semanticsuggestion_similarities
 * so that page_link_insights can visualize them.
 */
class SimilarityCacheService
{
    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * Write page-type suggestions to the similarities table.
     *
     * Deletes existing Solr-sourced rows for the given page+language first,
     * then inserts the fresh set.
     */
    public function persist(int $pageId, int $rootPageId, int $languageUid, array $suggestions): void
    {
        $pageSuggestions = array_filter($suggestions, static fn(array $s) =>
            ($s['type'] ?? '') === 'pages' && ($s['uid'] ?? 0) > 0
        );

        $connection = $this->connectionPool->getConnectionForTable('tx_semanticsuggestion_similarities');

        $connection->delete('tx_semanticsuggestion_similarities', [
            'page_id' => $pageId,
            'sys_language_uid' => $languageUid,
            'source' => 'solr',
        ]);

        $now = time();
        foreach ($pageSuggestions as $s) {
            $connection->insert('tx_semanticsuggestion_similarities', [
                'page_id' => $pageId,
                'similar_page_id' => (int)$s['uid'],
                'similarity_score' => (float)$s['score'],
                'root_page_id' => $rootPageId,
                'sys_language_uid' => $languageUid,
                'source' => 'solr',
                'crdate' => $now,
                'tstamp' => $now,
            ]);
        }
    }
}
