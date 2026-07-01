<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Tests\Unit;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\GustoIcalCleanerUpper\Service\CalendarMirror;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CalendarMirrorTest extends TestCase {
    private const APP_ID = 'gusto_ical_cleaner_upper';

    private function makeMirror(CalDavBackend $backend, IConfig $config): CalendarMirror {
        return new CalendarMirror($backend, $config, $this->createMock(LoggerInterface::class));
    }

    public function testSyncCreatesNewObjectsAndStoresHashes(): void {
        $backend = $this->createMock(CalDavBackend::class);
        $backend->method('getCalendarObjects')->willReturn([]);
        $uri = md5('uid-a') . '.ics';
        $backend->expects($this->once())->method('createCalendarObject')->with(7, $uri, 'DATA-A');
        $backend->expects($this->never())->method('deleteCalendarObject');

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('{}');
        $config->expects($this->once())->method('setAppValue')->with(
            self::APP_ID,
            'hashes_7',
            $this->callback(fn ($json) => json_decode($json, true) === [$uri => sha1('DATA-A')]),
        );

        $this->makeMirror($backend, $config)->sync(7, ['uid-a' => 'DATA-A']);
    }

    public function testSyncDeletesObjectsNoLongerInSource(): void {
        $backend = $this->createMock(CalDavBackend::class);
        $backend->method('getCalendarObjects')->willReturn([['uri' => 'stale.ics']]);
        $backend->expects($this->once())->method('deleteCalendarObject')->with(7, 'stale.ics');
        $backend->expects($this->never())->method('createCalendarObject');

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('{}');

        $this->makeMirror($backend, $config)->sync(7, []);
    }

    public function testSyncSkipsUnchangedObjects(): void {
        $uri = md5('uid-a') . '.ics';
        $backend = $this->createMock(CalDavBackend::class);
        $backend->method('getCalendarObjects')->willReturn([['uri' => $uri]]);
        $backend->expects($this->never())->method('createCalendarObject');
        $backend->expects($this->never())->method('updateCalendarObject');
        $backend->expects($this->never())->method('deleteCalendarObject');

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn(json_encode([$uri => sha1('DATA-A')]));
        $config->expects($this->never())->method('setAppValue');

        $this->makeMirror($backend, $config)->sync(7, ['uid-a' => 'DATA-A']);
    }

    public function testEnsureCalendarKeepsUserRenamedDisplayName(): void {
        $backend = $this->createMock(CalDavBackend::class);
        $backend->method('getCalendarByUri')->willReturn([
            'id' => 9,
            '{DAV:}displayname' => 'My Custom Name',
        ]);
        // Existing calendar: never rewrite the name, just return the id.
        $backend->expects($this->never())->method('updateCalendar');
        $backend->expects($this->never())->method('createCalendar');

        $config = $this->createMock(IConfig::class);
        $id = $this->makeMirror($backend, $config)
            ->ensureCalendar('principals/users/alice', 'gusto-aaaaaaaa-work', 'Feed Name');
        $this->assertSame(9, $id);
    }

    public function testRemoveStaleCalendarsDeletesOnlyInactiveManagedOnes(): void {
        $backend = $this->createMock(CalDavBackend::class);
        $backend->method('getCalendarsForUser')->willReturn([
            ['id' => 1, 'uri' => 'gusto-aaaaaaaa-work'], // active, keep
            ['id' => 2, 'uri' => 'gusto-bbbbbbbb-hr'],   // managed but inactive, delete
            ['id' => 3, 'uri' => 'personal'],            // not managed, never touch
        ]);
        $backend->expects($this->once())->method('deleteCalendar')->with(2);

        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())->method('deleteAppValue')->with(self::APP_ID, 'hashes_2');

        $this->makeMirror($backend, $config)
            ->removeStaleCalendars('principals/users/alice', ['gusto-aaaaaaaa-work']);
    }

    public function testPruneOrphanHashesChecksOnlyInactiveCalendars(): void {
        $backend = $this->createMock(CalDavBackend::class);
        $backend->expects($this->never())->method('getCalendarById'); // 5 is active

        $config = $this->createMock(IConfig::class);
        $config->method('getAppKeys')->willReturn(['hashes_5']);
        $config->expects($this->never())->method('deleteAppValue');

        $this->makeMirror($backend, $config)->pruneOrphanHashes([5]);
    }
}
