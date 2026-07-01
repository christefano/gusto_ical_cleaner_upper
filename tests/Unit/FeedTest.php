<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Tests\Unit;

use OCA\GustoIcalCleanerUpper\Service\Feed;
use PHPUnit\Framework\TestCase;

class FeedTest extends TestCase {
    public function testNormalizeUrlRewritesWebcalCaseInsensitively(): void {
        $this->assertSame('https://host/cal.ics', Feed::normalizeUrl('webcal://host/cal.ics'));
        $this->assertSame('https://host/cal.ics', Feed::normalizeUrl('webcals://host/cal.ics'));
        $this->assertSame('https://host/cal.ics', Feed::normalizeUrl('WEBCAL://host/cal.ics'));
        $this->assertSame('https://host/cal.ics', Feed::normalizeUrl('https://host/cal.ics'));
    }

    public function testIsGustoFeedAcceptsGustoHosts(): void {
        $this->assertTrue(Feed::isGustoFeed('https://app.gusto.com/calendars/X.ics'));
        $this->assertTrue(Feed::isGustoFeed('https://gusto.com/x'));
    }

    public function testIsGustoFeedRejectsSpoofsAndSsrf(): void {
        // Substring-but-not-host tricks must be rejected.
        $this->assertFalse(Feed::isGustoFeed('http://169.254.169.254/latest?x=gusto.com'));
        $this->assertFalse(Feed::isGustoFeed('https://gusto.com.evil.com/x'));
        $this->assertFalse(Feed::isGustoFeed('https://notgusto.com/x'));
        $this->assertFalse(Feed::isGustoFeed('https://app.gusto.com.evil.com/x'));
    }

    public function testWorkAndHrUrisAreStableAndDistinct(): void {
        $url = 'https://app.gusto.com/calendars/X.ics';
        $work = Feed::workUri($url);
        $hr = Feed::hrUri($url);

        $this->assertMatchesRegularExpression('/^gusto-[0-9a-f]{8}-work$/', $work);
        $this->assertMatchesRegularExpression('/^gusto-[0-9a-f]{8}-hr$/', $hr);
        $this->assertNotSame($work, $hr);
        // Deterministic: same URL always yields the same slug.
        $this->assertSame($work, Feed::workUri($url));
        // Different feeds do not collide.
        $this->assertNotSame($work, Feed::workUri('https://app.gusto.com/calendars/Y.ics'));
    }

    public function testIsManagedUriRecognizesOnlyManagedSlugs(): void {
        $this->assertTrue(Feed::isManagedUri('gusto-aaaaaaaa-work'));
        $this->assertTrue(Feed::isManagedUri('gusto-deadbeef-hr'));
        $this->assertFalse(Feed::isManagedUri('personal'));
        $this->assertFalse(Feed::isManagedUri('gusto-filtered'));
        $this->assertFalse(Feed::isManagedUri('gusto-xyz-work'));
    }
}
