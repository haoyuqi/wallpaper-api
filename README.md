<h1 align="center">Wallpaper API</h1>

<p align="center">
  <a href="https://github.com/haoyuqi/wallpaper-api/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/haoyuqi/wallpaper-api/actions/workflows/ci.yml/badge.svg?branch=master"></a>
  <a href="https://github.com/haoyuqi/wallpaper-api/actions/workflows/ci.yml?query=branch%3Amaster"><img alt="Coverage" src="https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fhaoyuqi%2Fwallpaper-api%2Fcoverage-badge%2Fcoverage.json"></a>
  <a href="https://laravel.com/docs/13.x"><img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white"></a>
  <a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue.svg"></a>
  <a href="https://github.com/haoyuqi/wallpaper-api/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/release/haoyuqi/wallpaper-api.svg"></a>
</p>

<p align="center">
  English | <a href="README.zh-CN.md">简体中文</a>
</p>

A Laravel 13 API that collects Bing wallpaper metadata and serves it by date. It stores normalized metadata plus the original source JSON; synchronization never downloads images.

## Features

- `GET /api/v1/wallpapers` for one exact date or an inclusive date range, returning only public metadata.
- `php artisan wallpapers:sync --date=YYYY-MM-DD` calls the installed `download-bing-wallpaper` command, validates the JSON it prints, and records every attempt.
- Idempotent writes keyed by source, market, date and item ID, with the wallpaper and its audit row saved in one transaction.
- Stable JSON errors for invalid input, missing routes, wrong methods, rate limits and unexpected failures; internal diagnostics never reach the response.
- Per-IP rate limiting, credential-free CORS for public reads, and a `/up` health check.

## Requirements

- PHP 8.4.x (8.4.27 or later), or PHP 8.5.12 or later within PHP 8.x
- Composer 2
- PDO SQLite for the default local setup, or PDO PostgreSQL for PostgreSQL
- PostgreSQL 18 is tested in CI; SQLite is also supported

CI and the production image build with PHP 8.4. Composer also permits PHP 8.5.12 and later, but this repository does not claim separate PHP 8.5 CI coverage. Use a local PHP installation or a container of your choice, and run the commands below from the repository root.

## Getting started

```bash
git clone https://github.com/haoyuqi/wallpaper-api.git
cd wallpaper-api
composer install
composer check-platform-reqs
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

The example configuration uses SQLite. For PostgreSQL, set the connection in your local `.env` and create the application database before migrating; keep credentials out of version control. The development server listens on `http://127.0.0.1:8000`, where `GET /up` confirms that the application boots. Do not run `artisan serve` in production.

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_URL` | `http://localhost` | Public origin of the deployment. |
| `DB_CONNECTION` | `sqlite` | Database driver: `sqlite`, `pgsql` or `mysql`. |
| `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` | SQLite file | Connection settings for the selected driver. |
| `CORS_ALLOWED_ORIGINS` | `*` | Comma-separated origins allowed to read the API; empty disables cross-origin reads. |
| `WALLPAPER_REQUESTS_PER_MINUTE` | `60` | Per-IP request budget for public reads. |
| `TEST_DB_CONNECTION`, `TEST_DB_*` | PostgreSQL `wallpaper_api_test` | Dedicated PHPUnit connection; credentials stay in the ignored `.env`. |

## Synchronize metadata

```bash
php artisan wallpapers:sync --date=2026-10-01
```

The command invokes the installed [download-bing-wallpaper](https://github.com/haoyuqi/download-bing-wallpaper) 3.x Artisan command and validates its JSON response. Repeated successful lookups update the same source identity, and every lookup records a synchronization attempt. Bing may not have data for a requested date.

| Exit code | Meaning |
| --- | --- |
| `0` | Synchronized, or the source has no wallpaper for that date. |
| `1` | Source or persistence failure. |
| `2` | Missing or invalid date. |

Output names the outcome and the audit record ID, without raw documents or exception details.

## Query metadata

```http
GET /api/v1/wallpapers?date=2026-10-01
GET /api/v1/wallpapers?from=2026-10-01&to=2026-10-07
```

| Parameter | Requirement |
| --- | --- |
| `date` | Exact-date query; omit `from` and `to`. |
| `from`, `to` | Inclusive range; both are required and `from` must not be later than `to`. |

Dates are real `YYYY-MM-DD` calendar dates without surrounding whitespace. Results are ordered by source date and ID descending and contain only public fields. Valid queries return HTTP 200, including empty results; invalid parameters return HTTP 422 with stable error codes. Queries read stored data and never contact Bing.

Public reads default to 60 requests per IP per minute, and over-budget requests return HTTP 429 with `Retry-After`. Version 1 has no authentication, tags, keyword filters or pagination, so large ranges can produce large responses.

## Daily synchronization

The registered task runs at 12:00 UTC and requests the previous UTC date. Deployments must invoke `schedule:run` once per minute on a single scheduler host; the overlap lock uses the file cache, so that storage must be writable and persistent.

```bash
php artisan schedule:list
```

## Testing and quality gates

| Command | Purpose |
| --- | --- |
| `composer validate --strict` | Validate the Composer manifest. |
| `vendor/bin/pint --test` | Check formatting with the Laravel preset. |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | Run Larastan level 5 over `app/`, routing, bootstrap and database. |
| `vendor/bin/phpunit` | Run the test suite against the dedicated testing connection. |
| `composer audit --locked --no-interaction` | Audit locked production and development dependencies. |

The default local suite uses PostgreSQL and makes no Bing requests; SQLite is a quick alternative but skips PostgreSQL-specific assertions. Copy `.env.example` to `.env` and set `TEST_DB_USERNAME` and `TEST_DB_PASSWORD` there; `phpunit.xml` supplies the non-secret testing defaults and no `.env.testing` file is needed. The test base rejects any PostgreSQL database name other than `wallpaper_api_test`.

CI requires exactly 100% PHPUnit line coverage for `app/`, with no excluded files and no rounding. The coverage badge above updates after each successful default-branch run.

## Deployment

The [production image](Dockerfile) runs PHP 8.4 with Apache as a non-root user on port 8080. It contains no secrets and no development dependencies, and it neither starts a scheduler nor migrates automatically. CI builds the image and exercises it against a disposable PostgreSQL 18 database.

Runtime secrets, TLS and proxies, persistent locks, migrations, health checks, backups and rollback are summarized under [Operations](#operations). For non-Docker deployments, run `composer install --no-dev` and `composer check-platform-reqs --no-dev` with the production PHP executable. `/up` confirms that the application boots; it does not prove database availability or synchronization freshness.

## Operations

- Run `schedule:run` once per minute on a single scheduler host. The overlap lock uses the file cache, so `storage/framework/cache/data` must be persistent and shared across releases on that host.
- Back up with `pg_dump --format=custom` and prove recovery by restoring into a separate database, then checking migration status and row counts. Keep `APP_KEY` outside the database backup.
- Roll back by restoring the previously verified image digest with the same `APP_KEY` and a compatible schema. Never run `migrate:fresh` or table-dropping rollbacks against production.
- Watch HTTP 5xx and 429 rates, worker memory, scheduler executions, failed audit codes and backup age. `/up` only proves that the application boots.
- Handle content takedown requests privately with a minimal decision record; do not promise removal from third-party copies.

## License

Project code is licensed under the [MIT license](LICENSE). Third-party images and source content retain their respective rights; the code license does not grant rights to them.
