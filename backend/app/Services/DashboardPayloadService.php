<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\EpisodeWatch;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Show;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DashboardPayloadService
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly MediaEventService $mediaEvents,
        private readonly MediaLibraryService $mediaLibrary,
        private readonly MediaMetadataService $metadata,
        private readonly PlaybackLibraryService $playbackLibrary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $stats = $this->mediaLibrary->statsFor($user);
        $alertsUnread = Alert::forUser($user)->unread()->count();
        $recentlyWatched = $this->recentlyWatched($user);

        $this->analytics->record('dashboard.viewed', $user);

        return [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'image' => '',
                'cover' => '',
            ],
            'source' => [
                'kind' => 'TV Time Laravel backend',
                'generatedAt' => now()->toIso8601String(),
            ],
            'stats' => [
                ...$stats,
                'alertsUnread' => $alertsUnread,
            ],
            'hero' => $recentlyWatched[0] ?? [
                'title' => 'Start your archive',
                'subtitle' => 'Your private library is empty',
                'meta' => 'Import or add TV Time data later',
                'poster' => '',
                'backdrop' => '',
                'progress' => 0,
                'kind' => 'library',
                'eyebrow' => 'Private dashboard',
            ],
            'alerts' => $this->alerts($user),
            'recentlyWatched' => $recentlyWatched,
            'followedNewEpisodes' => $this->followedNewEpisodes($user),
            'moviesToCheckOut' => $this->moviesToCheckOut($user),
            'topShows' => $this->topShows($user),
            'activity' => $this->activity($user),
            'timeline' => $this->mediaEvents->dashboardTimeline($user),
            'player' => $this->playbackLibrary->playerPayloadFor($user),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alerts(User $user): array
    {
        return Alert::forUser($user)
            ->orderByDesc('unread')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (Alert $alert): array => [
                'id' => $alert->id,
                'category' => $alert->category,
                'title' => $alert->title,
                'subtitle' => $alert->subtitle,
                'dueText' => $alert->due_text,
                'unread' => $alert->unread,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentlyWatched(User $user): array
    {
        $episodeItems = EpisodeWatch::forUser($user)
            ->with(['episode', 'show'])
            ->watched()
            ->latest('watched_at')
            ->limit(10)
            ->get()
            ->map(fn (EpisodeWatch $watch): array => $this->episodeWatchItem($watch));

        $movieItems = MovieWatch::forUser($user)
            ->with('movie')
            ->watched()
            ->latest('watched_at')
            ->limit(10)
            ->get()
            ->map(fn (MovieWatch $watch): array => $this->movieWatchItem($watch));

        return $episodeItems
            ->concat($movieItems)
            ->sortByDesc(fn (array $item): string => $item['watchedAt'] ?? '')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function followedNewEpisodes(User $user): array
    {
        return Show::forUser($user)
            ->followed()
            ->whereColumn('aired_episodes', '>', 'seen_episodes')
            ->orderByRaw('(aired_episodes - seen_episodes) desc')
            ->orderBy('title')
            ->limit(10)
            ->get()
            ->map(function (Show $show): array {
                $available = max(0, $show->aired_episodes - $show->seen_episodes);

                return [
                    'id' => 'show-gap-'.$show->id,
                    'kind' => 'show',
                    'showId' => $show->id,
                    'title' => $show->title,
                    'subtitle' => $available.' '.str('episode')->plural($available).' ready',
                    'meta' => $show->seen_episodes.'/'.$show->aired_episodes.' watched',
                    'poster' => $this->posterFor($show, $show->poster_url),
                    'backdrop' => $this->backdropFor($show, $show->fanart_url),
                    'progress' => $this->progress($show->seen_episodes, $show->aired_episodes),
                    'badge' => 'new',
                    'unread' => true,
                    ...$this->metadataFields($show, $show->first_air_date),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function moviesToCheckOut(User $user): array
    {
        return Movie::forUser($user)
            ->toWatch()
            ->orderByDesc('updated_at')
            ->orderBy('title')
            ->limit(10)
            ->get()
            ->map(fn (Movie $movie): array => [
                'id' => 'movie-shelf-'.$movie->id,
                'kind' => 'movie',
                'movieId' => $movie->id,
                'title' => $movie->title,
                'subtitle' => 'Saved for later',
                'meta' => $movie->runtime > 0 ? $movie->runtime.' min' : 'Watchlist',
                'poster' => $this->posterFor($movie, $movie->poster_url),
                'backdrop' => $this->backdropFor($movie, $movie->poster_url),
                'watchedAt' => null,
                'progress' => 0,
                'badge' => 'watchlist',
                ...$this->metadataFields($movie, $movie->release_date),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topShows(User $user): array
    {
        return Show::forUser($user)
            ->withCount('episodeWatches')
            ->withMax('episodeWatches', 'watched_at')
            ->whereHas('episodeWatches')
            ->orderByDesc('episode_watches_count')
            ->orderByDesc('episode_watches_max_watched_at')
            ->orderBy('title')
            ->limit(12)
            ->get()
            ->map(fn (Show $show): array => [
                'id' => 'top-show-'.$show->id,
                'kind' => 'show',
                'showId' => $show->id,
                'title' => $show->title,
                'subtitle' => $show->episode_watches_count.' watched '.str('episode')->plural($show->episode_watches_count),
                'meta' => $show->episode_watches_max_watched_at
                    ? 'Last watched '.$this->dateOnly($show->episode_watches_max_watched_at)
                    : 'From archive',
                'poster' => $this->posterFor($show, $show->poster_url),
                'backdrop' => $this->backdropFor($show, $show->fanart_url),
                'progress' => $this->progress($show->seen_episodes, $show->aired_episodes),
                'badge' => 'top',
                ...$this->metadataFields($show, $show->first_air_date),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{day:string,date:string,hours:float|int}>
     */
    private function activity(User $user): array
    {
        $latest = collect([
            EpisodeWatch::forUser($user)->max('watched_at'),
            MovieWatch::forUser($user)->max('watched_at'),
        ])->filter()->max();

        if (! $latest) {
            return $this->emptyActivity();
        }

        $end = $this->asCarbon($latest)->endOfDay();
        $start = $end->copy()->subDays(6)->startOfDay();
        $minutesByDate = collect();

        EpisodeWatch::forUser($user)
            ->whereBetween('watched_at', [$start, $end])
            ->get(['watched_at', 'runtime'])
            ->each(fn (EpisodeWatch $watch): Collection => $this->addRuntime($minutesByDate, $watch->watched_at, $watch->runtime));

        MovieWatch::forUser($user)
            ->whereBetween('watched_at', [$start, $end])
            ->get(['watched_at', 'runtime'])
            ->each(fn (MovieWatch $watch): Collection => $this->addRuntime($minutesByDate, $watch->watched_at, $watch->runtime));

        return collect(range(0, 6))
            ->map(function (int $offset) use ($start, $minutesByDate): array {
                $date = $start->copy()->addDays($offset);
                $minutes = (int) ($minutesByDate[$date->toDateString()] ?? 0);

                return [
                    'day' => $date->format('D'),
                    'date' => $date->toDateString(),
                    'hours' => round($minutes / 60, 1),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<string, int>  $minutesByDate
     * @return Collection<string, int>
     */
    private function addRuntime(Collection $minutesByDate, mixed $watchedAt, int $runtime): Collection
    {
        if (! $watchedAt) {
            return $minutesByDate;
        }

        $date = $this->asCarbon($watchedAt)->toDateString();
        $minutesByDate[$date] = (int) ($minutesByDate[$date] ?? 0) + $runtime;

        return $minutesByDate;
    }

    /**
     * @return array<string, mixed>
     */
    private function episodeWatchItem(EpisodeWatch $watch): array
    {
        $episode = $watch->episode;
        $show = $watch->show;
        $season = $episode?->season_number;
        $episodeNumber = $episode?->episode_number;

        return [
            'id' => 'episode-watch-'.$watch->id,
            'kind' => 'episode',
            'episodeId' => $episode?->id,
            'showId' => $show?->id,
            'title' => $show?->title ?? 'Untitled show',
            'subtitle' => $season && $episodeNumber ? 'S'.$season.' E'.$episodeNumber : 'Episode',
            'meta' => $watch->runtime > 0 ? $watch->runtime.' min episode' : 'Watched episode',
            'poster' => $this->posterFor($episode ?: $show, $show?->poster_url),
            'backdrop' => $this->backdropFor($episode ?: $show, $show?->fanart_url),
            'watchedAt' => $watch->watched_at?->toIso8601String(),
            'progress' => 100,
            'badge' => 'watched',
            ...$this->metadataFields($episode ?: $show, $episode?->air_date ?? $show?->first_air_date),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movieWatchItem(MovieWatch $watch): array
    {
        $movie = $watch->movie;

        return [
            'id' => 'movie-watch-'.$watch->id,
            'kind' => 'movie',
            'movieId' => $movie?->id,
            'title' => $movie?->title ?? 'Untitled movie',
            'subtitle' => 'Movie',
            'meta' => $watch->runtime > 0 ? $watch->runtime.' min movie' : 'Watched movie',
            'poster' => $this->posterFor($movie, $movie?->poster_url),
            'backdrop' => $this->backdropFor($movie, $movie?->poster_url),
            'watchedAt' => $watch->watched_at?->toIso8601String(),
            'progress' => 100,
            'badge' => 'watched',
            'eyebrow' => 'Recent movie',
            'actionLabel' => 'Open details',
            ...$this->metadataFields($movie, $movie?->release_date),
        ];
    }

    private function posterFor(mixed $media, ?string $fallback = ''): string
    {
        if (! $media) {
            return $fallback ?? '';
        }

        return $this->metadata->imageUrl($media->poster_path ?? null) ?: ($fallback ?? '');
    }

    private function backdropFor(mixed $media, ?string $fallback = ''): string
    {
        if (! $media) {
            return $fallback ?? '';
        }

        return $this->metadata->imageUrl($media->backdrop_path ?? null, 'w780') ?: ($fallback ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataFields(mixed $media, mixed $date): array
    {
        if (! $media) {
            return [
                'genres' => [],
                'releaseYear' => null,
                'runtime' => null,
                'metadataStatus' => 'local',
                'metadata' => [
                    'genres' => [],
                    'releaseYear' => null,
                    'runtime' => null,
                    'status' => null,
                    'tmdbId' => null,
                    'imdbId' => null,
                    'tvdbId' => null,
                    'metadataStatus' => 'local',
                ],
            ];
        }

        $genres = collect($media->genres ?? [])
            ->map(fn (mixed $genre): ?string => is_array($genre) ? ($genre['name'] ?? null) : null)
            ->filter()
            ->values()
            ->all();

        $metadataStatus = $media->metadata_refreshed_at ? 'enriched' : 'local';
        $runtime = (int) ($media->runtime ?? 0) ?: null;

        return [
            'genres' => $genres,
            'releaseYear' => $this->yearFromDate($date),
            'runtime' => $runtime,
            'metadataStatus' => $metadataStatus,
            'metadata' => [
                'genres' => $genres,
                'releaseYear' => $this->yearFromDate($date),
                'runtime' => $runtime,
                'status' => $media->status ?? null,
                'tmdbId' => $media->tmdb_id ?? null,
                'imdbId' => $media->imdb_id ?? null,
                'tvdbId' => $media->tvdb_id ?? null,
                'metadataStatus' => $metadataStatus,
            ],
        ];
    }

    private function yearFromDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $this->asCarbon($value)->format('Y');
    }

    private function progress(int $seen, int $aired): int
    {
        if ($aired <= 0) {
            return 0;
        }

        return min(100, (int) round(($seen / $aired) * 100));
    }

    private function dateOnly(mixed $value): string
    {
        return $this->asCarbon($value)->toDateString();
    }

    private function asCarbon(mixed $value): CarbonInterface
    {
        return $value instanceof CarbonInterface ? $value : CarbonImmutable::parse($value);
    }

    /**
     * @return list<array{day:string,date:string,hours:int}>
     */
    private function emptyActivity(): array
    {
        $start = now()->startOfDay()->subDays(6);

        return collect(range(0, 6))
            ->map(fn (int $offset): array => [
                'day' => $start->copy()->addDays($offset)->format('D'),
                'date' => $start->copy()->addDays($offset)->toDateString(),
                'hours' => 0,
            ])
            ->all();
    }
}
