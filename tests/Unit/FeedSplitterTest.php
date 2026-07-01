<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Tests\Unit;

use OCA\GustoIcalCleanerUpper\Service\FeedSplitter;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FeedSplitterTest extends TestCase {
    private const SAMPLE = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Gusto//GustoCalendar 1.0//EN
X-WR-CALNAME:Test Cal
BEGIN:VTIMEZONE
TZID:America/Los_Angeles
BEGIN:STANDARD
DTSTART:20201101T020000
TZOFFSETFROM:-0700
TZOFFSETTO:-0800
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
UID:shift@x
SUMMARY:Imaging Specialist
DTSTART;TZID=America/Los_Angeles:20260115T090000
END:VEVENT
BEGIN:VEVENT
UID:payday@x
SUMMARY:Estimated payday
DTSTART;VALUE=DATE:20260131
END:VEVENT
BEGIN:VEVENT
UID:sick@x
SUMMARY:Your Sick time
DTSTART;VALUE=DATE:20260201
END:VEVENT
BEGIN:VEVENT
UID:bday@x
SUMMARY:Alex B's birthday
DTSTART;VALUE=DATE:20260205
END:VEVENT
BEGIN:VEVENT
UID:anniv@x
SUMMARY:Sam Q's 3-year anniversary
DTSTART;VALUE=DATE:20260206
END:VEVENT
BEGIN:VEVENT
UID:ooo@x
SUMMARY:Jordan T - OOO
DTSTART;VALUE=DATE:20260210
END:VEVENT
BEGIN:VEVENT
UID:firstday@x
SUMMARY:Robin K's first day
DTSTART;VALUE=DATE:20260212
END:VEVENT
BEGIN:VEVENT
UID:lowerooo@x
SUMMARY:Casey - ooo
DTSTART;VALUE=DATE:20260211
END:VEVENT
END:VCALENDAR
ICS;

    private function split(string $body): \OCA\GustoIcalCleanerUpper\Service\SplitResult {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($body);
        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $splitter = new FeedSplitter($clientService, $this->createMock(LoggerInterface::class));
        return $splitter->fetchAndSplit('https://x/y.ics');
    }

    public function testWorkBucketKeepsShiftsPaydaysAndTimeOff(): void {
        $result = $this->split(self::SAMPLE);
        $this->assertArrayHasKey('shift@x', $result->workObjects);
        $this->assertArrayHasKey('payday@x', $result->workObjects);
        $this->assertArrayHasKey('sick@x', $result->workObjects);
    }

    public function testHrBucketKeepsBirthdayAnniversaryFirstDayAndOoo(): void {
        $result = $this->split(self::SAMPLE);
        $this->assertArrayHasKey('bday@x', $result->otherObjects);
        $this->assertArrayHasKey('anniv@x', $result->otherObjects);
        $this->assertArrayHasKey('firstday@x', $result->otherObjects);
        $this->assertArrayHasKey('ooo@x', $result->otherObjects);
        $this->assertArrayNotHasKey('firstday@x', $result->workObjects);
    }

    public function testOooMatchIsCaseInsensitive(): void {
        // Lowercase "- ooo" is treated the same as "- OOO", so it lands in HR.
        $result = $this->split(self::SAMPLE);
        $this->assertArrayHasKey('lowerooo@x', $result->otherObjects);
        $this->assertArrayNotHasKey('lowerooo@x', $result->workObjects);
    }

    public function testFirstDayRequiresPossessivePhrase(): void {
        // "first day" alone (no "'s") must not be misrouted to HR.
        $body = str_replace("Robin K's first day", 'Store first day sale', self::SAMPLE);
        $result = $this->split($body);
        $this->assertArrayHasKey('firstday@x', $result->workObjects);
        $this->assertArrayNotHasKey('firstday@x', $result->otherObjects);
    }

    public function testBirthdayMatchIsCaseInsensitive(): void {
        $body = str_replace("birthday", "BIRTHDAY", self::SAMPLE);
        $result = $this->split($body);
        $this->assertArrayHasKey('bday@x', $result->otherObjects);
    }

    public function testSourceNameAndTimezoneArePreserved(): void {
        $result = $this->split(self::SAMPLE);
        $this->assertSame('Test Cal', $result->calendarName);
        $this->assertStringContainsString('BEGIN:VTIMEZONE', $result->workObjects['shift@x']);
    }

    public function testAllDayObjectsCarryNoTimezone(): void {
        // All-day (VALUE=DATE) events reference no TZID, so their objects ship
        // with no VTIMEZONE. Timed events still carry the zone they reference.
        $result = $this->split(self::SAMPLE);
        $this->assertStringNotContainsString('BEGIN:VTIMEZONE', $result->workObjects['payday@x']);
        $this->assertStringNotContainsString('BEGIN:VTIMEZONE', $result->otherObjects['bday@x']);
        $this->assertStringContainsString('BEGIN:VTIMEZONE', $result->workObjects['shift@x']);
    }
}
