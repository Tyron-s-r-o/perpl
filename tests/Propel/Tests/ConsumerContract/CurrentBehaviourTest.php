<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractProduct;
use ConsumerContract\ContractProductQuery;
use Propel\Runtime\Map\TableMap;

/**
 * Documents current behaviour that is surprising, looks like a bug or that the owner intends to change
 * deliberately (generated code slimming, modernisation). Every test here is expected to change on purpose
 * at some point: when one fails after a refactoring, decide whether the change is wanted and update it.
 */
class CurrentBehaviourTest extends ConsumerContractTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->createCatalog();
    }

    /**
     * @return \ConsumerContract\ContractProduct
     */
    private function loadProduct(string $name = 'P1'): ContractProduct
    {
        return ContractProductQuery::create()->findOneByName($name);
    }

    /**
     * Surprising: BIGINT is a PHP string in both directions.
     *
     * @return void
     */
    public function testBigintColumnIsPhpString()
    {
        $product = (new ContractProduct())->setName('big')->setBigNumber(5);
        $this->assertSame('5', $product->getBigNumber());
        $product->setBigNumber('9223372036854775807');
        $product->save();
        $this->clearPools();

        $reloaded = ContractProductQuery::create()->findPk($product->getId());

        $this->assertSame('9223372036854775807', (string)$reloaded->getBigNumber());
        $this->assertIsString($reloaded->getBigNumber());
        $this->assertIsString($reloaded->toArray(TableMap::TYPE_FIELDNAME)['big_number']);
    }

    /**
     * Surprising: select() of an ENUM column returns the stored index, not the value.
     *
     * @return void
     */
    public function testSelectOfEnumColumnReturnsRawIndexNotValue()
    {
        $this->assertSame([0, 1, 0, 2], ContractProductQuery::create()->select('Type')->orderById()->find()->getData());
        $this->assertSame(['tyre', 'disc', 'tyre', 'other'], ContractProductQuery::create()->orderById()->find()->getColumnValues('Type'));
    }

    /**
     * Surprising: ENUM is stored as the index into the value set, so reordering or inserting values
     * in the schema changes the meaning of existing rows.
     *
     * @return void
     */
    public function testEnumIsStoredAsIndexInRawRows()
    {
        $rows = $this->getDebugConnection()->query('SELECT name, type FROM cc_product ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame([0, 1, 0, 2], array_column($rows, 'type'));
    }

    /**
     * Looks like a bug: a hydrating joinWith inside useQuery() without joinWith on the main query selects the
     * related columns first and hydrates the MAIN model with the related table's data (product 1 is
     * called "C1", the name of its category) and returns one object per joined row.
     *
     * @return void
     */
    public function testJoinWithInsideUseQueryOnlyHydratesMainModelWithRelatedColumns()
    {
        \Propel\Runtime\Perpl::disableInstancePooling();
        $con = $this->getDebugConnection();

        $products = ContractProductQuery::create()
            ->useContractProductCategoryQuery()
                ->joinWithContractCategory()
            ->endUse()
            ->orderById()
            ->find($con);

        $this->assertStringStartsWith('SELECT cc_category.id, cc_category.name, cc_product.id', $con->getLastExecutedQuery());
        $this->assertCount(3, $products);
        $this->assertContainsOnlyInstancesOf(ContractProduct::class, $products->getData());
        $this->assertSame(['C1', 'C2', 'C1'], $products->getColumnValues('Name'), 'category names end up in the product');
        $this->assertSame([1, 2, 1], $products->getColumnValues('Id'));
    }

    /**
     * Surprising: Query::update() does not clear the instance pool, findPk() keeps handing out stale data.
     *
     * @return void
     */
    public function testQueryUpdateLeavesStalePooledInstance()
    {
        $id = ContractProductQuery::create()->findOneByName('P1')->getId();
        $pooled = ContractProductQuery::create()->findPk($id);

        ContractProductQuery::create()->filterById($id)->update(['Qty' => 321]);

        $this->assertSame($pooled, ContractProductQuery::create()->findPk($id));
        $this->assertSame(3, ContractProductQuery::create()->findPk($id)->getQty());
        $this->clearPools();
        $this->assertSame(321, ContractProductQuery::create()->findPk($id)->getQty());
    }

    /**
     * Surprising: the DateTime returned by a getter is the model's cached instance. Mutating it changes what the
     * model returns, but the column is not marked modified and save() does not persist it.
     *
     * @return void
     */
    public function testMutatingDateTimeReturnedByGetterChangesModelSilently()
    {
        $product = $this->createProduct('dated', ['ReleasedOn' => '2020-02-03']);

        $date = $product->getReleasedOn();
        $date->modify('+1 day');

        $this->assertSame($date, $product->getReleasedOn(), 'same instance');
        $this->assertSame('2020-02-04', $product->getReleasedOn()->format('Y-m-d'));
        $this->assertSame('2020-02-03', $product->getReleasedOn('Y-m-d'), 'while the formatting getter still reads the raw value');
        $this->assertFalse($product->isModified());
        $this->assertSame(0, $product->save());
        $this->clearPools();
        $this->assertSame('2020-02-03', ContractProductQuery::create()->findPk($product->getId())->getReleasedOn('Y-m-d'));
    }

    /**
     * Surprising: only modified columns are written, so a mutation of the cached DateTime is lost even when
     * the model is saved for another change.
     *
     * @return void
     */
    public function testMutatedDateTimeIsLostWhenAnotherColumnIsSaved()
    {
        $product = $this->createProduct('dated', ['ReleasedOn' => '2020-02-03']);
        $product->getReleasedOn()->modify('+1 day');

        $product->setQty(77);
        $product->save();

        $this->clearPools();
        $reloaded = ContractProductQuery::create()->findPk($product->getId());
        $this->assertSame(77, $reloaded->getQty());
        $this->assertSame('2020-02-03', $reloaded->getReleasedOn('Y-m-d'));
    }

    /**
     * Surprising: an integer is not treated as a unix timestamp by the TIMESTAMP setter.
     *
     * @return void
     */
    public function testTimestampSetterDoesNotInterpretIntegerAsUnixTimestamp()
    {
        $product = (new ContractProduct())->setName('x');

        $product->setCreatedAt(86400 * 365);

        $this->assertSame('6000', $product->getCreatedAt('Y'));
    }

    /**
     * Surprising: with pooling on, joined rows that repeat a main object give the SAME instance twice, so a
     * virtual column filled per row ends up with the value of the last row on every occurrence.
     *
     * @return void
     */
    public function testVirtualColumnOfRepeatedPooledObjectHoldsLastRowValue()
    {
        $products = ContractProductQuery::create()
            ->useContractProductCategoryQuery('a')
                ->useContractCategoryQuery('b')
                    ->withColumn('b.Name', 'category_name')
                ->endUse()
            ->endUse()
            ->orderById()
            ->orderBy('a.Priority')
            ->find();

        $this->assertCount(3, $products);
        $this->assertSame($products[0], $products[1]);
        $this->assertSame(['C2', 'C2', 'C1'], array_map(fn ($p) => $p->getVirtualColumn('category_name'), $products->getData()));
    }

    /**
     * Surprising: after a failed commit of an outer transaction (nested rollback), the connection stays
     * in the transaction.
     *
     * @return void
     */
    public function testConnectionStaysInTransactionAfterFailedCommit()
    {
        $con = \Propel\Runtime\Perpl::getConnection('contract');
        $con->beginTransaction();
        $con->beginTransaction();
        $con->rollBack();

        try {
            $con->commit();
        } catch (\Propel\Runtime\Connection\Exception\RollbackException) {
        }

        $this->assertTrue($con->inTransaction());
        $con->rollBack();
        $this->assertFalse($con->inTransaction());
    }

    /**
     * Surprising: a wildcard in a plain filterByXxx() is NOT turned into LIKE (it is in upstream Propel 2).
     *
     * @return void
     */
    public function testFilterByWithWildcardIsPlainEquality()
    {
        $this->assertCount(0, ContractProductQuery::create()->filterByName('P%')->find());
        $this->assertCount(4, ContractProductQuery::create()->filterByName('P%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)->find());
    }

    /**
     * Surprising: the debug query log only contains values for the ORM's own statements, raw prepared
     * statements are logged with their placeholders.
     *
     * @return void
     */
    public function testRawPreparedStatementIsLoggedWithPlaceholders()
    {
        $con = $this->getDebugConnection();

        $statement = $con->prepare('SELECT * FROM cc_product WHERE name = ?');
        $statement->execute(['P1']);

        $this->assertSame('SELECT * FROM cc_product WHERE name = ?', $con->getLastExecutedQuery());
    }

    /**
     * Surprising: generated order/filter/find helpers like orderByName() are not real methods but are
     * resolved dynamically.
     *
     * @return void
     */
    public function testOrderByAndFindByHelpersAreResolvedMagically()
    {
        $this->assertFalse(method_exists(ContractProductQuery::class, 'orderByName'));
        $this->assertFalse(method_exists(ContractProductQuery::class, 'findOneByName'));
        $this->assertFalse(method_exists(ContractProductQuery::class, 'findByName'));
        $this->assertTrue(method_exists(ContractProductQuery::class, 'filterByName'));
        $this->assertSame('P4', ContractProductQuery::create()->orderByName('desc')->findOne()->getName());
    }
}
