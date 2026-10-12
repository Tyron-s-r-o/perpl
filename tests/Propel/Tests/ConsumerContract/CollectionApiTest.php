<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractProduct;
use ConsumerContract\ContractProductQuery;
use Propel\Runtime\Collection\ArrayCollection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Map\TableMap;

/**
 * Pins the ObjectCollection API.
 */
class CollectionApiTest extends ConsumerContractTestCase
{
    /**
     * @return \Propel\Runtime\Collection\ObjectCollection
     */
    private function collection(): ObjectCollection
    {
        $this->createCatalog();

        return ContractProductQuery::create()->orderById()->find();
    }

    /**
     * @return void
     */
    public function testGetDataReturnsPlainListOfModels()
    {
        $data = $this->collection()->getData();

        $this->assertIsArray($data);
        $this->assertSame([0, 1, 2, 3], array_keys($data));
        $this->assertContainsOnlyInstancesOf(ContractProduct::class, $data);
    }

    /**
     * @return void
     */
    public function testGetFirstAndGetLast()
    {
        $collection = $this->collection();

        $this->assertSame('P1', $collection->getFirst()->getName());
        $this->assertSame('P4', $collection->getLast()->getName());
        $this->assertSame($collection->getData()[0], $collection->getFirst());
    }

    /**
     * @return void
     */
    public function testGetFirstAndGetLastOfEmptyCollectionAreNull()
    {
        $collection = ContractProductQuery::create()->find();

        $this->assertNull($collection->getFirst());
        $this->assertNull($collection->getLast());
    }

    /**
     * @return void
     */
    public function testIsEmptyAndCount()
    {
        $collection = $this->collection();

        $this->assertFalse($collection->isEmpty());
        $this->assertSame(4, $collection->count());
        $this->assertCount(4, $collection);
        $this->assertTrue(ContractProductQuery::create()->filterByName('nope')->find()->isEmpty());
    }

    /**
     * @return void
     */
    public function testIteration()
    {
        $names = [];
        $keys = [];
        foreach ($this->collection() as $key => $product) {
            $keys[] = $key;
            $names[] = $product->getName();
        }

        $this->assertSame([0, 1, 2, 3], $keys);
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $names);
    }

    /**
     * @return void
     */
    public function testArrayAccess()
    {
        $collection = $this->collection();

        $this->assertSame('P2', $collection[1]->getName());
        $this->assertTrue(isset($collection[3]));
        $this->assertFalse(isset($collection[4]));
        $this->assertSame($collection->getFirst(), $collection[0]);
    }

    /**
     * @return void
     */
    public function testArrayAccessWriteAndAppend()
    {
        $collection = $this->collection();
        $new = new ContractProduct();
        $new->setName('extra');

        $collection[] = $new;
        $this->assertCount(5, $collection);
        $this->assertSame($new, $collection->getLast());

        unset($collection[0]);
        $this->assertCount(4, $collection);
        $this->assertFalse(isset($collection[0]));
    }

    /**
     * @return void
     */
    public function testToArrayKeepsIndexAndReturnsRowArrays()
    {
        $array = $this->collection()->toArray();

        $this->assertSame([0, 1, 2, 3], array_keys($array));
        $this->assertIsArray($array[0]);
        $this->assertSame('P1', $array[0]['Name']);
        $this->assertSame(['Id', 'Name', 'Type', 'Price', 'IsActive', 'Qty', 'Description', 'Note', 'BigNumber', 'ReleasedOn', 'OpensAt', 'Rating', 'IdManufacturer', 'CreatedAt', 'UpdatedAt'], array_keys($array[0]));
    }

    /**
     * @return void
     */
    public function testToArrayWithKeyColumnAndFieldNameKeyType()
    {
        $array = $this->collection()->toArray('Name', false, TableMap::TYPE_FIELDNAME);

        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_keys($array));
        $this->assertSame(1, $array['P1']['id']);
        $this->assertArrayHasKey('is_active', $array['P1']);
    }

    /**
     * @return void
     */
    public function testToArrayWithColNameKeys()
    {
        $array = $this->collection()->toArray(null, true, TableMap::TYPE_COLNAME);

        $this->assertSame('P1', $array['ContractProduct_0']['cc_product.name']);
        $this->assertSame(['ContractProduct_0', 'ContractProduct_1', 'ContractProduct_2', 'ContractProduct_3'], array_keys($array));
    }

    /**
     * @return void
     */
    public function testGetColumnValues()
    {
        $collection = $this->collection();

        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $collection->getColumnValues('Name'));
        $this->assertSame([3, 10, 7, 0], $collection->getColumnValues('Qty'));
        $this->assertSame([1, 2, 3, 4], $collection->getColumnValues('Id'));
    }

    /**
     * @return void
     */
    public function testToKeyIndex()
    {
        $index = $this->collection()->toKeyIndex('Name');

        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_keys($index));
        $this->assertSame(10, $index['P2']->getQty());
        $this->assertContainsOnlyInstancesOf(ContractProduct::class, $index);
    }

    /**
     * @return void
     */
    public function testToKeyIndexWithoutKeyUsesPrimaryKey()
    {
        $index = $this->collection()->toKeyIndex();

        $this->assertSame([1, 2, 3, 4], array_keys($index));
    }

    /**
     * @return void
     */
    public function testGetPrimaryKeys()
    {
        $keys = $this->collection()->getPrimaryKeys();

        $this->assertSame([1, 2, 3, 4], array_values($keys));
        $this->assertSame(['ContractProduct_0', 'ContractProduct_1', 'ContractProduct_2', 'ContractProduct_3'], array_keys($keys));
    }

    /**
     * @return void
     */
    public function testGetPrimaryKeysWithoutKeyPrefix()
    {
        $this->assertSame([0, 1, 2, 3], array_keys($this->collection()->getPrimaryKeys(false)));
    }

    /**
     * @return void
     */
    public function testContains()
    {
        $collection = $this->collection();

        $this->assertTrue($collection->contains($collection->getFirst()));
        $this->assertFalse($collection->contains(new ContractProduct()));
    }

    /**
     * @return void
     */
    public function testContainsMatchesByPrimaryKeyNotByInstance()
    {
        $collection = $this->collection();
        $this->clearPools();
        $other = ContractProductQuery::create()->findPk($collection->getFirst()->getId());
        $this->assertNotSame($collection->getFirst(), $other);

        $this->assertTrue($collection->contains($other));
    }

    /**
     * @return void
     */
    public function testGetArrayCopy()
    {
        $collection = $this->collection();

        $copy = $collection->getArrayCopy();

        $this->assertSame($collection->getData(), $copy);
        $copy[] = 'x';
        $this->assertCount(4, $collection, 'it is a copy');
    }

    /**
     * @return void
     */
    public function testCollectionIsTheGeneratedSubclass()
    {
        $collection = $this->collection();

        $this->assertInstanceOf(ObjectCollection::class, $collection);
        $this->assertSame('ContractProduct', $collection->getModel());
        $this->assertSame('\ConsumerContract\ContractProduct', $collection->getFullyQualifiedModel());
    }

    /**
     * @return void
     */
    public function testSelectCollectionIsArrayCollection()
    {
        $this->createCatalog();

        $collection = ContractProductQuery::create()->select(['Id', 'Name'])->orderById()->find();

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertNotInstanceOf(ObjectCollection::class, $collection);
        $this->assertSame(['Id' => 1, 'Name' => 'P1'], $collection->getFirst());
        $this->assertSame(['Id' => 4, 'Name' => 'P4'], $collection->getLast());
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_column($collection->getData(), 'Name'));
        $this->assertCount(4, $collection->toArray());
        $this->expectException(\Propel\Runtime\Exception\BadMethodCallException::class);
        $collection->getColumnValues('Name'); // only available on ObjectCollection
    }

    /**
     * @return void
     */
    public function testCollectionSaveAndDelete()
    {
        $collection = $this->collection();
        foreach ($collection as $product) {
            $product->setQty(100);
        }

        $collection->save();

        $this->clearPools();
        $this->assertSame([100, 100, 100, 100], ContractProductQuery::create()->orderById()->find()->getColumnValues('Qty'));

        $collection->delete();
        $this->assertSame(0, ContractProductQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testCollectionOfLeftJoinedRowsWithoutRelatedObjectsHasOneObjectPerRow()
    {
        $this->createCatalog();

        $collection = ContractProductQuery::create()->leftJoinContractProductCategory()->orderById()->find();

        $this->assertCount(5, $collection);
    }
}
