<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Perpl;
use Propel\Runtime\Propel;
use Propel\Tests\TestCase;

/**
 * Shared schema and helpers for the consumer contract tests.
 *
 * The contract tests pin the ORM API surface used by the (single) consumer application.
 * The schema is a condensed model of the consumer's feature profile.
 */
abstract class ConsumerContractTestCase extends TestCase
{
    public const SCHEMA = <<<'END'
<database name="contract" namespace="ConsumerContract" defaultIdMethod="native" defaultPhpNamingMethod="underscore">
    <table name="cc_manufacturer" phpName="ContractManufacturer">
        <column name="id" phpName="Id" type="INTEGER" primaryKey="true" autoIncrement="true" required="true"/>
        <column name="name" phpName="Name" type="VARCHAR" size="255" required="true"/>
        <column name="code" phpName="Code" type="VARCHAR" size="50"/>
        <unique>
            <unique-column name="code"/>
        </unique>
        <vendor type="mysql"><parameter name="Engine" value="InnoDB"/></vendor>
    </table>
    <table name="cc_product" phpName="ContractProduct">
        <column name="id" phpName="Id" type="INTEGER" primaryKey="true" autoIncrement="true" required="true"/>
        <column name="name" phpName="Name" type="VARCHAR" size="255" required="true"/>
        <column name="type" phpName="Type" type="ENUM" valueSet="tyre, disc, other" defaultValue="other"/>
        <column name="price" phpName="Price" type="DECIMAL" size="10" scale="2" defaultValue="0"/>
        <column name="is_active" phpName="IsActive" type="BOOLEAN" defaultValue="true"/>
        <column name="qty" phpName="Qty" type="INTEGER" defaultValue="0"/>
        <column name="description" phpName="Description" type="LONGVARCHAR"/>
        <column name="note" phpName="Note" type="CLOB"/>
        <column name="big_number" phpName="BigNumber" type="BIGINT"/>
        <column name="released_on" phpName="ReleasedOn" type="DATE"/>
        <column name="opens_at" phpName="OpensAt" type="TIME"/>
        <column name="rating" phpName="Rating" type="FLOAT"/>
        <column name="id_manufacturer" phpName="IdManufacturer" type="INTEGER"/>
        <foreign-key foreignTable="cc_manufacturer" onDelete="setnull" phpName="ContractManufacturer" refPhpName="ContractProduct">
            <reference local="id_manufacturer" foreign="id"/>
        </foreign-key>
        <index>
            <index-column name="name"/>
        </index>
        <behavior name="timestampable"/>
        <vendor type="mysql"><parameter name="Engine" value="InnoDB"/></vendor>
    </table>
    <table name="cc_category" phpName="ContractCategory">
        <column name="id" phpName="Id" type="INTEGER" primaryKey="true" autoIncrement="true" required="true"/>
        <column name="name" phpName="Name" type="VARCHAR" size="255"/>
        <vendor type="mysql"><parameter name="Engine" value="InnoDB"/></vendor>
    </table>
    <table name="cc_product_category" phpName="ContractProductCategory">
        <column name="id" phpName="Id" type="INTEGER" primaryKey="true" autoIncrement="true" required="true"/>
        <column name="id_product" phpName="IdProduct" type="INTEGER" required="true"/>
        <column name="id_category" phpName="IdCategory" type="INTEGER" required="true"/>
        <column name="priority" phpName="Priority" type="INTEGER" defaultValue="0"/>
        <foreign-key foreignTable="cc_product" onDelete="cascade" phpName="ContractProduct" refPhpName="ContractProductCategory">
            <reference local="id_product" foreign="id"/>
        </foreign-key>
        <foreign-key foreignTable="cc_category" onDelete="cascade" phpName="ContractCategory" refPhpName="ContractProductCategory">
            <reference local="id_category" foreign="id"/>
        </foreign-key>
        <vendor type="mysql"><parameter name="Engine" value="InnoDB"/></vendor>
    </table>
    <table name="cc_address" phpName="ContractAddress">
        <column name="id" phpName="Id" type="INTEGER" primaryKey="true" autoIncrement="true" required="true"/>
        <column name="city" phpName="City" type="VARCHAR" size="255"/>
        <vendor type="mysql"><parameter name="Engine" value="InnoDB"/></vendor>
    </table>
    <table name="cc_order" phpName="ContractOrder">
        <column name="id" phpName="Id" type="INTEGER" primaryKey="true" autoIncrement="true" required="true"/>
        <column name="number" phpName="Number" type="VARCHAR" size="50"/>
        <column name="created_at" phpName="CreatedAt" type="TIMESTAMP" defaultExpr="CURRENT_TIMESTAMP"/>
        <column name="total" phpName="Total" type="DECIMAL" size="10" scale="2"/>
        <column name="id_billing_address" phpName="IdBillingAddress" type="INTEGER"/>
        <column name="id_delivery_address" phpName="IdDeliveryAddress" type="INTEGER"/>
        <foreign-key foreignTable="cc_address" phpName="BillingAddress" refPhpName="BillingOrder">
            <reference local="id_billing_address" foreign="id"/>
        </foreign-key>
        <foreign-key foreignTable="cc_address">
            <reference local="id_delivery_address" foreign="id"/>
        </foreign-key>
        <vendor type="mysql"><parameter name="Engine" value="InnoDB"/></vendor>
    </table>
    <table name="cc_order_product" phpName="ContractOrderProduct">
        <column name="id" phpName="Id" type="INTEGER" primaryKey="true" autoIncrement="true" required="true"/>
        <column name="id_order" phpName="IdOrder" type="INTEGER" required="true"/>
        <column name="id_product" phpName="IdProduct" type="INTEGER" required="true"/>
        <column name="quantity" phpName="Quantity" type="INTEGER"/>
        <column name="price" phpName="Price" type="DECIMAL" size="10" scale="2"/>
        <foreign-key foreignTable="cc_order" onDelete="cascade" phpName="ContractOrder" refPhpName="ContractOrderProduct">
            <reference local="id_order" foreign="id"/>
        </foreign-key>
        <foreign-key foreignTable="cc_product" phpName="ContractProduct" refPhpName="ContractOrderProduct">
            <reference local="id_product" foreign="id"/>
        </foreign-key>
        <vendor type="mysql"><parameter name="Engine" value="InnoDB"/></vendor>
    </table>
    <table name="cc_order_log" phpName="ContractOrderLog">
        <column name="id" phpName="Id" type="INTEGER" primaryKey="true" autoIncrement="true" required="true"/>
        <column name="id_order" phpName="IdOrder" type="INTEGER"/>
        <column name="message" phpName="Message" type="VARCHAR" size="255"/>
        <behavior name="archivable"/>
        <vendor type="mysql"><parameter name="Engine" value="InnoDB"/></vendor>
    </table>
</database>
END;

    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!class_exists('\ConsumerContract\ContractProduct')) {
            QuickBuilder::buildSchema(static::SCHEMA);
        }
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Perpl::enableInstancePooling();
        $this->clearPools();
        $this->wipeTables();
        Perpl::enableInstancePooling();
        $this->clearPools();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $con = Propel::getConnection('contract');
        while ($con->inTransaction()) {
            $con->rollBack();
        }
        $con->useDebug(false);
        Perpl::enableInstancePooling();
        $this->clearPools();

        parent::tearDown();
    }

    /**
     * @return array<string>
     */
    protected function getTableNames(): array
    {
        return [
            'cc_order_log', 'cc_order_log_archive', 'cc_order_product', 'cc_product_category', 'cc_order',
            'cc_product', 'cc_category', 'cc_address', 'cc_manufacturer',
        ];
    }

    /**
     * @return void
     */
    protected function wipeTables(): void
    {
        $con = Propel::getConnection('contract');
        $con->useDebug(false);
        foreach ($this->getTableNames() as $table) {
            $con->exec("DELETE FROM $table");
        }
        $con->exec('DELETE FROM sqlite_sequence');
    }

    /**
     * @return void
     */
    protected function clearPools(): void
    {
        foreach (['ContractManufacturer', 'ContractProduct', 'ContractCategory', 'ContractProductCategory', 'ContractAddress', 'ContractOrder', 'ContractOrderProduct', 'ContractOrderLog', 'CcOrderLogArchive'] as $name) {
            $class = "\\ConsumerContract\\Map\\{$name}TableMap";
            if (class_exists($class)) {
                $class::clearInstancePool();
            }
        }
    }

    /**
     * Connection with debug mode on, so getLastExecutedQuery() and getQueryCount() work.
     *
     * @return \Propel\Runtime\Connection\ConnectionInterface
     */
    protected function getDebugConnection(): ConnectionInterface
    {
        $con = Propel::getConnection('contract');
        $con->useDebug(true);

        return $con;
    }

    /**
     * @param string $name
     * @param array<string, mixed> $values
     *
     * @return \ConsumerContract\ContractManufacturer
     */
    protected function createManufacturer(string $name = 'Acme', array $values = [])
    {
        $manufacturer = new \ConsumerContract\ContractManufacturer();
        $manufacturer->setName($name);
        $manufacturer->setCode($values['code'] ?? strtoupper($name));
        $manufacturer->save();

        return $manufacturer;
    }

    /**
     * @param string $name
     * @param array<string, mixed> $values Column PhpName => value
     *
     * @return \ConsumerContract\ContractProduct
     */
    protected function createProduct(string $name = 'Product', array $values = [])
    {
        $product = new \ConsumerContract\ContractProduct();
        $product->setName($name);
        $product->fromArray($values, \Propel\Runtime\Map\TableMap::TYPE_PHPNAME);
        $product->save();

        return $product;
    }

    /**
     * @param string $name
     *
     * @return \ConsumerContract\ContractCategory
     */
    protected function createCategory(string $name = 'Category')
    {
        $category = new \ConsumerContract\ContractCategory();
        $category->setName($name);
        $category->save();

        return $category;
    }

    /**
     * @param \ConsumerContract\ContractProduct $product
     * @param \ConsumerContract\ContractCategory $category
     * @param int $priority
     *
     * @return \ConsumerContract\ContractProductCategory
     */
    protected function linkProductCategory($product, $category, int $priority = 0)
    {
        $link = new \ConsumerContract\ContractProductCategory();
        $link->setContractProduct($product);
        $link->setContractCategory($category);
        $link->setPriority($priority);
        $link->save();

        return $link;
    }

    /**
     * @param string $city
     *
     * @return \ConsumerContract\ContractAddress
     */
    protected function createAddress(string $city = 'Bratislava')
    {
        $address = new \ConsumerContract\ContractAddress();
        $address->setCity($city);
        $address->save();

        return $address;
    }

    /**
     * @param string $number
     *
     * @return \ConsumerContract\ContractOrder
     */
    protected function createOrder(string $number = 'O1')
    {
        $order = new \ConsumerContract\ContractOrder();
        $order->setNumber($number);
        $order->save();

        return $order;
    }

    /**
     * Builds the serialized form written by the generated __sleep() of version 2.9.1 and
     * older: all properties of the object, including the ones holding relations.
     * Consumers have such blobs in their caches.
     *
     * @param object $model
     * @param array<string, mixed> $propertyOverrides Values by property name
     *
     * @return string
     */
    protected function buildLegacyBlob(object $model, array $propertyOverrides = []): string
    {
        $properties = [];
        foreach ((new \ReflectionClass($model))->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $prefix = $property->isPrivate() ? "\0" . $property->getDeclaringClass()->getName() . "\0" : ($property->isProtected() ? "\0*\0" : '');
            $value = $property->isInitialized($model) ? $property->getValue($model) : null;
            $properties[$prefix . $property->getName()] = is_object($value) ? null : $value;
        }
        foreach ($propertyOverrides as $name => $value) {
            $properties["\0*\0$name"] = $value;
        }
        $blob = 'O:' . strlen(get_class($model)) . ':"' . get_class($model) . '":' . count($properties) . ':{';
        foreach ($properties as $key => $value) {
            $blob .= serialize($key) . serialize($value);
        }

        return $blob . '}';
    }

    /**
     * Fixture: manufacturers A, B; products P1..P4 (P1,P2 -> A; P3 -> B; P4 -> none); categories C1, C2.
     * P1 in C1 (prio 1) and C2 (prio 2); P2 in C1 (prio 5).
     *
     * @return array<string, mixed>
     */
    protected function createCatalog(): array
    {
        $a = $this->createManufacturer('A');
        $b = $this->createManufacturer('B');
        $p1 = $this->createProduct('P1', ['Type' => 'tyre', 'Price' => '10.50', 'Qty' => 3, 'IdManufacturer' => $a->getId()]);
        $p2 = $this->createProduct('P2', ['Type' => 'disc', 'Price' => '20.00', 'Qty' => 10, 'IdManufacturer' => $a->getId()]);
        $p3 = $this->createProduct('P3', ['Type' => 'tyre', 'Price' => '30.00', 'Qty' => 7, 'IdManufacturer' => $b->getId()]);
        $p4 = $this->createProduct('P4', ['Type' => 'other', 'Price' => '40.00', 'Qty' => 0]);
        $c1 = $this->createCategory('C1');
        $c2 = $this->createCategory('C2');
        $this->linkProductCategory($p1, $c1, 1);
        $this->linkProductCategory($p1, $c2, 2);
        $this->linkProductCategory($p2, $c1, 5);
        $this->clearPools();

        return compact('a', 'b', 'p1', 'p2', 'p3', 'p4', 'c1', 'c2');
    }
}
