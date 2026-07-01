<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Service;

/**
 * Outcome of splitting a feed: the source calendar's own display name (from
 * X-WR-CALNAME, if any) plus the events grouped into the work bucket and the
 * HR bucket, each as serialized calendar objects keyed by UID.
 */
class SplitResult {
    /**
     * @param array<string,string> $workObjects  UID => serialized VCALENDAR
     * @param array<string,string> $otherObjects UID => serialized VCALENDAR
     */
    public function __construct(
        public readonly ?string $calendarName,
        public readonly array $workObjects,
        public readonly array $otherObjects,
    ) {
    }
}
