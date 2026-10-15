# Contributing

Bug reports, documentation improvements and focused pull requests are welcome.
Search existing issues before opening a new one. Discuss changes to the public
contract or major features before implementing them.

## Development setup

Follow the [README](README.md#getting-started). Any environment satisfying the
PHP and extension requirements is suitable, including local PHP or containers.
Create a feature branch from the latest `master`; submit changes through a
pull request.

Keep credentials and personal environment files out of commits. The tracked
`.env.testing` is shared test configuration, not a place for private credentials.
Pass PostgreSQL test credentials through environment variables.

## Verification

Run from the repository root:

```bash
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpunit
```

Use `vendor/bin/pint` to fix formatting. Follow [code style](docs/code-style.md).
Add or update meaningful tests for behavior changes. Update relevant docs when
changing public behavior, configuration or operating requirements.

The default suite uses isolated in-memory SQLite and makes no Bing requests.
PostgreSQL-specific tests require PostgreSQL 18 and the PDO PostgreSQL extension.
Set these environment variables for the test process:

- `TEST_DB_CONNECTION=pgsql`
- `TEST_DB_HOST` and `TEST_DB_PORT`
- `TEST_DB_USERNAME` and `TEST_DB_PASSWORD`
- `TEST_DB_DATABASE=wallpaper_api_test`

Use a dedicated local test server or an isolated CI service. The database role
must be able to create the test database. Migrations create it if absent and
reset its tables during testing. The test base rejects PostgreSQL database names
other than `wallpaper_api_test`. Do not use shared or production credentials.
All PostgreSQL-specific tests should pass when using that backend.

## Issues and pull requests

Include reproducible steps, expected and actual behavior, and relevant runtime
versions in bug reports. Use synthetic examples and sanitized diagnostics;
never attach credentials, private data or full environment files.

Keep changes focused. Explain the problem, resulting behavior and checks run
in your pull request. Preserve compatibility with the [v1 contract](docs/contracts/v1.md)
unless a breaking change has been discussed. Do not include private planning
notes or generated caches.

Communicate respectfully and keep feedback focused on the work.
