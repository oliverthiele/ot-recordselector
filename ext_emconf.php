<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Record Selector',
    'description' => 'Custom backend form element for selecting TYPO3 records with translated titles, AJAX autocomplete, permission checks, and hidden-record indicators',
    'category' => 'be',
    'author' => 'Oliver Thiele',
    'author_email' => 'mail@oliver-thiele.de',
    'state' => 'stable',
    'version' => '1.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
