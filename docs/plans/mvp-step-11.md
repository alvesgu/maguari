# MVP Step 11: Read stored runs through an API endpoint

## Context

Step 10 (`docs/plans/mvp-step-10.md`) finished the test email. Readings have been stored as runs since step 7, but nothing reads them back except the daily job, which only looks at each metric's current run.

Step 11 is: "Read stored runs through an API endpoint for future charts" (design section 15). The design says:

- **9.1:** runs live in `monitoring_metric_runs(id, instance_id, metric, value, start_at, end_at)`, with an index on `(instance_id, metric, start_at)` that "finds the current run and serves time-range reads". A new run starts when the value changes (for `disk_used_bytes`, beyond a 0.1% deadband, keeping the run's first value) or after a gap of more than 1.5 times the expected interval. "Charts must use step rendering, not linear interpolation."
- **10.1:** "uPlot for charts (runs in the browser, drawing JSON returned by the server)".
- **10.2:** routes are grouped by surface and each group has its own middleware chain, so new routes inherit protection. `/admin/*` has the session check and the CSRF check.
- **2.1:** Monitoring owns metrics, readings and runs. `Http/` stays thin and other contexts use only `MonitoringApi`.
- **14:** the free tier has 1 GB of egress a month, and dashboard use counts against it.

The endpoint's consumer is a chart that does not exist yet. So this step delivers the endpoint, its tests and the rule a chart will use to draw runs as steps. It adds no chart.

Relevant design sections: 2.1, 3.1, 9.1, 10.1, 10.2, 11.3, 14, 15.

## Is this too large for one step?

No. One endpoint, one repository query and small changes to the `/admin` wiring. One commit that passes both test suites.

## Interpretations (to confirm)

**I1. Only the endpoint.** No chart, no uPlot, no changes to the instance page. The endpoint is checked by its tests and by opening it in a signed-in browser (Verification).

**I2. The reader is a signed-in administrator's browser.** The endpoint serves the web app, so it belongs to the `/admin` surface, not to `/api/client/` (which is for clients and signed with HMAC). An admin session cannot impersonate a client and a client secret cannot read runs (design 10.2).

**I3. One metric of one instance per request.** A chart of disk usage needs `disk_used_bytes:/` and `disk_total_bytes:/`, which is two requests. Listing which metrics an instance has is not part of this step (see "Not in this step").

**I4. Runs are returned as stored.** The server does not resample, average or turn runs into chart points. Runs are the source of truth and are smaller than points.

## Decisions (proposed)

### The address

**D1. `GET /admin/api/instances/{id}/runs`**, in a new inner group `/admin/api` inside the `/admin` group.

```
GET /admin/api/instances/12/runs?metric=disk_used_bytes%3A%2F&from=1759881600&to=1759968000
```

1. The inner group inherits the `/admin` chain (session, CSRF, signed-in administrator), so every future JSON route for the web app inherits it too.
2. What changes for routes under `/admin/api/` (D7): JSON errors instead of HTML pages, and `401` instead of a redirect to the login page when nobody is signed in. A script calling `fetch()` would otherwise follow the redirect and get the login page's HTML with status `200`.
3. `{id}` is Fleet's instance ID (`[0-9]+`), the same ID as in `/admin/instances/{id}`.

*Alternative A:* `GET /admin/instances/{id}/runs`, next to the instance page, with no inner group. Shorter, but then the JSON error format and the `401` have to be decided route by route instead of by surface, and design 10.2 groups by surface.
*Alternative B:* a separate top-level `/api/admin/*` group. It would need its own copy of the session, CSRF and administrator middleware, which is the duplication the surface groups exist to avoid.

### The parameters

**D2. Three query parameters**, parsed and validated by Monitoring (`Domain/RunQuery`), the way `Readings::parse()` validates heartbeat readings. Each refusal is `400` with a fixed sentence (D6).

| Parameter | Required | Rule | Default |
|---|---|---|---|
| `metric` | Yes | A full metric name `<kind>:<subject>` (design 5.2), URL-encoded. The kind must be one Maguari stores (`disk_used_bytes`, `disk_total_bytes`, `certificate_expires_at`, from `shared/src/Metric.php`). The subject must not be empty and the name is at most 1,100 bytes (the longest kind, the separator and a 1,024-byte mount point, rounded up). | None |
| `from` | No | Unix seconds in UTC, digits only (no sign, no leading zeros, at most 11 digits) | `to` minus 24 hours (`RunQuery::DEFAULT_RANGE_SECONDS`) |
| `to` | No | Same format as `from`. Must be greater than `from`. May be in the future. | The server's current time |

1. `to - from` is at most 31 days (`RunQuery::MAX_RANGE_SECONDS`). D5 explains why.
2. A parameter given twice or as an array (`metric[]=...`) is refused. Unknown parameters are ignored.
3. An unknown kind is refused rather than answered with an empty list, so a typo in the kind is caught. A typo in the subject gives an empty list, like a filesystem that was never reported.
4. Times are Unix seconds, like every `_at` column (design 9.1) and uPlot's default time axis, so no conversion is needed on either side. All UTC.

**D3. Which runs are returned:** every run of that instance and metric that overlaps the range, that is `start_at <= to` and `end_at >= from`, in `start_at` order (ties by `id`). Runs are not clipped to the range: the first run may start before `from` and the last may end after `to`, and the chart sets its own axis limits.

The query stays on the `(instance_id, metric, start_at)` index. Runs never overlap (design 9.1 item 4), so only the last run that starts at or before `from` can reach into the range from before it:

```
SELECT id, value, start_at, end_at FROM monitoring_metric_runs
WHERE instance_id = :instance AND metric = :metric AND start_at <= :to AND end_at >= :from
  AND start_at >= COALESCE((SELECT MAX(start_at) FROM monitoring_metric_runs
      WHERE instance_id = :instance AND metric = :metric AND start_at <= :from), 0)
ORDER BY start_at DESC, id DESC
LIMIT :limit
```

Both parts are index range searches, so a long history costs nothing outside the range. A test checks this with `EXPLAIN QUERY PLAN` (no `SCAN` of the table). The newest rows come first so that truncation (D5) keeps the newest runs. The repository reverses them.

**D4. The instance must be picked.** The controller asks `FleetApi::pickedInstance()` first, exactly like the instance page: an instance that is not picked is `404 not_found`, even if runs remain from an earlier pick. A picked instance with no runs in the range is `200` with an empty list.

### The JSON format

**D5. The response** (`200`, `Content-Type: application/json`, `Cache-Control: no-store`):

```
{
    "instance_id": 12,
    "metric": "disk_used_bytes:/",
    "from": 1759881600,
    "to": 1759968000,
    "max_gap_seconds": 90,
    "truncated": false,
    "columns": ["start_at", "end_at", "value"],
    "runs": [
        [1759870000, 1759912345, 8123456512],
        [1759912405, 1759968000, 8134567890]
    ]
}
```

1. `from` and `to` are the range actually used, after defaults, so a caller that sent neither knows what it got.
2. Each run is an array `[start_at, end_at, value]`, in the order `columns` names. Arrays instead of objects make the body about half the size, which matters for egress (design 14). `columns` keeps the body self-describing.
3. `value` is a JSON number. The column is `NUMERIC`, so an integer stays an integer and a later fractional metric is a float. Disk sizes in bytes stay far below 2^53, so JavaScript reads them exactly.
4. `max_gap_seconds` is the longest gap between two runs that still counts as continuous (D6), computed by `RunRule` from the expected interval: 90 with the MVP's 60 seconds. The browser never repeats the 1.5 factor.
5. `truncated` is `true` when more than 5,000 runs (`MonitoringApi::MAX_RUNS`) overlap the range. Then only the newest 5,000 are returned, so the chart's right side (now) is always complete. The chart can say "Older readings not shown: pick a shorter range."

How large responses are limited:

| Limit | Value | Why |
|---|---|---|
| Range | 31 days at most, 24 hours by default | Bounds the work and covers a month view |
| Runs | 5,000 at most, newest first | About 150 KB of JSON before compression. A typical metric has a few dozen runs a day. The worst case, a value changing on every heartbeat, is 1,440 runs a day, so a day always fits and a week fits only for steady metrics. |
| Body | One metric per request (I3) | Responses grow with the range, never with the number of metrics or instances |

The runs limit is applied in SQL (`LIMIT 5001`, the extra row only detects truncation), so a long range never loads more than that into memory.

*Alternative:* a cursor (`next_from`) so a caller can fetch the rest page by page. Not proposed: a chart wants one request per view, and truncation keeps the endpoint simple. It can be added later without breaking the format.
*Alternative:* downsampling (minimum and maximum per bucket) for long ranges. Not proposed: it changes values, and the limits above are enough for the MVP's one month.

**Packaging note (no change in this step):** nginx compresses only `text/html` by default. When packaging writes the server's nginx configuration, adding `application/json` to `gzip_types` makes these responses several times smaller.

### Runs as steps

**D6. The drawing rule**, recorded in design section 10.1 for the charts step. Step 11 implements only the server side; no JavaScript changes.

Each run becomes a horizontal segment at its value, from `start_at` to `end_at`. Between two consecutive runs `a` and `b`:

| `b.start_at - a.end_at` | Drawn as |
|---|---|
| At most `max_gap_seconds` | Continuous: `a`'s value holds until `b.start_at`, then the line steps to `b`'s value. Normally the gap is one interval, because the reading that changed the value started `b`. |
| More than `max_gap_seconds` | A break: no line between `a.end_at` and `b.start_at`. This is the outage the gap rule keeps visible (design 9.1 item 3), even when both runs have the same value. |

For uPlot this means: one point `(start_at, value)` and one point `(end_at, value)` per run (one point when they are equal), a `null` value between two runs separated by more than `max_gap_seconds`. The series is drawn with `uPlot.paths.stepped({align: 1})` and `spanGaps: false`. The last run ends at its `end_at`, not at the right edge of the chart, so a client that stopped reporting shows as a line that stops.

Two consequences, also recorded:

1. `disk_used_bytes` runs keep their first value (the deadband, design 9.1 item 1), so the chart can differ from the real value by up to 0.1% of the filesystem's total. Invisible at chart scale.
2. A run made of one reading (`start_at` equals `end_at`) is a single point, drawn as a dot.

*Alternative:* the server returns uPlot's point arrays directly. Not proposed: twice the size, and it would tie the endpoint to one chart library.

**Known limit:** `max_gap_seconds` comes from today's fixed interval. Once the heartbeat interval is configurable per instance (design 7.3), runs stored under an older interval may be judged with the newer one. That is for the step that makes the interval configurable.

### Errors and protection

**D7. The `/admin/api/*` surface:**

| Concern | Behavior |
|---|---|
| Session | `SessionMiddleware`, as for every `/admin` route. Reading runs counts as activity for the idle timeout, like opening a page. |
| CSRF | `CsrfMiddleware`, as for every `/admin` route. It checks only state-changing methods, so a `GET` needs no token. The route is read-only (design 11.3: no state-changing GET). |
| Signed-in administrator | `RequireAdministratorMiddleware`. Under `/admin/api/` it answers `401 {"error": "unauthorized"}` instead of `303` to `/auth/login`. |
| Security headers | `SecurityHeadersMiddleware`, unchanged. The Content-Security-Policy's `default-src 'self'` already lets the web app's own script `fetch()` same-origin JSON. |
| Caching | `Cache-Control: no-store` on every response, success and error, like `/api/client/`. |
| Not set up | While the database or secret key is not ready, `/admin/api/*` answers `503 {"error": "unavailable"}` instead of the plain-text message. |

Errors are `{"error": "<code>"}`, and for `bad_request` also `"message"` with Maguari's own sentence, because an administrator may call the endpoint by hand. No other details: uncaught exceptions are logged and answered with `server_error`, as on the other surfaces.

| Status | Code | When |
|---|---|---|
| 400 | `bad_request` | D2's rules, with a sentence such as "from and to must be Unix seconds, digits only." or "The range is longer than 31 days." |
| 401 | `unauthorized` | No signed-in administrator |
| 404 | `not_found` | Instance not picked, or unknown route under `/admin/api/` |
| 405 | `method_not_allowed` | Any method but `GET`, with `Allow` |
| 500 | `server_error` | Anything uncaught (logged, details never sent) |
| 503 | `unavailable` | Database or secret key not ready |

These codes are the server's own (`Http/AdminApiError`), not `shared/src/ErrorCode.php`: that file is the client protocol (design 4), and the browser is not a client. Where a meaning is the same the string is the same.

`SurfaceErrorHandler` gets the third surface, so `/admin/api/*` errors are JSON whatever the `Accept` header says, the same rule as `/api/client/`.

**D8. One new header on every response (proposed):** `X-Content-Type-Options: nosniff`, so a browser never treats a JSON body as anything but JSON. It belongs in the list of design 11.3. It is harmless on HTML pages and assets, whose types are already correct.

### Code layout

**D9.** Monitoring gets:

```
server/src/Monitoring/
  MonitoringApi.php                    runs(int $instanceId, array $query): RunSeries; MAX_RUNS
  Domain/RunQuery.php                  D2: parse(array $query, int $now), the defaults and limits
  Domain/RunSeries.php                 the runs, the range used, max_gap_seconds, truncated
  Domain/RunRule.php                   maxGapSeconds()
  Exception/InvalidRunQuery.php        with the sentence
  Infrastructure/MetricRunRepository.php  overlapping(instanceId, metric, from, to, limit), D3's query
```

`MonitoringApi::runs()` receives the query parameters as an array of strings and its clock gives "now" for the default `to`, so `Domain/` stays free of clock functions (design 3.1 item 7).

`Http/` gets:

```
server/src/Http/Controller/RunsController.php   thin: picked instance check, MonitoringApi::runs(), JSON
server/src/Http/AdminApiResponse.php            JSON body and headers, errors (shares the JSON writing with ClientApiResponse)
server/src/Http/AdminApiError.php               D7's codes
server/src/Http/AdminApiErrorRenderer.php       uncaught errors as JSON
```

Plus the `/admin/api` inner group and the not-ready route in `Http/App.php`, the `401` in `RequireAdministratorMiddleware`, the third surface in `SurfaceErrorHandler` and, if D8 is approved, the header in `SecurityHeadersMiddleware`. No migration: the index from step 7 already serves the query.

## Not in this step

- The chart, uPlot and any change to the instance page or dashboard.
- Listing an instance's metrics (for example `GET /admin/api/instances/{id}/metrics`). The chart will need it to know which mount points and certificates exist; it is a small addition for the charts step.
- Several metrics or instances per request, cursors and downsampling (D5).
- Automatic refresh. A chart that refreshes on a timer would keep the session alive past the idle timeout (D7), so the charts step must decide how refresh and the idle timeout fit together.
- Time-weighted averages (design 9.1). The format returns everything an average needs.

## Tests

1. `RunQuery`: defaults (24 hours ending now); each refusal with its sentence (missing metric, unknown kind, empty subject, too long, signs, leading zeros, letters, `from` not before `to`, more than 31 days, a parameter given twice or as an array); unknown parameters ignored; a `to` in the future accepted.
2. Repository against SQLite: a run starting before `from` and ending inside the range is included; a run ending before `from` and one starting after `to` are not; runs are in `start_at` order with ties by `id`; other instances and other metrics (including one whose name starts with the same text) are never mixed in; truncation keeps the newest runs and says so; `EXPLAIN QUERY PLAN` shows index searches and no table scan.
3. `RunRule::maxGapSeconds()` agrees with `decide()`: a gap of exactly `maxGapSeconds()` extends a run and one second more starts a new one.
4. The endpoint: the JSON of D5 for a picked instance (integers stay integers; `from` and `to` are the range used); an empty list; `404` for an instance that is not picked, even with stored runs; `400` with its message; `401` without a session (not a redirect); `405` for `POST`; JSON for an unknown route under `/admin/api/` and for an uncaught exception, whatever the `Accept` header; `503` JSON while not set up; `Cache-Control: no-store` and `Content-Type: application/json` on every answer.
5. The existing `/admin` pages still redirect to the login page without a session and keep their HTML errors.
6. If D8 is approved: the header is on HTML, JSON and error responses.

## Changes to `docs/DESIGN.md`

- Section 9.1: reading runs back (overlap, order, no clipping, the index).
- Section 10.1: the drawing rule of D6 and its two consequences.
- Section 10.2: the `/admin/api/*` row in the surfaces table, its JSON errors and the `401`.
- Section 11.3: `X-Content-Type-Options: nosniff`, if D8 is approved.
- Section 15: step 11 links this plan.

## Verification

From `server/`:

```
vendor/bin/phpunit
```

From the repository root:

```
scripts/test-ubuntu-22.04.sh
```

### Manual check

In each terminal, from the repository root:

```
source scripts/dev-env.sh
```

From `server/`, start the app:

```
php -S localhost:8080 -t public
```

In a second terminal, from the repository root, with an enrolled development client, send heartbeats for a few minutes so runs exist:

```
client/bin/maguari-client run
```

Find the instance's ID and the stored runs:

```
sqlite3 server/var/dev.sqlite "SELECT instance_id, metric, value, start_at, end_at FROM monitoring_metric_runs ORDER BY instance_id, metric, start_at"
```

Then, signed in, open in the browser (with the instance's ID in place of 1). Firefox and Chrome show the JSON:

1. `http://localhost:8080/admin/api/instances/1/runs?metric=disk_total_bytes%3A%2F` shows one run covering the last minutes, with `from` and `to` 24 hours apart.
2. `http://localhost:8080/admin/api/instances/1/runs?metric=disk_used_bytes%3A%2F` shows one or more runs.
3. `http://localhost:8080/admin/api/instances/1/runs?metric=disk_free%3A%2F` is `400` with "Unknown metric kind" in its message.
4. `http://localhost:8080/admin/api/instances/999/runs?metric=disk_used_bytes%3A%2F` is `404 {"error": "not_found"}`.
5. Stop the client for more than 90 seconds and start it again: the `disk_total_bytes:/` response then has two runs with the same value and a gap of more than `max_gap_seconds` between them.
6. In a private window (not signed in), the first address is `401 {"error": "unauthorized"}`.

## Questions

1. **Address:** `/admin/api/instances/{id}/runs` in an inner group (D1), or `/admin/instances/{id}/runs` (alternative A)?
2. **Format:** runs as `[start_at, end_at, value]` arrays with `columns` (D5), or objects with named fields (larger but plainer)?
3. **Limits:** 31 days, 5,000 runs and truncation that keeps the newest runs, with no cursor (D5)?
4. **Drawing rule:** record D6 in design section 10.1 now, so the charts step implements an agreed rule?
5. **Header:** add `X-Content-Type-Options: nosniff` to every response (D8)?

## Status

Proposed, waiting for approval.
