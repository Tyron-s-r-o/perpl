<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\Collection;

use Propel\Runtime\Collection\ObjectCollection;
use Propel\Tests\Bookstore\Book;
use Propel\Tests\Bookstore\Publisher;
use Propel\Tests\TestCaseFixtures;

/**
 * Test class for Collection.
 *
 * @author Francois Zaninotto
 */
class CollectionConvertTest extends TestCaseFixtures
{
    private $coll;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $book1 = new Book();
        $book1->setId(9012);
        $book1->setTitle('Don Juan');
        $book1->setISBN('0140422161');
        $book1->setPrice(12.99);
        $book1->setAuthorId(5678);
        $book1->setPublisherId(1234);
        $book1->resetModified();
        $book2 = new Book();
        $book2->setId(58);
        $book2->setTitle('Harry Potter and the Order of the Phoenix');
        $book2->setISBN('043935806X');
        $book2->setPrice(10.99);
        $book2->resetModified();

        $this->coll = new ObjectCollection();
        $this->coll->setModel('\Propel\Tests\Bookstore\Book');
        $this->coll[] = $book1;
        $this->coll[] = $book2;
    }

    public static function toJsonDataProvider()
    {
        $expected = <<<EOF
{"Books":[{"Id":9012,"Title":"Don Juan","ISBN":"0140422161","Price":12.99,"PublisherId":1234,"AuthorId":5678},{"Id":58,"Title":"Harry Potter and the Order of the Phoenix","ISBN":"043935806X","Price":10.99,"PublisherId":null,"AuthorId":null}]}
EOF;

        return [[$expected]];
    }

    /**
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toJsonDataProvider')]
    public function testToJSON($expected)
    {
        $this->assertEquals($expected, $this->coll->toJSON());
    }

    /**
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toJsonDataProvider')]
    public function testfromJSON($expected)
    {
        $coll = new ObjectCollection();
        $coll->setModel('\Propel\Tests\Bookstore\Book');
        $coll->fromJSON($expected);
        // fix modified columns order
        foreach ($coll as $book) {
            $book->resetModified();
        }

        $this->assertEquals($this->coll->getData(), $coll->getData());
    }

    /**
     * @return void
     */
    public function testToStringUsesDefaultStringFormat()
    {
        $this->assertEquals($this->coll->toJSON(), (string)$this->coll, 'Collection::__toString() uses the JSON representation by default');
    }

    /**
     * @return void
     */
    public function testToStringUsesCustomStringFormat()
    {
        $coll = new ObjectCollection();
        $coll->setModel('\Propel\Tests\Bookstore\Publisher');
        $publisher = new Publisher();
        $publisher->setId(12345);
        $publisher->setName('Penguinoo');
        $coll[] = $publisher;
        $expected = '{"Publishers":[{"Id":12345,"Name":"Penguinoo"}]}';
        $this->assertEquals($expected, (string)$coll);
    }

    /**
     * @return void
     */
    public function testRemovedFormatsThrowException()
    {
        $this->expectException(\Propel\Runtime\Exception\FileNotFoundException::class);

        $this->coll->toXML();
    }
}
