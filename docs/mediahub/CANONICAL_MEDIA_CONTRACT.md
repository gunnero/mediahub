# MediaHub Canonical Media Contract

## Purpose

MediaHub separates permanent media identity from temporary provider state.

The rule is simple:

Provider items are temporary. Canonical media and watch history are permanent.

This contract is the implementation guide for the current Laravel backend and the product rule future work must preserve.

## Ownership

Every user-owned table must be scoped by `user_id`. A row carrying the current `user_id` is not enough when it points at another table; services must validate the full ownership graph before returning, linking, playing, updating, deleting, exporting, or restoring data.

Current user-owned surfaces:

- canonical media
- watch history
- ratings
- notes
- media events
- alerts
- playback sources
- playback source items
- media links
- playback sessions
- playback progress

There is no global stream catalog and no shared provider cache.

## Canonical Media

Canonical media is the permanent identity layer owned by a user.

Tables:

- `movies`
- `shows`
- `episodes`

Current note: there is no `seasons` table yet. Seasons are represented on `episodes` through `season_number`. If a future sprint adds seasons, the table must be user-scoped and must not depend on any provider source.

Rules:

- Canonical records belong to one user.
- Canonical records may be created manually, imported from TV Time, enriched by metadata providers later, or linked from a user-owned provider item.
- Canonical records must not store stream URLs, provider credentials, playlist URLs, API keys, or private provider settings.
- Provider deletion must not delete canonical records.

## Metadata Enrichment

Metadata enrichment is public canonical identity data, not provider state.

Current optional metadata provider:

- TMDB

Supported canonical metadata fields:

- `tmdb_id`
- `imdb_id`
- `tvdb_id`
- `original_title`
- `overview`
- `poster_path`
- `backdrop_path`
- `release_date` or `first_air_date`
- `genres`
- `runtime`
- `status`
- `vote_average`
- `metadata`
- `metadata_refreshed_at`

Rules:

- TMDB enrichment is optional and disabled by default.
- The app must work with no TMDB key configured.
- Real TMDB API keys must live only in private runtime `.env` files.
- Enrichment must be additive and must not blindly overwrite user/import-owned fields such as current titles or local poster URLs.
- Public poster/backdrop URLs may be exposed to the authenticated user's dashboard and detail payloads.
- Metadata payloads must not include stream URLs, playlist URLs, provider credentials, API keys, secrets, or private provider settings.
- Metadata commands must print summary counts only, not titles or private library contents.
- Metadata enrichment must be scoped by `user_id` when processing a user library.

## User Activity

User activity is permanent personal history attached to canonical media.

Tables:

- `movie_watches`
- `episode_watches`
- `ratings`
- `notes`
- `playback_sessions`
- `media_events`

Rules:

- Manual watch history works without any provider.
- Provider playback may update canonical history only when the provider item is linked to same-user canonical media.
- Ratings are per user and per canonical media item.
- Notes are private per user and per canonical media item.
- Ratings and notes survive provider changes and provider deletion.
- Duplicate provider completion updates for the same playback session must not create duplicate canonical watch rows.
- Media events are permanent user-scoped activity records and must survive provider deletion unless the user account itself is deleted.

## Provider Layer

Provider rows are temporary pointers into a user-owned source.

Tables:

- `playback_sources`
- `playback_source_items`
- `media_links`
- `playback_progress`

Rules:

- Provider sources are private per user.
- Provider settings are encrypted and hidden.
- Provider item stream URLs are encrypted and hidden.
- Provider items can link only to the same user's canonical `movies`, `shows`, or `episodes`.
- Unlinked provider playback may store source-only progress, but it must not create canonical watch history.
- Linked provider playback may create or update canonical watch history.
- Deleting a provider may delete provider source rows, source items, links, sessions, and progress.
- Deleting a provider must not delete canonical media, watches, ratings, notes, or dashboard stats derived from those rows.

## Dashboard Payload

`GET /api/v1/dashboard` must remain safe for the authenticated user and frontend-compatible.

The payload may include additive stats such as:

- `manualWatchesCount`
- `autoTrackedWatchesCount`
- `linkedProviderItemsCount`
- `unlinkedProviderItemsCount`
- `unsyncedSourceOnlyProgressCount`
- `ratingsCount`
- `notesCount`
- `timeline`

The dashboard payload must never expose:

- `stream_url`
- `streamUrl`
- `playbackUrl`
- `playlist_url`
- provider credentials
- API keys
- provider secrets
- raw provider settings

The authenticated play endpoint is the only current endpoint that returns a playback URL, and only after same-user provider item ownership checks pass.

## Backup And Restore

`mediahub:backup-user` creates a provider-safe private JSON backup under ignored Laravel storage.

Included by default:

- movies
- shows
- episodes
- movie watches
- episode watches
- ratings
- notes
- safe media link references
- safe playback progress references

Excluded by default:

- stream URLs
- playlist URLs
- provider settings
- provider credentials
- API keys
- secrets
- raw imported GDPR files

`mediahub:restore-user` accepts backup files only from private MediaHub backup storage. Restore writes restored rows to the target `user_id`, remaps canonical IDs, and does not import provider secrets.

## Admin Safety

Filament admin is limited to active `owner` and `admin` users.

Admin screens may inspect provider metadata/status and stream hashes. They must not expose raw stream URLs or raw provider URLs. Sensitive provider actions should create audit logs with sanitized metadata.

## Current Guarantees

Feature tests currently prove:

- users can rate movies, shows, and episodes
- users can create private notes on movies, shows, and episodes
- users cannot annotate another user's media
- manual watch history works without a provider
- unlinked provider playback saves source-only progress without canonical watches
- linked provider playback creates canonical watches
- duplicate completion updates for one session avoid duplicate watch rows
- provider deletion keeps watches, ratings, notes, and dashboard stats
- dashboard payloads do not expose provider/stream URL fields
- backup files exclude raw streams, playlists, API keys, and provider secrets
- restore keeps user isolation
- media events are user-scoped and sanitize sensitive provider/stream/API keys
- dashboard timeline payloads do not expose provider/stream URL fields

## Future Work

Future sprints may add:

- `seasons`
- richer metadata conflict resolution
- user-facing manual metadata correction
- richer manual library APIs
- user-facing import upload
- release calendars and notifications

All future work must preserve the separation between canonical media, user activity, and provider state.
