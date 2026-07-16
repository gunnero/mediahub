<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaLink;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class MediaDetailService
{
    public function __construct(
        private readonly MediaMetadataService $metadata,
        private readonly MediaEventService $events,
        private readonly TMDBClientService $tmdb,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function movie(User $user, Movie $movie): array
    {
        $movie = Movie::forUser($user)->findOrFail($movie->id);
        $watchQuery = MovieWatch::forUser($user)
            ->where('movie_id', $movie->id)
            ->watched();
        $watchedCount = (int) (clone $watchQuery)->get(['watch_count'])
            ->sum(fn (MovieWatch $watch): int => max(1, $watch->watch_count));
        $watches = $watchQuery->latest('watched_at')->latest('id')->limit(100)->get();
        $nextWatchNumber = $watchedCount;
        $public = $this->publicMetadata($movie, 'movie');

        return [
            'id' => $movie->id,
            'movieId' => $movie->id,
            'kind' => 'movie',
            'title' => $movie->title,
            'subtitle' => 'Movie',
            'meta' => $movie->runtime > 0 ? $movie->runtime.' min movie' : 'Movie',
            'poster' => $this->posterFor($movie, $movie->poster_url),
            'backdrop' => $this->backdropFor($movie, $movie->poster_url),
            'status' => $movie->is_to_watch ? 'watchlist' : ($watches->isNotEmpty() ? 'watched' : 'library'),
            'watched' => $watches->isNotEmpty(),
            'watchedCount' => $watchedCount,
            'watchlist' => (bool) $movie->is_to_watch,
            'overview' => $movie->overview,
            'tagline' => $public['tagline'],
            'originalTitle' => $public['original_title'],
            'people' => ['cast' => $public['cast'], 'directors' => $public['directors']],
            'production' => ['companies' => $public['companies'], 'countries' => $public['countries'], 'languages' => $public['languages']],
            'metadata' => $this->metadataFields($movie, $movie->release_date),
            'rating' => $this->rating($user, 'movie', $movie->id),
            'notes' => $this->notes($user, 'movie', $movie->id),
            'watchHistory' => $watches->map(function (MovieWatch $watch) use (&$nextWatchNumber): array {
                $watchNumber = $nextWatchNumber;
                $nextWatchNumber -= max(1, $watch->watch_count);

                return $this->watchItem($watch, $watchNumber);
            })->values()->all(),
            'provider' => $this->providerStatus($user, 'movie', $movie->id),
            'timeline' => $this->events->timeline($user, ['subject_type' => 'movie', 'subject_id' => $movie->id, 'limit' => 8]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, Show $show): array
    {
        $show = Show::forUser($user)->findOrFail($show->id);
        $watches = EpisodeWatch::forUser($user)
            ->where('show_id', $show->id)
            ->with('episode')
            ->latest('watched_at')
            ->latest('id')
            ->limit(20)
            ->get();
        $public = $this->publicMetadata($show, 'show');
        $showState = $this->showState($user, $show);

        return [
            'id' => $show->id,
            'showId' => $show->id,
            'kind' => 'show',
            'title' => $show->title,
            'subtitle' => $show->followed ? 'Followed show' : 'TV show',
            'meta' => $show->aired_episodes > 0
                ? $show->seen_episodes.'/'.$show->aired_episodes.' watched'
                : $watches->count().' watched episodes',
            'poster' => $this->posterFor($show, $show->poster_url),
            'backdrop' => $this->backdropFor($show, $show->fanart_url),
            'status' => $show->followed ? 'followed' : ($watches->isNotEmpty() ? 'watched' : 'library'),
            'watched' => $watches->isNotEmpty() || $show->seen_episodes > 0,
            'watchedEpisodes' => $showState['watchedEpisodes'],
            'watchlist' => (bool) $show->followed,
            'overview' => $show->overview,
            'tagline' => $public['tagline'],
            'originalTitle' => $public['original_title'],
            'people' => ['cast' => $public['cast'], 'directors' => $public['directors']],
            'production' => ['companies' => $public['companies'], 'countries' => $public['countries'], 'languages' => $public['languages']],
            'showState' => $showState,
            'metadata' => $this->metadataFields($show, $show->first_air_date),
            'rating' => $this->rating($user, 'show', $show->id),
            'notes' => $this->notes($user, 'show', $show->id),
            'watchHistory' => $watches->map(fn (EpisodeWatch $watch): array => $this->episodeWatchItem($watch))->values()->all(),
            'provider' => $this->showProviderStatus($user, $show),
            'latestEpisode' => $watches->first()?->episode ? $this->episodeRow($user, $watches->first()->episode) : null,
            'nextUnwatchedEpisode' => $this->nextUnwatchedEpisode($user, $show),
            'seasons' => $this->seasons($user, $show),
            'timeline' => $this->events->timeline($user, ['subject_type' => 'show', 'subject_id' => $show->id, 'limit' => 8]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function episode(User $user, Episode $episode): array
    {
        $episode = Episode::forUser($user)->with('show')->findOrFail($episode->id);
        $watchQuery = EpisodeWatch::forUser($user)
            ->where('episode_id', $episode->id)
            ->watched();
        $watchedCount = (clone $watchQuery)->count();
        $watches = $watchQuery->latest('watched_at')->latest('id')->limit(100)->get();
        $nextWatchNumber = $watchedCount;

        return [
            'id' => $episode->id,
            'episodeId' => $episode->id,
            'showId' => $episode->show_id,
            'showTitle' => $episode->show?->title,
            'kind' => 'episode',
            'title' => $episode->title ?: 'Untitled episode',
            'subtitle' => $this->episodeSubtitle($episode),
            'meta' => $episode->runtime > 0 ? $episode->runtime.' min episode' : 'Episode',
            'poster' => $this->posterFor($episode, $episode->show?->poster_url),
            'backdrop' => $this->backdropFor($episode, $episode->show?->fanart_url),
            'status' => $watches->isNotEmpty() ? 'watched' : 'library',
            'watched' => $watches->isNotEmpty(),
            'watchedCount' => $watchedCount,
            'watchlist' => false,
            'overview' => $episode->overview,
            'metadata' => $this->metadataFields($episode, $episode->air_date),
            'rating' => $this->rating($user, 'episode', $episode->id),
            'notes' => $this->notes($user, 'episode', $episode->id),
            'watchHistory' => $watches->map(function (EpisodeWatch $watch) use (&$nextWatchNumber): array {
                return $this->episodeWatchItem($watch, $nextWatchNumber--);
            })->values()->all(),
            'provider' => $this->providerStatus($user, 'episode', $episode->id),
            'timeline' => $this->events->timeline($user, ['subject_type' => 'episode', 'subject_id' => $episode->id, 'limit' => 8]),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rating(User $user, string $mediaType, int $mediaId): ?array
    {
        $rating = Rating::forUser($user)->forMedia($mediaType, $mediaId)->first();

        if (! $rating) {
            return null;
        }

        return [
            'id' => $rating->id,
            'rating' => $rating->rating,
            'updatedAt' => $rating->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notes(User $user, string $mediaType, int $mediaId): array
    {
        return Note::forUser($user)
            ->forMedia($mediaType, $mediaId)
            ->latest('updated_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (Note $note): array => [
                'id' => $note->id,
                'body' => $note->body,
                'updatedAt' => $note->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function watchItem(MovieWatch|EpisodeWatch $watch, ?int $watchNumber = null): array
    {
        return [
            'id' => $watch->id,
            'watchNumber' => $watchNumber,
            'watchCount' => $watch instanceof MovieWatch ? max(1, $watch->watch_count) : 1,
            'watchedAt' => $watch->watched_at?->toIso8601String(),
            'runtime' => $watch->runtime,
            'source' => $watch->source ?: 'archive',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function episodeWatchItem(EpisodeWatch $watch, ?int $watchNumber = null): array
    {
        return [
            ...$this->watchItem($watch, $watchNumber),
            'episodeId' => $watch->episode_id,
            'title' => $watch->episode?->title,
            'subtitle' => $watch->episode ? $this->episodeSubtitle($watch->episode) : 'Episode',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seasons(User $user, Show $show): array
    {
        $episodes = Episode::forUser($user)
            ->where('show_id', $show->id)
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->get();

        return $episodes
            ->groupBy(fn (Episode $episode): int => (int) $episode->season_number)
            ->map(function ($seasonEpisodes, int $seasonNumber) use ($user): array {
                $episodeRows = $seasonEpisodes
                    ->map(fn (Episode $episode): array => $this->episodeRow($user, $episode))
                    ->values()
                    ->all();

                return [
                    'seasonNumber' => $seasonNumber,
                    'title' => $seasonNumber > 0 ? 'Season '.$seasonNumber : 'Specials',
                    'episodesCount' => count($episodeRows),
                    'totalEpisodes' => count($episodeRows),
                    'watchedEpisodes' => collect($episodeRows)->where('watched', true)->count(),
                    'episodes' => $episodeRows,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function episodeRow(User $user, Episode $episode): array
    {
        $latestWatch = EpisodeWatch::forUser($user)
            ->where('episode_id', $episode->id)
            ->latest('watched_at')
            ->first();

        return [
            'id' => $episode->id,
            'episodeId' => $episode->id,
            'showId' => $episode->show_id,
            'seasonNumber' => (int) $episode->season_number,
            'episodeNumber' => (int) $episode->episode_number,
            'code' => $this->episodeCode($episode),
            'title' => $this->episodeTitle($episode),
            'runtime' => (int) $episode->runtime,
            'airDate' => $episode->air_date?->toDateString(),
            'poster' => $this->posterFor($episode),
            'watched' => $latestWatch !== null,
            'watchedAt' => $latestWatch?->watched_at?->toIso8601String(),
            'rating' => $this->rating($user, 'episode', $episode->id)['rating'] ?? null,
            'hasNote' => Note::forUser($user)->forMedia('episode', $episode->id)->exists(),
            'providerLinked' => $this->providerStatus($user, 'episode', $episode->id)['linked'],
            'playableItemId' => $this->providerStatus($user, 'episode', $episode->id)['playableItemId'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerStatus(User $user, string $columnMediaType, int $mediaId): array
    {
        $column = $columnMediaType.'_id';
        $links = MediaLink::forUser($user)
            ->where($column, $mediaId)
            ->whereHas('sourceItem', fn (Builder $query) => $query
                ->forUser($user)
                ->where('status', 'available')
                ->whereHas('source', fn (Builder $sourceQuery) => $sourceQuery->forUser($user)->active()))
            ->with(['sourceItem' => fn ($query) => $query->forUser($user)])
            ->get();

        return [
            'linked' => $links->isNotEmpty(),
            'linkedItemsCount' => $links->count(),
            'playableItemId' => $links->first(fn (MediaLink $link): bool => filled($link->sourceItem?->stream_url))?->playback_source_item_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function showProviderStatus(User $user, Show $show): array
    {
        $episodeIds = Episode::forUser($user)->where('show_id', $show->id)->pluck('id');
        $links = MediaLink::forUser($user)
            ->where(function (Builder $query) use ($episodeIds, $show): void {
                $query->where('show_id', $show->id)
                    ->orWhereIn('episode_id', $episodeIds);
            })
            ->whereHas('sourceItem', fn (Builder $query) => $query
                ->forUser($user)
                ->where('status', 'available')
                ->whereHas('source', fn (Builder $sourceQuery) => $sourceQuery->forUser($user)->active()))
            ->with(['sourceItem' => fn ($query) => $query->forUser($user)])
            ->get();

        return [
            'linked' => $links->isNotEmpty(),
            'linkedItemsCount' => $links->count(),
            'playableItemId' => $links->first(fn (MediaLink $link): bool => filled($link->sourceItem?->stream_url))?->playback_source_item_id,
        ];
    }

    /** @return array<string, mixed>|null */
    private function nextUnwatchedEpisode(User $user, Show $show): ?array
    {
        $watchedIds = EpisodeWatch::forUser($user)->where('show_id', $show->id)->pluck('episode_id');
        $episode = Episode::forUser($user)
            ->where('show_id', $show->id)
            ->whereNotIn('id', $watchedIds)
            ->where('season_number', '>', 0)
            ->where('episode_number', '>', 0)
            ->where(function (Builder $query): void {
                $query->whereNull('air_date')->orWhereDate('air_date', '<=', now()->toDateString());
            })
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->first();

        return $episode ? $this->episodeRow($user, $episode) : null;
    }

    /** @return array<string, mixed> */
    private function showState(User $user, Show $show): array
    {
        $airedIds = Episode::forUser($user)
            ->where('show_id', $show->id)
            ->where('season_number', '>', 0)
            ->where('episode_number', '>', 0)
            ->where(function (Builder $query): void {
                $query->whereNull('air_date')->orWhereDate('air_date', '<=', now()->toDateString());
            })
            ->pluck('id');
        $watchedEpisodes = EpisodeWatch::forUser($user)
            ->where('show_id', $show->id)
            ->whereIn('episode_id', $airedIds)
            ->distinct('episode_id')
            ->count('episode_id');
        $allWatched = $airedIds->isNotEmpty() && $watchedEpisodes >= $airedIds->count();
        $ended = in_array(strtolower(trim((string) $show->status)), ['ended', 'canceled', 'cancelled'], true);
        $nextRelease = data_get($show->metadata, 'release.next_episode');

        if ($ended && $allWatched) {
            return ['code' => 'ended_completed', 'title' => 'SHOW ENDED', 'description' => 'You watched every aired episode.', 'watchedEpisodes' => $watchedEpisodes, 'airedEpisodes' => $airedIds->count(), 'nextEpisode' => null];
        }

        if ($ended) {
            $remaining = max(0, $airedIds->count() - $watchedEpisodes);

            return ['code' => 'ended_incomplete', 'title' => 'Show ended', 'description' => $remaining.' '.str('episode')->plural($remaining).' left to watch.', 'watchedEpisodes' => $watchedEpisodes, 'airedEpisodes' => $airedIds->count(), 'nextEpisode' => null];
        }

        return [
            'code' => is_array($nextRelease) && filled($nextRelease['air_date'] ?? null) ? 'returning_scheduled' : 'returning',
            'title' => is_array($nextRelease) && filled($nextRelease['air_date'] ?? null) ? 'Next episode' : 'Returning series',
            'description' => is_array($nextRelease) && filled($nextRelease['air_date'] ?? null)
                ? trim(($nextRelease['name'] ?? 'New episode').' · '.$nextRelease['air_date'], ' ·')
                : 'The next release date has not been announced.',
            'watchedEpisodes' => $watchedEpisodes,
            'airedEpisodes' => $airedIds->count(),
            'nextEpisode' => is_array($nextRelease) ? $nextRelease : null,
        ];
    }

    /** @return array<string, mixed> */
    private function publicMetadata(Movie|Show $media, string $type): array
    {
        $public = data_get($media->metadata, 'public');
        if (! is_array($public) && $media->tmdb_id && $this->tmdb->enabled()) {
            $details = $type === 'movie'
                ? $this->tmdb->getMovie((int) $media->tmdb_id)
                : $this->tmdb->getShow((int) $media->tmdb_id);
            $public = is_array($details) ? $this->metadata->publicMetadata($details, $type) : null;
        }

        $public = is_array($public) ? $public : [];
        $person = fn (mixed $row): ?array => is_array($row) && filled($row['name'] ?? null) ? [
            'id' => $row['tmdb_id'] ?? null,
            'name' => (string) $row['name'],
            'role' => $row['role'] ?? null,
            'image' => $this->metadata->imageUrl($row['profile_path'] ?? null, 'w185'),
        ] : null;

        return [
            'tagline' => $public['tagline'] ?? null,
            'original_title' => $public['original_title'] ?? $media->original_title,
            'cast' => collect($public['cast'] ?? [])->map($person)->filter()->values()->all(),
            'directors' => collect($public['directors'] ?? [])->map($person)->filter()->values()->all(),
            'companies' => array_values(array_filter($public['companies'] ?? [], 'is_string')),
            'countries' => array_values(array_filter($public['countries'] ?? [], 'is_string')),
            'languages' => array_values(array_filter($public['languages'] ?? [], 'is_string')),
        ];
    }

    private function posterFor(mixed $media, ?string $fallback = ''): string
    {
        return $this->metadata->imageUrl($media->poster_path ?? null) ?: ($fallback ?? '');
    }

    private function backdropFor(mixed $media, ?string $fallback = ''): string
    {
        return $this->metadata->imageUrl($media->backdrop_path ?? null, 'w780') ?: ($fallback ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataFields(mixed $media, mixed $date): array
    {
        $genres = collect($media->genres ?? [])
            ->map(fn (mixed $genre): ?string => is_array($genre) ? ($genre['name'] ?? null) : null)
            ->filter()
            ->values()
            ->all();
        $metadataStatus = $media->metadata_refreshed_at ? 'enriched' : 'local';

        return [
            'genres' => $genres,
            'releaseYear' => $this->yearFromDate($date),
            'runtime' => (int) ($media->runtime ?? 0) ?: null,
            'status' => $media->status ?? null,
            'tmdbId' => $media->tmdb_id ?? null,
            'imdbId' => $media->imdb_id ?? null,
            'tvdbId' => $media->tvdb_id ?? null,
            'metadataStatus' => $metadataStatus,
        ];
    }

    private function yearFromDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y');
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('Y');
        } catch (Throwable) {
            return null;
        }
    }

    private function episodeSubtitle(Episode $episode): string
    {
        if ($episode->season_number && $episode->episode_number) {
            return 'S'.$episode->season_number.' E'.$episode->episode_number;
        }

        return 'Episode';
    }

    private function episodeCode(Episode $episode): string
    {
        if ($episode->season_number && $episode->episode_number) {
            return 'S'.str_pad((string) $episode->season_number, 2, '0', STR_PAD_LEFT)
                .'E'.str_pad((string) $episode->episode_number, 2, '0', STR_PAD_LEFT);
        }

        return 'Episode';
    }

    private function episodeTitle(Episode $episode): string
    {
        return trim((string) $episode->title) ?: 'Episode '.((int) $episode->episode_number ?: $episode->id);
    }
}
