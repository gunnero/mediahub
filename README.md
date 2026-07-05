# MediaHub

Provider-independent personal media operating system with a React/Vite dashboard and a Laravel backend.

## Current Shape

- `src/`: React dashboard UI. It logs in through Laravel and loads `/api/v1/dashboard`.
- `scripts/import_tvtime.py`: imports the preserved TV Time GDPR export into ignored private outputs.
- `backend/`: Laravel 13 backend for invite-only users, Filament admin, analytics, audit logs, alerts, per-user libraries, provider-owned player state, manual watch history, ratings, notes, and safe backups.

The deployed staging site at `https://ccc.razbudise.mk` remains protected by Apache Basic Auth. Do not remove that protection until Laravel auth and deployment hardening are finished.

## Privacy Rules

Do not commit or expose:

- GDPR exports or CSVs.
- `.env` files.
- access tokens, refresh tokens, device data, IP addresses, or passwords.
- `var/private/tvtime.sqlite`.
- `public/data/dashboard-data.json`.
- `public/assets/cache/`.
- backend storage imports, generated private JSON, database dumps, or private SQLite files.
- provider URLs, stream URLs, provider credentials, or global provider caches.

## Canonical Media Rule

Canonical media and watch history are permanent. Provider items are temporary.

- Canonical media: `movies`, `shows`, `episodes`.
- User activity: `movie_watches`, `episode_watches`, `ratings`, `notes`, `playback_sessions`.
- Provider layer: `playback_sources`, `playback_source_items`, `media_links`, `playback_progress`.

Every record is scoped by `user_id`. Deleting or disabling a provider may remove provider rows, links, sessions, and source progress, but it must not delete canonical movies, shows, episodes, watch history, ratings, or notes.

See `docs/mediahub/CANONICAL_MEDIA_CONTRACT.md`.

## Player Provider Rule

The Player is available only when a user attaches their own provider/source. Users without a provider still use the dashboard and manual library normally as a watch-history tracker.

Rules:

- provider sources are private per user
- User A's provider source or source items must never be visible or playable by User B
- provider items, links, sessions, and progress must validate the same-user ownership graph
- provider items can link only to that same user's canonical movies, shows, or episodes
- admin screens may inspect source metadata and status, but not raw provider URLs or stream URLs
- there is no global/shared stream catalog and provider content is not cached globally
- deleting a provider deletes provider/player rows only; canonical watch history remains

## Frontend Setup

```bash
npm install
npm run dev
```

For local authenticated API testing, also run Laravel on `127.0.0.1:8000`; Vite proxies `/api` there:

```bash
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

Build:

```bash
npm run build
```

Tests:

```bash
npm test -- --run
```

## Python Importer

Regenerate local private dashboard data:

```bash
python3 scripts/import_tvtime.py
```

Default private outputs are ignored by Git:

- `var/private/tvtime.sqlite`
- `public/data/dashboard-data.json`
- `public/assets/cache/`

## Laravel Import

Import the existing private SQLite or generated JSON into one Laravel user:

```bash
cd backend
php artisan tvtime:import-user {user_id} ../var/private/tvtime.sqlite
```

The command only accepts files from ignored private/generated paths and prints summary counts only.

## MediaHub Backup And Restore

Create or restore a provider-safe user backup:

```bash
cd backend
php artisan mediahub:backup-user {user_id}
php artisan mediahub:restore-user {user_id} storage/app/private/mediahub-backups/user-{user_id}-YYYYMMDD-HHMMSS.json
```

Backups are written under ignored private Laravel storage and include canonical library data, watch history, ratings, notes, safe media links, and safe progress. They intentionally exclude raw stream URLs, playlist URLs, provider settings, provider credentials, API keys, and secrets.

## Backend Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Run backend tests:

```bash
cd backend
php artisan test
```

The Phase 1 backend API routes live under `/api/v1`.

The React app expects the backend on the same origin. Private routes use Laravel session cookies:

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/me`
- `GET /api/v1/dashboard`
- `POST /api/v1/alerts/{alert}/read`
- `POST /api/v1/alerts/read-all`
- `GET /api/v1/library/movies/{movie}`
- `GET /api/v1/library/shows/{show}`
- `GET /api/v1/library/episodes/{episode}`
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
- `GET /api/v1/player/sources`
- `DELETE /api/v1/player/sources/{source}`
- `POST /api/v1/player/items/{item}/play`
- `POST /api/v1/player/items/{item}/link`
- `PATCH /api/v1/player/sessions/{session}`

Filament admin is available at `/admin` for active `owner` and `admin` users.

The React detail modal uses the library endpoints for user-owned movies, shows, and episodes. Users can rate 1-10, clear ratings, save/delete private notes, and add/remove manual watch rows for movies and episodes. The remove action only deletes manual watch rows; imported/provider watch history remains permanent.

## Verification

Run all current checks from the repository root:

```bash
npm test -- --run
python3 -m unittest discover -s tests -v
npm run build
cd backend && php artisan test
git diff --check
```

## Deployment Checklist

1. Keep Apache Basic Auth enabled on staging.
2. Pull the reviewed branch on the server.
3. Install PHP dependencies in `backend/` with production flags.
4. Configure backend `.env` on the server; do not commit it.
5. Run `php artisan migrate --force`.
6. Run `php artisan filament:assets` if Composer did not publish Filament assets.
7. Build the React frontend with `npm run build`.
8. Point the web server so `/api` and `/admin` hit Laravel and the SPA assets are served from the built frontend.
9. Smoke test Basic Auth, Laravel login, `/api/v1/status`, `/api/v1/dashboard`, and `/admin`.

## Staging Deployment 2026-07-05

Live staging URL: `https://ccc.razbudise.mk`, still behind Apache Basic Auth.

Server layout on `web01`:

- app checkout: `/home/razbudise/ccc.razbudise.mk/app`
- Laravel public root: `/home/razbudise/ccc.razbudise.mk/app/backend/public`
- deployment backup: `/home/razbudise/ccc.razbudise.mk/backups/20260705004417`
- private import source: `/home/razbudise/ccc.razbudise.mk/app/backend/storage/app/imports/tvtime.sqlite`
- MediaHub backup output: `/home/razbudise/ccc.razbudise.mk/app/backend/storage/app/private/mediahub-backups`

Apache routes `/api`, `/admin`, Livewire, Filament assets, and Laravel public assets through Laravel/PHP-FPM. SPA routes fall back to React `index.html`. Basic Auth and `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` remain enabled.

Imported staging counts for owner user `1`: 92 shows, 7,291 episodes, 7,292 episode watches, 533 movies, 512 movie watches, and 8 alerts. The safe user backup was created and verified to exclude sensitive provider field keys.

Smoke results: Basic Auth 401 without credentials, Laravel login/logout, `/api/v1/status`, `/api/v1/me`, `/api/v1/dashboard`, Player empty state, manual movie detail, rating save/clear, note save/update/delete, mark watched/unwatched, alert read persistence, `/admin`, dashboard sensitive-key scan, and authenticated browser asset/console checks all passed.

Rollback: restore `/etc/apache2/sites-available/ccc.razbudise.mk.conf` from the deployment backup, switch `DocumentRoot` back to the backed-up `public_html` deployment if needed, run `apachectl configtest`, then `systemctl reload apache2`.
