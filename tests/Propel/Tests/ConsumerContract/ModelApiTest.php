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
 * Pins the generated model (active record) API.
 */
class ModelApiTest extends ConsumerContractTestCase
{
    private const FIELD_NAMES = ['id', 'name', 'type', 'price', 'is_active', 'qty', 'description', 'note', 'big_number', 'released_on', 'opens_at', 'rating', 'id_manufacturer', 'created_at', 'updated_at'];

    private const PHP_NAMES = ['Id', 'Name', 'Type', 'Price', 'IsActive', 'Qty', 'Description', 'Note', 'BigNumber', 'ReleasedOn', 'OpensAt', 'Rating', 'IdManufacturer', 'CreatedAt', 'UpdatedAt'];

    /**
     * @return \ConsumerContract\ContractProduct
     */
    private function loadFirstProduct(): ContractProduct
    {
        $this->createCatalog();

        return ContractProductQuery::create()->findOneByName('P1');
    }

    /**
     * @return void
     */
    public function testSaveInsertsNewObject()
    {
        $product = new ContractProduct();
        $product->setName('x');
        $this->assertTrue($product->isNew());
        $this->assertNull($product->getId());

        $affected = $product->save();

        $this->assertSame(1, $affected);
        $this->assertFalse($product->isNew());
        $this->assertSame(1, $product->getId());
        $this->assertSame(1, $product->getPrimaryKey());
        $this->assertSame(1, ContractProductQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testInsertSql()
    {
        $con = $this->getDebugConnection();
        $product = new ContractProduct();
        $product->setName('x');

        $product->save($con);

        $this->assertStringStartsWith("INSERT INTO cc_product (id, name, created_at, updated_at) VALUES (NULL, 'x', '", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testSaveUpdatesExistingObject()
    {
        $product = $this->loadFirstProduct();
        $con = $this->getDebugConnection();

        $product->setName('changed');
        $affected = $product->save($con);

        $this->assertSame(1, $affected);
        $this->assertStringStartsWith("UPDATE cc_product SET name='changed'", $con->getLastExecutedQuery());
        $this->assertStringContainsString('WHERE cc_product.id=' . $product->getId(), $con->getLastExecutedQuery());
        $this->clearPools();
        $this->assertSame('changed', ContractProductQuery::create()->findPk($product->getId())->getName());
    }

    /**
     * @return void
     */
    public function testSaveWithoutChangesIssuesNoQueryAndReturnsZero()
    {
        $product = $this->loadFirstProduct();
        $con = $this->getDebugConnection();
        $queryCount = $con->getQueryCount();

        $affected = $product->save($con);

        $this->assertSame(0, $affected);
        $this->assertSame($queryCount, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testSettingSameValueDoesNotMarkModified()
    {
        $product = $this->loadFirstProduct();

        $product->setName('P1');
        $product->setQty('3');

        $this->assertFalse($product->isModified());
        $this->assertSame([], $product->getModifiedColumns());
    }

    /**
     * @return void
     */
    public function testModifiedStateTracking()
    {
        $product = $this->loadFirstProduct();
        $this->assertFalse($product->isModified());

        $product->setName('other');
        $product->setQty(5);

        $this->assertTrue($product->isModified());
        $this->assertTrue($product->isColumnModified(\ConsumerContract\Map\ContractProductTableMap::COL_NAME));
        $this->assertFalse($product->isColumnModified(\ConsumerContract\Map\ContractProductTableMap::COL_PRICE));
        $this->assertSame(['cc_product.name', 'cc_product.qty'], $product->getModifiedColumns());

        $product->resetModified();

        $this->assertFalse($product->isModified());
        $this->assertSame([], $product->getModifiedColumns());
        $this->assertSame('other', $product->getName(), 'resetModified keeps the values');
    }

    /**
     * @return void
     */
    public function testResetModifiedByColumn()
    {
        $product = $this->loadFirstProduct();
        $product->setName('other');
        $product->setQty(5);

        $product->resetModified(\ConsumerContract\Map\ContractProductTableMap::COL_NAME);

        $this->assertSame(['cc_product.qty'], $product->getModifiedColumns());
    }

    /**
     * @return void
     */
    public function testNewObjectHasModifiedColumnsOnlyForSetValues()
    {
        $product = new ContractProduct();
        $this->assertFalse($product->isModified());

        $product->setName('x');

        $this->assertSame(['cc_product.name'], $product->getModifiedColumns());
    }

    /**
     * @return void
     */
    public function testSetNewTrueMakesSaveInsertAgain()
    {
        $product = $this->loadFirstProduct();
        $oldId = $product->getId();

        $product->setNew(true);
        $product->setId(null);
        $product->setName('P1 again');
        $product->save();

        $this->assertNotSame($oldId, $product->getId());
        $this->assertSame(5, ContractProductQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testSetNewTrueInsertsOnlyModifiedColumns()
    {
        $product = $this->loadFirstProduct();

        $product->setNew(true);
        $product->setId(null);

        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        $product->save(); // name is not modified, so it is not part of the INSERT
    }

    /**
     * @return void
     */
    public function testSetNewFalseOnNewObject()
    {
        $product = new ContractProduct();
        $product->setNew(false);

        $this->assertFalse($product->isNew());
    }

    /**
     * @return void
     */
    public function testCopyCreatesNewObjectWithoutPrimaryKey()
    {
        $product = $this->loadFirstProduct();

        $copy = $product->copy();

        $this->assertNotSame($product, $copy);
        $this->assertTrue($copy->isNew());
        $this->assertNull($copy->getId());
        $this->assertSame('P1', $copy->getName());
        $this->assertSame('tyre', $copy->getType());
        $this->assertSame($product->getIdManufacturer(), $copy->getIdManufacturer());
        $this->assertSame(
            ['cc_product.name', 'cc_product.type', 'cc_product.price', 'cc_product.qty', 'cc_product.id_manufacturer', 'cc_product.created_at', 'cc_product.updated_at'],
            $copy->getModifiedColumns(),
        );
        $copy->save();
        $this->assertSame(5, ContractProductQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testDeepCopyCopiesLoadedChildren()
    {
        $product = $this->loadFirstProduct();
        $this->assertCount(2, $product->getContractProductCategories());

        $copy = $product->copy(true);
        $copy->save();

        $this->assertCount(2, $copy->getContractProductCategories());
        $this->assertSame(5, \ConsumerContract\ContractProductCategoryQuery::create()->count());
        $this->assertSame(2, \ConsumerContract\ContractProductCategoryQuery::create()->filterByIdProduct($copy->getId())->count());
    }

    /**
     * @return void
     */
    public function testShallowCopyDoesNotCopyChildren()
    {
        $product = $this->loadFirstProduct();
        $product->getContractProductCategories();

        $copy = $product->copy();
        $copy->save();

        $this->assertSame(3, \ConsumerContract\ContractProductCategoryQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testReloadDiscardsUnsavedChanges()
    {
        $product = $this->loadFirstProduct();
        $product->setName('unsaved');
        $this->assertTrue($product->isModified());

        $product->reload();

        $this->assertSame('P1', $product->getName());
        $this->assertFalse($product->isModified());
    }

    /**
     * @return void
     */
    public function testReloadPicksUpChangesMadeElsewhere()
    {
        $product = $this->loadFirstProduct();
        $this->getDebugConnection()->exec('UPDATE cc_product SET name = \'external\' WHERE id = ' . $product->getId());

        $product->reload();

        $this->assertSame('external', $product->getName());
    }

    /**
     * @return void
     */
    public function testReloadOfNewObjectThrows()
    {
        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        (new ContractProduct())->reload();
    }

    /**
     * @return void
     */
    public function testToArrayFieldNameKeysAndValues()
    {
        $product = $this->loadFirstProduct();

        $array = $product->toArray(TableMap::TYPE_FIELDNAME);

        $this->assertSame(self::FIELD_NAMES, array_keys($array));
        $this->assertSame(1, $array['id']);
        $this->assertSame('tyre', $array['type']);
        $this->assertSame('10.5', $array['price']);
        $this->assertTrue($array['is_active']);
        $this->assertSame(3, $array['qty']);
        $this->assertNull($array['description']);
        $this->assertIsString($array['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(\.\d+)?$/', $array['created_at']);
    }

    /**
     * @return void
     */
    public function testToArrayPhpNameKeys()
    {
        $product = $this->loadFirstProduct();

        $this->assertSame(self::PHP_NAMES, array_keys($product->toArray(TableMap::TYPE_PHPNAME)));
        $this->assertSame(self::PHP_NAMES, array_keys($product->toArray()), 'TYPE_PHPNAME is the default');
    }

    /**
     * @return void
     */
    public function testToArrayColNameKeys()
    {
        $product = $this->loadFirstProduct();

        $this->assertSame(
            array_map(fn ($f) => 'cc_product.' . $f, self::FIELD_NAMES),
            array_keys($product->toArray(TableMap::TYPE_COLNAME)),
        );
    }

    /**
     * @return void
     */
    public function testToArrayCamelNameKeys()
    {
        $product = $this->loadFirstProduct();

        $this->assertSame(
            ['id', 'name', 'type', 'price', 'isActive', 'qty', 'description', 'note', 'bigNumber', 'releasedOn', 'opensAt', 'rating', 'idManufacturer', 'createdAt', 'updatedAt'],
            array_keys($product->toArray(TableMap::TYPE_CAMELNAME)),
        );
    }

    /**
     * @return void
     */
    public function testToArrayIncludesVirtualColumns()
    {
        $this->createCatalog();
        $product = ContractProductQuery::create()->withColumn('ContractProduct.Qty * 2', 'double_qty')->findOneByName('P1');

        $array = $product->toArray(TableMap::TYPE_FIELDNAME);

        $this->assertSame(array_merge(self::FIELD_NAMES, ['double_qty']), array_keys($array));
        $this->assertEquals(6, $array['double_qty']);
    }

    /**
     * @return void
     */
    public function testToArrayWithForeignObjectsContainsOnlyLoadedRelations()
    {
        $product = $this->loadFirstProduct();
        $this->assertSame(self::PHP_NAMES, array_keys($product->toArray(TableMap::TYPE_PHPNAME, false, [], true)));

        $product->getContractManufacturer();
        $array = $product->toArray(TableMap::TYPE_PHPNAME, false, [], true);
        $this->assertSame(array_merge(self::PHP_NAMES, ['ContractManufacturer']), array_keys($array));
        $this->assertSame(['Id', 'Name', 'Code'], array_keys($array['ContractManufacturer']));
        $this->assertSame('A', $array['ContractManufacturer']['Name']);

        $product->getContractProductCategories();
        $array = $product->toArray(TableMap::TYPE_PHPNAME, false, [], true);
        $this->assertSame(array_merge(self::PHP_NAMES, ['ContractManufacturer', 'ContractProductCategories']), array_keys($array));
        $this->assertCount(2, $array['ContractProductCategories']);
        $this->assertSame(['Id', 'IdProduct', 'IdCategory', 'Priority'], array_keys($array['ContractProductCategories'][0]));
    }

    /**
     * @return void
     */
    public function testToArrayOfChildDoesNotRecurseIntoParent()
    {
        $product = $this->loadFirstProduct();
        $link = $product->getContractProductCategories()->getFirst();
        $link->getContractProduct();

        $array = $link->toArray(TableMap::TYPE_PHPNAME, true, [], true);

        $this->assertSame(['Id', 'IdProduct', 'IdCategory', 'Priority', 'ContractProduct'], array_keys($array));
        $this->assertSame('P1', $array['ContractProduct']['Name']);
        $this->assertSame(['*RECURSION*'], $array['ContractProduct']['ContractProductCategories'][0], 'the object being exported is replaced by a marker');
        $this->assertSame(2, $array['ContractProduct']['ContractProductCategories'][1]['Id'], 'its siblings are exported');
    }

    /**
     * @return void
     */
    public function testFromArrayWithPhpNameKeysIsDefault()
    {
        $product = new ContractProduct();

        $result = $product->fromArray(['Name' => 'fa', 'Qty' => 7, 'Type' => 'disc']);

        $this->assertSame($product, $result, 'fromArray() returns the model');
        $this->assertSame('fa', $product->getName());
        $this->assertSame(7, $product->getQty());
        $this->assertSame('disc', $product->getType());
        $this->assertSame(['cc_product.name', 'cc_product.type', 'cc_product.qty'], $product->getModifiedColumns());
    }

    /**
     * @return void
     */
    public function testFromArrayWithFieldNameKeys()
    {
        $product = new ContractProduct();

        $product->fromArray(['name' => 'fa', 'qty' => 7, 'is_active' => false], TableMap::TYPE_FIELDNAME);

        $this->assertSame('fa', $product->getName());
        $this->assertSame(7, $product->getQty());
        $this->assertFalse($product->getIsActive());
    }

    /**
     * @return void
     */
    public function testFromArrayWithColNameKeys()
    {
        $product = new ContractProduct();

        $product->fromArray(['cc_product.name' => 'fa'], TableMap::TYPE_COLNAME);

        $this->assertSame('fa', $product->getName());
    }

    /**
     * @return void
     */
    public function testFromArrayIgnoresUnknownKeys()
    {
        $product = new ContractProduct();

        $product->fromArray(['Name' => 'fa', 'Unknown' => 'x']);

        $this->assertSame('fa', $product->getName());
    }

    /**
     * @return void
     */
    public function testToArrayFromArrayRoundTrip()
    {
        $source = $this->loadFirstProduct();

        $target = new ContractProduct();
        $target->fromArray($source->toArray(TableMap::TYPE_FIELDNAME), TableMap::TYPE_FIELDNAME);

        $this->assertSame($source->toArray(TableMap::TYPE_FIELDNAME), $target->toArray(TableMap::TYPE_FIELDNAME));
        $this->assertTrue($target->isNew());
        $this->assertSame($source->getId(), $target->getId());
    }

    /**
     * @return void
     */
    public function testGetByNameAndSetByName()
    {
        $product = $this->loadFirstProduct();

        $this->assertSame('P1', $product->getByName('Name'));
        $this->assertSame('P1', $product->getByName('Name', TableMap::TYPE_PHPNAME));
        $this->assertSame('P1', $product->getByName('name', TableMap::TYPE_FIELDNAME));
        $this->assertSame('P1', $product->getByName('cc_product.name', TableMap::TYPE_COLNAME));

        $product->setByName('name', 'viaField', TableMap::TYPE_FIELDNAME);
        $this->assertSame('viaField', $product->getName());
        $product->setByName('Qty', 42, TableMap::TYPE_PHPNAME);
        $this->assertSame(42, $product->getQty());
        $product->setByName('Name', 'viaDefault');
        $this->assertSame('viaDefault', $product->getName());
    }

    /**
     * @return void
     */
    public function testSetByNameReturnsModel()
    {
        $product = new ContractProduct();

        $this->assertSame($product, $product->setByName('Name', 'x'));
    }

    /**
     * @return void
     */
    public function testVirtualColumnApi()
    {
        $product = new ContractProduct();
        $this->assertFalse($product->hasVirtualColumn('foo'));

        $result = $product->setVirtualColumn('foo', 5);

        $this->assertSame($product, $result);
        $this->assertTrue($product->hasVirtualColumn('foo'));
        $this->assertSame(5, $product->getVirtualColumn('foo'));
        $this->assertSame(['foo' => 5], $product->getVirtualColumns());
        $this->assertFalse($product->isModified(), 'virtual columns do not modify the model');
    }

    /**
     * @return void
     */
    public function testGetUnknownVirtualColumnThrows()
    {
        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        $this->expectExceptionMessage('Cannot get value of nonexistent virtual column `bar`');
        (new ContractProduct())->getVirtualColumn('bar');
    }

    /**
     * @return void
     */
    public function testExportToJson()
    {
        $product = $this->loadFirstProduct();

        $json = $product->exportTo('JSON');

        $decoded = json_decode($json, true);
        $this->assertSame(self::PHP_NAMES, array_keys($decoded));
        $this->assertSame('P1', $decoded['Name']);
        $this->assertSame('tyre', $decoded['Type']);
        $this->assertSame('10.5', $decoded['Price']);
    }

    /**
     * @return void
     */
    public function testExportToJsonWithKeyType()
    {
        $product = $this->loadFirstProduct();

        $decoded = json_decode($product->exportTo('JSON', true, TableMap::TYPE_FIELDNAME), true);

        $this->assertSame(self::FIELD_NAMES, array_keys($decoded));
    }

    /**
     * @return void
     */
    public function testFluentSettersReturnThis()
    {
        $product = new ContractProduct();

        $result = $product->setName('a')->setQty(1)->setPrice('2.50')->setType('disc')->setIsActive(false)->setDescription('d')->setReleasedOn('2020-01-01');

        $this->assertSame($product, $result);
        $this->assertSame('a', $product->getName());
    }

    /**
     * @return void
     */
    public function testGetPrimaryKeyOfNewAndSavedObject()
    {
        $product = new ContractProduct();
        $this->assertNull($product->getPrimaryKey());

        $product->setName('x')->save();

        $this->assertSame($product->getId(), $product->getPrimaryKey());
    }

    /**
     * @return void
     */
    public function testSetPrimaryKeySetsId()
    {
        $product = new ContractProduct();

        $product->setPrimaryKey(77);

        $this->assertSame(77, $product->getId());
    }

    /**
     * @return void
     */
    public function testInsertWithoutRequiredColumnFails()
    {
        $product = new ContractProduct();

        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        $product->save();
    }

    /**
     * @return void
     */
    public function testSaveWithinFailingTransactionLeavesObjectNew()
    {
        $product = new ContractProduct();
        try {
            $product->save();
            $this->fail('exception expected');
        } catch (\Propel\Runtime\Exception\PropelException) {
        }

        $this->assertTrue($product->isNew());
        $this->assertSame(0, ContractProductQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testDeleteMarksObjectAsDeleted()
    {
        $product = $this->loadFirstProduct();

        $this->assertFalse($product->isDeleted());
        $product->delete();
        $this->assertTrue($product->isDeleted());
    }

    /**
     * @return void
     */
    public function testToStringIsJson()
    {
        $product = $this->loadFirstProduct();

        $this->assertStringStartsWith('{"Id":1,"Name":"P1","Type":"tyre","Price":"10.5","IsActive":true,"Qty":3,', (string)$product);
        $this->assertSame($product->exportTo('JSON'), (string)$product);
    }
}
