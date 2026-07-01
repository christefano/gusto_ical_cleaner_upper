<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\GustoIcalCleanerUpper\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Sabre\DAV\PropPatch;

/**
 * Owns all CalDAV writes: creating and renaming managed calendars, delta-syncing
 * their objects, removing calendars whose feed is gone, and cleaning up state.
 *
 * Managed calendars use URIs of the form "gusto-<8 hex>-work" or "gusto-<8 hex>-hr".
 */
class CalendarMirror {
    private const HASH_PREFIX = 'hashes_';

    public function __construct(
        private CalDavBackend $calDavBackend,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ensure a calendar with this URI exists for the principal and carries the
     * given display name. Returns its id, or null if it can't be created.
     */
    public function ensureCalendar(string $principal, string $uri, string $name, string $color = ''): ?int {
        $existing = $this->calDavBackend->getCalendarByUri($principal, $uri);
        if ($existing !== null) {
            $id = (int)$existing['id'];
            if ((string)($existing['{DAV:}displayname'] ?? '') !== $name) {
                $this->updateDisplayName($id, $name);
            }
            return $id;
        }
        try {
            $properties = ['{DAV:}displayname' => $name];
            if ($color !== '') {
                // Only set a color when one is configured; otherwise let
                // Nextcloud assign its own default.
                $properties['{http://apple.com/ns/ical/}calendar-color'] = $color;
            }
            return $this->calDavBackend->createCalendar($principal, $uri, $properties);
        } catch (\Throwable $e) {
            $this->logger->error('Gusto iCal Cleaner Upper: could not create calendar', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Delta-sync the events into the calendar: create new objects, update
     * changed ones, and delete removed ones. Unchanged objects are left alone so
     * their ETags stay stable and clients sync only real differences. Change
     * detection uses a per-calendar content-hash map in app config.
     *
     * @param array<string,string> $objects UID => serialized VCALENDAR
     */
    public function sync(int $calendarId, array $objects): void {
        $desired = [];
        foreach ($objects as $uid => $data) {
            $desired[md5((string)$uid) . '.ics'] = $data;
        }

        $existing = [];
        foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $obj) {
            $existing[$obj['uri']] = true;
        }

        $hashKey = self::HASH_PREFIX . $calendarId;
        $storedHashes = json_decode($this->config->getAppValue(Application::APP_ID, $hashKey, '{}'), true);
        if (!is_array($storedHashes)) {
            $storedHashes = [];
        }
        $newHashes = [];

        foreach ($desired as $uri => $data) {
            $hash = sha1($data);
            $newHashes[$uri] = $hash;
            $present = isset($existing[$uri]);
            if ($present && ($storedHashes[$uri] ?? null) === $hash) {
                continue;
            }
            try {
                if ($present) {
                    $this->calDavBackend->updateCalendarObject($calendarId, $uri, $data);
                } else {
                    $this->calDavBackend->createCalendarObject($calendarId, $uri, $data);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Gusto iCal Cleaner Upper: failed to write object', ['uri' => $uri, 'exception' => $e]);
                unset($newHashes[$uri]);
            }
        }

        foreach (array_keys($existing) as $uri) {
            if (!isset($desired[$uri])) {
                try {
                    $this->calDavBackend->deleteCalendarObject($calendarId, $uri);
                } catch (\Throwable $e) {
                    $this->logger->warning('Gusto iCal Cleaner Upper: failed to delete object', ['uri' => $uri, 'exception' => $e]);
                }
            }
        }

        // Only persist when the map actually changed (== ignores key order),
        // so an unchanged calendar costs zero writes.
        if ($newHashes != $storedHashes) {
            $this->config->setAppValue(Application::APP_ID, $hashKey, json_encode($newHashes));
        }
    }

    /**
     * Delete managed calendars for this principal that are no longer active
     * (their feed/subscription is gone). Only calendars matching the managed URI
     * pattern are ever touched, so the user's own calendars are safe.
     *
     * @param string[] $activeUris managed URIs that should be kept
     */
    public function removeStaleCalendars(string $principal, array $activeUris): void {
        $keep = array_flip($activeUris);
        foreach ($this->calDavBackend->getCalendarsForUser($principal) as $calendar) {
            $uri = (string)($calendar['uri'] ?? '');
            if (!Feed::isManagedUri($uri) || isset($keep[$uri])) {
                continue;
            }
            $id = (int)$calendar['id'];
            try {
                $this->calDavBackend->deleteCalendar($id);
                $this->config->deleteAppValue(Application::APP_ID, self::HASH_PREFIX . $id);
                $this->logger->info('Gusto iCal Cleaner Upper: removed stale managed calendar', ['uri' => $uri]);
            } catch (\Throwable $e) {
                $this->logger->warning('Gusto iCal Cleaner Upper: could not remove stale calendar', ['uri' => $uri, 'exception' => $e]);
            }
        }
    }

    /**
     * Delete every managed calendar for this principal, whatever its feed. Used
     * by the uninstall repair step. Their hash maps go with them.
     */
    public function purgeManagedCalendars(string $principal): void {
        $this->removeStaleCalendars($principal, []);
    }

    /**
     * Cleanup step: drop per-calendar hash maps left behind by calendars that
     * have since been deleted. Calendars synced this run are known to exist, so
     * they're skipped without a lookup; only the rest are verified.
     *
     * @param int[] $activeCalendarIds calendar ids synced this run
     */
    public function pruneOrphanHashes(array $activeCalendarIds): void {
        $active = array_flip($activeCalendarIds);
        foreach ($this->config->getAppKeys(Application::APP_ID) as $key) {
            if (!str_starts_with($key, self::HASH_PREFIX)) {
                continue;
            }
            $calendarId = (int)substr($key, strlen(self::HASH_PREFIX));
            if (isset($active[$calendarId])) {
                continue;
            }
            if ($this->calDavBackend->getCalendarById($calendarId) === null) {
                $this->config->deleteAppValue(Application::APP_ID, $key);
                $this->logger->info('Gusto iCal Cleaner Upper: pruned hash map for deleted calendar', ['calendarId' => $calendarId]);
            }
        }
    }

    private function updateDisplayName(int $calendarId, string $name): void {
        try {
            $propPatch = new PropPatch(['{DAV:}displayname' => $name]);
            $this->calDavBackend->updateCalendar($calendarId, $propPatch);
            $propPatch->commit();
        } catch (\Throwable $e) {
            $this->logger->warning('Gusto iCal Cleaner Upper: could not update calendar name', ['exception' => $e]);
        }
    }
}
