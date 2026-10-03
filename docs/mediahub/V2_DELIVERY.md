# MediaHub V2 delivery

All ten approved updates are implemented on `feature/mediahub-v2-ten-updates`.
They extend the existing dark, compact interface. Verification uses isolated
synthetic data; production publication is a separate deployment step.

## Delivered behavior

| Update | Where to use it | Delivered behavior |
| --- | --- | --- |
| Watch-history editor | Title details → Watch History | Edit or remove one watch, update diary/totals, undo within 30 minutes; conflicting newer changes cannot be overwritten. |
| Personalized queue | Home → Manage your queue | Pin or pause shows, find the next aired unwatched episode, estimate catch-up time, choose a 30/45/60-minute budget. |
| Tailored discovery | Discover → For you / Filters | Genre, year, language, runtime and rating filters; recommendations explain their genre match; hide watched titles, dismiss suggestions, restore dismissals. |
| Library management | Movies / Shows → Library tools | Select a page or individual titles; manage watchlists, tags, pins, active/paused/dropped status and list membership; save reusable filters. Profile favorites are searchable. |
| Smart collections | Lists → Create a smart collection | Live genre, runtime, tag and watch-state rules; descriptions and cover accents; explicit share links that can be replaced or revoked. |
| Release planning | Calendar → Plan reminders and notifications | Title reminders, one-day snooze, timezones, quiet hours, weekly digest in Alerts, revocable calendar subscriptions and optional device push. |
| Import and recovery | Settings → Import & Export | Upload, preview duplicate counts, explicitly merge, preserve existing annotations, show results and undo against a verified recovery snapshot. JSON round trips retain smart rules and library preferences. |
| Viewing story | Statistics | Date ranges, year comparisons, daily activity tiles, monthly genre/rating trends and a downloadable PNG recap. |
| Friends and movie nights | Friends | Explicit activity opt-in, accepted-friend recommendations, spoiler concealment, room invitations, collaborative suggestions and voting; authors/owners can remove suggestions. |
| Mobile and offline | Library page → Save this page offline | Installable application shell, selected pages on the device, dated pending watches, automatic reconnect/manual sync, account checks and idempotent server receipts. |

## Product boundaries

- Queue suggestions use regular aired episodes; specials are excluded. Missing
  episode runtimes use show runtime when available and are marked as estimates.
  Paused/dropped shows can be resumed through Library tools.
- Discovery needs the existing TMDB configuration. Recommendations use watched
  and highly rated genres; there is no external AI service or AI credential.
- Smart collections display the first 100 matches with a truncation notice.
  Shared collections publish only the chosen description and title metadata.
- Reminders and weekly digests appear in Alerts. Browser push additionally needs
  HTTPS, server keys, a supported browser push service and device permission.
  No email delivery was added. Quiet hours defer delivery; they do not discard it.
  Calendar feeds cover seven days before today through 80 days ahead.
- Imports accept MediaHub JSON exports or the existing supported TV Time
  JSON/SQLite format, up to 10 MB. They do not accept ZIP files or arbitrary
  third-party schemas. Exact identities are preferred; ambiguous matches stop
  the import. Existing title details and ratings are preserved. New lists are
  private, and existing share tokens are never restored from an export.
- Upload/merge/recovery report their current stage and final counts. Merges run
  synchronously in one transaction. Previews and recovery snapshots are encrypted
  with the application key. Recovery refuses to overwrite subsequent library
  changes, including favorites and playback relationships.
- Statistics use the selected user timezone. Rating periods refer to when a
  title was last rated. “Shows caught up” is explicitly all-time. Heatmap tiles
  show days with activity; genre trends currently cover movie watches.
- Activity sharing starts from opt-in and hides again when disabled. Friends
  must accept room invitations. Blocking or removing friendships revokes access.
  Private notes, ratings, raw exports and provider data are not in the feed.
- Offline storage is explicit and local to the browser: up to 20 saved pages and
  100 pending watches. It contains title text and IDs, not artwork or video.
  Logging works for movies and individual episodes; History pages can provide
  those episode entries. Signing out or clearing device data removes the saved
  pages/outbox. An expired session must be renewed before synchronization.
  Offline reload requires the production build; the Vite development server does
  not cache its hot-reload shell.

## Release plan

Seven new migrations add private tables and nullable/defaulted columns. No old
migration was changed. `scripts/release-migrations.json` records exact SHA-256
checksums. `scripts/migration-plan.py` rejects modified/deleted old migrations,
unknown additions and checksum mismatches before production checkout changes.
The deploy script applies only the migration paths in that verified plan.

The existing release workflow retains the prior source/runtime, creates and
verifies a SQLite backup, enters maintenance mode, fast-forwards the release,
installs locked dependencies, applies the reviewed migrations and rebuilds caches.
It stages frontend assets with explicit public permissions and checks their bytes
through HTTP before publication. The new service worker is published after the
new index and checked through HTTP as well.

`php artisan mediahub:configure-push` creates persistent VAPID keys under
`storage/app/private/webpush/keys.json` if neither a key file nor configured keys
exist. The directory is private and the file uses mode 0600. Existing keys are
preserved and included in future private release backups. Alternatively configure
`WEBPUSH_PUBLIC_KEY`, `WEBPUSH_PRIVATE_KEY` and `WEBPUSH_SUBJECT`. Run
`php artisan config:cache` after configuring keys. No keys belong in source or a
public release package.

The application scheduler must invoke Laravel `schedule:run` each minute as the
application owner. The registered reminder command runs every five minutes with
overlap protection; the existing episode-catalog schedule remains in place.
Verify this scheduler and its private logs during rollout. Do not create a second
scheduler if one already exists. Users choose their timezone in Calendar.

Before publication, review the committed migration manifest, run release
preflight, confirm the existing database backup and check free space. After
publication, check login, a title history, library filters, discovery, imports,
calendar settings, a shared collection and an offline reload. Actual remote push
delivery and external calendar refreshes need a real-device rollout check.

If rollout fails, the script reports its backup and maintenance state. Keep the
application closed to writes until the failure is understood. These additive
migrations do not require an automatic destructive rollback. Prefer a forward
repair; if a full restore is necessary, restore the matching database, source and
runtime together from the verified private backup, preserve configuration and
push keys, rebuild caches, then verify before reopening. Do not run `migrate:reset`
or downgrade tables containing new user activity.

## Verification

Automated checks cover ownership, undo conflicts and diary consistency,
paused/pinned queues, atomic bulk updates, smart shares and revocation, discovery
filters, quiet hours, orphaned reminders, calendar escaping, push-key preservation,
friendship/invitation boundaries, spoiler controls, date ranges, import duplicate
previews and recovery, JSON round trips, and offline retries/account mismatches.
The existing application suite remains part of the release checks.

Browser checks use synthetic accounts and exercise mobile history editing/undo,
bulk tags, smart collection sharing, reminders, calendar links and offline sync;
desktop upload/preview/merge/recovery, movie-night voting/removal and saved views;
and a production-build offline reload with a pending watch preserved across a
second reload and synchronized after reconnection.

Verified locally: 183 backend tests (1,505 assertions), 119 frontend tests and
15 release-workflow tests passed. The production build, PHP style checks, route
cache, migration checksums and public-evidence scan passed. Composer validation
and its locked-dependency advisory audit passed. Browser checks reported no
uncaught errors or horizontal overflow at 390px and 1440px.

Run frontend tests/build, backend tests, release-workflow Python tests, PHP style
checks, shell syntax checks and `scripts/check-public-evidence.sh` before release.
The local review does not represent a production deployment or a live push test.
