# Wallpaper API

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
contact Bing. See the [v1 contract](docs/contracts/v1.md) for fields and examples.

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

## Tests and code style

```bash
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpunit
```

The default test suite uses isolated in-memory SQLite. PostgreSQL-specific tests
are skipped in that mode. CI runs the suite with both SQLite and PostgreSQL 18
on PHP 8.4. See [contributing](CONTRIBUTING.md) for PostgreSQL test configuration
and [code style](docs/code-style.md) for naming and readability conventions.
Pint uses the Laravel preset and does not enforce strict typing declarations.

## Documentation and support

- [Architecture](docs/architecture.md): responsibilities, data flows and extension boundaries
- [API and Composer contract](docs/contracts/v1.md): inputs, responses and compatibility
- [Daily synchronization](docs/operations/daily-sync.md): operation and troubleshooting
- [Public API controls](docs/operations/public-api.md): CORS, request limits and resource boundaries
- [Code style](docs/code-style.md): contribution conventions
- [Issues](https://github.com/haoyuqi/wallpaper-api/issues): bug reports, questions and proposals
- [Contributing](CONTRIBUTING.md): development setup and pull requests

## License and archived images

Project code is licensed under the [MIT license](LICENSE). Third-party images
and source content retain their respective rights; the code license does not
grant rights to them.

Historical wallpaper images under `storage/bing-wallpaper/` are retained as an
archive. The API runtime does not read or modify that archive, and `.gitignore`
ignores any new files under `storage/bing-wallpaper/` so local runs and CI cannot
stage new wallpaper images.
