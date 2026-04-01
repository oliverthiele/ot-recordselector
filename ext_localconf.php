<?php

declare(strict_types=1);

use OliverThiele\OtRecordselector\Form\Element\RecordSelectorElement;

defined('TYPO3') or die();

// Register the custom form element renderType "otRecordSelector"
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1743381000] = [
    'nodeName' => 'otRecordSelector',
    'priority' => 40,
    'class' => RecordSelectorElement::class,
];
