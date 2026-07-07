# MediaHub Library Browser Plan

## Goal

Make the canonical MediaHub library visible and usable without changing the provider architecture or exposing private source data.

## Current Missing UX

- The Movies and Shows sections are shelf views, not full libraries.
- There is no browseable list for all movies, all shows, all seasons, or all episodes.
- Show detail does not expose a season/episode browser.
- Watch history exists in the database but is not a first-class user screen.
- Global search filters only dashboard shelves, so most movies, shows, and episodes are unreachable from the UI.
- Provider playback exists, but provider state is not useful unless the canonical item can be found and opened.

## Desired Navigation

- Movies -> Movie Library -> Movie Detail
- Shows -> Show Library -> Show Detail -> Season List -> Episode List -> Episode Detail
- History -> Watched movies/episodes timeline/list
- Search -> Movies, Shows, Episodes

## Screens Needed

1. Movie Library
   - Search, status filters, sort controls, grid/list-friendly cards.
   - Cards show poster, title, year, runtime, watched status, rating, note indicator, provider link indicator, and metadata status.
2. Show Library
   - Search, status filters, sort controls, progress-forward cards.
   - Cards show poster, title, progress, watched/aired count, latest watched date, rating, note indicator, provider link indicator, and metadata status.
3. Show Detail / Season Browser
   - Existing modal remains the detail surface.
   - Add season summaries and grouped episode rows to the show detail payload/UI.
4. Episode Detail
   - Reuse the existing modal style.
   - Episode rows open the existing episode detail endpoint.
5. Watch History
   - Paginated list of watched movies and episodes.
   - Filters for all, movies, and episodes.
   - Rows open the correct detail modal.
6. Global Search
   - Search across movies, shows, and episodes.
   - Group results by type.
   - Provider items stay out of global search unless deliberately separated later.

## API Gaps

Add authenticated, user-scoped endpoints under `/api/v1`:

- `GET /library/movies`
- `GET /library/shows`
- `GET /library/history`
- `GET /library/search`

Harden existing endpoints:

- `GET /library/shows/{show}` should return season summaries and episodes grouped by season.
- `GET /library/episodes/{episode}` should include enough show/season context for the modal.

## Frontend Gaps

- Replace shelf-only Movies view with a library browser backed by `/api/v1/library/movies`.
- Replace shelf-only Shows view with a library browser backed by `/api/v1/library/shows`.
- Add a History section backed by `/api/v1/library/history`.
- Upgrade global search to call `/api/v1/library/search` and show grouped results.
- Extend the detail modal to show seasons/episodes when the selected item is a show.

## Data Safety Rules

- Every backend query must scope by `user_id`.
- Payloads must not include `stream_url`, `playbackUrl`, `provider_url`, `playlist_url`, credentials, passwords, API keys, tokens, or secrets.
- Provider status can be exposed only as booleans/counts.
- Provider items do not appear in global search in this sprint.
- The browser must use canonical media records, not a shared stream catalog.

## Acceptance Criteria

- Authenticated user can browse all own movies with search/filter/sort/pagination.
- Authenticated user can browse all own shows with search/filter/sort/pagination.
- Authenticated user can open a show and see seasons plus episode rows.
- Authenticated user can open an episode detail from the season browser.
- Authenticated user can browse paginated watch history without loading all rows at once.
- Global search returns movies, shows, and episodes grouped by type.
- Cross-user access returns 404 or empty results.
- Payload sensitive-key scans pass.
- Existing manual watch, rating, note, provider/player flows continue to work.
