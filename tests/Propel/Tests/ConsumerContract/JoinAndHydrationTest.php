<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractManufacturerQuery;
use ConsumerContract\ContractOrderQuery;
use ConsumerContract\ContractProductQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Pins joins, relation filters and hydrating joins.
 */
class JoinAndHydrationTest extends ConsumerContractTestCase
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
    }

    /**
     * @param \Propel\Runtime\Connection\ConnectionInterface $con
     *
     * @return string
     */
    private function fromClause(ConnectionInterface $con): string
    {
        $sql = $con->getLastExecutedQuery();

        return substr($sql, (int)strpos($sql, ' FROM '));
    }

    /**
     * @return void
     */
    public function testPlainJoinOnNullableForeignKeyIsLeftJoin()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()->joinContractManufacturer()->orderById()->find($con);

        $this->assertSame(' FROM cc_product LEFT JOIN cc_manufacturer ON (cc_product.id_manufacturer=cc_manufacturer.id) ORDER BY cc_product.id ASC', $this->fromClause($con));
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $result->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testPlainJoinOnRequiredForeignKeyIsInnerJoin()
    {
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->joinContractProductCategory()->find($con);

        $this->assertSame(' FROM cc_product INNER JOIN cc_product_category ON (cc_product.id=cc_product_category.id_product)', $this->fromClause($con));
    }

    /**
     * @return void
     */
    public function testLeftJoinAndInnerJoinMagicMethods()
    {
        $con = $this->getDebugConnection();

        $left = ContractProductQuery::create()->leftJoinContractProductCategory()->find($con);
        $this->assertSame(' FROM cc_product LEFT JOIN cc_product_category ON (cc_product.id=cc_product_category.id_product)', $this->fromClause($con));
        $this->assertCount(5, $left, 'one product object per joined row (P1 has two links), no deduplication without joinWith');

        $inner = ContractProductQuery::create()->innerJoinContractManufacturer()->find($con);
        $this->assertSame(' FROM cc_product INNER JOIN cc_manufacturer ON (cc_product.id_manufacturer=cc_manufacturer.id)', $this->fromClause($con));
        $this->assertCount(3, $inner);
    }

    /**
     * @return void
     */
    public function testLeftJoinWithAliasAndJoinCondition()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()
            ->leftJoinContractProductCategory('pc')
            ->addJoinCondition('pc', 'pc.Priority = ?', 1)
            ->where('pc.Id IS NOT NULL')
            ->find($con);

        $this->assertStringContainsString('LEFT JOIN cc_product_category pc ON (cc_product.id=pc.id_product AND pc.priority = 1)', $con->getLastExecutedQuery());
        $this->assertSame(['P1'], $result->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testUseQueryFiltersOnRelatedTable()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()
            ->useContractManufacturerQuery()
                ->filterByName('A')
            ->endUse()
            ->orderById()
            ->find($con);

        $this->assertSame(['P1', 'P2'], $result->getColumnValues('Name'));
        $this->assertStringContainsString("FROM cc_product LEFT JOIN cc_manufacturer ON (cc_product.id_manufacturer=cc_manufacturer.id) WHERE cc_manufacturer.name='A'", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testUseQueryOnRequiredForeignKeyIsInnerJoin()
    {
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->useContractProductCategoryQuery()->filterByPriority(1)->endUse()->find($con);

        $this->assertStringContainsString('FROM cc_product INNER JOIN cc_product_category ON (cc_product.id=cc_product_category.id_product) WHERE cc_product_category.priority=1', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testUseQueryWithAliasAndJoinType()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()
            ->useContractProductCategoryQuery('pc', Criteria::LEFT_JOIN)
                ->filterByPriority(null, Criteria::ISNULL)
            ->endUse()
            ->find($con);

        $this->assertStringContainsString('FROM cc_product LEFT JOIN cc_product_category pc ON (cc_product.id=pc.id_product) WHERE pc.priority IS NULL', $con->getLastExecutedQuery());
        $this->assertSame(['P3', 'P4'], $result->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testNestedUseQueryWithAliasesAndWithColumn()
    {
        $con = $this->getDebugConnection();

        \Propel\Runtime\Perpl::disableInstancePooling();
        $result = ContractProductQuery::create()
            ->useContractProductCategoryQuery('a')
                ->useContractCategoryQuery('b')
                    ->withColumn('b.Name', 'category_name')
                ->endUse()
            ->endUse()
            ->orderById()
            ->orderBy('a.Priority')
            ->find($con);

        $this->assertStringContainsString('b.name AS category_name', $con->getLastExecutedQuery());
        $this->assertStringContainsString('FROM cc_product INNER JOIN cc_product_category a ON (cc_product.id=a.id_product) INNER JOIN cc_category b ON (a.id_category=b.id)', $con->getLastExecutedQuery());
        $this->assertCount(3, $result, 'one product per joined row, no deduplication without joinWith');
        $this->assertSame(['P1', 'P1', 'P2'], $result->getColumnValues('Name'));
        $this->assertSame(['C1', 'C2', 'C1'], array_map(fn ($p) => $p->getVirtualColumn('category_name'), $result->getData()));
    }

    /**
     * @return void
     */
    public function testSameUseQueryTwiceProducesOneJoinWithBothConditions()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()
            ->useContractManufacturerQuery()->filterByName('A')->endUse()
            ->useContractManufacturerQuery()->filterByCode('A')->endUse()
            ->find($con);

        $sql = $con->getLastExecutedQuery();
        $this->assertSame(1, substr_count($sql, 'JOIN cc_manufacturer'));
        $this->assertStringContainsString("WHERE cc_manufacturer.name='A' AND cc_manufacturer.code='A'", $sql);
        $this->assertCount(2, $result);
    }

    /**
     * @return void
     */
    public function testInnerJoinFollowedByUseQueryOnSameRelationEndsUpAsLeftJoin()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()
            ->innerJoinContractManufacturer()
            ->useContractManufacturerQuery()->filterByName('A')->endUse()
            ->find($con);

        $sql = $con->getLastExecutedQuery();
        $this->assertSame(1, substr_count($sql, 'JOIN cc_manufacturer'), 'only one join');
        $this->assertStringContainsString('LEFT JOIN cc_manufacturer', $sql, 'the useQuery default join type replaces the explicit inner join');
        $this->assertCount(2, $result);
    }

    /**
     * @return void
     */
    public function testStringJoinSyntaxWithAliasAndWhere()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()
            ->join('ContractProduct.ContractProductCategory pc')
            ->where('pc.Priority = ?', 1)
            ->find($con);

        $this->assertStringContainsString('FROM cc_product INNER JOIN cc_product_category pc ON (cc_product.id=pc.id_product) WHERE pc.priority = 1', $con->getLastExecutedQuery());
        $this->assertSame(['P1'], $result->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testUseExistsQuery()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()
            ->useExistsQuery('ContractProductCategory')->filterByPriority(5)->endUse()
            ->find($con);

        $this->assertSame(['P2'], $result->getColumnValues('Name'));
        $this->assertStringContainsString('WHERE EXISTS (SELECT 1 AS existsFlag FROM cc_product_category WHERE cc_product_category.id_product=cc_product.id AND cc_product_category.priority=5)', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testUseNotExistsQuery()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()
            ->useNotExistsQuery('ContractProductCategory')->endUse()
            ->orderById()
            ->find($con);

        $this->assertSame(['P3', 'P4'], $result->getColumnValues('Name'));
        $this->assertStringContainsString('WHERE NOT EXISTS (SELECT 1 AS existsFlag FROM cc_product_category WHERE cc_product_category.id_product=cc_product.id)', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testTwoForeignKeysToSameTableNeedAliases()
    {
        $con = $this->getDebugConnection();
        $billing = $this->createAddress('Billing');
        $delivery = $this->createAddress('Delivery');
        $order = $this->createOrder('O1');
        $order->setBillingAddress($billing);
        $order->setContractAddressRelatedByIdDeliveryAddress($delivery);
        $order->save();

        $result = ContractOrderQuery::create()
            ->joinBillingAddress('b')
            ->joinContractAddressRelatedByIdDeliveryAddress('d')
            ->where('b.City = ?', 'Billing')
            ->where('d.City = ?', 'Delivery')
            ->find($con);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('LEFT JOIN cc_address b ON (cc_order.id_billing_address=b.id) LEFT JOIN cc_address d ON (cc_order.id_delivery_address=d.id)', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByManyToOneRelationObjectUsesForeignKeyEquality()
    {
        $con = $this->getDebugConnection();

        $result = ContractProductQuery::create()->filterByContractManufacturer($this->data['a'])->orderById()->find($con);

        $this->assertSame(['P1', 'P2'], $result->getColumnValues('Name'));
        $this->assertStringContainsString('FROM cc_product WHERE cc_product.id_manufacturer=' . $this->data['a']->getId(), $con->getLastExecutedQuery());
        $this->assertStringNotContainsString('JOIN', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByManyToOneRelationCollectionUsesIn()
    {
        $con = $this->getDebugConnection();
        $manufacturers = ContractManufacturerQuery::create()->find();

        $result = ContractProductQuery::create()->filterByContractManufacturer($manufacturers)->orderById()->find($con);

        $this->assertSame(['P1', 'P2', 'P3'], $result->getColumnValues('Name'));
        $this->assertStringContainsString('FROM cc_product WHERE cc_product.id_manufacturer IN (', $con->getLastExecutedQuery());
        $this->assertStringNotContainsString('JOIN', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByOneToManyRelationObjectUsesForeignKeyValueOfChild()
    {
        $con = $this->getDebugConnection();

        $result = ContractManufacturerQuery::create()->filterByContractProduct($this->data['p3'])->find($con);

        $this->assertSame(['B'], $result->getColumnValues('Name'));
        $this->assertStringContainsString('FROM cc_manufacturer WHERE cc_manufacturer.id=' . $this->data['b']->getId(), $con->getLastExecutedQuery());
        $this->assertStringNotContainsString('JOIN', $con->getLastExecutedQuery(), 'the object is translated into a condition on the parent key, no join');
    }

    /**
     * @return void
     */
    public function testFilterByOneToManyRelationCollectionJoinsAndUsesIn()
    {
        $con = $this->getDebugConnection();
        $products = ContractProductQuery::create()->filterByName(['P1', 'P3'])->find();

        $result = ContractManufacturerQuery::create()->filterByContractProduct($products)->orderById()->find($con);

        $this->assertSame(['A', 'B'], $result->getColumnValues('Name'));
        $this->assertStringContainsString('JOIN cc_product', $con->getLastExecutedQuery());
        $this->assertStringContainsString('cc_product.id IN (', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testFilterByRelationWithObjectOfWrongTypeThrows()
    {
        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        ContractProductQuery::create()->filterByContractManufacturer($this->data['p1']);
    }

    /**
     * @return void
     */
    public function testJoinWithManyToOneHydratesRelationWithoutExtraQuery()
    {
        $con = $this->getDebugConnection();

        $products = ContractProductQuery::create()->joinWithContractManufacturer()->orderById()->find($con);
        $queryCount = $con->getQueryCount();

        $this->assertStringContainsString('INNER JOIN cc_manufacturer', $con->getLastExecutedQuery());
        $this->assertCount(3, $products, 'inner join drops product without manufacturer');
        $this->assertSame('A', $products->getFirst()->getContractManufacturer()->getName());
        $this->assertSame('B', $products->getLast()->getContractManufacturer()->getName());
        $this->assertSame($queryCount, $con->getQueryCount(), 'no additional query');
    }

    /**
     * @return array<string, array<string>>
     */
    public static function leftJoinWithVariantsProvider(): array
    {
        return [
            'leftJoinWithXxx' => ['leftJoinWithContractManufacturer', []],
            'joinWithXxx(LEFT_JOIN)' => ['joinWithContractManufacturer', [Criteria::LEFT_JOIN]],
            'joinWith(relation, LEFT_JOIN)' => ['joinWith', ['ContractManufacturer', Criteria::LEFT_JOIN]],
        ];
    }

    /**
     * @param string $method
     * @param array<mixed> $args
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('leftJoinWithVariantsProvider')]
    public function testLeftHydratingJoinVariantsKeepProductsWithoutRelation(string $method, array $args)
    {
        $con = $this->getDebugConnection();

        $products = ContractProductQuery::create()->$method(...$args)->orderById()->find($con);
        $queryCount = $con->getQueryCount();

        $this->assertStringContainsString('LEFT JOIN cc_manufacturer', $con->getLastExecutedQuery());
        $this->assertCount(4, $products);
        $this->assertSame('A', $products->getFirst()->getContractManufacturer()->getName());
        $this->assertNull($products->getLast()->getContractManufacturer());
        $this->assertSame($queryCount, $con->getQueryCount(), 'no additional query, not even for the product without manufacturer');
    }

    /**
     * @return void
     */
    public function testInnerJoinWithAndJoinWithStringDefaultToInnerJoin()
    {
        $con = $this->getDebugConnection();

        ContractProductQuery::create()->innerJoinWithContractManufacturer()->find($con);
        $this->assertStringContainsString('INNER JOIN cc_manufacturer', $con->getLastExecutedQuery());

        ContractProductQuery::create()->joinWith('ContractManufacturer')->find($con);
        $this->assertStringContainsString('INNER JOIN cc_manufacturer', $con->getLastExecutedQuery());

        ContractProductQuery::create()->joinWithContractManufacturer()->find($con);
        $this->assertStringContainsString('INNER JOIN cc_manufacturer', $con->getLastExecutedQuery(), 'unlike plain joinXxx(), joinWithXxx() is INNER even for a nullable foreign key');
    }

    /**
     * @return void
     */
    public function testJoinWithOneToManyReturnsEachMainObjectOnceWithAllChildrenHydrated()
    {
        $con = $this->getDebugConnection();

        $products = ContractProductQuery::create()->leftJoinWithContractProductCategory()->orderById()->find($con);
        $queryCount = $con->getQueryCount();

        $this->assertCount(4, $products, 'main objects are not duplicated by the joined rows');
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $products->getColumnValues('Name'));
        $counts = [];
        foreach ($products as $product) {
            $counts[$product->getName()] = count($product->getContractProductCategories());
        }
        $this->assertSame(['P1' => 2, 'P2' => 1, 'P3' => 0, 'P4' => 0], $counts);
        $this->assertSame($queryCount, $con->getQueryCount(), 'no additional query');
        $this->assertStringContainsString('cc_product_category.priority FROM cc_product LEFT JOIN cc_product_category', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testInnerJoinWithOneToManyDropsMainObjectsWithoutChildren()
    {
        $products = ContractProductQuery::create()->innerJoinWithContractProductCategory()->orderById()->find();

        $this->assertSame(['P1', 'P2'], $products->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testJoinWithOneToManyCombinedWithLimitThrows()
    {
        $this->expectException(\Propel\Runtime\Exception\LogicException::class);
        $this->expectExceptionMessage('Cannot use limit() in conjunction with with() on a one-to-many relationship');
        ContractProductQuery::create()->leftJoinWithContractProductCategory()->orderById()->limit(1)->find();
    }

    /**
     * @return void
     */
    public function testNestedHydratingJoinThroughUseQuery()
    {
        $con = $this->getDebugConnection();

        $products = ContractProductQuery::create()
            ->joinWithContractProductCategory()
            ->useContractProductCategoryQuery()
                ->joinWithContractCategory()
            ->endUse()
            ->orderById()
            ->find($con);
        $queryCount = $con->getQueryCount();

        $this->assertSame(['P1', 'P2'], $products->getColumnValues('Name'));
        $names = [];
        foreach ($products as $product) {
            foreach ($product->getContractProductCategories() as $link) {
                $names[$product->getName()][] = $link->getContractCategory()->getName();
            }
        }
        $this->assertSame(['P1' => ['C1', 'C2'], 'P2' => ['C1']], $names);
        $this->assertSame($queryCount, $con->getQueryCount(), 'two levels hydrated by one query');
    }
}
