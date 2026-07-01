<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Tests\Unit;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\GustoIcalCleanerUpper\BackgroundJob\SyncJob;
use OCA\GustoIcalCleanerUpper\Service\CalendarMirror;
use OCA\GustoIcalCleanerUpper\Service\Feed;
use OCA\GustoIcalCleanerUpper\Service\FeedSplitter;
use OCA\GustoIcalCleanerUpper\Service\SplitResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SyncJobTest extends TestCase {
    private const RAW_FEED = 'webcal://app.gusto.com/calendars/X.ics';
    private const PRINCIPAL = 'principals/users/alice';

    private string $feedUrl;
    private string $base;
    private string $workUri;
    private string $hrUri;

    /** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $config;
    /** @var FeedSplitter&\PHPUnit\Framework\MockObject\MockObject */
    private $splitter;
    /** @var CalendarMirror&\PHPUnit\Framework\MockObject\MockObject */
    private $mirror;
    /** @var CalDavBackend&\PHPUnit\Framework\MockObject\MockObject */
    private $backend;

    protected function setUp(): void {
        $this->feedUrl = Feed::normalizeUrl(self::RAW_FEED);
        $this->base = substr(sha1($this->feedUrl), 0, 8);
        $this->workUri = 'gusto-' . $this->base . '-work';
        $this->hrUri = 'gusto-' . $this->base . '-hr';

        $this->config = $this->createMock(IConfig::class);
        // Only target_user is set (to alice); everything else uses its default.
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $key === 'target_user' ? 'alice' : $default
        );

        $this->splitter = $this->createMock(FeedSplitter::class);
        $this->mirror = $this->createMock(CalendarMirror::class);

        $this->backend = $this->createMock(CalDavBackend::class);
        $this->backend->method('getSubscriptionsForUser')->willReturn([
            ['source' => self::RAW_FEED],
        ]);
    }

    private function run(): void {
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('userExists')->willReturn(true);

        $job = new SyncJob(
            $this->createMock(ITimeFactory::class),
            $this->config,
            $this->createMock(LoggerInterface::class),
            $this->splitter,
            $this->mirror,
            $this->backend,
            $userManager,
        );

        $method = new \ReflectionMethod($job, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);
    }

    public function testHappyPathCreatesAndSyncsBothCalendars(): void {
        $this->splitter->method('fetchAndSplit')->with($this->feedUrl)
            ->willReturn(new SplitResult('My Cal', ['w1' => 'WD'], ['o1' => 'OD']));

        $this->mirror->method('ensureCalendar')->willReturnMap([
            [self::PRINCIPAL, $this->workUri, 'My Cal', '', 11],
            [self::PRINCIPAL, $this->hrUri, 'My Cal (HR)', '', 22],
        ]);

        $syncCalls = [];
        $this->mirror->method('sync')->willReturnCallback(
            function (int $id, array $objects) use (&$syncCalls): void {
                $syncCalls[] = [$id, $objects];
            }
        );

        $this->mirror->expects($this->once())->method('removeStaleCalendars')
            ->with(self::PRINCIPAL, [$this->workUri, $this->hrUri]);
        $this->mirror->expects($this->once())->method('pruneOrphanHashes')
            ->with([11, 22]);

        $this->run();

        $this->assertSame([[11, ['w1' => 'WD']], [22, ['o1' => 'OD']]], $syncCalls);
    }

    public function testFeedFetchFailureKeepsCalendars(): void {
        $this->splitter->method('fetchAndSplit')->willThrowException(new \RuntimeException('boom'));

        // Nothing is written, but the calendars are NOT removed: their URIs are
        // still passed to removeStaleCalendars so they survive the outage.
        $this->mirror->expects($this->never())->method('ensureCalendar');
        $this->mirror->expects($this->never())->method('sync');
        $this->mirror->expects($this->once())->method('removeStaleCalendars')
            ->with(self::PRINCIPAL, [$this->workUri, $this->hrUri]);
        $this->mirror->expects($this->once())->method('pruneOrphanHashes')->with([]);

        $this->run();
    }

    public function testEmptyFeedIsSkippedAndCalendarsKept(): void {
        $this->splitter->method('fetchAndSplit')
            ->willReturn(new SplitResult('My Cal', [], []));

        $this->mirror->expects($this->never())->method('ensureCalendar');
        $this->mirror->expects($this->never())->method('sync');
        $this->mirror->expects($this->once())->method('removeStaleCalendars')
            ->with(self::PRINCIPAL, [$this->workUri, $this->hrUri]);
        $this->mirror->expects($this->once())->method('pruneOrphanHashes')->with([]);

        $this->run();
    }
}
