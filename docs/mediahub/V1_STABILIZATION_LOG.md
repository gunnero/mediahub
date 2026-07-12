# MediaHub Web V1 Stabilization Log

## Purpose

This log tracks verified Web V1 defects and usability regressions found through real staging use. It is not a feature backlog.

Stabilization rules:

- reproduce before changing behavior
- add a regression test before fixing a confirmed bug
- never log private media titles, searches, notes, watch history, provider data, URLs, credentials, IP addresses, tokens, or request bodies
- keep Laravel authentication and staging `noindex` protection enabled
- do not add subscriptions, native playback, or broad visual changes

## Issue Statuses

- `confirmed`: reproduced or supported by live runtime evidence
- `fixed locally`: regression test and fix pass locally; not committed or deployed
- `resolved deployed`: fix is deployed and live behavior was verified
- `monitoring`: no deterministic safe repair is approved yet

## Issues

### V1-001 - Unauthenticated API requests returned 500

- **Date:** 2026-07-05, with one recurrence during deployment work on 2026-07-11
- **Screen:** Authentication and private API bootstrap
- **Expected behavior:** Unauthenticated private API requests return JSON `401` and the public application shell displays Laravel login.
- **Actual behavior:** Eight logged requests returned `500` because Laravel attempted to redirect to an undefined `login` route.
- **Severity:** Critical
- **Reproduction steps:**
  1. Use the pre-fix deployment.
  2. Request `/api/v1/me`, `/api/v1/dashboard`, or another authenticated API route without a session.
  3. Observe `500` and `RouteNotFoundException` for the missing login route.
- **Status:** Resolved deployed in `01b20c4` by using Laravel JSON authentication behavior for private API routes.

### V1-002 - Discovery could fail when SQLite cache was locked

- **Date:** 2026-07-11
- **Screen:** Discover
- **Expected behavior:** TMDB discovery returns results or a safe unavailable state without crashing MediaHub.
- **Actual behavior:** Two live discovery requests returned `500`. Laravel recorded `QueryException: database is locked` while updating the `cache` table.
- **Severity:** High
- **Reproduction steps:**
  1. Configure SQLite as the application database and database-backed Laravel cache.
  2. Trigger overlapping or rapid TMDB discovery requests that write cache entries.
  3. Observe a cache-table write collision and HTTP `500`.
- **Regression test:** `TmdbMetadataTest::test_tmdb_uses_dedicated_cache_store_instead_of_sqlite_default`
- **Fix:** TMDB now uses `TMDB_CACHE_STORE=file`, independent of the database-backed application cache.
- **Status:** Fixed locally; not committed or deployed.

### V1-003 - Metadata review alert counted every episode

- **Date:** 2026-07-11
- **Screen:** Alerts
- **Expected behavior:** The review alert counts only episodes with a recorded metadata failure pending manual review.
- **Actual behavior:** The alert showed all 7,291 episode rows because newly added review status defaults were counted without requiring a failure.
- **Severity:** High
- **Reproduction steps:**
  1. Deploy the metadata review status migration over an existing imported library.
  2. Load the dashboard.
  3. Observe an alert claiming all episodes need review.
- **Regression test:** `WebV1ApiTest` verifies pending rows require `metadata_failure_count > 0`.
- **Status:** Resolved deployed in `18ed9bb`; live count is 22.

### V1-004 - Invalid-number duplicate episode groups remain ambiguous

- **Date:** 2026-07-11
- **Screen:** Metadata maintenance; no normal-user failure
- **Expected behavior:** Canonical positive season and episode numbers are unique within a show.
- **Actual behavior:** Fifteen imported groups with invalid numbering contain 46 extra rows. Positive-number duplicate groups are zero.
- **Severity:** Low
- **Reproduction steps:**
  1. Inspect user 1 import relationships.
  2. Group episodes where season or episode number is not positive.
  3. Count duplicate groups and extra rows.
- **Status:** Monitoring. Automatic deletion or merging is intentionally prohibited because the rows are ambiguous.

### V1-005 - One imported show has a higher aggregate seen counter

- **Date:** 2026-07-11
- **Screen:** Statistics and show progress
- **Expected behavior:** Aggregate counters reconcile with canonical rows when the mapping is deterministic.
- **Actual behavior:** Show ID 55 retains an imported aggregate of 90 seen against 88 canonical episode/watch rows.
- **Severity:** Low
- **Reproduction steps:**
  1. Compare the show's imported aggregate counters with same-user canonical episode and watch rows.
  2. Observe the two-row difference.
- **Status:** Monitoring. The deterministic repair intentionally does not lower imported counters because that could destroy preserved history.

### V1-006 - Page-wide Home hero leaked into focused screens

- **Date:** 2026-07-12
- **Screen:** Discover, History, Alerts, Stats, Lists, and Settings
- **Expected behavior:** Focused screens open directly into their own controls and content. The entertainment hero and diary remain Home concerns; Shows may use its own latest-show hero.
- **Actual behavior:** The shared recent-movie hero rendered above unrelated screens, and Settings retained a second diary column.
- **Severity:** Medium
- **Reproduction steps:** Open each listed navigation destination in Web V1 and observe the repeated Home composition.
- **Regression tests:** `App.test.jsx` verifies page-specific hero rules and that Settings has no Entertainment Diary while Home keeps it.
- **Status:** Fixed locally; not committed or deployed.

### V1-007 - Manual rewatch overwrote the watched state

- **Date:** 2026-07-12
- **Screen:** Movie and episode detail, History, Stats
- **Expected behavior:** Every explicit rewatch creates a new dated event and preserves earlier dates.
- **Actual behavior:** Manual watch handling treated watched as one boolean-like row, preventing a second historical watch event.
- **Severity:** High
- **Reproduction steps:** Mark an already watched movie or episode as watched again, then inspect its detail history and Stats.
- **Regression tests:** `ManualLibraryUiApiTest` verifies append-only rows, chronological watch numbers, repeat-aware counts, and latest-manual-only removal.
- **Status:** Fixed locally; not committed or deployed.

### V1-008 - Release and alert surfaces lacked useful upcoming data

- **Date:** 2026-07-12
- **Screen:** Calendar and Alerts
- **Expected behavior:** Calendar uses followed-show episode dates and watchlist movie releases; Alerts distinguishes release, episode, and continue-watching reasons.
- **Actual behavior:** Calendar could remain empty despite usable metadata, and the Upcoming alert tab lacked category-specific generated entries.
- **Severity:** Medium
- **Reproduction steps:** Use a followed show with next-episode metadata or a watchlist movie with a future release, then open Calendar and Upcoming Alerts.
- **Regression tests:** `TmdbMetadataTest`, `WebV1ApiTest`, and `WebV1Surfaces.test.jsx` cover next-episode hints, watchlist releases, alert types, and empty states.
- **Status:** Fixed locally; existing shows may require a metadata refresh before new next-episode hints are available. Not committed or deployed.

### V1-009 - Profile identity fields and avatar controls were incomplete

- **Date:** 2026-07-12
- **Screen:** Profile and Privacy
- **Expected behavior:** Users can maintain full name, ISO country, and an explicitly publishable avatar without exposing upload metadata or another user's files.
- **Actual behavior:** The form lacked full name/country completeness and had no safe avatar upload/remove pipeline.
- **Severity:** Medium
- **Reproduction steps:** Open Edit Profile and inspect the available identity/avatar controls.
- **Regression tests:** `ProfilesAndFriendsTest` and `ProfileSurfaces.test.jsx` cover formats, size/MIME rejection, thumbnail generation, replacement/deletion, privacy, country selection, preview, and upload progress.
- **Status:** Fixed locally; not committed or deployed.

## Privacy-Safe Operational Monitoring

The stabilization monitor is intentionally narrow and file-backed.

Recorded event types:

- `api.slow`
- `api.5xx`
- `api.exception`
- `job.failed`

Allowed fields:

- route template, such as `api/v1/library/movies/{movie}`
- HTTP status
- request duration in milliseconds
- exception class only
- job class only

Never recorded:

- actual URL or route parameter values
- query strings or search text
- request or response bodies
- user IDs, emails, IP addresses, or headers
- media titles, notes, ratings, or watch history
- provider settings, stream locators, credentials, tokens, or API keys
- exception messages or stack traces in the monitoring channel
- failed-job payloads

Configuration:

```dotenv
MEDIAHUB_MONITORING_ENABLED=true
MEDIAHUB_SLOW_REQUEST_MS=1000
MEDIAHUB_MONITORING_RETENTION_DAYS=14
TMDB_CACHE_STORE=file
```

Monitoring output is written as daily JSON lines to ignored files under `backend/storage/logs/mediahub-monitoring-*.log`.

Weekly safe summary:

```bash
cd backend
php artisan mediahub:stabilization-summary --days=7
```

Monitoring is best-effort. Logging failures never change an API response or interrupt a user workflow.

## Weekly Stabilization Summary

### Week ending 2026-07-11

- **Release:** Web V1 plus deployed metadata-alert hotfix `18ed9bb`
- **Apache requests reviewed:** 4,005
- **HTTP status classes:** 1,745 `2xx`, 5 `3xx`, 2,245 `4xx`, 10 `5xx`
- **5xx breakdown:** eight historical missing-login-route failures; two SQLite cache-lock failures in discovery
- **Laravel errors reviewed:** 17 error entries; current actionable issue is V1-002
- **Laravel warnings reviewed:** 405, all classified as safe TMDB non-success responses from metadata matching
- **Failed queue jobs:** 0
- **Browser console on live login surface:** 0 warnings, 0 errors
- **Measured service baseline:** dashboard 152.8 ms; movies 61.8 ms; shows 175.0 ms; history 21.6 ms; calendar 2.3 ms; statistics 627.8 ms
- **Slow request threshold:** 1,000 ms
- **Security state:** public login shell remains available; private APIs return `401`; staging retains `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet`
- **Fixes this pass:** dedicated file-backed TMDB cache; privacy-safe API/job monitoring
- **Deployment state:** local changes only; no commit and no deployment

## Weekly Review Checklist

1. Run `php artisan mediahub:stabilization-summary --days=7`.
2. Review aggregate Apache status counts without printing request URLs or IP addresses.
3. Review Laravel error classes and safe failure codes without printing exception messages.
4. Run `php artisan queue:failed` and report counts only.
5. Check the live login surface for browser console errors.
6. Measure core API/service latency and compare it with the 1,000 ms threshold.
7. Add every confirmed issue to this log before changing code.
8. Add a failing regression test before implementing each bug fix.
9. Reconfirm private APIs return `401` and staging remains `noindex`.
10. Commit or deploy only a tested, verified fix.
