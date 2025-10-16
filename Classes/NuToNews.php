<?php

declare(strict_types=1);

namespace SchachvereinBalingenEv\NuToNews\Task;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

final class NuToNews extends AbstractTask
{
    /**
     * MUST be implemented by all tasks
     */
    public function execute(): bool
    {
        # Dependency injection cannot be used in scheduler tasks

	die('test')
    }

    public function getAdditionalInformation()
    {
        $this->getLanguageService()->sL('LLL:EXT:my_extension/Resources/Private/Language/locallang.xlf:myTaskInformation');
    }
}
