<?php

declare(strict_types=1);

use SchachvereinBalingenEv\NuToNews\Task\NuToNews;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][NuToNews::class] = [
    'extension' => 'scheduler',
    'title' => 'Paarungen von Nu to News',
    'description' => 'Paarungen von Nu to News'
];
