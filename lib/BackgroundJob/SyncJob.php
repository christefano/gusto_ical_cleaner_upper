<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\BackgroundJob;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\GustoIcalCleanerUpper\AppInfo\Application;
use OCA\GustoIcalCleanerUpper\Service\CalendarMirror;
use OCA\GustoIcalCleanerUpper\Service\Feed;
use OCA\GustoIcalCleanerUpper\Service\FeedSplitter;
use OCA\GustoIcalCleanerUpper\Service\SplitResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Orchestration. For each target user, gather their Gusto feeds (discovered from
 * webcal subscriptions, plus any in feed_url), split each feed into a work and an
 * HR calendar, then remove managed calendars whose feed is gone.
 */
class SyncJob extends TimedJob {
    private const FALLBACK_CALENDAR_NAME = 'Gusto';
    private const DEFAULT_INTERVAL = 3600; // seconds

    /** @var array<string,?SplitResult> normalized feed URL => result (null = failed), per run */
    private array $splitCache = [];

    public function __construct(
        ITimeFactory $time,
        private IConfig $config,
        private LoggerInterface $logger,
        private FeedSplitter $splitter,
        private CalendarMirror $mirror,
        private CalDavBackend $calDavBackend,
        private IUserManager $userManager,
    ) {
        parent::__construct($time);
        $interval = (int)$this->appValue('interval', (string)self::DEFAULT_INTERVAL);
        $this->setInterval($interval > 0 ? $interval : self::DEFAULT_INTERVAL);
    }

    protected function run($argument): void {
        $uids = $this->getTargetUids();
        if ($uids === []) {
            $this->logger->warning('Gusto iCal Cleaner Upper: no target users found');
            return;
        }

        $manualFeeds = $this->manualFeeds();
        $color = $this->appValue('calendar_color', '');
        $this->splitCache = [];
        $activeCalendarIds = [];

        foreach ($uids as $uid) {
            try {
                $activeCalendarIds = array_merge($activeCalendarIds, $this->processUser($uid, $manualFeeds, $color));
            } catch (\Throwable $e) {
                // Isolate failures so one user can't abort the rest of the run.
                $this->logger->error('Gusto iCal Cleaner Upper: failed processing user', ['user' => $uid, 'exception' => $e]);
            }
        }

        $this->mirror->pruneOrphanHashes($activeCalendarIds);
    }

    /**
     * @param string[] $manualFeeds
     * @return int[] calendar ids synced for this user
     */
    private function processUser(string $uid, array $manualFeeds, string $color): array {
        $principal = 'principals/users/' . $uid;
        $feeds = array_values(array_unique(array_merge($manualFeeds, $this->discoverFeeds($principal))));

        // URIs we expect this user to keep, derived from the feed list itself.
        // A feed that is listed but fails to fetch still protects its calendars
        // from removal, so a transient Gusto outage never wipes data.
        $expectedUris = [];
        foreach ($feeds as $feedUrl) {
            $expectedUris[] = Feed::workUri($feedUrl);
            $expectedUris[] = Feed::hrUri($feedUrl);
        }

        $activeCalendarIds = [];
        foreach ($feeds as $feedUrl) {
            $result = $this->splitFeed($feedUrl);
            if ($result === null) {
                continue; // keep existing calendars, retry next run
            }
            if ($result->workObjects === [] && $result->otherObjects === []) {
                // A healthy Gusto feed is never empty; treat this as a bad
                // response and skip rather than emptying the calendars.
                $this->logger->warning('Gusto iCal Cleaner Upper: feed returned no events, skipping to protect calendars', ['url' => $feedUrl]);
                continue;
            }

            $workName = $result->calendarName ?? self::FALLBACK_CALENDAR_NAME;
            $buckets = [
                [Feed::workUri($feedUrl), $workName, $result->workObjects],
                [Feed::hrUri($feedUrl), $workName . ' (HR)', $result->otherObjects],
            ];
            foreach ($buckets as [$uri, $name, $objects]) {
                $calendarId = $this->mirror->ensureCalendar($principal, $uri, $name, $color);
                if ($calendarId !== null) {
                    $this->mirror->sync($calendarId, $objects);
                    $activeCalendarIds[] = $calendarId;
                }
            }
        }

        // Remove managed calendars only for feeds this user genuinely no longer
        // has. Failed or empty fetches stay in $expectedUris, so they survive.
        $this->mirror->removeStaleCalendars($principal, $expectedUris);

        return $activeCalendarIds;
    }

    /** Fetch and split a feed once per run, caching the result (null = failed). */
    private function splitFeed(string $feedUrl): ?SplitResult {
        if (!array_key_exists($feedUrl, $this->splitCache)) {
            try {
                $this->splitCache[$feedUrl] = $this->splitter->fetchAndSplit($feedUrl);
            } catch (\Throwable $e) {
                $this->logger->error('Gusto iCal Cleaner Upper: feed fetch/split failed', ['url' => $feedUrl, 'exception' => $e]);
                $this->splitCache[$feedUrl] = null;
            }
        }
        return $this->splitCache[$feedUrl];
    }

    /**
     * Manual feeds from the optional feed_url config (a comma-separated list).
     *
     * @return string[] normalized URLs
     */
    private function manualFeeds(): array {
        $raw = trim($this->appValue('feed_url', ''));
        return $raw === '' ? [] : $this->validGustoFeeds(explode(',', $raw), true);
    }

    /**
     * Discover this user's Gusto feeds from their webcal subscriptions.
     *
     * @return string[] normalized URLs
     */
    private function discoverFeeds(string $principal): array {
        $sources = array_map(
            static fn ($subscription): string => (string)($subscription['source'] ?? ''),
            $this->calDavBackend->getSubscriptionsForUser($principal),
        );
        return $this->validGustoFeeds($sources, false);
    }

    /**
     * Normalize each raw URL and keep only the ones whose host is gusto.com (or a
     * subdomain), which also stops the app from being pointed at internal hosts.
     *
     * @param string[] $rawUrls
     * @return string[] normalized, validated URLs
     */
    private function validGustoFeeds(array $rawUrls, bool $logRejects): array {
        $feeds = [];
        foreach ($rawUrls as $raw) {
            $url = Feed::normalizeUrl(trim($raw));
            if ($url === '') {
                continue;
            }
            if (Feed::isGustoFeed($url)) {
                $feeds[] = $url;
            } elseif ($logRejects) {
                $this->logger->warning('Gusto iCal Cleaner Upper: ignoring non-Gusto feed_url entry', ['url' => $url]);
            }
        }
        return $feeds;
    }

    /**
     * Target users.
     *
     * Default (target_user unset): every Nextcloud user.
     * Override: a comma-separated list of uids; existing ones are used, missing
     * ones are logged and skipped.
     *
     * @return string[] list of uids
     */
    private function getTargetUids(): array {
        $configured = trim($this->appValue('target_user', ''));
        if ($configured !== '') {
            $uids = [];
            foreach (explode(',', $configured) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }
                if ($this->userManager->userExists($candidate)) {
                    $uids[] = $candidate;
                } else {
                    $this->logger->error('Gusto iCal Cleaner Upper: target_user "' . $candidate . '" does not exist');
                }
            }
            return array_values(array_unique($uids));
        }

        $uids = [];
        $this->userManager->callForAllUsers(function (IUser $user) use (&$uids): void {
            $uids[] = $user->getUID();
        });
        return $uids;
    }

    private function appValue(string $key, string $default): string {
        return $this->config->getAppValue(Application::APP_ID, $key, $default);
    }
}
