<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractProduct;
use ConsumerContract\ContractProductQuery;
use ConsumerContract\Map\ContractProductTableMap;
use Propel\Runtime\Perpl;
use Propel\Runtime\Propel;

/**
 * Pins instance pooling.
 */
class InstancePoolTest extends ConsumerContractTestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->data = $this->createCatalog();
        $this->clearPools();
    }

    /**
     * @return void
     */
    public function testPoolingIsEnabledByDefault()
    {
        $this->assertTrue(Perpl::isInstancePoolingEnabled());
        $this->assertTrue(Propel::isInstancePoolingEnabled());
    }

    /**
     * @return void
     */
    public function testDisableAndEnableReturnWhetherTheStateChanged()
    {
        $this->assertTrue(Perpl::disableInstancePooling());
        $this->assertFalse(Perpl::isInstancePoolingEnabled());
        $this->assertFalse(Perpl::disableInstancePooling(), 'already disabled');
        $this->assertTrue(Perpl::enableInstancePooling());
        $this->assertTrue(Perpl::isInstancePoolingEnabled());
        $this->assertFalse(Perpl::enableInstancePooling(), 'already enabled');
    }

    /**
     * @return void
     */
    public function testFindPkTwiceWithPoolingReturnsSameInstanceAndRunsOneQuery()
    {
        $id = $this->data['p1']->getId();
        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();

        $first = ContractProductQuery::create()->findPk($id, $con);
        $second = ContractProductQuery::create()->findPk($id, $con);

        $this->assertSame($first, $second);
        $this->assertSame($before + 1, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testFindPkTwiceWithoutPoolingReturnsDifferentInstancesAndRunsTwoQueries()
    {
        Perpl::disableInstancePooling();
        $id = $this->data['p1']->getId();
        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();

        $first = ContractProductQuery::create()->findPk($id, $con);
        $second = ContractProductQuery::create()->findPk($id, $con);

        $this->assertNotSame($first, $second);
        $this->assertEquals($first->toArray(), $second->toArray());
        $this->assertSame($before + 2, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testFindPksUsesPoolOnlyForFindPk()
    {
        $id = $this->data['p1']->getId();
        $pooled = ContractProductQuery::create()->findPk($id);
        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();

        $found = ContractProductQuery::create()->findPks([$id], $con);

        $this->assertSame($before + 1, $con->getQueryCount(), 'findPks always queries');
        $this->assertSame($pooled, $found->getFirst(), 'but hydration hands out the pooled instance');
    }

    /**
     * @return void
     */
    public function testFindReturnsPooledInstanceAndDoesNotRefreshItWithChangedRowData()
    {
        $id = $this->data['p1']->getId();
        $pooled = ContractProductQuery::create()->findPk($id);
        $this->getDebugConnection()->exec("UPDATE cc_product SET name = 'changed behind the back' WHERE id = $id");

        $found = ContractProductQuery::create()->filterById($id)->findOne();

        $this->assertSame($pooled, $found);
        $this->assertSame('P1', $found->getName(), 'the stale pooled instance is returned unchanged');
        $this->clearPools();
        $this->assertSame('changed behind the back', ContractProductQuery::create()->findPk($id)->getName());
    }

    /**
     * @return void
     */
    public function testFindWithoutPoolingSeesChangedRowData()
    {
        Perpl::disableInstancePooling();
        $id = $this->data['p1']->getId();
        $this->getDebugConnection()->exec("UPDATE cc_product SET name = 'changed behind the back' WHERE id = $id");

        $this->assertSame('changed behind the back', ContractProductQuery::create()->filterById($id)->findOne()->getName());
    }

    /**
     * @return void
     */
    public function testClearInstancePoolForcesNewInstanceAndQuery()
    {
        $id = $this->data['p1']->getId();
        $first = ContractProductQuery::create()->findPk($id);

        ContractProductTableMap::clearInstancePool();

        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();
        $second = ContractProductQuery::create()->findPk($id, $con);
        $this->assertNotSame($first, $second);
        $this->assertSame($before + 1, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testRemoveInstanceRemovesSingleObjectFromPool()
    {
        $one = ContractProductQuery::create()->findPk($this->data['p1']->getId());
        $two = ContractProductQuery::create()->findPk($this->data['p2']->getId());

        ContractProductTableMap::removeInstanceFromPool($one);

        $this->assertNotSame($one, ContractProductQuery::create()->findPk($this->data['p1']->getId()));
        $this->assertSame($two, ContractProductQuery::create()->findPk($this->data['p2']->getId()));
    }

    /**
     * @return void
     */
    public function testInsertedObjectIsAddedToPool()
    {
        $product = $this->createProduct('pooled on insert');
        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();

        $found = ContractProductQuery::create()->findPk($product->getId(), $con);

        $this->assertSame($product, $found);
        $this->assertSame($before, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testLoadedObjectIsAddedToPoolByFind()
    {
        $all = ContractProductQuery::create()->orderById()->find();
        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();

        $found = ContractProductQuery::create()->findPk($all->getFirst()->getId(), $con);

        $this->assertSame($all->getFirst(), $found);
        $this->assertSame($before, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testDeletedObjectIsRemovedFromPool()
    {
        $product = ContractProductQuery::create()->findPk($this->data['p4']->getId());

        $product->delete();

        $this->assertNull(ContractProductQuery::create()->findPk($this->data['p4']->getId()));
    }

    /**
     * @return void
     */
    public function testQueryDeleteClearsPool()
    {
        $id = $this->data['p4']->getId();
        $this->assertNotNull(ContractProductQuery::create()->findPk($id));

        ContractProductQuery::create()->filterById($id)->delete();

        $this->assertNull(ContractProductQuery::create()->findPk($id));
    }

    /**
     * @return void
     */
    public function testEnableAfterDisableDoesNotKeepInstancesAddedWhileDisabled()
    {
        Perpl::disableInstancePooling();
        $id = $this->data['p1']->getId();
        $first = ContractProductQuery::create()->findPk($id);
        Perpl::enableInstancePooling();

        $second = ContractProductQuery::create()->findPk($id);

        $this->assertNotSame($first, $second);
    }

    /**
     * @return void
     */
    public function testPoolIsPerTableMap()
    {
        $product = ContractProductQuery::create()->findPk($this->data['p1']->getId());

        \ConsumerContract\Map\ContractCategoryTableMap::clearInstancePool();

        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();
        $this->assertSame($product, ContractProductQuery::create()->findPk($product->getId(), $con));
        $this->assertSame($before, $con->getQueryCount());
        $this->assertInstanceOf(ContractProduct::class, $product);
    }
}
