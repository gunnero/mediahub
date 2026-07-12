<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Enums\ProviderSyncStatus;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaybackLibraryService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly MediaEventService $mediaEvents,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function sourcesFor(User $user): array
    {
        return PlaybackSource::forUser($user)
            ->withCount('items')
            ->latest('updated_at')
            ->get()
            ->map(fn (PlaybackSource $source): array => $this->sourceSummary($source))
            ->values()
            ->all();
    }

    /**
     * @param  array{name:string,provider_type:string,legal_confirmed?:bool}  $data
     */
    public function createSource(User $user, array $data): PlaybackSource
    {
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'provider_type' => $data['provider_type'],
            'status' => 'active',
            'sync_status' => ProviderSyncStatus::NeverSynced->value,
            'metadata' => [
                'created_from' => 'manual-ui',
                'legal_confirmed_at' => now()->toIso8601String(),
            ],
        ]);

        $this->auditLogs->record('playback_source.created', $user, $source, $user, [
            'provider_type' => $source->provider_type,
            'status' => $source->status,
        ]);
        $this->mediaEvents->record($user, MediaEventType::ProviderCreated, $source, [
            'title' => $source->name,
            'provider_type' => $source->provider_type,
            'status' => $source->status,
        ], MediaEventSource::Provider);

        return $source;
    }

    /**
     * @param  array{status:string}  $data
     */
    public function updateSourceStatus(User $user, PlaybackSource $source, array $data): PlaybackSource
    {
        $this->assertOwnedSource($user, $source);

        $source->forceFill(['status' => $data['status']])->save();

        $this->auditLogs->record('playback_source.status_changed', $user, $source, $user, [
            'provider_type' => $source->provider_type,
            'status' => $source->status,
        ]);
        if ($source->status === 'disabled') {
            $this->mediaEvents->record($user, MediaEventType::ProviderDisabled, $source, [
                'title' => $source->name,
                'provider_type' => $source->provider_type,
                'status' => $source->status,
            ], MediaEventSource::Provider);
        }

        return $source->refresh()->loadCount('items');
    }

    /**
     * @param  array{title:string,kind:string,stream_url:string,external_id?:string|null}  $data
     */
    public function createManualItem(User $user, PlaybackSource $source, array $data): PlaybackSourceItem
    {
        $this->assertOwnedSource($user, $source);

        $streamUrl = $data['stream_url'];

        $item = PlaybackSourceItem::updateOrCreate([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => $data['external_id'] ?? (string) Str::uuid(),
        ], [
            'kind' => $data['kind'],
            'title' => $data['title'],
            'status' => 'available',
            'stream_url' => $streamUrl,
            'stream_url_hash' => hash('sha256', $streamUrl),
            'metadata' => ['created_from' => 'manual-ui'],
            'match_status' => 'needs_review',
            'last_seen_at' => now(),
            'catalog_synced_at' => now(),
        ]);

        $this->auditLogs->record('playback_source_item.created', $user, $item, $user, [
            'playback_source_id' => $source->id,
            'kind' => $item->kind,
            'status' => $item->status,
        ]);
        $this->mediaEvents->record($user, MediaEventType::ProviderItemCreated, $item, [
            'title' => $item->title,
            'kind' => $item->kind,
            'playback_source_id' => $source->id,
        ], MediaEventSource::Provider);

        return $item->refresh()->loadMissing(['source', 'mediaLink']);
    }

    /**
     * @param  array{q?:string|null,source_id?:int|null,status?:string|null,linked?:bool|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function sourceItemsForUser(User $user, array $filters = []): array
    {
        $query = PlaybackSourceItem::forUser($user)
            ->with([
                'source',
                'progress',
                'mediaLink' => fn ($query) => $query->forUser($user)->with([
                    'movie' => fn ($movieQuery) => $movieQuery->forUser($user),
                    'show' => fn ($showQuery) => $showQuery->forUser($user),
                    'episode' => fn ($episodeQuery) => $episodeQuery->forUser($user),
                ]),
            ])
            ->whereHas('source', fn (Builder $sourceQuery) => $sourceQuery->forUser($user));

        if (filled($filters['source_id'] ?? null)) {
            $query->where('playback_source_id', (int) $filters['source_id']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['kind'] ?? null)) {
            $kinds = is_array($filters['kind']) ? $filters['kind'] : explode(',', (string) $filters['kind']);
            $query->whereIn('kind', array_values(array_filter($kinds)));
        }

        if (filled($filters['category'] ?? null)) {
            $query->where('category', $filters['category']);
        }

        if (filled($filters['match_status'] ?? null)) {
            $query->where('match_status', $filters['match_status']);
        }

        if (($filters['linked'] ?? null) !== null) {
            $linked = (bool) $filters['linked'];
            $linked
                ? $query->whereHas('mediaLink', fn (Builder $linkQuery) => $linkQuery->forUser($user))
                : $query->whereDoesntHave('mediaLink', fn (Builder $linkQuery) => $linkQuery->forUser($user));
        }

        if (filled($filters['q'] ?? null)) {
            $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['q'])).'%');
        }

        return $query
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (PlaybackSourceItem $item): array => $this->sourceItemSummary($item))
            ->values()
            ->all();
    }

    /**
     * @param  array{q?:string|null,type?:string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function linkTargetsFor(User $user, array $filters = []): array
    {
        $queryText = trim((string) ($filters['q'] ?? ''));
        $type = $filters['type'] ?? null;
        $targets = [];

        if (! $type || $type === 'movie') {
            $targets = array_merge($targets, Movie::forUser($user)
                ->when($queryText !== '', fn (Builder $query) => $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $queryText).'%'))
                ->orderBy('title')
                ->limit(10)
                ->get()
                ->map(fn (Movie $movie): array => [
                    'type' => 'movie',
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'subtitle' => 'Movie',
                    'meta' => $movie->runtime ? $movie->runtime.' min' : null,
                ])
                ->all());
        }

        if (! $type || $type === 'show') {
            $targets = array_merge($targets, Show::forUser($user)
                ->when($queryText !== '', fn (Builder $query) => $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $queryText).'%'))
                ->orderBy('title')
                ->limit(10)
                ->get()
                ->map(fn (Show $show): array => [
                    'type' => 'show',
                    'id' => $show->id,
                    'title' => $show->title,
                    'subtitle' => 'Show',
                    'meta' => $show->seen_episodes ? $show->seen_episodes.' watched episodes' : null,
                ])
                ->all());
        }

        if (! $type || $type === 'episode') {
            $targets = array_merge($targets, Episode::forUser($user)
                ->with(['show' => fn ($query) => $query->forUser($user)])
                ->when($queryText !== '', fn (Builder $query) => $query->where(function (Builder $nested) use ($queryText, $user): void {
                    $safeQuery = str_replace(['%', '_'], ['\%', '\_'], $queryText);

                    $nested
                        ->where('title', 'like', '%'.$safeQuery.'%')
                        ->orWhereHas('show', fn (Builder $showQuery) => $showQuery
                            ->forUser($user)
                            ->where('title', 'like', '%'.$safeQuery.'%'));
                }))
                ->orderBy('season_number')
                ->orderBy('episode_number')
                ->limit(10)
                ->get()
                ->map(fn (Episode $episode): array => [
                    'type' => 'episode',
                    'id' => $episode->id,
                    'title' => $episode->title ?: trim(($episode->show?->title ?? 'Episode').' S'.($episode->season_number ?? '?').' E'.($episode->episode_number ?? '?')),
                    'subtitle' => $episode->show?->title ?: 'Episode',
                    'meta' => trim('S'.($episode->season_number ?? '?').' E'.($episode->episode_number ?? '?')),
                ])
                ->all());
        }

        return array_slice($targets, 0, 30);
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
            'sourceItems' => $hasProvider ? $this->dashboardSourceItemsFor($user) : [],
            'linkedItems' => $hasProvider ? $this->linkedItemsFor($user) : [],
            'unlinkedItems' => $hasProvider ? $this->unlinkedItemsFor($user) : [],
            'continueWatching' => $hasProvider ? $this->continueWatchingFor($user) : [],
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function catalogFor(User $user, array $filters = []): array
    {
        $view = (string) ($filters['view'] ?? 'home');
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(60, max(1, (int) ($filters['per_page'] ?? 24)));
        $base = PlaybackSourceItem::forUser($user)
            ->with([
                'source',
                'progress',
                'mediaLink' => fn ($query) => $query->forUser($user)->with([
                    'movie' => fn ($movieQuery) => $movieQuery->forUser($user),
                    'show' => fn ($showQuery) => $showQuery->forUser($user),
                    'episode' => fn ($episodeQuery) => $episodeQuery->forUser($user),
                ]),
            ])
            ->where('status', 'available')
            ->whereHas('source', fn (Builder $query) => $query->forUser($user)->active());

        $categories = (clone $base)
            ->whereNotNull('category')
            ->selectRaw('category, COUNT(*) as items_count')
            ->groupBy('category')
            ->orderByDesc('items_count')
            ->limit(30)
            ->get()
            ->map(fn (PlaybackSourceItem $item): array => ['name' => $item->category, 'count' => (int) $item->items_count])
            ->values()
            ->all();

        $counts = $this->catalogCounts(clone $base);

        if ($view === 'home') {
            return [
                'view' => 'home',
                'counts' => $counts,
                'continueWatching' => $this->continueWatchingFor($user),
                'recentMovies' => $this->catalogShelf(clone $base, ['movie'], 12),
                'recentShows' => $this->catalogShelf(clone $base, ['show'], 12),
                'recentlyWatched' => $this->catalogProgressShelf($user, 12),
                'linkedItems' => $this->catalogShelf((clone $base)->whereHas('mediaLink', fn (Builder $query) => $query->forUser($user)), [], 12),
                'needsMatching' => $this->catalogShelf((clone $base)->whereDoesntHave('mediaLink', fn (Builder $query) => $query->forUser($user)), [], 12),
                'categories' => $categories,
            ];
        }

        if ($view === 'movies') {
            $base->where('kind', 'movie');
        } elseif ($view === 'shows') {
            $base->whereIn('kind', ['show', 'episode']);
        } elseif (in_array($view, ['live', 'guide'], true)) {
            $base->where('kind', 'live');
        }

        if (filled($filters['category'] ?? null)) {
            $base->where('category', $filters['category']);
        }
        if (filled($filters['query'] ?? null)) {
            $safeQuery = str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['query']));
            $base->where('title', 'like', '%'.$safeQuery.'%');
        }

        $total = $base->count();
        $items = $base
            ->latest('catalog_synced_at')
            ->latest('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (PlaybackSourceItem $item): array => $this->sourceItemSummary($item))
            ->values()
            ->all();

        if ($view === 'guide') {
            $items = array_values(array_filter($items, fn (array $item): bool => ! empty($item['epg'])));
        }

        return [
            'view' => $view,
            'counts' => $counts,
            'items' => $items,
            'categories' => $categories,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'hasMore' => $page * $perPage < $total,
            ],
        ];
    }

    public function setFavorite(User $user, PlaybackSourceItem $item, bool $favorite): PlaybackSourceItem
    {
        $this->assertOwnedItem($user, $item);
        $item->forceFill(['favorite' => $favorite])->save();

        return $item->refresh()->loadMissing(['source', 'mediaLink', 'progress']);
    }

    public function startPlayback(User $user, PlaybackSourceItem $item): PlaybackSession
    {
        $this->assertOwnedItem($user, $item);
        $item->loadMissing([
            'source',
            'mediaLink' => fn ($query) => $query->forUser($user),
        ]);

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

        $session = PlaybackSession::create([
            'user_id' => $user->id,
            'playback_source_id' => $item->playback_source_id,
            'playback_source_item_id' => $item->id,
            'media_link_id' => $item->mediaLink?->id,
            'status' => 'playing',
            'started_at' => now(),
            'last_position_seconds' => 0,
        ]);

        $this->mediaEvents->record($user, MediaEventType::PlaybackStarted, $session, [
            'title' => $item->title,
            'kind' => $item->kind,
            'playback_source_item_id' => $item->id,
            'linked' => (bool) $item->mediaLink,
        ], MediaEventSource::Player);

        return $session;
    }

    /**
     * @param  array{movie_id?:int|null,show_id?:int|null,episode_id?:int|null,ai_suggestion?:bool|null}  $data
     */
    public function link(User $user, PlaybackSourceItem $item, array $data): MediaLink
    {
        $this->assertOwnedItem($user, $item);

        if (($data['confirm'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'confirm' => 'Confirm this item belongs to the selected media before linking.',
            ]);
        }

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

        $link = MediaLink::updateOrCreate([
            'user_id' => $user->id,
            'playback_source_item_id' => $item->id,
        ], [
            'movie_id' => $movieId,
            'show_id' => $showId,
            'episode_id' => $episodeId,
            'linked_at' => now(),
        ]);

        $item->forceFill(['match_status' => 'linked'])->save();

        $this->mediaEvents->record($user, MediaEventType::ProviderItemLinked, $item, [
            'title' => $item->title,
            'kind' => $item->kind,
            'movie_id' => $movieId,
            'show_id' => $showId,
            'episode_id' => $episodeId,
        ], MediaEventSource::Provider);

        if (($data['ai_suggestion'] ?? false) === true) {
            $this->mediaEvents->record($user, MediaEventType::AIMatchConfirmed, $item, [
                'title' => $item->title,
                'kind' => $item->kind,
                'movie_id' => $movieId,
                'show_id' => $showId,
                'episode_id' => $episodeId,
            ], MediaEventSource::AI);
        }

        return $link;
    }

    public function unlink(User $user, PlaybackSourceItem $item): void
    {
        $this->assertOwnedItem($user, $item);

        $link = MediaLink::forUser($user)
            ->where('playback_source_item_id', $item->id)
            ->first();

        MediaLink::forUser($user)
            ->where('playback_source_item_id', $item->id)
            ->delete();

        $suggested = is_array(($item->metadata ?? [])['link_suggestion'] ?? null);
        $item->forceFill(['match_status' => $suggested ? 'suggested' : 'needs_review'])->save();

        if ($link) {
            $this->mediaEvents->record($user, MediaEventType::ProviderItemUnlinked, $item, [
                'title' => $item->title,
                'kind' => $item->kind,
                'movie_id' => $link->movie_id,
                'show_id' => $link->show_id,
                'episode_id' => $link->episode_id,
            ], MediaEventSource::Provider);
        }
    }

    /**
     * @param  array{position_seconds?:int,duration_seconds?:int|null,completed?:bool|null,status?:string|null}  $data
     */
    public function updateSession(User $user, PlaybackSession $session, array $data): PlaybackSession
    {
        $this->assertOwnedSession($user, $session);

        return DB::transaction(function () use ($data, $session, $user): PlaybackSession {
            $completed = (bool) ($data['completed'] ?? false);
            $alreadyCompleted = $session->status === 'completed' || $session->ended_at !== null;

            $session->forceFill([
                'last_position_seconds' => (int) ($data['position_seconds'] ?? $session->last_position_seconds),
                'duration_seconds' => $data['duration_seconds'] ?? $session->duration_seconds,
                'status' => $completed ? 'completed' : ($data['status'] ?? $session->status),
                'ended_at' => $completed ? ($session->ended_at ?? now()) : $session->ended_at,
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

            if ($completed && ! $alreadyCompleted) {
                $this->mediaEvents->record($user, MediaEventType::PlaybackCompleted, $session, [
                    'title' => $session->sourceItem?->title,
                    'playback_source_item_id' => $session->playback_source_item_id,
                    'movie_id' => $progress->movie_id,
                    'episode_id' => $progress->episode_id,
                    'duration_seconds' => $session->duration_seconds,
                    'position_seconds' => $session->last_position_seconds,
                    'linked' => (bool) $session->mediaLink,
                ], MediaEventSource::Player);
            }

            return $session->refresh();
        });
    }

    public function deleteSource(User $user, PlaybackSource $source): void
    {
        if ($source->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        $this->auditLogs->record('playback_source.deleted', $user, $source, $user, [
            'provider_type' => $source->provider_type,
            'status' => $source->status,
            'items_count' => $source->items()->count(),
        ]);
        $this->mediaEvents->record($user, MediaEventType::ProviderDeleted, $source, [
            'title' => $source->name,
            'provider_type' => $source->provider_type,
            'status' => $source->status,
            'items_count' => $source->items()->count(),
        ], MediaEventSource::Provider);

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

        $watch = MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => $data['watched_at'] ?? now(),
            'runtime' => $data['runtime'] ?? $movie->runtime,
            'watch_count' => 1,
            'source' => 'manual',
        ]);

        $this->mediaEvents->record($user, MediaEventType::MovieWatched, $movie, [
            'title' => $movie->title,
            'media_type' => 'movie',
            'watched_at' => $watch->watched_at?->toIso8601String(),
            'runtime' => $watch->runtime,
        ], MediaEventSource::Manual);

        return $watch->refresh();
    }

    /**
     * @param  array{watched_at?:string|null,runtime?:int|null}  $data
     */
    public function manuallyTrackEpisode(User $user, Episode $episode, array $data): EpisodeWatch
    {
        $episode->loadMissing('show');

        if ($episode->user_id !== $user->id || ($episode->show && $episode->show->user_id !== $user->id)) {
            throw new ModelNotFoundException;
        }

        $watch = EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $episode->show_id,
            'episode_id' => $episode->id,
            'watched_at' => $data['watched_at'] ?? now(),
            'runtime' => $data['runtime'] ?? $episode->runtime,
            'source' => 'manual',
        ]);

        $this->mediaEvents->record($user, MediaEventType::EpisodeWatched, $episode, [
            'title' => $episode->title ?: $episode->show?->title,
            'media_type' => 'episode',
            'show_id' => $episode->show_id,
            'watched_at' => $watch->watched_at?->toIso8601String(),
            'runtime' => $watch->runtime,
        ], MediaEventSource::Manual);

        return $watch->refresh();
    }

    public function untrackManualMovie(User $user, Movie $movie): void
    {
        if ($movie->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        $watch = MovieWatch::forUser($user)
            ->where('movie_id', $movie->id)
            ->where('source', 'manual')
            ->latest('watched_at')
            ->latest('id')
            ->first();

        if ($watch) {
            $watch->delete();
            $this->mediaEvents->record($user, MediaEventType::MovieUnwatched, $movie, [
                'title' => $movie->title,
                'media_type' => 'movie',
            ], MediaEventSource::Manual);
        }
    }

    public function untrackManualEpisode(User $user, Episode $episode): void
    {
        $episode->loadMissing('show');

        if ($episode->user_id !== $user->id || ($episode->show && $episode->show->user_id !== $user->id)) {
            throw new ModelNotFoundException;
        }

        $watch = EpisodeWatch::forUser($user)
            ->where('episode_id', $episode->id)
            ->where('source', 'manual')
            ->latest('watched_at')
            ->latest('id')
            ->first();

        if ($watch) {
            $watch->delete();
            $this->mediaEvents->record($user, MediaEventType::EpisodeUnwatched, $episode, [
                'title' => $episode->title ?: $episode->show?->title,
                'media_type' => 'episode',
                'show_id' => $episode->show_id,
            ], MediaEventSource::Manual);
        }
    }

    /** @return array{episodes:int,watched:int} */
    public function manuallyTrackSeason(User $user, Show $show, int $seasonNumber): array
    {
        $show = Show::forUser($user)->findOrFail($show->id);
        $episodes = Episode::forUser($user)
            ->where('show_id', $show->id)
            ->where('season_number', $seasonNumber)
            ->orderBy('episode_number')
            ->get();

        $watched = 0;
        DB::transaction(function () use ($episodes, $user, &$watched): void {
            foreach ($episodes as $episode) {
                if (EpisodeWatch::forUser($user)->where('episode_id', $episode->id)->exists()) {
                    continue;
                }

                $this->manuallyTrackEpisode($user, $episode, []);
                $watched++;
            }
        });

        return ['episodes' => $episodes->count(), 'watched' => $watched];
    }

    /** @return array{episodes:int,unwatched:int} */
    public function untrackManualSeason(User $user, Show $show, int $seasonNumber): array
    {
        $show = Show::forUser($user)->findOrFail($show->id);
        $episodeIds = Episode::forUser($user)
            ->where('show_id', $show->id)
            ->where('season_number', $seasonNumber)
            ->pluck('id');
        $deleted = EpisodeWatch::forUser($user)
            ->whereIn('episode_id', $episodeIds)
            ->where('source', 'manual')
            ->delete();

        return ['episodes' => $episodeIds->count(), 'unwatched' => $deleted];
    }

    /**
     * @return array<string, mixed>
     */
    public function sourceSummary(PlaybackSource $source): array
    {
        $metadata = $source->metadata ?? [];
        $settings = $source->settings ?? [];

        return [
            'id' => $source->id,
            'name' => $source->name,
            'providerType' => $source->provider_type,
            'status' => $source->status,
            'itemsCount' => $source->items_count ?? $source->items()->count(),
            'lastSyncedAt' => $source->last_synced_at?->toIso8601String(),
            'syncStatus' => ProviderSyncStatus::normalize($source->sync_status),
            'lastSyncError' => $source->last_sync_error,
            'credentialsConfigured' => filled($settings['username'] ?? null) && filled($settings['password'] ?? null),
            'serverConfigured' => filled($settings['base_url'] ?? null) || filled($settings['playlist_url'] ?? null),
            'xmltvConfigured' => filled($settings['xmltv_url'] ?? null),
            'epgAvailable' => (bool) ($metadata['epg_available'] ?? false),
        ];
    }

    /** @return array{total:int,movies:int,shows:int,episodes:int,live:int,categories:int} */
    private function catalogCounts(Builder $query): array
    {
        $kindCounts = (clone $query)
            ->selectRaw('kind, COUNT(*) as items_count')
            ->groupBy('kind')
            ->pluck('items_count', 'kind');

        return [
            'total' => (int) $kindCounts->sum(),
            'movies' => (int) ($kindCounts['movie'] ?? 0),
            'shows' => (int) ($kindCounts['show'] ?? 0),
            'episodes' => (int) ($kindCounts['episode'] ?? 0),
            'live' => (int) ($kindCounts['live'] ?? 0),
            'categories' => (clone $query)->whereNotNull('category')->distinct()->count('category'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dashboardSourceItemsFor(User $user): array
    {
        return PlaybackSourceItem::forUser($user)
            ->with([
                'source',
                'mediaLink' => fn ($query) => $query->forUser($user),
            ])
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
                'sourceItem' => fn ($query) => $query->forUser($user),
                'movie' => fn ($query) => $query->forUser($user),
                'show' => fn ($query) => $query->forUser($user),
                'episode' => fn ($query) => $query->forUser($user),
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
            ->whereDoesntHave('mediaLink', fn (Builder $query) => $query->forUser($user))
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
            ->with(['sourceItem' => fn ($query) => $query->forUser($user)])
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
        $link = $item->mediaLink;

        return [
            'id' => $item->id,
            'sourceId' => $item->playback_source_id,
            'sourceName' => $item->source?->name,
            'sourceStatus' => $item->source?->status,
            'kind' => $item->kind,
            'title' => $item->title,
            'status' => $item->status,
            'category' => $item->category,
            'durationSeconds' => $item->duration_seconds,
            'releaseYear' => $item->release_year,
            'matchStatus' => $link ? 'linked' : $item->match_status,
            'favorite' => (bool) $item->favorite,
            'playable' => filled($item->stream_url),
            'artworkAvailable' => filled($item->poster_url),
            'progress' => $item->progress ? [
                'positionSeconds' => $item->progress->position_seconds,
                'durationSeconds' => $item->progress->duration_seconds,
                'completed' => (bool) $item->progress->completed,
            ] : null,
            'epg' => is_array(($item->metadata ?? [])['epg'] ?? null) ? $item->metadata['epg'] : null,
            'suggestedMatch' => is_array(($item->metadata ?? [])['link_suggestion'] ?? null) ? $item->metadata['link_suggestion'] : null,
            'linked' => (bool) $link,
            'link' => $link ? [
                'id' => $link->id,
                'movieId' => $link->movie_id,
                'showId' => $link->show_id,
                'episodeId' => $link->episode_id,
                'canonicalTitle' => $link->movie?->title ?? $link->show?->title ?? $link->episode?->title,
                'linkedAt' => $link->linked_at?->toIso8601String(),
            ] : null,
            'lastSeenAt' => $item->last_seen_at?->toIso8601String(),
            'catalogSyncedAt' => $item->catalog_synced_at?->toIso8601String(),
        ];
    }

    /** @param Builder<PlaybackSourceItem> $query @param list<string> $kinds @return list<array<string,mixed>> */
    private function catalogShelf(Builder $query, array $kinds, int $limit): array
    {
        return $query
            ->when($kinds !== [], fn (Builder $builder) => $builder->whereIn('kind', $kinds))
            ->latest('catalog_synced_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (PlaybackSourceItem $item): array => $this->sourceItemSummary($item))
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function catalogProgressShelf(User $user, int $limit): array
    {
        return PlaybackProgress::forUser($user)
            ->with(['sourceItem.source', 'sourceItem.mediaLink', 'sourceItem.progress'])
            ->whereHas('sourceItem', fn (Builder $query) => $query->forUser($user))
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (PlaybackProgress $progress): ?array => $progress->sourceItem ? $this->sourceItemSummary($progress->sourceItem) : null)
            ->filter()
            ->values()
            ->all();
    }

    private function assertOwnedSource(User $user, PlaybackSource $source): void
    {
        if ($source->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
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
