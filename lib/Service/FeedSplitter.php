<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Fetches a remote iCal feed and splits its events into two buckets:
 *  - "other" (the HR calendar): titles containing "birthday", "anniversary",
 *    "'s first day", or "- OOO" (all case-insensitive).
 *  - "work" (everything else): shifts, paydays, sick and time off.
 */
class FeedSplitter {
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchAndSplit(string $feedUrl): SplitResult {
        $url = Feed::normalizeUrl($feedUrl);

        $client = $this->clientService->newClient();
        $response = $client->get($url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'text/calendar, */*'],
            // Gusto serves the .ics directly. Don't follow redirects, so the
            // validated gusto.com host can't be bounced to an internal address
            // (SSRF hardening on top of Nextcloud's local-address blocking).
            'allow_redirects' => false,
        ]);

        /** @var VCalendar $vobj */
        $vobj = Reader::read((string)$response->getBody());

        $sourceName = trim((string)($vobj->{'X-WR-CALNAME'} ?? ''));
        $calendarName = $sourceName !== '' ? $sourceName : null;

        $timezones = [];
        $workByUid = [];
        $otherByUid = [];
        $work = 0;
        $other = 0;

        foreach ($vobj->getComponents() as $comp) {
            if ($comp->name === 'VTIMEZONE') {
                $timezones[(string)$comp->TZID] = $comp;
                continue;
            }
            if ($comp->name !== 'VEVENT') {
                continue;
            }
            $summary = (string)($comp->SUMMARY ?? '');
            // Group by UID. Synthesize a stable key for the rare UID-less event
            // so distinct ones can't collapse into a single merged object.
            $uid = (string)($comp->UID ?? '');
            if ($uid === '') {
                $uid = 'no-uid-' . sha1($comp->serialize());
            }
            if (EventCategory::isHr($summary)) {
                $otherByUid[$uid][] = $comp;
                $other++;
            } else {
                $workByUid[$uid][] = $comp;
                $work++;
            }
        }

        $this->logger->info('Gusto iCal Cleaner Upper: split feed', ['work' => $work, 'other' => $other]);

        return new SplitResult(
            $calendarName,
            $this->buildObjects($workByUid, $timezones),
            $this->buildObjects($otherByUid, $timezones),
        );
    }

    /**
     * @param array<string,array> $eventsByUid
     * @return array<string,string> UID => serialized VCALENDAR
     */
    private function buildObjects(array $eventsByUid, array $timezones): array {
        $objects = [];
        foreach ($eventsByUid as $uid => $events) {
            $cal = new VCalendar();
            $referenced = $this->referencedTzids($events);
            foreach ($timezones as $tzid => $tz) {
                // Only carry a VTIMEZONE that some event actually references.
                // All-day (VALUE=DATE) events reference none, so they ship
                // with no timezone at all.
                if (isset($referenced[$tzid])) {
                    $cal->add(clone $tz);
                }
            }
            foreach ($events as $event) {
                $cal->add(clone $event);
            }
            $objects[(string)$uid] = $cal->serialize();
        }
        return $objects;
    }

    /**
     * Collect the set of TZIDs actually referenced by these events, from the
     * TZID parameter on any property (DTSTART, DTEND, RECURRENCE-ID, EXDATE, etc).
     *
     * @param array $events VEVENT components sharing a UID
     * @return array<string,true> referenced TZID => true
     */
    private function referencedTzids(array $events): array {
        $referenced = [];
        foreach ($events as $event) {
            foreach ($event->children() as $property) {
                $tzid = $property['TZID'] ?? null;
                if ($tzid !== null) {
                    $referenced[(string)$tzid] = true;
                }
            }
        }
        return $referenced;
    }
}
