# Daily wallpaper synchronization

The application registers exactly one wallpaper schedule: `wallpapers:sync-daily`
at **12:00 UTC every day**. It invokes `wallpapers:sync` through `Artisan::call()`
with the **previous UTC calendar date**, calculated when the callback executes,
not when the application boots. For example, 2026-10-05 at 12:00 UTC requests
2026-10-04. Application/server timezone and daylight-saving changes do not change
the schedule or requested date. This deliberately accepts one-day latency.

## Source observation and timing policy

The Composer package requests this fixed `en-US` metadata endpoint:

```bash
curl --fail --silent --show-error \
  'https://global.bing.com/HPImageArchive.aspx?format=js&idx=0&n=8&mkt=en-US'
```

On 2026-10-05 at 11:43:10 UTC it returned eight consecutive source dates,
2026-09-28 through 2026-10-05. The latest record had `startdate=20261005`,
`fullstartdate=202610050700`, `enddate=20261006`; the previous record had
`startdate=20261004`, `fullstartdate=202610040700`, `enddate=20261005`.
An earlier observation of `www.bing.com` at 11:39:52 UTC instead returned
2026-10-04 as its latest date. Do not substitute that host when reproducing
the package's behavior.

These snapshots show availability, not a contractual publication time or a
guaranteed timezone for `fullstartdate`. Noon UTC and a previous-day lookup are
a conservative v1 policy, not a proven universal rollover rule. If the requested
date is absent, the command records `not_found` and exits successfully. There
are no automatic retries or catch-up backfills.

## Scheduler and locks

Inspect the registration without making a Bing request:

```bash
php artisan schedule:list
```

Use **one scheduler host**. Its eventual deployment must invoke Laravel's
`schedule:run` once per minute. For example, configure a cron entry with the
deployment's PHP executable and application path:

```cron
* * * * * cd /path/to/wallpaper-api && php artisan schedule:run >> /dev/null 2>&1
```

If PHP runs in a container, invoke the equivalent command in that container.
The scheduler explicitly uses the existing `file` cache store for its mutex,
independently of the application's default cache. No cache tables are required.
`storage/framework/cache/data` must be writable and persist/shared between
scheduler processes on that host, including across application releases.
Isolated per-process/per-release filesystems do not share these locks.

`withoutOverlapping()` skips an execution while the named task's file lock is
held. Normal completion, including command failure, releases it. After a hard
crash, the stale lock expires after Laravel's default **24 hours**. A hung run
longer than that can outlive its lock; this is not an unlimited-duration lock.
Only after verifying that no instance is running, an operator can recover this
task's lock specifically:

```bash
php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); Illuminate\Support\Facades\Cache::store("file")->lock("framework/schedule-".sha1("wallpapers:sync-daily"))->forceRelease();'
```

Run recovery from the application root with its normal runtime configuration,
or wait for expiry. Do not flush unrelated application cache.
Manual `wallpapers:sync --date=...`
does not acquire the scheduler lock; do not invoke it concurrently with the
scheduled task. This prevents concurrent scheduled runs, not exactly-once
execution: duplicate sequential triggers can still produce another audit.

## Failure visibility

Lookup/persistence failures emit `Wallpaper synchronization failed.` at error
level with structured context: `source`, `market`, `requested_date`, `error_code`,
and `sync_run_id`. Use the ID to find the matching failed `sync_runs` row.
No raw response, exception text, credentials or image data is logged. Existing
log-channel configuration determines the output destination/format.

When even the failure audit cannot be stored, the log uses
`error_code=AUDIT_UNAVAILABLE` and a null `sync_run_id`; no database record can
be promised while the database is unavailable. The scheduled callback converts
the command's exit code to a boolean so Laravel marks command failure as a
failed scheduled event. Success and `not_found` remain successful events.

No monitoring provider, alerts, deployment, queues, image download or Composer
scheduling logic is included.

For container startup, runtime secrets, persistent storage and scheduler wiring,
see the [deployment runbook](deployment.md). The production image runs only the
web server; scheduler execution must be configured separately.
