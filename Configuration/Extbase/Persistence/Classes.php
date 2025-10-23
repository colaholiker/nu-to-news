<?php

declare(strict_types=1);

/**
 * Replace config.persistence.classes typoscript configuration
 *
 * https://docs.typo3.org/c/typo3/cms-core/master/en-us/Changelog/10.0/Breaking-87623-ReplaceConfigpersistenceclassesTyposcriptConfiguration.html
 */
return [
    SchachvereinBalingenEv\NuToNews\Domain\Category::class => [
        'tableName' => 'sys_category',
    ],
];