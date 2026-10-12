<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContractHooks\HookedThing;
use ConsumerContractHooks\HookedThingQuery;
use Propel\Generator\Builder\Om\BuilderType;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\Propel;
use Propel\Tests\TestCase;

/**
 * Pins the order of the object hooks and the abort-by-false behaviour.
 *
 * QuickBuilder cannot generate custom stub classes, so this test builds its own small schema without
 * the object stub and loads a hand-written stub (HookedThing.php) that records hook calls.
 */
class HookTest extends TestCase
{
    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (class_exists('\ConsumerContractHooks\HookedThing', false)) {
            return;
        }
        $schema = <<<'END'
<database name="contract_hooks" namespace="ConsumerContractHooks" defaultIdMethod="native">
    <table name="cc_hooked_thing" phpName="HookedThing">
        <column name="id" phpName="Id" type="INTEGER" primaryKey="true" autoIncrement="true" required="true"/>
        <column name="name" phpName="Name" type="VARCHAR" size="50"/>
    </table>
</database>
END;
        $targets = [BuilderType::TableMap, BuilderType::ObjectBase, BuilderType::Collection, BuilderType::QueryBase, BuilderType::QueryStub];
        $builder = new QuickBuilder();
        $builder->setSchema($schema);
        $builder->build(null, null, null, null, $targets);
        require_once __DIR__ . '/HookedThing.php';
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        HookedThing::$log = [];
        HookedThing::$abortAt = null;
        Propel::getConnection('contract_hooks')->exec('DELETE FROM cc_hooked_thing');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        HookedThing::$log = [];
        HookedThing::$abortAt = null;
        \ConsumerContractHooks\Map\HookedThingTableMap::clearInstancePool();
        parent::tearDown();
    }

    /**
     * @return \ConsumerContractHooks\HookedThing
     */
    protected function savedThing(): HookedThing
    {
        $thing = new HookedThing();
        $thing->setName('a');
        $thing->save();
        HookedThing::$log = [];

        return $thing;
    }

    /**
     * @return void
     */
    public function testInsertHookOrder()
    {
        $thing = new HookedThing();
        $thing->setName('a');
        $affected = $thing->save();

        $this->assertSame(1, $affected);
        $this->assertSame(['preSave', 'preInsert', 'postInsert', 'postSave'], HookedThing::$log);
    }

    /**
     * @return void
     */
    public function testUpdateHookOrder()
    {
        $thing = $this->savedThing();
        $thing->setName('b');
        $affected = $thing->save();

        $this->assertSame(1, $affected);
        $this->assertSame(['preSave', 'preUpdate', 'postUpdate', 'postSave'], HookedThing::$log);
    }

    /**
     * @return void
     */
    public function testUnmodifiedSaveStillCallsUpdateHooks()
    {
        $thing = $this->savedThing();
        $affected = $thing->save();

        $this->assertSame(0, $affected);
        $this->assertSame(['preSave', 'preUpdate', 'postUpdate', 'postSave'], HookedThing::$log);
    }

    /**
     * @return void
     */
    public function testDeleteHookOrder()
    {
        $thing = $this->savedThing();
        $thing->delete();

        $this->assertSame(['preDelete', 'postDelete'], HookedThing::$log);
        $this->assertTrue($thing->isDeleted());
        $this->assertSame(0, HookedThingQuery::create()->count());
    }

    /**
     * @return array<string, array<string>>
     */
    public static function insertAbortProvider(): array
    {
        return [
            'preSave' => ['preSave', ['preSave']],
            'preInsert' => ['preInsert', ['preSave', 'preInsert']],
        ];
    }

    /**
     *
     * @param string $abortAt
     * @param array<string> $expectedLog
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('insertAbortProvider')]
    public function testInsertIsAbortedByFalseFromPreHook(string $abortAt, array $expectedLog)
    {
        HookedThing::$abortAt = $abortAt;
        $thing = new HookedThing();
        $thing->setName('a');
        $affected = $thing->save();

        $this->assertSame(0, $affected);
        $this->assertSame($expectedLog, HookedThing::$log);
        $this->assertTrue($thing->isNew());
        $this->assertSame(0, HookedThingQuery::create()->count());
    }

    /**
     * @return array<string, array<string>>
     */
    public static function updateAbortProvider(): array
    {
        return [
            'preSave' => ['preSave', ['preSave']],
            'preUpdate' => ['preUpdate', ['preSave', 'preUpdate']],
        ];
    }

    /**
     *
     * @param string $abortAt
     * @param array<string> $expectedLog
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('updateAbortProvider')]
    public function testUpdateIsAbortedByFalseFromPreHook(string $abortAt, array $expectedLog)
    {
        $thing = $this->savedThing();
        HookedThing::$abortAt = $abortAt;
        $thing->setName('changed');
        $affected = $thing->save();

        $this->assertSame(0, $affected);
        $this->assertSame($expectedLog, HookedThing::$log);
        \ConsumerContractHooks\Map\HookedThingTableMap::clearInstancePool();
        $this->assertSame('a', HookedThingQuery::create()->findOneById($thing->getId())->getName());
    }

    /**
     * @return void
     */
    public function testDeleteIsAbortedByFalseFromPreDelete()
    {
        $thing = $this->savedThing();
        HookedThing::$abortAt = 'preDelete';
        $thing->delete();

        $this->assertSame(['preDelete'], HookedThing::$log);
        $this->assertFalse($thing->isDeleted());
        $this->assertSame(1, HookedThingQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testQueryDeleteDoesNotCallObjectHooks()
    {
        $this->savedThing();
        HookedThingQuery::create()->deleteAll();

        $this->assertSame([], HookedThing::$log);
    }
}
