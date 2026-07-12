# MediaHub Deployment Architecture

This document describes the staging deployment architecture for MediaHub on `web01`.

## Purpose

MediaHub deployments should be one command from the repository root:

```bash
./deploy-mediahub.sh
```

The script is intentionally operational tooling only. It does not change application features, expose private TV Time data, or print provider URLs, stream URLs, passwords, API keys, tokens, or secrets.

## Current Stack

- GitHub: source of truth for the `main` branch.
- web01: Hetzner-hosted server running the staging site.
- SSH: local host alias `web01`, currently expected to use `~/.ssh/123url_ed25519`.
- Apache: public TLS endpoint and routing layer for `https://ccc.razbudise.mk`.
- PHP: Laravel runtime behind Apache/PHP-FPM.
- Node: Vite/React frontend build runtime.
- Laravel: backend API, Filament admin, auth, imports, media events, provider/player APIs.
- React: Cinema Command Center frontend.

## Source And Runtime Paths

Known staging layout:

- live URL: `https://ccc.razbudise.mk`
- app checkout: `/home/razbudise/ccc.razbudise.mk/app`
- Laravel app: `/home/razbudise/ccc.razbudise.mk/app/backend`
- Laravel public root: `/home/razbudise/ccc.razbudise.mk/app/backend/public`
- React build staging directory: `/home/razbudise/ccc.razbudise.mk/app/dist`
- backups: `/home/razbudise/ccc.razbudise.mk/backups`
- private imports: `/home/razbudise/ccc.razbudise.mk/app/backend/storage/app/imports`
- safe user backups: `/home/razbudise/ccc.razbudise.mk/app/backend/storage/app/private/mediahub-backups`

The frontend build target is configurable with `MEDIAHUB_FRONTEND_PUBLIC_DIR`. The default is `/home/razbudise/ccc.razbudise.mk/app/backend/public`, because Apache serves the Laravel public root. Vite still builds into `/home/razbudise/ccc.razbudise.mk/app/dist`; the deploy script then copies `dist/index.html`, `dist/assets/*`, and a fixed allowlist of browser identity files (`favicon.svg`, `mediahub-pinned-tab.svg`, and `site.webmanifest`) into `backend/public`.

The frontend sync must preserve Laravel public files. It must not overwrite `backend/public/index.php`, `backend/public/.htaccess`, `robots.txt`, `favicon.ico`, or existing Laravel/Filament/Livewire public assets. `site.webmanifest` is replaced only because it is on the explicit browser-identity allowlist; the sync does not delete unrelated public files.

## Apache Contract

Apache must keep these rules:

- The login page is public and must not emit an Apache `WWW-Authenticate` challenge.
- Laravel session authentication protects private product and admin routes.
- ACME challenge paths may remain excluded for certificate renewal.
- `/api/*`, `/admin/*`, Livewire, Filament assets, and Laravel public assets route to Laravel/PHP-FPM.
- React SPA routes load the built frontend.
- Staging responses keep `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet`.

Apache Basic Auth was removed with explicit approval on 2026-07-11. Do not reintroduce it without new explicit approval.

## Deployment Flow

`deploy-mediahub.sh` performs:

1. Verify the local branch is `main`.
2. Verify the local working tree is clean.
3. Verify SSH access to `web01` using an explicit `IdentityFile`.
4. Verify the public login page, Laravel authentication boundary, and `X-Robots-Tag`.
5. Create a timestamped server backup.
6. Pull the latest `origin/main` on the server.
7. Run production Composer install.
8. Run Laravel migrations and caches.
9. Build the React/Vite frontend into `app/dist`.
10. Sync React `index.html` and built assets into `backend/public` without deleting Laravel public files.
11. Run `apachectl configtest`.
12. Reload Apache.
13. Run live smoke checks.

## Sensitive Data Rules

The deployment scripts must never print or commit:

- backend `.env`
- SQLite database files
- GDPR ZIP/CSV exports
- private imports
- provider files
- stream URLs
- provider URLs
- playlist URLs
- TMDB keys
- API keys
- passwords
- tokens
- secrets
- generated private dashboard JSON
- logs/cache contents

Authenticated smoke checks scan API payloads for sensitive field names such as `stream_url`, `provider_url`, `playlist_url`, `api_key`, `token`, and `secret`.

## Rollback Flow

`rollback-mediahub.sh --yes` performs:

1. Verify SSH access.
2. Select `MEDIAHUB_ROLLBACK_BACKUP` or the latest timestamped backup.
3. Create a pre-rollback snapshot.
4. Restore app files.
5. Restore `database.sqlite` if the backup contains it.
6. Restore Apache vhost config if present.
7. Run `apachectl configtest`.
8. Reload Apache.
9. Verify the login page has no Basic Auth challenge, private APIs return `401`, and noindex remains enabled.

## Required Local Setup

The local SSH setup must be made permanent once:

```bash
ssh-add --apple-use-keychain ~/.ssh/123url_ed25519
```

Then `./deploy-mediahub.sh --check` should pass without manual key juggling.

## Configuration Variables

The scripts are configurable through environment variables, so secrets never need to be committed.

| Variable | Default |
| --- | --- |
| `MEDIAHUB_BRANCH` | `main` |
| `MEDIAHUB_REMOTE` | `origin` |
| `MEDIAHUB_SSH_HOST` | `web01` |
| `MEDIAHUB_SSH_USER` | `root` |
| `MEDIAHUB_SSH_IDENTITY` | `$HOME/.ssh/123url_ed25519` |
| `MEDIAHUB_SERVER_SITE_ROOT` | `/home/razbudise/ccc.razbudise.mk` |
| `MEDIAHUB_SERVER_APP_DIR` | `$MEDIAHUB_SERVER_SITE_ROOT/app` |
| `MEDIAHUB_SERVER_BACKUP_ROOT` | `$MEDIAHUB_SERVER_SITE_ROOT/backups` |
| `MEDIAHUB_FRONTEND_PUBLIC_DIR` | `$MEDIAHUB_SERVER_APP_DIR/backend/public` |
| `MEDIAHUB_LIVE_URL` | `https://ccc.razbudise.mk` |
| `MEDIAHUB_PHP_FPM_SERVICE` | empty |
| `MEDIAHUB_RELOAD_APACHE` | `true` |

Optional smoke-test variables:

- `MEDIAHUB_APP_EMAIL`
- `MEDIAHUB_APP_PASSWORD`

## Frontend Sync Verification

After each deploy, verify:

- `backend/public/index.html` exists and references the newest built asset hash.
- `backend/public/assets/` contains the newest Vite `.js` and `.css` files.
- `backend/public/index.php` still exists.
- `/api/v1/status` still routes to Laravel.
- The public login has no `WWW-Authenticate` header, Laravel private routes return `401` when unauthenticated, and `X-Robots-Tag` noindex remains enabled.
