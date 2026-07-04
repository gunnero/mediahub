# MediaHub Laravel Backend Design

## Purpose

Build a proper product backend for MediaHub. The current React app began as a TV Time archive dashboard, but the product is now a provider-independent personal media operating system where each user starts with an empty personal media library and later imports or adds their own data.

The backend must be safe for private viewing histories. No raw GDPR exports, access tokens, IP addresses, device identifiers, passwords, or generated private database files are committed to Git or served directly from the web root.

## Product Shape

The product is invite-only for now. There is no public registration screen. Admins create invites, invited users accept them, set a password, and receive their own isolated account. New users do not see the existing `gunner` archive and do not inherit any seed media data.

The first backend release should establish the product foundation:

- Multi-user identity and roles.
- Invite-only onboarding.
- Admin user management.
- Admin analytics dashboard.
- Per-user empty dashboard API.
- Alert read/unread persistence.
- Audit logging for sensitive actions.
- Background-job and scheduler foundation.
- Private storage conventions for future imports.
- Health/status endpoints for deployment checks.

The first release does not need live episode-release integrations, email/push notifications, payment, social features, recommendations, AI UI, mobile apps, or public discovery. Those become later modules.

## Architecture

Use Laravel as a modular monolith under `backend/` in the existing repository. Keep the existing React dashboard as the customer-facing SPA and move it toward API-backed data.

The deployable shape is:

- `backend/`: Laravel application, migrations, models, API routes, admin panel, jobs, tests.
- Existing React frontend: remains the dashboard UI, then switches from `/data/dashboard-data.json` to `/api/v1/dashboard`.
- Same-origin production hosting on `ccc.razbudise.mk`.
- Apache serves the React build and routes `/api` and admin paths to Laravel.
- Private upload/import storage stays outside public document roots.

Laravel should provide:

- Cookie/session auth for same-origin SPA access.
- CSRF protection for browser writes.
- Policies and gates for user/admin access.
- Queue jobs for imports, analytics rollups, and future metadata refreshes.
- Scheduler for daily analytics aggregation and maintenance.
- Database migrations as the only schema source of truth.

## Roles And Permissions

Roles:

- `owner`: full system access, can manage admins and all users.
- `admin`: can manage invites, inspect user accounts, view analytics, and review import/job status.
- `member`: can manage only their own library, alerts, settings, and future imports.

Permission rules:

- Members can never read another user's library, alerts, imports, or dashboard payload.
- Admins can see aggregate analytics and user/account metadata.
- Admin access to user media details should be explicit, audited, and reserved for support workflows.
- Owner/admin actions that affect identity, invites, imports, or account status create audit-log entries.

## Core Modules

### Identity

Tables:

- `users`: Laravel users plus `role`, `status`, `last_login_at`, and basic profile fields.
- `invites`: hashed token, email, role, inviter user id, expiry timestamp, accepted timestamp.
- `sessions`: Laravel session storage if database sessions are enabled.

Flows:

- Admin creates an invite.
- System generates a one-time token and stores only a hash.
- Invited user accepts, sets password, and receives a fresh member account.
- Public registration remains disabled.
- Login/logout/me endpoints support the SPA.

### Library

Tables are scoped by `user_id` from day one:

- `shows`: canonical per-user show records.
- `episodes`: episode metadata imported or added for a user.
- `episode_watches`: user watch history rows.
- `movies`: per-user movie records.
- `movie_watches`: user movie watch history.
- `ratings`: private user ratings for movies, shows, and episodes.
- `notes`: private user notes for movies, shows, and episodes.
- `library_items`: deferred unifying layer for cross-media browsing after the first release.

The first backend release can return an empty but complete dashboard payload for new users. Existing static/importer logic can later be ported into import jobs that populate these tables per user.

### Player And User-Owned Providers

The Player is available only for users who attach their own provider/source. Users without a provider still use the dashboard and manual library normally as a watch-history tracker.

Rules:

- Provider sources are private per user.
- A provider added by User A must never be visible or playable by User B.
- Provider source items, media links, sessions, and progress must validate the same-user ownership graph, not only their own row-level `user_id`.
- If a user has no provider, Player playback features are hidden or disabled and the UI shows: "Attach your own source to enable playback and automatic tracking."
- If a user has a provider, the Player can show that user's own source items, linked/unlinked items, and continue-watching rows.
- Provider items can link only to the same user's canonical movies, shows, or episodes.
- Admins may inspect provider metadata/status, but must not see raw provider URLs or stream URLs.
- Do not create a global/shared stream catalog.
- Do not cache or expose provider content globally.
- Disabling or deleting a provider must not delete canonical watch history.

Tables are scoped by `user_id`:

- `playback_sources`: user-owned provider/source record, status, metadata, encrypted settings.
- `playback_source_items`: user-owned source items, encrypted stream URL, stream hash, source metadata.
- `media_links`: user-owned link from source item to that same user's canonical movie/show/episode.
- `playback_sessions`: user-owned playback session rows.
- `playback_progress`: user-owned continue-watching/progress rows.

Provider deletion may cascade provider rows, source items, links, sessions, and progress. It must not cascade to `movies`, `shows`, `episodes`, `movie_watches`, or `episode_watches`.

Canonical media and permanent activity outlive providers. Provider deletion must also preserve ratings and notes.

### Canonical Media Contract

Provider items are temporary. Canonical media and watch history are permanent.

Canonical media:

- `movies`
- `shows`
- `episodes`

User activity:

- `movie_watches`
- `episode_watches`
- `ratings`
- `notes`
- `playback_sessions`

Provider layer:

- `playback_sources`
- `playback_source_items`
- `media_links`
- `playback_progress`

Unlinked provider playback may save source-only progress. Linked provider playback may create/update canonical watch history. Dashboard payloads must not expose stream URLs, playlist URLs, provider credentials, API keys, provider secrets, or raw provider settings.

The canonical contract is documented in `docs/mediahub/CANONICAL_MEDIA_CONTRACT.md`.

### Alerts

Tables:

- `alerts`: user-scoped site alerts with category, title, subtitle, due text, payload JSON, unread flag, and timestamps.

Behavior:

- Dashboard API returns only the authenticated user's alerts.
- Opening an alert or using "mark all read" persists to the backend.
- Future metadata jobs create alerts for new episodes, upcoming releases, import status, and watchlist changes.

### Admin

Use Filament for the Laravel admin panel.

Admin resources:

- Users: view, disable, promote/demote within permission limits.
- Invites: create, expire, resend, inspect accepted state.
- Imports: view job status and failures once import uploads exist.
- Alerts: inspect counts and recent system-generated alerts.
- Audit logs: filter by actor, action, subject, date.
- Analytics: user growth, active users, invite conversion, import activity, library sizes, alert engagement.

### Analytics

Capture product analytics server-side without exposing private media details unnecessarily.

Tables:

- `analytics_events`: actor user id, event name, coarse metadata JSON, request context, timestamp.
- `analytics_daily_rollups`: date, metric name, dimensions JSON, integer/decimal values.

Initial events:

- `invite.created`
- `invite.accepted`
- `user.login`
- `dashboard.viewed`
- `alert.read`
- `alert.read_all`
- `admin.user.updated`

Initial admin metrics:

- Total users.
- Active users in the last 1, 7, and 30 days.
- Pending invites.
- Accepted invites.
- Dashboard views.
- Alerts read.
- Per-user library size once imports exist.

Analytics must avoid storing raw tokens, passwords, IP addresses in display output, device identifiers, or full GDPR row payloads.

### Audit Log

Tables:

- `audit_logs`: actor id, action, subject type, subject id, target user id, safe metadata JSON, timestamp.

Audit entries are mandatory for:

- Invite creation, expiration, and acceptance.
- Role changes.
- User disable/enable.
- Import start, success, failure, deletion.
- Admin support access to a user's media data.

### Imports

Import support should be designed now but can be implemented after the identity/admin foundation.

Rules:

- Uploads are stored on a private Laravel disk outside public web roots.
- Jobs parse imports asynchronously.
- Every imported row is assigned to the importing user's `user_id`.
- Sensitive source files can be deleted after successful import unless the user chooses to retain them.
- Failed imports expose safe error messages and never print secrets into logs or chat.

The existing Python importer can be used as a reference. The long-term direction is either a Laravel-native importer or a controlled worker wrapper around the Python importer that writes into the Laravel schema.

## API Surface

Use `/api/v1` for SPA APIs.

Authentication:

- `GET /api/v1/me`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `POST /api/v1/invites/accept`

Dashboard:

- `GET /api/v1/dashboard`

Manual library:

- `POST /api/v1/library/movies/{movie}/watch`
- `POST /api/v1/library/movies/{movie}/rating`
- `POST /api/v1/library/shows/{show}/rating`
- `POST /api/v1/library/episodes/{episode}/rating`
- `POST /api/v1/library/movies/{movie}/notes`
- `POST /api/v1/library/shows/{show}/notes`
- `POST /api/v1/library/episodes/{episode}/notes`

Player:

- `GET /api/v1/player/sources`
- `DELETE /api/v1/player/sources/{source}`
- `POST /api/v1/player/items/{item}/play`
- `POST /api/v1/player/items/{item}/link`
- `PATCH /api/v1/player/sessions/{session}`

Alerts:

- `POST /api/v1/alerts/{alert}/read`
- `POST /api/v1/alerts/read-all`

Health:

- `GET /api/v1/status`

Support commands:

- `php artisan tvtime:import-user {user_id} {path_to_sqlite_or_json}`
- `php artisan mediahub:backup-user {user_id}`
- `php artisan mediahub:restore-user {user_id} {backup_file}`

Admin operations should primarily live in the admin panel. JSON admin APIs can be added only when the frontend needs them.

## Frontend Integration

The React app should switch from:

- `GET /data/dashboard-data.json`

to:

- `GET /api/v1/dashboard`

The first authenticated empty-user payload should preserve the existing dashboard shape:

- `profile`
- `source`
- `stats`
- `hero`
- `alerts`
- `recentlyWatched`
- `followedNewEpisodes`
- `moviesToCheckOut`
- `topShows`
- `activity`
- `player`

For a new user, lists are empty, stats are zero, and the UI shows onboarding-oriented empty states.

Dashboard stats may add:

- `manualWatchesCount`
- `autoTrackedWatchesCount`
- `linkedProviderItemsCount`
- `unlinkedProviderItemsCount`
- `unsyncedSourceOnlyProgressCount`
- `ratingsCount`
- `notesCount`

Player UI behavior:

- Dashboard is always available.
- Manual Library is always available.
- Player without provider shows: "Attach your own source to enable playback and automatic tracking."
- Player with provider shows the user's own source items, linked/unlinked items, and continue watching.

## Deployment

Production target remains `ccc.razbudise.mk` on `web01`.

Required production pieces:

- Laravel `.env` stored only on server.
- Database credentials stored only in `.env`.
- Private storage under the Laravel app storage path, outside `public`.
- Apache routes static React assets and forwards Laravel public/admin/API routes correctly.
- Queue worker managed by systemd or supervisor.
- Scheduler cron or systemd timer runs Laravel scheduler.
- HTTPS remains required.
- Basic Auth may stay during early private staging, even after app login is added.

Backups must include:

- Laravel database.
- Private import storage if retained.
- `.env` handled separately as a secret, not committed.

MediaHub user backups must exclude raw stream URLs, playlist URLs, provider credentials, API keys, provider settings, secrets, and raw GDPR files by default.

## Testing

Use Laravel feature and unit tests for:

- Invite-only registration.
- Login/logout/me.
- Role permissions.
- User isolation for dashboard, alerts, imports, and library data.
- User isolation for playback sources, source items, media links, sessions, and progress.
- Empty dashboard payload for fresh users.
- Alert read and read-all persistence.
- Provider users cannot access another user's provider, item, links, or sessions.
- Users without providers can still manually track history.
- Deleting a provider preserves canonical watch history.
- Ratings and notes are private, same-user only, and survive provider deletion.
- Unlinked provider playback saves source-only progress without creating canonical watches.
- Linked provider playback creates canonical watches without duplicate rows for repeated completion updates.
- Dashboard payloads never expose stream/provider URLs.
- Backup files exclude stream/provider URLs and provider secrets.
- Restore validates private backup paths and preserves user isolation.
- Admin invite creation.
- Analytics event capture.
- Audit log creation.
- `/api/v1/status` health response.

Frontend tests should cover:

- Fetching dashboard from `/api/v1/dashboard`.
- Empty states for new users.
- Persisted alert read behavior through API calls.
- Auth failure state.

## Implementation Phases

Phase 1: Laravel foundation

- Scaffold Laravel in `backend/`.
- Add users, roles, invites, sessions, status endpoint.
- Add admin panel foundation.
- Add dashboard API returning per-user empty payload.
- Add alerts API with persistence.
- Add analytics and audit infrastructure.
- Add tests for identity, authorization, dashboard, alerts, analytics, and audit.

Phase 2: Frontend API migration

- Replace static JSON fetch with authenticated API fetch.
- Add login and invite acceptance screens.
- Add empty dashboard states.
- Add API-backed alert read actions.

Phase 3: Import pipeline

- Add private upload flow.
- Add import job model and queue worker.
- Port or wrap the existing importer into user-scoped library tables.
- Add import progress and admin import monitoring.

Phase 4: Metadata and live alerts

- Add metadata providers for shows/movies.
- Add scheduled metadata refresh.
- Generate alerts for new episodes and movie/watchlist changes.
- Keep notification delivery site-only until email/push is explicitly added.

## Acceptance Criteria For Phase 1

- A fresh invited member can log in and see an empty personal dashboard via `/api/v1/dashboard`.
- No public registration path exists.
- Admin can create an invite and see user/invite analytics.
- Member API requests cannot access another user's records.
- Alert read state persists in the database.
- Admin-sensitive actions create audit logs.
- Analytics events are captured for login, dashboard view, and alert reads.
- Health endpoint returns application, database, and queue readiness.
- Raw GDPR exports and generated private databases remain ignored and outside public web roots.
- Provider/player data is user-owned only; no global stream catalog exists.
