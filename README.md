# Wallpaper API

[![CI](https://github.com/haoyuqi/wallpaper-api/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/haoyuqi/wallpaper-api/actions/workflows/ci.yml?query=branch%3Amaster)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/docs/13.x)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Latest release](https://img.shields.io/github/v/release/haoyuqi/wallpaper-api)](https://github.com/haoyuqi/wallpaper-api/releases/latest)

[English](README.md) | [简体中文](README.zh-CN.md)

A Laravel 13 API for collecting Bing wallpaper metadata and querying it by date.
It stores metadata and source JSON; synchronization does not download images.

## Requirements

- PHP 8.4 or later within the PHP 8.x series
- Composer 2
- PDO SQLite for the default local setup, or PDO PostgreSQL for PostgreSQL
- PostgreSQL 18 is tested in CI; SQLite is also supported

Use a local PHP installation or a container environment of your choice. Run
the commands below from the repository root in that environment.

## Getting started

```bash
git clone https://github.com/haoyuqi/wallpaper-api.git
cd wallpaper-api
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

The example configuration uses SQLite. For PostgreSQL, configure the database
connection in your local `.env` and create the application database before
running migrations. Keep credentials out of version control.

The local server defaults to `http://127.0.0.1:8000`. Check `GET /up` to confirm
the application boots. Do not use `artisan serve` as a production web server.

## Synchronize metadata

```bash
php artisan wallpapers:sync --date=2026-10-01
```

The command invokes the installed
[download-bing-wallpaper](https://github.com/haoyuqi/download-bing-wallpaper)
3.x Artisan command and validates its JSON response. Repeated successful
lookups update the same source identity; every lookup records a synchronization
attempt. Bing may not have data for a requested date.

Exit codes are `0` for success or `not_found`, `1` for source or persistence
failure, and `2` for missing or invalid dates. Output includes the outcome and
audit ID, without raw documents or exception details.

## Query metadata

```http
GET /api/v1/wallpapers?date=2026-10-01
GET /api/v1/wallpapers?from=2026-10-01&to=2026-10-07
```

Supply either `date` or both `from` and `to`, in real `YYYY-MM-DD` calendar-date
format. Ranges include both endpoints. Results are ordered by source date and
ID descending and contain only public metadata.

Valid queries return HTTP 200, including empty results. Invalid parameters
return HTTP 422 with stable error codes. Queries read stored data and do not
contact Bing. See the [API reference](docs/api.md) for parameters, curl examples,
response fields and errors.

Public reads default to 60 requests per IP per minute. Over-budget requests
return HTTP 429 with `Retry-After`. CORS defaults to public, credential-free
reads; configure `CORS_ALLOWED_ORIGINS` and `WALLPAPER_REQUESTS_PER_MINUTE` for
your deployment. See [public API controls](docs/operations/public-api.md).

Version 1 has no authentication, tags, keyword filters or pagination. Large
ranges can return large responses; choose an appropriate range for your client.

## Daily synchronization

The registered task runs at 12:00 UTC and requests the previous UTC date.
Deployments must invoke Laravel's `schedule:run` once per minute on one
scheduler host. Its file-cache lock requires writable, persistent storage.

```bash
php artisan schedule:list
```

See [daily synchronization](docs/operations/daily-sync.md) for scheduler
configuration, locks, failure logs and recovery.

## Production and releases

The [production image](Dockerfile) runs PHP 8.4 with Apache as a non-root user
on port 8080. It excludes secrets and development dependencies;
it does not start a scheduler or migrate automatically. CI builds the image and
checks it against a disposable PostgreSQL 18 database.

Follow the [deployment runbook](docs/operations/deployment.md) for runtime secrets,
TLS/proxies, persistent locks, migrations, health checks, backups and rollback.
`/up` confirms application boot, not database availability or synchronization
freshness. Actual hosting and resource provisioning are separate from the code
release. See [release gates](docs/release.md) and [CHANGELOG](CHANGELOG.md).

## Tests and code style

```bash
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
vendor/bin/phpunit
composer audit --locked --no-interaction
```

The local `.env` is ignored by Git. Copy `.env.example` to `.env`, then supply
the password for a dedicated PostgreSQL test role under `TEST_DB_PASSWORD`.
PHPUnit selects the `testing` connection and reads these `TEST_DB_*` values from
`.env`; no `.env.testing` file is required. Without these values, the testing
connection falls back to in-memory SQLite and skips PostgreSQL-only assertions.
CI runs both databases on PHP 8.4.
See [contributing](CONTRIBUTING.md) for PostgreSQL test configuration
and [code style](docs/code-style.md) for naming and readability conventions.
Pint uses the Laravel preset and does not enforce strict typing declarations.
Larastan checks application code, routing, bootstrap and database definitions at
level 5 without an ignored-error baseline. CI also tests the production container;
automated checks do not call live Bing.

## Documentation and support

- [Architecture](docs/architecture.md): responsibilities, data flows and extension boundaries
- [API reference](docs/api.md): query parameters, curl examples, responses and errors
- [API and Composer contract](docs/contracts/v1.md): inputs, responses and compatibility
- [Daily synchronization](docs/operations/daily-sync.md): operation and troubleshooting
- [Public API controls](docs/operations/public-api.md): CORS, request limits and resource boundaries
- [Deployment runbook](docs/operations/deployment.md): container, migrations, backups and rollback
- [Release checklist](docs/release.md) and [changelog](CHANGELOG.md): publication gates and changes
- [Security policy](SECURITY.md): private vulnerability reporting
- [Code style](docs/code-style.md): contribution conventions
- [Issues](https://github.com/haoyuqi/wallpaper-api/issues): bug reports, questions and proposals
- [Contributing](CONTRIBUTING.md): development setup and pull requests

## License

Project code is licensed under the [MIT license](LICENSE). Third-party images
and source content retain their respective rights; the code license does not
grant rights to them.
