# Security policy

Security fixes target the latest maintained code on `master` and the current
stable release. Older application versions are not maintained runtimes.
Use PHP 8.4 and keep deployed images and dependencies updated.

Do not disclose credentials, database dumps, personal data, sensitive logs or
exploit details in a public issue. If GitHub's private vulnerability reporting
is enabled, use the repository's Security tab to report privately. Otherwise,
ask the maintainer for a private reporting channel without publishing the details.

Include affected commits or versions, impact, reproducible steps with synthetic
data, and any proposed mitigation. Do not probe third-party services or other
people's deployments without their authorization. If credentials were exposed,
rotate them; deleting a file does not remove secrets from Git history or copies.

The service is public and read-only. CORS and IP rate limits are not authentication
or infrastructure billing caps. Report unsafe error disclosure, unintended
upstream requests and integrity problems as security concerns. See the
[deployment runbook](docs/operations/deployment.md) for operational boundaries and
content/takedown handling.
