<?php

$EM_CONF['ot_recordselector'] = [
    'title' => 'Record Selector',
    'description' => 'Custom backend form element for selecting TYPO3 records with translated titles, AJAX autocomplete, permission checks, and hidden-record indicators',
    'category' => 'be',
    'author' => 'Oliver Thiele',
    'author_email' => 'mail@oliver-thiele.de',
    'state' => 'stable',
    'version' => '2.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'php' => '8.4.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
