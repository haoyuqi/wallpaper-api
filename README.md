# Wallpaper API

A minimal Laravel 13 API foundation providing wallpaper metadata services.

## Requirements

- PHP `^8.3`
- Laravel `^13.17`

## Development Environment

All PHP and Composer operations are run inside the Laradock workspace container. No host PHP or Composer installation is required or supported.

From the Laradock directory (`~/Developer/laradock`):

```bash
docker compose exec -T -u laradock -w /var/www/wallpaper-api workspace <command>
```

Example development commands:

```bash
# Validate Composer configuration
docker compose exec -T -u laradock -w /var/www/wallpaper-api workspace composer validate --strict

# Run code style checks
docker compose exec -T -u laradock -w /var/www/wallpaper-api workspace vendor/bin/pint --test

# Run test suite
docker compose exec -T -u laradock -w /var/www/wallpaper-api workspace php artisan test
```

## Health Check

The service exposes the framework-native health check endpoint:

- `GET /up`: Returns HTTP 200 when the application boots successfully.

## Persistence and tests

`wallpapers` stores normalized metadata plus its original JSON payload; `sync_runs` audits each synchronization attempt. Both tables contain metadata only, never image binaries. Dates are calendar `DATE` values. Event timestamps are stored as UTC instants; PostgreSQL uses `JSONB` for the raw documents.

The default test suite uses an isolated in-memory SQLite database. To verify PostgreSQL-specific behavior locally, run the same suite with `TEST_DB_CONNECTION=pgsql`, `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_USERNAME`, `TEST_DB_PASSWORD`, and `TEST_DB_DATABASE=wallpaper_api_test` set in the workspace container. The PostgreSQL service and a role with database-creation permission must already exist. Laravel's `migrate:fresh` creates the missing test database automatically; subsequent runs reset its tables. The test base class refuses any PostgreSQL database name other than `wallpaper_api_test`. Never point test credentials at a shared or production server.

CI runs the full suite on SQLite with PHP 8.3 and 8.4, and separately on PostgreSQL with PHP 8.4. The PostgreSQL job starts with no application database to exercise automatic creation, then verifies migration rollback.

## Bing metadata command integration

The internal gateway invokes the installed `haoyuqi/download-bing-wallpaper` 3.x command through `Artisan::call()` and validates its complete v1 JSON result and exit code. Each invocation uses a separate output buffer. It returns metadata or a stable failure category to the ingestion service, without writing to the database or exposing an HTTP endpoint. The command runs in the current PHP process; network timeouts are handled by the package, with no separate process timeout or output capture limit. Tests exercise the installed command with a fake metadata provider, so they make no Bing request.

## Synchronize a date

Configure the application database and run `php artisan migrate` before synchronizing. From the Laradock directory:

```bash
docker compose exec -T -u laradock -w /var/www/wallpaper-api workspace \
  php artisan wallpapers:sync --date=2026-09-29
```

`wallpapers:sync` calls the gateway once and stores metadata only. Found results are atomically upserted by `(source, market, source_date, source_item_id)` with a successful `sync_runs` record. Repeating a date updates the matching record without changing its ID or creating a duplicate; each attempt receives a separate audit record. `not_found` records the lookup without inserting or deleting wallpaper data. Validated response documents, their schema versions, and original JSON value types are retained internally.

The lookup happens before the database transaction. A write failure rolls back both the wallpaper change and success audit, then records a separate `failed` attempt with a fixed, safe diagnostic. A persistence-failure audit leaves both the schema-version and response-document columns null, so a document rejected by the database cannot cause the failure audit to fail again. Malformed documents and command exceptions are audited without retaining unvalidated output. If the database cannot save even the failure audit, the command reports that the attempt could not be recorded and exits unsuccessfully.

Exit codes are `0` for success or `not_found`, `1` for lookup/persistence failure, and `2` for a missing or invalid `--date`. Invalid input does not invoke the package or write an audit row because there is no valid requested calendar date. Command output includes the outcome and audit ID, never raw responses or exception details. Failures also emit safe structured log context correlated with the audit ID (or `AUDIT_UNAVAILABLE` if it cannot be saved). There is no automatic retry, image download, or Git operation.

## Daily synchronization

`wallpapers:sync-daily` runs at **12:00 UTC** and requests the previous UTC calendar date. It uses the existing manual command in-process with a persistent file-cache overlap lock on one scheduler host. Inspect it using `php artisan schedule:list`; no Bing request is made by that inspection. See [daily synchronization operations](docs/operations/daily-sync.md) for source observations, date semantics, lock requirements, failure logs and limits. Production scheduler provisioning is not included.

## Query wallpaper metadata

The public, read-only endpoint needs no authentication:

```http
GET /api/v1/wallpapers?date=2026-08-31
GET /api/v1/wallpapers?from=2026-08-01&to=2026-08-31
```

Supply either `date` or both `from` and `to` as real `YYYY-MM-DD` calendar dates. Ranges include both endpoints, with no range cap or pagination. Results are ordered by source date descending, then ID descending; only public metadata is returned. Valid requests return HTTP 200, including an empty `data` array. Invalid modes or dates return HTTP 422 with stable error codes. The response includes `meta.query` and `meta.count`; see the [v1 contract](docs/contracts/v1.md) for exact fields and validation precedence. Parameters come from the URL query only; other parameters are ignored and do not enable filters. No Bing lookup or database write occurs during a query.

## Architecture & Scope

This repository provides an API-only service foundation:

- No frontend views, assets, or Node/Mix/Vite runtimes are included.
- No session or default authentication scaffolding is loaded.
- Wallpaper persistence, the internal Composer command gateway, manual synchronization, a daily schedule and public date queries are present. Authentication, tags, keyword search and pagination are outside v1.
- The frozen v1 API and ingestion contract is documented in [docs/contracts/v1.md](docs/contracts/v1.md).

## Archived Data

The 321 historical daily wallpaper images under `storage/bing-wallpaper/` remain archived in this repository for historical preservation but are not accessed or modified by the application runtime.
