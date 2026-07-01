<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Service;

/**
 * A single HR event read back out of a managed HR calendar, ready to be turned
 * into a dashboard widget item.
 */
final class HrEvent {
    public function __construct(
        public readonly string $category,
        public readonly string $summary,
        public readonly \DateTimeImmutable $date,
    ) {
    }
}
