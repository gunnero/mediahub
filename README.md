# TV Time Dashboard

Private TV Time archive dashboard with a React/Vite frontend and a Laravel backend.

## Current Shape

- `src/`: React dashboard UI. It now logs in through Laravel and loads `/api/v1/dashboard`.
- `scripts/import_tvtime.py`: imports the preserved TV Time GDPR export into ignored private outputs.
- `backend/`: Laravel 13 backend for invite-only users, Filament admin, analytics, audit logs, alerts, and per-user libraries.

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
- `POST /api/v1/library/movies/{movie}/watch`
- `GET /api/v1/player/sources`
- `DELETE /api/v1/player/sources/{source}`
- `POST /api/v1/player/items/{item}/play`
- `POST /api/v1/player/items/{item}/link`
- `PATCH /api/v1/player/sessions/{session}`

Filament admin is available at `/admin` for active `owner` and `admin` users.

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
