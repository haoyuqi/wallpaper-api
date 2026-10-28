# Project agent guide

This guide supplements global instructions. Follow the user's requirements for the environment, safety and authorization.

## Reading order

- [README.md](README.md): installation, configuration, API usage, verification and operations.
- [docs/architecture.md](docs/architecture.md): feature map, responsibilities and runtime paths.
- [docs/code-style.md](docs/code-style.md): naming, types and readability.
- [docs/api.md](docs/api.md): public response fields, errors and limits.
- [docs/contracts/v1.md](docs/contracts/v1.md): HTTP and Composer protocol.

Read the relevant reference, verify it against the source, and update the owning document in the same change when responsibilities, interfaces, execution paths or failure behavior change. Keep each fact in one place.

## Change boundaries

- Trace the complete path: entry point, callers, data writes, outputs and side effects.
- Preserve failure semantics: an invocation or parsing failure must never become a normal `not_found`.
- Keep public JSON keys, error codes and the Composer document compatible unless a breaking change is agreed; protocol changes belong in `docs/contracts/v1.md` first.
- Automated checks never call live Bing; tests use fixtures and a fake provider.
- Credentials, dumps and personal environment files never enter the repository or logs.
- Never run `migrate:fresh`, `migrate:reset` or a table-dropping rollback outside a disposable test database.

## Verification

Run from the repository root:

```bash
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
vendor/bin/phpunit
composer audit --locked --no-interaction
```

The default suite uses the dedicated PostgreSQL connection configured in `phpunit.xml` and reads `TEST_DB_USERNAME` and `TEST_DB_PASSWORD` from the ignored `.env`. The test base rejects PostgreSQL database names other than `wallpaper_api_test`. SQLite is a quick alternative but skips PostgreSQL assertions. CI requires exactly 100% line coverage for `app/`.

Report the checks run, their results and any unverified scope. A cancelled or incomplete run is not a pass.

## Dependency updates

Dependabot security updates target `master`. Only a non-draft pull request from this repository, targeting `master`, with a Composer patch update that changes just `composer.json` and `composer.lock` gets squash auto-merge after `CI result` passes. Minor, major, pre-release and unknown updates need manual review. That workflow runs on `pull_request_target` with `contents: write` and must never check out or execute pull request code.

## Commits and pull requests

- Branch from the latest `master` and submit through a pull request.
- Keep commits focused, with a short imperative subject. Substantive changes explain why and record verification in the body.
- Describe each affected feature: its trigger, before and after behavior, and effects on interfaces, data, compatibility and failure handling.
- Documentation alone does not prove the implementation; link the tests or checks that exercise the change.
