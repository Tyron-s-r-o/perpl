<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Behavior\BehaviorCodeTest;

use Propel\Generator\Builder\Om\AbstractOMBuilder;
use Propel\Generator\Builder\Om\BuilderType;
use Propel\Generator\Config\QuickGeneratorConfig;
use Propel\Generator\Platform\PlatformInterface;
use Propel\Tests\Attributes\ComparesGeneratedFile;
use Propel\Tests\CompareGeneratedCodeTestCase;
use RuntimeException;

class BehaviorCodeTest extends CompareGeneratedCodeTestCase
{
    /**
     * @return array<array<string>>
     */
    #[ComparesGeneratedFile(textBuilder: 'buildBehaviorCode')]
    public static function BehaviorDataProvider(): array
    {
        $refDir = __DIR__ . '/expected_behavior_code';

        return [ // [schema, array{fileName, builder}]
            [
                [static::ARCHIVABLE_SCHEMA, 'table', BuilderType::ObjectBase,],
                "$refDir/archivable---model_reference.txt",
            ],
            [
                [static::ARCHIVABLE_SCHEMA, 'table', BuilderType::QueryBase,],
                "$refDir/archivable---query_reference.txt",
            ],
            [
                [static::TIMESTAMPABLE_SCHEMA, 'table', BuilderType::ObjectBase,],
                "$refDir/timestampable---object_reference.txt",
            ],
            [
                [static::TIMESTAMPABLE_SCHEMA, 'table', BuilderType::QueryBase,],
                "$refDir/timestampable---query_reference.txt",
            ],
        ];
    }

    /**
     * @param string $schemaXml
     * @param array $config
     * @param \Propel\Generator\Platform\PlatformInterface $platform
     *
     * @return string
     */
    public function buildBehaviorCode(string $schemaXml, string $tableName, BuilderType $builderType, array $hookInput = []): string
    {
        $builder = $this->buildCodeBuilder($schemaXml, $builderType, $tableName);
        $code = '';

        foreach (static::getHooks($builderType) as $hook) {
            $script = '';
            if (array_key_exists($hook, $hookInput)) {
                $hookInput[$hook]($builder, $script);
            }
            $builder->applyBehaviorModifier($hook, $script, '');
            if (!$script) {
                continue;
            }
            $code .= $this->buildCodeFileContent($hook, $script);
        }

        return $code;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('BehaviorDataProvider')]
    public function testEnumeratedColumnQueryCode(array $builderArgs, string $fileName): void
    {
        $code = $this->buildBehaviorCode(...$builderArgs);
        $this->assertStringEqualsFile($fileName, $code, CompareGeneratedCodeTestCase::HOW_TO_UPDATE_MESSAGE);
    }

    /**
     * @param string $schemaXml
     * @param \Propel\Generator\Builder\Om\BuilderType $builderType
     *
     * @return \Propel\Generator\Builder\Om\AbstractOMBuilder
     */
    public function buildCodeBuilder(
        string $schemaXml,
        BuilderType $builderType,
        string $tableName,
        array|null $extraConfig = null,
        PlatformInterface|null $platform = null
    ): AbstractOMBuilder {
        $database = static::buildDatabaseFromSchema($schemaXml, $extraConfig, $platform);
        $config = new QuickGeneratorConfig($extraConfig);
        $table = $database->getTable($tableName);

        return $config->loadConfiguredBuilder($table, $builderType);
    }

    public static function getHooks(BuilderType $builderType): array
    {
        return match ($builderType) {
            BuilderType::ObjectBase => [
                'objectMethods',
                'objectFilter',
                'objectAttributes',
                'postHydrate',
                'preDelete',
                'postDelete',
                'preSave',
                'preInsert',
                'preUpdate',
                'postSave',
                'postUpdate',
                'postInsert',
                'objectClearReferences',
                'objectCall',
            ],
            BuilderType::QueryBase => [
                'queryAttributes',
                'staticConstants',
                'staticAttributes',
                'staticMethods',
                'queryAttributes',
                'queryMethods',
                'queryFilter',
                'preSelectQuery',
                'preDeleteQuery',
                'postDeleteQuery',
                'preUpdateQuery',
                'postUpdateQuery',
            ],
            BuilderType::TableMap => [
                'staticConstants',
                'staticAttributes',
                'staticMethods',
                'tableMapFilter',
            ],
            BuilderType::QueryStub => [
                'extensionQueryFilter'
            ],
            BuilderType::ObjectStub => [
                'extensionObjectFilter'
            ],
            BuilderType::Collection => [
                'addObjectCollectionMethods',

            ],
            default => throw new RuntimeException("Builder type not registered: " . $builderType->name)
        };
    }

    protected const ARCHIVABLE_SCHEMA = <<<XML
    <database>
        <table name="table">
            <column name="id" primaryKey="true" type="INTEGER"/>
            <column name="title" type="VARCHAR" size="100" primaryString="true"/>
            <behavior name="archivable">
                <parameter name="archive_table" value="table_archive"/>
                <parameter name="archive_on_insert" value="true"/>
                <parameter name="archive_on_update" value="true"/>
                <parameter name="archive_on_delete" value="true"/>
            </behavior>
        </table>
    </database>
XML;

    protected const TIMESTAMPABLE_SCHEMA = <<<XML
    <database>
        <table name="table">
            <column name="id" type="INTEGER" primaryKey="true"/>
            <behavior name="timestampable"/>
        </table>
    </database>
XML;

}
