<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Tests\Unit;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\GustoIcalCleanerUpper\Service\EventCategory;
use OCA\GustoIcalCleanerUpper\Service\HrEventReader;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HrEventReaderTest extends TestCase {
    private \DateTimeZone $tz;
    private \DateTimeImmutable $today;

    protected function setUp(): void {
        $this->tz = new \DateTimeZone('UTC');
        $this->today = new \DateTimeImmutable('today', $this->tz);
    }

    /** Build a one-event all-day VCALENDAR string. */
    private function ics(string $uid, string $summary, \DateTimeImmutable $start, ?\DateTimeImmutable $end = null): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//EN',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'SUMMARY:' . $summary,
            'DTSTART;VALUE=DATE:' . $start->format('Ymd'),
        ];
        if ($end !== null) {
            $lines[] = 'DTEND;VALUE=DATE:' . $end->format('Ymd');
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines);
    }

    /**
     * @param array<string,string> $objects uri => calendardata
     */
    private function reader(array $objects, array $calendars): HrEventReader {
        $backend = $this->createMock(CalDavBackend::class);
        $backend->method('getCalendarsForUser')->willReturn($calendars);
        $backend->method('getCalendarObjects')->willReturn(
            array_map(static fn (string $uri): array => ['uri' => $uri], array_keys($objects)),
        );
        $backend->method('getMultipleCalendarObjects')->willReturn(
            array_map(static fn (string $data): array => ['calendardata' => $data], array_values($objects)),
        );

        $dateTimeZone = $this->createMock(IDateTimeZone::class);
        $dateTimeZone->method('getTimeZone')->willReturn($this->tz);

        return new HrEventReader($backend, $dateTimeZone, $this->createMock(LoggerInterface::class));
    }

    private const HR_CAL = ['id' => 1, 'uri' => 'gusto-abcd1234-hr'];

    public function testOutTodayReturnsOnlyOooOverlappingToday(): void {
        $objects = [
            'a.ics' => $this->ics('a', 'Jordan T - OOO', $this->today),
            'b.ics' => $this->ics('b', 'Past T - OOO', $this->today->modify('-3 days')),
            'c.ics' => $this->ics('c', 'Span T - OOO', $this->today->modify('-1 day'), $this->today->modify('+1 day')),
            // Multi-day OOO that already ended (DTEND exclusive) must be excluded.
            'e.ics' => $this->ics('e', 'Ended T - OOO', $this->today->modify('-3 days'), $this->today->modify('-1 day')),
            'd.ics' => $this->ics('d', "Alex B's birthday", $this->today),
        ];
        $out = $this->reader($objects, [self::HR_CAL])->outToday('frodo');
        $summaries = array_map(static fn ($e) => $e->summary, $out);
        sort($summaries);
        $this->assertSame(['Jordan T - OOO', 'Span T - OOO'], $summaries);
        foreach ($out as $event) {
            $this->assertSame(EventCategory::OOO, $event->category);
        }
    }

    public function testCelebrationsReturnsUpcomingWithinWindowSorted(): void {
        $objects = [
            'a.ics' => $this->ics('a', "Now B's birthday", $this->today),
            'b.ics' => $this->ics('b', "Soon Q's 3-year anniversary", $this->today->modify('+3 days')),
            'c.ics' => $this->ics('c', "Late K's first day", $this->today->modify('+10 days')),
            'd.ics' => $this->ics('d', 'Jordan T - OOO', $this->today->modify('+2 days')),
            'e.ics' => $this->ics('e', "Past B's birthday", $this->today->modify('-1 day')),
        ];
        $celebrations = $this->reader($objects, [self::HR_CAL])->celebrations('frodo', 7);
        $summaries = array_map(static fn ($e) => $e->summary, $celebrations);
        // Within 7 days, no OOO, no past, sorted soonest first.
        $this->assertSame(["Now B's birthday", "Soon Q's 3-year anniversary"], $summaries);
    }

    public function testOnlyManagedHrCalendarsAreRead(): void {
        $objects = ['a.ics' => $this->ics('a', 'Jordan T - OOO', $this->today)];
        $calendars = [
            ['id' => 2, 'uri' => 'gusto-abcd1234-work'], // managed work, skip
            ['id' => 3, 'uri' => 'personal'],            // user's own, skip
            self::HR_CAL,                                 // managed hr, read
        ];
        $out = $this->reader($objects, $calendars)->outToday('frodo');
        $this->assertCount(1, $out);
    }
}
