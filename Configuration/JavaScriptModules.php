<?php

declare(strict_types=1);

return [
    'dependencies' => ['backend', 'core'],
    'tags' => [
        'backend.form',
    ],
    'imports' => [
        '@oliverthiele/ot-recordselector/' => 'EXT:ot_recordselector/Resources/Public/JavaScript/',
    ],
];
