# MediaHub Product UX Audit

Date: 2026-07-05

Scope: Product Design Sprint 001. This audit covers the existing React dashboard experience only: login, dashboard, detail modal, Player tab, timeline, alerts, and visible admin entry points. It does not propose backend architecture changes.

## Product Direction

MediaHub should feel like the user's entertainment memory, not a TV Time clone, IPTV dashboard, or technical library manager.

Target feeling:

- cinematic
- calm
- premium
- fast
- personal
- private
- not cluttered
- not generic SaaS

## What Feels Good

- The dark poster-led visual system already fits the media domain.
- Same-origin Laravel auth keeps the app feeling like one product instead of two systems.
- The detail modal brings ratings, notes, watch history, provider link state, and public metadata into one place.
- The provider/player layer respects the product rule that sources are private per user.
- The timeline data model is a strong foundation for an emotional activity diary.
- Manual tracking still works without a provider, which keeps MediaHub useful for users who only want a personal history.

## What Feels Confusing

- "Timeline" sounds technical; "Entertainment diary" better explains why the feed matters.
- Provider language can sound like infrastructure instead of a private source the user owns.
- Raw source labels such as `manual` feel like database values when shown to normal users.
- The detail modal had useful features, but section names made it feel like a CRUD panel.
- Source items were mixed together, so linked and unlinked states required too much scanning.
- Manual watch actions were terse; "Add to watch history" is clearer than "Mark watched".

## What Feels Too Technical

- Raw event source names.
- Provider/source status language without privacy reassurance.
- Player progress controls labeled primarily by seconds.
- "Link source item" without enough explanation of how it protects permanent watch history.
- Settings placeholder copy that explains local file hygiene; this is useful for development but should not stay user-facing forever.

## What Should Be Hidden From Normal Users

- Stream URLs, provider URLs, playlist URLs, credentials, tokens, API keys, and hashes.
- Raw event type names unless the user is in an admin/debug surface.
- Import internals, GDPR file paths, SQLite paths, and generated JSON paths.
- Provider implementation detail beyond source name, status, item count, and link state.
- Laravel, Filament, TMDB, queue, and cache details.

## What Should Feel More Emotional

- The home screen should make "Continue Watching" and recent memories feel primary.
- The activity feed should feel like a private diary, not telemetry.
- Ratings should feel like the user's taste profile, not just a number field.
- Notes should feel private and intentional.
- Empty states should tell the user what memory will be created next.
- Provider linking should explain the benefit: permanent watch history survives provider changes.

## Completed Sprint Fixes

1. Renamed the timeline surface to "Entertainment diary".
2. Added human-readable event source labels.
3. Preserved Today, Yesterday, This week, and Earlier event groups.
4. Added a detail-modal diary snippet for the selected title.
5. Renamed rating/note sections to "Your rating" and "Private memory".
6. Clarified manual watch actions with "Add to watch history" and "Remove manual watch".
7. Clarified provider privacy copy in the Player tab.
8. Split source items into "Linked to library" and "Needs linking".
9. Added a clearer unlinked playback warning.
10. Added subtle hover, focus, modal, and reduced-motion polish.

## Top 10 UX Fixes Still Worth Doing

1. Add a dedicated library browse/search screen for movies and shows instead of relying on shelves.
2. Replace seconds-only playback controls with friendlier elapsed/runtime display.
3. Add a proper empty dashboard state for brand-new users.
4. Add keyboard focus trapping inside modals.
5. Add an "import complete" summary screen after TV Time import.
6. Add safer confirmation copy for deleting providers.
7. Add a user-facing metadata status only where it helps, not everywhere.
8. Add a "recent memories" detail drawer from the diary feed.
9. Add poster/backdrop loading skeletons.
10. Add browser-level smoke tests for login, details, player, and diary.

## Accessibility Risks

- Modal focus is improved, but full focus trapping is still not implemented.
- Player progress inputs are functional but not yet the most accessible media-control pattern.
- Poster-only shelves need careful alt text strategy once more imagery is introduced.
- Color contrast should be checked again after any visual redesign.
- Mobile navigation is compact and should be tested with real touch targets before public launch.

## Risks

- Over-polishing before metadata enrichment can make sparse libraries feel underwhelming.
- Player UI can accidentally feel like an IPTV product if provider privacy and ownership copy is weakened.
- Showing too many technical states will reduce trust for normal users.
- Hiding too much state can hurt debugging during staging.
- Future AI/recommendation UI should not appear before the canonical media and event data are stable.
