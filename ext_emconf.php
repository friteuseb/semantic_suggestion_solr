<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Semantic Suggestion Solr',
    'description' => 'Suggests semantically related content (pages, news, etc.) using Solr More Like This (MLT) with TF-IDF term vectors',
    'category' => 'plugin',
    'author' => 'Cyril Marchand',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'state' => 'beta',
    'clearCacheOnLoad' => 0,
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.99.99',
            'solr' => '13.0.0-13.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
