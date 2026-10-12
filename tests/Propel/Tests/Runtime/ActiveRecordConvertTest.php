<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime;

use Propel\Runtime\Map\TableMap;
use Propel\Tests\Bookstore\Author;
use Propel\Tests\Bookstore\Book;
use Propel\Tests\Bookstore\Publisher;
use Propel\Tests\TestCaseFixtures;

/**
 * Test class for ActiveRecord.
 *
 * @author François Zaninotto
 */
class ActiveRecordConvertTest extends TestCaseFixtures
{
    /**
     * @var \Propel\Tests\Bookstore\Book
     */
    private $book;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $publisher = new Publisher();
        $publisher->setId(1234);
        $publisher->setName('Penguin');
        $author = new Author();
        $author->setId(5678);
        $author->setFirstName('George');
        $author->setLastName('Byron');
        $book = new Book();
        $book->setId(9012);
        $book->setTitle('Don Juan');
        $book->setISBN('0140422161');
        $book->setPrice(12.99);
        $book->setAuthor($author);
        $book->setPublisher($publisher);
        $this->book = $book;
    }

    public static function toJsonDataProvider()
    {
        $phpName = <<<EOF
{"Id":9012,"Title":"Don Juan","ISBN":"0140422161","Price":12.99,"PublisherId":1234,"AuthorId":5678,"Publisher":{"Id":1234,"Name":"Penguin","Books":[["*RECURSION*"]]},"Author":{"Id":5678,"FirstName":"George","LastName":"Byron","Email":null,"Age":null,"Books":[["*RECURSION*"]]}}
EOF;
        $camelName = <<<EOF
{"id":9012,"title":"Don Juan","iSBN":"0140422161","price":12.99,"publisherId":1234,"authorId":5678,"publisher":{"id":1234,"name":"Penguin","books":[["*RECURSION*"]]},"author":{"id":5678,"firstName":"George","lastName":"Byron","email":null,"age":null,"books":[["*RECURSION*"]]}}
EOF;

        $colName = <<<EOF
{"book.id":9012,"book.title":"Don Juan","book.isbn":"0140422161","book.price":12.99,"book.publisher_id":1234,"book.author_id":5678,"Publisher":{"publisher.id":1234,"publisher.name":"Penguin","Books":[["*RECURSION*"]]},"Author":{"author.id":5678,"author.first_name":"George","author.last_name":"Byron","author.email":null,"author.age":null,"Books":[["*RECURSION*"]]}}
EOF;

        $fieldName = <<<EOF
{"id":9012,"title":"Don Juan","isbn":"0140422161","price":12.99,"publisher_id":1234,"author_id":5678,"publisher":{"id":1234,"name":"Penguin","books":[["*RECURSION*"]]},"author":{"id":5678,"first_name":"George","last_name":"Byron","email":null,"age":null,"books":[["*RECURSION*"]]}}
EOF;

        return [[$phpName, TableMap::TYPE_PHPNAME],
                [$camelName, TableMap::TYPE_CAMELNAME],
                [$colName, TableMap::TYPE_COLNAME],
                [$fieldName, TableMap::TYPE_FIELDNAME]];
    }

    /**
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toJsonDataProvider')]
    public function testToJSON($expected, $type)
    {
        $this->assertEquals($expected, $this->book->toJSON(true, $type));
    }

    /**
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toJsonDataProvider')]
    public function testfromJSON($expected, $type)
    {
        $book = new Book();
        $book->fromJSON($expected, $type);
        // FIXME: fromArray() doesn't take related objects into account
        $book->resetModified();
        $author = $this->book->getAuthor();
        $this->book->setAuthor(null);
        $this->book->setAuthorId($author->getId());
        $publisher = $this->book->getPublisher();
        $this->book->setPublisher(null);
        $this->book->setPublisherId($publisher->getId());
        $this->book->resetModified();

        $this->assertEquals($this->book, $book);
    }

}
