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
use Propel\Runtime\Map\TableMap;

/**
 * Pins what survives a serialize()/unserialize() round trip (the consumer caches models and collections).
 */
class SerializationTest extends ConsumerContractTestCase
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
     * @return void
     */
    public function testRoundTripKeepsColumnValues()
    {
        $product = ContractProductQuery::create()->findOneByName('P1');

        $restored = unserialize(serialize($product));

        $this->assertInstanceOf(ContractProduct::class, $restored);
        $this->assertNotSame($product, $restored);
        $this->assertSame($product->toArray(TableMap::TYPE_FIELDNAME), $restored->toArray(TableMap::TYPE_FIELDNAME));
        $this->assertSame('tyre', $restored->getType());
        $this->assertSame('10.5', $restored->getPrice());
    }

    /**
     * @return void
     */
    public function testRoundTripKeepsIsNewFalse()
    {
        $restored = unserialize(serialize(ContractProductQuery::create()->findOneByName('P1')));

        $this->assertFalse($restored->isNew());
        $this->assertFalse($restored->isModified());
    }

    /**
     * @return void
     */
    public function testRoundTripKeepsIsNewTrueForUnsavedObject()
    {
        $product = new ContractProduct();
        $product->setName('unsaved');

        $restored = unserialize(serialize($product));

        $this->assertTrue($restored->isNew());
        $this->assertSame('unsaved', $restored->getName());
    }

    /**
     * @return void
     */
    public function testRoundTripKeepsModifiedColumns()
    {
        $product = ContractProductQuery::create()->findOneByName('P1');
        $product->setName('changed')->setQty(99);

        $restored = unserialize(serialize($product));

        $this->assertTrue($restored->isModified());
        $this->assertSame(['cc_product.name', 'cc_product.qty'], $restored->getModifiedColumns());
        $this->assertSame(1, $restored->save());
        $this->clearPools();
        $this->assertSame('changed', ContractProductQuery::create()->findPk($restored->getId())->getName());
    }

    /**
     * @return void
     */
    public function testRoundTripKeepsVirtualColumns()
    {
        $product = ContractProductQuery::create()->withColumn('ContractProduct.Qty * 2', 'double_qty')->findOneByName('P2');

        $restored = unserialize(serialize($product));

        $this->assertTrue($restored->hasVirtualColumn('double_qty'));
        $this->assertEquals(20, $restored->getVirtualColumn('double_qty'));
        $this->assertSame($product->getVirtualColumns(), $restored->getVirtualColumns());
    }

    /**
     * @return void
     */
    public function testRoundTripDoesNotCarryRelations()
    {
        $product = ContractProductQuery::create()->findOneByName('P1');
        $product->getContractManufacturer();
        $product->getContractProductCategories();

        $restored = unserialize(serialize($product));

        $this->assertNull((new \ReflectionProperty($restored, 'aContractManufacturer'))->getValue($restored));
        $this->assertNull((new \ReflectionProperty($restored, 'collContractProductCategories'))->getValue($restored));
        $this->assertSame($product->getIdManufacturer(), $restored->getIdManufacturer(), 'foreign key column survives');
        $this->clearPools();
        $this->assertSame('A', $restored->getContractManufacturer()->getName());
        $this->assertCount(2, $restored->getContractProductCategories());
    }

    /**
     * @return void
     */
    public function testRoundTripKeepsDateColumns()
    {
        $product = $this->createProduct('dates', ['ReleasedOn' => '2020-02-03', 'OpensAt' => '08:30:00']);
        $this->clearPools();
        $product = ContractProductQuery::create()->findPk($product->getId());

        $restored = unserialize(serialize($product));

        $this->assertSame('2020-02-03', $restored->getReleasedOn('Y-m-d'));
        $this->assertSame('08:30:00', $restored->getOpensAt('H:i:s'));
        $this->assertEquals($product->getCreatedAt(), $restored->getCreatedAt());
    }

    /**
     * @return void
     */
    public function testRoundTripOfSavedModelAllowsFurtherUpdate()
    {
        $restored = unserialize(serialize(ContractProductQuery::create()->findOneByName('P3')));

        $restored->setQty(1234);
        $affected = $restored->save();

        $this->assertSame(1, $affected);
        $this->clearPools();
        $this->assertSame(1234, ContractProductQuery::create()->findOneByName('P3')->getQty());
    }

    /**
     * @return void
     */
    public function testObjectCollectionRoundTrip()
    {
        $collection = ContractProductQuery::create()->orderById()->find();

        $restored = unserialize(serialize($collection));

        $this->assertInstanceOf(ObjectCollection::class, $restored);
        $this->assertInstanceOf(\ConsumerContract\Base\Collection\ContractProductCollection::class, $restored);
        $this->assertCount(4, $restored);
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $restored->getColumnValues('Name'));
        $this->assertContainsOnlyInstancesOf(ContractProduct::class, $restored->getData());
        $this->assertFalse($restored->getFirst()->isNew());
    }

    /**
     * @return void
     */
    public function testObjectCollectionRoundTripKeepsModelAndAllowsSaving()
    {
        $restored = unserialize(serialize(ContractProductQuery::create()->orderById()->find()));
        $restored->getFirst()->setQty(555);

        $restored->save();

        $this->clearPools();
        $this->assertSame(555, ContractProductQuery::create()->findOneByName('P1')->getQty());
        $this->assertSame('ContractProduct', $restored->getModel());
        $this->assertSame('\ConsumerContract\ContractProduct', $restored->getFullyQualifiedModel());
    }

    /**
     * @return void
     */
    public function testEmptyObjectCollectionRoundTrip()
    {
        $restored = unserialize(serialize(ContractProductQuery::create()->filterByName('nope')->find()));

        $this->assertInstanceOf(ObjectCollection::class, $restored);
        $this->assertTrue($restored->isEmpty());
    }

    /**
     * @return void
     */
    public function testSerializedCollectionOfModelsWithLoadedRelationsDropsTheRelations()
    {
        $collection = ContractProductQuery::create()->leftJoinWithContractProductCategory()->orderById()->find();
        $this->assertCount(2, $collection->getFirst()->getContractProductCategories());

        $restored = unserialize(serialize($collection));

        $this->assertNull((new \ReflectionProperty($restored->getFirst(), 'collContractProductCategories'))->getValue($restored->getFirst()));
    }

    /**
     * @return void
     */
    public function testArrayCollectionFromSelectRoundTrip()
    {
        $collection = ContractProductQuery::create()->select(['Id', 'Name'])->orderById()->find();

        $restored = unserialize(serialize($collection));

        $this->assertSame($collection->getData(), $restored->getData());
    }

    /**
     * Until 2.9.1 the generated __sleep() called clearAllReferences() on the object being serialized.
     *
     * @return void
     */
    public function testSerializingAModelLeavesLoadedRelationsOnTheOriginalObject()
    {
        $product = ContractProductQuery::create()->findOneByName('P1');
        $product->getContractManufacturer();
        $product->getContractProductCategories();
        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();

        serialize($product);

        $product->getContractManufacturer();
        $product->getContractProductCategories();
        $this->assertSame($before, $con->getQueryCount(), 'loaded relations are still cached on the object');
    }

    /**
     * @return void
     */
    public function testSerializedModelContainsColumnsAndStateOnly()
    {
        $product = ContractProductQuery::create()->findOneByName('P1');
        $product->getContractManufacturer();
        $product->getContractProductCategories();
        $product->getCreatedAt();

        $serialized = serialize($product);

        $this->assertStringStartsWith('O:32:"ConsumerContract\ContractProduct":19:{', $serialized);
        $propertyNames = array_map(fn (string $key) => substr($key, 3), array_keys($product->__serialize()));
        $this->assertSame(
            [
                'new', 'deleted', 'modifiedColumns', 'virtualColumns',
                'id', 'name', 'type', 'price', 'is_active', 'qty', 'description', 'note', 'big_number',
                'released_on', 'opens_at', 'rating', 'id_manufacturer', 'created_at', 'updated_at',
            ],
            $propertyNames,
        );
    }

    /**
     * Objects serialized by the former __sleep() carry all properties, including
     * the ones of relations. Those blobs sit in caches of the consumer.
     *
     * @return void
     */
    public function testUnserializeReadsFormatWithAllPropertiesAndIgnoresRelations()
    {
        $product = ContractProductQuery::create()->findOneByName('P1');
        $legacyBlob = $this->buildLegacyBlob($product, ['collContractProductCategoriesPartial' => false]);
        $this->assertStringContainsString('collContractProductCategoriesPartial', $legacyBlob);
        $this->assertStringContainsString('alreadyInSave', $legacyBlob);

        $restored = unserialize($legacyBlob);

        $this->assertInstanceOf(ContractProduct::class, $restored);
        $this->assertSame($product->toArray(), $restored->toArray());
        $this->assertFalse($restored->isNew());
        $this->assertCount(2, $restored->getContractProductCategories(), 'relation is loaded from the database');
    }

    /**
     * @return void
     */
    public function testModelSubclassPropertiesSurviveRoundTrip()
    {
        require_once __DIR__ . '/SerializableProductWithExtraState.php';
        $product = new SerializableProductWithExtraState();
        $product->setName('custom');
        $product->setExtraState('private value', 'protected value', 'public value');

        $restored = unserialize(serialize($product));

        $this->assertInstanceOf(SerializableProductWithExtraState::class, $restored);
        $this->assertSame('custom', $restored->getName());
        $this->assertSame(['private value', 'protected value', 'public value'], $restored->getExtraState());
    }
}
