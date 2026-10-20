# Changelog

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
- Disable the legacy downloader/README generator and remove the 321 historical
  PNGs from the current tree. Preserve them at `archive/legacy-wallpapers` with a
  recovery manifest, without rewriting Git history; prevent new tracked images.
- Provide a non-root PHP Apache production image, PostgreSQL container smoke
  checks, Larastan analysis and dependency auditing in CI.
- Document architecture, contribution rules, public API controls, deployment,
  backup/restore, rollback, security and release procedures in linked references.

This code release does not deploy infrastructure or grant rights to third-party
images. See [release boundaries](docs/release.md#known-v1-boundaries).
