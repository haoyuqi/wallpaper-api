# Project agent guide

This guide supplements global instructions. Follow the user's requirements for the environment, safety and authorization.

## Reading order

- [README.md](README.md): setup, configuration, API usage, verification and operations.
- [docs/architecture.md](docs/architecture.md): feature map, responsibilities and runtime paths.
- [docs/code-style.md](docs/code-style.md): naming, types and readability.
- [docs/api.md](docs/api.md): public responses, errors and limits.
- [docs/contracts/v1.md](docs/contracts/v1.md): HTTP and Composer protocol.

Read the owning reference and verify it against the source. When responsibilities, interfaces, execution paths or failure behavior change, update that document in the same change.

## Boundaries

- A source or parsing failure never becomes a normal `not_found`.
- Keep public JSON keys, error codes and the Composer document compatible; protocol changes go to `docs/contracts/v1.md` first.
- Automated checks never call live Bing.
- Never run `migrate:fresh`, `migrate:reset` or a table-dropping rollback outside a disposable database.

## Verification

Run the commands in README's *Testing and quality gates*. The suite uses the `testing` connection from `phpunit.xml`, rejects PostgreSQL database names other than `wallpaper_api_test`, and CI requires exactly 100% line coverage for `app/`. Report what ran and what did not; a cancelled run is not a pass.

## Delivery

- Branch from `master` as `<type>/<issue-number>-<kebab-case-description>`, or `<type>/<kebab-case-description>` when there is no issue. Supported types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `chore`.
- Commit messages and pull request titles use `<type>(<scope>): <imperative summary>` in English, lowercase, without a trailing period, within 72 characters.
- Keep commits focused, describe the affected behavior, and record the checks in the body.
- Dependabot patch updates that only touch `composer.json` and `composer.lock` auto-merge after `CI result`; that workflow runs with write access on `pull_request_target` and must never check out pull request code.
