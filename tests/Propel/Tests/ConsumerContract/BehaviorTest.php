<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\CcOrderLogArchive;
use ConsumerContract\CcOrderLogArchiveQuery;
use ConsumerContract\ContractOrderLog;
use ConsumerContract\ContractOrderLogQuery;
use ConsumerContract\ContractProduct;
use ConsumerContract\ContractProductQuery;

/**
 * Pins the two behaviors the consumer uses: timestampable and archivable.
 */
class BehaviorTest extends ConsumerContractTestCase
{
    /**
     * @param \DateTimeInterface|null $date
     *
     * @return void
     */
    private function assertRecent(?\DateTimeInterface $date): void
    {
        $this->assertInstanceOf(\DateTime::class, $date);
        $this->assertLessThan(60, abs(time() - $date->getTimestamp()));
    }

    /**
     * @return void
     */
    public function testTimestampableSetsCreatedAtAndUpdatedAtOnInsert()
    {
        $product = new ContractProduct();
        $product->setName('t');
        $this->assertNull($product->getCreatedAt());
        $this->assertNull($product->getUpdatedAt());

        $product->save();

        $this->assertRecent($product->getCreatedAt());
        $this->assertRecent($product->getUpdatedAt());
        $this->assertEquals($product->getCreatedAt(), $product->getUpdatedAt());
    }

    /**
     * @return void
     */
    public function testTimestampableInsertSqlContainsBothTimestamps()
    {
        $con = $this->getDebugConnection();
        $product = new ContractProduct();
        $product->setName('t');

        $product->save($con);

        $this->assertMatchesRegularExpression("/INSERT INTO cc_product \(id, name, created_at, updated_at\) VALUES \(NULL, 't', '\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}', '\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}'\)/", $con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testTimestampableDoesNotOverwriteExplicitValuesOnInsert()
    {
        $product = new ContractProduct();
        $product->setName('t');
        $product->setCreatedAt('2001-02-03 04:05:06');
        $product->setUpdatedAt('2002-03-04 05:06:07');

        $product->save();

        $this->assertSame('2001-02-03 04:05:06', $product->getCreatedAt('Y-m-d H:i:s'));
        $this->assertSame('2002-03-04 05:06:07', $product->getUpdatedAt('Y-m-d H:i:s'));
    }

    /**
     * @return void
     */
    public function testTimestampableUpdatesOnlyUpdatedAtOnUpdate()
    {
        $product = $this->createProduct('t');
        $product->setCreatedAt('2001-02-03 04:05:06');
        $product->setUpdatedAt('2001-02-03 04:05:06');
        $product->save();

        $product->setName('changed');
        $product->save();

        $this->assertSame('2001-02-03 04:05:06', $product->getCreatedAt('Y-m-d H:i:s'));
        $this->assertRecent($product->getUpdatedAt());
        $this->clearPools();
        $reloaded = ContractProductQuery::create()->findPk($product->getId());
        $this->assertSame('2001-02-03 04:05:06', $reloaded->getCreatedAt('Y-m-d H:i:s'));
        $this->assertRecent($reloaded->getUpdatedAt());
    }

    /**
     * @return void
     */
    public function testTimestampableDoesNotTouchUpdatedAtWhenNothingChanged()
    {
        $product = $this->createProduct('t');
        $product->setUpdatedAt('2001-02-03 04:05:06');
        $product->save();

        $product->save();

        $this->assertSame('2001-02-03 04:05:06', $product->getUpdatedAt('Y-m-d H:i:s'));
    }

    /**
     * @return void
     */
    public function testKeepUpdateDateUnchangedSkipsUpdatedAtOnNextSave()
    {
        $product = $this->createProduct('t');
        $product->setUpdatedAt('2001-02-03 04:05:06');
        $product->save();

        $result = $product->keepUpdateDateUnchanged();
        $product->setName('changed');
        $product->save();

        $this->assertSame($product, $result);
        $this->assertSame('2001-02-03 04:05:06', $product->getUpdatedAt('Y-m-d H:i:s'));
        $this->clearPools();
        $this->assertSame('2001-02-03 04:05:06', ContractProductQuery::create()->findPk($product->getId())->getUpdatedAt('Y-m-d H:i:s'));
    }

    /**
     * @return void
     */
    public function testKeepUpdateDateUnchangedAppliesToOneSaveOnly()
    {
        $product = $this->createProduct('t');
        $product->setUpdatedAt('2001-02-03 04:05:06');
        $product->save();
        $product->keepUpdateDateUnchanged();
        $product->setName('first');
        $product->save();

        $product->setName('second');
        $product->save();

        $this->assertRecent($product->getUpdatedAt());
    }

    /**
     * @return void
     */
    public function testTimestampableQueryHelpers()
    {
        $this->createProduct('old')->setCreatedAt('2001-01-01 00:00:00')->save();
        $this->createProduct('new');

        $this->assertSame(['new', 'old'], ContractProductQuery::create()->lastCreatedFirst()->find()->getColumnValues('Name'));
        $this->assertSame(['old', 'new'], ContractProductQuery::create()->firstCreatedFirst()->find()->getColumnValues('Name'));
        $this->assertSame(['new'], ContractProductQuery::create()->recentlyCreated()->find()->getColumnValues('Name'));
        $this->assertTrue(method_exists(ContractProductQuery::class, 'lastUpdatedFirst'));
        $this->assertTrue(method_exists(ContractProductQuery::class, 'firstUpdatedFirst'));
    }

    /**
     * @return void
     */
    public function testQueryUpdateDoesNotTouchUpdatedAtByDefault()
    {
        $product = $this->createProduct('t');
        $product->setUpdatedAt('2001-02-03 04:05:06');
        $product->save();
        $this->clearPools();

        ContractProductQuery::create()->filterById($product->getId())->update(['Qty' => 2]);

        $this->clearPools();
        $this->assertSame('2001-02-03 04:05:06', ContractProductQuery::create()->findPk($product->getId())->getUpdatedAt('Y-m-d H:i:s'));
    }

    /**
     * @return void
     */
    public function testArchivableGeneratesArchiveModelAndQuery()
    {
        $this->assertTrue(class_exists(CcOrderLogArchive::class));
        $this->assertTrue(class_exists(CcOrderLogArchiveQuery::class));
        $this->assertSame('cc_order_log_archive', \ConsumerContract\Map\CcOrderLogArchiveTableMap::TABLE_NAME);
        $this->assertSame(
            ['Id', 'IdOrder', 'Message', 'ArchivedAt'],
            \ConsumerContract\Map\CcOrderLogArchiveTableMap::getFieldNames(\Propel\Runtime\Map\TableMap::TYPE_PHPNAME),
        );
        foreach (['getArchive', 'archive', 'restoreFromArchive', 'populateFromArchive', 'deleteWithoutArchive'] as $method) {
            $this->assertTrue(method_exists(ContractOrderLog::class, $method), $method);
        }
    }

    /**
     * @return void
     */
    public function testArchivableDeleteWritesRowToArchiveTable()
    {
        $log = new ContractOrderLog();
        $log->setIdOrder(5)->setMessage('hello');
        $log->save();
        $id = $log->getId();
        $this->assertSame(0, CcOrderLogArchiveQuery::create()->count(), 'nothing archived on insert');

        $log->delete();

        $this->assertSame(0, ContractOrderLogQuery::create()->count());
        $this->assertSame(1, CcOrderLogArchiveQuery::create()->count());
        $archive = CcOrderLogArchiveQuery::create()->findOne();
        $this->assertInstanceOf(CcOrderLogArchive::class, $archive);
        $this->assertSame($id, $archive->getId());
        $this->assertSame(5, $archive->getIdOrder());
        $this->assertSame('hello', $archive->getMessage());
        $this->assertRecent($archive->getArchivedAt());
    }

    /**
     * @return void
     */
    public function testArchivableDeleteWithoutArchiveDoesNotWriteArchive()
    {
        $log = new ContractOrderLog();
        $log->setIdOrder(5)->setMessage('gone for good');
        $log->save();

        $log->deleteWithoutArchive();

        $this->assertSame(0, ContractOrderLogQuery::create()->count());
        $this->assertSame(0, CcOrderLogArchiveQuery::create()->count());
        $this->assertTrue($log->isDeleted());
    }

    /**
     * @return void
     */
    public function testArchivableGetArchiveReturnsArchiveRowOrNull()
    {
        $log = new ContractOrderLog();
        $log->setIdOrder(5)->setMessage('m');
        $log->save();
        $this->assertNull($log->getArchive());

        $log->archive();

        $archive = $log->getArchive();
        $this->assertInstanceOf(CcOrderLogArchive::class, $archive);
        $this->assertSame('m', $archive->getMessage());
        $this->assertSame(1, ContractOrderLogQuery::create()->count(), 'archive() does not delete');
    }

    /**
     * @return void
     */
    public function testArchivableArchiveTwiceUpdatesSameArchiveRow()
    {
        $log = new ContractOrderLog();
        $log->setIdOrder(5)->setMessage('v1');
        $log->save();
        $log->archive();
        $log->setMessage('v2')->save();

        $log->archive();

        $this->assertSame(1, CcOrderLogArchiveQuery::create()->count());
        $this->assertSame('v2', $log->getArchive()->getMessage());
    }

    /**
     * @return void
     */
    public function testArchivableQueryDeleteArchivesByDefaultAndWithoutArchiveSkipsIt()
    {
        $log = new ContractOrderLog();
        $log->setIdOrder(5)->setMessage('m');
        $log->save();
        $other = new ContractOrderLog();
        $other->setIdOrder(6)->setMessage('n');
        $other->save();

        $this->assertSame(1, ContractOrderLogQuery::create()->filterById($log->getId())->delete());
        $this->assertSame(1, CcOrderLogArchiveQuery::create()->count());

        $this->assertSame(1, ContractOrderLogQuery::create()->filterById($other->getId())->deleteWithoutArchive());
        $this->assertSame(0, ContractOrderLogQuery::create()->count());
        $this->assertSame(1, CcOrderLogArchiveQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testArchivableRestoreFromArchiveOverwritesValuesOfExistingObject()
    {
        $log = new ContractOrderLog();
        $log->setIdOrder(5)->setMessage('archived value');
        $log->save();
        $log->archive();
        $log->setMessage('later value')->setIdOrder(9)->save();

        $result = $log->restoreFromArchive();

        $this->assertSame($log, $result);
        $this->assertSame('archived value', $log->getMessage());
        $this->assertSame(5, $log->getIdOrder());
        $this->assertTrue($log->isModified());
    }

    /**
     * @return void
     */
    public function testArchivableRestoreWithoutArchiveThrows()
    {
        $log = new ContractOrderLog();
        $log->setMessage('never archived');
        $log->save();

        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        $log->restoreFromArchive();
    }

    /**
     * @return void
     */
    public function testArchivableArchiveOfNewObjectThrows()
    {
        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        (new ContractOrderLog())->archive();
    }
}
