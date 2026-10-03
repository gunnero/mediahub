<?php

namespace App\Services;

use App\Models\EpisodeWatch;
use App\Models\MovieWatch;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class StatisticsService
{
    /** @return array<string, mixed> */
    public function forUser(User $user, array $filters = []): array
    {
        $timezone = $user->timezone ?: config('app.timezone');
        $start = isset($filters['from']) ? CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc() : null;
        $end = isset($filters['to']) ? CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()->utc() : null;
        $movieWatches = MovieWatch::forUser($user)
            ->whereHas('movie', fn ($query) => $query->forUser($user))
            ->with(['movie' => fn ($query) => $query->forUser($user)->select(['id', 'user_id', 'title', 'genres'])])
            ->watched()->when($start, fn ($q) => $q->where('watched_at', '>=', $start))->when($end, fn ($q) => $q->where('watched_at', '<=', $end))
            ->get(['id', 'user_id', 'movie_id', 'watched_at', 'runtime', 'watch_count']);
        $episodeWatches = EpisodeWatch::forUser($user)
            ->whereHas('episode', fn ($query) => $query->forUser($user))
            ->whereHas('show', fn ($query) => $query->forUser($user))
            ->with([
                'episode' => fn ($query) => $query->forUser($user)->select(['id', 'user_id', 'show_id']),
                'show' => fn ($query) => $query->forUser($user)->select(['id', 'user_id', 'title', 'genres']),
            ])->watched()->when($start, fn ($q) => $q->where('watched_at', '>=', $start))->when($end, fn ($q) => $q->where('watched_at', '<=', $end))
            ->get(['id', 'user_id', 'show_id', 'episode_id', 'watched_at', 'runtime']);
        $movieWatchEvents = (int) $movieWatches->sum(fn (MovieWatch $watch): int => max(1, $watch->watch_count));
        $episodeWatchEvents = $episodeWatches->count();
        $allWatches = $movieWatches->map(fn (MovieWatch $watch): array => $this->watchPoint(
            $watch->watched_at?->copy()->setTimezone($timezone),
            $watch->runtime * max(1, $watch->watch_count),
            'movie',
            max(1, $watch->watch_count),
        ))
            ->concat($episodeWatches->map(fn (EpisodeWatch $watch): array => $this->watchPoint($watch->watched_at?->copy()->setTimezone($timezone), $watch->runtime, 'episode', 1)))
            ->filter(fn (array $point): bool => filled($point['date']));
        $totalMinutes = $allWatches->sum('minutes');
        $uniqueMovieCount = $movieWatches->pluck('movie_id')->filter()->unique()->count();
        $uniqueEpisodeCount = $episodeWatches->pluck('episode_id')->filter()->unique()->count();
        $ratings = Rating::forUser($user)->when($start, fn ($q) => $q->where('updated_at', '>=', $start))->when($end, fn ($q) => $q->where('updated_at', '<=', $end))->get();
        $topMovies = $movieWatches
            ->groupBy('movie_id')
            ->map(function (Collection $watches): array {
                $watchEvents = (int) $watches->sum(fn (MovieWatch $watch): int => max(1, $watch->watch_count));

                return [
                    'id' => $watches->first()?->movie_id,
                    'title' => $watches->first()?->movie?->title ?? 'Untitled movie',
                    'watches' => $watchEvents,
                    'minutes' => (int) $watches->sum(fn (MovieWatch $watch): int => $watch->runtime * max(1, $watch->watch_count)),
                ];
            })
            ->sortByDesc('watches')
            ->take(10)
            ->values()
            ->all();

        return [
            'range' => ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'timezone' => $timezone],
            'dailyActivity' => $this->groupActivity($allWatches, 'Y-m-d'),
            'genreTrends' => $movieWatches->groupBy(fn ($watch) => $watch->watched_at->copy()->setTimezone($timezone)->format('Y-m'))->map(fn ($watches, $period) => ['period' => $period, 'genres' => $this->genreDistribution($watches, collect())])->sortBy('period')->values()->all(),
            'ratingTrends' => $ratings->groupBy(fn ($rating) => $rating->updated_at->copy()->setTimezone($timezone)->format('Y-m'))->map(fn ($ratings, $period) => ['period' => $period, 'average' => round($ratings->avg('rating'), 1), 'count' => $ratings->count()])->sortBy('period')->values()->all(),
            'summary' => [
                'moviesWatched' => $movieWatchEvents,
                'episodesWatched' => $episodeWatchEvents,
                'showsCompleted' => Show::forUser($user)->where('aired_episodes', '>', 0)->whereColumn('seen_episodes', '>=', 'aired_episodes')->count(),
                'totalWatchMinutes' => $totalMinutes,
                'totalWatchHours' => round($totalMinutes / 60, 1),
                'rewatchCount' => max(0, $movieWatchEvents - $uniqueMovieCount)
                    + max(0, $episodeWatchEvents - $uniqueEpisodeCount),
                'longestStreakDays' => $this->longestStreak($allWatches->pluck('date')->unique()->sort()->values()),
            ],
            'monthlyActivity' => $this->groupActivity($allWatches, 'Y-m'),
            'yearlyActivity' => $this->groupActivity($allWatches, 'Y'),
            'genres' => $this->genreDistribution($movieWatches, $episodeWatches),
            'ratings' => $ratings->groupBy('rating')->map(fn ($items, $rating): array => ['rating' => (int) $rating, 'count' => $items->count()])->sortBy('rating')->values()->all(),
            'topMovies' => $topMovies,
            'topShows' => $episodeWatches->groupBy('show_id')->map(fn (Collection $watches): array => ['id' => $watches->first()?->show_id, 'title' => $watches->first()?->show?->title ?? 'Untitled show', 'episodes' => $watches->count(), 'minutes' => (int) $watches->sum('runtime')])->sortByDesc('episodes')->take(10)->values()->all(),
        ];
    }

    /** @return array{date:string|null,minutes:int,type:string,watches:int} */
    private function watchPoint(mixed $watchedAt, int $runtime, string $type, int $watches): array
    {
        return ['date' => $watchedAt?->toDateString(), 'minutes' => max(0, $runtime), 'type' => $type, 'watches' => max(1, $watches)];
    }

    /** @return list<array{period:string,watches:int,minutes:int}> */
    private function groupActivity(Collection $watches, string $format): array
    {
        return $watches->groupBy(fn (array $watch): string => CarbonImmutable::parse($watch['date'])->format($format))
            ->map(fn (Collection $items, string $period): array => ['period' => $period, 'watches' => (int) $items->sum('watches'), 'minutes' => (int) $items->sum('minutes')])
            ->sortBy('period')
            ->values()
            ->all();
    }

    /** @return list<array{genre:string,count:int}> */
    private function genreDistribution(Collection $movies, Collection $episodes): array
    {
        $genres = collect();
        $movies->each(fn (MovieWatch $watch) => collect($watch->movie?->genres ?? [])->each(fn (mixed $genre) => $this->incrementGenre($genres, $genre)));
        $episodes->groupBy('show_id')->each(function (Collection $watches) use ($genres): void {
            collect($watches->first()?->show?->genres ?? [])->each(fn (mixed $genre) => $this->incrementGenre($genres, $genre));
        });

        return $genres->map(fn (int $count, string $genre): array => ['genre' => $genre, 'count' => $count])->sortByDesc('count')->take(12)->values()->all();
    }

    private function incrementGenre(Collection $genres, mixed $genre): void
    {
        $name = is_array($genre) ? trim((string) ($genre['name'] ?? '')) : trim((string) $genre);
        if ($name !== '') {
            $genres[$name] = (int) ($genres[$name] ?? 0) + 1;
        }
    }

    private function longestStreak(Collection $dates): int
    {
        $longest = 0;
        $current = 0;
        $previous = null;
        foreach ($dates as $date) {
            $day = CarbonImmutable::parse($date);
            $current = $previous && (int) $previous->diffInDays($day) === 1 ? $current + 1 : 1;
            $longest = max($longest, $current);
            $previous = $day;
        }

        return $longest;
    }
}
