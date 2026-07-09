<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'nu_to_news',
    'description' => 'NuLiga Parser to tx_news',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.4.99',
            'scheduler' => '13.4.0-14.4.99',
            'news' => '12.2.0-14.99.99',
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'SchachvereinBalingenEv\\NuToNews\\' => 'Classes/',
        ],
    ],
];
