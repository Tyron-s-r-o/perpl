<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractAddressQuery;
use ConsumerContract\ContractOrder;
use ConsumerContract\ContractOrderQuery;
use ConsumerContract\ContractProduct;
use ConsumerContract\ContractProductCategory;
use ConsumerContract\ContractProductCategoryQuery;
use ConsumerContract\ContractProductQuery;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Pins the relation accessors generated on models.
 */
class RelationAccessorTest extends ConsumerContractTestCase
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
     * Number of queries executed by the callback.
     *
     * @param callable $callback
     *
     * @return int
     */
    private function queriesOf(callable $callback): int
    {
        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();
        $callback();

        return $con->getQueryCount() - $before;
    }

    /**
     * @return \ConsumerContract\ContractProduct
     */
    private function loadProduct(string $name = 'P1'): ContractProduct
    {
        return ContractProductQuery::create()->findOneByName($name);
    }

    /**
     * @return void
     */
    public function testManyToOneGetterQueriesOnceThenIsCached()
    {
        $product = $this->loadProduct();

        $manufacturer = null;
        $this->assertSame(1, $this->queriesOf(function () use ($product, &$manufacturer) {
            $manufacturer = $product->getContractManufacturer();
        }));
        $this->assertSame('A', $manufacturer->getName());
        $this->assertSame(0, $this->queriesOf(fn () => $product->getContractManufacturer()));
        $this->assertSame($manufacturer, $product->getContractManufacturer());
    }

    /**
     * @return void
     */
    public function testManyToOneGetterWithNullForeignKeyReturnsNullWithoutQuery()
    {
        $product = $this->loadProduct('P4');

        $this->assertSame(0, $this->queriesOf(fn () => $this->assertNull($product->getContractManufacturer())));
    }

    /**
     * @return void
     */
    public function testManyToOneSetterSetsForeignKeyAndModifiesColumn()
    {
        $product = $this->loadProduct('P4');

        $result = $product->setContractManufacturer($this->data['b']);

        $this->assertSame($product, $result);
        $this->assertSame($this->data['b']->getId(), $product->getIdManufacturer());
        $this->assertSame(['cc_product.id_manufacturer'], $product->getModifiedColumns());
        $this->assertSame($this->data['b'], $product->getContractManufacturer());
        $this->assertSame(0, $this->queriesOf(fn () => $product->getContractManufacturer()));
    }

    /**
     * @return void
     */
    public function testManyToOneSetterAddsChildToParentCollectionAndMarksItPartial()
    {
        $product = $this->loadProduct('P4');
        $manufacturer = $this->data['b'];
        $manufacturer->clearAllReferences();
        $reflection = new \ReflectionProperty($manufacturer, 'collContractProducts');
        $this->assertNull($reflection->getValue($manufacturer));

        $product->setContractManufacturer($manufacturer);

        $loaded = $reflection->getValue($manufacturer);
        $this->assertCount(1, $loaded, 'only the product that was set is in the parent collection');
        $this->assertSame($product, $loaded->getFirst());
        $partialFlags = (new \ReflectionProperty($manufacturer, 'partialFlagByCollection'))->getValue($manufacturer);
        $this->assertTrue($partialFlags['collContractProducts']);
        $this->assertSame(['P3'], $manufacturer->getContractProducts()->getColumnValues('Name'), 'a partial collection is replaced by a query; the unsaved P4 is lost from it');
    }

    /**
     * @return void
     */
    public function testManyToOneSetterNullClearsForeignKey()
    {
        $product = $this->loadProduct();

        $product->setContractManufacturer(null);

        $this->assertNull($product->getIdManufacturer());
        $this->assertNull($product->getContractManufacturer());
        $product->save();
        $this->clearPools();
        $this->assertNull($this->loadProduct()->getIdManufacturer());
    }

    /**
     * @return void
     */
    public function testSavingChildSavesNewParentSetViaSetter()
    {
        $product = new ContractProduct();
        $product->setName('with new parent');
        $manufacturer = new \ConsumerContract\ContractManufacturer();
        $manufacturer->setName('New');
        $product->setContractManufacturer($manufacturer);

        $affected = $product->save();

        $this->assertSame(2, $affected);
        $this->assertFalse($manufacturer->isNew());
        $this->assertSame($manufacturer->getId(), $product->getIdManufacturer());
    }

    /**
     * @return void
     */
    public function testOneToManyGetterQueriesOnceThenIsCached()
    {
        $product = $this->loadProduct();

        $links = null;
        $this->assertSame(1, $this->queriesOf(function () use ($product, &$links) {
            $links = $product->getContractProductCategories();
        }));
        $this->assertInstanceOf(\ConsumerContract\Base\Collection\ContractProductCategoryCollection::class, $links);
        $this->assertCount(2, $links);
        $this->assertSame(0, $this->queriesOf(fn () => $product->getContractProductCategories()));
        $this->assertSame($links, $product->getContractProductCategories());
    }

    /**
     * @return void
     */
    public function testOneToManyGetterOfNewObjectIsEmptyWithoutQuery()
    {
        $product = new ContractProduct();

        $this->assertSame(0, $this->queriesOf(fn () => $this->assertCount(0, $product->getContractProductCategories())));
    }

    /**
     * @return void
     */
    public function testOneToManyGetterWithCriteriaAlwaysQueriesAndDoesNotReplaceTheCache()
    {
        $product = $this->loadProduct();
        $all = $product->getContractProductCategories();
        $criteria = ContractProductCategoryQuery::create()->filterByPriority(2);

        $filtered = null;
        $this->assertSame(1, $this->queriesOf(function () use ($product, $criteria, &$filtered) {
            $filtered = $product->getContractProductCategories($criteria);
        }));
        $this->assertCount(1, $filtered);
        $this->assertSame(0, $this->queriesOf(fn () => $this->assertSame($all, $product->getContractProductCategories())));
        $this->assertCount(2, $product->getContractProductCategories());
    }

    /**
     * @return void
     */
    public function testOneToManyGetterWithCriteriaSql()
    {
        $product = $this->loadProduct();
        $con = $this->getDebugConnection();

        $product->getContractProductCategories(ContractProductCategoryQuery::create()->filterByPriority(2), $con);

        $this->assertStringEndsWith('FROM cc_product_category WHERE cc_product_category.priority=2 AND cc_product_category.id_product=' . $product->getId(), $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testCountRelationQueriesWhenNotLoadedAndUsesCollectionWhenLoaded()
    {
        $product = $this->loadProduct();
        $con = $this->getDebugConnection();

        $this->assertSame(2, $product->countContractProductCategories(null, false, $con));
        $this->assertSame('SELECT COUNT(*) FROM cc_product_category WHERE cc_product_category.id_product=' . $product->getId(), $con->getLastExecutedQuery());

        $product->getContractProductCategories();
        $this->assertSame(0, $this->queriesOf(fn () => $this->assertSame(2, $product->countContractProductCategories())));
    }

    /**
     * @return void
     */
    public function testCountRelationWithCriteriaAlwaysQueries()
    {
        $product = $this->loadProduct();
        $product->getContractProductCategories();
        $criteria = ContractProductCategoryQuery::create()->filterByPriority(2);

        $this->assertSame(1, $this->queriesOf(fn () => $this->assertSame(1, $product->countContractProductCategories($criteria))));
    }

    /**
     * @return void
     */
    public function testCountRelationOfNewObjectIsZeroWithoutQuery()
    {
        $this->assertSame(0, $this->queriesOf(fn () => $this->assertSame(0, (new ContractProduct())->countContractProductCategories())));
    }

    /**
     * @return void
     */
    public function testAddChildSetsBackReferenceAndSaveInsertsIt()
    {
        $product = $this->loadProduct('P3');
        $link = new ContractProductCategory();
        $link->setIdCategory($this->data['c1']->getId());

        $result = $product->addContractProductCategory($link);

        $this->assertSame($product, $result);
        $this->assertSame($product, $link->getContractProduct());
        $this->assertSame($product->getId(), $link->getIdProduct());
        $this->assertCount(1, $product->getContractProductCategories());

        $affected = $product->save();

        $this->assertSame(1, $affected);
        $this->assertFalse($link->isNew());
        $this->assertSame(4, ContractProductCategoryQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testAddChildToNewParentIsSavedWithParent()
    {
        $product = new ContractProduct();
        $product->setName('new');
        $link = new ContractProductCategory();
        $link->setIdCategory($this->data['c1']->getId());
        $product->addContractProductCategory($link);

        $affected = $product->save();

        $this->assertSame(2, $affected);
        $this->assertSame($product->getId(), $link->getIdProduct());
    }

    /**
     * @return void
     */
    public function testRemoveChildSchedulesDeletionAndSaveDeletesRow()
    {
        $product = $this->loadProduct();
        $links = $product->getContractProductCategories();
        $first = $links->getFirst();

        $result = $product->removeContractProductCategory($first);

        $this->assertSame($product, $result);
        $this->assertCount(1, $product->getContractProductCategories());
        $this->assertSame(3, ContractProductCategoryQuery::create()->count(), 'nothing deleted before save');

        $product->save();

        $this->assertSame(2, ContractProductCategoryQuery::create()->count());
        $this->assertNull(ContractProductCategoryQuery::create()->findPk($first->getId()));
    }

    /**
     * @return void
     */
    public function testSetChildrenCollectionAndSaveInsertsNewAndDeletesMissing()
    {
        $product = $this->loadProduct();
        $existing = $product->getContractProductCategories();
        $keep = $existing->getFirst();
        $dropId = $existing->getLast()->getId();
        $new = new ContractProductCategory();
        $new->setIdCategory($this->data['c2']->getId());
        $new->setPriority(9);

        $replacement = new \ConsumerContract\Base\Collection\ContractProductCategoryCollection();
        $replacement->setModel('\ConsumerContract\ContractProductCategory');
        $replacement->append($keep);
        $replacement->append($new);
        $result = $product->setContractProductCategories($replacement);

        $this->assertSame($product, $result);
        $con = $this->getDebugConnection();
        $affected = $product->save($con);

        $this->assertSame(1, $affected, 'only the new child is counted');
        $this->assertNull(ContractProductCategoryQuery::create()->findPk($dropId));
        $this->assertSame(
            [1, 9],
            ContractProductCategoryQuery::create()->filterByIdProduct($product->getId())->orderByPriority()->find()->getColumnValues('Priority'),
        );
        $this->assertSame(3, ContractProductCategoryQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testSetEmptyChildrenCollectionDeletesAllChildrenOnSave()
    {
        $product = $this->loadProduct();
        $product->getContractProductCategories();

        $product->setContractProductCategories(new \ConsumerContract\Base\Collection\ContractProductCategoryCollection());
        $product->save();

        $this->assertSame(1, ContractProductCategoryQuery::create()->count());
        $this->assertSame(0, ContractProductCategoryQuery::create()->filterByIdProduct($product->getId())->count());
    }

    /**
     * @return void
     */
    public function testEagerJoinHelperLoadsRelatedObjectsOfChildren()
    {
        $product = $this->loadProduct();
        $con = $this->getDebugConnection();

        $links = $product->getContractProductCategoriesJoinContractCategory(null, $con);
        $queryCount = $con->getQueryCount();

        $this->assertCount(2, $links);
        $this->assertStringContainsString('LEFT JOIN cc_category ON (cc_product_category.id_category=cc_category.id)', $con->getLastExecutedQuery());
        $this->assertSame(['C1', 'C2'], array_map(fn ($link) => $link->getContractCategory()->getName(), $links->getData()));
        $this->assertSame($queryCount, $con->getQueryCount(), 'categories were loaded by the join');
    }

    /**
     * @return void
     */
    public function testEagerJoinHelperWithCriteriaAndJoinType()
    {
        $product = $this->loadProduct();
        $criteria = ContractProductCategoryQuery::create()->filterByPriority(2);
        $con = $this->getDebugConnection();

        $links = $product->getContractProductCategoriesJoinContractCategory($criteria, $con, \Propel\Runtime\ActiveQuery\Criteria::INNER_JOIN);

        $this->assertCount(1, $links);
        $this->assertStringContainsString('INNER JOIN cc_category', $con->getLastExecutedQuery());
        $this->assertStringContainsString('cc_product_category.priority=2', $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testEagerJoinHelperIsAvailableOnEveryOneToManyRelation()
    {
        $this->assertTrue(method_exists(ContractProduct::class, 'getContractOrderProductsJoinContractOrder'));
        $this->assertTrue(method_exists(\ConsumerContract\ContractManufacturer::class, 'getContractProductsJoinContractManufacturer') === false, 'no helper for the relation to itself');
        $this->assertTrue(method_exists(\ConsumerContract\ContractCategory::class, 'getContractProductCategoriesJoinContractProduct'));
    }

    /**
     * @return void
     */
    public function testTwoForeignKeysToSameTableHaveSeparateAccessors()
    {
        $billing = $this->createAddress('Billing');
        $delivery = $this->createAddress('Delivery');
        $order = new ContractOrder();
        $order->setNumber('O1');

        $order->setBillingAddress($billing);
        $order->setContractAddressRelatedByIdDeliveryAddress($delivery);
        $order->save();
        $this->clearPools();

        $reloaded = ContractOrderQuery::create()->findOneByNumber('O1');
        $this->assertSame($billing->getId(), $reloaded->getIdBillingAddress());
        $this->assertSame($delivery->getId(), $reloaded->getIdDeliveryAddress());
        $this->assertSame('Billing', $reloaded->getBillingAddress()->getCity());
        $this->assertSame('Delivery', $reloaded->getContractAddressRelatedByIdDeliveryAddress()->getCity());
    }

    /**
     * @return void
     */
    public function testReverseAccessorsOfTwoForeignKeysToSameTable()
    {
        $billing = $this->createAddress('Billing');
        $delivery = $this->createAddress('Delivery');
        $order = $this->createOrder('O1');
        $order->setBillingAddress($billing)->setContractAddressRelatedByIdDeliveryAddress($delivery)->save();
        $this->clearPools();

        $address = ContractAddressQuery::create()->findPk($billing->getId());
        $this->assertCount(1, $address->getBillingOrders());
        $this->assertSame(0, $address->countContractOrdersRelatedByIdDeliveryAddress());
        $address = ContractAddressQuery::create()->findPk($delivery->getId());
        $this->assertCount(0, $address->getBillingOrders());
        $this->assertCount(1, $address->getContractOrdersRelatedByIdDeliveryAddress());
        $this->assertSame('O1', $address->getContractOrdersRelatedByIdDeliveryAddress()->getFirst()->getNumber());
    }

    /**
     * @return void
     */
    public function testClearAllReferencesDropsLoadedRelationsButKeepsColumnValues()
    {
        $product = $this->loadProduct();
        $product->getContractManufacturer();
        $product->getContractProductCategories();

        $product->clearAllReferences();

        $this->assertSame('P1', $product->getName());
        $this->assertSame(0, $this->queriesOf(fn () => $product->getContractManufacturer()), 'served from the instance pool');
        $this->assertSame(1, $this->queriesOf(fn () => $product->getContractProductCategories()));
    }

    /**
     * @return void
     */
    public function testClearResetsColumnsAndRelationsAndMarksObjectNew()
    {
        $product = $this->loadProduct();
        $product->getContractProductCategories();

        $product->clear();

        $this->assertNull($product->getId());
        $this->assertNull($product->getName());
        $this->assertSame('other', $product->getType(), 'default values are applied');
        $this->assertSame(0, $product->getQty());
        $this->assertFalse($product->isModified());
        $this->assertTrue($product->isNew(), 'clear() makes the object new');
        $this->assertSame(0, $product->countContractProductCategories());
    }

    /**
     * @return void
     */
    public function testClearOfOneRelationCollectionWithClearMethod()
    {
        $product = $this->loadProduct();
        $product->getContractProductCategories();

        $product->clearContractProductCategories();

        $this->assertSame(1, $this->queriesOf(fn () => $product->getContractProductCategories()));
    }

    /**
     * @return void
     */
    public function testDeleteParentEmulatesOnDeleteCascadeForChildren()
    {
        $this->data['p1']->delete();

        $this->assertSame(1, ContractProductCategoryQuery::create()->count(), 'the two links of P1 are gone, the one of P2 stays');
    }

    /**
     * @return void
     */
    public function testDeleteParentEmulatesOnDeleteSetNull()
    {
        $this->data['a']->delete();
        $this->clearPools();

        $this->assertSame(1, ContractProductQuery::create()->filterByIdManufacturer(null, \Propel\Runtime\ActiveQuery\Criteria::ISNOTNULL)->count());
        $this->assertNull($this->loadProduct('P1')->getIdManufacturer());
    }

    /**
     * @return void
     */
    public function testQueryDeleteOfParentEmulatesOnDeleteCascade()
    {
        ContractProductQuery::create()->filterByName('P1')->delete();

        $this->assertSame(1, ContractProductCategoryQuery::create()->count());
    }
}
