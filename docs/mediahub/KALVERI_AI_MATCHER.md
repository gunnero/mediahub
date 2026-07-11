# Kalveri AI Matcher

## Purpose

The Kalveri AI media matcher helps resolve ambiguous provider source items and metadata review episodes after deterministic local matching and TMDB enrichment have reached their limits.

Kalveri AI is a fallback assistant. It is not the source of truth.

## Principles

- Local deterministic matching runs first.
- TMDB remains the canonical metadata source.
- Kalveri AI suggestions require confirmation.
- Provider items are private per user.
- Suggestions must never auto-link provider items.
- Suggestions must never auto-apply metadata corrections.
- Kalveri AI must not receive secrets, stream URLs, provider URLs, provider settings, private notes, tokens, API keys, or private watch history.

## Configuration

Kalveri AI is disabled by default:

```dotenv
KALVERI_AI_ENABLED=false
KALVERI_AI_BASE_URL=
KALVERI_AI_API_KEY=
KALVERI_AI_TIMEOUT=20
```

Real keys belong only in private runtime `.env` files.

## Services

`KalveriAIClient`

- sends structured JSON requests
- supports disabled mode
- handles timeout and HTTP failures safely
- logs only path/status summaries
- never logs the API key

`SafeAIMatchingPayloadService`

- recursively strips forbidden key fragments
- protects nested payloads before any Kalveri AI request or stored suggestion

`KalveriAIMediaMatcherService`

- builds provider-item candidate payloads
- builds metadata-review episode payloads
- stores suggestions in model metadata
- records safe media events
- requires confirmation before link/apply

## Provider Item Flow

1. Normalize source item title.
2. Search same-user canonical movies, shows, or episodes.
3. Compute a local candidate confidence.
4. If local confidence is low, call Kalveri AI with sanitized candidates.
5. Store one suggestion on the source item under `metadata.ai_match_suggestion`.
6. Show the suggestion in the Player link modal.
7. User confirms or rejects.

Confirming still calls:

```http
POST /api/v1/player/items/{item}/link
```

with explicit confirmation. The normal ownership checks still apply.

## Metadata Review Episode Flow

Kalveri AI may suggest corrected TMDB season and episode numbers for rows in the metadata review queue.

Ask:

```bash
php artisan mediahub:ai-match-review-episode {episode_id}
```

Apply after human review:

```bash
php artisan mediahub:apply-review-match {episode_id} --season=1 --episode=2
```

The ask command stores `metadata.ai_review_suggestion` only. It does not set `tmdb_id`, clear failures, or change `metadata_review_status`.

## API

Authenticated routes:

- `POST /api/v1/player/items/{item}/ai-match`
- `POST /api/v1/player/items/{item}/ai-match/reject`

Both routes are user-scoped. User A cannot request or reject AI matches for User B's provider item.

## Events

Stable event types:

- `ai.match.requested`
- `ai.match.suggested`
- `ai.match.confirmed`
- `ai.match.rejected`

Source:

- `ai`

Events store summaries only. They do not store prompts, provider URLs, stream URLs, credentials, or secrets.

## Frontend

The Player link modal includes:

- Ask Kalveri AI
- suggested match
- confidence
- reason
- confirm through the existing link checkbox
- reject suggestion

The UI does not expose stream URLs or provider URLs.

## Current Non-Goals

- recommendations
- Kalveri AI memory ingestion
- automatic linking
- automatic metadata correction
- batch AI review
- cross-user matching
