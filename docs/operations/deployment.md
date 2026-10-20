# Deployment and recovery runbook

This is a portable deployment reference, not a record of a live deployment.
It creates no cloud resources and assumes no particular hosting provider.
Use PHP 8.4 and PostgreSQL 18, an HTTPS entry point, and one scheduler host.
SQLite remains supported for local use and tests.

## Build and runtime

The [Dockerfile](../../Dockerfile) uses the official PHP Apache image and a
multi-stage Composer install from `composer.lock`. The final image runs as
`www-data` on port 8080, includes no development dependencies or application
`.env`, and serves only `public/`. The historical image archive and personal
files are excluded from the build context. Source files are root-owned; only
`storage` and `bootstrap/cache` need application write access.

On an authorized build host:

```bash
docker build --pull --tag wallpaper-api:release .
docker run --detach --name wallpaper-api --publish 127.0.0.1:8080:8080 \
  --env-file /secure/wallpaper-api.env \
  --mount type=volume,source=wallpaper-storage,target=/var/www/html/storage \
  wallpaper-api:release
```

The external environment file is private, untracked and readable only by the
operator. Do not use the local example unchanged in production. Build without
production credentials; inject secrets only at runtime. Store and deploy the
tested image digest rather than rebuilding the same release tag on each host.
Base image tags receive updates, so a later rebuild can produce a different
digest. Rebuild and rerun gates for security updates.

Fresh storage volumes must contain `framework/cache/data`, `framework/sessions`,
`framework/views` and `logs`, writable by UID/GID 33 (`www-data` in this image).
An empty bind mount hides the image's prepared directories; prepare it explicitly
before starting the service. Configuration caches contain resolved secrets;
keep `bootstrap/cache` private and do not export it as an artifact.

| Setting | Production requirement |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | `production`, `false`. The image defaults to both. |
| `APP_KEY` | Generate a unique key with `php artisan key:generate --show` in a trusted terminal, then store it through the secret mechanism. Do not log or commit it. Preserve it across restarts and rollback. |
| `APP_URL` | The final HTTPS service URL. |
| `DB_CONNECTION` | `pgsql`. Create a dedicated database and role before migration. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Private database connection values. The application does not need administrator or database-creation privileges after provisioning. |
| `DB_SSLMODE` | Use the database provider's verified TLS configuration; prefer `verify-full` with the trusted CA available to libpq. Local disposable CI alone uses `disable`. |
| `DB_URL` | Optional alternative connection URL. Do not set it alongside conflicting individual settings. Treat it as a secret. |
| `CACHE_STORE`, `QUEUE_CONNECTION` | `file`, `sync` for the single-host baseline. No database cache/job tables are shipped. |
| `CORS_ALLOWED_ORIGINS` | `*` for public credential-free reads, or an explicit comma-separated origin list. |
| `WALLPAPER_REQUESTS_PER_MINUTE` | Default `60`; tune from observed load. |
| `LOG_CHANNEL`, `LOG_LEVEL` | Image defaults to `stderr`, `info`. |
| `LOG_STDERR_FORMATTER` | Image defaults to `Monolog\Formatter\JsonFormatter` for structured application logs. |

Configuration and proxy assumptions are detailed in [public API controls](public-api.md).
PHP has a 128 MiB memory limit and 60-second web execution limit in this image;
Apache has a 60-second timeout and allows up to 8 concurrent workers (`MaxRequestWorkers 8`,
for a theoretical PHP worker ceiling of roughly 8 × 128 MiB ≈ 1 GiB). Set a suitable
container memory limit and concurrency at the hosting layer: each Apache worker
can consume PHP memory. Unbounded matching result counts remain a documented API
limitation. Limit instances and total concurrency before public exposure; no
application control is a hard infrastructure spending cap.

Terminate TLS at the platform or a reverse proxy, forwarding only to port 8080.
Trust only verified proxy addresses if enabling forwarded-IP resolution; there
is no wildcard proxy trust configured here. Do not expose the database publicly.
The image does not provision certificates, a proxy, DNS, backups or scheduling.

## Migration and startup checks

Run exactly one migration process against the intended database using the same
image and secrets as the web service. Take a backup before upgrading existing data.
Do not run migrations automatically on every web-container startup.

```bash
docker exec wallpaper-api php artisan migrate --force --no-interaction
docker exec wallpaper-api php artisan config:cache
docker exec wallpaper-api php artisan route:cache
docker exec wallpaper-api php artisan schedule:list
```

Recreate configuration and route caches after replacing the image or environment.
Never copy development caches into production. The image starts without caches;
cache generation is an explicit runtime step with production settings loaded.
Restart web workers after code/configuration changes; OPcache timestamp checking
is disabled in the immutable production image.

Check `/up`, one exact-date query, one range query and an invalid date from the
public HTTPS entry point. Empty valid results return 200; invalid dates return
422; the response must not contain raw payloads or stack traces. Verify CORS and
429 headers using a controlled test allowance, restoring the intended value.
`/up` only confirms application boot; it does not prove database connectivity,
source availability, recent synchronization or backup health.

The [container smoke script](../../scripts/smoke-container.sh) verifies real web
requests, migrations, cached boot and safe database failures in CI. After checking
an empty result, it copies a dedicated Seeder and expected JSON fixture into the
disposable container, runs `db:seed`, and checks a populated HTTP 200 response
against the complete public contract example. These test files are excluded from
the production image; no live Bing request is made. It rolls back
tables and **must never run against production or a shared database**.

## Scheduler and synchronization

Use one scheduler host with persistent `storage/framework/cache/data`. Invoke
`php artisan schedule:run` every minute using the same image, secrets and storage
as the application. A host cron example for the container above is:

```cron
* * * * * docker exec wallpaper-api php artisan schedule:run
```

Capture command output and failed exits through the host's bounded logging.
The actual wallpaper task runs at 12:00 UTC for the previous UTC calendar date.
The image starts only the web server; it does not start cron implicitly.
For external scheduled jobs, retain equivalent UTC/date and mutual-exclusion
semantics. Ephemeral or separate job filesystems do not share file locks.
See [daily synchronization](daily-sync.md) for source observations, lock recovery,
audit codes and manual recovery. Manual synchronization can call Bing and is an
operator action, not a health check. There are no automatic retries or backfills.

## Backups and restore

Use PostgreSQL 18 client tools and a protected libpq service definition and
password file (`PGSERVICE`, `PGSERVICEFILE`, `PGPASSFILE`) outside the repository.
Do not put database passwords in shell arguments or publish backup artifacts.
Choose backup frequency from the acceptable data-loss window; daily backups
and a backup before each migration are a small-service starting point. Encrypt
off-host backups, restrict access and define retention.

```bash
pg_dump --format=custom --file=/secure/backups/wallpaper.dump
pg_restore --list /secure/backups/wallpaper.dump
```

`--list` checks the archive contents, not recoverability. Provision a separate
empty recovery database and point the protected libpq service at it, then:

```bash
pg_restore --exit-on-error --no-owner --no-privileges --dbname=service=wallpaper_restore \
  /secure/backups/wallpaper.dump
```

Run migration-status checks, record counts and representative queries against
that isolated database. Record successful restore drills and measured recovery
time. Keep required application keys and deployment configuration separately;
the database backup alone is not a complete service recovery package. Cache
counters are disposable, but scheduler storage and running processes must be
coordinated before replacing lock state.

## Upgrade and rollback

1. Verify the release commit and passing CI, back up data and pause the scheduler.
2. Apply reviewed migrations once. Check compatibility with the currently serving code before changing schema.
3. Start the tested image digest, inject secrets, refresh caches and run public smoke checks.
4. Resume the single scheduler and inspect its first audit outcome.

For a failed code rollout, stop the new service, restore the previous tested
image digest with its matching configuration, rebuild caches and repeat smoke
checks. Keep the same application key and compatible database. Migration rollback
is not a generic recovery operation: the initial migrations drop all wallpaper
and audit tables. Prefer a reviewed forward fix or restore the verified backup
to a separate database and switch connections after confirming data-loss impact.
Never automatically run `migrate:fresh`, `migrate:reset` or table-dropping rollback
against production.

## Observation and content handling

Monitor HTTP 5xx/429 rates, latency, worker memory, database capacity, scheduler
execution, failed audit codes, backup age and restore evidence. A successful
`not_found` means no exact source record; it does not require a technical retry.
The container emits structured application logs to stderr and access logs with
method, URL path, status and duration; it omits query strings and client IPs.
PHP/Apache startup diagnostics are separate platform logs and must remain private.
Set bounded retention and do not expose logs through the public service.

For copyright/takedown requests, ask for the source URL or record identifier,
the affected content and the requester's basis for acting. Keep personal details
in private correspondence. Review the request, disable affected public metadata
where appropriate, and prevent scheduled reintroduction while resolving it
(pause synchronization if necessary). Retain a minimal private decision record
and provide an outcome. Third-party image URLs and historical Git objects may
remain outside the running service; do not promise their removal from Bing or
other copies. Historical archive deletion is a separate maintenance action.

Use the [security policy](../../SECURITY.md) for vulnerabilities and the
[release checklist](../release.md) for code publication. Actual hosting, account,
DNS and cost changes are separate from preparing this repository.
