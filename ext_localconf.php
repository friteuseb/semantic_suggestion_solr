<?php

defined('TYPO3') or die();

use CyrilMarchand\SemanticSuggestionSolr\Controller\SuggestionsController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

(static function () {
    ExtensionUtility::configurePlugin(
        'SemanticSuggestionSolr',
        'Suggestions',
        [SuggestionsController::class => 'list'],
        []
    );

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
        '@import "EXT:semantic_suggestion_solr/Configuration/TypoScript/setup.typoscript"'
    );
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptConstants(
        '@import "EXT:semantic_suggestion_solr/Configuration/TypoScript/constants.typoscript"'
    );
})();
