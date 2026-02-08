<?php

declare(strict_types=1);

namespace Cywolf\SemanticSuggestionSolr\Controller;

use Cywolf\SemanticSuggestionSolr\Service\SimilarityCacheService;
use Cywolf\SemanticSuggestionSolr\Service\SolrMltService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Frontend controller for displaying Solr MLT suggestions.
 */
class SuggestionsController extends ActionController
{
    public function __construct(
        private readonly SolrMltService $solrMltService,
        private readonly SimilarityCacheService $similarityCacheService,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * Display similar content suggestions for the current page or record.
     */
    public function listAction(): ResponseInterface
    {
        $pageId = $this->resolveCurrentPageId();
        $languageUid = $this->resolveLanguageUid();
        $newsUid = $this->resolveNewsUid();

        if ($newsUid > 0) {
            $suggestions = $this->solrMltService->findSimilarByType(
                'tx_news_domain_model_news',
                $newsUid,
                $this->settings,
                $languageUid
            );
        } else {
            $suggestions = $this->solrMltService->findSimilar(
                $pageId,
                $this->settings,
                $languageUid
            );
        }

        // Persist page-type suggestions for page_link_insights visualization
        if ($newsUid === 0 && $pageId > 0 && !empty($suggestions)) {
            try {
                $site = $this->siteFinder->getSiteByPageId($pageId);
                $rootPageId = $site->getRootPageId();
            } catch (\Throwable) {
                $rootPageId = 0;
            }
            if ($rootPageId > 0) {
                $this->similarityCacheService->persist($pageId, $rootPageId, $languageUid, $suggestions);
            }
        }

        $this->view->assignMultiple([
            'suggestions' => $suggestions,
            'settings' => $this->settings,
        ]);

        return $this->htmlResponse();
    }

    private function resolveCurrentPageId(): int
    {
        $routing = $this->request->getAttribute('routing');
        if ($routing !== null && method_exists($routing, 'getPageId')) {
            return (int)$routing->getPageId();
        }

        return 0;
    }

    private function resolveLanguageUid(): int
    {
        $siteLanguage = $this->request->getAttribute('language');
        if ($siteLanguage !== null && method_exists($siteLanguage, 'getLanguageId')) {
            return $siteLanguage->getLanguageId();
        }

        return 0;
    }

    private function resolveNewsUid(): int
    {
        $queryParams = $this->request->getQueryParams();

        if (isset($queryParams['tx_news_pi1']['news'])) {
            return (int)$queryParams['tx_news_pi1']['news'];
        }

        return 0;
    }
}
