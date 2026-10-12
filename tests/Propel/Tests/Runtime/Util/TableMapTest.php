<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\Util;

use Exception;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Propel;
use Propel\Tests\Bookstore\BookQuery;
use Propel\Tests\Bookstore\Bookstore;
use Propel\Tests\Bookstore\BookstoreQuery;
use Propel\Tests\Bookstore\Map\AuthorTableMap;
use Propel\Tests\Bookstore\Map\BookstoreTableMap;
use Propel\Tests\Bookstore\Map\BookTableMap;
use Propel\Tests\Bookstore\Map\PublisherTableMap;
use Propel\Tests\Helpers\Bookstore\BookstoreDataPopulator;
use Propel\Tests\Helpers\Bookstore\BookstoreTestBase;

/**
 * Tests the TableMap classes.
 *
 * @see BookstoreDataPopulator
 * @author Hans Lellelid <hans@xmpl.org>
 *
 * @group database
 */
class TableMapTest extends BookstoreTestBase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        BookstoreDataPopulator::depopulate();
        BookstoreDataPopulator::populate();
    }

    /**
     * @return void
     */
    public function testNeedsSelectAliases()
    {
        $c = new Criteria();
        $this->assertFalse($c->needsSelectAliases(), 'Empty Criterias don\'t need aliases');

        $c = new Criteria();
        $c->addSelectColumn(BookTableMap::COL_ID);
        $c->addSelectColumn(BookTableMap::COL_TITLE);
        $this->assertFalse($c->needsSelectAliases(), 'Criterias with distinct column names don\'t need aliases');

        $c = new Criteria();
        BookTableMap::addSelectColumns($c);
        $this->assertFalse($c->needsSelectAliases(), 'Criterias with only the columns of a model don\'t need aliases');

        $c = new Criteria();
        $c->addSelectColumn(BookTableMap::COL_ID);
        $c->addSelectColumn(AuthorTableMap::COL_ID);
        $this->assertTrue($c->needsSelectAliases(), 'Criterias with common column names do need aliases');
    }

    /**
     * @doesNotPerformAssertions
     *
     * @return void
     */
    public function testDoCountDuplicateColumnName()
    {
        $con = Propel::getServiceContainer()->getReadConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria();
        $c->addSelectColumn(BookTableMap::COL_ID);
        $c->addJoin(BookTableMap::COL_AUTHOR_ID, AuthorTableMap::COL_ID);
        $c->addSelectColumn(AuthorTableMap::COL_ID);
        $c->setLimit(3);
        try {
            $c->doCount($con);
        } catch (Exception $e) {
            $this->fail('doCount() cannot deal with a criteria selecting duplicate column names ');
        }
    }

    /**
     * @return void
     */
    public function testBigIntIgnoreCaseOrderBy()
    {
        BookstoreTableMap::doDeleteAll();

        // Some sample data
        $b = new Bookstore();
        $b->setStoreName('SortTest1')->setPopulationServed(2000)->save();

        $b = new Bookstore();
        $b->setStoreName('SortTest2')->setPopulationServed(201)->save();

        $b = new Bookstore();
        $b->setStoreName('SortTest3')->setPopulationServed(302)->save();

        $b = new Bookstore();
        $b->setStoreName('SortTest4')->setPopulationServed(10000000)->save();

        $c = new Criteria();
        $c->setIgnoreCase(true);
        $c->add(BookstoreTableMap::COL_STORE_NAME, 'SortTest%', Criteria::LIKE);
        $c->addAscendingOrderByColumn(BookstoreTableMap::COL_POPULATION_SERVED);

        $rows = BookstoreQuery::create(null, $c)->find();
        $this->assertEquals('SortTest2', $rows[0]->getStoreName());
        $this->assertEquals('SortTest3', $rows[1]->getStoreName());
        $this->assertEquals('SortTest1', $rows[2]->getStoreName());
        $this->assertEquals('SortTest4', $rows[3]->getStoreName());
    }

    /**
     * @return void
     */
    public function testMixedJoinOrder()
    {
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->addSelectColumn(BookTableMap::COL_ID);
        $c->addSelectColumn(BookTableMap::COL_TITLE);

        $c->addJoin(BookTableMap::COL_PUBLISHER_ID, PublisherTableMap::COL_ID, Criteria::LEFT_JOIN);
        $c->addJoin(BookTableMap::COL_AUTHOR_ID, AuthorTableMap::COL_ID);

        $params = [];
        $sql = $c->createSelectSql($params);
        $expectedSql = $this->getSql('SELECT book.id, book.title FROM book LEFT JOIN publisher ON (book.publisher_id=publisher.id) INNER JOIN author ON (book.author_id=author.id)');
        $this->assertEquals($expectedSql, $sql);
    }

    /**
     * @return void
     */
    public function testDoDeleteNoCondition()
    {
        $this->expectException(PropelException::class);

        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->doDelete($con);
    }

    /**
     * @return void
     */
    public function testDoDeleteJoin()
    {
        $this->expectException(PropelException::class);

        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->add(BookTableMap::COL_TITLE, 'War And Peace');
        $c->addJoin(BookTableMap::COL_AUTHOR_ID, AuthorTableMap::COL_ID);
        $c->doDelete($con);
    }

    /**
     * @return void
     */
    public function testDoDeleteSimpleCondition()
    {
        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->add(BookTableMap::COL_TITLE, 'War And Peace');
        $c->doDelete($con);
        $expectedSQL = $this->getSql("DELETE FROM book WHERE book.title='War And Peace'");
        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'doDelete() translates a condition into a WHERE');
    }

    /**
     * @return void
     */
    public function testDoDeleteSeveralConditions()
    {
        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->add(BookTableMap::COL_TITLE, 'War And Peace');
        $c->add(BookTableMap::COL_ID, 12);
        $c->doDelete($con);
        $expectedSQL = $this->getSql("DELETE FROM book WHERE book.title='War And Peace' AND book.id=12");
        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'doDelete() combines conditions in WHERE with an AND');
    }

    /**
     * @return void
     */
    public function testDoDeleteTableAlias()
    {
        if ($this->runningOnSQLite()) {
            $this->markTestSkipped('SQLite does not support Alias in Deletes');
        }
        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->addAlias('b', BookTableMap::TABLE_NAME);
        $c->add('b.title', 'War And Peace');
        $c->doDelete($con);

        $expectedSQL = $this->getSql("DELETE b FROM book AS b WHERE b.title='War And Peace'");

        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'doDelete() accepts a Criteria with a table alias');
    }

    /**
     * Not documented anywhere, and probably wrong
     *
     * @see http://www.propelorm.org/ticket/952
     *
     * @return void
     */
    public function testDoDeleteSeveralTables()
    {
        $con = Propel::getServiceContainer()->getWriteConnection(BookTableMap::DATABASE_NAME);
        $count = $con->getQueryCount();
        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->addFilter(BookTableMap::COL_TITLE, 'War And Peace');
        $c->addFilter(AuthorTableMap::COL_FIRST_NAME, 'Leo');
        $this->expectException(\Propel\Runtime\ActiveQuery\QueryExecutor\QueryExecutionException::class);
        $c->doDelete($con);
        /*
        $expectedSQL = $this->getSql("DELETE FROM author WHERE author.first_name='Leo'");
        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'doDelete() issues two DELETE queries when passed conditions on two tables');
        $this->assertEquals($count + 2, $con->getQueryCount(), 'doDelete() issues two DELETE queries when passed conditions on two tables');

        $c = new Criteria(BookTableMap::DATABASE_NAME);
        $c->add(AuthorTableMap::COL_FIRST_NAME, 'Leo');
        $c->add(BookTableMap::COL_TITLE, 'War And Peace');
        $c->doDelete($con);
        $expectedSQL = $this->getSql("DELETE FROM book WHERE book.title='War And Peace'");
        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'doDelete() issues two DELETE queries when passed conditions on two tables');
        $this->assertEquals($count + 4, $con->getQueryCount(), 'doDelete() issues two DELETE queries when passed conditions on two tables');
        */
    }

    /**
     * @return void
     */
    public function testCommentDoSelect()
    {
        $c = new Criteria();
        $c->setComment('Foo');
        $c->addSelectColumn(BookTableMap::COL_ID);
        $expected = $this->getSql('SELECT /* Foo */ book.id FROM book');
        $params = [];
        $this->assertEquals($expected, $c->createSelectSQL($params), 'Criteria::setComment() adds a comment to select queries');
    }

    /**
     * @return void
     */
    public function testCommentDoUpdate()
    {
        $c1 = new Criteria();
        $c1->setPrimaryTableName(BookTableMap::TABLE_NAME);
        $c1->setComment('Foo');
        $c2 = new Criteria();
        $c2->setUpdateValue(BookTableMap::COL_TITLE, 'Updated Title', \PDO::PARAM_STR);
        $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $c1->doUpdate($c2, $con);
        $expected = $this->getSql('UPDATE /* Foo */ book SET title=\'Updated Title\'');
        $this->assertEquals($expected, $con->getLastExecutedQuery(), 'Criteria::setComment() adds a comment to update queries');
    }

    /**
     * @return void
     */
    public function testCommentDoDelete()
    {
        $c = new Criteria();
        $c->setComment('Foo');
        $c->add(BookTableMap::COL_TITLE, 'War And Peace');
        $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $c->doDelete($con);
        $expected = $this->getSql('DELETE /* Foo */ FROM book WHERE book.title=\'War And Peace\'');
        $this->assertEquals($expected, $con->getLastExecutedQuery(), 'Criteria::setComment() adds a comment to delete queries');
    }

    /**
     * @return void
     */
    public function testIneffectualUpdateUsingBookObject()
    {
        $con = Propel::getConnection(BookTableMap::DATABASE_NAME);
        $book = BookQuery::create()->findOne($con);
        $count = $con->getQueryCount();
        $book->setTitle($book->getTitle());
        $book->setISBN($book->getISBN());

        try {
            $rowCount = $book->save($con);
            $this->assertEquals(0, $rowCount, 'save() should indicate zero rows updated');
        } catch (Exception $ex) {
            $this->fail('save() threw an exception');
        }

        $this->assertEquals($count, $con->getQueryCount(), 'save() does not execute any queries when there are no changes');
    }
}
