<?php

defined('TYPO3') or die();

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
    'SemanticSuggestionSolr',
    'Suggestions',
    'Suggestions similaires (Solr)',
    'content-idea'
);
