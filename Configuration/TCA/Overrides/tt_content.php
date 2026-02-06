<?php

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

ExtensionUtility::registerPlugin(
    'SemanticSuggestionSolr',
    'Suggestions',
    'LLL:EXT:semantic_suggestion_solr/Resources/Private/Language/locallang.xlf:plugin.title',
    'content-idea'
);

$pluginSignature = 'semanticsuggestionsolr_suggestions';

$GLOBALS['TCA']['tt_content']['types']['list']['subtypes_addlist'][$pluginSignature] = 'pi_flexform';
ExtensionManagementUtility::addPiFlexFormValue(
    $pluginSignature,
    'FILE:EXT:semantic_suggestion_solr/Configuration/FlexForms/Suggestions.xml'
);
