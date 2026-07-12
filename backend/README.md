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
- `GET /api/v1/alerts`
- `GET|PATCH /api/v1/notification-preferences`
- `GET /api/v1/calendar`
- `GET /api/v1/stats`
- `GET /api/v1/settings`
- `GET|POST /api/v1/lists`
- `GET|PATCH|DELETE /api/v1/lists/{list}`
- `POST /api/v1/lists/{list}/items`
- `DELETE /api/v1/lists/{list}/items/{item}`
- `PATCH /api/v1/lists/{list}/reorder`
- `GET /api/v1/exports/json`
- `GET /api/v1/exports/csv/{dataset}`
- `GET /api/v1/media-events`
- `GET /api/v1/media-events/recent`
- `GET /api/v1/library/movies`
- `GET /api/v1/library/shows`
- `GET /api/v1/library/history`
- `GET /api/v1/library/search`
- `GET /api/v1/library/movies/{movie}`
- `GET /api/v1/library/shows/{show}`
- `GET /api/v1/library/episodes/{episode}`
- `POST|DELETE /api/v1/library/movies/{movie}/watchlist`
- `POST|DELETE /api/v1/library/shows/{show}/watchlist`
- `POST|DELETE /api/v1/library/shows/{show}/seasons/{season}/watch`
- `POST /api/v1/library/movies/{movie}/watch`
- `POST /api/v1/library/episodes/{episode}/watch`
- `DELETE /api/v1/library/movies/{movie}/watch`
- `DELETE /api/v1/library/episodes/{episode}/watch`
- `POST /api/v1/library/movies/{movie}/rating`
- `POST /api/v1/library/shows/{show}/rating`
- `POST /api/v1/library/episodes/{episode}/rating`
- `DELETE /api/v1/library/movies/{movie}/rating`
- `DELETE /api/v1/library/shows/{show}/rating`
- `DELETE /api/v1/library/episodes/{episode}/rating`
- `POST /api/v1/library/movies/{movie}/notes`
- `POST /api/v1/library/shows/{show}/notes`
- `POST /api/v1/library/episodes/{episode}/notes`
- `PATCH /api/v1/library/notes/{note}`
- `DELETE /api/v1/library/notes/{note}`
- `GET /api/v1/discover/search`
- `GET /api/v1/discover/browse`
- `POST /api/v1/discover/movies/{tmdbId}/add`
- `POST /api/v1/discover/shows/{tmdbId}/add`
- `GET|PATCH /api/v1/profile`
- `GET /api/v1/profile/options`
- `POST|DELETE /api/v1/profile/avatar`
- `PATCH /api/v1/profile/privacy`
- `GET /api/v1/providers`
- `POST /api/v1/providers/test`
- `POST /api/v1/providers`
- `PATCH /api/v1/providers/{provider}`
- `POST /api/v1/providers/{provider}/refresh`
- `DELETE /api/v1/providers/{provider}`
- `GET /api/v1/player/sources`
- `POST /api/v1/player/sources`
- `PATCH /api/v1/player/sources/{source}`
- `DELETE /api/v1/player/sources/{source}`
- `GET /api/v1/player/items`
- `GET /api/v1/player/catalog`
- `POST /api/v1/player/sources/{source}/items`
- `PATCH /api/v1/player/items/{item}/favorite`
- `GET /api/v1/player/link-targets`
- `POST /api/v1/player/items/{item}/play`
- `POST /api/v1/player/items/{item}/link`
- `DELETE /api/v1/player/items/{item}/link`
- `PATCH /api/v1/player/sessions/{session}`

The API uses session-backed same-origin authentication. Public registration is intentionally absent; users are created through invites or admin management.

## Web V1 Feature Flags

The provider/player APIs and data model stay available for future native clients while normal Web V1 hides those surfaces by default:

```dotenv
MEDIAHUB_WEB_PLAYER_ENABLED=false
MEDIAHUB_WEB_PROVIDERS_ENABLED=false
MEDIAHUB_VERSION=1.0.0
```

`GET /api/v1/dashboard` returns these flags under `features`. They control web presentation only and must never delete or migrate provider data.

Web V1 calendar, alerts, statistics, lists, settings, and export services query only the authenticated user's rows. JSON/CSV exports include canonical media, history, ratings, notes, and lists but exclude playback sources, provider settings, locators, credentials, secrets, and tokens.

`GET /api/v1/dashboard` returns the dashboard-compatible JSON shape consumed by the React app. Detail endpoints return safe canonical item payloads with watch history, rating, private notes, provider link status, and show season/episode groups, but never raw stream URLs or provider settings.

Library browser endpoints are canonical and user-scoped:

- `GET /api/v1/library/movies`: paginated movies with `search`, `status`, `sort`, `page`, and `per_page` filters.
- `GET /api/v1/library/shows`: paginated shows with `search`, `status`, `sort`, `page`, and `per_page` filters.
- `GET /api/v1/library/history`: paginated movie/episode watch history with `type`, `search`, `date_from`, `date_to`, `page`, and `per_page` filters.
- `GET /api/v1/library/search`: grouped canonical search results for movies, shows, and episodes.

These endpoints do not search or return provider source items and must not expose provider URLs, stream URLs, playlist URLs, credentials, API keys, secrets, or raw provider settings.

`GET /api/v1/media-events` and `/recent` return only the authenticated user's sanitized media events. Supported filters are `event_type`, `source`, `subject_type`, `date_from`, and `date_to`.

## Canonical Media Contract

Provider items are temporary. Canonical media and user activity are permanent.

- Canonical media: `movies`, `shows`, `episodes`
- User activity: `movie_watches`, `episode_watches`, `ratings`, `notes`, `playback_sessions`, `media_events`
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

The frontend Player tab now uses these APIs for user-owned provider attach/manage, manual source-item creation, item search, manual link/unlink confirmation, HTML5/HLS playback, progress saving, and completion tracking. HLS support uses the frontend `hls.js` fallback where native browser playback is unavailable; no stream catalog or stream provider is bundled with MediaHub.

The frontend Movies, Shows, History, global search, and show season browser use the canonical library endpoints. Provider source-item search remains isolated inside the Player tab.

## Discovery And Catalog Import

Discovery is separate from canonical library search. `GET /api/v1/discover/search` queries optional TMDB metadata through the backend, while `GET /api/v1/discover/browse` returns safe Trending, Popular, Now Playing, Upcoming, and Top Rated shelves. The add routes create/reuse canonical same-user records and can set watchlist/watched state. Duplicate TMDB IDs do not create duplicate media. When TMDB is disabled or unavailable, discovery returns a safe empty status and the existing library remains fully usable.

## Repeat Watches

Movie and episode watch rows are events, not booleans. A manual watch request always appends a new same-user watch row, including when the item was already watched. Manual unwatch removes only the most recent manual row. Player completion continues to use its existing session-aware deduplication rules, and bulk season completion skips previously watched episodes. Detail/history/statistics payloads expose repeat counts without collapsing or overwriting earlier dates.

## Profile Avatars

`POST /api/v1/profile/avatar` accepts one authenticated multipart `avatar` upload. Validation limits files to 5 MB and decoded JPEG, PNG, or WebP images. `UserAvatarService` rejects oversized pixel dimensions, center-crops and re-encodes the source to strip EXIF/embedded metadata, and creates 512, 128, 64, and 32 pixel JPEG variants with random names on the public disk. Replacement and deletion are restricted to the current user's avatar prefix. `DELETE /api/v1/profile/avatar` removes the variants and restores the generated default avatar behavior.

The public profile allowlist includes an avatar only when the viewer may see the profile and `show_avatar` is enabled. Email, upload paths, storage metadata, and avatar variant maps are never returned by public profile endpoints.

Provider configuration is handled by `ProviderService`; imported catalog refresh is handled by `ProviderCatalogService` and `ProviderConnectionService`. Supported first-pass adapters are Xtream-compatible API, M3U, XMLTV, and manual source. Type-specific connection details are required at creation, stored in encrypted `playback_sources.settings`, and represented in API summaries only as configuration booleans.

Provider requests use configured timeouts, retries, item limits, and `MEDIAHUB_PROVIDER_MAX_RESPONSE_BYTES` so oversized JSON, M3U, or XMLTV responses are rejected before parsing.

Catalog refresh:

```bash
php artisan mediahub:refresh-provider {provider_id}
```

The refresh command/API creates and updates same-user source items, marks removed imported items unavailable, retains links, and emits summary-only audit/media events. `playback_source_items.stream_url` and `poster_url` are encrypted and hidden. Normal catalog responses expose only title/category/year/duration, safe EPG program metadata, link/match state, progress, favorite, and playability/artwork-availability booleans.

Only `POST /api/v1/player/items/{item}/play` returns `playbackUrl`, after validating the full same-user source/item/link ownership graph. Live sessions remain playback-session history and never create canonical movie/episode watches.

See `../docs/mediahub/DISCOVERY_AND_PROVIDER_PLAYER_SPEC.md`.

## Kalveri AI Media Matcher

Kalveri AI matching is optional and disabled by default:

```dotenv
KALVERI_AI_ENABLED=false
KALVERI_AI_BASE_URL=
KALVERI_AI_API_KEY=
KALVERI_AI_TIMEOUT=20
```

`KalveriAIClient` sends structured JSON only and logs safe status summaries without API keys. `KalveriAIMediaMatcherService` builds sanitized payloads, runs local matching first, calls Kalveri AI only when useful, stores suggestions under model metadata, records safe `ai.match.*` media events, and never auto-links or auto-applies a suggestion.

Provider-item API:

- `POST /api/v1/player/items/{item}/ai-match`
- `POST /api/v1/player/items/{item}/ai-match/reject`

Metadata review commands:

- `php artisan mediahub:ai-match-review-episode {episode_id}`
- `php artisan mediahub:apply-review-match {episode_id} --season=1 --episode=2`

Allowed Kalveri AI payload fields are normalized/original titles, media type guess, year, season/episode numbers, same-user candidate IDs/titles, and public TMDB IDs. Forbidden fields include stream URLs, playlist URLs, provider settings, provider credentials, private notes, API keys, tokens, and watch history.

## TMDB Metadata

TMDB metadata enrichment is optional and disabled by default. Configure only private runtime `.env` values:

```dotenv
TMDB_ENABLED=false
TMDB_API_KEY=
TMDB_TIMEOUT=20
TMDB_CACHE_TTL=86400
```

Do not commit real TMDB keys. The client caches safe public TMDB responses, uses timeouts, returns safe failures, and never logs API keys. When disabled or failing, enrichment commands skip/fail gracefully and the app continues using local canonical data.

Commands:

```bash
php artisan mediahub:enrich-movie {movie_id}
php artisan mediahub:enrich-show {show_id}
php artisan mediahub:enrich-user {user_id}
php artisan mediahub:metadata-status {user_id}
```

Output is limited to counts: searched, matched, enriched, skipped, and failed. Metadata fields are additive on `movies`, `shows`, and `episodes`: TMDB/IMDb/TVDB IDs, original title, overview, poster/backdrop paths, release/air dates, genres, runtime, status, vote average, metadata JSON, and `metadata_refreshed_at`.

Dashboard and detail payloads may include public metadata and TMDB image URLs. They must never expose stream URLs, playlist URLs, provider credentials, API keys, or private provider settings.

## Ratings And Notes

Users can rate movies, shows, and episodes from 1 to 10. Users can add private notes to movies, shows, and episodes. Ratings and notes are always user-scoped and survive provider changes or provider deletion.

Manual watch toggles exist for movies and episodes. They create/update one `source = manual` watch row per user-owned item to avoid duplicates. The unwatch endpoints remove only manual rows, so imported archive history and provider auto-tracked history remain permanent.

Tables:

- `ratings`
- `notes`

## Media Events

`MediaEventService` records meaningful user-scoped activity into `media_events` for timelines, future statistics, Kalveri AI memory, recommendations, notifications, and achievements.

Current stable event types include imports, manual watched/unwatched events, ratings, notes, provider/source lifecycle events, provider item link/unlink events, playback started/completed, metadata enrichment, AI match requested/suggested/confirmed/rejected, backup creation, and restore completion.

Event metadata is sanitized through the shared metadata sanitizer before storage. Forbidden keys include stream URLs, playback URLs, provider URLs, playlist URLs, passwords, API keys, tokens, secrets, and credentials. Event recording catches failures and logs only a safe summary so user flows do not crash if an event cannot be written.

The dashboard payload includes a `timeline` object with recent events plus today and this-week counts. The frontend renders it as an "Entertainment diary" with user-readable source labels instead of raw event/source names.

## Backup And Restore

Provider-safe private backup commands:

```bash
php artisan mediahub:backup-user {user_id}
php artisan mediahub:restore-user {user_id} storage/app/private/mediahub-backups/user-{user_id}-YYYYMMDD-HHMMSS.json
```

Backups include canonical library data, public metadata, watches, ratings, notes, safe media links, and safe progress. Backups exclude stream URLs, playlist URLs, provider credentials, API keys, provider settings, and secrets by default. Restore paths are accepted only from `storage/app/private/mediahub-backups`.

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

## Repair Imported Relationships

Use dry-run first when imported show counters and canonical episode relationships disagree:

```bash
php artisan mediahub:repair-import-relationships {user_id} --dry-run
php artisan mediahub:repair-import-relationships {user_id} --apply
```

The repair is same-user, deterministic, idempotent, and summary-only. Apply mode creates a private backup before writing. It may connect a canonical episode and watch to one provable same-user show or raise counters to canonical row counts. It never deletes history or annotations and never creates fictional episodes from aggregate totals.

See `../docs/mediahub/IMPORT_RELATIONSHIP_REPAIR.md`.

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
- Media Events
- Audit Logs

Only active `owner` and `admin` users can access the Filament panel. Imported media resources are inspection-first/read-only to keep user-owned library data explicit and safe.

## Tests

```bash
php artisan test
```

The feature tests cover status readiness, invite acceptance, login/logout, `/me`, unauthenticated private API access, empty and imported dashboard payloads, alert read persistence, analytics events, audit logs, media events, import validation, canonical library browser/search/history APIs, player/provider ownership, manual tracking without a provider, provider auto-tracking, provider deletion preserving watch history/ratings/notes/events, backup/restore, dashboard URL safety, TMDB disabled/failure/enrichment behavior, manual library detail/rating/note/watch APIs, and cross-user isolation.

## Deployment Checklist

Do not deploy private files. The staging login page is public, private routes use Laravel authentication, and Apache must retain the `noindex` header without a `WWW-Authenticate` challenge. After code is reviewed on the server, configure `.env`, run migrations with `php artisan migrate --force`, run `php artisan filament:assets` if Composer did not publish Filament assets, build frontend assets from the repo root, then sync React `dist/index.html`, `dist/assets/*`, and only the allowlisted browser identity files into `backend/public`. Preserve Laravel `index.php`, `.htaccess`, `robots.txt`, `favicon.ico`, and Laravel/Filament/Livewire assets. Smoke test `/api/v1/status`, unauthenticated `/api/v1/me`, login, `/api/v1/dashboard`, alert read actions, and `/admin`.

The future `mediahub.razbudise.mk` cutover is documentation-only until separately approved. See `../docs/infrastructure/MEDIAHUB_DOMAIN_MIGRATION.md`; do not redirect or remove `ccc.razbudise.mk` during a normal application deploy.

## Staging Deployment 2026-07-05

`web01` serves `https://ccc.razbudise.mk` from `/home/razbudise/ccc.razbudise.mk/app/backend/public`. The login page is public, Laravel protects private routes, and Apache retains staging `noindex` headers without Basic Auth.

Runtime notes:

- backend `.env` and SQLite database are server-private and ignored
- app checkout: `/home/razbudise/ccc.razbudise.mk/app`
- current deployed commit before Sprint 006: `d436f3aeb9362414e0d335705f6176971ace9dee`
- deployment backup: `/home/razbudise/ccc.razbudise.mk/backups/20260705004417`
- private import file: `storage/app/imports/tvtime.sqlite`
- safe backup directory: `storage/app/private/mediahub-backups`

Server commands used:

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate --force --no-interaction
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm ci --cache /home/razbudise/.npm-cache --prefer-offline=false
npm run build -- --emptyOutDir
```

The server required `php8.4-sqlite3` for the SQLite staging database. Installing it upgraded PHP 8.4 packages from `8.4.22` to `8.4.23` and PHP-FPM was restarted.

Imported user `1` counts: 92 shows, 7,291 episodes, 7,292 episode watches, 533 movies, 512 movie watches, and 8 alerts. `mediahub:backup-user 1` produced a provider-safe backup under ignored private storage; field-key verification found no stream URL, playlist URL, provider credential, API key, secret, or password keys.

Live smoke passed for the public login page, Laravel login/logout, unauthenticated private-route `401`, `/api/v1/status`, `/api/v1/me`, `/api/v1/dashboard`, Player empty state, manual detail/rating/note/watch APIs, alert read persistence, `/admin`, sensitive dashboard scan, and authenticated browser console/asset checks.
