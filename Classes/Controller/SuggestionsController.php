<?php

declare(strict_types=1);

namespace Cywolf\SemanticSuggestionSolr\Controller;

use Cywolf\SemanticSuggestionSolr\Service\SimilarityCacheService;
use Cywolf\SemanticSuggestionSolr\Service\SolrMltService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
        if (!$this->isCurrentPageAllowed()) {
            return $this->htmlResponse('');
        }

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

    /**
     * Check if the current page is allowed to show suggestions based on
     * includePageTrees / excludePageTrees settings.
     *
     * Both settings accept comma-separated parent page UIDs. A page "matches"
     * a tree if the parent UID appears anywhere in the page's rootline
     * (i.e. the page itself OR any ancestor).
     *
     * Logic:
     *   - excludePageTrees always wins (checked first)
     *   - includePageTrees empty = show everywhere (minus excludes)
     *   - includePageTrees set  = show only in those trees
     */
    private function isCurrentPageAllowed(): bool
    {
        $pageInformation = $this->request->getAttribute('frontend.page.information');
        if ($pageInformation === null) {
            return true;
        }

        $rootLine = $pageInformation->getRootLine();
        $rootLineUids = array_map('intval', array_column($rootLine, 'uid'));

        // Exclude takes priority
        $excludePids = GeneralUtility::intExplode(',', (string)($this->settings['excludePageTrees'] ?? ''), true);
        if (!empty($excludePids) && array_intersect($excludePids, $rootLineUids) !== []) {
            return false;
        }

        // Include: empty = everywhere, set = whitelist
        $includePids = GeneralUtility::intExplode(',', (string)($this->settings['includePageTrees'] ?? ''), true);
        if (!empty($includePids) && array_intersect($includePids, $rootLineUids) === []) {
            return false;
        }

        return true;
    }
}
