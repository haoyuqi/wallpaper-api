# Contributing

Bug reports, documentation improvements and focused pull requests are welcome.
Search existing issues before opening a new one. Discuss changes to the public
contract or major features before implementing them.

## Documentation map

| Topic | Reference |
| --- | --- |
| Installation, runtime requirements and commands | [README](README.md#getting-started) |
| Feature implementations and tests | [Architecture and feature map](docs/architecture.md) |
| Naming, types and readability | [Code style](docs/code-style.md) |
| Public API usage and examples | [API reference](docs/api.md) |
| HTTP and Composer protocols | [v1 contract](docs/contracts/v1.md) |
| Scheduling, locks, logs and recovery | [Daily synchronization](docs/operations/daily-sync.md) |
| Container, backups and rollback | [Deployment runbook](docs/operations/deployment.md) |
| Code publication gates | [Release checklist](docs/release.md) |
| Reading order for coding agents | [AGENTS.md](AGENTS.md) |

## Change requirements

| Rule | Evidence to check |
| --- | --- |
| Keep changes focused | Each behavior change serves the stated goal; unrelated refactoring is separate. |
| Trace the complete path | Inspect entry points, callers, data changes, outputs and side effects beyond the edited function. |
| Cover related cases | Search similar entry points and conditions; identify which cases change and which remain unchanged. |
| Verify external protocols | Check dependency implementations, contracts and sanitized examples instead of guessing upstream behavior. |
| Preserve failure semantics | Invocation or parsing failures must not become normal `not_found` results. |
| Match verification to risk | Check input boundaries, transaction and database semantics, and scheduling dates and timezones as applicable. |

## Development setup

Follow the [README](README.md#getting-started). Any environment satisfying the
PHP and extension requirements is suitable, including local PHP or containers.
Create a feature branch from the latest `master`; submit changes through a
pull request.
Preserve existing changes. Concurrent writing sessions should use separate
working directories. Personal environment details belong in local instructions.
Follow the [documentation maintenance rules](docs/architecture.md#documentation-maintenance).

Keep credentials and personal environment files out of commits. PHPUnit's
shared database defaults belong in `phpunit.xml`; set local PostgreSQL
credentials in the Git-ignored `.env`.

## Verification

Run from the repository root:

```bash
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
vendor/bin/phpunit
composer audit --locked --no-interaction
```

Use `vendor/bin/pint` to fix formatting. Follow [code style](docs/code-style.md).
Documentation-only changes require link, example and formatting checks rather
than another application test run. Test observable behavior without locking down
unrelated implementation details. Record real Bing integration checks separately
and state whether they were performed.

Larastan level 5 covers application code, routes, bootstrap configuration and
database definitions without a baseline or ignored-error list. Fix findings
rather than lowering the level to make CI pass. Dependencies are locked; audit
both production and development packages. CI also builds the production image
from a clean context and exercises migrations and HTTP behavior on PostgreSQL 18.
Run `bash scripts/smoke-container.sh <container-name>` only against a disposable
test container/database: it intentionally rolls back tables. Docker production
builds are separate from the contributor's normal PHP development environment.

The SQLite CI suite requires exactly 100% PHPUnit line coverage for all source
files under `app/`; no source files are excluded from the coverage scope. The
gate compares covered and executable line counts directly, without rounding.
The default local suite uses PostgreSQL and makes no Bing requests. SQLite is
useful for a quick check but skips PostgreSQL-only assertions. PostgreSQL tests
require the PDO PostgreSQL extension. Copy `.env.example` to the Git-ignored
`.env` and set the local test username and password there. Laravel reads `.env`
during PHPUnit runs because this repository does not provide `.env.testing`.
`phpunit.xml` selects the `testing` connection and sets these non-secret values:

- `TEST_DB_CONNECTION=pgsql`
- `TEST_DB_HOST` and `TEST_DB_PORT`
- `TEST_DB_DATABASE=wallpaper_api_test`

`TEST_DB_USERNAME` and `TEST_DB_PASSWORD` come from the ignored `.env`. CI
overrides the connection and database for its SQLite job and supplies
PostgreSQL settings separately.

Use a dedicated local test server or an isolated CI service. The database role
must be able to create the test database. Migrations create it if absent and
reset its tables during testing. The test base rejects PostgreSQL database names
other than `wallpaper_api_test`. Do not use shared or production credentials.
All PostgreSQL-specific tests should pass when using that backend.
The connection guard runs before database traits; a permitted database name does
not prove the host or role is safe. Verify both independently.

Run targeted tests while changing behavior and all applicable checks before
handoff. Bug fixes should include a regression test that catches the original
behavior. Where practical, verify in an isolated working directory that the old
logic fails a behavioral assertion; compilation failures and skipped tests do
not demonstrate reproduction. Explain when this is inapplicable. Do not invent
regression tests for documentation or formatting changes.

Record the verified commit SHA, runtime versions, checks and results, relevant
pass/skip counts, and checks not run or failing with reasons. After new commits,
repeat affected checks. Cancelled or incomplete CI is not a pass; investigate
known failures rather than rerunning them repeatedly and claiming a fix.

## Dependency updates

Dependabot security updates open pull requests against `master`. A patch update
that only changes `composer.json` and `composer.lock` gets squash auto-merge from
[the auto-merge workflow](.github/workflows/dependabot-auto-merge.yml); GitHub
merges it after the required `CI result` check passes and the branch is up to
date. Minor, major, pre-release and unrecognized updates stay open for manual
review. If a pull request later stops matching, the workflow disables its
auto-merge. Merging changes only the code: it does not tag, release or deploy.
Turn off **Allow auto-merge** in the repository settings to stop all automatic
merging.

This workflow is the one exception to the read-only workflow rule: it needs
`contents: write` to enable auto-merge, runs on `pull_request_target`, and must
never check out or execute pull request code. A guard test enforces both limits.

## Issues and pull requests

Include reproducible steps, expected and actual behavior, and relevant runtime
versions in bug reports. Use synthetic examples and sanitized diagnostics;
never attach credentials, private data or full environment files.

Keep commits focused with short subjects. For substantive changes, explain why
and record key verification in the commit body.

Describe each affected feature in the PR: its trigger, before/after behavior,
and effects on interfaces, data, compatibility and failures. For refactoring,
state which contracts remain unchanged. Documentation alone is not evidence
that the implementation is correct. Link relevant source, documentation and
existing issues; small changes do not require a new issue. Use the
[PR template](.github/PULL_REQUEST_TEMPLATE.md).

Preserve compatibility with the [v1 contract](docs/contracts/v1.md) unless a
breaking change has been discussed. Do not include private planning notes or
generated caches.

Review in this order:

1. Locate the actual entry points through the feature map.
2. Compare the base and current commits; trace callers, state changes and side effects.
3. Check public fields, transactions, dates and exception boundaries against test evidence.
4. Verify documentation against code, check links and record unverified areas.

Tie review conclusions to a specific commit. Revisit affected behavior after
new commits, and verify the reviewed head and applicable CI before merging.
A review does not authorize merging, releasing or deploying.

Communicate respectfully and keep feedback focused on the work.
