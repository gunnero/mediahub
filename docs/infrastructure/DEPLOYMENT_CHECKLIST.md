# MediaHub Deployment Checklist

## Before deploy

1. Confirm the work is committed and pushed to GitHub.
2. Confirm the repository is on `main`.
3. Confirm `git status --short` is clean.
4. Confirm no private files are staged or committed:
   - `.env`
   - SQLite databases
   - GDPR ZIP/CSV files
   - private imports
   - generated private JSON
   - provider files
   - logs/cache
   - `vendor`
   - `node_modules`
5. Confirm SSH is permanent:
   - `ssh-add -l`
   - `ssh web01 'hostname'`
   - `./deploy-mediahub.sh --check`
6. Confirm app credentials are available only as local environment variables if full authenticated smoke testing is needed.

## During deploy

Run:

```bash
./deploy-mediahub.sh
```

The script should:

1. Verify Git branch and clean tree.
2. Verify SSH.
3. Verify the public login page, Laravel authentication boundary, and `X-Robots-Tag`.
4. Create a timestamped backup on `web01`.
5. Pull from GitHub.
6. Run Composer production install.
7. Run Laravel migrations.
8. Rebuild Laravel caches.
9. Build React.
10. Copy React `dist/index.html` and `dist/assets/*` into `backend/public` without deleting Laravel public files.
11. Verify `backend/public/index.php` still exists.
12. Verify `/api/v1/status` is still registered in Laravel.
13. Run Apache config test.
14. Reload Apache.
15. Run smoke checks.

## After deploy

Verify:

1. Unauthenticated `https://ccc.razbudise.mk/` returns `200` without a `WWW-Authenticate` header.
2. `X-Robots-Tag` contains `noindex`.
3. `backend/public/index.html` references the newest React build asset.
4. `backend/public/assets/` contains the newest built `.js` and `.css` assets.
5. `backend/public/index.php` still exists.
6. Login works.
7. Logout works.
8. Public `/api/v1/status` works and unauthenticated `/api/v1/me` returns `401`.
9. `/api/v1/dashboard` loads after login.
10. Entertainment diary renders.
11. `/api/v1/media-events` and `/api/v1/media-events/recent` work after login.
12. Detail modal opens.
13. Rating save/clear works.
14. Note save/update/delete works.
15. Mark watched/unwatched works.
16. Player tab loads.
17. `/admin` loads for owner/admin.
18. No browser console secrets are printed.
19. Dashboard, timeline, and player list payloads do not expose stream/provider URLs.

## Rollback

Use the latest backup:

```bash
./rollback-mediahub.sh --yes
```

Use a specific backup:

```bash
MEDIAHUB_ROLLBACK_BACKUP=/home/razbudise/ccc.razbudise.mk/backups/20260705004417 ./rollback-mediahub.sh --yes
```

Rollback should:

1. Create a pre-rollback snapshot.
2. Restore app files.
3. Restore `database.sqlite` if present in the backup.
4. Restore Apache vhost if present in the backup.
5. Run `apachectl configtest`.
6. Reload Apache.
7. Verify the public login has no Basic Auth challenge, private APIs return `401`, and `X-Robots-Tag` remains present.

## Stop Conditions

Stop and do not continue if:

- SSH asks for a password or fails public-key auth.
- The local tree is dirty.
- Apache config test fails.
- An Apache `WWW-Authenticate` challenge appears.
- An unauthenticated private Laravel route does not return `401`.
- `X-Robots-Tag` noindex is missing.
- A payload scan finds `stream_url`, `provider_url`, `playlist_url`, `api_key`, `token`, `secret`, or `credential`.
