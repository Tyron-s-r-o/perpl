<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\Map\ContractAddressTableMap;
use ConsumerContract\Map\ContractManufacturerTableMap;
use ConsumerContract\Map\ContractOrderTableMap;
use ConsumerContract\Map\ContractProductTableMap;
use Propel\Runtime\Map\ColumnMap;
use Propel\Runtime\Map\DatabaseMap;
use Propel\Runtime\Map\Exception\ColumnNotFoundException;
use Propel\Runtime\Map\Exception\RelationNotFoundException;
use Propel\Runtime\Map\RelationMap;
use Propel\Runtime\Map\TableMap;

/**
 * Pins the table map (metadata) API.
 */
class TableMapMetadataTest extends ConsumerContractTestCase
{
    /**
     * @return void
     */
    public function testGetTableMapReturnsTableMapInstance()
    {
        $tableMap = ContractProductTableMap::getTableMap();

        $this->assertInstanceOf(ContractProductTableMap::class, $tableMap);
        $this->assertInstanceOf(TableMap::class, $tableMap);
        $this->assertSame($tableMap, ContractProductTableMap::getTableMap(), 'same instance on every call');
        $this->assertInstanceOf(DatabaseMap::class, $tableMap->getDatabaseMap());
        $this->assertSame('contract', $tableMap->getDatabaseMap()->getName());
    }

    /**
     * @return void
     */
    public function testTableNamesAndClassConstants()
    {
        $tableMap = ContractProductTableMap::getTableMap();

        $this->assertSame('cc_product', $tableMap->getName());
        $this->assertSame('ContractProduct', $tableMap->getPhpName());
        $this->assertSame('\ConsumerContract\ContractProduct', $tableMap->getClassName());
        $this->assertSame('cc_product', ContractProductTableMap::TABLE_NAME);
        $this->assertSame('contract', ContractProductTableMap::DATABASE_NAME);
        $this->assertSame('ConsumerContract\ContractProduct', ContractProductTableMap::OM_CLASS);
    }

    /**
     * @return void
     */
    public function testColumnConstants()
    {
        $this->assertSame('cc_product.id', ContractProductTableMap::COL_ID);
        $this->assertSame('cc_product.name', ContractProductTableMap::COL_NAME);
        $this->assertSame('cc_product.type', ContractProductTableMap::COL_TYPE);
        $this->assertSame('cc_product.is_active', ContractProductTableMap::COL_IS_ACTIVE);
        $this->assertSame('cc_product.id_manufacturer', ContractProductTableMap::COL_ID_MANUFACTURER);
        $this->assertSame('cc_product.created_at', ContractProductTableMap::COL_CREATED_AT);
        $this->assertSame('cc_product.updated_at', ContractProductTableMap::COL_UPDATED_AT);
    }

    /**
     * @return void
     */
    public function testGetColumnByFieldName()
    {
        $column = ContractProductTableMap::getTableMap()->getColumn('name');

        $this->assertInstanceOf(ColumnMap::class, $column);
        $this->assertSame('name', $column->getName());
        $this->assertSame('Name', $column->getPhpName());
        $this->assertSame('VARCHAR', $column->getType());
        $this->assertFalse($column->isPrimaryKey());
        $this->assertSame(255, $column->getSize());
        $this->assertTrue($column->isNotNull());
        $this->assertSame('cc_product.name', $column->getFullyQualifiedName());
        $this->assertSame('cc_product', $column->getTableName());
    }

    /**
     * @return void
     */
    public function testGetColumnIsCaseInsensitive()
    {
        $tableMap = ContractProductTableMap::getTableMap();

        $this->assertSame($tableMap->getColumn('name'), $tableMap->getColumn('NAME'));
    }

    /**
     * @return void
     */
    public function testGetUnknownColumnThrows()
    {
        $this->expectException(ColumnNotFoundException::class);
        ContractProductTableMap::getTableMap()->getColumn('nope');
    }

    /**
     * @return void
     */
    public function testHasColumnAndGetColumnByPhpName()
    {
        $tableMap = ContractProductTableMap::getTableMap();

        $this->assertTrue($tableMap->hasColumn('name'));
        $this->assertFalse($tableMap->hasColumn('nope'));
        $this->assertTrue($tableMap->hasColumnByPhpName('IsActive'));
        $this->assertSame('is_active', $tableMap->getColumnByPhpName('IsActive')->getName());
    }

    /**
     * @return void
     */
    public function testPrimaryKeyColumn()
    {
        $tableMap = ContractProductTableMap::getTableMap();
        $id = $tableMap->getColumn('id');

        $this->assertTrue($id->isPrimaryKey());
        $this->assertSame('INTEGER', $id->getType());
        $this->assertSame(['id'], array_keys($tableMap->getPrimaryKeys()));
    }

    /**
     * @return void
     */
    public function testGetColumnsKeysAreUppercaseColumnNames()
    {
        $columns = ContractProductTableMap::getTableMap()->getColumns();

        $this->assertSame(
            ['ID', 'NAME', 'TYPE', 'PRICE', 'IS_ACTIVE', 'QTY', 'DESCRIPTION', 'NOTE', 'BIG_NUMBER', 'RELEASED_ON', 'OPENS_AT', 'RATING', 'ID_MANUFACTURER', 'CREATED_AT', 'UPDATED_AT'],
            array_keys($columns),
        );
        $this->assertContainsOnlyInstancesOf(ColumnMap::class, $columns);
    }

    /**
     * @return void
     */
    public function testColumnTypes()
    {
        $tableMap = ContractProductTableMap::getTableMap();
        $types = [];
        foreach ($tableMap->getColumns() as $column) {
            $types[$column->getName()] = $column->getType();
        }

        $this->assertSame([
            'id' => 'INTEGER',
            'name' => 'VARCHAR',
            'type' => 'ENUM_BINARY',
            'price' => 'DECIMAL',
            'is_active' => 'BOOLEAN',
            'qty' => 'INTEGER',
            'description' => 'LONGVARCHAR',
            'note' => 'CLOB',
            'big_number' => 'BIGINT',
            'released_on' => 'DATE',
            'opens_at' => 'TIME',
            'rating' => 'FLOAT',
            'id_manufacturer' => 'INTEGER',
            'created_at' => 'TIMESTAMP',
            'updated_at' => 'TIMESTAMP',
        ], $types);
    }

    /**
     * @return void
     */
    public function testColumnMapSizeDefaultAndForeignKeyFlag()
    {
        $tableMap = ContractProductTableMap::getTableMap();

        $this->assertSame(10, $tableMap->getColumn('price')->getSize());
        $this->assertSame(0, $tableMap->getColumn('qty')->getSize());
        $this->assertTrue($tableMap->getColumn('id_manufacturer')->isForeignKey());
        $this->assertFalse($tableMap->getColumn('qty')->isForeignKey());
        $this->assertSame('other', $tableMap->getColumn('type')->getDefaultValue());
        $this->assertSame(0, $tableMap->getColumn('qty')->getDefaultValue());
        $this->assertTrue($tableMap->getColumn('is_active')->getDefaultValue());
        $this->assertFalse($tableMap->getColumn('id_manufacturer')->isNotNull());
    }

    /**
     * @return void
     */
    public function testGetRelationsKeysAndTypes()
    {
        $relations = ContractProductTableMap::getTableMap()->getRelations();

        $this->assertSame(['ContractManufacturer', 'ContractProductCategory', 'ContractOrderProduct'], array_keys($relations));
        $this->assertContainsOnlyInstancesOf(RelationMap::class, $relations);
        $this->assertSame(RelationMap::MANY_TO_ONE, $relations['ContractManufacturer']->getType());
        $this->assertSame(RelationMap::ONE_TO_MANY, $relations['ContractProductCategory']->getType());
        $this->assertSame(RelationMap::ONE_TO_MANY, $relations['ContractOrderProduct']->getType());
    }

    /**
     * @return void
     */
    public function testGetRelationManyToOne()
    {
        $relation = ContractProductTableMap::getTableMap()->getRelation('ContractManufacturer');

        $this->assertSame('ContractManufacturer', $relation->getName());
        $this->assertSame('cc_product', $relation->getLocalTable()->getName());
        $this->assertSame('cc_manufacturer', $relation->getForeignTable()->getName());
        $this->assertInstanceOf(ContractManufacturerTableMap::class, $relation->getForeignTable());
        $this->assertSame(['cc_product.id_manufacturer' => 'cc_manufacturer.id'], $relation->getColumnMappings());
        $this->assertSame('SET NULL', $relation->getOnDelete());
        $this->assertSame('ContractProduct', $relation->getSymmetricalRelation()->getName());
    }

    /**
     * @return void
     */
    public function testGetRelationOneToMany()
    {
        $relation = ContractProductTableMap::getTableMap()->getRelation('ContractProductCategory');

        $this->assertSame('cc_product_category', $relation->getLocalTable()->getName());
        $this->assertSame('cc_product', $relation->getForeignTable()->getName());
        $this->assertSame(['cc_product_category.id_product' => 'cc_product.id'], $relation->getColumnMappings());
        $this->assertSame('CASCADE', $relation->getOnDelete());
    }

    /**
     * @return void
     */
    public function testGetUnknownRelationThrows()
    {
        $this->expectException(RelationNotFoundException::class);
        ContractProductTableMap::getTableMap()->getRelation('Nope');
    }

    /**
     * @return void
     */
    public function testHasRelation()
    {
        $tableMap = ContractProductTableMap::getTableMap();

        $this->assertTrue($tableMap->hasRelation('ContractManufacturer'));
        $this->assertFalse($tableMap->hasRelation('Nope'));
    }

    /**
     * @return void
     */
    public function testRelationNamesForTwoForeignKeysToSameTable()
    {
        $this->assertSame(
            ['BillingAddress', 'ContractAddressRelatedByIdDeliveryAddress', 'ContractOrderProduct'],
            array_keys(ContractOrderTableMap::getTableMap()->getRelations()),
        );
        $this->assertSame(
            ['BillingOrder', 'ContractOrderRelatedByIdDeliveryAddress'],
            array_keys(ContractAddressTableMap::getTableMap()->getRelations()),
        );
    }

    /**
     * @return void
     */
    public function testEnumValueSet()
    {
        $this->assertSame(['tyre', 'disc', 'other'], ContractProductTableMap::getValueSet(ContractProductTableMap::COL_TYPE));
        $this->assertSame(['cc_product.type' => ['tyre', 'disc', 'other']], ContractProductTableMap::getValueSets());
    }

    /**
     * @return void
     */
    public function testValueSetOfNonEnumColumnIsEmpty()
    {
        $this->assertSame([], ContractProductTableMap::getTableMap()->getColumn('name')->getValueSet());
    }

    /**
     * @return void
     */
    public function testTranslateFieldName()
    {
        $this->assertSame('is_active', ContractProductTableMap::translateFieldName('IsActive', TableMap::TYPE_PHPNAME, TableMap::TYPE_FIELDNAME));
        $this->assertSame('cc_product.is_active', ContractProductTableMap::translateFieldName('is_active', TableMap::TYPE_FIELDNAME, TableMap::TYPE_COLNAME));
        $this->assertSame('IsActive', ContractProductTableMap::translateFieldName('is_active', TableMap::TYPE_FIELDNAME, TableMap::TYPE_PHPNAME));
        $this->assertSame('isActive', ContractProductTableMap::translateFieldName('is_active', TableMap::TYPE_FIELDNAME, TableMap::TYPE_CAMELNAME));
    }

    /**
     * @return void
     */
    public function testTranslateUnknownFieldNameThrows()
    {
        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        ContractProductTableMap::translateFieldName('Nope', TableMap::TYPE_PHPNAME, TableMap::TYPE_FIELDNAME);
    }

    /**
     * @return void
     */
    public function testGetFieldNames()
    {
        $this->assertSame(
            ['id', 'name', 'type', 'price', 'is_active', 'qty', 'description', 'note', 'big_number', 'released_on', 'opens_at', 'rating', 'id_manufacturer', 'created_at', 'updated_at'],
            ContractProductTableMap::getFieldNames(TableMap::TYPE_FIELDNAME),
        );
        $this->assertSame(
            ['Id', 'Name', 'Type', 'Price', 'IsActive', 'Qty', 'Description', 'Note', 'BigNumber', 'ReleasedOn', 'OpensAt', 'Rating', 'IdManufacturer', 'CreatedAt', 'UpdatedAt'],
            ContractProductTableMap::getFieldNames(TableMap::TYPE_PHPNAME),
        );
        $this->assertSame('cc_product.id', ContractProductTableMap::getFieldNames(TableMap::TYPE_COLNAME)[0]);
    }

    /**
     * @return void
     */
    public function testDatabaseMapKnowsAllTables()
    {
        $databaseMap = ContractProductTableMap::getTableMap()->getDatabaseMap();

        $this->assertTrue($databaseMap->hasTable('cc_manufacturer'));
        $this->assertSame('ContractOrderLog', $databaseMap->getTable('cc_order_log')->getPhpName());
    }

    /**
     * @return void
     */
    public function testInstancePoolKeyOfSingleColumnPrimaryKey()
    {
        $this->assertSame('5', ContractProductTableMap::getPrimaryKeyHashFromRow([5, 'x']));
    }
}
