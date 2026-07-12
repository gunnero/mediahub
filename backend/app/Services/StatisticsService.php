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
    public function forUser(User $user): array
    {
        $movieWatches = MovieWatch::forUser($user)
            ->whereHas('movie', fn ($query) => $query->forUser($user))
            ->with(['movie' => fn ($query) => $query->forUser($user)])
            ->watched()->get();
        $episodeWatches = EpisodeWatch::forUser($user)
            ->whereHas('episode', fn ($query) => $query->forUser($user))
            ->whereHas('show', fn ($query) => $query->forUser($user))
            ->with([
                'episode' => fn ($query) => $query->forUser($user),
                'show' => fn ($query) => $query->forUser($user),
            ])->watched()->get();
        $movieWatchEvents = (int) $movieWatches->sum(fn (MovieWatch $watch): int => max(1, $watch->watch_count));
        $episodeWatchEvents = $episodeWatches->count();
        $allWatches = $movieWatches->map(fn (MovieWatch $watch): array => $this->watchPoint(
            $watch->watched_at,
            $watch->runtime * max(1, $watch->watch_count),
            'movie',
            max(1, $watch->watch_count),
        ))
            ->concat($episodeWatches->map(fn (EpisodeWatch $watch): array => $this->watchPoint($watch->watched_at, $watch->runtime, 'episode', 1)))
            ->filter(fn (array $point): bool => filled($point['date']));
        $totalMinutes = $allWatches->sum('minutes');
        $uniqueMovieCount = $movieWatches->pluck('movie_id')->filter()->unique()->count();
        $uniqueEpisodeCount = $episodeWatches->pluck('episode_id')->filter()->unique()->count();
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
            'ratings' => Rating::forUser($user)->selectRaw('rating, COUNT(*) as items_count')->groupBy('rating')->orderBy('rating')->get()->map(fn (Rating $rating): array => ['rating' => $rating->rating, 'count' => (int) $rating->items_count])->all(),
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
