<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'nu_to_news',
    'description' => 'NuLiga Parser to tx_news',
    'constraints' => [
        'depends' => [
            'typo3' => '13.0.0-13.4.99',
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'SchachvereinBalingenEv\\NuToNews\\' => 'Classes/',
        ],
    ],
];
