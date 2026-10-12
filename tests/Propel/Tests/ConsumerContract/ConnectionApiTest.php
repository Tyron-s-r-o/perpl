<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractCategoryQuery;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Connection\Exception\RollbackException;
use Propel\Runtime\Connection\StatementInterface;
use Propel\Runtime\DataFetcher\DataFetcherInterface;
use Propel\Runtime\Perpl;
use Propel\Runtime\Propel;

/**
 * Pins the connection API used by the consumer (service container accessors, transactions, raw SQL).
 */
class ConnectionApiTest extends ConsumerContractTestCase
{
    /**
     * @return \Propel\Runtime\Connection\ConnectionInterface
     */
    private function con(): ConnectionInterface
    {
        return Perpl::getConnection('contract');
    }

    /**
     * @return void
     */
    public function testPerplGetConnectionReturnsConnectionWrapper()
    {
        $con = Perpl::getConnection('contract');

        $this->assertInstanceOf(ConnectionInterface::class, $con);
        $this->assertInstanceOf(ConnectionWrapper::class, $con);
        $this->assertSame($con, Perpl::getConnection('contract'), 'the same connection on every call');
    }

    /**
     * @return void
     */
    public function testPropelFacadeReturnsTheSameConnection()
    {
        $this->assertSame(Perpl::getConnection('contract'), Propel::getConnection('contract'));
    }

    /**
     * @return void
     */
    public function testReadAndWriteConnectionAreTheSameConnectionWithoutReplication()
    {
        $this->assertSame(Perpl::getConnection('contract'), Perpl::getReadConnection('contract'));
        $this->assertSame(Perpl::getConnection('contract'), Perpl::getWriteConnection('contract'));
    }

    /**
     * @return void
     */
    public function testServiceContainerAccessors()
    {
        $container = Perpl::getServiceContainer();

        $this->assertSame($this->con(), $container->getReadConnection('contract'));
        $this->assertSame($this->con(), $container->getWriteConnection('contract'));
        $this->assertSame($this->con(), $container->getConnection('contract'));
        $this->assertSame('contract', Perpl::getDatabaseMap('contract')->getName());
    }

    /**
     * @return void
     */
    public function testGetConnectionOfUnknownDatabaseThrows()
    {
        $this->expectException(\Propel\Runtime\Exception\RuntimeException::class);
        Perpl::getConnection('no_such_database');
    }

    /**
     * @return void
     */
    public function testCommitPersistsChanges()
    {
        $con = $this->con();

        $this->assertFalse($con->inTransaction());
        $this->assertTrue($con->beginTransaction());
        $this->assertTrue($con->inTransaction());
        $con->exec("INSERT INTO cc_category (name) VALUES ('committed')");
        $this->assertTrue($con->commit());

        $this->assertFalse($con->inTransaction());
        $this->assertSame(1, ContractCategoryQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testRollBackDiscardsChanges()
    {
        $con = $this->con();

        $con->beginTransaction();
        $con->exec("INSERT INTO cc_category (name) VALUES ('rolled back')");
        $this->assertTrue($con->rollBack());

        $this->assertFalse($con->inTransaction());
        $this->assertSame(0, ContractCategoryQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testNestedBeginCommitOnlyCommitsAtOutermostLevel()
    {
        $con = $this->con();

        $con->beginTransaction();
        $this->assertSame(1, $con->getNestedTransactionCount());
        $con->beginTransaction();
        $this->assertSame(2, $con->getNestedTransactionCount());
        $con->exec("INSERT INTO cc_category (name) VALUES ('nested')");
        $con->commit();

        $this->assertTrue($con->inTransaction(), 'inner commit keeps the transaction open');
        $this->assertSame(1, $con->getNestedTransactionCount());
        $con->commit();
        $this->assertFalse($con->inTransaction());
        $this->assertSame(1, ContractCategoryQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testRollBackOfOuterTransactionDiscardsNestedWork()
    {
        $con = $this->con();

        $con->beginTransaction();
        $con->beginTransaction();
        $con->exec("INSERT INTO cc_category (name) VALUES ('nested')");
        $con->commit();
        $con->rollBack();

        $this->assertFalse($con->inTransaction());
        $this->assertSame(0, ContractCategoryQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testCommitAfterNestedRollBackThrowsAndKeepsTransactionOpen()
    {
        $con = $this->con();

        $con->beginTransaction();
        $con->beginTransaction();
        $con->exec("INSERT INTO cc_category (name) VALUES ('doomed')");
        $con->rollBack();
        try {
            $con->commit();
            $this->fail('RollbackException expected');
        } catch (RollbackException $e) {
            $this->assertSame('Cannot commit because a nested transaction was rolled back', $e->getMessage());
        }

        $this->assertTrue($con->inTransaction(), 'the caller has to roll back explicitly');
        $con->rollBack();
        $this->assertFalse($con->inTransaction());
        $this->assertSame(0, ContractCategoryQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testTransactionHelperCommitsOnSuccessAndRollsBackOnException()
    {
        $con = $this->con();

        $result = $con->transaction(function () use ($con) {
            $con->exec("INSERT INTO cc_category (name) VALUES ('ok')");

            return 'done';
        });
        $this->assertSame('done', $result);
        $this->assertSame(1, ContractCategoryQuery::create()->count());

        try {
            $con->transaction(function () use ($con) {
                $con->exec("INSERT INTO cc_category (name) VALUES ('fail')");

                throw new \RuntimeException('boom');
            });
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertFalse($con->inTransaction());
        $this->assertSame(1, ContractCategoryQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testModelSaveInsideOuterTransactionIsRolledBackWithIt()
    {
        $con = $this->con();

        $con->beginTransaction();
        $this->createCategory('inside');
        $this->assertSame(1, ContractCategoryQuery::create()->count());
        $con->rollBack();

        $this->assertSame(0, ContractCategoryQuery::create()->count());
    }

    /**
     * @return void
     */
    public function testPrepareExecuteAndFetchAllAssoc()
    {
        $this->createCategory('one');
        $this->createCategory('two');
        $con = $this->con();

        $statement = $con->prepare('SELECT id, name FROM cc_category WHERE name = :name');
        $this->assertInstanceOf(StatementInterface::class, $statement);
        $this->assertTrue($statement->execute([':name' => 'two']));
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame([['id' => 2, 'name' => 'two']], $rows);
    }

    /**
     * @return void
     */
    public function testPrepareWithPositionalParametersAndBindValue()
    {
        $this->createCategory('one');
        $con = $this->con();

        $statement = $con->prepare('SELECT name FROM cc_category WHERE id = ?');
        $statement->bindValue(1, 1);
        $statement->execute();

        $this->assertSame([['name' => 'one']], $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return void
     */
    public function testPrepareFetchColumnAndRowCount()
    {
        $this->createCategory('one');
        $this->createCategory('two');
        $con = $this->con();

        $select = $con->prepare('SELECT COUNT(*) FROM cc_category');
        $select->execute();
        $this->assertSame(2, (int)$select->fetchColumn());

        $update = $con->prepare("UPDATE cc_category SET name = 'x'");
        $update->execute();
        $this->assertSame(2, $update->rowCount());
    }

    /**
     * @return void
     */
    public function testQueryReturnsDataFetcherWithFetchAll()
    {
        $this->createCategory('one');
        $con = $this->con();

        $result = $con->query('SELECT id, name FROM cc_category');

        $this->assertInstanceOf(DataFetcherInterface::class, $result);
        $this->assertSame([['id' => 1, 'name' => 'one']], $result->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return void
     */
    public function testQueryFetchColumnAndFetchAssoc()
    {
        $this->createCategory('one');
        $con = $this->con();

        $this->assertSame(1, (int)$con->query('SELECT COUNT(*) FROM cc_category')->fetchColumn());
        $this->assertSame(['id' => 1, 'name' => 'one'], $con->query('SELECT id, name FROM cc_category')->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * @return void
     */
    public function testExecReturnsAffectedRows()
    {
        $this->createCategory('one');
        $this->createCategory('two');
        $con = $this->con();

        $this->assertSame(2, $con->exec("UPDATE cc_category SET name = 'same'"));
        $this->assertSame(1, $con->exec("INSERT INTO cc_category (name) VALUES ('three')"));
        $this->assertSame(0, $con->exec("DELETE FROM cc_category WHERE name = 'nope'"));
        $this->assertSame(3, $con->exec('DELETE FROM cc_category'));
    }

    /**
     * @return void
     */
    public function testLastInsertIdIsStringOfGeneratedId()
    {
        $con = $this->con();

        $con->exec("INSERT INTO cc_category (name) VALUES ('a')");
        $first = $con->lastInsertId();
        $con->exec("INSERT INTO cc_category (name) VALUES ('b')");

        $this->assertSame('1', $first);
        $this->assertSame('2', $con->lastInsertId());
    }

    /**
     * @return void
     */
    public function testRawExecErrorThrowsPdoException()
    {
        $this->expectException(\PDOException::class);
        $this->con()->exec('INSERT INTO no_such_table VALUES (1)');
    }

    /**
     * @return void
     */
    public function testDebugModeTracksLastQueryAndCount()
    {
        $con = $this->getDebugConnection();
        $before = $con->getQueryCount();

        $con->query('SELECT 1');
        $this->assertSame('SELECT 1', $con->getLastExecutedQuery());
        $con->exec('SELECT 2');
        $this->assertSame('SELECT 2', $con->getLastExecutedQuery());

        $this->assertSame($before + 2, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testDebugLogOfRawPreparedStatementKeepsPlaceholders()
    {
        $con = $this->getDebugConnection();

        $statement = $con->prepare('SELECT :v AS v');
        $statement->bindValue(':v', 'abc');
        $statement->execute();

        $this->assertSame('SELECT :v AS v', $con->getLastExecutedQuery(), 'only the ORM own statements are logged with values');
    }

    /**
     * @return void
     */
    public function testDebugModeIsOffByDefaultAfterTearDown()
    {
        $con = $this->con();
        $con->useDebug(false);
        $count = $con->getQueryCount();

        $con->query('SELECT 1');

        $this->assertSame($count, $con->getQueryCount());
    }
}
