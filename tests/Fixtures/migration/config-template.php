<?php

/** Configuration file for `migration` fixtures */

return [
    'propel' => [
        'general' => [
            'project' => 'migration',
        ],
        'database' => [
            'connections' => [
                'migration' => [
                    'adapter' => '##DATABASE_VENDOR##',
                    'classname' => '\\Propel\\Runtime\\Connection\\DebugPDO',
                    'dsn' => '##DATABASE_URL##',
                    'user' => '##DATABASE_USER##',
                    'password' => '##DATABASE_PASSWORD##',
                    'options' => [
                        'ATTR_PERSISTENT' => false,
                    ],
                    'attributes' => [
                        'ATTR_EMULATE_PREPARES' => true,
                    ],
                    'settings' => [
                        'charset' => 'utf8',
                    ],
                ],
            ],
        ],
        'generator' => [
            'defaultConnection' => 'migration',
            'connections' => [
                'migration',
            ],
            'targetPackage' => 'migration',
            'declareStrictTypesInBuilders' => true,
        ],
        'runtime' => [
            'defaultConnection' => 'migration',
            'connections' => [
                'migration',
            ],
        ],
    ],
];
