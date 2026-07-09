<?php

declare(strict_types=1);

use SchachvereinBalingenEv\NuToNews\Task\NuToNews;
use TYPO3\CMS\Core\Information\Typo3Version;

defined('TYPO3') or die();

// TYPO3 v13: Scheduler-Task wird klassisch über SC_OPTIONS registriert.
// In v14 übernimmt die TCA-Registrierung in
// Configuration/TCA/Overrides/tx_scheduler_nu_to_news.php.
// TODO: entfernen, sobald der v13-Support wegfällt.
if ((new Typo3Version())->getMajorVersion() < 14) {
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][NuToNews::class] = [
        'extension' => 'SchachvereinBalingenEv',
        'title' => 'Paarungen von Nu to News',
        'description' => 'Paarungen von Nu to News',
    ];
}
