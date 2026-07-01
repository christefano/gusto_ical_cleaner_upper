<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Service;

/**
 * A single HR event read back out of a managed HR calendar, ready to be turned
 * into a dashboard widget item. $date is the start day, $end is the exclusive
 * end day (start + 1 for a single-day event), both at midnight in the user's
 * timezone.
 */
final class HrEvent {
    public function __construct(
        public readonly string $category,
        public readonly string $summary,
        public readonly \DateTimeImmutable $date,
        public readonly \DateTimeImmutable $end,
    ) {
    }
}
