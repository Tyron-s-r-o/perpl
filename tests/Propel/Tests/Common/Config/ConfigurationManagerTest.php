<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Common\Config;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use Propel\Common\Config\ConfigurationManager;
use Propel\Common\Config\Exception\InvalidArgumentException;
use Propel\Tests\TestCase;
use Propel\Generator\Util\VfsTrait;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigurationManagerTest extends TestCase
{
    use VfsTrait;
    use DataProviderTrait;

    /**
     * @param string $filename
     * @param array $conf
     *
     * @return \org\bovigo\vfs\vfsStreamFile
     */
    private function newPhpFile(string $filename, array $conf)
    {
        return $this->newFile($filename, '<?php return ' . var_export($conf, true) . ';');
    }

    /**
     * @return void
     */
    public function testLoadConfigFileInCurrentDirectory()
    {
        $conf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new TestableConfigurationManager($this->getRoot()->url());
        $actual = $manager->getConfig();

        $this->assertEquals('bar', $actual['foo']);
        $this->assertEquals('baz', $actual['bar']);
    }

    /**
     * @return void
     */
    public function testLoadConfigFileInConfigSubdirectory()
    {
        $conf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $this->newPhpFile('config/propel.php', $conf);
        $manager = new TestableConfigurationManager($this->getRoot()->url());
        $actual = $manager->getConfig();

        $this->assertEquals('bar', $actual['foo']);
        $this->assertEquals('baz', $actual['bar']);
    }

    /**
     * @return void
     */
    public function testLoadConfigFileInConfSubdirectory()
    {
        $conf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $this->newPhpFile('conf/propel.php', $conf);
        $manager = new TestableConfigurationManager($this->getRoot()->url());
        $actual = $manager->getConfig();

        $this->assertEquals('bar', $actual['foo']);
        $this->assertEquals('baz', $actual['bar']);
    }

    /**
     * @return void
     */
    public function testNotExistingConfigFileLoadsDefaultSettingsAndDoesNotThrowExceptions()
    {
        $conf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $this->newPhpFile('doctrine.php', $conf);

        $manager = new TestableConfigurationManager($this->getRoot()->url());
        $this->assertNull($manager->getConfigProperty('general.version'));
    }

    /**
     * @return void
     */
    public function testBackupConfigFilesAreIgnored()
    {
        $conf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $this->newPhpFile('propel.php.bak', $conf);
        $this->newPhpFile('propel.php~', $conf);

        $manager = new TestableConfigurationManager($this->getRoot()->url());
        $actual = $manager->getConfig();

        $this->assertArrayNotHasKey('bar', $actual);
        $this->assertArrayNotHasKey('baz', $actual);
    }

    /**
     * @return void
     */
    public function testUnsupportedExtensionsAreIgnored()
    {
        $conf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $this->newPhpFile('propel.log', $conf);

        $manager = new TestableConfigurationManager($this->getRoot()->url());
        $actual = $manager->getConfig();

        $this->assertArrayNotHasKey('bar', $actual);
        $this->assertArrayNotHasKey('baz', $actual);
    }

    /**
     * @return void
     */
    public function testMoreThanOneConfigurationFileInSameDirectoryThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Propel expects only one configuration file');

        $conf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $otherConf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $this->newPhpFile('propel.php', $conf);
        $this->newPhpFile('perpl.php', $otherConf);

        $manager = new TestableConfigurationManager($this->getRoot()->url());
    }

    /**
     * @return void
     */
    public function testMoreThanOneConfigurationFileInDifferentDirectoriesThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Propel expects only one configuration file');

        $conf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $otherConf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $this->newPhpFile('propel.php', $conf);
        $this->newPhpFile('conf/perpl.php', $otherConf);

        $manager = new TestableConfigurationManager($this->getRoot()->url());
    }

    /**
     * @return void
     */
    public function testGetSection()
    {
        $conf = [
            'runtime' => [
                'foo' => 'bar',
                'bar' => 'baz',
            ],
            'buildtime' => [
                'bfoo' => 'bbar',
                'bbar' => 'bbaz',
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new TestableConfigurationManager($this->getRoot()->url());
        $actual = $manager->getSection('buildtime');

        $this->assertEquals('bbar', $actual['bfoo']);
        $this->assertEquals('bbaz', $actual['bbar']);
    }

    /**
     * @return void
     */
    public function testLoadGivenConfigFile()
    {
        $conf = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];
        $file = $this->newPhpFile('myDir/mySubdir/myConfigFile.php', $conf);

        $manager = new TestableConfigurationManager($file->url());
        $actual = $manager->getConfig();

        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $actual);
    }

    /**
     * @return void
     */
    public function testLoadAlsoDistConfigFile()
    {
        $conf = [
            'buildtime' => [
                'bfoo' => 'bbar',
                'bbar' => 'bbaz',
            ],
        ];
        $distConf = [
            'runtime' => [
                'foo' => 'bar',
                'bar' => 'baz',
            ],
        ];

        $this->newPhpFile('propel.php.dist', $distConf);
        $this->newPhpFile('propel.php', $conf);

        $manager = new TestableConfigurationManager($this->getRoot()->url());
        $actual = $manager->getConfig();

        $this->assertEquals(['bfoo' => 'bbar', 'bbar' => 'bbaz'], $actual['buildtime']);
        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $actual['runtime']);
    }

    /**
     * @return void
     */
    public function testLoadOnlyDistFile()
    {
        $distConf = [
            'runtime' => [
                'foo' => 'bar',
                'bar' => 'baz',
            ],
        ];

        $this->newPhpFile('propel.php.dist', $distConf);

        $manager = new TestableConfigurationManager($this->getRoot()->url());
        $actual = $manager->getConfig();

        $this->assertEquals(['runtime' => ['foo' => 'bar', 'bar' => 'baz']], $actual);
    }

    /**
     * @return void
     */
    public function testLoadGivenFileAndDist()
    {
        $conf = [
            'buildtime' => [
                'bfoo' => 'bbar',
                'bbar' => 'bbaz',
            ],
        ];
        $distConf = [
            'runtime' => [
                'foo' => 'bar',
                'bar' => 'baz',
            ],
        ];
        $file = $this->newPhpFile('myDir/mySubdir/myConfigFile.php', $conf);
        $this->newPhpFile('myDir/mySubdir/myConfigFile.php.dist', $distConf);

        $manager = new TestableConfigurationManager($file->url());
        $actual = $manager->getConfig();

        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $actual['runtime']);
        $this->assertEquals(['bfoo' => 'bbar', 'bbar' => 'bbaz'], $actual['buildtime']);
    }

    /**
     * @return void
     */
    public function testLoadDistGivenFileOnly()
    {
        $distConf = [
            'runtime' => [
                'foo' => 'bar',
                'bar' => 'baz',
            ],
        ];
        $file = $this->newPhpFile('myDir/mySubdir/myConfigFile.php.dist', $distConf);

        $manager = new TestableConfigurationManager($file->url());
        $actual = $manager->getConfig();

        $this->assertEquals(['runtime' => ['foo' => 'bar', 'bar' => 'baz']], $actual);
    }

    /**
     * @return void
     */
    public function testLoadInGivenDirectory()
    {
        $conf = [
            'buildtime' => [
                'bfoo' => 'bbar',
                'bbar' => 'bbaz',
            ],
        ];
        $distConf = [
            'runtime' => [
                'foo' => 'bar',
                'bar' => 'baz',
            ],
        ];
        $this->newPhpFile('myDir/mySubdir/propel.php', $conf);
        $this->newPhpFile('myDir/mySubdir/propel.php.dist', $distConf);
        $manager = new TestableConfigurationManager(vfsStream::url('root/myDir/mySubdir/'));
        $actual = $manager->getConfig();

        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $actual['runtime']);
        $this->assertEquals(['bfoo' => 'bbar', 'bbar' => 'bbaz'], $actual['buildtime']);
    }

    /**
     * @return void
     */
    public function testMergeExtraProperties()
    {
        $extraConf = [
            'buildtime' => [
                'bfoo' => 'extrabar',
            ],
            'extralevel' => [
                'extra1' => 'val1',
                'extra2' => 'val2',
            ],
        ];

        $conf = [
            'runtime' => [
                'foo' => 'bar',
                'bar' => 'baz',
            ],
            'buildtime' => [
                'bfoo' => 'bbar',
                'bbar' => 'bbaz',
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new TestableConfigurationManager($this->getRoot()->url(), $extraConf);
        $actual = $manager->getConfig();

        $this->assertEquals($actual['runtime'], ['foo' => 'bar', 'bar' => 'baz']);
        $this->assertEquals($actual['buildtime'], ['bfoo' => 'extrabar', 'bbar' => 'bbaz']);
        $this->assertEquals($actual['extralevel'], ['extra1' => 'val1', 'extra2' => 'val2']);
    }

    /**
     * @return void
     */
    public function testInvalidHierarchyTrowsException()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized options "foo, bar" under "propel"');

        $conf = [
            'runtime' => [
                'foo' => 'bar',
                'bar' => 'baz',
            ],
            'buildtime' => [
                'bfoo' => 'bbar',
                'bbar' => 'bbaz',
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new ConfigurationManager($this->getRoot()->url());
    }

    /**
     * @return void
     */
    public function testNotDefineRuntimeAndGeneratorSectionUsesDefaultConnections()
    {
        $conf = [
            'propel' => [
                'general' => [
                    'project' => 'MyAwesomeProject',
                    'version' => '2.0.0-dev',
                ],
                'database' => [
                    'connections' => [
                        'default' => [
                            'adapter' => 'sqlite',
                            'classname' => 'Propel\\Runtime\\Connection\\ConnectionWrapper',
                            'dsn' => 'sqlite:memory',
                            'user' => null,
                            'password' => null,
                        ],
                    ],
                ],
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new ConfigurationManager($this->getRoot()->url());

        $this->assertArrayHasKey('runtime', $manager->getConfig());
        $this->assertArrayHasKey('generator', $manager->getConfig());

        $this->assertArrayHasKey('connections', $manager->getConfigProperty('runtime'));
        $this->assertArrayHasKey('connections', $manager->getConfigProperty('generator'));

        $this->assertEquals(['default'], $manager->getConfigProperty('runtime.connections'));
        $this->assertEquals(['default'], $manager->getConfigProperty('generator.connections'));
    }

    /**
     * @return void
     */
    public function testNotDefineDatabaseSectionTrowsException()
    {
        $this->expectException(InvalidConfigurationException::class);
        //Starting from Symfony Config 5.2, the following message changed
        //$this->expectExceptionMessage('The child node "database" at path "propel" must be configured');

        $conf = [
            'propel' => [
                'general' => [
                    'project' => 'MyAwesomeProject',
                    'version' => '2.0.0-dev',
                ],
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new ConfigurationManager($this->getRoot()->url());
    }

    /**
     * @return void
     */
    public function testDotInConnectionNamesArentAccepted()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Dots are not allowed in connection names');

        $conf = [
            'propel' => [
                'database' => [
                    'connections' => [
                        'mysource.name' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=mydb',
                            'user' => 'root',
                            'password' => null,
                        ],
                    ],
                ],
                'runtime' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
                'generator' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                    ],
                ],
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new ConfigurationManager($this->getRoot()->url());
    }

    /**
     * @return void
     */
    #[DataProvider('providerForInvalidConnections')]
    public function testRuntimeOrGeneratorConnectionIsNotInConfiguredConnectionsThrowsException($conf, $section)
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->newPhpFile('propel.php', $conf);
        $manager = new ConfigurationManager($this->getRoot()->url());
    }

    /**
     * @return void
     */
    #[DataProvider('providerForInvalidDefaultConnection')]
    public function testRuntimeOrGeneratorDefaultConnectionIsNotInConfiguredConnectionsThrowsException($conf, $section)
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->newPhpFile('propel.php', $conf);
        $manager = new ConfigurationManager($this->getRoot()->url());
    }

    /**
     * @return void
     */
    public function testLoadValidConfigurationFile()
    {
        $conf = [
            'propel' => [
                'database' => [
                    'connections' => [
                        'mysource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=mydb',
                            'user' => 'root',
                            'password' => null,
                            'attributes' => null,
                        ],
                        'yoursource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=yourdb',
                            'user' => 'root',
                            'password' => null,
                            'attributes' => null,
                        ],
                    ],
                ],
                'runtime' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
                'generator' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new ConfigurationManager($this->getRoot()->url());
        $actual = $manager->getConfigProperty('runtime');

        $this->assertEquals($actual['defaultConnection'], 'mysource');
        $this->assertEquals($actual['connections'], ['mysource', 'yoursource']);
    }

    /**
     * @return void
     */
    public function testSomeDeafults()
    {
        $conf = [
            'propel' => [
                'database' => [
                    'connections' => [
                        'mysource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=mydb',
                            'user' => 'root',
                            'password' => null,
                            'attributes' => null,
                        ],
                        'yoursource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=yourdb',
                            'user' => 'root',
                            'password' => null,
                            'attributes' => null,
                        ],
                    ],
                ],
                'runtime' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
                'generator' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new ConfigurationManager($this->getRoot()->url());
        $actual = $manager->getConfig();

        $this->assertTrue($actual['generator']['namespaceAutoPackage']);
        $this->assertEquals($actual['generator']['dateTime']['dateTimeClass'], '\DateTime');
        $this->assertFalse($actual['generator']['schema']['autoPackage']);
        $this->assertEquals($actual['generator']['objectModel']['pluralizerClass'], '\Propel\Common\Pluralizer\StandardEnglishPluralizer');
        $this->assertEquals($actual['generator']['objectModel']['builders']['objectstub'], '\Propel\Generator\Builder\Om\ExtensionObjectBuilder');
    }

    /**
     * @return void
     */
    public function testGetConfigProperty()
    {
        $conf = [
            'propel' => [
                'database' => [
                    'connections' => [
                        'mysource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=mydb',
                            'user' => 'root',
                            'password' => null,
                            'attributes' => null,
                        ],
                        'yoursource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=yourdb',
                            'user' => 'root',
                            'password' => null,
                            'attributes' => null,
                        ],
                    ],
                ],
                'runtime' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
                'generator' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new ConfigurationManager($this->getRoot()->url());
        $this->assertEquals('mysource', $manager->getConfigProperty('runtime.defaultConnection'));
        $this->assertEquals('yoursource', $manager->getConfigProperty('runtime.connections.1'));
        $this->assertEquals('root', $manager->getConfigProperty('database.connections.mysource.user'));
    }

    /**
     * @return void
     */
    public function testGetConfigPropertyBadNameThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid empty configuration property name');

        $conf = [
            'propel' => [
                'database' => [
                    'connections' => [
                        'mysource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=mydb',
                            'user' => 'root',
                            'password' => null,
                            'attributes' => null,
                        ],
                        'yoursource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=yourdb',
                            'user' => 'root',
                            'password' => null,
                            'attributes' => null,
                        ],
                    ],
                ],
                'runtime' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
                'generator' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new ConfigurationManager();
        $manager->getConfigProperty('');
    }

    /**
     * @return void
     */
    public function testGetConfigPropertyBadName()
    {
        $conf = [
            'propel' => [
                'database' => [
                    'connections' => [
                        'mysource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=mydb',
                            'user' => 'root',
                            'password' => null,
                            'attributes' => null,
                        ],
                        'yoursource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=yourdb',
                            'user' => 'root',
                            'password' => null,
                            'attributes' => null,
                        ],
                    ],
                ],
                'runtime' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
                'generator' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $manager = new ConfigurationManager($this->getRoot()->url());
        $value = $manager->getConfigProperty('database.connections.adapter');

        $this->assertNull($value);
    }

    /**
     * @return void
     */
    public function testProcessWithParam()
    {
        $configs = [
            'propel' => [
                'database' => [
                    'connections' => [
                        'default' => [
                            'adapter' => 'sqlite',
                            'classname' => 'Propel\Runtime\Connection\DebugPDO',
                            'dsn' => 'sqlite::memory:',
                            'user' => '',
                            'password' => '',
                            'model_paths' => [
                                'src',
                                'vendor',
                            ],
                        ],
                    ],
                ],
                'runtime' => [
                    'defaultConnection' => 'default',
                    'connections' => ['default'],
                ],
                'generator' => [
                    'defaultConnection' => 'default',
                    'connections' => ['default'],
                ],
            ],
        ];

        $manager = new NotLoadingConfigurationManager(null, $configs);
        $actual = $manager->getConfigProperty('database.connections');

        $this->assertEquals($configs['propel']['database']['connections'], $actual);
    }

    /**
     * @return void
     */
    public function testProcessWrongParameter()
    {
        $manager = new NotLoadingConfigurationManager(null, null);

        $this->assertEmpty($manager->getConfig());
    }

    /**
     * @return void
     */
    public function testGetConfigurationParametersArrayTest()
    {
        $conf = [
            'propel' => [
                'database' => [
                    'connections' => [
                        'mysource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=mydb',
                            'user' => 'root',
                            'password' => null,
                            'model_paths' => [
                                'src',
                            ],
                        ],
                        'yoursource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=yourdb',
                            'user' => 'root',
                            'password' => null,
                        ],
                    ],
                ],
                'runtime' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                        'yoursource',
                    ],
                ],
                'generator' => [
                    'defaultConnection' => 'mysource',
                    'connections' => [
                        'mysource',
                    ],
                ],
            ],
        ];
        $this->newPhpFile('propel.php', $conf);

        $expectedRuntime = [
            'mysource' => [
                'adapter' => 'mysql',
                'classname' => 'Propel\Runtime\Connection\DebugPDO',
                'dsn' => 'mysql:host=localhost;dbname=mydb',
                'user' => 'root',
                'password' => '',
                'model_paths' => [
                    'src',
                ],
            ],
            'yoursource' => [
                'adapter' => 'mysql',
                'classname' => 'Propel\Runtime\Connection\DebugPDO',
                'dsn' => 'mysql:host=localhost;dbname=yourdb',
                'user' => 'root',
                'password' => '',
                'model_paths' => [
                    'src',
                    'vendor',
                ],
            ],
        ];

        $expectedGenerator = [
            'mysource' => [
                'adapter' => 'mysql',
                'classname' => 'Propel\Runtime\Connection\DebugPDO',
                'dsn' => 'mysql:host=localhost;dbname=mydb',
                'user' => 'root',
                'password' => '',
                'model_paths' => [
                    'src',
                ],
            ],
        ];

        $manager = new ConfigurationManager($this->getRoot()->url());
        $this->assertEquals($expectedRuntime, $manager->getConnectionParametersArray('runtime'));
        $this->assertEquals($expectedRuntime, $manager->getConnectionParametersArray()); //default `runtime`
        $this->assertEquals($expectedGenerator, $manager->getConnectionParametersArray('generator'));
        $this->assertNull($manager->getConnectionParametersArray('bad_section'));
    }

    /**
     * @return void
     */
    public function testSetConnectionsIfNotDefined()
    {
        $conf = [
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
                        'yoursource' => [
                            'adapter' => 'mysql',
                            'classname' => 'Propel\\Runtime\\Connection\\DebugPDO',
                            'dsn' => 'mysql:host=localhost;dbname=yourdb',
                            'user' => 'root',
                            'password' => null,
                        ],
                    ],
                ],
            ],
        ];
        $this->newPhpFile('propel.php', $conf);
        $manager = new ConfigurationManager($this->getRoot()->url());

        $this->assertEquals('mysource', $manager->getConfigProperty('generator.defaultConnection'));
        $this->assertEquals('mysource', $manager->getConfigProperty('runtime.defaultConnection'));
        $this->assertEquals(['mysource', 'yoursource'], $manager->getConfigProperty('generator.connections'));
        $this->assertEquals(['mysource', 'yoursource'], $manager->getConfigProperty('runtime.connections'));
    }

    /**
     * @return array<array>
     */
    public static function deflateConfigurationDataProvider(): array
    {
        return [
        [['foo' => 42], ['foo' => 42]],
        [['foo.bar.baz' => 42], ['foo' => ['bar' => ['baz' => 42]]]],
        [['foo.bar.baz' => 42, 'foo.fizz' => 43], ['foo' => ['bar' => ['baz' => 42], 'fizz' => 43]]]
        ];
    }

    /**
     * @param array $flattenedConfig
     * @param array $expectedArray
     *
     * @return void
     */
    #[DataProvider('deflateConfigurationDataProvider')]
    public function testDeflateConfiguration(array $flattenedConfig, array $expectedArray)
    {
        $actualArray = ConfigurationManager::deflateConfigurationArray($flattenedConfig);
        $this->assertEquals($expectedArray, $actualArray);
    }

    /**
     * @return array<array>
     */
    public static function ExpectedFilenamsDataProvider(): array
    {
        return [
            ['foo.ext', [0 => 'foo.ext.dist', 1 => 'foo.dist.ext', 2 => 'foo.ext']],  // Note: keys are precedence values, not indexes
            ['foo', [0 => 'foo.dist', 2 => 'foo']],
            ['foo.bar.ext', [0 => 'foo.bar.ext.dist', 1 => 'foo.bar.dist.ext', 2 => 'foo.bar.ext']],
        ];
    }

    #[DataProvider('ExpectedFilenamsDataProvider')]
    public function testFilenameBuilder(string $fileName, array $expectedArray)
    {
        $actualArray = $this->callMethod(new ConfigurationManager(), 'buildExpectedConfigFileNamesForMainConfig', [$fileName]);
        $this->assertEquals($expectedArray, $actualArray);
    }


}

class TestableConfigurationManager extends ConfigurationManager
{
    protected function process(array $extraConf = []): void
    {
    }
}

class NotLoadingConfigurationManager extends ConfigurationManager
{
    protected function loadConfig(string $path, array $extraConf = []): array
    {
        return $extraConf;
    }
}
