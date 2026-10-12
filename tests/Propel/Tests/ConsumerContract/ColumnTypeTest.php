<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractOrder;
use ConsumerContract\ContractOrderQuery;
use ConsumerContract\ContractProduct;
use ConsumerContract\ContractProductQuery;
use Propel\Runtime\Exception\PropelException;

/**
 * Pins getter/setter behaviour and storage of the column types the consumer uses.
 */
class ColumnTypeTest extends ConsumerContractTestCase
{
    /**
     * @return \ConsumerContract\ContractProduct
     */
    private function newProduct(): ContractProduct
    {
        return (new ContractProduct())->setName('t');
    }

    /**
     * Saves, clears the pool and loads the product again from the database.
     *
     * @param \ConsumerContract\ContractProduct $product
     *
     * @return \ConsumerContract\ContractProduct
     */
    private function roundTrip(ContractProduct $product): ContractProduct
    {
        $product->save();
        $this->clearPools();

        return ContractProductQuery::create()->findPk($product->getId());
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRow(ContractProduct $product): array
    {
        return $this->getDebugConnection()->query('SELECT * FROM cc_product WHERE id = ' . $product->getId())->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @return void
     */
    public function testEnumSetterTakesStringAndGetterReturnsString()
    {
        $product = $this->newProduct();

        $product->setType('disc');

        $this->assertSame('disc', $product->getType());
        $this->assertSame('disc', $this->roundTrip($product)->getType());
    }

    /**
     * @return void
     */
    public function testEnumInvalidValueThrows()
    {
        $this->expectException(PropelException::class);
        $this->expectExceptionMessage("Value 'bogus' is not accepted in this enumerated column");
        $this->newProduct()->setType('bogus');
    }

    /**
     * @return void
     */
    public function testEnumInvalidValueLeavesPreviousValueUntouched()
    {
        $product = $this->newProduct()->setType('disc');

        try {
            $product->setType('bogus');
        } catch (PropelException) {
        }

        $this->assertSame('disc', $product->getType());
    }

    /**
     * @return void
     */
    public function testEnumFilterByValue()
    {
        $this->createCatalog();

        $this->assertSame(['P1', 'P3'], ContractProductQuery::create()->filterByType('tyre')->orderById()->find()->getColumnValues('Name'));
        $this->assertSame(1, ContractProductQuery::create()->filterByType('disc')->count());
    }

    /**
     * @return void
     */
    public function testEnumFilterByInvalidValueThrows()
    {
        $this->expectException(PropelException::class);
        ContractProductQuery::create()->filterByType('bogus')->find();
    }

    /**
     * @return void
     */
    public function testEnumIsStoredAsZeroBasedIndexOfValueSet()
    {
        $this->assertSame(0, $this->rawRow($this->lastSaved('tyre'))['type']);
        $this->assertSame(1, $this->rawRow($this->lastSaved('disc'))['type']);
        $this->assertSame(2, $this->rawRow($this->lastSaved('other'))['type']);
    }

    /**
     * @param string $type
     *
     * @return \ConsumerContract\ContractProduct
     */
    private function lastSaved(string $type): ContractProduct
    {
        $product = $this->newProduct()->setType($type);
        $product->save();

        return $product;
    }

    /**
     * @return void
     */
    public function testEnumDefaultValueOnNewObject()
    {
        $product = new ContractProduct();

        $this->assertSame('other', $product->getType());
        $this->assertFalse($product->isColumnModified(\ConsumerContract\Map\ContractProductTableMap::COL_TYPE));
    }

    /**
     * @return void
     */
    public function testEnumDefaultIsAppliedByDatabaseWhenColumnWasNotSetOnInsert()
    {
        $product = $this->newProduct();
        $product->save();

        $this->assertSame(2, $this->rawRow($product)['type']);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function booleanCastProvider(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'int 1' => [1, true],
            'int 0' => [0, false],
            'string 1' => ['1', true],
            'string 0' => ['0', false],
            'string true' => ['true', true],
            'string false' => ['false', false],
            'string off' => ['off', false],
            'empty string' => ['', false],
            'null' => [null, null],
        ];
    }

    /**
     * @param mixed $input
     * @param bool|null $expected
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('booleanCastProvider')]
    public function testBooleanSetterCastsToBoolOrNull($input, $expected)
    {
        $product = $this->newProduct();

        $product->setIsActive($input);

        $this->assertSame($expected, $product->getIsActive());
    }

    /**
     * @return void
     */
    public function testBooleanGetterAndIsAliasAndRoundTrip()
    {
        $product = $this->newProduct()->setIsActive(false);

        $this->assertSame($product->getIsActive(), $product->isActive());
        $this->assertFalse($this->roundTrip($product)->getIsActive());
        $this->assertSame(0, $this->rawRow($product)['is_active']);
    }

    /**
     * @return void
     */
    public function testBooleanDefaultTrue()
    {
        $this->assertTrue((new ContractProduct())->getIsActive());
    }

    /**
     * @return void
     */
    public function testDecimalGetterReturnsString()
    {
        $product = $this->newProduct();

        $product->setPrice('12.5');
        $this->assertSame('12.5', $product->getPrice());
        $product->setPrice(7);
        $this->assertSame('7', $product->getPrice());
        $product->setPrice(1.25);
        $this->assertSame('1.25', $product->getPrice());
    }

    /**
     * @return void
     */
    public function testDecimalDefaultValueIsStringZero()
    {
        $this->assertSame('0', (new ContractProduct())->getPrice());
    }

    /**
     * @return void
     */
    public function testDecimalRoundTripReturnsStringWithoutTrailingZeros()
    {
        $product = $this->roundTrip($this->newProduct()->setPrice('10.50'));

        $this->assertSame('10.5', $product->getPrice());
        $this->assertSame('10.5', $product->toArray(\Propel\Runtime\Map\TableMap::TYPE_FIELDNAME)['price']);
    }

    /**
     * @return void
     */
    public function testDecimalIsNotRoundedToScaleByTheOrm()
    {
        $product = $this->newProduct()->setPrice('12.345');

        $this->assertSame('12.345', $product->getPrice());
    }

    /**
     * @return void
     */
    public function testDecimalFilterByRange()
    {
        $this->createCatalog();

        $this->assertSame(['P2', 'P3'], ContractProductQuery::create()->filterByPrice(['min' => 15, 'max' => 35])->orderById()->find()->getColumnValues('Name'));
    }

    /**
     * @return void
     */
    public function testIntegerSetterCastsNumericStrings()
    {
        $product = $this->newProduct();

        $product->setQty('12');
        $this->assertSame(12, $product->getQty());
        $product->setQty(3.0);
        $this->assertSame(3, $product->getQty());
        $product->setQty(null);
        $this->assertNull($product->getQty());
    }

    /**
     * @return void
     */
    public function testIntegerDefaultAndRoundTrip()
    {
        $this->assertSame(0, (new ContractProduct())->getQty());
        $this->assertSame(55, $this->roundTrip($this->newProduct()->setQty(55))->getQty());
    }

    /**
     * @return void
     */
    public function testFloatRoundTripReturnsFloat()
    {
        $product = $this->roundTrip($this->newProduct()->setRating(2.5));

        $this->assertSame(2.5, $product->getRating());
        $this->assertSame(1.25, $this->newProduct()->setRating('1.25')->getRating());
    }

    /**
     * @return void
     */
    public function testLongvarcharAndClobRoundTrip()
    {
        $text = str_repeat('Lorem ipsum ', 1000) . "\nščťž";
        $product = $this->roundTrip($this->newProduct()->setDescription($text)->setNote($text . '!'));

        $this->assertSame($text, $product->getDescription());
        $this->assertSame($text . '!', $product->getNote());
        $this->assertIsString($product->getNote());
    }

    /**
     * @return void
     */
    public function testDateGetterWithoutArgumentReturnsDateTime()
    {
        $product = $this->roundTrip($this->newProduct()->setReleasedOn('2020-02-03'));

        $date = $product->getReleasedOn();

        $this->assertSame(\DateTime::class, get_class($date));
        $this->assertSame('2020-02-03 00:00:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame(date_default_timezone_get(), $date->getTimezone()->getName());
    }

    /**
     * @return void
     */
    public function testDateGetterWithFormatReturnsString()
    {
        $product = $this->roundTrip($this->newProduct()->setReleasedOn('2020-02-03'));

        $this->assertSame('03.02.2020', $product->getReleasedOn('d.m.Y'));
        $this->assertSame('2020-02-03', $product->getReleasedOn('Y-m-d'));
    }

    /**
     * @return void
     */
    public function testDateGetterWithNullFormatReturnsDateTime()
    {
        $product = $this->newProduct()->setReleasedOn('2020-02-03');

        $this->assertInstanceOf(\DateTime::class, $product->getReleasedOn(null));
    }

    /**
     * @return void
     */
    public function testDateSetterAcceptsStringDateTimeAndNull()
    {
        $product = $this->newProduct();

        $product->setReleasedOn('2020-02-03');
        $this->assertSame('2020-02-03', $product->getReleasedOn('Y-m-d'));

        $product->setReleasedOn(new \DateTime('2021-05-06 10:11:12'));
        $this->assertSame('2021-05-06', $product->getReleasedOn('Y-m-d'));

        $product->setReleasedOn(null);
        $this->assertNull($product->getReleasedOn());
        $this->assertNull($product->getReleasedOn('Y-m-d'));
    }

    /**
     * @return void
     */
    public function testDateSetterAcceptsDateTimeImmutableAndGetterReturnsImmutableUntilReload()
    {
        $product = $this->newProduct();

        $product->setReleasedOn(new \DateTimeImmutable('2021-05-07'));

        $this->assertSame('2021-05-07', $product->getReleasedOn('Y-m-d'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $product->getReleasedOn(), 'the object given to the setter is kept as the cached value');
        $this->assertInstanceOf(\DateTime::class, $this->roundTrip($product)->getReleasedOn());
    }

    /**
     * @return void
     */
    public function testDateSetterWithSameValueDoesNotMarkColumnModified()
    {
        $product = $this->roundTrip($this->newProduct()->setReleasedOn('2020-02-03'));

        $product->setReleasedOn('2020-02-03');
        $product->setReleasedOn(new \DateTime('2020-02-03 00:00:00'));

        $this->assertFalse($product->isModified());
    }

    /**
     * @return void
     */
    public function testDateSetterWithInvalidStringThrows()
    {
        $this->expectException(PropelException::class);
        $this->newProduct()->setReleasedOn('not a date');
    }

    /**
     * @return void
     */
    public function testDateIsStoredAsIsoString()
    {
        $product = $this->newProduct()->setReleasedOn('2020-02-03');
        $product->save();

        $this->assertSame('2020-02-03', $this->rawRow($product)['released_on']);
    }

    /**
     * @return void
     */
    public function testTimeGetterReturnsDateTimeOfTodayAndFormatReturnsString()
    {
        $product = $this->roundTrip($this->newProduct()->setOpensAt('08:30:15'));

        $this->assertInstanceOf(\DateTime::class, $product->getOpensAt());
        $this->assertSame('08:30:15', $product->getOpensAt('H:i:s'));
        $this->assertSame('08:30', $product->getOpensAt('H:i'));
        $this->assertSame(date('Y-m-d'), $product->getOpensAt()->format('Y-m-d'), 'a TIME value is anchored on the current day');
    }

    /**
     * @return void
     */
    public function testTimeSetterAcceptsDateTimeAndNull()
    {
        $product = $this->newProduct()->setOpensAt(new \DateTime('2000-01-01 23:59:58'));
        $this->assertSame('23:59:58', $product->getOpensAt('H:i:s'));

        $product->setOpensAt(null);
        $this->assertNull($product->getOpensAt());
    }

    /**
     * @return void
     */
    public function testTimestampGetterReturnsDateTimeWithMicroseconds()
    {
        $product = $this->roundTrip($this->newProduct());

        $created = $product->getCreatedAt();

        $this->assertSame(\DateTime::class, get_class($created));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $product->getCreatedAt('Y-m-d H:i:s'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}$/', $product->getCreatedAt('Y-m-d H:i:s.u'));
    }

    /**
     * @return void
     */
    public function testTimestampSetterAcceptsStringDateTimeImmutableAndNull()
    {
        $product = $this->newProduct();

        $product->setCreatedAt('2001-02-03 04:05:06');
        $this->assertSame('2001-02-03 04:05:06', $product->getCreatedAt('Y-m-d H:i:s'));
        $product->setCreatedAt(new \DateTime('2002-03-04 05:06:07'));
        $this->assertSame('2002-03-04 05:06:07', $product->getCreatedAt('Y-m-d H:i:s'));
        $product->setCreatedAt(new \DateTimeImmutable('2003-04-05 06:07:08'));
        $this->assertSame('2003-04-05 06:07:08', $product->getCreatedAt('Y-m-d H:i:s'));
        $product->setCreatedAt(null);
        $this->assertNull($product->getCreatedAt());
    }

    /**
     * @return void
     */
    public function testNewObjectHasDefaultValuesFromSchemaWithoutBeingModified()
    {
        $product = new ContractProduct();

        $this->assertSame('other', $product->getType());
        $this->assertSame('0', $product->getPrice());
        $this->assertTrue($product->getIsActive());
        $this->assertSame(0, $product->getQty());
        $this->assertNull($product->getName());
        $this->assertNull($product->getDescription());
        $this->assertFalse($product->isModified());
        $this->assertSame([], $product->getModifiedColumns());
    }

    /**
     * @return void
     */
    public function testApplyDefaultValuesIsPublicAndRestoresDefaults()
    {
        $product = $this->newProduct()->setQty(5)->setType('disc');

        $product->applyDefaultValues();

        $this->assertSame(0, $product->getQty());
        $this->assertSame('other', $product->getType());
        $this->assertSame('t', $product->getName());
    }

    /**
     * @return void
     */
    public function testDefaultExprColumnIsNullUntilReloadedAfterInsert()
    {
        $order = new ContractOrder();
        $order->setNumber('x');
        $this->assertNull($order->getCreatedAt());

        $order->save();
        $this->assertNull($order->getCreatedAt(), 'the database default is not read back by save()');

        $order->reload();
        $this->assertInstanceOf(\DateTime::class, $order->getCreatedAt());
        $this->assertLessThan(86400 * 2, abs(time() - $order->getCreatedAt()->getTimestamp()));
    }

    /**
     * @return void
     */
    public function testDefaultExprColumnIsNotPartOfInsertAndFoundAfterFreshLoad()
    {
        $con = $this->getDebugConnection();
        $order = new ContractOrder();
        $order->setNumber('x');

        $order->save($con);

        $this->assertStringStartsWith("INSERT INTO cc_order (id, number) VALUES (NULL, 'x')", $con->getLastExecutedQuery());
        $this->clearPools();
        $this->assertNotNull(ContractOrderQuery::create()->findPk($order->getId())->getCreatedAt());
    }

    /**
     * @return void
     */
    public function testNotNullColumnWithoutValueFailsOnInsert()
    {
        $this->expectException(PropelException::class);
        (new ContractProduct())->save();
    }

    /**
     * @return void
     */
    public function testUniqueColumnViolationThrowsPropelException()
    {
        $this->createManufacturer('Acme', ['code' => 'X']);

        $this->expectException(PropelException::class);
        $this->createManufacturer('Other', ['code' => 'X']);
    }
}
