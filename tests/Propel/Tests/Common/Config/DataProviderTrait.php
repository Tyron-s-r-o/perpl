<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Common\Config;

/**
 * This trait contains the data providers for ConfigurationManagerTest class.
 */
trait DataProviderTrait
{
    /**
     * @return array<int, array{0: array, 1: string}>
     */
    public static function providerForInvalidConnections()
    {
        return [
            [[
                'propel' => [
                    'database' => [
                        'connections' => [
                            'mysource' => [
                                'adapter' => 'mysql',
                                'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                                'dsn' => 'mysql:host=localhost;dbname=mydb',
                                'user' => 'root',
                                'password' => null,
                            ],
                        ],
                    ],
                    'runtime' => [
                        'defaultConnection' => 'wrongsource',
                        'connections' => [
                            'wrongsource',
                        ],
                    ],
                ],
            ], 'runtime'],
            [[
                'propel' => [
                    'database' => [
                        'connections' => [
                            'mysource' => [
                                'adapter' => 'mysql',
                                'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                                'dsn' => 'mysql:host=localhost;dbname=mydb',
                                'user' => 'root',
                                'password' => null,
                            ],
                        ],
                    ],
                    'runtime' => [
                        'defaultConnection' => 'wrongsource',
                        'connections' => [
                            'mysource',
                            'wrongsource',
                        ],
                    ],
                ],
            ], 'runtime'],
            [[
                'propel' => [
                    'database' => [
                        'connections' => [
                            'mysource' => [
                                'adapter' => 'mysql',
                                'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                                'dsn' => 'mysql:host=localhost;dbname=mydb',
                                'user' => 'root',
                                'password' => null,
                            ],
                        ],
                    ],
                    'generator' => [
                        'defaultConnection' => 'wrongsource',
                        'connections' => [
                            'wrongsource',
                        ],
                    ],
                ],
            ], 'generator'],
            [[
                'propel' => [
                    'database' => [
                        'connections' => [
                            'mysource' => [
                                'adapter' => 'mysql',
                                'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                                'dsn' => 'mysql:host=localhost;dbname=mydb',
                                'user' => 'root',
                                'password' => null,
                            ],
                        ],
                    ],
                    'generator' => [
                        'defaultConnection' => 'wrongsource',
                        'connections' => [
                            'wrongsource',
                            'mysource',
                        ],
                    ],
                ],
            ], 'generator'],
            [[
                'propel' => [
                    'database' => [
                        'connections' => [
                            'mysource' => [
                                'adapter' => 'mysql',
                                'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                                'dsn' => 'mysql:host=localhost;dbname=mydb',
                                'user' => 'root',
                                'password' => null,
                            ],
                        ],
                    ],
                    'generator' => [
                        'defaultConnection' => 'wrongsource',
                        'connections' => [
                            'wrongsource',
                        ],
                    ],
                    'runtime' => [
                        'defaultConnection' => 'wrongsource',
                        'connections' => [
                            'wrongsource',
                        ],
                    ],
                ],
            ], 'runtime'],
        ];
    }

    /**
     * @return array<int, array{0: array, 1: string}>
     */
    public static function providerForInvalidDefaultConnection()
    {
        return [
            [[
                'propel' => [
                    'database' => [
                        'connections' => [
                            'mysource' => [
                                'adapter' => 'mysql',
                                'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                                'dsn' => 'mysql:host=localhost;dbname=mydb',
                                'user' => 'root',
                                'password' => null,
                            ],
                        ],
                    ],
                    'runtime' => [
                        'defaultConnection' => 'wrongsource',
                        'connections' => [
                            'mysource',
                        ],
                    ],
                ],
            ], 'runtime'],
            [[
                'propel' => [
                    'database' => [
                        'connections' => [
                            'mysource' => [
                                'adapter' => 'mysql',
                                'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                                'dsn' => 'mysql:host=localhost;dbname=mydb',
                                'user' => 'root',
                                'password' => null,
                            ],
                        ],
                    ],
                    'generator' => [
                        'defaultConnection' => 'wrongsource',
                        'connections' => [
                            'mysource',
                        ],
                    ],
                ],
            ], 'generator'],
            [[
                'propel' => [
                    'database' => [
                        'connections' => [
                            'mysource' => [
                                'adapter' => 'mysql',
                                'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                                'dsn' => 'mysql:host=localhost;dbname=mydb',
                                'user' => 'root',
                                'password' => null,
                            ],
                        ],
                    ],
                    'generator' => [
                        'defaultConnection' => 'wrongsource',
                        'connections' => [
                            'mysource',
                        ],
                    ],
                    'runtime' => [
                        'defaultConnection' => 'wrongsource',
                        'connections' => [
                            'mysource',
                        ],
                    ],
                ],
            ], 'runtime'],
        ];
    }
}
