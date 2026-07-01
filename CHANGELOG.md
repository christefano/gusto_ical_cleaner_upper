# Changelog

## [1.0] - 2026-06-30

First release.

### Added
- Splits each Gusto iCal or `webcal` feed into a work calendar (shifts, paydays,
  and sick or time off) and an HR calendar (birthdays, anniversaries, first work
  days, and out-of-office events).
- Multi-feed support. Discovers all of a user's `gusto.com` webcal subscriptions
  and processes each, with an optional `feed_url` list for extra feeds.
- Runs headless on Nextcloud's background cron, with no configuration UI.
- Delta sync so unchanged events keep their ETags and clients sync only real
  differences.
- Per-feed calendars named after the feed's `X-WR-CALNAME`, with the HR calendar
  getting ` (HR)` appended.
- Each event carries only the `VTIMEZONE` blocks it references, so all-day events
  carry none. Cuts stored size and sync bandwidth by roughly 40% on a single-zone
  feed and more on multi-zone feeds.
- Two optional dashboard widgets, "Out today" and "Celebrations", read from the HR
  calendar via the built-in dashboard API (no extra JavaScript). The celebrations
  look-ahead is set by `dashboard_window_days` (default 7).
- Resilience: a feed that fails to fetch or returns no events keeps its existing
  calendars instead of wiping them, and each user is processed independently.
- Removes a feed's calendars when that feed is gone, and prunes leftover state.
- Uninstalling runs a repair step that removes every managed calendar for every
  user, so uninstall leaves nothing behind.
- Optional `calendar_color` and `target_user` configuration.

### Security
- Feed hosts are validated by parsed host (gusto.com or a subdomain) rather than
  a substring, preventing the app from being pointed at internal addresses.
