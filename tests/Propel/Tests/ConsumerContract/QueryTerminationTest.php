<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractProduct;
use ConsumerContract\ContractProductQuery;
use Propel\Runtime\Collection\ObjectCollection;

/**
 * Pins the ways a query is terminated.
 */
class QueryTerminationTest extends ConsumerContractTestCase
{
    /**
     * @return void
     */
    public function testFindReturnsObjectCollectionOfModels()
    {
        $this->createCatalog();
        $result = ContractProductQuery::create()->orderById()->find();

        $this->assertInstanceOf(ObjectCollection::class, $result);
        $this->assertInstanceOf(\ConsumerContract\Base\Collection\ContractProductCollection::class, $result);
        $this->assertCount(4, $result);
        $this->assertContainsOnlyInstancesOf(ContractProduct::class, $result->getData());
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $result->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testFindOnEmptyTableReturnsEmptyCollection()
    {
        $result = ContractProductQuery::create()->find();

        $this->assertInstanceOf(ObjectCollection::class, $result);
        $this->assertCount(0, $result);
        $this->assertTrue($result->isEmpty());
    }

    /**
     * @return void
     */
    public function testFindOneReturnsFirstModelOrNull()
    {
        $this->createCatalog();
        $con = $this->getDebugConnection();

        $found = ContractProductQuery::create()->orderByName(\Propel\Runtime\ActiveQuery\Criteria::DESC)->findOne($con);
        $this->assertInstanceOf(ContractProduct::class, $found);
        $this->assertSame('P4', $found->getName());
        $this->assertStringContainsString('LIMIT 1', $con->getLastExecutedQuery());

        $this->assertNull(ContractProductQuery::create()->filterByName('nope')->findOne());
    }

    /**
     * @return void
     */
    public function testFindPkReturnsModelOrNull()
    {
        $data = $this->createCatalog();

        $found = ContractProductQuery::create()->findPk($data['p2']->getId());
        $this->assertInstanceOf(ContractProduct::class, $found);
        $this->assertSame('P2', $found->getName());
        $this->assertNull(ContractProductQuery::create()->findPk(9999));
    }

    /**
     * @return void
     */
    public function testFindPkWithNullReturnsNull()
    {
        $this->createCatalog();

        $this->assertNull(ContractProductQuery::create()->findPk(null));
    }

    /**
     * @return void
     */
    public function testFindPkSqlSelectsByPrimaryKey()
    {
        $data = $this->createCatalog();
        $this->clearPools();
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->findPk($data['p1']->getId(), $con);

        $this->assertStringEndsWith('FROM cc_product WHERE id = ' . $data['p1']->getId(), $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFindPksReturnsCollectionOfExistingRows()
    {
        $data = $this->createCatalog();

        $result = ContractProductQuery::create()->findPks([$data['p1']->getId(), $data['p3']->getId(), 9999]);

        $this->assertInstanceOf(ObjectCollection::class, $result);
        $this->assertEqualsCanonicalizing(['P1', 'P3'], $result->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testFindPksSqlUsesIn()
    {
        $data = $this->createCatalog();
        $this->clearPools();
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->findPks([$data['p1']->getId(), $data['p2']->getId()], $con);

        $this->assertStringContainsString('WHERE cc_product.id IN (' . $data['p1']->getId() . ',' . $data['p2']->getId() . ')', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testMagicFindOneByColumn()
    {
        $this->createCatalog();

        $found = ContractProductQuery::create()->findOneByName('P3');
        $this->assertInstanceOf(ContractProduct::class, $found);
        $this->assertSame('P3', $found->getName());
        $this->assertNull(ContractProductQuery::create()->findOneByName('nope'));
    }

    /**
     * @return void
     */
    public function testMagicFindByColumnReturnsCollection()
    {
        $this->createCatalog();

        $result = ContractProductQuery::create()->orderById()->findByType('tyre');

        $this->assertInstanceOf(ObjectCollection::class, $result);
        $this->assertSame(['P1', 'P3'], $result->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testMagicFindByColumnSqlUsesEquality()
    {
        $this->createCatalog();
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->findByName('P1', $con);

        $this->assertStringContainsString("WHERE cc_product.name='P1'", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testCountReturnsInt()
    {
        $this->createCatalog();

        $this->assertSame(4, ContractProductQuery::create()->count());
        $this->assertSame(2, ContractProductQuery::create()->filterByType('tyre')->count());
        $this->assertSame(0, ContractProductQuery::create()->filterByType('disc')->filterByName('P1')->count());
    }

    /**
     * @return void
     */
    public function testCountSqlStoresEnumAsIndex()
    {
        $this->createCatalog();
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->filterByType('tyre')->count($con);

        $this->assertSame("SELECT COUNT(*) FROM cc_product WHERE cc_product.type=0", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testExistsReturnsBool()
    {
        $this->createCatalog();

        $this->assertTrue(ContractProductQuery::create()->filterByName('P1')->exists());
        $this->assertFalse(ContractProductQuery::create()->filterByName('nope')->exists());
    }

    /**
     * @return void
     */
    public function testExistsSql()
    {
        $this->createCatalog();
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->filterByName('P1')->exists($con);

        $this->assertSame("SELECT 1 FROM cc_product WHERE cc_product.name='P1' LIMIT 1", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testPaginateReturnsPagerWithPageOfResults()
    {
        $this->createManyProducts(7);

        $pager = ContractProductQuery::create()->orderById()->paginate(2, 3);

        $this->assertInstanceOf(\Propel\Runtime\Util\PropelModelPager::class, $pager);
        $this->assertSame(7, $pager->getNbResults());
        $this->assertTrue($pager->haveToPaginate());
        $this->assertSame(3, $pager->getLastPage());
        $this->assertSame(2, $pager->getPage());
        $this->assertSame(3, $pager->getMaxPerPage());
        $this->assertSame(['N4', 'N5', 'N6'], $pager->getResults()->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testPaginateLastPageHasRemainder()
    {
        $this->createManyProducts(7);

        $pager = ContractProductQuery::create()->orderById()->paginate(3, 3);

        $this->assertSame(['N7'], $pager->getResults()->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testPaginateIsIterable()
    {
        $this->createManyProducts(5);

        $pager = ContractProductQuery::create()->orderById()->paginate(1, 2);
        $names = [];
        foreach ($pager as $product) {
            $this->assertInstanceOf(ContractProduct::class, $product);
            $names[] = $product->getName();
        }

        $this->assertSame(['N1', 'N2'], $names);
    }

    /**
     * @return void
     */
    public function testPaginateDoesNotHaveToPaginateWhenEverythingFitsOnePage()
    {
        $this->createManyProducts(3);

        $pager = ContractProductQuery::create()->paginate(1, 10);

        $this->assertFalse($pager->haveToPaginate());
        $this->assertSame(1, $pager->getLastPage());
        $this->assertSame(3, $pager->getNbResults());
    }

    /**
     * @return void
     */
    public function testPaginateSqlUsesCountAndLimit()
    {
        $this->createManyProducts(7);
        $con = $this->getDebugConnection();

        $pager = ContractProductQuery::create()->orderById()->paginate(2, 3, $con);
        $pager->getResults();

        $this->assertStringContainsString('LIMIT 3 OFFSET 3', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testQueryUpdateReturnsAffectedRowsAndWritesValues()
    {
        $this->createCatalog();

        $affected = ContractProductQuery::create()->filterByType('tyre')->update(['Qty' => 99]);

        $this->assertSame(2, $affected);
        $this->assertSame(2, ContractProductQuery::create()->filterByQty(99)->count());
        $this->assertSame(0, ContractProductQuery::create()->filterByType('tyre')->update(['Qty' => 99]) - 2);
    }

    /**
     * @return void
     */
    public function testQueryUpdateSql()
    {
        $this->createCatalog();
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->filterByName('P1')->update(['Qty' => 5, 'Name' => 'X'], $con);

        $this->assertStringContainsString("UPDATE cc_product SET", $con->getLastExecutedQuery());
        $this->assertStringContainsString("WHERE cc_product.name='P1'", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testQueryUpdateWithNoMatchReturnsZero()
    {
        $this->createCatalog();

        $this->assertSame(0, ContractProductQuery::create()->filterByName('nope')->update(['Qty' => 1]));
    }

    /**
     * @return void
     */
    public function testQueryDeleteReturnsAffectedRows()
    {
        $this->createCatalog();

        $affected = ContractProductQuery::create()->filterByType('tyre')->delete();

        $this->assertSame(2, $affected);
        $this->assertSame(2, ContractProductQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testQueryDeleteSql()
    {
        $this->createCatalog();
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->filterByName('P4')->delete($con);

        $this->assertSame("DELETE FROM cc_product WHERE cc_product.name='P4'", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testQueryDeleteWithoutConditionsThrows()
    {
        $this->createCatalog();

        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        ContractProductQuery::create()->delete();
    }

    /**
     * @return void
     */
    public function testQueryDeleteAllRemovesEverything()
    {
        $this->createCatalog();

        $this->assertSame(4, ContractProductQuery::create()->deleteAll());
        $this->assertSame(0, ContractProductQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testObjectDeleteRemovesRow()
    {
        $data = $this->createCatalog();
        $product = $data['p4'];

        $product->delete();

        $this->assertTrue($product->isDeleted());
        $this->assertSame(3, ContractProductQuery::create()->count());
        $this->assertNull(ContractProductQuery::create()->findPk($product->getId()));
    }

    /**
     * @return void
     */
    public function testObjectDeleteSql()
    {
        $data = $this->createCatalog();
        $con = $this->getDebugConnection();

        $data['p4']->delete($con);

        $this->assertSame('DELETE FROM cc_product WHERE cc_product.id=' . $data['p4']->getId(), $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testSavingDeletedObjectThrows()
    {
        $product = $this->createProduct('gone');
        $product->delete();

        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        $product->save();
    }

    /**
     * @param int $count
     *
     * @return void
     */
    private function createManyProducts(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->createProduct('N' . $i);
        }
        $this->clearPools();
    }
}
