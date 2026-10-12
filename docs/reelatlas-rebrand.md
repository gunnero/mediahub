# ReelAtlas identity and domain rollout

The product is **ReelAtlas**, with the owner-selected domain `reelatlas.app`
and the tagline **Every story, remembered.** The visual identity retains the
approved dark cinematic surfaces and cool blue accent. Its original compass
mark includes a play symbol and is supplied as SVG plus installable-app PNGs.

The rebrand covers navigation, sign-in, public profiles and invitations,
settings, offline installation, push notifications, calendar labels, viewing
recaps, download filenames, administrative branding and repository presentation.
It requires no database migration. Historical design/audit documents and
screenshots describe the identity that existed when they were produced.

## Compatibility

- Existing `mediahub-*` local-storage keys and offline queues stay readable.
- Export and backup schema identifiers stay unchanged, so earlier exports can
  still be imported. Only the user-visible download filename changes.
- Calendar event UIDs stay unchanged to avoid creating duplicate subscribed events.
- Existing service-worker scope/cache prefixes and notification tags stay intact.
  Brand assets and the manifest use new versioned URLs to refresh cached icons.
- Command names, environment prefixes, internal class names, deployment profile
  paths and the `gunnero/mediahub` GitHub repository remain stable.
- Legacy `APP_NAME=MediaHub` displays as ReelAtlas without changing the underlying
  environment-derived session-cookie, cache or Redis prefixes. Explicit custom
  application and mail sender names remain supported. Mail addresses and transport
  are not changed by the rebrand.
- TV Time references identify supported imports, not the product brand.

## Activate the new domain

The selected domain is a branding decision, not evidence that DNS or TLS is live.
Release the rebrand through the existing reviewed deployment workflow first.

1. Confirm the domain belongs to the operator and prepare its DNS zone in the
   existing hosting account. Preserve any existing mail and verification records.
2. Add the hostname to the existing site's ingress using its established private
   origin, runtime owner, database and uploads. Do not create a second empty app.
3. Validate HTTPS and the site on the new hostname before changing canonical URLs.
   `.app` requires functioning HTTPS in browsers.
4. Update the deployment profile's live URL and the application's `APP_URL` only
   when the domain works. Keep cookies host-scoped, check allowed session origins,
   and regenerate application configuration/route caches as the application owner.
   Preserve the application key and explicitly configured session/cache prefixes.
5. Check sign-in, CSRF, library and episode URLs, profile/invitation links,
   calendar subscriptions, icon installation, push registration and logout.
   URLs must be generated for the active deployment, not hardcoded in frontend code.
6. Keep the old origin available during the transition. Browser sessions, installed
   apps, push subscriptions and offline storage belong to an origin and do not move
   automatically between domains. Sync pending offline watches on the old origin
   first, then sign in and re-save offline pages on the new one. Re-enable push and
   update calendar subscriptions as needed. Do not clear the old origin's storage.
7. Introduce redirects only after transition checks pass, preserving deep paths and
   queries. Retain access to the old offline/sync flows until pending data is handled.

Back up the affected private configuration before hostname changes and retain the
old ingress as the recovery path. Actual hosting values and deployment receipts
belong in private operations notes, not the public repository.
