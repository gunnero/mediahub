<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaLink;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\PlaybackProgress;
use App\Models\PlaybackSession;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlaybackLibraryService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function sourcesFor(User $user): array
    {
        return PlaybackSource::forUser($user)
            ->latest('updated_at')
            ->get()
            ->map(fn (PlaybackSource $source): array => $this->sourceSummary($source))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function playerPayloadFor(User $user): array
    {
        $hasProvider = PlaybackSource::forUser($user)->active()->exists();

        return [
            'enabled' => $hasProvider,
            'emptyState' => $hasProvider
                ? null
                : 'Attach your own source to enable playback and automatic tracking.',
            'sourceItems' => $hasProvider ? $this->sourceItemsFor($user) : [],
            'linkedItems' => $hasProvider ? $this->linkedItemsFor($user) : [],
            'unlinkedItems' => $hasProvider ? $this->unlinkedItemsFor($user) : [],
            'continueWatching' => $hasProvider ? $this->continueWatchingFor($user) : [],
        ];
    }

    public function startPlayback(User $user, PlaybackSourceItem $item): PlaybackSession
    {
        $this->assertOwnedItem($user, $item);
        $item->loadMissing(['source', 'mediaLink']);

        if ($item->source?->status !== 'active' || $item->status !== 'available') {
            throw ValidationException::withMessages([
                'item' => 'This source item is not available for playback.',
            ]);
        }

        if (! $item->stream_url) {
            throw ValidationException::withMessages([
                'item' => 'This source item does not have a playable stream.',
            ]);
        }

        return PlaybackSession::create([
            'user_id' => $user->id,
            'playback_source_id' => $item->playback_source_id,
            'playback_source_item_id' => $item->id,
            'media_link_id' => $item->mediaLink?->id,
            'status' => 'playing',
            'started_at' => now(),
            'last_position_seconds' => 0,
        ]);
    }

    /**
     * @param  array{movie_id?:int|null,show_id?:int|null,episode_id?:int|null}  $data
     */
    public function link(User $user, PlaybackSourceItem $item, array $data): MediaLink
    {
        $this->assertOwnedItem($user, $item);

        $targets = array_filter([
            'movie_id' => $data['movie_id'] ?? null,
            'show_id' => $data['show_id'] ?? null,
            'episode_id' => $data['episode_id'] ?? null,
        ], fn ($value): bool => filled($value));

        if (count($targets) !== 1) {
            throw ValidationException::withMessages([
                'media' => 'Select exactly one movie, show, or episode to link.',
            ]);
        }

        $movieId = isset($targets['movie_id']) ? $this->ownedMovie($user, (int) $targets['movie_id'])->id : null;
        $showId = isset($targets['show_id']) ? $this->ownedShow($user, (int) $targets['show_id'])->id : null;
        $episodeId = isset($targets['episode_id']) ? $this->ownedEpisode($user, (int) $targets['episode_id'])->id : null;

        return MediaLink::updateOrCreate([
            'user_id' => $user->id,
            'playback_source_item_id' => $item->id,
        ], [
            'movie_id' => $movieId,
            'show_id' => $showId,
            'episode_id' => $episodeId,
            'linked_at' => now(),
        ]);
    }

    /**
     * @param  array{position_seconds?:int,duration_seconds?:int|null,completed?:bool|null,status?:string|null}  $data
     */
    public function updateSession(User $user, PlaybackSession $session, array $data): PlaybackSession
    {
        $this->assertOwnedSession($user, $session);

        return DB::transaction(function () use ($data, $session, $user): PlaybackSession {
            $completed = (bool) ($data['completed'] ?? false);

            $session->forceFill([
                'last_position_seconds' => (int) ($data['position_seconds'] ?? $session->last_position_seconds),
                'duration_seconds' => $data['duration_seconds'] ?? $session->duration_seconds,
                'status' => $completed ? 'completed' : ($data['status'] ?? $session->status),
                'ended_at' => $completed ? now() : $session->ended_at,
            ])->save();

            $progress = PlaybackProgress::updateOrCreate([
                'user_id' => $user->id,
                'playback_source_item_id' => $session->playback_source_item_id,
            ], [
                'playback_session_id' => $session->id,
                'movie_id' => $session->mediaLink?->movie_id,
                'episode_id' => $session->mediaLink?->episode_id,
                'position_seconds' => $session->last_position_seconds,
                'duration_seconds' => $session->duration_seconds,
                'completed' => $completed,
            ]);

            if ($progress->completed) {
                $this->recordCanonicalWatch($user, $session, $progress);
            }

            return $session->refresh();
        });
    }

    public function deleteSource(User $user, PlaybackSource $source): void
    {
        if ($source->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        $source->delete();
    }

    /**
     * @param  array{watched_at?:string|null,runtime?:int|null}  $data
     */
    public function manuallyTrackMovie(User $user, Movie $movie, array $data): MovieWatch
    {
        if ($movie->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        return MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => $data['watched_at'] ?? now(),
            'runtime' => $data['runtime'] ?? $movie->runtime,
            'watch_count' => 1,
            'source' => 'manual',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceSummary(PlaybackSource $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'providerType' => $source->provider_type,
            'status' => $source->status,
            'metadata' => $source->metadata ?? [],
            'lastSyncedAt' => $source->last_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceItemsFor(User $user): array
    {
        return PlaybackSourceItem::forUser($user)
            ->with(['source', 'mediaLink'])
            ->whereHas('source', fn (Builder $query) => $query->forUser($user)->active())
            ->latest('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (PlaybackSourceItem $item): array => $this->sourceItemSummary($item))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linkedItemsFor(User $user): array
    {
        return MediaLink::forUser($user)
            ->with([
                'sourceItem' => fn (Builder $query) => $query->forUser($user),
                'movie' => fn (Builder $query) => $query->forUser($user),
                'show' => fn (Builder $query) => $query->forUser($user),
                'episode' => fn (Builder $query) => $query->forUser($user),
            ])
            ->whereHas('sourceItem', fn (Builder $query) => $query
                ->forUser($user)
                ->whereHas('source', fn (Builder $sourceQuery) => $sourceQuery->forUser($user)))
            ->latest('linked_at')
            ->limit(20)
            ->get()
            ->map(fn (MediaLink $link): array => [
                'id' => $link->id,
                'sourceItemId' => $link->playback_source_item_id,
                'sourceTitle' => $link->sourceItem?->title,
                'movieId' => $link->movie_id,
                'showId' => $link->show_id,
                'episodeId' => $link->episode_id,
                'canonicalTitle' => $link->movie?->title ?? $link->show?->title ?? $link->episode?->title,
                'linkedAt' => $link->linked_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unlinkedItemsFor(User $user): array
    {
        return PlaybackSourceItem::forUser($user)
            ->whereHas('source', fn (Builder $query) => $query->forUser($user)->active())
            ->whereDoesntHave('mediaLink')
            ->latest('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (PlaybackSourceItem $item): array => $this->sourceItemSummary($item))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function continueWatchingFor(User $user): array
    {
        return PlaybackProgress::forUser($user)
            ->with(['sourceItem' => fn (Builder $query) => $query->forUser($user)])
            ->whereHas('sourceItem', fn (Builder $query) => $query
                ->forUser($user)
                ->whereHas('source', fn (Builder $sourceQuery) => $sourceQuery->forUser($user)->active()))
            ->where('completed', false)
            ->latest('updated_at')
            ->limit(12)
            ->get()
            ->map(fn (PlaybackProgress $progress): array => [
                'id' => $progress->id,
                'sourceItemId' => $progress->playback_source_item_id,
                'title' => $progress->sourceItem?->title,
                'kind' => $progress->sourceItem?->kind,
                'positionSeconds' => $progress->position_seconds,
                'durationSeconds' => $progress->duration_seconds,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceItemSummary(PlaybackSourceItem $item): array
    {
        return [
            'id' => $item->id,
            'sourceId' => $item->playback_source_id,
            'sourceName' => $item->source?->name,
            'kind' => $item->kind,
            'title' => $item->title,
            'status' => $item->status,
            'linked' => (bool) $item->mediaLink,
            'lastSeenAt' => $item->last_seen_at?->toIso8601String(),
        ];
    }

    private function assertOwnedItem(User $user, PlaybackSourceItem $item): void
    {
        $item->loadMissing('source');

        if ($item->user_id !== $user->id || $item->source?->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }

    private function assertOwnedSession(User $user, PlaybackSession $session): void
    {
        $session->loadMissing(['source', 'sourceItem.source', 'mediaLink']);

        if (
            $session->user_id !== $user->id
            || $session->source?->user_id !== $user->id
            || $session->sourceItem?->user_id !== $user->id
            || $session->sourceItem?->playback_source_id !== $session->playback_source_id
            || $session->sourceItem?->source?->user_id !== $user->id
            || ($session->mediaLink && $session->mediaLink->user_id !== $user->id)
            || ($session->mediaLink && $session->mediaLink->playback_source_item_id !== $session->playback_source_item_id)
        ) {
            throw new ModelNotFoundException;
        }
    }

    private function ownedMovie(User $user, int $id): Movie
    {
        return Movie::forUser($user)->findOrFail($id);
    }

    private function ownedShow(User $user, int $id): Show
    {
        return Show::forUser($user)->findOrFail($id);
    }

    private function ownedEpisode(User $user, int $id): Episode
    {
        return Episode::forUser($user)->findOrFail($id);
    }

    private function recordCanonicalWatch(User $user, PlaybackSession $session, PlaybackProgress $progress): void
    {
        if ($progress->movie_id) {
            $movie = Movie::forUser($user)->find($progress->movie_id);

            if ($movie) {
                MovieWatch::firstOrCreate([
                    'user_id' => $user->id,
                    'movie_id' => $movie->id,
                    'watched_at' => $session->ended_at,
                ], [
                    'runtime' => $session->duration_seconds ? (int) round($session->duration_seconds / 60) : 0,
                    'watch_count' => 1,
                    'source' => 'provider',
                ]);
            }
        }

        if ($progress->episode_id) {
            $episode = Episode::forUser($user)->with('show')->find($progress->episode_id);

            if ($episode) {
                EpisodeWatch::firstOrCreate([
                    'user_id' => $user->id,
                    'episode_id' => $episode->id,
                    'watched_at' => $session->ended_at,
                ], [
                    'show_id' => $episode->show_id,
                    'runtime' => $session->duration_seconds ? (int) round($session->duration_seconds / 60) : ($episode->runtime ?? 0),
                    'source' => 'provider',
                ]);
            }
        }
    }
}
