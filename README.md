# Gusto iCal Cleaner Upper

A Nextcloud app that splits one or more Gusto calendar feeds into two: a main "work" calendar with the things you care about (work shifts, paydays, and sick days / time off), and an "other" calendar with the rest (birthdays, work anniversaries, first work days, and out of office events) that you can hide.

There's actually no way to remove events (e.g. Gusto "out of office" events) that are in a feed you don't control, and Gusto iCal Cleaner Upper sits between the Gusto source feeds and your Nextcloud calendars. On cron it fetches each feed, classifies the events using the assumptions listed below, and splits them into separate calendars. 

Enable Gusto iCal Cleaner Upper under **Apps**, and it runs automatically on each cron run. Disable it to stop syncing. Uninstall it to remove the split calendars. Your original Gusto calendar will still remain. Be sure you already subscribe to a Gusto feed for Gusto iCal Cleaner Upper to do detect it and actually do anything.

I keep the other "HR" calendar hidden in case I need it, but I mostly never do and those events take up a lot of mental and visual space. In one of my organization of about 40 employees, Gusto iCal Cleaner Upper reduces the number of events I look at each day by more than 80%. As a convenience, Gusto iCal Cleaner Upper provides "Out today" and "Celebrations" widgets for your Nextcloud dashboard.

Note: while the two new, split calendars are technically editable, any changes you make other than renaming them will be lost on each cron run.

## Features

- Discovers Gusto calendar feeds already in your Nextcloud calendar.
- Splits each Gusto feed into a main "work" calendar and another "HR" calendar. Once they're created, you can rename them in Nextcloud Calendar as you see fit.
- Can handle several Gusto feeds at once, one for your work and one for HR miscellany.
- Adds two optional dashboard widgets, "Out today" (who is out of office right now) and "Celebrations" (upcoming birthdays, anniversaries, and first work days), both read straight from the other "HR" calendar.
- Runs on Nextcloud's existing background cron.
- Writes only what changed on each run so source edits, additions, and deletions propagate.
- Removes the new, split calendars automatically if the original Gusto feed is manually deleted.
- Preserves `VTIMEZONE` data so timezoned events keep correct times, while trimming each event to only the timezones it references. All-day events carry none, which cuts stored calendar size and sync bandwidth by roughly 40% on a single-timezone feed, and up to 75% or more on a multi-timezone one.

## Requirements

Nextcloud 28 to 34 with the bundled `dav` app enabled (it is by default) and a working background cron. The recommended cron mode is `occ background:cron` (system crontab) rather than AJAX.

Tested with Nextcloud 34 with PHP 8.3.6.

## Assumptions

Gusto iCal Cleaner Upper assumes the following:

- Birthdays have the string, `birthday`
- Work anniversaries have the string, `anniversary`
- First work days have the string, `'s first day`
- Out of office events are appended `- OOO`

All these go into the other "HR" calendar. Everything else (work shifts, paydays, and sick days / time off) goes into the main "work" calendar.

## Installation

1. Copy or clone the app into the server's apps directory:

   ```
   cp -r gusto_ical_cleaner_upper /var/www/nextcloud/apps/
   ```

2. Enable it:

   ```
   sudo -u www-data php /var/www/nextcloud/occ app:enable gusto_ical_cleaner_upper
   ```

3. Make sure your Gusto feed is added as a Nextcloud calendar subscription, and Gusto iCal Cleaner Upper discovers it from there. If you'd rather point it at feeds directly, set `feed_url` (see **Configuration**).

4. Wait for the next cron run. The split calendars then appear in the Calendar app, one pair per feed. If you don't want to wait, force a run (see **occ commands**).

## Configuration

The app works with no configuration since it discovers feeds from your existing subscriptions. Set or override any value with `occ config`:

- `feed_url` is an optional comma-separated list of feeds to process in addition to the discovered ones. Entries are merged with discovery and de-duplicated.
  - `occ config:app:set gusto_ical_cleaner_upper feed_url --value="webcal://app.gusto.com/calendars/your-feed.ics"`
  - `occ config:app:set gusto_ical_cleaner_upper feed_url --value="webcal://a.example/one.ics,webcal://b.example/two.ics"`
- `calendar_color` is an optional hex color applied to every managed calendar. When it's unset, Nextcloud assigns its own default color.
  - `occ config:app:set gusto_ical_cleaner_upper calendar_color --value="#0a7c4a"`
- `interval` is the number of seconds between syncs (default `3600`).
  - `occ config:app:set gusto_ical_cleaner_upper interval --value="3600"`
- `dashboard_window_days` is how many days ahead the "Celebrations" widget looks (default `7`).
  - `occ config:app:set gusto_ical_cleaner_upper dashboard_window_days --value="14"`
- `target_user` decides who gets the managed calendars. When it's unset, every Nextcloud user is processed. Set it to one uid, or to a comma-separated list of uids, to restrict it to just those users.
  - `occ config:app:set gusto_ical_cleaner_upper target_user --value="frodo"`
  - `occ config:app:set gusto_ical_cleaner_upper target_user --value="gandalf,galadriel,gimli"`
  - `occ config:app:delete gusto_ical_cleaner_upper target_user` (back to all users)

Read any current value with `occ config:app:get gusto_ical_cleaner_upper <key>`.

## occ commands

**Force a sync now** (for the impatient who don't want to wait for cron)

```
occ background-job:list | grep GustoIcalCleanerUpper   # find the numeric job id
occ background-job:execute <id>
```

**Inspect or change settings**

```
occ config:app:get gusto_ical_cleaner_upper feed_url
occ config:app:set gusto_ical_cleaner_upper calendar_color --value="#0a7c4a"
occ config:app:delete gusto_ical_cleaner_upper target_user
```

**Watch the result**

```
occ log:tail | grep "Gusto iCal Cleaner Upper"          # work / other counts per feed
```

Both `interval` and `target_user` are read at run time so changes take effect on the next sync. No app reload is needed.

## What you will see per feed

1. Your original Gusto subscription that holds everything. Gusto iCal Cleaner Upper doesn't touch it so you can safely hide it once the split calendars appear.
2. A work calendar that's named after the feed.
3. Another calendar that's named after the feed with `(HR)` appended.

## How it works

- The app declares a `TimedJob` (`lib/BackgroundJob/SyncJob.php`) via `<background-jobs>` in `appinfo/info.xml`. Nextcloud registers it on the cron job list when the app is enabled.
- For each target user it gathers that user's feeds, combining the webcal subscriptions whose host is `gusto.com` or a subdomain of it with any feeds in `feed_url`, then de-duplicates them.
- It fetches each feed over HTTPS (rewriting `webcal://` to `https://`) and parses it with the bundled `sabre/vobject` library. A feed is fetched once per run even if several users share it.
- It classifies every `VEVENT` into the "work" or "HR" bucket, groups them by `UID`, and re-serializes one calendar object per event, carrying through only the `VTIMEZONE` blocks that event actually references (all-day events reference none).
- For each feed it ensures two calendars, `gusto-<hash>-work` and `gusto-<hash>-hr`, where `<hash>` is derived from the feed URL so feeds never collide. The display names come from the feed's `X-WR-CALNAME`.
- It delta-syncs each calendar through `CalendarMirror`, creating new objects, updating changed ones, and deleting removed ones. Unchanged objects keep their ETags so clients sync only real differences.
- It removes managed calendars if the original Gusto feed is no longer present, and prunes leftover change-detection state for calendars that no longer exist.

## Dashboard widgets

The app ships two optional widgets for the Nextcloud dashboard. Add them from the dashboard's **Customize** panel.

1. **Out today** lists whoever has an out-of-office event covering the current day.
2. **Celebrations** lists upcoming birthdays, anniversaries, and first work days within the next `dashboard_window_days` days (default `7`).

Both read straight from the managed HR calendars, so they show nothing until the first sync has run, and they never touch your own calendars. They render through Nextcloud's built-in dashboard API, so there is no extra JavaScript or build step.

## Resilience

The app is built so a Gusto feed problem never destroys your calendars:

- If a feed fails to fetch (timeout, an HTTP error, or a DNS or Gusto outage), that feed is skipped for the run and retried on the next one. Its work and HR calendars are left exactly as they were.
- If a feed responds but returns no events at all, the app treats it as a bad response and skips it rather than emptying the calendars since a healthy Gusto feed is never empty.
- Each user is processed independently so an error for one user does not stop the others.
- A feed shared by several users is fetched once per run so a flaky feed fails fast rather than repeatedly.

A feed's calendars are only removed when the feed is genuinely gone from the user's subscriptions and `feed_url`, not when a fetch merely fails.

## Limitations

- The category strings `birthday`, `anniversary`, `'s first day`, and `- OOO` are hardcoded. They are not configurable, and all match case-insensitively.
- The main "work" calendar is the residual bucket. Any event that matches no HR rule lands there so a new kind of Gusto event you didn't expect shows up in the main "work" calendar.
- Deleting a Gusto subscription removes that feed's "work" and "HR" calendars on the next run. Keep the subscription (hidden is fine) because Gusto iCal Cleaner Upper rediscovers feeds from subscriptions on every run.
- The managed calendars are one-way mirrors rebuilt from the source. Any personal events added to them are deleted (so treat them as read-only).
- Work happens per user and per feed so cost scales with users multiplied by feeds. Restrict with `target_user` for large orgs.
- Matching is a literal substring rather than a regex or word match.

## Data

The app stores its settings as Nextcloud app config keys under the `gusto_ical_cleaner_upper` app id: `feed_url`, `calendar_color`, `interval`, `target_user`, and `dashboard_window_days`. It also keeps internal `hashes_<calendarId>` keys for change detection, which are managed automatically and pruned when their calendar no longer exists.

The only other data it owns is the managed calendars it creates, which are named after each source feed. Disabling the app stops syncing but leaves existing calendars in place, so you can re-enable and pick up where you left off. Uninstalling runs a cleanup step that removes every split calendar Gusto iCal Cleaner Upper creates for every user (only calendars matching the managed URI pattern, so your own calendars are safe). If you disabled rather than uninstalled and want the calendars gone, delete them from the Calendar app.

## Testing

Unit tests live in `tests/Unit` and run under Nextcloud's PHPUnit harness. The app must sit at `<nextcloud>/apps/gusto_ical_cleaner_upper`:

```
cd /var/www/nextcloud/apps/gusto_ical_cleaner_upper
../../vendor/bin/phpunit -c tests/phpunit.xml
```

`FeedSplitterTest` covers work and HR routing, case-insensitive matching, `X-WR-CALNAME` extraction, `VTIMEZONE` preservation, and per-event timezone trimming (all-day events carry none). `FeedTest` covers URL normalization (including the `webcal://` to `https://` rewrite), the SSRF guard, URI stability and collision prevention, and the managed-URI pattern. `CalendarMirrorTest` covers delta sync, stale-calendar removal, and orphan-hash cleanup. `SyncJobTest` covers the happy path, fetch failure resilience, and empty-feed handling. `EventCategoryTest` covers marker classification, and `HrEventReaderTest` covers the dashboard widgets' out-today and celebrations-window filtering. `UninstallCleanupTest` covers the uninstall repair step purging every user's new, split calendars.

## TODO

**Morning nudge for HR events.**
Send a Nextcloud notification each morning for that day's birthdays, anniversaries, and first work days, for example `Today: Gimli's 3-year anniversary` or `Elron's first day`.

## License

GPL-2.0-or-later.
