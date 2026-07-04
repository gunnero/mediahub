# MediaHub Backend

Laravel backend for MediaHub, a provider-independent personal media operating system.

## Stack

- Laravel `13.x`
- Filament `5.x`
- SQLite for local development by default
- PHPUnit via `php artisan test`
- Session/cookie auth for the same-origin React SPA

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Do not commit `.env`, local SQLite files, database dumps, GDPR exports, uploaded imports, generated private JSON, or private cache files.

## API

Current routes:

- `GET /api/v1/status`
- `GET /api/v1/me`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `POST /api/v1/invites/accept`
- `GET /api/v1/dashboard`
- `POST /api/v1/alerts/{alert}/read`
- `POST /api/v1/alerts/read-all`
- `POST /api/v1/library/movies/{movie}/watch`
- `POST /api/v1/library/movies/{movie}/rating`
- `POST /api/v1/library/shows/{show}/rating`
- `POST /api/v1/library/episodes/{episode}/rating`
- `POST /api/v1/library/movies/{movie}/notes`
- `POST /api/v1/library/shows/{show}/notes`
- `POST /api/v1/library/episodes/{episode}/notes`
- `GET /api/v1/player/sources`
- `DELETE /api/v1/player/sources/{source}`
- `POST /api/v1/player/items/{item}/play`
- `POST /api/v1/player/items/{item}/link`
- `PATCH /api/v1/player/sessions/{session}`

The API uses session-backed same-origin authentication. Public registration is intentionally absent; users are created through invites or admin management.

`GET /api/v1/dashboard` returns the dashboard-compatible JSON shape consumed by the React app.

## Canonical Media Contract

Provider items are temporary. Canonical media and user activity are permanent.

- Canonical media: `movies`, `shows`, `episodes`
- User activity: `movie_watches`, `episode_watches`, `ratings`, `notes`, `playback_sessions`
- Provider layer: `playback_sources`, `playback_source_items`, `media_links`, `playback_progress`

All of these tables are scoped by `user_id`. Provider deletion cascades provider rows only and must not delete canonical media, watch history, ratings, or notes.

See `../docs/mediahub/CANONICAL_MEDIA_CONTRACT.md`.

## Player And Providers

Player playback is enabled only for users who attach their own provider/source. Users without a provider keep the dashboard and manual library features and can still manually track watch history.

All player tables are scoped by `user_id`:

- `playback_sources`
- `playback_source_items`
- `media_links`
- `playback_sessions`
- `playback_progress`

Provider settings and item stream URLs are encrypted/hidden. API list/dashboard payloads never include stream URLs; the play endpoint returns a playback URL only to the owner of that specific source item. Admin resources expose source metadata/status and item hashes, not raw URLs. Do not add a global/shared stream catalog.

The player service validates the whole ownership graph for source items, media links, playback sessions, and progress. A row with the current `user_id` is not enough if it points at another user's provider source or canonical media.

## Ratings And Notes

Users can rate movies, shows, and episodes from 1 to 10. Users can add private notes to movies, shows, and episodes. Ratings and notes are always user-scoped and survive provider changes or provider deletion.

Tables:

- `ratings`
- `notes`

## Backup And Restore

Provider-safe private backup commands:

```bash
php artisan mediahub:backup-user {user_id}
php artisan mediahub:restore-user {user_id} storage/app/private/mediahub-backups/user-{user_id}-YYYYMMDD-HHMMSS.json
```

Backups include canonical library data, watches, ratings, notes, safe media links, and safe progress. Backups exclude stream URLs, playlist URLs, provider credentials, API keys, provider settings, and secrets by default. Restore paths are accepted only from `storage/app/private/mediahub-backups`.

## Import Existing Data

Import an ignored private SQLite or generated JSON source for one existing user:

```bash
php artisan tvtime:import-user {user_id} ../var/private/tvtime.sqlite
```

Safety behavior:

- user must exist
- source file must exist
- source must be under `../var/private`, `../public/data`, `storage/app/private`, or `storage/app/imports`
- raw GDPR/token/device/IP data is never printed
- command output is limited to imported counts
- existing media rows for that one user are replaced; other users are untouched

## Admin

Filament is installed at `/admin`.

Current resources:

- Users
- Invites
- Alerts
- Shows
- Movies
- Episode Watches
- Movie Watches
- Playback Sources
- Playback Source Items
- Analytics Events
- Audit Logs

Only active `owner` and `admin` users can access the Filament panel. Imported media resources are inspection-first/read-only to keep user-owned library data explicit and safe.

## Tests

```bash
php artisan test
```

The feature tests cover status readiness, invite acceptance, login/logout, `/me`, unauthenticated private API access, empty and imported dashboard payloads, alert read persistence, analytics events, audit logs, import validation, player/provider ownership, manual tracking without a provider, provider auto-tracking, provider deletion preserving watch history/ratings/notes, backup/restore, dashboard URL safety, and cross-user isolation.

## Deployment Checklist

Do not deploy private files. Keep Apache Basic Auth enabled on staging. After code is reviewed on the server, configure `.env`, run migrations with `php artisan migrate --force`, run `php artisan filament:assets` if Composer did not publish Filament assets, build frontend assets from the repo root, and smoke test `/api/v1/status`, login, `/api/v1/dashboard`, alert read actions, and `/admin`.
