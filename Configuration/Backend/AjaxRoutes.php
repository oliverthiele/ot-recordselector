<?php

declare(strict_types=1);

use OliverThiele\OtRecordselector\Controller\RecordSelectorController;

return [
    'ot_recordselector_search' => [
        'path' => '/ot-recordselector/search',
        'methods' => ['GET'],
        'target' => RecordSelectorController::class . '::searchAction',
    ],
];
