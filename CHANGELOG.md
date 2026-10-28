# Changelog

## Unreleased

- Require patched PHP runtimes (8.4.27 or later, 8.5.12 or later within PHP 8.x)
  for the application, the Composer manifest and the production image.
- Update the Bing wallpaper dependency to 3.0.1.
- Reject composer URLs that embed a user name or password, so credentials cannot
  be stored or published with the metadata.
- Require complete `app/` line coverage in CI and publish the measured run
  summary.

## 1.1.0 — 2026-10-21

- Add an upgrade migration for the `sync_runs` lookup index so existing
  installations receive the index without rerunning their original migration.
- Make PostgreSQL the default local PHPUnit database, with credentials kept in
  the ignored `.env`, while CI continues to test both SQLite and PostgreSQL 18.
- Simplify application scaffolding and adjust production container concurrency.
- Refresh public documentation to describe the current API and release process.

The public API and v1 JSON contract are unchanged. This release does not deploy
infrastructure or download images.

## 1.0.0 — 2026-10-20

- Rebuild the application as a Laravel 13 metadata API with PHP 8.4.
- Validate the `download-bing-wallpaper` v3 Artisan JSON contract in-process.
- Persist normalized metadata and original source JSON with idempotent upserts
  and transactional synchronization audits.
- Schedule one synchronization at 12:00 UTC for the previous UTC calendar date.
- Expose exact-date and inclusive date-range queries with stable public JSON.
- Add configurable credential-free CORS and per-IP request limits, safe API
  errors and structured failure logs. Anonymous exception names do not disclose
  their source paths in application logs.
- Remove the legacy image downloader and README generator.
- Provide a non-root PHP Apache production image, PostgreSQL container smoke
  checks, Larastan analysis and dependency auditing in CI.
- Document architecture, contribution rules, public API controls, deployment,
  backup/restore, rollback, security and release procedures in linked references.

This code release does not deploy infrastructure or grant rights to third-party
images. See [release boundaries](docs/release.md#known-v1-boundaries).
