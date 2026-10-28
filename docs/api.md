# Public API reference

Query stored wallpaper metadata by date. Version 1 requires no authentication
and returns JSON. Queries never contact Bing or download images.

Examples use the local development server at `http://127.0.0.1:8000`.
Replace that origin with your deployment's HTTPS origin when connecting remotely.
Dates and image URLs below are illustrative; availability depends on stored data.

## Endpoint and parameters

```http
GET /api/v1/wallpapers
Accept: application/json
```

Supply parameters in the URL query string, not a request body.

| Parameter | Format | Requirement |
| --- | --- | --- |
| `date` | `YYYY-MM-DD` | Required for an exact-date query; omit `from` and `to`. |
| `from` | `YYYY-MM-DD` | Required with `to` for a range; omit `date`. |
| `to` | `YYYY-MM-DD` | Required with `from` for a range; omit `date`. |

Use real calendar dates with a four-digit year, two-digit month and two-digit day
(`0001-01-01` through `9999-12-31`). Empty values, surrounding whitespace, invalid
dates such as `2026-02-30`, and timestamps are invalid. Dates are calendar labels;
clients must not shift them into another date when converting timezones.

Ranges include both endpoints and require `from <= to`. There is no date-span
limit or pagination; large ranges can produce large responses. Version 1 does
not support source, market, keyword or tag filters.

## Request examples

Exact date:

```bash
curl --get 'http://127.0.0.1:8000/api/v1/wallpapers' \
  --header 'Accept: application/json' \
  --data-urlencode 'date=2026-08-31'
```

Date range:

```bash
curl --get 'http://127.0.0.1:8000/api/v1/wallpapers' \
  --header 'Accept: application/json' \
  --data-urlencode 'from=2026-08-01' \
  --data-urlencode 'to=2026-08-31'
```

## Successful responses

HTTP `200`:

```json
{
  "data": [
    {
      "id": "01K41V6QAZ0000000000000000",
      "source": "bing",
      "market": "en-US",
      "sourceDate": "2026-08-31",
      "title": "Mountain lake at sunrise",
      "copyright": "Example © Photographer",
      "copyrightLink": "https://www.bing.com/search?q=example",
      "imageUrl": "https://www.bing.com/th?id=example_1920x1080.jpg",
      "downloadUrl": "https://www.bing.com/th?id=example_UHD.jpg"
    }
  ],
  "meta": {
    "query": {
      "date": "2026-08-31",
      "from": null,
      "to": null
    },
    "count": 1
  }
}
```

| Field in each `data` item | Type | Meaning |
| --- | --- | --- |
| `id` | string | Opaque record identifier; do not parse its internal format. |
| `source` | string | Metadata source, currently `bing`. |
| `market` | string | Source market, currently `en-US`. |
| `sourceDate` | string | Source calendar date in `YYYY-MM-DD` format. |
| `title` | string or null | Source title. |
| `copyright` | string or null | Source attribution text. |
| `copyrightLink` | HTTPS URL or null | Source attribution link. |
| `imageUrl` | HTTPS URL | Image address provided by the source. |
| `downloadUrl` | HTTPS URL or null | Download address provided by the source. |

Results are sorted by `sourceDate` descending, then `id` descending. `meta.count`
is the number of returned items. `meta.query` always has all three keys; in range
mode it contains `date: null` and the requested `from` and `to` values.
Internal source identifiers, raw payloads and synchronization records are not
exposed. Image URLs point to the source; the API does not proxy image files.

No stored matches also returns HTTP `200`, not `404`:

```json
{
  "data": [],
  "meta": {
    "query": {
      "date": "2026-08-31",
      "from": null,
      "to": null
    },
    "count": 0
  }
}
```

## Errors

Invalid query parameters return HTTP `422`. For example, `date=2026-02-30`:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The request parameters are invalid.",
    "details": [
      {
        "field": "date",
        "code": "INVALID_DATE",
        "message": "The date must be a real calendar date in YYYY-MM-DD format."
      }
    ]
  }
}
```

| Validation detail code | Meaning |
| --- | --- |
| `QUERY_MODE_REQUIRED` | Supply `date` or both range endpoints. |
| `QUERY_MODE_CONFLICT` | Do not combine `date` with `from` or `to`. |
| `RANGE_ENDPOINT_REQUIRED` | Supply the missing range endpoint. |
| `INVALID_DATE` | A supplied value is not a real date in the required format. |
| `RANGE_ORDER_INVALID` | `from` must be on or before `to`. |

Query-mode errors take precedence over date-format errors, which take precedence
over range-order errors. Details are ordered by `date`, `from`, `to`, then
`query`. Use error codes for client logic rather than matching English messages.

Other API errors use the same envelope without validation details:

```json
{
  "error": {
    "code": "INTERNAL_ERROR",
    "message": "An internal error occurred."
  }
}
```

| HTTP status | Error code | Meaning |
| --- | --- | --- |
| `404` | `NOT_FOUND` | Unknown API route; not an empty wallpaper result. |
| `405` | `METHOD_NOT_ALLOWED` | Unsupported request method. |
| `422` | `VALIDATION_ERROR` | Invalid query parameters. |
| `429` | `TOO_MANY_REQUESTS` | Request allowance exhausted; respect `Retry-After`. |
| `500` | `INTERNAL_ERROR` | Application failure; internal diagnostics are withheld. |
| `503` | `SERVICE_UNAVAILABLE` | Service temporarily unavailable. |

The full error mapping is in the [v1 contract](contracts/v1.md#other-api-errors).
Infrastructure failures outside the application may have a different format.

## Rate limits and browser access

The default allowance is 60 requests per IP per 60-second window, shared by
exact-date queries, range queries, `HEAD` requests and invalid queries.
Deployments may configure a different allowance. Inspect `X-RateLimit-Limit`
and `X-RateLimit-Remaining`; after HTTP `429`, wait the number of seconds given
by `Retry-After` before retrying.

By default, browser requests from any origin are allowed without credentials.
Deployments can restrict allowed origins. CORS does not provide authentication.
Set `CORS_ALLOWED_ORIGINS` and `WALLPAPER_REQUESTS_PER_MINUTE` to change these
defaults.

## Related documentation

- [v1 contract](contracts/v1.md): normative HTTP and Composer protocol details.
- [README](../README.md): installation, configuration, verification and operations.
