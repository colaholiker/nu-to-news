<?php

declare(strict_types=1);

use SchachvereinBalingenEv\NuToNews\Task\NuToNews;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

if (isset($GLOBALS['TCA']['tx_scheduler_task'])) {
    ExtensionManagementUtility::addRecordType(
        [
            'label' => 'Paarungen von Nu to News',
            'description' => '',
            'value' => NuToNews::class,
            'icon' => '',
            'iconOverlay' => '',
            'group' => 'blub',
        ],
        $GLOBALS['TCA']['tx_scheduler_task']['0']['showitem'],
        [],
        '',
        'tx_scheduler_task'
    );
}
