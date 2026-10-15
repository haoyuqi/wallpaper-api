# Public API controls

## Cross-origin reads

Laravel's global CORS middleware applies to `api/*`. The public metadata API
defaults to `CORS_ALLOWED_ORIGINS=*`, permits `GET` and `HEAD`, and never enables
credentialed CORS. `Accept` and `Content-Type` are the permitted request headers.
Preflight responses are cached by browsers for up to 600 seconds and do not
consume the query rate limit.

Set `CORS_ALLOWED_ORIGINS` to a comma-separated list of exact origins, including
scheme and any non-default port, to restrict browser access. Whitespace around
entries is ignored; an empty value grants no cross-origin permission. For example:

```dotenv
CORS_ALLOWED_ORIGINS=https://gallery.example,https://preview.example
WALLPAPER_REQUESTS_PER_MINUTE=60
```

CORS is a browser policy, not authentication. Disallowed origins can still reach
the public endpoint using non-browser clients. The CORS library may emit the
single configured origin even for requests from other origins; browsers reject
that mismatched value. Multiple origins use dynamic matching and `Vary: Origin`.
Allowed browsers can read error responses and rate-limit headers as well as
successful responses.

Rebuild the configuration cache after changing environment configuration.

## Request budget

The wallpaper route uses Laravel's named `wallpapers` rate limiter, defaulting to
60 requests per IP per 60-second window starting with the first counted request.
`WALLPAPER_REQUESTS_PER_MINUTE` adjusts the allowance; values below one clamp to
one. `GET`, `HEAD`, invalid queries and both date modes share the same IP budget.
The `/up` health endpoint is not rate limited by this rule.

Exhausted clients receive HTTP 429 with the stable `TOO_MANY_REQUESTS` envelope,
`Retry-After`, and Laravel's `X-RateLimit-*` headers. CORS exposes these headers to
browser clients. Retry after the specified delay rather than polling rapidly.

Counters use the configured cache store (file by default). A persistent file
store is suitable for a single host; multiple replicas need a shared supported
cache or an edge rate limit. Local ephemeral counters do not provide a global
limit. This application rule is best-effort abuse mitigation, not a billing cap
or protection against a distributed attack or concurrent bursts.

The key uses Laravel's resolved client IP. Forwarded headers are not trusted by
default. Configure only the deployment's verified proxy addresses before relying
on forwarded IPs; trusting arbitrary proxies permits budget evasion. Users behind
the same public IP share a budget. Never trust a caller-supplied IP query field.

## Query resources and upstream isolation

The v1 date contract has no date-span limit, result truncation or pagination.
The service executes one indexed date-filtered query with an explicit public
column projection and stable ordering. It does not select raw JSON or audit
payloads, enumerate every day in a range, or contact Bing during public reads.
Client-supplied URL parameters are ignored and never fetched; only the separate
synchronization command performs an upstream lookup.

The broad-query regression test requests `0001-01-01` through `9999-12-31` against
1,000 synthetic records containing roughly 4 MiB of private raw payloads. It
checks the complete result count, single projected query, absence of raw payloads
in SQL and output, no outbound HTTP requests, and no synchronization audit writes.
This is a functional resource check, not a production capacity benchmark.

Responses still materialize all matching public metadata in memory. Memory and
response size therefore grow with matching records; throttling does not bound
one response. Set worker memory, request/concurrency and timeout budgets for the
deployment and measure representative data there. Do not silently truncate
responses or introduce a date cap to hide resource exhaustion. Revisit the
public contract explicitly if volume requires pagination or streaming.

## Failures and logs

Production must set `APP_DEBUG=false`. Existing centralized API error handling
also sanitizes application failures when debug is accidentally enabled. It keeps
HTTP status and required headers without exposing SQL, paths or stack traces.
Reportable API failures use fixed messages, error codes and exception classes;
synchronization failures use fixed classifications and audit IDs. Routine
validation and throttle responses do not generate per-request error logs.

Use bounded log retention and deployment access metrics to observe failure rates
and resource pressure. Web-server, startup failures and infrastructure monitoring
remain deployment responsibilities. Do not log URL query values or raw upstream
payloads as a substitute for metrics.
