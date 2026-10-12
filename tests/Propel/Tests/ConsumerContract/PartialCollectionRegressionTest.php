<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractProduct;
use ConsumerContract\ContractProductCategoryQuery;
use ConsumerContract\ContractProductQuery;
use Propel\Runtime\Map\TableMap;

/**
 * Regression tests for the fork patch that makes the generated `collXxxPartial` properties `?bool = null`
 * (instead of `bool = false`) and restores the caching semantics of Propel 2.0 beta4.
 */
class PartialCollectionRegressionTest extends ConsumerContractTestCase
{
    private const PARTIAL_PROPERTY = 'collContractProductCategoriesPartial';

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
    private function loadProduct(): ContractProduct
    {
        return ContractProductQuery::create()->findOneByName('P1');
    }

    /**
     * @param object $model
     * @param string $property
     *
     * @return mixed
     */
    private function readProperty(object $model, string $property): mixed
    {
        if ($property === self::PARTIAL_PROPERTY) {
            // the flags of all collections are kept in one property since the fork left 2.9.1
            $flags = (new \ReflectionProperty($model, 'partialFlagByCollection'))->getValue($model);

            return $flags[substr($property, 0, -strlen('Partial'))] ?? null;
        }

        return (new \ReflectionProperty($model, $property))->getValue($model);
    }

    /**
     * @return void
     */
    public function testPartialFlagOfFreshlyLoadedModelIsNullNotFalse()
    {
        $product = $this->loadProduct();

        $this->assertNull($this->readProperty($product, self::PARTIAL_PROPERTY));
        $this->assertNull($this->readProperty(new ContractProduct(), self::PARTIAL_PROPERTY));
    }

    /**
     * @return void
     */
    public function testPartialFlagIsFalseAfterFullLoadAndTrueAfterEagerJoinHelper()
    {
        $product = $this->loadProduct();
        $product->getContractProductCategories();
        $this->assertFalse($this->readProperty($product, self::PARTIAL_PROPERTY));

        $other = ContractProductQuery::create()->findOneByName('P2');
        $other->getContractProductCategoriesJoinContractCategory();
        $this->assertTrue($this->readProperty($other, self::PARTIAL_PROPERTY));
    }

    /**
     * @return void
     */
    public function testGetRelationAfterEagerJoinHelperQueriesAgainAndReturnsSameInstances()
    {
        $product = $this->loadProduct();
        $con = $this->getDebugConnection();

        $viaHelper = $product->getContractProductCategoriesJoinContractCategory(null, $con);
        $afterHelper = $con->getQueryCount();
        $viaGetter = $product->getContractProductCategories(null, $con);

        $this->assertSame($afterHelper + 1, $con->getQueryCount(), 'the collection filled by the helper is partial, so the getter queries again');
        $this->assertCount(2, $viaGetter);
        $this->assertSame($viaHelper->getData(), $viaGetter->getData(), 'instance pool hands out the very same objects');
        $this->assertFalse($this->readProperty($product, self::PARTIAL_PROPERTY));

        $this->assertSame($afterHelper + 1, $con->getQueryCount());
        $product->getContractProductCategories(null, $con);
        $this->assertSame($afterHelper + 1, $con->getQueryCount(), 'now the collection is complete and cached');
    }

    /**
     * @return void
     */
    public function testGetRelationAfterEagerJoinHelperKeepsJoinedRelatedObjects()
    {
        $product = $this->loadProduct();
        $product->getContractProductCategoriesJoinContractCategory();

        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();
        $names = array_map(fn ($link) => $link->getContractCategory()->getName(), $product->getContractProductCategories(null, $con)->getData());

        $this->assertSame(['C1', 'C2'], $names);
        $this->assertSame($before + 1, $con->getQueryCount(), 'only the query for the getter, categories come from the pooled link objects');
    }

    /**
     * @return void
     */
    public function testGetRelationAfterEagerJoinHelperWithoutPoolingReturnsFreshObjects()
    {
        \Propel\Runtime\Perpl::disableInstancePooling();
        $product = $this->loadProduct();

        $viaHelper = $product->getContractProductCategoriesJoinContractCategory();
        $viaGetter = $product->getContractProductCategories();

        $this->assertCount(2, $viaGetter);
        $this->assertNotSame($viaHelper->getFirst(), $viaGetter->getFirst());
    }

    /**
     * @return void
     */
    public function testGetRelationWithCriteriaOnFreshModelMakesCollectionPartial()
    {
        $product = $this->loadProduct();
        $criteria = ContractProductCategoryQuery::create()->filterByPriority(2);

        $filtered = $product->getContractProductCategories($criteria);

        $this->assertCount(1, $filtered);
        $this->assertTrue($this->readProperty($product, self::PARTIAL_PROPERTY));
        $this->assertCount(1, $this->readProperty($product, 'collContractProductCategories'));
        $this->assertCount(2, $product->getContractProductCategories(), 'the plain getter completes the collection');
    }

    /**
     * @return void
     */
    public function testGetRelationWithCriteriaAfterFullLoadDoesNotMakeCollectionPartial()
    {
        $product = $this->loadProduct();
        $product->getContractProductCategories();

        $product->getContractProductCategories(ContractProductCategoryQuery::create()->filterByPriority(2));

        $this->assertFalse($this->readProperty($product, self::PARTIAL_PROPERTY));
        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();
        $this->assertCount(2, $product->getContractProductCategories());
        $this->assertSame($before, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testCountOnPartialCollectionLoadsTheWholeCollection()
    {
        $product = $this->loadProduct();
        $product->getContractProductCategoriesJoinContractCategory();

        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();
        $count = $product->countContractProductCategories();

        $this->assertSame(2, $count);
        $this->assertSame($before + 1, $con->getQueryCount());
        $this->assertStringContainsString('SELECT cc_product_category.id', $con->getLastExecutedQuery(), 'it ran the full select, not COUNT(*)');
    }

    /**
     * @return void
     */
    public function testToArrayWithForeignObjectsContainsEagerLoadedChildren()
    {
        $product = $this->loadProduct();
        $product->getContractProductCategoriesJoinContractCategory();

        $array = $product->toArray(TableMap::TYPE_PHPNAME, true, [], true);

        $this->assertArrayHasKey('ContractProductCategories', $array);
        $this->assertCount(2, $array['ContractProductCategories']);
        $this->assertSame(['Id', 'IdProduct', 'IdCategory', 'Priority', 'ContractCategory'], array_keys($array['ContractProductCategories'][0]));
        $this->assertSame('C1', $array['ContractProductCategories'][0]['ContractCategory']['Name']);
        $this->assertSame(2, $array['ContractProductCategories'][1]['IdCategory']);
    }

    /**
     * @return void
     */
    public function testToArrayWithForeignObjectsOfModelWithoutLoadedChildrenHasNoChildrenKey()
    {
        $array = $this->loadProduct()->toArray(TableMap::TYPE_PHPNAME, true, [], true);

        $this->assertArrayNotHasKey('ContractProductCategories', $array);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function partialValueProvider(): array
    {
        return [
            'null (fork default)' => ['N;'],
            'false (old default)' => ['b:0;'],
            'true' => ['b:1;'],
        ];
    }

    /**
     * Blob of a loaded product in the format of version 2.9.1 and older, with the given value of the partial flag.
     *
     * @param string $serializedValue
     *
     * @return string
     */
    private function blobWithPartialFlag(string $serializedValue): string
    {
        $blob = $this->buildLegacyBlob($this->loadProduct(), [self::PARTIAL_PROPERTY => unserialize($serializedValue)]);
        $key = 's:' . (strlen(self::PARTIAL_PROPERTY) + 3) . ':"' . "\0*\0" . self::PARTIAL_PROPERTY . '";';
        $this->assertStringContainsString($key . $serializedValue, $blob);

        return $blob;
    }

    /**
     * @param string $serializedValue
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('partialValueProvider')]
    public function testBlobWithAnyPartialFlagValueUnserializesAndRelationAccessorWorks(string $serializedValue)
    {
        $blob = $this->blobWithPartialFlag($serializedValue);

        $restored = unserialize($blob);

        $this->assertInstanceOf(ContractProduct::class, $restored);
        $this->assertSame('P1', $restored->getName());
        $this->assertFalse($restored->isNew());
        $this->clearPools();
        $links = $restored->getContractProductCategories();
        $this->assertCount(2, $links);
        $this->assertSame(2, $restored->countContractProductCategories());
        $this->assertSame('A', $restored->getContractManufacturer()->getName());
    }

    /**
     * @return void
     */
    public function testBlobProducedAfterFullLoadDoesNotCarryTheFlagAndRestoresFine()
    {
        $product = $this->loadProduct();
        $product->getContractProductCategories();
        $blob = serialize($product);

        $this->assertStringNotContainsString(self::PARTIAL_PROPERTY, $blob, 'state of relations is not serialized');

        $restored = unserialize($blob);
        $this->clearPools();
        $this->assertCount(2, $restored->getContractProductCategories());
    }
}
