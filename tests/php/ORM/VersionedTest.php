<?php

namespace SilverStripe\ORM\Tests;

use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Versioning\Versioned;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Core\Convert;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Control\Director;
use SilverStripe\Control\Session;
use DateTime;

class VersionedTest extends SapphireTest
{

    protected static $fixture_file = 'VersionedTest.yml';

    public static $extra_data_objects = [
        VersionedTest\TestObject::class,
        VersionedTest\Subclass::class,
        VersionedTest\AnotherSubclass::class,
        VersionedTest\RelatedWithoutversion::class,
        VersionedTest\SingleStage::class,
        VersionedTest\WithIndexes::class,
        VersionedTest\PublicStage::class,
        VersionedTest\PublicViaExtension::class,
        VersionedTest\CustomTable::class,
    ];

    protected function getExtraDataObjects()
    {
        return static::$extra_data_objects;
    }

    public function testUniqueIndexes()
    {
        $tableExpectations = array(
            'VersionedTest_WithIndexes' =>
                array('value' => true, 'message' => 'Unique indexes are unique in main table'),
            'VersionedTest_WithIndexes_Versions' =>
                array('value' => false, 'message' => 'Unique indexes are no longer unique in _Versions table'),
            'VersionedTest_WithIndexes_Live' =>
                array('value' => true, 'message' => 'Unique indexes are unique in _Live table'),
        );

        // Test each table's performance
        foreach ($tableExpectations as $tableName => $expectation) {
            $indexes = DB::get_schema()->indexList($tableName);

            // Check for presence of all unique indexes
            $indexColumns = array_map(
                function ($index) {
                    return $index['value'];
                },
                $indexes
            );
            sort($indexColumns);
            $expectedColumns = array('"UniqA"', '"UniqS"');
            $this->assertEquals(
                array_values($expectedColumns),
                array_values(array_intersect($indexColumns, $expectedColumns)),
                "$tableName has both indexes"
            );

            // Check unique -> non-unique conversion
            foreach ($indexes as $indexKey => $indexSpec) {
                if (in_array($indexSpec['value'], $expectedColumns)) {
                    $isUnique = $indexSpec['type'] === 'unique';
                    $this->assertEquals($isUnique, $expectation['value'], $expectation['message']);
                }
            }
        }
    }

    public function testDeletingOrphanedVersions()
    {
        $obj = new VersionedTest\Subclass();
        $obj->ExtraField = 'Foo'; // ensure that child version table gets written
        $obj->write();
        $obj->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $obj->ExtraField = 'Bar'; // ensure that child version table gets written
        $obj->write();
        $obj->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $versions = DB::query(
            "SELECT COUNT(*) FROM \"VersionedTest_Subclass_Versions\""
            . " WHERE \"RecordID\" = '$obj->ID'"
        )->value();

        $this->assertGreaterThan(0, $versions, 'At least 1 version exists in the history of the page');

        // Force orphaning of all versions created earlier, only on parent record.
        // The child versiones table should still have the correct relationship
        DB::query("DELETE FROM \"VersionedTest_DataObject_Versions\" WHERE \"RecordID\" = $obj->ID");

        // insert a record with no primary key (ID)
        DB::query("INSERT INTO \"VersionedTest_DataObject_Versions\" (\"RecordID\") VALUES ($obj->ID)");

        // run the script which should clean that up
        $obj->augmentDatabase();

        $versions = DB::query(
            "SELECT COUNT(*) FROM \"VersionedTest_Subclass_Versions\""
            . " WHERE \"RecordID\" = '$obj->ID'"
        )->value();
        $this->assertEquals(0, $versions, 'Orphaned versions on child tables are removed');

        // test that it doesn't delete records that we need
        $obj->write();
        $obj->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $count = DB::query(
            "SELECT COUNT(*) FROM \"VersionedTest_Subclass_Versions\""
            . " WHERE \"RecordID\" = '$obj->ID'"
        )->value();
        $obj->augmentDatabase();

        $count2 = DB::query(
            "SELECT COUNT(*) FROM \"VersionedTest_Subclass_Versions\""
            . " WHERE \"RecordID\" = '$obj->ID'"
        )->value();

        $this->assertEquals($count, $count2);
    }

    public function testCustomTable()
    {
        $obj = new VersionedTest\CustomTable();
        $obj->Title = 'my object';
        $obj->write();
        $id = $obj->ID;
        $obj->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $obj->Title = 'new title';
        $obj->write();

        $liveRecord = Versioned::get_by_stage(VersionedTest\CustomTable::class, Versioned::LIVE)->byID($id);
        $draftRecord = Versioned::get_by_stage(VersionedTest\CustomTable::class, Versioned::DRAFT)->byID($id);

        $this->assertEquals('my object', $liveRecord->Title);
        $this->assertEquals('new title', $draftRecord->Title);
    }

    /**
     * Test that publishing from invalid stage will throw exception
     */
    public function testInvalidPublish()
    {
        $obj = new VersionedTest\Subclass();
        $obj->ExtraField = 'Foo'; // ensure that child version table gets written
        $obj->write();
        $class = VersionedTest\TestObject::class;
        $this->setExpectedException(
            'InvalidArgumentException',
            "Can't find {$class}#{$obj->ID} in stage Live"
        );

        // Fail publishing from live to stage
        $obj->copyVersionToStage(Versioned::LIVE, Versioned::DRAFT);
    }

    public function testDuplicate()
    {
        $obj1 = new VersionedTest\Subclass();
        $obj1->ExtraField = 'Foo';
        $obj1->write(); // version 1
        $obj1->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $obj1->ExtraField = 'Foo2';
        $obj1->write(); // version 2

        // Make duplicate
        $obj2 = $obj1->duplicate();

        // Check records differ
        $this->assertNotEquals($obj1->ID, $obj2->ID);
        $this->assertEquals(2, $obj1->Version);
        $this->assertEquals(1, $obj2->Version);
    }

    public function testForceChangeUpdatesVersion()
    {
        $obj = new VersionedTest\TestObject();
        $obj->Name = "test";
        $obj->write();

        $oldVersion = $obj->Version;
        $obj->forceChange();
        $obj->write();

        $this->assertTrue(
            ($obj->Version > $oldVersion),
            "A object Version is increased when just calling forceChange() without any other changes"
        );
    }

    /**
     * Test Versioned::get_including_deleted()
     */
    public function testGetIncludingDeleted()
    {
        // Get all ids of pages
        $allPageIDs = DataObject::get(
            VersionedTest\TestObject::class,
            "\"ParentID\" = 0",
            "\"VersionedTest_DataObject\".\"ID\" ASC"
        )->column('ID');

        // Modify a page, ensuring that the Version ID and Record ID will differ,
        // and then subsequently delete it
        $targetPage = $this->objFromFixture(VersionedTest\TestObject::class, 'page3');
        $targetPage->Content = 'To be deleted';
        $targetPage->write();
        $targetPage->delete();

        // Get all items, ignoring deleted
        $remainingPages = DataObject::get(
            VersionedTest\TestObject::class,
            "\"ParentID\" = 0",
            "\"VersionedTest_DataObject\".\"ID\" ASC"
        );
        // Check that page 3 has gone
        $this->assertNotNull($remainingPages);
        $this->assertEquals(array("Page 1", "Page 2", "Subclass Page 1"), $remainingPages->column('Title'));

        // Get all including deleted
        $allPages = Versioned::get_including_deleted(
            VersionedTest\TestObject::class,
            "\"ParentID\" = 0",
            "\"VersionedTest_DataObject\".\"ID\" ASC"
        );
        // Check that page 3 is still there
        $this->assertEquals(array("Page 1", "Page 2", "Page 3", "Subclass Page 1"), $allPages->column('Title'));

        // Check that the returned pages have the correct IDs
        $this->assertEquals($allPageIDs, $allPages->column('ID'));

        // Check that this still works if we switch to reading the other stage
        Versioned::set_stage(Versioned::LIVE);
        $allPages = Versioned::get_including_deleted(
            VersionedTest\TestObject::class,
            "\"ParentID\" = 0",
            "\"VersionedTest_DataObject\".\"ID\" ASC"
        );
        $this->assertEquals(array("Page 1", "Page 2", "Page 3", "Subclass Page 1"), $allPages->column('Title'));

        // Check that the returned pages still have the correct IDs
        $this->assertEquals($allPageIDs, $allPages->column('ID'));
    }

    public function testVersionedFieldsAdded()
    {
        $obj = new VersionedTest\TestObject();
        // Check that the Version column is added as a full-fledged column
        $this->assertInstanceOf('SilverStripe\\ORM\\FieldType\\DBInt', $obj->dbObject('Version'));

        $obj2 = new VersionedTest\Subclass();
        // Check that the Version column is added as a full-fledged column
        $this->assertInstanceOf('SilverStripe\\ORM\\FieldType\\DBInt', $obj2->dbObject('Version'));
    }

    public function testVersionedFieldsNotInCMS()
    {
        $obj = new VersionedTest\TestObject();

        // the version field in cms causes issues with Versioned::augmentWrite()
        $this->assertNull($obj->getCMSFields()->dataFieldByName('Version'));
    }

    public function testPublishCreateNewVersion()
    {
        /** @var VersionedTest\TestObject $page1 */
        $page1 = $this->objFromFixture(VersionedTest\TestObject::class, 'page1');
        $page1->Content = 'orig';
        $page1->write();
        $firstVersion = $page1->Version;
        $page1->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE, false);
        $this->assertEquals(
            $firstVersion,
            $page1->Version,
            'publish() with $createNewVersion=FALSE does not create a new version'
        );

        $page1->Content = 'changed';
        $page1->write();
        $secondVersion = $page1->Version;
        $this->assertTrue($firstVersion < $secondVersion, 'write creates new version');

        $page1->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE, true);
        $thirdVersion = Versioned::get_latest_version(VersionedTest\TestObject::class, $page1->ID)->Version;
        $liveVersion = Versioned::get_versionnumber_by_stage(VersionedTest\TestObject::class, 'Live', $page1->ID);
        $stageVersion = Versioned::get_versionnumber_by_stage(VersionedTest\TestObject::class, 'Stage', $page1->ID);
        $this->assertTrue(
            $secondVersion < $thirdVersion,
            'publish() with $createNewVersion=TRUE creates a new version'
        );
        $this->assertEquals(
            $liveVersion,
            $thirdVersion,
            'publish() with $createNewVersion=TRUE publishes to live'
        );
        $this->assertEquals(
            $stageVersion,
            $thirdVersion,
            'publish() with $createNewVersion=TRUE also updates draft'
        );
    }

    public function testRollbackTo()
    {
        $page1 = $this->objFromFixture(VersionedTest\AnotherSubclass::class, 'subclass1');
        $page1->Content = 'orig';
        $page1->write();
        $page1->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $origVersion = $page1->Version;

        $page1->Content = 'changed';
        $page1->write();
        $page1->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $changedVersion = $page1->Version;

        $page1->doRollbackTo($origVersion);
        $page1 = Versioned::get_one_by_stage(
            VersionedTest\TestObject::class,
            'Stage',
            array(
            '"VersionedTest_DataObject"."ID" = ?' => $page1->ID
            )
        );

        $this->assertTrue($page1->Version == $changedVersion + 1, 'Create a new higher version number');
        $this->assertEquals('orig', $page1->Content, 'Copies the content from the old version');

        // check db entries
        $version = DB::prepared_query(
            "SELECT MAX(\"Version\") FROM \"VersionedTest_DataObject_Versions\" WHERE \"RecordID\" = ?",
            array($page1->ID)
        )->value();
        $this->assertEquals($page1->Version, $version, 'Correct entry in VersionedTest_DataObject_Versions');

        $version = DB::prepared_query(
            "SELECT MAX(\"Version\") FROM \"VersionedTest_AnotherSubclass_Versions\" WHERE \"RecordID\" = ?",
            array($page1->ID)
        )->value();
        $this->assertEquals($page1->Version, $version, 'Correct entry in VersionedTest_AnotherSubclass_Versions');
    }

    public function testDeleteFromStage()
    {
        $page1 = $this->objFromFixture(VersionedTest\TestObject::class, 'page1');
        $pageID = $page1->ID;

        $page1->Content = 'orig';
        $page1->write();
        $page1->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $this->assertEquals(
            1,
            DB::query('SELECT COUNT(*) FROM "VersionedTest_DataObject" WHERE "ID" = '.$pageID)->value()
        );
        $this->assertEquals(
            1,
            DB::query('SELECT COUNT(*) FROM "VersionedTest_DataObject_Live" WHERE "ID" = '.$pageID)->value()
        );

        $page1->deleteFromStage('Live');

        // Confirm that deleteFromStage() doesn't manipulate the original record
        $this->assertEquals($pageID, $page1->ID);

        $this->assertEquals(
            1,
            DB::query('SELECT COUNT(*) FROM "VersionedTest_DataObject" WHERE "ID" = '.$pageID)->value()
        );
        $this->assertEquals(
            0,
            DB::query('SELECT COUNT(*) FROM "VersionedTest_DataObject_Live" WHERE "ID" = '.$pageID)->value()
        );

        $page1->delete();

        $this->assertEquals(0, $page1->ID);
        $this->assertEquals(
            0,
            DB::query('SELECT COUNT(*) FROM "VersionedTest_DataObject" WHERE "ID" = '.$pageID)->value()
        );
        $this->assertEquals(
            0,
            DB::query('SELECT COUNT(*) FROM "VersionedTest_DataObject_Live" WHERE "ID" = '.$pageID)->value()
        );
    }

    public function testWritingNewToStage()
    {
        $origReadingMode = Versioned::get_reading_mode();

        Versioned::set_stage(Versioned::DRAFT);
        $page = new VersionedTest\TestObject();
        $page->Title = "testWritingNewToStage";
        $page->URLSegment = "testWritingNewToStage";
        $page->write();

        $live = Versioned::get_by_stage(
            VersionedTest\TestObject::class,
            'Live',
            array(
            '"VersionedTest_DataObject_Live"."ID"' => $page->ID
            )
        );
        $this->assertEquals(0, $live->count());

        $stage = Versioned::get_by_stage(
            VersionedTest\TestObject::class,
            'Stage',
            array(
            '"VersionedTest_DataObject"."ID"' => $page->ID
            )
        );
        $this->assertEquals(1, $stage->count());
        $this->assertEquals($stage->First()->Title, 'testWritingNewToStage');

        Versioned::set_reading_mode($origReadingMode);
    }

    /**
     * Writing a page to live should update both draft and live tables
     */
    public function testWritingNewToLive()
    {
        $origReadingMode = Versioned::get_reading_mode();

        Versioned::set_stage(Versioned::LIVE);
        $page = new VersionedTest\TestObject();
        $page->Title = "testWritingNewToLive";
        $page->URLSegment = "testWritingNewToLive";
        $page->write();

        $live = Versioned::get_by_stage(
            VersionedTest\TestObject::class,
            'Live',
            array(
            '"VersionedTest_DataObject_Live"."ID"' => $page->ID
            )
        );
        $this->assertEquals(1, $live->count());
        $liveRecord = $live->First();
        $this->assertEquals($liveRecord->Title, 'testWritingNewToLive');

        $stage = Versioned::get_by_stage(
            VersionedTest\TestObject::class,
            'Stage',
            array(
            '"VersionedTest_DataObject"."ID"' => $page->ID
            )
        );
        $this->assertEquals(1, $stage->count());
        $stageRecord = $stage->first();
        $this->assertEquals($stageRecord->Title, 'testWritingNewToLive');

        // Both records have the same version
        $this->assertEquals($liveRecord->Version, $stageRecord->Version);

        Versioned::set_reading_mode($origReadingMode);
    }

    /**
     * Tests DataObject::hasOwnTableDatabaseField
     */
    public function testHasOwnTableDatabaseFieldWithVersioned()
    {
        $schema = DataObject::getSchema();

        $this->assertNull(
            $schema->fieldSpec(DataObject::class, 'Version', DataObjectSchema::UNINHERITED),
            'Plain models have no version field.'
        );
        $this->assertEquals(
            'Int',
            $schema->fieldSpec(VersionedTest\TestObject::class, 'Version', DataObjectSchema::UNINHERITED),
            'The versioned ext adds an Int version field.'
        );
        $this->assertNull(
            $schema->fieldSpec(VersionedTest\Subclass::class, 'Version', DataObjectSchema::UNINHERITED),
            'Sub-classes of a versioned model don\'t have a Version field.'
        );
        $this->assertNull(
            $schema->fieldSpec(VersionedTest\AnotherSubclass::class, 'Version', DataObjectSchema::UNINHERITED),
            'Sub-classes of a versioned model don\'t have a Version field.'
        );
        $this->assertEquals(
            'Varchar(255)',
            $schema->fieldSpec(VersionedTest\UnversionedWithField::class, 'Version', DataObjectSchema::UNINHERITED),
            'Models w/o Versioned can have their own Version field.'
        );
    }

    /**
     * Test that SQLSelect::queriedTables() applies the version-suffixes properly.
     */
    public function testQueriedTables()
    {
        Versioned::set_stage(Versioned::LIVE);

        $this->assertEquals(
            array(
            'VersionedTest_DataObject_Live',
            'VersionedTest_Subclass_Live',
            ),
            DataObject::get(VersionedTest\Subclass::class)->dataQuery()->query()->queriedTables()
        );
    }

    /**
     * Virtual "sleep" that doesn't actually slow execution, only advances DBDateTime::now()
     *
     * @param int $minutes
     */
    protected function sleep($minutes)
    {
        $now = DBDatetime::now();
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $now->getValue());
        $date->modify("+{$minutes} minutes");
        DBDatetime::set_mock_now($date->format('Y-m-d H:i:s'));
    }

    /**
     * Tests records selected by specific version
     */
    public function testGetVersion()
    {
        // Create a few initial versions to ensure this version
        // doesn't clash with child versions
        $this->sleep(1);
        /** @var VersionedTest\TestObject $page2 */
        $page2 = $this->objFromFixture(VersionedTest\TestObject::class, 'page2');
        $page2->Title = 'dummy1';
        $page2->write();
        $this->sleep(1);
        $page2->Title = 'dummy2';
        $page2->write();
        $this->sleep(1);
        $page2->Title = 'Page 2 - v1';
        $page2->write();
        $version1Date = $page2->LastEdited;
        $version1 = $page2->Version;

        // Create another version where this object and some
        // child records have been modified
        $this->sleep(1);
        /** @var VersionedTest\TestObject $page2a */
        $page2a = $this->objFromFixture(VersionedTest\TestObject::class, 'page2a');
        $page2a->Title = 'Page 2a - v2';
        $page2a->write();
        $this->sleep(1);
        $page2->Title = 'Page 2 - v2';
        $page2->write();
        $version2Date = $page2->LastEdited;
        $version2 = $page2->Version;
        $this->assertGreaterThan($version1, $version2);
        $this->assertDOSEquals(
            [
                ['Title' => 'Page 2a - v2'],
                ['Title' => 'Page 2b'],
            ],
            $page2->Children()
        );

        // test selecting v1
        /** @var VersionedTest\TestObject $page2v1 */
        $page2v1 = Versioned::get_version(VersionedTest\TestObject::class, $page2->ID, $version1);
        $this->assertEquals('Page 2 - v1', $page2v1->Title);

        // When selecting v1, related records should by filtered by
        // the modified date of that version
        $archiveParms = [
            'Versioned.mode' => 'archive',
            'Versioned.date' => $version1Date
        ];
        $this->assertEquals($archiveParms, $page2v1->getInheritableQueryParams());
        $this->assertArraySubset($archiveParms, $page2v1->Children()->getQueryParams());
        $this->assertDOSEquals(
            [
                ['Title' => 'Page 2a'],
                ['Title' => 'Page 2b'],
            ],
            $page2v1->Children()
        );

        // When selecting v2, we get the same as on stage
        /** @var VersionedTest\TestObject $page2v2 */
        $page2v2 = Versioned::get_version(VersionedTest\TestObject::class, $page2->ID, $version2);
        $this->assertEquals('Page 2 - v2', $page2v2->Title);

        // When selecting v2, related records should by filtered by
        // the modified date of that version
        $archiveParms = [
            'Versioned.mode' => 'archive',
            'Versioned.date' => $version2Date
        ];
        $this->assertEquals($archiveParms, $page2v2->getInheritableQueryParams());
        $this->assertArraySubset($archiveParms, $page2v2->Children()->getQueryParams());
        $this->assertDOSEquals(
            [
                ['Title' => 'Page 2a - v2'],
                ['Title' => 'Page 2b'],
            ],
            $page2v2->Children()
        );
    }

    public function testGetVersionWhenClassnameChanged()
    {
        $obj = new VersionedTest\TestObject;
        $obj->Name = "test";
        $obj->write();
        $obj->Name = "test2";
        $obj->ClassName = VersionedTest\Subclass::class;
        $obj->write();
        $subclassVersion = $obj->Version;

        $obj->Name = "test3";
        $obj->ClassName = VersionedTest\TestObject::class;
        $obj->write();

        // We should be able to pass the subclass and still get the correct class back
        $obj2 = Versioned::get_version(VersionedTest\Subclass::class, $obj->ID, $subclassVersion);
        $this->assertInstanceOf(VersionedTest\Subclass::class, $obj2);
        $this->assertEquals("test2", $obj2->Name);

        $obj3 = Versioned::get_latest_version(VersionedTest\Subclass::class, $obj->ID);
        $this->assertEquals("test3", $obj3->Name);
        $this->assertInstanceOf(VersionedTest\TestObject::class, $obj3);
    }

    public function testArchiveVersion()
    {
        // In 2005 this file was created
        DBDatetime::set_mock_now('2005-01-01 00:00:00');
        $testPage = new VersionedTest\Subclass();
        $testPage->Title = 'Archived page';
        $testPage->Content = 'This is the content from 2005';
        $testPage->ExtraField = '2005';
        $testPage->write();

        // In 2007 we updated it
        DBDatetime::set_mock_now('2007-01-01 00:00:00');
        $testPage->Content = "It's 2007 already!";
        $testPage->ExtraField = '2007';
        $testPage->write();

        // In 2009 we updated it again
        DBDatetime::set_mock_now('2009-01-01 00:00:00');
        $testPage->Content = "I'm enjoying 2009";
        $testPage->ExtraField = '2009';
        $testPage->write();

        // End mock, back to the present day:)
        DBDatetime::clear_mock_now();

        // Test 1 - 2006 Content
        singleton(VersionedTest\Subclass::class)->flushCache(true);
        Versioned::set_reading_mode('Archive.2006-01-01 00:00:00');
        $testPage2006 = DataObject::get(VersionedTest\Subclass::class)->filter(array('Title' => 'Archived page'))->first();
        $this->assertInstanceOf(VersionedTest\Subclass::class, $testPage2006);
        $this->assertEquals("2005", $testPage2006->ExtraField);
        $this->assertEquals("This is the content from 2005", $testPage2006->Content);

        // Test 2 - 2008 Content
        singleton(VersionedTest\Subclass::class)->flushCache(true);
        Versioned::set_reading_mode('Archive.2008-01-01 00:00:00');
        $testPage2008 = DataObject::get(VersionedTest\Subclass::class)->filter(array('Title' => 'Archived page'))->first();
        $this->assertInstanceOf(VersionedTest\Subclass::class, $testPage2008);
        $this->assertEquals("2007", $testPage2008->ExtraField);
        $this->assertEquals("It's 2007 already!", $testPage2008->Content);

        // Test 3 - Today
        singleton(VersionedTest\Subclass::class)->flushCache(true);
        Versioned::set_reading_mode('Stage.Stage');
        $testPageCurrent = DataObject::get(VersionedTest\Subclass::class)->filter(array('Title' => 'Archived page'))
            ->first();
        $this->assertInstanceOf(VersionedTest\Subclass::class, $testPageCurrent);
        $this->assertEquals("2009", $testPageCurrent->ExtraField);
        $this->assertEquals("I'm enjoying 2009", $testPageCurrent->Content);
    }

    /**
     * Test that archive works on live stage
     */
    public function testArchiveLive()
    {
        Versioned::set_stage(Versioned::LIVE);
        $this->logInWithPermission('ADMIN');
        $record = new VersionedTest\TestObject();
        $record->Name = 'test object';
        // Writing in live mode should write to draft as well
        $record->write();
        $recordID = $record->ID;
        $this->assertTrue($record->isPublished());
        $this->assertTrue($record->isOnDraft());

        // Delete in live
        /** @var VersionedTest\TestObject $recordLive */
        $recordLive = VersionedTest\TestObject::get()->byID($recordID);
        $recordLive->doArchive();
        $this->assertFalse($recordLive->isPublished());
        $this->assertFalse($recordLive->isOnDraft());
    }

    /**
     * Test archive works on draft
     */
    public function testArchiveDraft()
    {
        Versioned::set_stage(Versioned::DRAFT);
        $this->logInWithPermission('ADMIN');
        $record = new VersionedTest\TestObject();
        $record->Name = 'test object';

        // Writing in draft mode requires publishing to effect on live
        $record->write();
        $record->publishRecursive();
        $recordID = $record->ID;
        $this->assertTrue($record->isPublished());
        $this->assertTrue($record->isOnDraft());

        // Delete in draft
        /** @var VersionedTest\TestObject $recordDraft */
        $recordDraft = VersionedTest\TestObject::get()->byID($recordID);
        $recordDraft->doArchive();
        $this->assertFalse($recordDraft->isPublished());
        $this->assertFalse($recordDraft->isOnDraft());
    }

    public function testAllVersions()
    {
        // In 2005 this file was created
        DBDatetime::set_mock_now('2005-01-01 00:00:00');
        $testPage = new VersionedTest\Subclass();
        $testPage->Title = 'Archived page';
        $testPage->Content = 'This is the content from 2005';
        $testPage->ExtraField = '2005';
        $testPage->write();

        // In 2007 we updated it
        DBDatetime::set_mock_now('2007-01-01 00:00:00');
        $testPage->Content = "It's 2007 already!";
        $testPage->ExtraField = '2007';
        $testPage->write();

        // Check both versions are returned
        $versions = Versioned::get_all_versions(VersionedTest\Subclass::class, $testPage->ID);
        $content = array();
        $extraFields = array();
        foreach ($versions as $version) {
            $content[] = $version->Content;
            $extraFields[] = $version->ExtraField;
        }

        $this->assertEquals($versions->Count(), 2, 'All versions returned');
        $this->assertEquals(
            $content,
            array('This is the content from 2005', "It's 2007 already!"),
            'Version fields returned'
        );
        $this->assertEquals($extraFields, array('2005', '2007'), 'Version fields returned');

        // In 2009 we updated it again
        DBDatetime::set_mock_now('2009-01-01 00:00:00');
        $testPage->Content = "I'm enjoying 2009";
        $testPage->ExtraField = '2009';
        $testPage->write();

        // End mock, back to the present day:)
        DBDatetime::clear_mock_now();

        $versions = Versioned::get_all_versions(VersionedTest\Subclass::class, $testPage->ID);
        $content = array();
        $extraFields = array();
        foreach ($versions as $version) {
            $content[] = $version->Content;
            $extraFields[] = $version->ExtraField;
        }

        $this->assertEquals($versions->Count(), 3, 'Additional all versions returned');
        $this->assertEquals(
            $content,
            array('This is the content from 2005', "It's 2007 already!", "I'm enjoying 2009"),
            'Additional version fields returned'
        );
        $this->assertEquals($extraFields, array('2005', '2007', '2009'), 'Additional version fields returned');
    }

    public function testArchiveRelatedDataWithoutVersioned()
    {
        DBDatetime::set_mock_now('2009-01-01 00:00:00');

        $relatedData = new VersionedTest\RelatedWithoutversion();
        $relatedData->Name = 'Related Data';
        $relatedDataId = $relatedData->write();

        $testData = new VersionedTest\TestObject();
        $testData->Title = 'Test';
        $testData->Content = 'Before Content';
        $testData->Related()->add($relatedData);
        $id = $testData->write();

        DBDatetime::set_mock_now('2010-01-01 00:00:00');
        $testData->Content = 'After Content';
        $testData->write();

        Versioned::reading_archived_date('2009-01-01 19:00:00');

        $fetchedData = VersionedTest\TestObject::get()->byId($id);
        $this->assertEquals('Before Content', $fetchedData->Content, 'We see the correct content of the older version');

        $relatedData = VersionedTest\RelatedWithoutversion::get()->byId($relatedDataId);
        $this->assertEquals(
            1,
            $relatedData->Related()->count(),
            'We have a relation, with no version table, querying it still works'
        );
    }

    public function testVersionedWithSingleStage()
    {
        $tables = DB::table_list();
        $this->assertContains(
            'versionedtest_singlestage',
            array_keys($tables),
            'Contains base table'
        );
        $this->assertContains(
            'versionedtest_singlestage_versions',
            array_keys($tables),
            'Contains versions table'
        );
        $this->assertNotContains(
            'versionedtest_singlestage_live',
            array_keys($tables),
            'Does not contain separate table with _Live suffix'
        );
        $this->assertNotContains(
            'versionedtest_singlestage_stage',
            array_keys($tables),
            'Does not contain separate table with _Stage suffix'
        );

        Versioned::set_stage(Versioned::DRAFT);
        $obj = new VersionedTest\SingleStage(array('Name' => 'MyObj'));
        $obj->write();
        $this->assertNotNull(
            VersionedTest\SingleStage::get()->byID($obj->ID),
            'Writes to and reads from default stage if its set explicitly'
        );

        Versioned::set_stage(Versioned::LIVE);
        $obj = new VersionedTest\SingleStage(array('Name' => 'MyObj'));
        $obj->write();
        $this->assertNotNull(
            VersionedTest\SingleStage::get()->byID($obj->ID),
            'Writes to and reads from default stage even if a non-matching stage is set'
        );
    }

    /**
     * Test that publishing processes respects lazy loaded fields
     */
    public function testLazyLoadFields()
    {
        $originalMode = Versioned::get_reading_mode();

        // Generate staging record and retrieve it from stage in live mode
        Versioned::set_stage(Versioned::DRAFT);
        $obj = new VersionedTest\Subclass();
        $obj->Name = 'bob';
        $obj->ExtraField = 'Field Value';
        $obj->write();
        $objID = $obj->ID;
        $filter = sprintf('"VersionedTest_DataObject"."ID" = \'%d\'', Convert::raw2sql($objID));
        Versioned::set_stage(Versioned::LIVE);

        // Check fields are unloaded prior to access
        $objLazy = Versioned::get_one_by_stage(VersionedTest\TestObject::class, 'Stage', $filter, false);
        $lazyFields = $objLazy->getQueriedDatabaseFields();
        $this->assertTrue(isset($lazyFields['ExtraField_Lazy']));
        $this->assertEquals(VersionedTest\Subclass::class, $lazyFields['ExtraField_Lazy']);

        // Check lazy loading works when viewing a Stage object in Live mode
        $this->assertEquals('Field Value', $objLazy->ExtraField);

        // Test that writeToStage respects lazy loaded fields
        $objLazy = Versioned::get_one_by_stage(VersionedTest\TestObject::class, 'Stage', $filter, false);
        $objLazy->writeToStage('Live');
        $objLive = Versioned::get_one_by_stage(VersionedTest\TestObject::class, 'Live', $filter, false);
        $liveLazyFields = $objLive->getQueriedDatabaseFields();

        // Check fields are unloaded prior to access
        $this->assertTrue(isset($liveLazyFields['ExtraField_Lazy']));
        $this->assertEquals(VersionedTest\Subclass::class, $liveLazyFields['ExtraField_Lazy']);

        // Check that live record has original value
        $this->assertEquals('Field Value', $objLive->ExtraField);

        Versioned::set_reading_mode($originalMode);
    }

    public function testLazyLoadFieldsRetrieval()
    {
        // Set reading mode to Stage
        Versioned::set_stage(Versioned::DRAFT);

        // Create object only in reading stage
        $original = new VersionedTest\Subclass();
        $original->ExtraField = 'Foo';
        $original->write();

        // Query for object using base class
        $query = VersionedTest\TestObject::get()->filter('ID', $original->ID);

        // Set reading mode to Live
        Versioned::set_stage(Versioned::LIVE);

        $fetched = $query->first();
        $this->assertTrue($fetched instanceof VersionedTest\Subclass);
        $this->assertEquals($original->ID, $fetched->ID); // Eager loaded
        $this->assertEquals($original->ExtraField, $fetched->ExtraField); // Lazy loaded
    }

    /**
     * Tests that reading mode persists between requests
     */
    public function testReadingPersistent()
    {
        $session = Injector::inst()->create('SilverStripe\\Control\\Session', array());
        $adminID = $this->logInWithPermission('ADMIN');
        $session->inst_set('loggedInAs', $adminID);

        // Set to stage
        Director::test('/?stage=Stage', null, $session);
        $this->assertEquals(
            'Stage.Stage',
            $session->inst_get('readingMode'),
            'Check querystring changes reading mode to Stage'
        );
        Director::test('/', null, $session);
        $this->assertEquals(
            'Stage.Stage',
            $session->inst_get('readingMode'),
            'Check that subsequent requests in the same session remain in Stage mode'
        );

        // Test live persists
        Director::test('/?stage=Live', null, $session);
        $this->assertEquals(
            'Stage.Live',
            $session->inst_get('readingMode'),
            'Check querystring changes reading mode to Live'
        );
        Director::test('/', null, $session);
        $this->assertEquals(
            'Stage.Live',
            $session->inst_get('readingMode'),
            'Check that subsequent requests in the same session remain in Live mode'
        );

        // Test that session doesn't redundantly store the default stage if it doesn't need to
        $session2 = Injector::inst()->create('SilverStripe\\Control\\Session', array());
        $session2->inst_set('loggedInAs', $adminID);
        Director::test('/', null, $session2);
        $this->assertArrayNotHasKey('readingMode', $session2->inst_changedData());
        Director::test('/?stage=Live', null, $session2);
        $this->assertArrayNotHasKey('readingMode', $session2->inst_changedData());

        // Test choose_site_stage
        unset($_GET['stage']);
        unset($_GET['archiveDate']);
        Session::set('readingMode', 'Stage.Stage');
        Versioned::choose_site_stage();
        $this->assertEquals('Stage.Stage', Versioned::get_reading_mode());
        Session::set('readingMode', 'Archive.2014-01-01');
        Versioned::choose_site_stage();
        $this->assertEquals('Archive.2014-01-01', Versioned::get_reading_mode());
        Session::clear('readingMode');
        Versioned::choose_site_stage();
        $this->assertEquals('Stage.Live', Versioned::get_reading_mode());
    }

    /**
     * Test that stage parameter is blocked by non-administrative users
     */
    public function testReadingModeSecurity()
    {
        $this->setExpectedException(HTTPResponse_Exception::class);
        $session = Injector::inst()->create(Session::class, array());
        Director::test('/?stage=Stage', null, $session);
    }

    /**
     * Ensures that the latest version of a record is the expected value
     *
     * @param DataObject $record
     * @param int        $version
     */
    protected function assertRecordHasLatestVersion($record, $version)
    {
        $schema = DataObject::getSchema();
        foreach (ClassInfo::ancestry(get_class($record), true) as $class) {
            $table = $schema->tableName($class);
            $versionForClass = DB::prepared_query(
                $sql = "SELECT MAX(\"Version\") FROM \"{$table}_Versions\" WHERE \"RecordID\" = ?",
                array($record->ID)
            )->value();
            $this->assertEquals($version, $versionForClass, "That the table $table has the latest version $version");
        }
    }

    /**
     * Test that that stage a record was queried from cascades to child relations, even if the
     * global stage has changed
     */
    public function testStageCascadeOnRelations()
    {
        $origReadingMode = Versioned::get_reading_mode();

        // Stage record - 2 children
        Versioned::set_stage(Versioned::DRAFT);
        $draftPage = $this->objFromFixture(VersionedTest\TestObject::class, 'page2');
        $draftPage->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $this->assertEquals(2, $draftPage->Children()->Count());

        // Live record - no children
        Versioned::set_stage(Versioned::LIVE);
        $livePage = $this->objFromFixture(VersionedTest\TestObject::class, 'page2');
        $this->assertEquals(0, $livePage->Children()->Count());

        // Validate that draft page still queries draft children even though global stage is live
        $this->assertEquals(2, $draftPage->Children()->Count());

        // Validate that live page still queries live children even though global stage is live
        Versioned::set_stage(Versioned::DRAFT);
        $this->assertEquals(0, $livePage->Children()->Count());

        Versioned::set_reading_mode($origReadingMode);
    }

    /**
     * Tests that multi-table dataobjects are correctly versioned
     */
    public function testWriteToStage()
    {
        // Test subclass with versioned extension directly added
        $record = VersionedTest\Subclass::create();
        $record->Title = "Test A";
        $record->ExtraField = "Test A";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 1);
        $record->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $this->assertRecordHasLatestVersion($record, 1);
        $record->Title = "Test A2";
        $record->ExtraField = "Test A2";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 2);

        // Test subclass without changes to base class
        $record = VersionedTest\Subclass::create();
        $record->ExtraField = "Test B";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 1);
        $record->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $this->assertRecordHasLatestVersion($record, 1);
        $record->ExtraField = "Test B2";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 2);

        // Test subclass without changes to sub class
        $record = VersionedTest\Subclass::create();
        $record->Title = "Test C";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 1);
        $record->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $this->assertRecordHasLatestVersion($record, 1);
        $record->Title = "Test C2";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 2);

        // Test subclass with versioned extension only added to the base clases
        $record = VersionedTest\AnotherSubclass::create();
        $record->Title = "Test A";
        $record->AnotherField = "Test A";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 1);
        $record->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $this->assertRecordHasLatestVersion($record, 1);
        $record->Title = "Test A2";
        $record->AnotherField = "Test A2";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 2);


        // Test subclass without changes to base class
        $record = VersionedTest\AnotherSubclass::create();
        $record->AnotherField = "Test B";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 1);
        $record->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $this->assertRecordHasLatestVersion($record, 1);
        $record->AnotherField = "Test B2";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 2);

        // Test subclass without changes to sub class
        $record = VersionedTest\AnotherSubclass::create();
        $record->Title = "Test C";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 1);
        $record->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $this->assertRecordHasLatestVersion($record, 1);
        $record->Title = "Test C2";
        $record->writeToStage("Stage");
        $this->assertRecordHasLatestVersion($record, 2);
    }

    public function testVersionedHandlesRenamedDataObjectFields()
    {
        Config::inst()->remove(VersionedTest\RelatedWithoutversion::class, 'db', 'Name', 'Varchar');

        Config::inst()->update(
            VersionedTest\RelatedWithoutversion::class,
            'db',
            array(
            "NewField" => "Varchar",
            )
        );

        VersionedTest\RelatedWithoutversion::add_extension(Versioned::class);
        $this->resetDBSchema(true);
        $testData = new VersionedTest\RelatedWithoutversion();
        $testData->NewField = 'Test';
        $testData->write();
    }

    public function testCanView()
    {
        $public1ID = $this->idFromFixture(VersionedTest\PublicStage::class, 'public1');
        $public2ID = $this->idFromFixture(VersionedTest\PublicViaExtension::class, 'public2');
        $privateID = $this->idFromFixture(VersionedTest\TestObject::class, 'page1');
        $singleID = $this->idFromFixture(VersionedTest\SingleStage::class, 'single');

        // Test that all (and only) public pages are viewable in stage mode
        Session::clear("loggedInAs");
        Versioned::set_stage(Versioned::DRAFT);
        $public1 = Versioned::get_one_by_stage(VersionedTest\PublicStage::class, 'Stage', array('"ID"' => $public1ID));
        $public2 = Versioned::get_one_by_stage(VersionedTest\PublicViaExtension::class, 'Stage', array('"ID"' => $public2ID));
        $private = Versioned::get_one_by_stage(VersionedTest\TestObject::class, 'Stage', array('"ID"' => $privateID));
        // Also test an object that has just a single-stage (eg. is only versioned)
        $single = Versioned::get_one_by_stage(VersionedTest\SingleStage::class, 'Stage', array('"ID"' => $singleID));


        $this->assertTrue($public1->canView());
        $this->assertTrue($public2->canView());
        $this->assertFalse($private->canView());
        $this->assertFalse($single->canView());

        // Adjusting the current stage should not allow objects loaded in stage to be viewable
        Versioned::set_stage(Versioned::LIVE);
        $this->assertTrue($public1->canView());
        $this->assertTrue($public2->canView());
        $this->assertFalse($private->canView());
        $this->assertFalse($single->canView());

        // Writing the private page to live should be fine though
        $private->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $privateLive = Versioned::get_one_by_stage(VersionedTest\TestObject::class, 'Live', array('"ID"' => $privateID));
        $this->assertTrue($private->canView());
        $this->assertTrue($privateLive->canView());

        // But if the private version becomes different to the live version, it's once again disallowed
        Versioned::set_stage(Versioned::DRAFT);
        $private->Title = 'Secret Title';
        $private->write();
        $this->assertFalse($private->canView());
        $this->assertTrue($privateLive->canView());

        // And likewise, viewing a live page (when mode is draft) should be ok
        Versioned::set_stage(Versioned::DRAFT);
        $this->assertFalse($private->canView());
        $this->assertTrue($privateLive->canView());

        // Logging in as admin should allow all permissions
        $this->logInWithPermission('ADMIN');
        Versioned::set_stage(Versioned::DRAFT);
        $this->assertTrue($public1->canView());
        $this->assertTrue($public2->canView());
        $this->assertTrue($private->canView());
        $this->assertTrue($single->canView());
    }

    public function testCanViewStage()
    {
        $public = $this->objFromFixture(VersionedTest\PublicStage::class, 'public1');
        $private = $this->objFromFixture(VersionedTest\TestObject::class, 'page1');
        Session::clear("loggedInAs");
        Versioned::set_stage(Versioned::DRAFT);

        // Test that all (and only) public pages are viewable in stage mode
        // Unpublished records are not viewable in live regardless of permissions
        $this->assertTrue($public->canViewStage('Stage'));
        $this->assertFalse($private->canViewStage('Stage'));
        $this->assertFalse($public->canViewStage('Live'));
        $this->assertFalse($private->canViewStage('Live'));

        // Writing records to live should make both stage and live modes viewable
        $private->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $public->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $this->assertTrue($public->canViewStage('Stage'));
        $this->assertTrue($private->canViewStage('Stage'));
        $this->assertTrue($public->canViewStage('Live'));
        $this->assertTrue($private->canViewStage('Live'));

        // If the draft mode changes, the live mode remains public, although the updated
        // draft mode is secured for non-public records.
        $private->Title = 'Secret Title';
        $private->write();
        $public->Title = 'Public Title';
        $public->write();
        $this->assertTrue($public->canViewStage('Stage'));
        $this->assertFalse($private->canViewStage('Stage'));
        $this->assertTrue($public->canViewStage('Live'));
        $this->assertTrue($private->canViewStage('Live'));
    }

    /**
     * Values that are overwritten with null are saved to the _versions table correctly.
     */
    public function testWriteNullValueToVersion()
    {
        $record = VersionedTest\Subclass::create();
        $record->Title = "Test A";
        $record->write();

        $version = Versioned::get_latest_version($record->ClassName, $record->ID);

        $this->assertEquals(1, $version->Version);
        $this->assertEquals($record->Title, $version->Title);

        $record->Title = null;
        $record->write();

        $version = Versioned::get_latest_version($record->ClassName, $record->ID);

        $this->assertEquals(2, $version->Version);
        $this->assertEquals($record->Title, $version->Title);
    }



    public function testStageStates()
    {
        // newly created page
        $createdPage = new VersionedTest\TestObject();
        $createdPage->write();
        $this->assertTrue($createdPage->isOnDraft());
        $this->assertFalse($createdPage->isPublished());
        $this->assertTrue($createdPage->isOnDraftOnly());
        $this->assertTrue($createdPage->isModifiedOnDraft());

        // published page
        $publishedPage = new VersionedTest\TestObject();
        $publishedPage->write();
        $publishedPage->copyVersionToStage('Stage', 'Live');
        $this->assertTrue($publishedPage->isOnDraft());
        $this->assertTrue($publishedPage->isPublished());
        $this->assertFalse($publishedPage->isOnDraftOnly());
        $this->assertFalse($publishedPage->isOnLiveOnly());
        $this->assertFalse($publishedPage->isModifiedOnDraft());

        // published page, deleted from stage
        $deletedFromDraftPage = new VersionedTest\TestObject();
        $deletedFromDraftPage->write();
        $deletedFromDraftPage->copyVersionToStage('Stage', 'Live');
        $deletedFromDraftPage->deleteFromStage('Stage');
        $this->assertFalse($deletedFromDraftPage->isArchived());
        $this->assertFalse($deletedFromDraftPage->isOnDraft());
        $this->assertTrue($deletedFromDraftPage->isPublished());
        $this->assertFalse($deletedFromDraftPage->isOnDraftOnly());
        $this->assertTrue($deletedFromDraftPage->isOnLiveOnly());
        $this->assertFalse($deletedFromDraftPage->isModifiedOnDraft());

        // published page, deleted from live
        $deletedFromLivePage = new VersionedTest\TestObject();
        $deletedFromLivePage->write();
        $deletedFromLivePage->copyVersionToStage('Stage', 'Live');
        $deletedFromLivePage->deleteFromStage('Live');
        $this->assertFalse($deletedFromLivePage->isArchived());
        $this->assertTrue($deletedFromLivePage->isOnDraft());
        $this->assertFalse($deletedFromLivePage->isPublished());
        $this->assertTrue($deletedFromLivePage->isOnDraftOnly());
        $this->assertFalse($deletedFromLivePage->isOnLiveOnly());
        $this->assertTrue($deletedFromLivePage->isModifiedOnDraft());

        // published page, deleted from both stages
        $deletedFromAllStagesPage = new VersionedTest\TestObject();
        $deletedFromAllStagesPage->write();
        $deletedFromAllStagesPage->copyVersionToStage('Stage', 'Live');
        $deletedFromAllStagesPage->doArchive();
        $this->assertTrue($deletedFromAllStagesPage->isArchived());
        $this->assertFalse($deletedFromAllStagesPage->isOnDraft());
        $this->assertFalse($deletedFromAllStagesPage->isPublished());
        $this->assertFalse($deletedFromAllStagesPage->isOnDraftOnly());
        $this->assertFalse($deletedFromAllStagesPage->isOnLiveOnly());
        $this->assertFalse($deletedFromAllStagesPage->isModifiedOnDraft());

        // published page, modified
        $modifiedOnDraftPage = new VersionedTest\TestObject();
        $modifiedOnDraftPage->write();
        $modifiedOnDraftPage->copyVersionToStage('Stage', 'Live');
        $modifiedOnDraftPage->Content = 'modified';
        $modifiedOnDraftPage->write();
        $this->assertFalse($modifiedOnDraftPage->isArchived());
        $this->assertTrue($modifiedOnDraftPage->isOnDraft());
        $this->assertTrue($modifiedOnDraftPage->isPublished());
        $this->assertFalse($modifiedOnDraftPage->isOnDraftOnly());
        $this->assertFalse($modifiedOnDraftPage->isOnLiveOnly());
        $this->assertTrue($modifiedOnDraftPage->isModifiedOnDraft());
    }
}
