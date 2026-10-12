<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Helpers\Bookstore;

use Propel\Runtime\Propel;
use Propel\Tests\Bookstore\AcctAccessRole;
use Propel\Tests\Bookstore\Author;
use Propel\Tests\Bookstore\Book;
use Propel\Tests\Bookstore\BookClubList;
use Propel\Tests\Bookstore\BookListRel;
use Propel\Tests\Bookstore\BookOpinion;
use Propel\Tests\Bookstore\BookQuery;
use Propel\Tests\Bookstore\BookReader;
use Propel\Tests\Bookstore\Bookstore;
use Propel\Tests\Bookstore\BookstoreEmployee;
use Propel\Tests\Bookstore\BookstoreEmployeeAccount;
use Propel\Tests\Bookstore\BookSummary;
use Propel\Tests\Bookstore\Map\BookTableMap;
use Propel\Tests\Bookstore\Media;
use Propel\Tests\Bookstore\Publisher;
use Propel\Tests\Bookstore\ReaderFavorite;
use Propel\Tests\Bookstore\RecordLabel;
use Propel\Tests\Bookstore\ReleasePool;
use Propel\Tests\Bookstore\Review;

define('_LOB_SAMPLE_FILE_PATH', __DIR__ . '/../../../../Fixtures/etc/lob');

/**
 * Populates data needed by the bookstore unit tests.
 *
 * This classes uses the actual Propel objects to do the population rather than
 * inserting directly into the database. This will have a performance hit, but will
 * benefit from increased flexibility (as does anything using Propel).
 *
 * @author Hans Lellelid <hans@xmpl.org>
 */
class BookstoreDataPopulator
{
    /**
     * @return void
     */
    public static function populate($con = null)
    {
        if ($con === null) {
            $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        }
        $con->beginTransaction();

        $scholastic = (new Publisher())
            ->setName('Scholastic')
        ;
        // do not save, will do later to test cascade

        $morrow = (new Publisher())
            ->setName('William Morrow')
        ;
        $morrow->save($con);

        $penguin = (new Publisher())
            ->setName('Penguin')
        ;
        $penguin->save();

        $vintage = (new Publisher())
            ->setName('Vintage')
        ;
        $vintage->save($con);

        $rowling = (new Author())
            ->setFirstName('J.K.')
            ->setLastName('Rowling')
        ;

        $stephenson = (new Author())
            ->setFirstName('Neal')
            ->setLastName('Stephenson')
        ;
        $stephenson->save($con);

        $byron = (new Author())
            ->setFirstName('George')
            ->setLastName('Byron')
        ;
        $byron->save($con);

        $grass = (new Author())
            ->setFirstName('Gunter')
            ->setLastName('Grass')
        ;
        $grass->save($con);

        $phoenix = (new Book())
            ->setTitle('Harry Potter and the Order of the Phoenix')
            ->setISBN('043935806X')
            ->setAuthor($rowling)
            ->setPublisher($scholastic)
            ->setPrice(10.99)
        ;
        $phoenix->save($con);

        $qs = (new Book())
            ->setISBN('0380977427')
            ->setTitle('Quicksilver')
            ->setPrice(11.99)
            ->setAuthor($stephenson)
            ->setPublisher($morrow)
        ;
        $qs->save($con);

        $dj = (new Book())
            ->setISBN('0140422161')
            ->setTitle('Don Juan')
            ->setPrice(12.99)
            ->setAuthor($byron)
            ->setPublisher($penguin)
        ;
        $dj->save($con);

        $td = (new Book())
            ->setISBN('067972575X')
            ->setTitle('The Tin Drum')
            ->setPrice(13.99)
            ->setAuthor($grass)
            ->setPublisher($vintage)
        ;
        $td->save($con);

        $r1 = (new Review())
            ->setBook($phoenix)
            ->setReviewedBy('Washington Post')
            ->setRecommended(true)
            ->setReviewDate(time())
        ;
        $r1->save($con);

        $r2 = (new Review())
            ->setBook($phoenix)
            ->setReviewedBy('New York Times')
            ->setRecommended(false)
            ->setReviewDate(time())
        ;
        $r2->save($con);

        $blob_path = _LOB_SAMPLE_FILE_PATH . '/tin_drum.gif';
        $clob_path = _LOB_SAMPLE_FILE_PATH . '/tin_drum.txt';

        $m1 = (new Media())
            ->setBook($td)
            ->setCoverImage(file_get_contents($blob_path))
        ;
        $m1->setExcerpt(file_get_contents($clob_path));
        $m1->save($con);

        // Add book list records
        // ---------------------
        // (this is for many-to-many tests)

        $brel1 = (new BookListRel())
            ->setBook($phoenix)
        ;

        $brel2 = (new BookListRel())
            ->setBook($dj)
        ;
        $blc1 = (new BookClubList())
            ->setGroupLeader('Crazyleggs')
            ->setTheme('Happiness')
            ->addBookListRel($brel1)
            ->addBookListRel($brel2)
        ;
        $blc1->save();

        $bemp1 = (new BookstoreEmployee())
            ->setName('John')
            ->setJobTitle('Manager')
            ;

        $bemp2 = (new BookstoreEmployee())
            ->setName('Pieter')
            ->setJobTitle('Clerk')
            ->setSupervisor($bemp1)
            ;
        $bemp2->save($con);

        $role = (new AcctAccessRole())
            ->setName('Admin')
            ;

        $bempacct = (new BookstoreEmployeeAccount())
            ->setBookstoreEmployee($bemp1)
            ->setAcctAccessRole($role)
            ->setLogin('john')
            ->setPassword('johnp4ss')
            ;
        $bempacct->save($con);

        // Add bookstores

        $store = (new Bookstore())
            ->setStoreName('Amazon')
            ->setPopulationServed(5000000000) // world population
            ->setTotalBooks(300)
            ;
        $store->save($con);

        $store = (new Bookstore())
            ->setStoreName('Local Store')
            ->setPopulationServed(20)
            ->setTotalBooks(500000)
            ;
        $store->save($con);

        $summary = (new BookSummary())
            ->setSummarizedBook($phoenix)
            ->setSummary('Harry Potter does some amazing magic!')
            ;
        $summary->save();

        // Add release_pool and record_label
        $acuna = (new RecordLabel())
            ->setAbbr('acuna')
            ->setName('Acunadeep')
            ;
        $acuna->save();

        $fade = (new RecordLabel())
            ->setAbbr('fade')
            ->setName('Fade Records')
            ;
        $fade->save();

        $pool = (new ReleasePool())
            ->setName('D.Chmelyuk - Revert Me Back')
            ->setRecordLabel($acuna)
            ;
        $pool->save();

        $pool = (new ReleasePool())
            ->setName('VIF & Lola Palmer - Dreamer')
            ->setRecordLabel($acuna)
            ;
        $pool->save();

        $pool = (new ReleasePool())
            ->setName('Lola Palmer - Do You Belong To Me')
            ->setRecordLabel($acuna)
            ;
        $pool->save();

        $pool = (new ReleasePool())
            ->setName('Chris Forties - Despegue (foem.info Runners Up Remixes)')
            ->setRecordLabel($fade)
            ;
        $pool->save();

        $con->commit();
    }

    /**
     * @return void
     */
    public static function populateOpinionFavorite($con = null)
    {
        if ($con === null) {
            $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        }
        $con->beginTransaction();

        $book1 = BookQuery::create()->findOne($con);
        $reader1 = new BookReader();
        $reader1->save($con);

        $bo = (new BookOpinion())
            ->setBook($book1)
            ->setBookReader($reader1)
            ;
        $bo->save($con);

        $rf = (new ReaderFavorite())
            ->setBookOpinion($bo);
        $rf->save($con);

        $con->commit();
    }

    /**
     * @return void
     */
    public static function depopulate($con = null)
    {
        $tableMapClasses = [
            'Propel\Tests\Bookstore\Map\AuthorTableMap',
            'Propel\Tests\Bookstore\Map\BookstoreTableMap',
            'Propel\Tests\Bookstore\Map\BookstoreContestTableMap',
            'Propel\Tests\Bookstore\Map\BookstoreContestEntryTableMap',
            'Propel\Tests\Bookstore\Map\BookstoreEmployeeTableMap',
            'Propel\Tests\Bookstore\Map\BookstoreEmployeeAccountTableMap',
            'Propel\Tests\Bookstore\Map\BookstoreSaleTableMap',
            'Propel\Tests\Bookstore\Map\BookClubListTableMap',
            'Propel\Tests\Bookstore\Map\BookOpinionTableMap',
            'Propel\Tests\Bookstore\Map\BookReaderTableMap',
            'Propel\Tests\Bookstore\Map\BookListRelTableMap',
            'Propel\Tests\Bookstore\Map\BookTableMap',
            'Propel\Tests\Bookstore\Map\ContestTableMap',
            'Propel\Tests\Bookstore\Map\CustomerTableMap',
            'Propel\Tests\Bookstore\Map\MediaTableMap',
            'Propel\Tests\Bookstore\Map\PublisherTableMap',
            'Propel\Tests\Bookstore\Map\ReaderFavoriteTableMap',
            'Propel\Tests\Bookstore\Map\ReviewTableMap',
            'Propel\Tests\Bookstore\Map\BookSummaryTableMap',
            'Propel\Tests\Bookstore\Map\RecordLabelTableMap',
            'Propel\Tests\Bookstore\Map\ReleasePoolTableMap',
        ];
        // free the memory from existing objects
        foreach ($tableMapClasses as $tableMapClass) {
            foreach ($tableMapClass::$instances as $i) {
                $i->clearAllReferences();
            }
            $tableMapClass::doDeleteAll($con);
        }
    }
}
