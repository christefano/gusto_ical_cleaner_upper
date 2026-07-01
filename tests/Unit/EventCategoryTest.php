<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Tests\Unit;

use OCA\GustoIcalCleanerUpper\Service\EventCategory;
use PHPUnit\Framework\TestCase;

class EventCategoryTest extends TestCase {
    public function testEachMarkerMapsToItsCategory(): void {
        $this->assertSame(EventCategory::BIRTHDAY, EventCategory::of("Alex B's birthday"));
        $this->assertSame(EventCategory::ANNIVERSARY, EventCategory::of("Sam Q's 3-year anniversary"));
        $this->assertSame(EventCategory::FIRST_DAY, EventCategory::of("Robin K's first day"));
        $this->assertSame(EventCategory::OOO, EventCategory::of('Jordan T - OOO'));
    }

    public function testResidualIsWork(): void {
        $this->assertSame(EventCategory::WORK, EventCategory::of('Imaging Specialist'));
        $this->assertSame(EventCategory::WORK, EventCategory::of('Estimated payday'));
    }

    public function testMatchingIsCaseInsensitive(): void {
        $this->assertSame(EventCategory::OOO, EventCategory::of('Casey - ooo'));
        $this->assertSame(EventCategory::BIRTHDAY, EventCategory::of('BIG BIRTHDAY'));
    }

    public function testFirstDayRequiresPossessive(): void {
        // "first day" without "'s" is not a first-work-day event.
        $this->assertSame(EventCategory::WORK, EventCategory::of('Store first day sale'));
    }

    public function testIsHrIsTrueForEveryMarkerAndFalseForWork(): void {
        $this->assertTrue(EventCategory::isHr("Alex B's birthday"));
        $this->assertTrue(EventCategory::isHr('Jordan T - OOO'));
        $this->assertFalse(EventCategory::isHr('Imaging Specialist'));
    }
}
