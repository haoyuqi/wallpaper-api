# Code release checklist

The planned first code release is `v1.0.0`. This checklist does not deploy the
service or publish a container image. The Composer package has its own version;
its `v3` tags are unrelated to this API's release numbering.

## Required gates

1. Merge reviewed code through a PR. Confirm WAPI-09 and WAPI-10 are complete. WAPI-12 removes the legacy PNGs after explicit approval; verify its [archival tag and manifest](archive/README.md) before releasing the cleaned tree.
2. Verify the exact release commit is on `master` with no uncommitted changes. Run the full CI workflow on that commit, including PHP 8.4 lint/static analysis/audit, SQLite, PostgreSQL 18 and the production-container smoke test.
3. Inspect the container smoke result: non-root execution, isolated migrations and rollback, cached boot, health, public query contract, CORS/limit headers and sanitized database failure. It makes no live Bing requests.
4. Review README translations, the v1 contract, architecture and operating references against the final code. Record any known limitations in release notes.
5. Review [CHANGELOG](../CHANGELOG.md), rename the pending section to the version and publication date in the release PR, then verify the final merged commit again.

CI runs on PRs, `master`, manual dispatch, and daily at 23:00 UTC
(07:00 Asia/Shanghai the following day). Scheduled runs use the default branch;
GitHub may delay their start. Base image tags can change, so
record the tested application image digest for actual deployment. CI does not
push images, create tags, or commit wallpaper data. A tag push alone does not
prove that the release passed CI.

## Publish from the verified commit

Only after the gates pass, use a clean checkout of the verified `master` commit:

```bash
git fetch origin master --tags
git switch master
git merge --ff-only origin/master
gh run list --workflow ci.yml --branch master
```

Inspect the successful run's head SHA and confirm it equals `git rev-parse HEAD`.
Never move an existing published tag to different code. Create an annotated tag
and a GitHub Release with notes describing the API, requirements and limitations:

```bash
git tag -a v1.0.0 -m "Wallpaper API v1.0.0"
git push origin v1.0.0
gh release create v1.0.0 --verify-tag --title "Wallpaper API v1.0.0" \
  --notes-file CHANGELOG.md
```

The tag identifies the commit; the Release adds the human-readable announcement.
Close WAPI-11 only after the release exists and references the verified tag.
Deployment remains a separate operational task. Version changes follow semantic
versioning; changes to frozen public fields/date semantics require explicit
compatibility review. Document a correction in a new release rather than silently
changing existing tags.

## Known v1 boundaries

- Metadata and source JSON only: no image download/proxy or image ownership rights.
- Fixed Bing `en-US` integration; no authentication, tags, keyword search or pagination.
- Successful synchronization is idempotent; attempts are audited separately. No automatic retries or historical backfills.
- Full matching responses materialize in memory; a per-IP budget does not bound one response or distributed traffic.
- One scheduler host with persistent file locks; ephemeral multi-instance deployments need deliberate coordination.
- `/up` checks boot only, not data freshness or database/source readiness.
- Runtime supports SQLite and PostgreSQL 18; PHP 8.4 is the supported CI baseline. No PHP 8.3 support is claimed.
