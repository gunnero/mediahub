# MovieDesk identity and domain rollout

MovieDesk uses the tagline “Every story, remembered.” and the existing
dark cinematic design. The public address is
`https://moviedesk.aleksandardimovski.me`. The new name appears in the app,
sign-in screen, public links, admin panel, installable app, push notifications,
calendars, and downloads.

## Compatibility

- Existing browser storage keys, export schemas, calendar event IDs and
  service worker cache prefixes remain readable. Brand assets and the manifest
  use versioned URLs to refresh installed icons.
- Existing `APP_NAME` and `MAIL_FROM_NAME` values of MediaHub or ReelAtlas
  display as MovieDesk. Keep the underlying environment values and their
  session, cache and Redis prefixes during the move.
- Operational commands and repository paths continue to use their established
  names. The domain change requires no database migration.

## Domain cutover

1. Sync pending offline watches on the old origin before retiring it.
2. Add the new hostname to the existing ingress and verify HTTPS reaches the
   same runtime, database and uploads.
3. Back up the existing server configuration and set `APP_URL` to the new
   HTTPS URL. Keep secure cookies scoped to the requesting host. Regenerate the
   configuration and route caches as the existing application owner.
4. Verify sign-in, CSRF, library and episode URLs, profile and invitation links,
   calendar subscriptions, app installation and logout on the new hostname.
5. Retire the old site routes and web DNS records after the new address is
   working. Update any monitors using the old URL.

Browser sessions, push registrations and offline storage are origin-specific.
Sign in again, reinstall or re-save offline pages, and re-enable push on the
new hostname as needed. Update saved calendar subscription URLs.
