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
6. Confirm Basic Auth credentials and app credentials are available only as local environment variables if full smoke testing is needed.

## During deploy

Run:

```bash
./deploy-mediahub.sh
```

The script should:

1. Verify Git branch and clean tree.
2. Verify SSH.
3. Verify Basic Auth and `X-Robots-Tag`.
4. Create a timestamped backup on `web01`.
5. Pull from GitHub.
6. Run Composer production install.
7. Run Laravel migrations.
8. Rebuild Laravel caches.
9. Build React.
10. Run Apache config test.
11. Reload Apache.
12. Run smoke checks.

## After deploy

Verify:

1. Unauthenticated `https://ccc.razbudise.mk/` returns 401.
2. `X-Robots-Tag` contains `noindex`.
3. Login works.
4. Logout works.
5. `/api/v1/status` works behind Basic Auth.
6. `/api/v1/dashboard` loads after login.
7. Entertainment diary renders.
8. `/api/v1/media-events` and `/api/v1/media-events/recent` work after login.
9. Detail modal opens.
10. Rating save/clear works.
11. Note save/update/delete works.
12. Mark watched/unwatched works.
13. Player tab loads.
14. `/admin` loads for owner/admin.
15. No browser console secrets are printed.
16. Dashboard, timeline, and player list payloads do not expose stream/provider URLs.

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
7. Verify 401 Basic Auth and `X-Robots-Tag`.

## Stop Conditions

Stop and do not continue if:

- SSH asks for a password or fails public-key auth.
- The local tree is dirty.
- Apache config test fails.
- Basic Auth is missing.
- `X-Robots-Tag` noindex is missing.
- A payload scan finds `stream_url`, `provider_url`, `playlist_url`, `api_key`, `token`, `secret`, or `credential`.
