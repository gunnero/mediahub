# MediaHub Monitoring And Health

This document defines lightweight health checks for staging deployment verification. It does not add new application code.

## Staging Protection

The login screen is public while application data stays private behind Laravel authentication:

```bash
curl -I https://ccc.razbudise.mk/
```

Expected:

- HTTP status `200`
- no `WWW-Authenticate` header
- `X-Robots-Tag` containing `noindex`

## API Health

```bash
curl https://ccc.razbudise.mk/api/v1/status
curl -H 'Accept: application/json' -o /dev/null -w '%{http_code}\n' https://ccc.razbudise.mk/api/v1/me
```

Expected:

- HTTP 200
- app readiness data
- database readiness data
- unauthenticated private API status `401`

## Authenticated Product Health

After Laravel login, verify:

- `/api/v1/dashboard`
- `/api/v1/media-events`
- `/api/v1/media-events/recent`
- `/api/v1/player/items`

These endpoints must be user-scoped and must not expose stream/provider URLs, playlist URLs, credentials, API keys, tokens, passwords, or secrets.

## Sensitive Payload Scan

Deployment smoke checks scan JSON responses for these forbidden keys:

- `stream_url`
- `playbackUrl`
- `provider_url`
- `playlist_url`
- `password`
- `api_key`
- `token`
- `secret`
- `credential`

The only endpoint allowed to return a playback URL is the owner-only play endpoint:

```text
POST /api/v1/player/items/{item}/play
```

Do not include that endpoint in broad dashboard/list/timeline payload scans unless the test is specifically checking owner-only playback.

## Browser Smoke

After deployment, manually verify:

1. A fresh browser opens the MediaHub login screen without a browser credential prompt.
2. Login works.
3. Dashboard loads.
4. Entertainment diary appears.
5. Timeline groups render.
6. Detail modal opens.
7. Rating and notes work.
8. Player tab loads.
9. `/admin` loads for owner/admin.
10. Browser console has no secrets.

## Operational Status Report

Each deployment report should include:

- deployed commit
- server backup path
- migration result
- cache result
- Apache configtest result
- public login and Laravel authentication checks
- noindex check
- smoke result
- known issues
- rollback target

## Monitoring Boundaries

This is staging, not public production. Keep the checks minimal and privacy-safe:

- No third-party analytics.
- No public uptime page.
- No private data in logs.
- No stream/provider URLs in monitoring output.
