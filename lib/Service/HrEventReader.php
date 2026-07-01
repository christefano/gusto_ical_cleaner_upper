<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IDateTimeZone;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

/**
 * Reads events back out of a user's managed HR calendars for the dashboard
 * widgets. Only calendars matching the managed "-hr" URI pattern are touched,
 * never the user's own calendars. No new storage: this reads what the sync job
 * already wrote.
 */
class HrEventReader {
    public function __construct(
        private CalDavBackend $calDavBackend,
        private IDateTimeZone $dateTimeZone,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Out-of-office events overlapping today, in the user's timezone.
     *
     * @return HrEvent[]
     */
    public function outToday(string $userId): array {
        $tz = $this->dateTimeZone->getTimeZone();
        $today = new \DateTimeImmutable('today', $tz);
        $tomorrow = $today->modify('+1 day');

        $out = [];
        foreach ($this->readHrEvents($userId) as $event) {
            if ($event->category !== EventCategory::OOO) {
                continue;
            }
            // All-day OOO spans [start, end); a single-day event ends the next
            // day. Include it when today falls inside that span.
            $end = $event->date >= $today ? $event->date->modify('+1 day') : $tomorrow;
            if ($event->date <= $today && $today < $end) {
                $out[] = $event;
            }
        }
        return $out;
    }

    /**
     * Upcoming birthdays, anniversaries, and first days within the window,
     * sorted soonest first.
     *
     * @return HrEvent[]
     */
    public function celebrations(string $userId, int $windowDays): array {
        $tz = $this->dateTimeZone->getTimeZone();
        $today = new \DateTimeImmutable('today', $tz);
        $end = $today->modify('+' . max(0, $windowDays) . ' days');

        $celebrations = [];
        foreach ($this->readHrEvents($userId) as $event) {
            if ($event->category === EventCategory::OOO || $event->category === EventCategory::WORK) {
                continue;
            }
            if ($event->date >= $today && $event->date <= $end) {
                $celebrations[] = $event;
            }
        }
        usort($celebrations, static fn (HrEvent $a, HrEvent $b) => $a->date <=> $b->date);
        return $celebrations;
    }

    /**
     * Parse every event out of the user's managed HR calendars.
     *
     * @return HrEvent[]
     */
    private function readHrEvents(string $userId): array {
        $tz = $this->dateTimeZone->getTimeZone();
        $principal = 'principals/users/' . $userId;
        $events = [];

        foreach ($this->calDavBackend->getCalendarsForUser($principal) as $calendar) {
            $uri = (string)($calendar['uri'] ?? '');
            if (!Feed::isManagedUri($uri) || !str_ends_with($uri, '-hr')) {
                continue;
            }
            $calendarId = (int)$calendar['id'];
            $uris = array_map(
                static fn (array $o): string => (string)$o['uri'],
                $this->calDavBackend->getCalendarObjects($calendarId),
            );
            if ($uris === []) {
                continue;
            }
            foreach ($this->calDavBackend->getMultipleCalendarObjects($calendarId, $uris) as $object) {
                $event = $this->parse((string)($object['calendardata'] ?? ''), $tz);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }
        return $events;
    }

    private function parse(string $calendarData, \DateTimeZone $tz): ?HrEvent {
        if ($calendarData === '') {
            return null;
        }
        try {
            $vobj = Reader::read($calendarData);
            $vevent = $vobj->VEVENT;
            if ($vevent === null) {
                return null;
            }
            $summary = (string)($vevent->SUMMARY ?? '');
            // Interpret the (all-day) start as a date in the user's timezone.
            $dateStr = $vevent->DTSTART->getDateTime()->format('Y-m-d');
            $date = new \DateTimeImmutable($dateStr, $tz);
            return new HrEvent(EventCategory::of($summary), $summary, $date);
        } catch (\Throwable $e) {
            $this->logger->warning('Gusto iCal Cleaner Upper: could not parse HR event', ['exception' => $e]);
            return null;
        }
    }
}
