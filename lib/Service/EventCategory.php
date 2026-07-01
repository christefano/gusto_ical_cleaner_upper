<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Service;

/**
 * Single source of truth for classifying a Gusto event by its SUMMARY.
 *
 * Everything that is not an HR category ("work") lands in the work calendar.
 * All markers match case-insensitively. Kept here so FeedSplitter and the
 * dashboard widgets agree on exactly what counts as a birthday, anniversary,
 * first day, or out-of-office event.
 */
final class EventCategory {
    public const WORK = 'work';
    public const OOO = 'ooo';
    public const BIRTHDAY = 'birthday';
    public const ANNIVERSARY = 'anniversary';
    public const FIRST_DAY = 'firstday';

    public static function of(string $summary): string {
        $lower = strtolower($summary);
        if (str_contains($lower, '- ooo')) {
            return self::OOO;
        }
        if (str_contains($lower, 'birthday')) {
            return self::BIRTHDAY;
        }
        if (str_contains($lower, 'anniversary')) {
            return self::ANNIVERSARY;
        }
        if (str_contains($lower, "'s first day")) {
            return self::FIRST_DAY;
        }
        return self::WORK;
    }

    public static function isHr(string $summary): bool {
        return self::of($summary) !== self::WORK;
    }
}
