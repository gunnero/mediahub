# MediaHub AI Handover

Generated: 2026-07-05
Repository: `/Users/aleksandardimovski/Sites/tvtime/dashboard`
Remote: `https://github.com/gunnero/Tvtime.git`
Staging: `https://ccc.razbudise.mk` with a public login page, Laravel-authenticated private routes, and Apache `noindex`

## 2026-07-12 Web V1 Bugfix Sprint 002 Addendum

This working tree contains local, uncommitted, and undeployed usability fixes. It also retains the immediately preceding local Settings layout fix that removes the Entertainment Diary only from Settings.

- Home shelf actions now open canonical History (movie and episode events, newest first) and the Movies watchlist (newest added first).
- Discover no longer uses the shared recent-movie hero and can browse Trending, Popular, Now Playing, Upcoming, and Top Rated through the optional TMDB client.
- Manual movie and episode watches are append-only repeat events. Detail/history payloads preserve every date and expose numbered watch history; manual unwatch removes only the latest manual event.
- Shows owns a latest-watched-show hero. History, Alerts, Stats, Lists, Discover, and Settings open directly into their page content without the Home hero.
- Calendar release output is restricted to followed/show episode metadata and watchlist movie releases, includes the user's timezone, and has a friendly empty state. Alerts now distinguish new episodes, upcoming episodes/movies, watchlist releases, and continue-watching reminders.
- Profile editing adds full name, ISO country selection, and privacy-controlled avatars. The backend decodes and re-encodes JPEG/PNG/WebP uploads, strips embedded metadata, creates 512/128/64/32 square variants, and restricts replacement/deletion to the current user.
- Browser identity is MediaHub with an SVG favicon, pinned-tab mark, and manifest.
- The `mediahub.razbudise.mk` virtual host, certificate, canonical/noindex, deployment-variable, and rollback sequence is prepared in documentation/templates only. No DNS, Apache, certificate, redirect, or deployment change has been made.

No subscription, native player, recommendation, or broad redesign work is included.

## 2026-07-12 Profiles And Friends Addendum

This local, uncommitted V1 bugfix replaces the inert top-right account control with an accessible account menu and adds the first private-by-default social foundation.

- `users` now carries unique username/slug, chosen display identity, optional bio/avatar/country/genres, profile visibility, selected favorites/lists, publication switches, and membership/activity timestamps.
- `friendships` stores one user pair with pending, accepted, declined, or blocked state. Ownership checks protect accept/decline/remove/block operations; blocked users cannot remove someone else's block.
- `friend_invites` stores only a token hash and tracks pending/opened/accepted/expired/revoked state. Opening does not create a friendship; acceptance is explicit.
- Public profiles are served at `/u/{profile_slug}`. Private profiles return only a minimal identity shell. Friends-only content requires an accepted friendship. Public content is field-by-field opt-in.
- Public output never contains email, private notes, ratings, raw history, diary events, provider/player data, exports, internal IDs, roles, alerts, IP/device data, or last-active timestamps.
- The recent-activity preference is stored but V1 intentionally emits no public activity.
- New account destinations are Profile, Friends, Invite Friends, Privacy, and Settings. Public-preview mode applies guest rules even for the owner.
- Safe in-app alerts cover friend request received, friend request accepted, and invitation accepted. No social email delivery was added.

Source of truth: `docs/mediahub/PROFILES_AND_FRIENDS_SPEC.md`.

## 2026-07-11 Web V1 Addendum

This section supersedes older implementation-status statements below.

- MediaHub Web V1 is implemented locally and is not deployed by this program.
- Default navigation is Home, Discover, Movies, Shows, History, Calendar, Alerts, Stats, Lists, and Settings.
- `MEDIAHUB_WEB_PLAYER_ENABLED=false` and `MEDIAHUB_WEB_PROVIDERS_ENABLED=false` hide Player, Play actions, provider status, and Provider Settings from the normal web product.
- Provider/player APIs, migrations, encrypted records, admin support, and tests remain intact for compatibility and the future native app.
- New user-scoped services and APIs cover release calendar, useful alerts/preferences, trustworthy stats, private manual lists, settings status, and JSON/CSV exports.
- New `media_lists`, `media_list_items`, and `notification_preferences` tables are user-scoped. Alert deduplication uses a per-user `dedupe_key`.
- `mediahub:repair-import-relationships {user_id} --dry-run|--apply` repairs only deterministic same-user episode/watch/show relationships. Apply mode backs up first; ambiguous or aggregate-only gaps remain unresolved.
- Backup and restore now include private manual lists but still exclude provider credentials, settings, locators, secrets, and tokens.
- Visible branding is MediaHub, with TV Time named only as an import source.
- No subscription, payment, premium-gating, new AI, native app, or native playback code was added.

Current source-of-truth documents:

- `docs/mediahub/WEB_PRODUCT_SCOPE.md`
- `docs/mediahub/IMPORT_RELATIONSHIP_REPAIR.md`
- `docs/mediahub/NATIVE_APP_FUTURE_ARCHITECTURE.md`
- `docs/mediahub/FREE_V1_AND_MEMBERSHIP_PLAN.md`
- `docs/mediahub/MEDIAHUB_V1_CHECKLIST.md`

The two pre-existing provider fixes were classified and committed separately before Web V1 work:

- `94ca2e0` - preserve XMLTV query credentials
- `276ddb2` - expose provider catalog sync lifecycle

## 1. Project Overview

Project name: MediaHub.

Purpose: provider-independent personal media operating system for movies and TV shows. The TV Time archive/importer is one data source, not the product identity.

Current version: frontend `package.json` is `0.0.0`; UI footer says `v1.0.0`. The latest working tree is the uncommitted and undeployed Web V1 Bugfix Sprint 002 described above.

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
- User-facing detail modal for movies, shows, and episodes with rating, private notes, safe watch history, provider link status, and manual watched/unwatched controls for movies/episodes.
- User-facing canonical library browsers for movies, shows, show seasons/episodes, watch history, and global search.
- User-facing Player tab for attaching own provider/source records, adding manual source items, linking/unlinking source items to same-user canonical media, starting HTML5/HLS playback, saving progress, and marking playback complete.
- Optional TMDB metadata foundation: private `.env` config, TMDB client service, additive metadata enrichment service, summary-only Artisan commands, public poster/backdrop use in dashboard/detail payloads, and safe Filament metadata inspection/refresh actions.
- User-scoped media event system for meaningful activity timeline, future statistics, Kalveri AI memory, recommendations, notifications, auditability, and achievements. Events sanitize forbidden metadata keys and never store stream/provider URLs or credentials.
- Kalveri AI Media Matcher v1: optional disabled-by-default Kalveri AI client, sanitized provider-item and metadata-review payloads, Player link-modal suggestions, metadata review commands, safe Filament actions, and `ai.match.*` media events. Suggestions require confirmation and never auto-link/apply.
- Separate My Library and Discover search modes. Discover queries TMDB through Laravel, previews public metadata, and adds same-user canonical movies/shows with duplicate prevention.
- Cinematic movie/show detail overlays with independently scrolling content, compact tabs, collapsed technical metadata, and a one-season-at-a-time episode browser.
- Settings information architecture for Profile, Privacy, Library, Providers, Backups, Metadata, and About.
- Private provider management for Xtream-compatible API, M3U, XMLTV, and manual sources with encrypted settings, safe test responses, catalog refresh, enable/disable, and deletion.
- Private provider catalog import with encrypted playback/artwork locators, categories, EPG summaries, deterministic link suggestions, inactive-item retention, and summary-only events.
- Player catalog views for Home, Movies, Shows, Live TV, TV Guide, and Search. Raw locators remain absent from list payloads; only owner play returns `playbackUrl`.
- Detail APIs for user-owned movies, shows, and episodes plus clear-rating, note update/delete, episode manual watch, and manual unwatch endpoints.
- Feature tests for canonical watch invariants, ratings/notes, safe backup/restore, provider URL safety, and provider deletion preserving permanent history.
- Filament admin panel at `/admin`.
- Filament resources for Users, Invites, Alerts, Shows, Movies, Episodes, Episode Watches, Movie Watches, Playback Sources, Playback Source Items, Media Events, Analytics Events, Audit Logs.
- Feature tests for auth, invite-only flow, dashboard, import, analytics, audit, alert persistence, provider ownership, manual tracking, provider deletion behavior, and cross-user isolation.
- Staging deployment to `https://ccc.razbudise.mk` on `web01`; Apache Basic Auth was removed with explicit approval on 2026-07-11 while Laravel auth and `noindex` remain.
- First owner user created on staging and full private SQLite imported for that user.
- Safe staging backup created with sensitive provider fields excluded.

Still planned:

- Review and commit the current Web V1 bugfix working tree only after the full validation and screenshot gate stays green.
- Deploy the bugfix only after explicit approval.
- Verify `mediahub.razbudise.mk` independently before separately approving any redirect from `ccc.razbudise.mk`.
- User-facing import upload flow.
- Background jobs/scheduler for future alert checks.
- Metadata conflict resolution and manual metadata correction.
- Richer analytics dashboard and admin metrics.

## Staging Deployment Snapshot 2026-07-05

Live URL: `https://ccc.razbudise.mk`.

Deployed commit: `d436f3aeb9362414e0d335705f6176971ace9dee` for Sprint 005. Local Sprint 006 event-system changes are not deployed yet.

Server: `web01`.

Paths:

- app checkout: `/home/razbudise/ccc.razbudise.mk/app`
- Laravel public root: `/home/razbudise/ccc.razbudise.mk/app/backend/public`
- deployment backup: `/home/razbudise/ccc.razbudise.mk/backups/20260705004417`
- private import source: `/home/razbudise/ccc.razbudise.mk/app/backend/storage/app/imports/tvtime.sqlite`
- safe user backups: `/home/razbudise/ccc.razbudise.mk/app/backend/storage/app/private/mediahub-backups`

Apache:

- The public login page does not emit an Apache `WWW-Authenticate` challenge.
- Laravel session authentication protects private product and admin routes.
- `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` remains enabled.
- `/api`, `/admin`, Livewire, Filament assets, and Laravel public assets route to Laravel/PHP-FPM.
- React SPA routes fall back to `index.html`.

Server setup:

- `composer install --no-dev --optimize-autoloader`
- `php artisan key:generate --force --no-interaction`
- `php artisan migrate --force --no-interaction`
- `php artisan config:cache`
- `php artisan route:cache`
- `php artisan view:cache`
- `npm ci --cache /home/razbudise/.npm-cache --prefer-offline=false`
- `npm run build -- --emptyOutDir`

Server dependency note: `php8.4-sqlite3` was installed so Laravel can use the private SQLite staging database. The install upgraded PHP 8.4 packages from `8.4.22` to `8.4.23` and PHP-FPM was restarted.

Imported owner user `1` counts:

- shows: 92
- episodes: 7,291
- episode watches: 7,292
- movies: 533
- movie watches: 512
- alerts: 8

Smoke tests passed:

- The public login page returns `200`; unauthenticated private APIs return `401` through Laravel.
- Laravel login/logout.
- `/api/v1/status`, `/api/v1/me`, `/api/v1/dashboard`.
- Dashboard payload sensitive-key scan found no stream/provider URL or token fields.
- Player empty state renders for a user without providers.
- Manual movie detail opens.
- Rating save/clear.
- Note save/update/delete.
- Mark watched/unwatched.
- Alert read persistence.
- `/admin` loads for the owner user.
- Authenticated browser dashboard smoke has zero console errors and zero broken script/style/image/font assets.

Rollback:

1. Restore the Apache vhost from `/home/razbudise/ccc.razbudise.mk/backups/20260705004417/ccc.razbudise.mk.conf`.
2. Restore the backed-up static deployment from `/home/razbudise/ccc.razbudise.mk/backups/20260705004417/public_html.tar.gz` if needed.
3. Run `apachectl configtest`.
4. Reload Apache with `systemctl reload apache2`.

## 2. Tech Stack

Backend: Laravel `13.x`, PHP `^8.3` per Composer; staging currently runs PHP `8.4.23`, Filament `5.6`, PHPUnit.
Frontend: React `19.2`, Vite `6.4`, Phosphor icons, plain CSS.
Database: SQLite locally; Laravel migrations are source of truth. Production DB can be SQLite or MySQL/MariaDB, but not decided here.
APIs: Laravel `/api/v1`; canonical library browser/search/history endpoints; optional TMDB API integration for public media metadata enrichment.
Authentication: Laravel session/cookie auth, invite-only registration, roles `owner`, `admin`, `member`, statuses `active`, `disabled`.
Queue system: Laravel default jobs tables exist; no queued product jobs yet.
Testing: `php artisan test`, `npm test -- --run`, `python3 -m unittest discover -s tests -v`, `npm run build`.

Important packages:

- Backend Composer: `laravel/framework`, `filament/filament`, `laravel/tinker`, `phpunit/phpunit`, `laravel/pint`.
- Frontend npm: `react`, `react-dom`, `vite`, `@vitejs/plugin-react`, `@phosphor-icons/react`, `hls.js`, `vitest`, `jsdom`, `playwright`.

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
│   ├── mediahub/MEDIA_EVENT_SYSTEM.md
│   └── superpowers/specs/2026-07-04-laravel-backend-design.md
├── public/assets/generated/
├── scripts/import_tvtime.py
├── src/
│   ├── App.jsx
│   ├── App.test.jsx
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
- `MediaEventSource`: `manual`, `player`, `import`, `provider`, `metadata`, `system`
- `MediaEventType`: stable dotted event names such as `movie.watched`, `episode.watched`, `rating.created`, `note.updated`, `provider.item.linked`, `playback.completed`, `metadata.enriched`, `backup.created`, and `restore.completed`

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
- `shows`: `id`, `user_id`, `external_source`, `external_id`, optional `tmdb_id`, `imdb_id`, `tvdb_id`, `title`, `original_title`, `overview`, `poster_url`, `fanart_url`, `poster_path`, `backdrop_path`, `first_air_date`, `genres`, `followed`, `seen_episodes`, `aired_episodes`, `runtime`, `status`, `vote_average`, `metadata`, `metadata_refreshed_at`, `latest_seen_at`, timestamps. Indexes include `followed`, metadata IDs/dates/status, `user_id/title`, `user_id/tmdb_id`, unique `user_id/external_source/external_id`. FK `user_id` cascade delete.
- `episodes`: `id`, `user_id`, `show_id`, `external_source`, `external_id`, optional `tmdb_id`, `imdb_id`, `tvdb_id`, `season_number`, `episode_number`, `title`, `original_title`, `overview`, `poster_path`, `backdrop_path`, `genres`, `runtime`, `air_date`, `status`, `vote_average`, `metadata`, `metadata_refreshed_at`, timestamps. Indexes include `air_date`, metadata IDs/status, `user_id/show_id`, `user_id/air_date`, `user_id/tmdb_id`, unique `user_id/external_source/external_id`. FKs: `user_id` cascade delete, `show_id` null on delete.
- `episode_watches`: `id`, `user_id`, `show_id`, `episode_id`, `watched_at`, `runtime`, `source`, timestamps. Indexes: `watched_at`, `user_id/watched_at`, `user_id/show_id`. FKs: `user_id` cascade delete, `show_id` and `episode_id` null on delete.
- `movies`: `id`, `user_id`, `external_source`, `external_id`, optional `tmdb_id`, `imdb_id`, `tvdb_id`, `title`, `original_title`, `overview`, `poster_url`, `poster_path`, `backdrop_path`, `release_date`, `genres`, `runtime`, `status`, `vote_average`, `metadata`, `metadata_refreshed_at`, `is_to_watch`, timestamps. Indexes include `is_to_watch`, metadata IDs/dates/status, `user_id/title`, `user_id/tmdb_id`, unique `user_id/external_source/external_id`. FK `user_id` cascade delete.
- `movie_watches`: `id`, `user_id`, `movie_id`, `watched_at`, `runtime`, `watch_count`, `source`, timestamps. Indexes: `watched_at`, `user_id/watched_at`, `user_id/movie_id`. FKs: `user_id` cascade delete, `movie_id` null on delete.
- `ratings`: `id`, `user_id`, `media_type`, `media_id`, `rating`, timestamps. Unique `user_id/media_type/media_id`; indexes `media_type`, `media_id`, `user_id/media_type`. FK `user_id` cascade delete.
- `notes`: `id`, `user_id`, `media_type`, `media_id`, `body`, timestamps. Indexes `media_type`, `media_id`, `user_id/media_type/media_id`, `user_id/updated_at`. FK `user_id` cascade delete.
- `playback_sources`: `id`, `user_id`, `name`, `provider_type`, `status`, `metadata`, encrypted `settings`, `last_synced_at`, `sync_status`, `last_sync_error`, timestamps. Indexes include provider/status/sync state and same-user provider/status. FK `user_id` cascade delete.
- `playback_source_items`: `id`, `user_id`, `playback_source_id`, `external_id`, `kind`, `title`, `status`, encrypted `stream_url`, `stream_url_hash`, `metadata`, `last_seen_at`, `category`, encrypted `poster_url`, `duration_seconds`, `release_year`, `match_status`, `favorite`, `catalog_synced_at`, timestamps. Unique `user_id/playback_source_id/external_id`; indexes cover kind/status/category/match/favorite/sync and same-user catalog filters. FKs: `user_id` cascade delete, `playback_source_id` cascade delete.
- `media_links`: `id`, `user_id`, `playback_source_item_id`, `movie_id`, `show_id`, `episode_id`, `linked_at`, timestamps. Unique `user_id/playback_source_item_id`; indexes `user_id/movie_id`, `user_id/show_id`, `user_id/episode_id`. Provider item cascades; canonical media nulls on delete.
- `playback_sessions`: `id`, `user_id`, `playback_source_id`, `playback_source_item_id`, `media_link_id`, `status`, `started_at`, `ended_at`, `last_position_seconds`, `duration_seconds`, timestamps. Indexes `status`, `started_at`, `ended_at`, `user_id/status`, `user_id/playback_source_item_id`.
- `playback_progress`: `id`, `user_id`, `playback_session_id`, `playback_source_item_id`, `movie_id`, `episode_id`, `position_seconds`, `duration_seconds`, `completed`, timestamps. Unique `user_id/playback_source_item_id`; indexes `completed`, `user_id/completed`.
- `media_events`: `id`, `user_id`, `event_type`, nullable `subject_type`, nullable `subject_id`, nullable `actor_type`, nullable `actor_id`, `occurred_at`, `source`, `metadata`, timestamps. Indexes: `user_id/occurred_at`, `user_id/event_type`, `subject_type/subject_id`. FK `user_id` cascade delete.

Every media/library/player/annotation table is scoped by `user_id`.

## 5. Models

- `User`: fillable identity/auth/role/status fields; hidden password/remember token; casts password hashed, dates, `UserRole`, `UserStatus`; relationships to invites, alerts, analytics events, audit logs, shows, episodes, episode watches, movies, movie watches, and media events; scopes `active`, `admins`, `members`; Filament panel access for active owner/admin.
- `Invite`: casts role/status/expires/accepted dates; relationships inviter and accepted user; scopes pending/forEmail.
- `Alert`: casts payload array, unread bool, read_at datetime; belongs to user; scopes `forUser`, `unread`, `forCategory`.
- `AnalyticsEvent`: casts metadata array, occurred_at datetime; belongs to actor; scopes `forActor`, `named`.
- `AnalyticsDailyRollup`: casts date, dimensions array, numeric values; scopes by date/metric.
- `AuditLog`: casts metadata array, created_at datetime; belongs to actor and target user; morph-like subject fields; scopes action/forActor/forTarget.
- `Show`: casts metadata IDs, first_air_date, genres array, followed bool, counts/runtime ints, vote_average, metadata array, metadata_refreshed_at, latest_seen_at datetime; belongs to user; has episodes and episode watches; scopes `forUser`, `followed`.
- `Episode`: casts metadata IDs, season/episode/runtime ints, genres array, air_date date, vote_average, metadata array, metadata_refreshed_at; belongs to user/show; has watches; scope `forUser`.
- `EpisodeWatch`: casts watched_at datetime and runtime int; belongs to user/show/episode; scopes `forUser`, `watched`.
- `Movie`: casts metadata IDs, release_date, genres array, runtime int, vote_average, metadata array, metadata_refreshed_at, and is_to_watch bool; belongs to user; has watches; scopes `forUser`, `toWatch`.
- `MovieWatch`: casts watched_at datetime, runtime/watch_count ints; belongs to user/movie; scopes `forUser`, `watched`.
- `Rating`: casts rating int; belongs to user; scopes `forUser`, `forMedia`.
- `Note`: belongs to user; scopes `forUser`, `forMedia`.
- `PlaybackSource`: encrypted settings, metadata array, belongs to user, has source items/sessions, scopes `forUser`, `active`.
- `PlaybackSourceItem`: encrypted hidden stream/artwork URLs, metadata array, typed catalog fields, belongs to user/source, has media link/sessions/progress, scopes `forUser`, `available`.
- `MediaLink`: belongs to user/source item and optional same-user canonical movie/show/episode, scope `forUser`.
- `PlaybackSession`: belongs to user/source/source item/media link, has progress, scope `forUser`.
- `PlaybackProgress`: belongs to user/session/source item/movie/episode, scope `forUser`.
- `MediaEvent`: casts metadata array and occurred_at datetime; belongs to user; optional morph subject and actor; scopes `forUser`, `ofType`, `fromSource`, and timeline-oriented filtering.

## 6. Controllers And Endpoints

- `StatusController`: `GET /api/v1/status`; returns app/database/queue readiness.
- `AuthController@login`: `POST /api/v1/auth/login`; validates credentials, rejects disabled users, regenerates session, records analytics.
- `AuthController@logout`: `POST /api/v1/auth/logout`; logs out, invalidates session, regenerates CSRF token.
- `AuthController@me`: `GET /api/v1/me`; returns current user id/name/email/role/status.
- `InviteAcceptanceController`: `POST /api/v1/invites/accept`; accepts invite token, creates user, logs in.
- `DashboardController`: `GET /api/v1/dashboard`; returns user-scoped dashboard JSON.
- `AlertController@read`: `POST /api/v1/alerts/{alert}/read`; marks one owned alert read.
- `AlertController@readAll`: `POST /api/v1/alerts/read-all`; marks all owned alerts read.
- `LibraryBrowserController@movies`: `GET /api/v1/library/movies`; returns paginated same-user movie cards with search/status/sort filters.
- `LibraryBrowserController@shows`: `GET /api/v1/library/shows`; returns paginated same-user show cards with search/status/sort filters.
- `LibraryBrowserController@history`: `GET /api/v1/library/history`; returns paginated same-user movie/episode watch history.
- `LibraryBrowserController@search`: `GET /api/v1/library/search`; returns grouped same-user canonical movie/show/episode search results.
- `DiscoveryController@search`: `GET /api/v1/discover/search`; returns rate-limited TMDB movie/show discovery results with same-user existing-library flags and safe failure states.
- `DiscoveryController@addMovie/addShow`: `POST /api/v1/discover/{movies|shows}/{tmdbId}/add`; creates/reuses same-user canonical media and applies library/watchlist/watched actions.
- `ProviderController@index/store/update/test/refresh/destroy`: manages same-user encrypted provider configuration, safe status-only connection tests, catalog refresh, and provider deletion without returning raw settings.
- `ManualLibraryController@showMovie/showShow/showEpisode`: `GET /api/v1/library/{media}/{id}`; returns a safe user-owned detail payload with status, rating, private notes, watch history, provider link status, and show season/episode groups.
- `ManualLibraryController@watchMovie/watchEpisode`: `POST /api/v1/library/{media}/{id}/watch`; creates or updates one manual watch row for a user-owned movie or episode.
- `ManualLibraryController@unwatchMovie/unwatchEpisode`: `DELETE /api/v1/library/{media}/{id}/watch`; removes only manual watch rows for a user-owned movie or episode, preserving imported/provider history.
- `ManualLibraryController@rateMovie/rateShow/rateEpisode`: `POST /api/v1/library/{media}/{id}/rating`; saves a 1-10 rating for same-user canonical media.
- `ManualLibraryController@clearMovieRating/clearShowRating/clearEpisodeRating`: `DELETE /api/v1/library/{media}/{id}/rating`; clears the current user's rating.
- `ManualLibraryController@noteMovie/noteShow/noteEpisode`: `POST /api/v1/library/{media}/{id}/notes`; creates a private same-user note.
- `ManualLibraryController@updateNote/deleteNote`: `PATCH|DELETE /api/v1/library/notes/{note}`; updates or deletes only the current user's private note.
- `PlayerController@sources`: `GET /api/v1/player/sources`; lists only the user's provider sources.
- `PlayerController@storeSource`: `POST /api/v1/player/sources`; creates an owned provider/source after legal confirmation.
- `PlayerController@updateSource`: `PATCH /api/v1/player/sources/{source}`; enables or disables an owned provider/source.
- `PlayerController@destroySource`: `DELETE /api/v1/player/sources/{source}`; deletes one owned provider without deleting canonical watch history.
- `PlayerController@items`: `GET /api/v1/player/items`; lists/searches only owned source items without raw URLs.
- `PlayerController@catalog`: `GET /api/v1/player/catalog`; returns safe same-user Home/Movies/Shows/Live/Guide/Search catalog views.
- `PlayerController@favorite`: `PATCH /api/v1/player/items/{item}/favorite`; changes a same-user live/catalog favorite flag.
- `PlayerController@storeItem`: `POST /api/v1/player/sources/{source}/items`; creates a manual owned source item with encrypted stream URL.
- `PlayerController@linkTargets`: `GET /api/v1/player/link-targets`; searches same-user canonical movies, shows, and episodes for manual linking.
- `PlayerController@play`: `POST /api/v1/player/items/{item}/play`; starts playback for an owned provider item only.
- `PlayerController@aiMatch`: `POST /api/v1/player/items/{item}/ai-match`; asks Kalveri AI/local matcher for a same-user source-item link suggestion without auto-linking.
- `PlayerController@rejectAiMatch`: `POST /api/v1/player/items/{item}/ai-match/reject`; rejects the stored AI suggestion and records a safe event.
- `PlayerController@link`: `POST /api/v1/player/items/{item}/link`; links an owned provider item to one same-user movie/show/episode, requiring explicit confirmation.
- `PlayerController@unlink`: `DELETE /api/v1/player/items/{item}/link`; removes the current user's link for an owned provider item.
- `PlayerController@updateSession`: `PATCH /api/v1/player/sessions/{session}`; updates owned playback progress and auto-records completed canonical watches.
- `MediaEventController@index`: `GET /api/v1/media-events`; returns current-user events with optional filters for event type, source, subject type, and date range.
- `MediaEventController@recent`: `GET /api/v1/media-events/recent`; returns a compact current-user recent activity timeline.

## 7. Services

- `InviteService`: creates hashed invite tokens and accepts valid pending invites.
- `DashboardPayloadService`: builds the React-compatible payload from Laravel tables, preferring safe TMDB poster/backdrop image URLs when enriched, adding a safe timeline object, and never exposing provider/stream URLs.
- `LibraryBrowserService`: builds paginated canonical movie, show, watch-history, and grouped search payloads for the React browser without exposing provider/source URLs.
- `MediaDetailService`: builds safe per-item detail payloads for the React modal with public metadata fields and without stream URLs or provider secrets.
- `DiscoveryService`: queries optional TMDB discovery, calculates same-user library state, and creates/reuses canonical movies/shows with sanitized events.
- `ProviderService`: manages encrypted provider settings and exposes only safe provider summaries and connection-test results.
- `ProviderConnectionService`: validates and fetches authorized Xtream/M3U/XMLTV catalogs with timeout/retry and safe error codes.
- `ProviderCatalogService`: imports private provider catalog metadata, encrypts locators, deactivates missing source items without deleting links, and creates deterministic same-user suggestions.
- `TMDBClientService`: optional TMDB API client with disabled mode, timeouts, response caching, and no API key logging.
- `KalveriAIClient`: optional Kalveri AI JSON client with disabled mode, timeout/failure fallback, and no API key logging.
- `SafeAIMatchingPayloadService`: recursively strips forbidden fields before Kalveri AI requests and before storing suggestions.
- `KalveriAIMediaMatcherService`: runs local provider-item matching, calls Kalveri AI only as fallback, stores suggestions, records `ai.match.*` events, and applies review matches only after explicit confirmation.
- `MediaMetadataService`: enriches movies, shows, episodes, and user libraries additively with public metadata while preserving user/import-owned fields.
- `MediaEventService`: records sanitized user-scoped activity events, strips forbidden metadata keys, exposes recent/timeline payload helpers, and fails softly so event recording never breaks the main user action.
- `PlaybackLibraryService`: enforces user-owned provider/player access, safe catalog views, favorites, media links, playback sessions, progress, manual/season tracking, and provider deletion rules.
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
- Authenticated library: `GET /api/v1/library/movies`, `GET /api/v1/library/shows`, `GET /api/v1/library/history`, `GET /api/v1/library/search`, `GET /api/v1/library/movies/{movie}`, `GET /api/v1/library/shows/{show}`, `GET /api/v1/library/episodes/{episode}`, `POST|DELETE /api/v1/library/movies/{movie}/watch`, `POST|DELETE /api/v1/library/episodes/{episode}/watch`, `POST|DELETE /api/v1/library/{media}/{id}/rating`, `POST /api/v1/library/{media}/{id}/notes`, `PATCH|DELETE /api/v1/library/notes/{note}`
- Authenticated player: `GET|POST /api/v1/player/sources`, `PATCH|DELETE /api/v1/player/sources/{source}`, `GET /api/v1/player/items`, `POST /api/v1/player/sources/{source}/items`, `GET /api/v1/player/link-targets`, `POST /api/v1/player/items/{item}/play`, `POST|DELETE /api/v1/player/items/{item}/link`, `PATCH /api/v1/player/sessions/{session}`
- Authenticated media events: `GET /api/v1/media-events`, `GET /api/v1/media-events/recent`

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
- `/admin/media-events`
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
- Player section is provider-gated: users without a provider can manually track; users can attach their own source, add manual source items, link/unlink same-user media, play only their own source items, save progress, and completion auto-tracks watch history only through the existing backend rules.
- Ratings and notes are private, user-scoped, provider-independent, and usable from the React detail modal.
- Movies, shows, and episodes have a safe user-facing detail modal. Movies and episodes can be manually marked watched/unwatched; unwatch removes manual rows only and keeps imported/provider history.
- Provider-safe backup/restore exports canonical library data and permanent user activity while excluding stream URLs, provider credentials, and secrets.
- Optional TMDB enrichment adds public canonical identity fields, poster/backdrop paths, genres, runtimes, release dates, overview, status, vote average, and external IDs without requiring TMDB for the app to run.
- Optional Kalveri AI media matcher helps with ambiguous provider items and metadata review episodes. It is fallback-only, confirmation-first, and never a source of truth.
- Media events record meaningful user-scoped activity from imports, manual watches, ratings, notes, provider actions, playback completion, metadata enrichment, backup, and restore. Timeline payloads are additive and sanitized.
- Dashboard stats include manual watch count, auto-tracked watch count, linked/unlinked provider item counts, unsynced source-only progress count, ratings count, and notes count.
- Analytics and audit log creation.
- Filament admin for users, invites, alerts, media inspection, analytics, and audit logs.
- Frontend loading, login, API error, empty library, session expired, logout, search/filter, manual-library detail modal, poster shelves, stats, and activity chart.

## 10. Current TODO

Priority 1:

- Review and commit Sprint 006 after the full verification gate stays green.
- Deploy Sprint 006 while preserving the public-login/Laravel-auth boundary and Apache `noindex` header.
- Smoke test `/api/v1/media-events`, dashboard timeline, manual watch/rating/note/provider/playback event creation, Filament Media Events, and sensitive payload scans on staging.
- Configure the pending private TMDB API key on staging before any metadata smoke enrichment.

Priority 2:

- Add safe user-facing import upload flow.
- Add user-facing backup download/restore flow if desired; current support is command-line only.
- Add metadata conflict review/manual correction UI for ambiguous TMDB matches.
- Add admin dashboard metrics and rollups.
- Add production queue/scheduler process.

Priority 3:

- Add TVDB/TVMaze or another metadata provider only after TMDB matching behavior is proven.
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
- Media events are always user-scoped and sanitized. They must never store provider URLs, stream URLs, API keys, tokens, passwords, credentials, or secrets.
- Playback progress events are intentionally not recorded for every small progress tick; the timeline should stay meaningful rather than noisy.
- Admin may inspect provider status/metadata and stream hashes, but raw provider URLs and stream URLs are encrypted/hidden.
- Deleting a provider must not delete canonical watch history.
- Deleting a provider must not delete ratings or notes.
- User backups must not include stream URLs, playlist URLs, provider credentials, API keys, or secrets by default.
- Dashboard API returns the existing static JSON shape so frontend changes stay small.
- Do not reintroduce Apache Basic Auth without explicit product direction; Laravel is the application authentication boundary.

Do not change these without explicit product direction: private ignore rules, invite-only access, `user_id` scoping, the public-login/Laravel-auth boundary, Apache `noindex`, and the current dashboard payload contract.

## 12. APIs And External Integrations

TMDB: optional Laravel integration exists for public movie/show/episode metadata enrichment. It is disabled by default through `TMDB_ENABLED=false`, requires a private `TMDB_API_KEY` only in runtime `.env`, uses a configurable timeout and Laravel cache TTL, and fails safely without crashing the app.
Kalveri AI: optional Laravel integration exists for media match suggestions. It is disabled by default through `KALVERI_AI_ENABLED=false`, requires private runtime `KALVERI_AI_BASE_URL` and `KALVERI_AI_API_KEY`, and fails safely without breaking provider linking or metadata review. Payloads are limited to titles, media type guesses, season/episode numbers, same-user candidate IDs/titles, years, and public TMDB IDs.
TVDB/TVMaze: not implemented yet.
External APIs: TMDB and optional Kalveri AI. Python importer can cache remote artwork into ignored `public/assets/cache`.
Caching: TMDB responses are cached through Laravel cache with `TMDB_CACHE_TTL`; no other external product cache strategy yet.
Rate limiting: no custom rate limits yet; add before any wider public launch. Removing the browser-level Basic Auth gate did not make private APIs public.
Media event API: internal authenticated `/api/v1/media-events` routes expose only the current user's sanitized timeline data and support simple filters for future activity views.

## 13. Authentication

Login: `POST /api/v1/auth/login` with email/password; only active users stay authenticated.
Registration: public registration route is intentionally missing; `POST /api/v1/invites/accept` is the only public account creation path.
Middleware: API routes use `web`; private routes use `auth`. Frontend sends same-origin cookies and CSRF token header when available.
Permissions: Filament access is restricted in `User::canAccessPanel()` to active `owner` and `admin`.

## 14. UI

Screens:

- Login screen: email/password form with API errors.
- Home dashboard: hero, recently watched, movie shelf, alerts panel, followed shows, stats, activity chart.
- Entertainment diary: compact recent activity grouped by Today, Yesterday, This week, and Earlier with user-readable source labels. Empty users get a quiet personal-memory empty state.
- Shows view: top watched shows and followed shows with available episodes.
- Movies view: movies to check out.
- Player view: shows the attach-source empty state plus attach form for users without providers; emphasizes private user-owned sources; shows provider management, manual source-item creation, source item search, linked and needs-linking source groups, link/unlink modal with Ask Kalveri AI suggestions, HTML5/HLS playback, progress controls, continue watching, and linked/unlinked counts.
- Alerts view: wide alert list; opening an alert persists read state.
- Stats view: stats strip and activity chart.
- Lists/settings placeholders: preserved from original design.
- Detail modal: alert detail plus movie/show/episode detail; media details show watched state, public metadata, "Your rating", "Private memory", safe watch history, provider link status, title-specific diary moments, and manual add/remove watch-history controls for movies and episodes.

Screenshots are not embedded in this handover. Generate with Playwright after starting the app if needed.

## 15. Current Problems And Technical Debt

Known issues:

- Product Design Sprint 001 is local/uncommitted until reviewed; it changes React UX/copy/motion and docs only.
- TMDB API key is still pending on staging, so Sprint 005 enrichment remains optional until private `.env` configuration is completed.
- TMDB matching is title/year based and needs manual conflict review before bulk production use on ambiguous titles.
- No production owner/admin seed workflow documented beyond using Laravel/Filament.
- No user-facing import upload flow yet.
- Backup/restore is command-line only.
- Future episode/movie alert automation is not live yet.

Technical debt:

- `DashboardPayloadService` is intentionally pragmatic and may need extraction once metadata/provider integrations grow.
- Filament media resources are basic inspection tables.
- TMDB admin refresh actions are basic row actions and should eventually move to queued jobs for large libraries.
- There is no `seasons` table yet; season numbers live on `episodes`.
- Queue tables exist but no queue worker contract is installed on staging.
- No browser E2E login smoke test yet.

## 16. Next Sprint

1. Review Product Design Sprint 001 in the browser on desktop and mobile, then commit if the verification gate stays green.
2. Deploy committed Sprint 006 plus Product Design Sprint 001 to `ccc.razbudise.mk` while preserving Laravel auth and Apache `noindex`.
3. Smoke test media event APIs, Entertainment diary, Filament Media Events, and event creation from manual watch/rating/note/provider/playback flows.
4. Configure a private server-side `TMDB_API_KEY`, run `php artisan mediahub:metadata-status 1`, then test one movie and one show enrichment before any full-user enrichment.

## 17. Git

Current branch: `main`.

Run `git log --oneline -5` for the exact current commit chain before handoff or deployment.

Expected uncommitted areas after Sprint 006:

- `README.md`
- `backend/`
- `docs/AI_HANDOVER.md`
- `docs/mediahub/MEDIA_EVENT_SYSTEM.md`
- `src/`

Run `git status --short --branch` before handoff or deployment.

## 18. README

The root `README.md` and `backend/README.md` have been updated with install, test, import, API, admin, privacy, and deployment notes.

## 19. Project Tree

See section 3 for the source tree. Full local checkouts also contain ignored/generated folders including `node_modules`, `backend/vendor`, `dist`, `var`, `backend/storage`, and private data outputs. Do not include those in commits or handoff snippets.

## 20. Five-Minute Summary

MediaHub is now an authenticated Laravel + React personal media operating system. The existing poster UI remains, but it logs into Laravel and loads `/api/v1/dashboard` instead of static JSON. Laravel owns invite-only users, sessions, alerts, analytics, audit logs, user-scoped canonical media, watch history, ratings, notes, provider/player tables, Filament admin, TV Time import, provider-safe backup/restore, optional TMDB metadata enrichment, and a local Sprint 006 media event system. Provider items are temporary; canonical media, watch history, ratings, notes, public metadata, and meaningful activity events are permanent user-owned library data. The React detail modal lets users view safe movie/show/episode detail, save/clear ratings, save/delete private notes, inspect safe watch history, see provider link status, inspect public metadata, and manually mark movies/episodes watched or unwatched without deleting imported/provider history. The dashboard now has an additive sanitized timeline panel. There is no global stream catalog and dashboard/detail/list/timeline payloads do not expose stream/provider URLs; the play endpoint is owner-only and is the only place a playback URL is returned. Sprint 006 adds `media_events`, `MediaEventService`, authenticated event APIs, a Filament Media Events resource, dashboard timeline payloads, and event hooks for imports, manual watches, ratings, notes, provider actions, playback completion, metadata enrichment, backup, and restore. Staging exposes the login page publicly, keeps private routes behind Laravel authentication, and retains Apache `noindex`; the browser-level Basic Auth prompt is intentionally removed.
