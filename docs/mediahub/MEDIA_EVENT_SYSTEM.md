# MediaHub Media Event System

## Purpose

Media events are the user-scoped activity memory for MediaHub.

They record meaningful library activity without storing provider secrets, stream URLs, playlist URLs, credentials, or raw private imports. The event stream supports the current dashboard timeline and becomes the future foundation for richer statistics, Kalveri AI memory, recommendations, notifications, auditability, and achievements.

## Philosophy

Canonical media and watch history are permanent. Provider items are temporary. Media events sit with permanent user activity.

Events should describe something meaningful:

- a movie was watched
- an episode was finished
- a rating changed
- a private note changed
- a provider item was linked or unlinked
- playback started or completed
- metadata was enriched
- a backup or restore completed

Events should not become a noisy telemetry dump. Routine playback progress saves should not appear in the visible timeline unless they become meaningful milestones.

## Storage

Table: `media_events`

Columns:

- `id`
- `user_id`
- `event_type`
- `subject_type`
- `subject_id`
- `actor_type`
- `actor_id`
- `occurred_at`
- `source`
- `metadata`
- timestamps

Indexes:

- `user_id`, `occurred_at`
- `user_id`, `event_type`
- `subject_type`, `subject_id`

Every query must be scoped by `user_id`.

## Event Sources

Stable sources:

- `manual`
- `player`
- `import`
- `provider`
- `metadata`
- `ai`
- `system`

## Event Types

Stable event types:

- `movie.imported`
- `show.imported`
- `episode.imported`
- `movie.watched`
- `episode.watched`
- `movie.unwatched`
- `episode.unwatched`
- `rating.created`
- `rating.updated`
- `rating.deleted`
- `note.created`
- `note.updated`
- `note.deleted`
- `provider.created`
- `provider.disabled`
- `provider.deleted`
- `provider.item.created`
- `provider.item.linked`
- `provider.item.unlinked`
- `playback.started`
- `playback.progressed`
- `playback.completed`
- `metadata.enriched`
- `ai.match.requested`
- `ai.match.suggested`
- `ai.match.confirmed`
- `ai.match.rejected`
- `backup.created`
- `restore.completed`

Do not rename these casually. Future analytics, Kalveri AI memory, notifications, and achievements will depend on stable names.

## Safety Rules

`MediaEventService` sanitizes metadata before writing.

Forbidden metadata key fragments include:

- `stream_url`
- `playbackUrl`
- `provider_url`
- `playlist_url`
- `password`
- `api_key`
- `token`
- `secret`
- `credential`

The sanitizer also strips these from nested arrays.

Event recording must not break user flows. If event writing fails, the service logs only a safe summary containing user id, event type, source, and exception class.

## Current Producers

Current event producers:

- TV Time import command
- manual movie/episode watched and unwatched actions
- rating create/update/delete
- note create/update/delete
- provider create/disable/delete
- provider source item create
- provider item link/unlink
- playback start/completion
- TMDB metadata enrichment success
- Kalveri AI match request/suggestion/confirmation/rejection
- user backup creation
- user restore completion

## API

Routes:

- `GET /api/v1/media-events`
- `GET /api/v1/media-events/recent`

Filters:

- `event_type`
- `source`
- `subject_type`
- `date_from`
- `date_to`

All routes require the authenticated Laravel session and return only the current user's events.

## Dashboard Timeline

`GET /api/v1/dashboard` includes:

- `timeline.recent`
- `timeline.todaySummary`
- `timeline.thisWeekSummary`

Timeline items contain safe display fields:

- event id
- event type
- title
- subtitle
- source
- subject type/id
- occurred time
- group: Today, Yesterday, Earlier
- sanitized metadata

The React dashboard renders a compact "Entertainment diary" panel. Empty users receive a valid empty timeline payload and a quiet user-facing empty state.

## Kalveri AI Future Use

Kalveri AI may later consume media events through structured APIs for:

- memory
- recommendations
- watch insights
- notification copy
- recap generation
- achievement suggestions

MediaHub should continue to own storage and safety. Kalveri AI should receive sanitized, scoped event payloads only.

## Non-Goals

This sprint does not add:

- Kalveri AI
- recommendations
- notification delivery
- achievement UI
- high-volume playback telemetry
- cross-user or admin-global event APIs
