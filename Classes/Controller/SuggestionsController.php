<?php

declare(strict_types=1);

namespace CyrilMarchand\SemanticSuggestionSolr\Controller;

use CyrilMarchand\SemanticSuggestionSolr\Service\SolrMltService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Frontend controller for displaying Solr MLT suggestions.
 */
class SuggestionsController extends ActionController
{
    public function __construct(
        private readonly SolrMltService $solrMltService,
    ) {}

    /**
     * Display similar content suggestions for the current page or record.
     */
    public function listAction(): ResponseInterface
    {
        $pageId = $this->resolveCurrentPageId();

        // Detect if we are on a news detail page
        $newsUid = $this->resolveNewsUid();

        if ($newsUid > 0) {
            $suggestions = $this->solrMltService->findSimilarByType(
                'tx_news_domain_model_news',
                $newsUid,
                $this->settings
            );
        } else {
            $suggestions = $this->solrMltService->findSimilar($pageId, $this->settings);
        }

        $this->view->assignMultiple([
            'suggestions' => $suggestions,
            'settings' => $this->settings,
        ]);

        return $this->htmlResponse();
    }

    /**
     * Get the current page ID from the request routing.
     */
    private function resolveCurrentPageId(): int
    {
        $routing = $this->request->getAttribute('routing');
        if ($routing !== null && method_exists($routing, 'getPageId')) {
            return (int)$routing->getPageId();
        }

        return 0;
    }

    /**
     * Check if we are on a news detail page and return the news UID.
     */
    private function resolveNewsUid(): int
    {
        $queryParams = $this->request->getQueryParams();

        // EXT:news uses tx_news_pi1[news] as the detail parameter
        if (isset($queryParams['tx_news_pi1']['news'])) {
            return (int)$queryParams['tx_news_pi1']['news'];
        }

        return 0;
    }
}
