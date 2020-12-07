<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration;

use Monolog\Logger;
use Piwik\ArchiveProcessor\Parameters;
use Piwik\ArchiveProcessor\Rules;
use Piwik\Container\StaticContainer;
use Piwik\CronArchive;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\Date;
use Piwik\Db;
use Piwik\Period\Factory;
use Piwik\Plugins\CoreAdminHome\tests\Framework\Mock\API;
use Piwik\Plugins\SegmentEditor\Model;
use Piwik\Segment;
use Piwik\Site;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeLogger;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Plugins\SegmentEditor\API as SegmentAPI;
use Piwik\Version;
use Psr\Log\NullLogger;

/**
 * @group Archiver
 * @group CronArchive
 */
class CronArchiveTest extends IntegrationTestCase
{
    /**
     * @dataProvider getTestDataForInvalidateRecentDate
     */
    public function test_invalidateRecentDate_invalidatesCorrectPeriodsAndSegments($dateStr, $segments,
                                                                                   $expectedInvalidationCalls)
    {
        $idSite = Fixture::createWebsite('2019-04-04 03:45:45', 0, false, false, 1, null, null, 'Australia/Sydney');

        Rules::setBrowserTriggerArchiving(false);
        foreach ($segments as $idx => $segment) {
            SegmentAPI::getInstance()->add('segment #' . $idx, $segment, $idx % 2 === 0 ? $idSite : false, true, true);
        }
        Rules::setBrowserTriggerArchiving(true);

        $t = Fixture::getTracker($idSite, Date::yesterday()->addHour(2)->getDatetime());
        $t->setUrl('http://someurl.com/abc');
        Fixture::checkResponse($t->doTrackPageView('some page'));

        $t = Fixture::getTracker($idSite, Date::today()->addHour(2)->getDatetime());
        $t->setUrl('http://someurl.com/def');
        Fixture::checkResponse($t->doTrackPageView('some page 2'));

        $mockInvalidateApi = $this->getMockInvalidateApi();

        $archiver = new CronArchive();
        $archiver->init();
        $archiver->setApiToInvalidateArchivedReport($mockInvalidateApi);

        $archiver->invalidateRecentDate($dateStr, $idSite);

        $actualInvalidationCalls = $mockInvalidateApi->getInvalidations();

        $this->assertEquals($expectedInvalidationCalls, $actualInvalidationCalls);
    }

    public function getTestDataForInvalidateRecentDate()
    {
        $segments = [
            'browserCode==IE',
            'visitCount>5',
        ];

        return [
            [
                'today',
                $segments,
                [
                    array (
                        1,
                        '2020-02-03',
                        'day',
                        false,
                        false,
                        false,
                    ),
                    array (
                        1,
                        '2020-02-03',
                        'day',
                        'browserCode==IE',
                        false,
                        false,
                    ),
                    array (
                        1,
                        '2020-02-03',
                        'day',
                        'visitCount>5',
                        false,
                        false,
                    ),
                ],
            ],
            [
                'yesterday',
                $segments,
                [
                    array (
                        1,
                        '2020-02-02',
                        'day',
                        false,
                        false,
                        false,
                    ),
                    array (
                        1,
                        '2020-02-02',
                        'day',
                        'browserCode==IE',
                        false,
                        false,
                    ),
                    array (
                        1,
                        '2020-02-02',
                        'day',
                        'visitCount>5',
                        false,
                        false,
                    ),
                ],
            ],
        ];
    }

    private function getMockInvalidateApi()
    {
        $mock = new class {
            private $calls = [];

            public function invalidateArchivedReports()
            {
                $this->calls[] = func_get_args();
            }

            public function getInvalidations()
            {
                return $this->calls;
            }
        };
        return $mock;
    }

    public function test_isThereExistingValidPeriod_returnsTrueIfPeriodHasToday_AndExistingArchiveIsNewEnough()
    {
        Fixture::createWebsite('2019-04-04 03:45:45');

        Date::$now = strtotime('2020-04-05');

        $archiver = new CronArchive();

        $params = new Parameters(new Site(1), Factory::build('week', '2020-04-05'), new Segment('', [1]));

        $tsArchived = Date::now()->subSeconds(100)->getDatetime();

        $archiveTable = ArchiveTableCreator::getNumericTable(Date::factory('2020-03-30'));
        Db::query("INSERT INTO $archiveTable (idarchive, idsite, period, date1, date2, name, value, ts_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
            1, 1,2, '2020-03-30', '2020-04-05', 'done', ArchiveWriter::DONE_OK, $tsArchived
        ]);

        $actual =$archiver->isThereExistingValidPeriod($params);
        $this->assertTrue($actual);
    }

    public function test_isThereExistingValidPeriod_returnsTrueIfPeriodHasToday_AndExistingArchiveIsNewEnoughAndInvalidated()
    {
        Fixture::createWebsite('2019-04-04 03:45:45');

        Date::$now = strtotime('2020-04-05');

        $archiver = new CronArchive();

        $params = new Parameters(new Site(1), Factory::build('week', '2020-04-05'), new Segment('', [1]));

        $tsArchived = Date::now()->subSeconds(100)->getDatetime();

        $archiveTable = ArchiveTableCreator::getNumericTable(Date::factory('2020-03-30'));
        Db::query("INSERT INTO $archiveTable (idarchive, idsite, period, date1, date2, name, value, ts_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
            1, 1,2, '2020-03-30', '2020-04-05', 'done', ArchiveWriter::DONE_INVALIDATED, $tsArchived
        ]);

        $actual =$archiver->isThereExistingValidPeriod($params, $isYesterday = false);
        $this->assertTrue($actual);
    }

    public function test_isThereExistingValidPeriod_returnsTrueIfPeriodDoesNotHaveToday_AndExistingArchiveIsOk()
    {
        Fixture::createWebsite('2019-04-04 03:45:45');

        Date::$now = strtotime('2020-04-05');

        $archiver = new CronArchive();

        $params = new Parameters(new Site(1), Factory::build('day', '2020-03-05'), new Segment('', [1]));

        $tsArchived = Date::now()->subDay(1)->getDatetime();

        $archiveTable = ArchiveTableCreator::getNumericTable(Date::factory('2020-03-05'));
        Db::query("INSERT INTO $archiveTable (idarchive, idsite, period, date1, date2, name, value, ts_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
            1, 1, 1, '2020-03-05', '2020-03-05', 'done', ArchiveWriter::DONE_OK, $tsArchived
        ]);

        $actual =$archiver->isThereExistingValidPeriod($params, $isYesterday = false);
        $this->assertTrue($actual);
    }

    public function test_isThereExistingValidPeriod_returnsFalseIfDayHasChangedAndDateIsYesterday()
    {
        Fixture::createWebsite('2019-04-04 03:45:45');

        Date::$now = strtotime('2020-04-05');

        $archiver = new CronArchive();

        $params = new Parameters(new Site(1), Factory::build('day', '2020-04-04'), new Segment('', [1]));

        $tsArchived = Date::now()->subDay(1)->getDatetime();

        $archiveTable = ArchiveTableCreator::getNumericTable(Date::factory('2020-04-04'));
        Db::query("INSERT INTO $archiveTable (idarchive, idsite, period, date1, date2, name, value, ts_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
            1, 1, 1, '2020-04-04', '2020-04-04', 'done', ArchiveWriter::DONE_OK, $tsArchived
        ]);

        $actual =$archiver->isThereExistingValidPeriod($params, $isYesterday = true);
        $this->assertFalse($actual);
    }

    public function test_isThereExistingValidPeriod_returnsTrueIfDayHasNotChangedAndDateIsYesterday()
    {
        Fixture::createWebsite('2019-04-04 03:45:45');

        Date::$now = strtotime('2020-04-05 06:23:40');

        $archiver = new CronArchive();

        $params = new Parameters(new Site(1), Factory::build('day', '2020-04-04'), new Segment('', [1]));

        $tsArchived = Date::now()->subSeconds(1500)->getDatetime();

        $archiveTable = ArchiveTableCreator::getNumericTable(Date::factory('2020-04-04'));
        Db::query("INSERT INTO $archiveTable (idarchive, idsite, period, date1, date2, name, value, ts_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
            1, 1, 1, '2020-04-04', '2020-04-04', 'done', ArchiveWriter::DONE_OK, $tsArchived
        ]);

        $actual = $archiver->isThereExistingValidPeriod($params, $isYesterday = true);
        $this->assertTrue($actual);
    }

    public function test_getColumnNamesFromTable()
    {
        Fixture::createWebsite('2014-12-12 00:01:02');
        Fixture::createWebsite('2014-12-12 00:01:02');

        $ar = StaticContainer::get('Piwik\Archive\ArchiveInvalidator');
        $ar->rememberToInvalidateArchivedReportsLater(1, Date::factory('2014-04-05'));
        $ar->rememberToInvalidateArchivedReportsLater(2, Date::factory('2014-04-05'));
        $ar->rememberToInvalidateArchivedReportsLater(2, Date::factory('2014-04-06'));

        $api = API::getInstance();

        $cronarchive = new TestCronArchive(Fixture::getRootUrl() . 'tests/PHPUnit/proxy/index.php');
        $cronarchive->init();
        $cronarchive->setApiToInvalidateArchivedReport($api);
        $cronarchive->invalidateArchivedReportsForSitesThatNeedToBeArchivedAgain(1);
        $cronarchive->invalidateArchivedReportsForSitesThatNeedToBeArchivedAgain(2);

        /**
         * should look like this but the result is random
         *  array(
        array(array(1,2), '2014-04-05'),
        array(array(2), '2014-04-06')
        )
         */
        $invalidatedReports = $api->getInvalidatedReports();
        $this->assertCount(3, $invalidatedReports);

        usort($invalidatedReports, function ($a, $b) {
            return strcmp($a[1], $b[1]);
        });

        $this->assertSame(1, $invalidatedReports[0][0]);
        $this->assertSame('2014-04-05', $invalidatedReports[0][1]);

        $this->assertSame(2, $invalidatedReports[1][0]);
        $this->assertSame('2014-04-05', $invalidatedReports[1][1]);

        $this->assertSame(2, $invalidatedReports[2][0]);
        $this->assertSame('2014-04-06', $invalidatedReports[2][1]);
    }

    public function test_wasSegmentCreatedRecently()
    {
        Fixture::createWebsite('2014-12-12 00:01:02');

        Rules::setBrowserTriggerArchiving(false);
        SegmentAPI::getInstance()->add('foo', 'actions>=1', 1, true, true);
        $id = SegmentAPI::getInstance()->add('barb', 'actions>=2', 1, true, true);
        Rules::setBrowserTriggerArchiving(true);

        $segments = new Model();
        $segments->updateSegment($id, array('ts_created' => Date::now()->subHour(30)->getDatetime()));

        $allSegments = $segments->getSegmentsToAutoArchive(1);

        $cronarchive = new TestCronArchive(Fixture::getRootUrl() . 'tests/PHPUnit/proxy/index.php');
        $this->assertTrue($cronarchive->wasSegmentChangedRecently('actions>=1', $allSegments));

        // created 30 hours ago...
        $this->assertFalse($cronarchive->wasSegmentChangedRecently('actions>=2', $allSegments));

        // not configured segment
        $this->assertFalse($cronarchive->wasSegmentChangedRecently('actions>=999', $allSegments));
    }

    public function test_skipSegmentsToday()
    {
        \Piwik\Tests\Framework\Mock\FakeCliMulti::$specifiedResults = array(
            '/method=API.get/' => json_encode(array(array('nb_visits' => 1)))
        );

        Fixture::createWebsite('2014-12-12 00:01:02');
        Rules::setBrowserTriggerArchiving(false);
        SegmentAPI::getInstance()->add('foo', 'actions>=1', 1, true, true);
        $id = SegmentAPI::getInstance()->add('barb', 'actions>=2', 1, true, true);
        Rules::setBrowserTriggerArchiving(true);

        $segments = new Model();
        $segments->updateSegment($id, array('ts_created' => Date::now()->subHour(30)->getDatetime()));

        $logger = new FakeLogger();

        $archiver = new CronArchive(null, $logger);
        $archiver->init();
        $archiveFilter = new CronArchive\ArchiveFilter();
        $archiveFilter->setSkipSegmentsForToday(true);
        $archiver->setArchiveFilter($archiveFilter);
        $archiver->shouldArchiveAllSites = true;
        $archiver->shouldArchiveAllPeriodsSince = true;
        $archiver->init();
        $archiver->run();

        self::assertStringContainsString('Will skip segments archiving for today unless they were created recently', $logger->output);
        self::assertStringContainsString('Segment "actions>=1" was created or changed recently and will therefore archive today', $logger->output);
        self::assertStringNotContainsString('Segment "actions>=2" was created recently', $logger->output);
    }

    public function test_output()
    {
        \Piwik\Tests\Framework\Mock\FakeCliMulti::$specifiedResults = array(
            '/method=API.get/' => json_encode(array(array('nb_visits' => 1)))
        );

        Fixture::createWebsite('2014-12-12 00:01:02');
        Rules::setBrowserTriggerArchiving(false);
        SegmentAPI::getInstance()->add('foo', 'actions>=2', 1, true, true);
        SegmentAPI::getInstance()->add('burr', 'actions>=4', 1, true, true);
        Rules::setBrowserTriggerArchiving(true);

        $tracker = Fixture::getTracker(1, '2019-12-12 02:03:00');
        $tracker->setUrl('http://someurl.com');
        Fixture::checkResponse($tracker->doTrackPageView('abcdefg'));

        $tracker->setForceVisitDateTime('2019-12-11 03:04:05');
        $tracker->setUrl('http://someurl.com/2');
        Fixture::checkResponse($tracker->doTrackPageView('abcdefg2'));

        $tracker->setForceVisitDateTime('2019-12-10 03:04:05');
        $tracker->setUrl('http://someurl.com/3');
        Fixture::checkResponse($tracker->doTrackPageView('abcdefg3'));

        $tracker->setForceVisitDateTime('2019-12-02 03:04:05');
        $tracker->setUrl('http://someurl.com/4');
        Fixture::checkResponse($tracker->doTrackPageView('abcdefg4'));

        $logger = new FakeLogger();

        $archiver = new CronArchive(null, $logger);

        $archiveFilter = new CronArchive\ArchiveFilter();
        $archiveFilter->setSegmentsToForce(['actions>=2;browserCode=FF', 'actions>=2']);
        $archiver->setArchiveFilter($archiveFilter);

        $archiver->init();
        $archiver->run();

        $version = Version::VERSION;
        $expected = <<<LOG
---------------------------
INIT
Running Matomo $version as Super User
---------------------------
NOTES
- If you execute this script at least once per hour (or more often) in a crontab, you may disable 'Browser trigger archiving' in Matomo UI > Settings > General Settings.
  See the doc at: https://matomo.org/docs/setup-auto-archiving/
- Async process archiving supported, using CliMulti.
- Reports for today will be processed at most every 900 seconds. You can change this value in Matomo UI > Settings > General Settings.
- Limiting segment archiving to following segments:
  * actions>=2;browserCode=FF
  * actions>=2
---------------------------
START
Starting Matomo reports archiving...
Applying queued rearchiving...
Start processing archives for site 1.
Checking for queued invalidations...
  Will invalidate archived reports for 2019-12-12 for following websites ids: 1
  Will invalidate archived reports for 2019-12-11 for following websites ids: 1
  Will invalidate archived reports for 2019-12-10 for following websites ids: 1
  Will invalidate archived reports for 2019-12-02 for following websites ids: 1
  Today archive can be skipped due to no visits for idSite = 1, skipping invalidation...
  Yesterday archive can be skipped due to no visits for idSite = 1, skipping invalidation...
  Segment "actions>=2" was created or changed recently and will therefore archive today (for site ID = 1)
  Segment "actions>=4" was created or changed recently and will therefore archive today (for site ID = 1)
Done invalidating
Found invalidated archive we can skip (no visits): [idinvalidation = 75, idsite = 1, period = day(2020-02-03 - 2020-02-03), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 76, idsite = 1, period = week(2020-02-03 - 2020-02-09), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 74, idsite = 1, period = day(2020-02-02 - 2020-02-02), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 72, idsite = 1, period = day(2020-02-01 - 2020-02-01), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 73, idsite = 1, period = month(2020-02-01 - 2020-02-29), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 68, idsite = 1, period = week(2020-01-27 - 2020-02-02), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 65, idsite = 1, period = day(2020-01-01 - 2020-01-01), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 66, idsite = 1, period = month(2020-01-01 - 2020-01-31), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 67, idsite = 1, period = year(2020-01-01 - 2020-12-31), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 60, idsite = 1, period = day(2019-12-31 - 2019-12-31), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 58, idsite = 1, period = day(2019-12-30 - 2019-12-30), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 59, idsite = 1, period = week(2019-12-30 - 2020-01-05), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 56, idsite = 1, period = day(2019-12-23 - 2019-12-23), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 57, idsite = 1, period = week(2019-12-23 - 2019-12-29), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 54, idsite = 1, period = day(2019-12-16 - 2019-12-16), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Found invalidated archive we can skip (no visits): [idinvalidation = 55, idsite = 1, period = week(2019-12-16 - 2019-12-22), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Processing invalidation: [idinvalidation = 5, idsite = 1, period = day(2019-12-12 - 2019-12-12), name = donee0512c03f7c20af6ef96a8d792c6bb9f].
Processing invalidation: [idinvalidation = 17, idsite = 1, period = day(2019-12-11 - 2019-12-11), name = donee0512c03f7c20af6ef96a8d792c6bb9f].
Processing invalidation: [idinvalidation = 29, idsite = 1, period = day(2019-12-10 - 2019-12-10), name = donee0512c03f7c20af6ef96a8d792c6bb9f].
Starting archiving for ?module=API&method=CoreAdminHome.archiveReports&idSite=1&period=day&date=2019-12-12&format=json&segment=actions%3E%3D2&trigger=archivephp
Starting archiving for ?module=API&method=CoreAdminHome.archiveReports&idSite=1&period=day&date=2019-12-11&format=json&segment=actions%3E%3D2&trigger=archivephp
Starting archiving for ?module=API&method=CoreAdminHome.archiveReports&idSite=1&period=day&date=2019-12-10&format=json&segment=actions%3E%3D2&trigger=archivephp
Archived website id 1, period = day, date = 2019-12-12, segment = 'actions%3E%3D2', 0 visits found. Time elapsed: %fs
Archived website id 1, period = day, date = 2019-12-11, segment = 'actions%3E%3D2', 0 visits found. Time elapsed: %fs
Archived website id 1, period = day, date = 2019-12-10, segment = 'actions%3E%3D2', 0 visits found. Time elapsed: %fs
Found invalidated archive we can skip (no visits): [idinvalidation = 52, idsite = 1, period = day(2019-12-09 - 2019-12-09), name = donee0512c03f7c20af6ef96a8d792c6bb9f]
Processing invalidation: [idinvalidation = 53, idsite = 1, period = week(2019-12-09 - 2019-12-15), name = donee0512c03f7c20af6ef96a8d792c6bb9f].
Processing invalidation: [idinvalidation = 49, idsite = 1, period = day(2019-12-02 - 2019-12-02), name = donee0512c03f7c20af6ef96a8d792c6bb9f].
No next invalidated archive.
Starting archiving for ?module=API&method=CoreAdminHome.archiveReports&idSite=1&period=week&date=2019-12-09&format=json&segment=actions%3E%3D2&trigger=archivephp
Starting archiving for ?module=API&method=CoreAdminHome.archiveReports&idSite=1&period=day&date=2019-12-02&format=json&segment=actions%3E%3D2&trigger=archivephp
Archived website id 1, period = week, date = 2019-12-09, segment = 'actions%3E%3D2', 0 visits found. Time elapsed: %fs
Archived website id 1, period = day, date = 2019-12-02, segment = 'actions%3E%3D2', 0 visits found. Time elapsed: %fs
Processing invalidation: [idinvalidation = 50, idsite = 1, period = week(2019-12-02 - 2019-12-08), name = donee0512c03f7c20af6ef96a8d792c6bb9f].
No next invalidated archive.
Starting archiving for ?module=API&method=CoreAdminHome.archiveReports&idSite=1&period=week&date=2019-12-02&format=json&segment=actions%3E%3D2&trigger=archivephp
Archived website id 1, period = week, date = 2019-12-02, segment = 'actions%3E%3D2', 0 visits found. Time elapsed: %fs
Processing invalidation: [idinvalidation = 51, idsite = 1, period = month(2019-12-01 - 2019-12-31), name = donee0512c03f7c20af6ef96a8d792c6bb9f].
No next invalidated archive.
Starting archiving for ?module=API&method=CoreAdminHome.archiveReports&idSite=1&period=month&date=2019-12-01&format=json&segment=actions%3E%3D2&trigger=archivephp
Archived website id 1, period = month, date = 2019-12-01, segment = 'actions%3E%3D2', 0 visits found. Time elapsed: %fs
Processing invalidation: [idinvalidation = 64, idsite = 1, period = year(2019-01-01 - 2019-12-31), name = donee0512c03f7c20af6ef96a8d792c6bb9f].
No next invalidated archive.
Starting archiving for ?module=API&method=CoreAdminHome.archiveReports&idSite=1&period=year&date=2019-01-01&format=json&segment=actions%3E%3D2&trigger=archivephp
Archived website id 1, period = year, date = 2019-01-01, segment = 'actions%3E%3D2', 0 visits found. Time elapsed: %fs
No next invalidated archive.
Finished archiving for site 1, 8 API requests, Time elapsed: %fs [1 / 1 done]
No more sites left to archive, stopping.
Done archiving!
---------------------------
SUMMARY
Processed 8 archives.
Total API requests: 8
done: 8 req, %d ms, no error
Time elapsed: %fs
LOG;

        // remove a bunch of debug lines since we can't have a sprintf format that long
        $output = $this->cleanOutput($logger->output);

        $this->assertStringMatchesFormat($expected, $output);
    }

    private function cleanOutput($output)
    {
        $output = explode("\n", $output);
        $output = array_filter($output, function ($l) { return strpos($l, 'Skipping invalidated archive') === false; });
        $output = array_filter($output, function ($l) { return strpos($l, 'Found archive with intersecting period') === false; });
        $output = array_filter($output, function ($l) { return strpos($l, 'Found duplicate invalidated archive') === false; });
        $output = array_filter($output, function ($l) { return strpos($l, 'No usable archive exists') === false; });
        $output = implode("\n", $output);
        return $output;
    }

    public function test_shouldNotStopProcessingWhenOneSiteIsInvalid()
    {
        \Piwik\Tests\Framework\Mock\FakeCliMulti::$specifiedResults = array(
            '/method=API.get/' => json_encode(array(array('nb_visits' => 1)))
        );

        Fixture::createWebsite('2014-12-12 00:01:02');

        $logger = new FakeLogger();

        $archiver = new CronArchive(null, $logger);
        $archiver->shouldArchiveSpecifiedSites = array(99999, 1);
        $archiver->init();
        $archiver->run();

        $expected = <<<LOG
- Will process 2 websites (--force-idsites)
- Will process specified sites: 1
---------------------------
START
Starting Matomo reports archiving...
Applying queued rearchiving...
Start processing archives for site 1.
Checking for queued invalidations...
  Today archive can be skipped due to no visits for idSite = 1, skipping invalidation...
  Yesterday archive can be skipped due to no visits for idSite = 1, skipping invalidation...
Done invalidating
No next invalidated archive.
LOG;

        self::assertStringContainsString($expected, $logger->output);
    }

    public function provideContainerConfig()
    {
        Date::$now = strtotime('2020-02-03 04:05:06');

        return array(
            'Piwik\CliMulti' => \DI\create('Piwik\Tests\Framework\Mock\FakeCliMulti')
        );
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->createSuperUser = true;
    }
}

class TestCronArchive extends CronArchive
{
    protected function checkPiwikUrlIsValid()
    {
    }

    protected function initPiwikHost($piwikUrl = false)
    {
    }

    public function wasSegmentChangedRecently($definition, $allSegments)
    {
        return parent::wasSegmentChangedRecently($definition, $allSegments);
    }
}
