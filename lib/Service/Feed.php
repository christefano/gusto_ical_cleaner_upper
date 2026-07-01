<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Service;

/**
 * Single source of truth for feed-URL handling and managed-calendar identity:
 * normalizing URLs, validating that a URL really is a Gusto feed, and building
 * and recognizing the managed calendar URI slugs. Keeping the slug format in one
 * place means the code that creates calendars and the code that cleans them up
 * can never drift apart.
 */
final class Feed {
    private const SLUG_PREFIX = 'gusto-';

    /** Rewrite webcal/webcals to https (case-insensitive). */
    public static function normalizeUrl(string $url): string {
        return preg_replace('#^webcals?://#i', 'https://', $url) ?? $url;
    }

    /**
     * True only when the URL's host is gusto.com or a subdomain of it. This is a
     * proper host check, not a substring match, so a URL like
     * http://internal/?x=gusto.com cannot slip through (SSRF guard).
     */
    public static function isGustoFeed(string $url): bool {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        return $host === 'gusto.com' || str_ends_with($host, '.gusto.com');
    }

    public static function workUri(string $normalizedUrl): string {
        return self::SLUG_PREFIX . self::base($normalizedUrl) . '-work';
    }

    public static function hrUri(string $normalizedUrl): string {
        return self::SLUG_PREFIX . self::base($normalizedUrl) . '-hr';
    }

    /** True if a calendar URI is one this app manages. */
    public static function isManagedUri(string $uri): bool {
        return preg_match('#^' . self::SLUG_PREFIX . '[0-9a-f]{8}-(work|hr)$#', $uri) === 1;
    }

    private static function base(string $normalizedUrl): string {
        return substr(sha1($normalizedUrl), 0, 8);
    }
}
