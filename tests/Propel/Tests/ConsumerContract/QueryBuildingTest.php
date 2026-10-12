<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractProductCategoryQuery;
use ConsumerContract\ContractProductQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ArrayCollection;

/**
 * Pins the query building API (filters, conditions, ordering, select, virtual columns).
 */
class QueryBuildingTest extends ConsumerContractTestCase
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
     * @param \Propel\Runtime\ActiveQuery\ModelCriteria $query
     *
     * @return array<string>
     */
    private function names($query): array
    {
        return $query->orderById()->find()->getColumnValues('Name');
    }

    /**
     * @return void
     */
    public function testFilterByScalarIsEquality()
    {
        $con = $this->getDebugConnection();

        $this->assertSame(['P2'], $this->names(ContractProductQuery::create()->filterByQty(10)));
        ContractProductQuery::create()->filterByQty(10)->find($con);
        $this->assertStringContainsString('WHERE cc_product.qty=10', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByArrayIsIn()
    {
        $con = $this->getDebugConnection();

        $query = ContractProductQuery::create()->filterByName(['P1', 'P3']);
        $this->assertSame(['P1', 'P3'], $this->names($query));
        ContractProductQuery::create()->filterByName(['P1', 'P3'])->find($con);
        $this->assertStringContainsString("WHERE cc_product.name IN ('P1','P3')", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByMinMaxIsRange()
    {
        $con = $this->getDebugConnection();

        $this->assertSame(['P3', 'P2'], ContractProductQuery::create()->filterByQty(['min' => 5, 'max' => 10])->orderByQty()->find()->getColumnValues('Name'));
        ContractProductQuery::create()->filterByQty(['min' => 5, 'max' => 10])->find($con);
        $this->assertStringContainsString('WHERE (cc_product.qty>=5 AND cc_product.qty<=10)', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByMinOnlyAndMaxOnly()
    {
        $this->assertSame(['P2', 'P3'], $this->names(ContractProductQuery::create()->filterByQty(['min' => 5])));
        $this->assertSame(['P1', 'P4'], $this->names(ContractProductQuery::create()->filterByQty(['max' => 3])));
    }

    /**
     * @return void
     */
    public function testFilterByWithLike()
    {
        $con = $this->getDebugConnection();

        $this->assertSame(['P1'], $this->names(ContractProductQuery::create()->filterByName('P1%', Criteria::LIKE)));
        ContractProductQuery::create()->filterByName('P%', Criteria::LIKE)->find($con);
        $this->assertStringContainsString("WHERE cc_product.name LIKE 'P%'", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByWildcardWithoutOperatorIsPlainEquality()
    {
        $con = $this->getDebugConnection();

        $this->assertSame([], $this->names(ContractProductQuery::create()->filterByName('P%')));
        ContractProductQuery::create()->filterByName('P%')->find($con);
        $this->assertStringContainsString("WHERE cc_product.name='P%'", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByWithNotEqual()
    {
        $con = $this->getDebugConnection();

        $this->assertSame(['P2', 'P3', 'P4'], $this->names(ContractProductQuery::create()->filterByName('P1', Criteria::NOT_EQUAL)));
        ContractProductQuery::create()->filterByName('P1', Criteria::NOT_EQUAL)->find($con);
        $this->assertStringContainsString("WHERE cc_product.name<>'P1'", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByWithIsNullAndIsNotNull()
    {
        $con = $this->getDebugConnection();

        $this->assertSame(['P4'], $this->names(ContractProductQuery::create()->filterByIdManufacturer(null, Criteria::ISNULL)));
        $this->assertSame(['P1', 'P2', 'P3'], $this->names(ContractProductQuery::create()->filterByIdManufacturer(null, Criteria::ISNOTNULL)));
        ContractProductQuery::create()->filterByIdManufacturer(null, Criteria::ISNOTNULL)->find($con);
        $this->assertStringContainsString('WHERE cc_product.id_manufacturer IS NOT NULL', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByNullIsIsNull()
    {
        $con = $this->getDebugConnection();

        $this->assertSame(['P4'], $this->names(ContractProductQuery::create()->filterByIdManufacturer(null)));
        ContractProductQuery::create()->filterByIdManufacturer(null)->find($con);
        $this->assertStringContainsString('WHERE cc_product.id_manufacturer IS NULL', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByWithGreaterEqual()
    {
        $con = $this->getDebugConnection();

        $this->assertSame(['P2', 'P3'], $this->names(ContractProductQuery::create()->filterByQty(7, Criteria::GREATER_EQUAL)));
        ContractProductQuery::create()->filterByQty(7, Criteria::GREATER_EQUAL)->find($con);
        $this->assertStringContainsString('WHERE cc_product.qty>=7', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByWithNotIn()
    {
        $con = $this->getDebugConnection();

        $this->assertSame(['P2', 'P4'], $this->names(ContractProductQuery::create()->filterByName(['P1', 'P3'], Criteria::NOT_IN)));
        ContractProductQuery::create()->filterByName(['P1', 'P3'], Criteria::NOT_IN)->find($con);
        $this->assertStringContainsString("WHERE cc_product.name NOT IN ('P1','P3')", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByEnumValue()
    {
        $this->assertSame(['P1', 'P3'], $this->names(ContractProductQuery::create()->filterByType('tyre')));
        $this->assertSame(['P1', 'P2', 'P3'], $this->names(ContractProductQuery::create()->filterByType(['tyre', 'disc'])));
    }

    /**
     * @return void
     */
    public function testFilterByBoolean()
    {
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $this->names(ContractProductQuery::create()->filterByIsActive(true)));
        $this->assertSame([], $this->names(ContractProductQuery::create()->filterByIsActive(false)));
    }

    /**
     * @return void
     */
    public function testGenericFilterByUsesPhpName()
    {
        $con = $this->getDebugConnection();

        $this->assertSame(['P3'], $this->names(ContractProductQuery::create()->filterBy('Name', 'P3')));
        ContractProductQuery::create()->filterBy('Qty', 5, Criteria::GREATER_THAN)->find($con);
        $this->assertStringContainsString('WHERE cc_product.qty>5', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testWhereWithLikeAndPlaceholder()
    {
        $con = $this->getDebugConnection();

        $this->assertSame(['P1'], $this->names(ContractProductQuery::create()->where('ContractProduct.Name LIKE ?', 'P1')));
        ContractProductQuery::create()->where('ContractProduct.Name LIKE ?', 'P%')->find($con);
        $this->assertStringContainsString("WHERE cc_product.name LIKE 'P%'", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testMultipleWhereAreAnded()
    {
        $con = $this->getDebugConnection();

        $query = ContractProductQuery::create()->where('ContractProduct.Qty > ?', 2)->where('ContractProduct.Type = ?', 'tyre');
        $this->assertSame(['P1', 'P3'], $this->names($query));
        ContractProductQuery::create()->where('ContractProduct.Qty > ?', 2)->where('ContractProduct.Type = ?', 'tyre')->find($con);
        $this->assertStringContainsString('WHERE cc_product.qty > 2 AND cc_product.type = 0', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testNamedConditionsCombinedWithOr()
    {
        $con = $this->getDebugConnection();
        $build = fn () => ContractProductQuery::create()
            ->condition('c1', 'ContractProduct.Qty > ?', 5)
            ->condition('c2', 'ContractProduct.Name = ?', 'P1')
            ->where(['c1', 'c2'], Criteria::LOGICAL_OR);

        $this->assertSame(['P1', 'P2', 'P3'], $this->names($build()));
        $build()->find($con);
        $this->assertStringContainsString("WHERE (cc_product.qty > 5 OR cc_product.name = 'P1')", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testCombineAndWhereWithNamedCondition()
    {
        $con = $this->getDebugConnection();
        $build = fn () => ContractProductQuery::create()
            ->condition('c1', 'ContractProduct.Qty > ?', 5)
            ->condition('c2', 'ContractProduct.Name = ?', 'P1')
            ->condition('c3', 'ContractProduct.Type = ?', 'tyre')
            ->combine(['c1', 'c2'], Criteria::LOGICAL_OR, 'c12')
            ->where(['c12', 'c3'], Criteria::LOGICAL_AND);

        $this->assertSame(['P1', 'P3'], $this->names($build()));
        $build()->find($con);
        $this->assertStringContainsString("WHERE ((cc_product.qty > 5 OR cc_product.name = 'P1') AND cc_product.type = 0)", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testHavingWithNamedConditions()
    {
        $con = $this->getDebugConnection();
        $build = fn () => ContractProductQuery::create()
            ->withColumn('SUM(ContractProduct.Qty)', 'total')
            ->groupByType()
            ->condition('h1', 'SUM(ContractProduct.Qty) > ?', 5)
            ->condition('h2', 'SUM(ContractProduct.Qty) = ?', 0)
            ->having(['h1', 'h2'], Criteria::LOGICAL_OR);

        $result = $build()->select(['Type', 'total'])->orderByType()->find();
        // select() returns the raw ENUM index, not the value (see CurrentBehaviourTest)
        $this->assertEqualsCanonicalizing(
            [['Type' => 0, 'total' => 10], ['Type' => 1, 'total' => 10], ['Type' => 2, 'total' => 0]],
            array_map(fn ($row) => ['Type' => $row['Type'], 'total' => (int)$row['total']], $result->getData()),
        );
        $build()->select(['Type', 'total'])->find($con);
        $this->assertStringContainsString('GROUP BY cc_product.type HAVING (SUM(cc_product.qty) > 5 OR SUM(cc_product.qty) = 0)', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testOrFilter()
    {
        $con = $this->getDebugConnection();
        $build = fn () => ContractProductQuery::create()->filterByName('P1')->_or()->filterByName('P4');

        $this->assertSame(['P1', 'P4'], $this->names($build()));
        $build()->find($con);
        $this->assertStringContainsString("WHERE (cc_product.name='P1' OR cc_product.name='P4')", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testOrBindsOnlyToAdjacentConditions()
    {
        $con = $this->getDebugConnection();
        $build = fn () => ContractProductQuery::create()->filterByType('tyre')->filterByName('P1')->_or()->filterByName('P2');

        $this->assertSame(['P1'], $this->names($build()));
        $build()->find($con);
        $this->assertStringContainsString("WHERE cc_product.type=0 AND (cc_product.name='P1' OR cc_product.name='P2')", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testAndFilterIsDefaultOperator()
    {
        $con = $this->getDebugConnection();
        $build = fn () => ContractProductQuery::create()->filterByType('tyre')->_and()->filterByQty(3);

        $this->assertSame(['P1'], $this->names($build()));
        $build()->find($con);
        $this->assertStringContainsString('WHERE cc_product.type=0 AND cc_product.qty=3', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testOrderByGeneratedMethodDefaultsToAscending()
    {
        $this->assertSame(['P4', 'P1', 'P3', 'P2'], ContractProductQuery::create()->orderByQty()->find()->getColumnValues('Name'));
        $this->assertSame(['P2', 'P3', 'P1', 'P4'], ContractProductQuery::create()->orderByQty(Criteria::DESC)->find()->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testGenericOrderByUsesPhpName()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()->orderBy('Name', Criteria::DESC)->find($con);

        $this->assertSame(['P4', 'P3', 'P2', 'P1'], $result->getColumnValues('Name'));
        $this->assertStringContainsString('ORDER BY cc_product.name DESC', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testLimitAndOffset()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()->orderById()->limit(2)->offset(1)->find($con);

        $this->assertSame(['P2', 'P3'], $result->getColumnValues('Name'));
        $this->assertStringContainsString('LIMIT 2 OFFSET 1', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testLimitWithoutOffset()
    {
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->limit(3)->find($con);

        $this->assertStringEndsWith('LIMIT 3', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testDistinct()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()->select('Type')->distinct()->orderByType()->find($con);

        $this->assertCount(3, $result);
        $this->assertStringContainsString('SELECT DISTINCT cc_product.type', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testGroupByWithAddAsColumn()
    {
        $con = $this->getDebugConnection();
        $build = fn () => ContractProductQuery::create()
            ->addAsColumn('cnt', 'COUNT(cc_product.id)')
            ->select(['Type', 'cnt'])
            ->groupByType()
            ->orderByType();

        $result = $build()->find($con);

        // select() returns the raw ENUM index, not the value (see CurrentBehaviourTest)
        $this->assertSame([['Type' => 0, 'cnt' => '2'], ['Type' => 1, 'cnt' => '1'], ['Type' => 2, 'cnt' => '1']], array_map(fn ($row) => ['Type' => $row['Type'], 'cnt' => (string)$row['cnt']], $result->getData()));
        $this->assertStringContainsString('COUNT(cc_product.id) AS cnt', $con->getLastExecutedQuery());
        $this->assertStringContainsString('GROUP BY cc_product.type', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testWithColumnGivesVirtualColumnOnModel()
    {
        $product = ContractProductQuery::create()
            ->withColumn('ContractProduct.Qty * 2', 'double_qty')
            ->filterByName('P2')
            ->findOne();

        $this->assertTrue($product->hasVirtualColumn('double_qty'));
        $this->assertEquals(20, $product->getVirtualColumn('double_qty'));
        $this->assertSame(['double_qty' => $product->getVirtualColumn('double_qty')], $product->getVirtualColumns());
    }

    /**
     * @return void
     */
    public function testWithColumnSql()
    {
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->withColumn('ContractProduct.Qty * 2', 'double_qty')->find($con);

        $this->assertStringContainsString('cc_product.qty * 2 AS double_qty', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testAddAsColumnGivesVirtualColumnOnModel()
    {
        $product = ContractProductQuery::create()
            ->addAsColumn('lower_name', 'LOWER(cc_product.name)')
            ->filterByName('P2')
            ->findOne();

        $this->assertSame('p2', $product->getVirtualColumn('lower_name'));
    }

    /**
     * @return void
     */
    public function testSelectSingleColumnReturnsScalarsInArrayCollection()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()->select('Name')->orderById()->find($con);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $result->getData());
        $this->assertStringStartsWith('SELECT cc_product.name AS "Name" FROM cc_product', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testSelectSingleColumnWithFindOneReturnsScalar()
    {
        $this->assertSame('P1', ContractProductQuery::create()->select('Name')->orderById()->findOne());
    }

    /**
     * @return void
     */
    public function testSelectSingleColumnWithFindOneOnNoMatch()
    {
        $this->assertNull(ContractProductQuery::create()->select('Name')->filterByName('nope')->findOne());
    }

    /**
     * @return void
     */
    public function testSelectMultipleColumnsReturnsRowsKeyedByNames()
    {
        $result = ContractProductQuery::create()->select(['Id', 'Name'])->orderById()->find();

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $first = $result->getFirst();
        $this->assertSame(['Id', 'Name'], array_keys($first));
        $this->assertSame('P1', $first['Name']);
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_column($result->getData(), 'Name'));
    }

    /**
     * @return void
     */
    public function testSelectWithDbStyleColumnName()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()->select('cc_product.name')->orderById()->find($con);

        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $result->getData());
    }

    /**
     * @return void
     */
    public function testSelectWithDbStyleColumnNamesInArray()
    {
        $result = ContractProductQuery::create()->select(['cc_product.id', 'cc_product.name'])->orderById()->find();

        $first = $result->getFirst();
        $this->assertSame(['cc_product.id', 'cc_product.name'], array_keys($first));
    }

    /**
     * @return void
     */
    public function testSelectCombinedWithAddAsColumn()
    {
        $result = ContractProductQuery::create()
            ->addAsColumn('upper_name', 'UPPER(cc_product.name)')
            ->select(['Id', 'upper_name'])
            ->filterByName('P1')
            ->find();

        $this->assertEqualsCanonicalizing(['Id', 'upper_name'], array_keys($result->getFirst()));
        $this->assertSame('P1', $result->getFirst()['upper_name']);
    }

    /**
     * @return void
     */
    public function testSelectCombinedWithJoinedColumn()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()
            ->joinContractManufacturer()
            ->select(['Name', 'ContractManufacturer.Name'])
            ->orderById()
            ->find($con);

        $this->assertSame(
            [['Name' => 'P1', 'ContractManufacturer.Name' => 'A'], ['Name' => 'P2', 'ContractManufacturer.Name' => 'A'], ['Name' => 'P3', 'ContractManufacturer.Name' => 'B'], ['Name' => 'P4', 'ContractManufacturer.Name' => null]],
            $result->getData(),
        );
        $this->assertStringContainsString('LEFT JOIN cc_manufacturer ON (cc_product.id_manufacturer=cc_manufacturer.id)', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testSelectWithWithColumnAlias()
    {
        $result = ContractProductQuery::create()
            ->withColumn('ContractProduct.Qty + 1', 'qty_plus')
            ->select(['Name', 'qty_plus'])
            ->filterByName('P2')
            ->find();

        $this->assertEquals(['Name' => 'P2', 'qty_plus' => 11], $result->getFirst());
    }

    /**
     * @return void
     */
    public function testQueryOnJoinTableFiltersByGeneratedMethod()
    {
        $result = ContractProductCategoryQuery::create()->filterByPriority(2)->find();

        $this->assertCount(1, $result);
        $this->assertSame(2, $result->getFirst()->getPriority());
    }
}
