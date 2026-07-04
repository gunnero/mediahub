# MediaHub AI Handover

Generated: 2026-07-05
Repository: `/Users/aleksandardimovski/Sites/tvtime/dashboard`
Remote: `https://github.com/gunnero/Tvtime.git`
Staging: `https://ccc.razbudise.mk` behind Apache Basic Auth

## 1. Project Overview

Project name: MediaHub.

Purpose: provider-independent personal media operating system for movies and TV shows. The TV Time archive/importer is one data source, not the product identity.

Current version: frontend `package.json` is `0.0.0`; UI footer says `v1.0.0`; backend includes Laravel foundation, authenticated dashboard, user-owned provider/player layer, and Canonical Media Engine sprint 001. No release tag yet.

Completed:

- React/Vite Cinema Command Center dashboard.
- Python GDPR importer that creates ignored SQLite and dashboard JSON.
- Laravel backend under `backend/`.
- Session/cookie login/logout, invite acceptance, `/api/v1/me`, `/api/v1/dashboard`.
- Per-user tables for shows, episodes, episode watches, movies, movie watches, alerts.
- Import command `php artisan tvtime:import-user {user_id} {path_to_sqlite_or_json}`.
- Dashboard payload service that returns the JSON shape the React app expects.
- React login screen, API loading, logout, loading/error/empty/session-expired states.
- Persistent alert read/read-all API actions.
- User-owned provider/player architecture with private playback sources and no global stream catalog.
- Canonical media contract documented in `docs/mediahub/CANONICAL_MEDIA_CONTRACT.md`.
- User-scoped ratings and private notes for movies, shows, and episodes.
- Provider-independent backup/restore commands: `mediahub:backup-user` and `mediahub:restore-user`.
- Dashboard additive stats for manual watches, auto-tracked watches, provider link state, ratings, notes, and source-only progress.
- Feature tests for canonical watch invariants, ratings/notes, safe backup/restore, provider URL safety, and provider deletion preserving permanent history.
- Filament admin panel at `/admin`.
- Filament resources for Users, Invites, Alerts, Shows, Movies, Episode Watches, Movie Watches, Playback Sources, Playback Source Items, Analytics Events, Audit Logs.
- Feature tests for auth, invite-only flow, dashboard, import, analytics, audit, alert persistence, provider ownership, manual tracking, provider deletion behavior, and cross-user isolation.

Still planned:

- Production deployment wiring for Laravel routes on `ccc.razbudise.mk`.
- Create the first owner/admin production user and import the real private SQLite for that user.
- User-facing import upload flow.
- User-facing provider attach/manage flow.
- User-facing ratings/notes UI beyond API/dashboard counts.
- Background jobs/scheduler for future alert checks.
- TMDB/TVMaze or similar metadata integration later.
- Richer analytics dashboard and admin metrics.

## 2. Tech Stack

Backend: Laravel `13.x`, PHP `8.5`, Filament `5.6`, PHPUnit.
Frontend: React `19.2`, Vite `6.4`, Phosphor icons, plain CSS.
Database: SQLite locally; Laravel migrations are source of truth. Production DB can be SQLite or MySQL/MariaDB, but not decided here.
APIs: Laravel `/api/v1`; no TMDB/TVMaze yet.
Authentication: Laravel session/cookie auth, invite-only registration, roles `owner`, `admin`, `member`, statuses `active`, `disabled`.
Queue system: Laravel default jobs tables exist; no queued product jobs yet.
Testing: `php artisan test`, `npm test -- --run`, `python3 -m unittest discover -s tests -v`, `npm run build`.

Important packages:

- Backend Composer: `laravel/framework`, `filament/filament`, `laravel/tinker`, `phpunit/phpunit`, `laravel/pint`.
- Frontend npm: `react`, `react-dom`, `vite`, `@vitejs/plugin-react`, `@phosphor-icons/react`, `vitest`, `jsdom`, `playwright`.

## 3. Folder Structure

Source tree, excluding heavy/generated/private folders such as `.git`, `node_modules`, `backend/vendor`, `dist`, `var`, `public/data`, `public/assets/cache`, `backend/storage`, and backend cache files:

```text
.
├── AGENTS.md
├── README.md
├── backend/
│   ├── README.md
│   ├── app/
│   │   ├── Console/Commands/
│   │   ├── Enums/
│   │   ├── Filament/Resources/
│   │   ├── Http/Controllers/Api/V1/
│   │   ├── Models/
│   │   ├── Providers/
│   │   └── Services/
│   ├── bootstrap/app.php
│   ├── config/
│   ├── database/
│   │   ├── factories/
│   │   ├── migrations/
│   │   └── seeders/
│   ├── public/
│   ├── routes/api.php
│   ├── routes/web.php
│   └── tests/
├── docs/
│   ├── AI_HANDOVER.md
│   ├── mediahub/CANONICAL_MEDIA_CONTRACT.md
│   └── superpowers/specs/2026-07-04-laravel-backend-design.md
├── public/assets/generated/
├── scripts/import_tvtime.py
├── src/
│   ├── App.jsx
│   ├── lib/api.js
│   ├── lib/api.test.js
│   ├── lib/dashboard.js
│   ├── lib/dashboard.test.js
│   ├── main.jsx
│   └── styles.css
├── tests/test_import_tvtime.py
├── package.json
└── vite.config.mjs
```

Ignored private/generated paths that must not be committed include `backend/.env`, `backend/database/*.sqlite`, `var/`, `public/data/*.json`, `public/assets/cache/`, GDPR ZIP/CSV files, backend storage imports/private/generated files, logs, dumps, and token/password/device/IP data.

## 4. Database

Enums/constants:

- `UserRole`: `owner`, `admin`, `member`
- `UserStatus`: `active`, `disabled`
- `InviteStatus`: `pending`, `accepted`, `expired`
- `ImportStatus`: `pending`, `processing`, `completed`, `failed`
- `MediaStatus`: `planned`, `watching`, `watched`, `archived`

Tables:

- `users`: `id`, `name`, `email`, `email_verified_at`, `password`, `role`, `status`, `last_login_at`, `remember_token`, timestamps. Indexes: unique `email`, `role`, `status`, `last_login_at`.
- `password_reset_tokens`: `email` primary key, `token`, `created_at`.
- `sessions`: `id` primary key, `user_id` index, `ip_address`, `user_agent`, `payload`, `last_activity` index.
- `cache`: `key` primary key, `value`, `expiration` index.
- `cache_locks`: `key` primary key, `owner`, `expiration` index.
- `jobs`: `id`, `queue` index, `payload`, `attempts`, `reserved_at`, `available_at`, `created_at`.
- `job_batches`: `id` primary key, `name`, job counters, `failed_job_ids`, `options`, `cancelled_at`, `created_at`, `finished_at`.
- `failed_jobs`: `id`, `uuid` unique, `connection`, `queue`, `payload`, `exception`, `failed_at`; index on `connection`, `queue`, `failed_at`.
- `invites`: `id`, `email`, `token_hash`, `role`, `status`, `invited_by_user_id`, `accepted_by_user_id`, `expires_at`, `accepted_at`, timestamps. Indexes: `email`, unique `token_hash`, `role`, `status`, `expires_at`, `accepted_at`. FKs to `users` null on delete.
- `alerts`: `id`, `user_id`, `category`, `title`, `subtitle`, `due_text`, `payload`, `unread`, `read_at`, timestamps. Indexes: `category`, `unread`, `user_id/unread`, `user_id/category`. FK `user_id` cascade delete.
- `analytics_events`: `id`, `actor_user_id`, `event_name`, `metadata`, `occurred_at`, timestamps. Indexes: `event_name`, `occurred_at`, `actor_user_id/occurred_at`, `event_name/occurred_at`. FK `actor_user_id` null on delete.
- `analytics_daily_rollups`: `id`, `date`, `metric_name`, `dimensions`, `dimensions_hash`, `integer_value`, `decimal_value`, timestamps. Indexes: `date`, `metric_name`, unique `date/metric_name/dimensions_hash`.
- `audit_logs`: `id`, `actor_user_id`, `action`, `subject_type`, `subject_id`, `target_user_id`, `metadata`, `created_at`. Indexes: `action`, `actor_user_id/created_at`, `target_user_id/created_at`, `subject_type/subject_id`, `action/created_at`. User FKs null on delete.
- `shows`: `id`, `user_id`, `external_source`, `external_id`, `title`, `poster_url`, `fanart_url`, `followed`, `seen_episodes`, `aired_episodes`, `runtime`, `latest_seen_at`, timestamps. Indexes: `followed`, `user_id/title`, unique `user_id/external_source/external_id`. FK `user_id` cascade delete.
- `episodes`: `id`, `user_id`, `show_id`, `external_source`, `external_id`, `season_number`, `episode_number`, `title`, `runtime`, `air_date`, timestamps. Indexes: `air_date`, `user_id/show_id`, `user_id/air_date`, unique `user_id/external_source/external_id`. FKs: `user_id` cascade delete, `show_id` null on delete.
- `episode_watches`: `id`, `user_id`, `show_id`, `episode_id`, `watched_at`, `runtime`, `source`, timestamps. Indexes: `watched_at`, `user_id/watched_at`, `user_id/show_id`. FKs: `user_id` cascade delete, `show_id` and `episode_id` null on delete.
- `movies`: `id`, `user_id`, `external_source`, `external_id`, `title`, `poster_url`, `runtime`, `is_to_watch`, timestamps. Indexes: `is_to_watch`, `user_id/title`, unique `user_id/external_source/external_id`. FK `user_id` cascade delete.
- `movie_watches`: `id`, `user_id`, `movie_id`, `watched_at`, `runtime`, `watch_count`, `source`, timestamps. Indexes: `watched_at`, `user_id/watched_at`, `user_id/movie_id`. FKs: `user_id` cascade delete, `movie_id` null on delete.
- `ratings`: `id`, `user_id`, `media_type`, `media_id`, `rating`, timestamps. Unique `user_id/media_type/media_id`; indexes `media_type`, `media_id`, `user_id/media_type`. FK `user_id` cascade delete.
- `notes`: `id`, `user_id`, `media_type`, `media_id`, `body`, timestamps. Indexes `media_type`, `media_id`, `user_id/media_type/media_id`, `user_id/updated_at`. FK `user_id` cascade delete.
- `playback_sources`: `id`, `user_id`, `name`, `provider_type`, `status`, `metadata`, encrypted `settings`, `last_synced_at`, timestamps. Indexes: `provider_type`, `status`, `last_synced_at`, `user_id/status`, `user_id/provider_type`. FK `user_id` cascade delete.
- `playback_source_items`: `id`, `user_id`, `playback_source_id`, `external_id`, `kind`, `title`, `status`, encrypted `stream_url`, `stream_url_hash`, `metadata`, `last_seen_at`, timestamps. Unique `user_id/playback_source_id/external_id`; indexes `kind`, `status`, `stream_url_hash`, `user_id/kind`, `user_id/status`. FKs: `user_id` cascade delete, `playback_source_id` cascade delete.
- `media_links`: `id`, `user_id`, `playback_source_item_id`, `movie_id`, `show_id`, `episode_id`, `linked_at`, timestamps. Unique `user_id/playback_source_item_id`; indexes `user_id/movie_id`, `user_id/show_id`, `user_id/episode_id`. Provider item cascades; canonical media nulls on delete.
- `playback_sessions`: `id`, `user_id`, `playback_source_id`, `playback_source_item_id`, `media_link_id`, `status`, `started_at`, `ended_at`, `last_position_seconds`, `duration_seconds`, timestamps. Indexes `status`, `started_at`, `ended_at`, `user_id/status`, `user_id/playback_source_item_id`.
- `playback_progress`: `id`, `user_id`, `playback_session_id`, `playback_source_item_id`, `movie_id`, `episode_id`, `position_seconds`, `duration_seconds`, `completed`, timestamps. Unique `user_id/playback_source_item_id`; indexes `completed`, `user_id/completed`.

Every media/library/player/annotation table is scoped by `user_id`.

## 5. Models

- `User`: fillable identity/auth/role/status fields; hidden password/remember token; casts password hashed, dates, `UserRole`, `UserStatus`; relationships to invites, alerts, analytics events, audit logs, shows, episodes, episode watches, movies, movie watches; scopes `active`, `admins`, `members`; Filament panel access for active owner/admin.
- `Invite`: casts role/status/expires/accepted dates; relationships inviter and accepted user; scopes pending/forEmail.
- `Alert`: casts payload array, unread bool, read_at datetime; belongs to user; scopes `forUser`, `unread`, `forCategory`.
- `AnalyticsEvent`: casts metadata array, occurred_at datetime; belongs to actor; scopes `forActor`, `named`.
- `AnalyticsDailyRollup`: casts date, dimensions array, numeric values; scopes by date/metric.
- `AuditLog`: casts metadata array, created_at datetime; belongs to actor and target user; morph-like subject fields; scopes action/forActor/forTarget.
- `Show`: casts followed bool, counts/runtime ints, latest_seen_at datetime; belongs to user; has episodes and episode watches; scopes `forUser`, `followed`.
- `Episode`: casts season/episode/runtime ints and air_date date; belongs to user/show; has watches; scope `forUser`.
- `EpisodeWatch`: casts watched_at datetime and runtime int; belongs to user/show/episode; scopes `forUser`, `watched`.
- `Movie`: casts runtime int and is_to_watch bool; belongs to user; has watches; scopes `forUser`, `toWatch`.
- `MovieWatch`: casts watched_at datetime, runtime/watch_count ints; belongs to user/movie; scopes `forUser`, `watched`.
- `Rating`: casts rating int; belongs to user; scopes `forUser`, `forMedia`.
- `Note`: belongs to user; scopes `forUser`, `forMedia`.
- `PlaybackSource`: encrypted settings, metadata array, belongs to user, has source items/sessions, scopes `forUser`, `active`.
- `PlaybackSourceItem`: encrypted hidden stream URL, metadata array, belongs to user/source, has media link/sessions/progress, scopes `forUser`, `available`.
- `MediaLink`: belongs to user/source item and optional same-user canonical movie/show/episode, scope `forUser`.
- `PlaybackSession`: belongs to user/source/source item/media link, has progress, scope `forUser`.
- `PlaybackProgress`: belongs to user/session/source item/movie/episode, scope `forUser`.

## 6. Controllers And Endpoints

- `StatusController`: `GET /api/v1/status`; returns app/database/queue readiness.
- `AuthController@login`: `POST /api/v1/auth/login`; validates credentials, rejects disabled users, regenerates session, records analytics.
- `AuthController@logout`: `POST /api/v1/auth/logout`; logs out, invalidates session, regenerates CSRF token.
- `AuthController@me`: `GET /api/v1/me`; returns current user id/name/email/role/status.
- `InviteAcceptanceController`: `POST /api/v1/invites/accept`; accepts invite token, creates user, logs in.
- `DashboardController`: `GET /api/v1/dashboard`; returns user-scoped dashboard JSON.
- `AlertController@read`: `POST /api/v1/alerts/{alert}/read`; marks one owned alert read.
- `AlertController@readAll`: `POST /api/v1/alerts/read-all`; marks all owned alerts read.
- `ManualLibraryController@watchMovie`: `POST /api/v1/library/movies/{movie}/watch`; manually tracks a user-owned movie watch.
- `ManualLibraryController@rateMovie/rateShow/rateEpisode`: `POST /api/v1/library/{media}/{id}/rating`; saves a 1-10 rating for same-user canonical media.
- `ManualLibraryController@noteMovie/noteShow/noteEpisode`: `POST /api/v1/library/{media}/{id}/notes`; creates a private same-user note.
- `PlayerController@sources`: `GET /api/v1/player/sources`; lists only the user's provider sources.
- `PlayerController@destroySource`: `DELETE /api/v1/player/sources/{source}`; deletes one owned provider without deleting canonical watch history.
- `PlayerController@play`: `POST /api/v1/player/items/{item}/play`; starts playback for an owned provider item only.
- `PlayerController@link`: `POST /api/v1/player/items/{item}/link`; links an owned provider item to one same-user movie/show/episode.
- `PlayerController@updateSession`: `PATCH /api/v1/player/sessions/{session}`; updates owned playback progress and auto-records completed canonical watches.

## 7. Services

- `InviteService`: creates hashed invite tokens and accepts valid pending invites.
- `DashboardPayloadService`: builds the React-compatible payload from Laravel tables.
- `PlaybackLibraryService`: enforces user-owned provider/player access, media links, playback sessions, progress, manual tracking, and provider deletion rules.
- `MediaAnnotationService`: enforces same-user canonical media ownership for ratings and notes, records analytics, and writes audit logs.
- `AlertService`: marks alerts read/read-all with user ownership checks and analytics.
- `AnalyticsService`: records sanitized analytics events.
- `AuditLogService`: writes sanitized audit logs.
- `MediaLibraryService`: computes library stats.
- `SanitizesMetadata`: removes sensitive metadata keys before analytics/audit persistence.

## 8. Routes

Web:

- `GET /`
- Filament `/admin` routes for dashboard/login/logout and resources.

API:

- Public under `web` middleware: `GET /api/v1/status`, `POST /api/v1/auth/login`, `POST /api/v1/invites/accept`
- Authenticated under `auth`: `GET /api/v1/me`, `POST /api/v1/auth/logout`, `GET /api/v1/dashboard`, `POST /api/v1/alerts/{alert}/read`, `POST /api/v1/alerts/read-all`
- Authenticated player/library: `POST /api/v1/library/movies/{movie}/watch`, rating/note routes for movies/shows/episodes, `GET /api/v1/player/sources`, `DELETE /api/v1/player/sources/{source}`, `POST /api/v1/player/items/{item}/play`, `POST /api/v1/player/items/{item}/link`, `PATCH /api/v1/player/sessions/{session}`

Admin:

- `/admin/users`
- `/admin/invites`
- `/admin/alerts`
- `/admin/shows`
- `/admin/movies`
- `/admin/episode-watches`
- `/admin/movie-watches`
- `/admin/playback-sources`
- `/admin/playback-source-items`
- `/admin/analytics-events`
- `/admin/audit-logs`

## 9. Current Features

- Invite-only registration and login.
- Active/disabled user status.
- Owner/admin/member roles.
- Same-origin SPA session auth with CSRF-aware frontend API helper.
- User-specific dashboard payload.
- SQLite/JSON import command for existing private dashboard data.
- Persistent site alerts.
- Player section is provider-gated: users without a provider can manually track; users with a provider can play only their own source items and completion auto-tracks watch history.
- Ratings and notes are private, user-scoped, and provider-independent.
- Provider-safe backup/restore exports canonical library data and permanent user activity while excluding stream URLs, provider credentials, and secrets.
- Dashboard stats include manual watch count, auto-tracked watch count, linked/unlinked provider item counts, unsynced source-only progress count, ratings count, and notes count.
- Analytics and audit log creation.
- Filament admin for users, invites, alerts, media inspection, analytics, and audit logs.
- Frontend loading, login, API error, empty library, session expired, logout, search/filter, details modal, poster shelves, stats, and activity chart.

## 10. Current TODO

Priority 1:

- Deploy Laravel backend routing on staging without removing Apache Basic Auth.
- Create first owner/admin user in production.
- Import the real private SQLite for that user and verify counts: 92 shows, 7,292 watched episodes, 533 movies, 8 alerts.

Priority 2:

- Add safe user-facing import upload flow.
- Add user-facing ratings/notes management UI.
- Add user-facing backup download/restore flow if desired; current support is command-line only.
- Add admin dashboard metrics and rollups.
- Add production queue/scheduler process.

Priority 3:

- Add metadata provider integration for new episodes and movies.
- Add richer list browsing and settings.

## 11. Architecture Decisions

- GDPR export and generated private data stay ignored; Laravel tables become the authenticated app store.
- React UI was preserved; no redesign during backend connection sprint.
- Public registration is absent; users enter through invites.
- Imported media rows are always scoped by `user_id`.
- Filament media resources are read-only by default to reduce accidental private library edits.
- Provider/player sources are private per user; do not introduce a global/shared stream catalog or global provider cache.
- Canonical media and permanent activity outlive providers. Provider items are temporary.
- Provider/player access validates the same-user ownership graph across sources, source items, media links, sessions, progress, and canonical media.
- Admin may inspect provider status/metadata and stream hashes, but raw provider URLs and stream URLs are encrypted/hidden.
- Deleting a provider must not delete canonical watch history.
- Deleting a provider must not delete ratings or notes.
- User backups must not include stream URLs, playlist URLs, provider credentials, API keys, or secrets by default.
- Dashboard API returns the existing static JSON shape so frontend changes stay small.
- Do not replace Apache Basic Auth on staging yet.

Do not change these without explicit product direction: private ignore rules, invite-only access, `user_id` scoping, Basic Auth on staging, and the current dashboard payload contract.

## 12. APIs And External Integrations

TMDB/TVMaze: not implemented yet.
External APIs: none in Laravel yet. Python importer can cache remote artwork into ignored `public/assets/cache`.
Caching: Laravel cache tables exist; no product cache strategy yet.
Rate limiting: no custom rate limits yet; add before public launch if Basic Auth is removed.

## 13. Authentication

Login: `POST /api/v1/auth/login` with email/password; only active users stay authenticated.
Registration: public registration route is intentionally missing; `POST /api/v1/invites/accept` is the only public account creation path.
Middleware: API routes use `web`; private routes use `auth`. Frontend sends same-origin cookies and CSRF token header when available.
Permissions: Filament access is restricted in `User::canAccessPanel()` to active `owner` and `admin`.

## 14. UI

Screens:

- Login screen: email/password form with API errors.
- Home dashboard: hero, recently watched, movie shelf, alerts panel, followed shows, stats, activity chart.
- Shows view: top watched shows and followed shows with available episodes.
- Movies view: movies to check out.
- Player view: shows the attach-source empty state for users without providers; shows continue watching, source items, and linked/unlinked counts when provider data exists.
- Alerts view: wide alert list; opening an alert persists read state.
- Stats view: stats strip and activity chart.
- Lists/settings placeholders: preserved from original design.
- Detail modal: item and alert detail surface.

Screenshots are not embedded in this handover. Generate with Playwright after starting the app if needed.

## 15. Current Problems And Technical Debt

Known issues:

- Not deployed yet as a Laravel-backed site.
- No production owner/admin seed workflow documented beyond using Laravel/Filament.
- No user-facing import upload flow yet.
- No user-facing provider attach/manage UI yet; the backend architecture and owner-enforced APIs are in place.
- No user-facing ratings/notes management UI yet; backend APIs and dashboard counts exist.
- Backup/restore is command-line only.
- No metadata provider integration, so future episode/movie alert automation is not live.

Technical debt:

- `DashboardPayloadService` is intentionally pragmatic and may need extraction once provider integrations arrive.
- Filament media resources are basic inspection tables.
- Player UI is a first-pass section and does not yet wire frontend play buttons to `POST /api/v1/player/items/{item}/play`.
- There is no `seasons` table yet; season numbers live on `episodes`.
- Queue tables exist but no queue worker contract is installed on staging.
- No browser E2E login smoke test yet.

## 16. Next Sprint

1. Configure staging web server so the React app and Laravel API/admin work on `ccc.razbudise.mk`.
2. Keep Apache Basic Auth enabled.
3. Configure backend production `.env` on the server.
4. Run migrations, including ratings and notes.
5. Create first owner/admin user.
6. Import `../var/private/tvtime.sqlite` for that user.
7. Run `php artisan mediahub:backup-user {user_id}` once after import to verify safe backup generation.
8. Smoke test Basic Auth, Laravel login, `/api/v1/status`, `/api/v1/dashboard`, rating/note endpoints, alert read/read-all, `/admin`.

## 17. Git

Current branch: `main`.

Known recent local commit before backend work: `a8e970d` for the backend design spec; branch was ahead of `origin/main` by 1 at sprint start.

Expected uncommitted areas after this sprint:

- `README.md`
- `backend/`
- `docs/AI_HANDOVER.md`
- `docs/mediahub/CANONICAL_MEDIA_CONTRACT.md`

Run `git status --short --branch` before handoff or deployment.

## 18. README

The root `README.md` and `backend/README.md` have been updated with install, test, import, API, admin, privacy, and deployment notes.

## 19. Project Tree

See section 3 for the source tree. Full local checkouts also contain ignored/generated folders including `node_modules`, `backend/vendor`, `dist`, `var`, `backend/storage`, and private data outputs. Do not include those in commits or handoff snippets.

## 20. Five-Minute Summary

MediaHub is now an authenticated Laravel + React personal media operating system. The existing poster UI remains, but it logs into Laravel and loads `/api/v1/dashboard` instead of static JSON. Laravel owns invite-only users, sessions, alerts, analytics, audit logs, user-scoped canonical media, watch history, ratings, notes, provider/player tables, Filament admin, TV Time import, and provider-safe backup/restore. Provider items are temporary; canonical media, watch history, ratings, and notes are permanent. Player playback exists only for users who attach their own provider/source; there is no global stream catalog and dashboard payloads do not expose stream/provider URLs. The next engineer should deploy behind existing Apache Basic Auth, create the first admin user, import the private SQLite for that user, create a safe backup, and smoke test login, dashboard data, ratings/notes, alerts, provider-gated Player state, and `/admin`.
