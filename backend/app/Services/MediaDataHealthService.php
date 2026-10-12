<?php

namespace App\Services;

use App\Enums\MediaEventType;
use App\Models\Alert;
use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaEvent;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MediaDataHealthService
{
    public function __construct(
        private readonly CanonicalWatchHistoryService $history,
        private readonly LibraryBrowserService $library,
        private readonly StatisticsService $statistics,
        private readonly CalendarService $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function analyze(User $user): array
    {
        $movies = Movie::forUser($user)->get([
            'id', 'tmdb_id', 'title', 'release_date', 'poster_path', 'backdrop_path', 'runtime', 'genres',
        ]);
        $shows = Show::forUser($user)->get([
            'id', 'tmdb_id', 'title', 'first_air_date', 'poster_path', 'backdrop_path', 'runtime', 'genres',
        ]);
        $episodes = Episode::forUser($user)->get([
            'id', 'show_id', 'tmdb_id', 'season_number', 'episode_number', 'air_date', 'poster_path', 'backdrop_path', 'runtime', 'genres',
        ]);
        $watchCounts = $this->history->watchCounts($user);
        $historyRows = $this->library->history($user, ['per_page' => 1])['pagination']['total'];
        $statistics = $this->statistics->forUser($user)['summary'];
        $diaryWatchEvents = MediaEvent::forUser($user)->whereIn('event_type', [
            MediaEventType::MovieWatched->value,
            MediaEventType::EpisodeWatched->value,
        ])->count();
        $today = now()->toDateString();
        $weekEnd = now()->addDays(7)->toDateString();
        $calendarItems = collect($this->calendar->forUser($user, [
            'date_from' => $today,
            'date_to' => now()->addDays(30)->toDateString(),
        ])['items']);
        $alerts = Alert::forUser($user)->get(['payload']);

        return [
            'generated_at' => now()->toIso8601String(),
            'coverage' => [
                'movies' => $this->coverage($movies, 'movie'),
                'shows' => $this->coverage($shows, 'show'),
                'episodes' => $this->coverage($episodes, 'episode'),
            ],
            'upcoming' => [
                'shows_with_next_episode_hint' => Show::forUser($user)
                    ->get(['metadata'])
                    ->filter(fn (Show $show): bool => filled(data_get($show->metadata, 'release.next_episode.air_date')))
                    ->count(),
                'episodes_next_7_days' => Episode::forUser($user)
                    ->whereDate('air_date', '>=', $today)
                    ->whereDate('air_date', '<=', $weekEnd)
                    ->whereHas('show', fn (Builder $query) => $query->forUser($user)->where(fn (Builder $query) => $query->where('followed', true)->orWhere('seen_episodes', '>', 0)))
                    ->count(),
                'watchlist_movies_next_7_days' => Movie::forUser($user)->toWatch()->whereDate('release_date', '>=', $today)->whereDate('release_date', '<=', $weekEnd)->count(),
                'continue_watching_items' => count($this->library->continueWatching($user, ['limit' => 12, 'candidate_limit' => 60])['items']),
            ],
            'duplicates' => [
                'movie_title_year' => $this->duplicateSummary($movies, fn (Movie $movie): string => $this->normalize($movie->title).'|'.($movie->release_date?->format('Y') ?? '')),
                'movie_tmdb_id' => $this->duplicateSummary($movies->whereNotNull('tmdb_id'), fn (Movie $movie): string => (string) $movie->tmdb_id),
                'show_title' => $this->duplicateSummary($shows, fn (Show $show): string => $this->normalize($show->title)),
                'show_tmdb_id' => $this->duplicateSummary($shows->whereNotNull('tmdb_id'), fn (Show $show): string => (string) $show->tmdb_id),
                'episode_coordinates' => $this->duplicateSummary($episodes, fn (Episode $episode): string => $episode->show_id.'|'.$episode->season_number.'|'.$episode->episode_number),
                'episode_tmdb_id' => $this->duplicateSummary($episodes->whereNotNull('tmdb_id'), fn (Episode $episode): string => (string) $episode->tmdb_id),
            ],
            'broken_references' => [
                'episodes_without_owned_show' => Episode::forUser($user)->whereDoesntHave('show', fn (Builder $query) => $query->forUser($user))->count(),
                'movie_watches_without_owned_movie' => MovieWatch::forUser($user)->whereDoesntHave('movie', fn (Builder $query) => $query->forUser($user))->count(),
                'episode_watches_without_owned_episode' => EpisodeWatch::forUser($user)->whereDoesntHave('episode', fn (Builder $query) => $query->forUser($user))->count(),
                'episode_watches_without_owned_show' => EpisodeWatch::forUser($user)->whereDoesntHave('show', fn (Builder $query) => $query->forUser($user))->count(),
                'ratings_without_owned_media' => $this->brokenAnnotations($user, Rating::forUser($user)->get()),
                'notes_without_owned_media' => $this->brokenAnnotations($user, Note::forUser($user)->get()),
                'calendar_items_without_owned_media' => $calendarItems->filter(fn (array $item): bool => ! $this->calendarItemIsOwned($user, $item))->count(),
                'alerts_without_owned_media' => $alerts->filter(fn (Alert $alert): bool => ! $this->alertIsOwned($user, $alert))->count(),
            ],
            'metadata_review' => [
                'episodes_with_failed_lookup' => Episode::forUser($user)->where('metadata_failure_count', '>', 0)->count(),
                'episodes_with_invalid_numbering' => Episode::forUser($user)->where(function (Builder $query): void {
                    $query->whereNull('season_number')->orWhereNull('episode_number')->orWhere('season_number', '<=', 0)->orWhere('episode_number', '<=', 0);
                })->count(),
                'episodes_pending_review' => Episode::forUser($user)->where('metadata_review_status', 'pending')->where('metadata_failure_count', '>', 0)->count(),
            ],
            'canonical_consistency' => [
                ...$watchCounts,
                'history_rows' => $historyRows,
                'statistics_movie_watches' => (int) $statistics['moviesWatched'],
                'statistics_episode_watches' => (int) $statistics['episodesWatched'],
                'diary_watch_events' => $diaryWatchEvents,
                'calendar_items_next_30_days' => $calendarItems->count(),
                'alerts_total' => $alerts->count(),
                'statistics_match_canonical' => (int) $statistics['moviesWatched'] === $watchCounts['movie_watches']
                    && (int) $statistics['episodesWatched'] === $watchCounts['episode_watches'],
                'diary_matches_canonical' => $diaryWatchEvents === $watchCounts['total_watches'],
                'calendar_matches_canonical' => $calendarItems->every(fn (array $item): bool => $this->calendarItemIsOwned($user, $item)),
                'alerts_match_canonical' => $alerts->every(fn (Alert $alert): bool => $this->alertIsOwned($user, $alert)),
            ],
        ];
    }

    /** @param Collection<int, Model> $records @return array<string, int|float> */
    private function coverage(Collection $records, string $type): array
    {
        $total = $records->count();
        $missingPoster = $records->filter(fn (Model $record): bool => blank($record->getAttribute('poster_path')))->count();
        $missingBackdrop = $records->filter(fn (Model $record): bool => blank($record->getAttribute('backdrop_path')))->count();
        $dateField = match ($type) {
            'movie' => 'release_date',
            'show' => 'first_air_date',
            default => 'air_date',
        };

        return [
            'total' => $total,
            'with_tmdb_id' => $records->whereNotNull('tmdb_id')->count(),
            'missing_tmdb_id' => $records->whereNull('tmdb_id')->count(),
            'missing_poster' => $missingPoster,
            'missing_backdrop' => $missingBackdrop,
            'missing_date' => $records->filter(fn (Model $record): bool => blank($record->getAttribute($dateField)))->count(),
            'missing_runtime' => $records->filter(fn (Model $record): bool => (int) $record->getAttribute('runtime') <= 0)->count(),
            'missing_genres' => $records->filter(fn (Model $record): bool => $type !== 'episode' && empty($record->getAttribute('genres')))->count(),
            'tmdb_coverage_percent' => $total > 0 ? round(($records->whereNotNull('tmdb_id')->count() / $total) * 100, 1) : 100.0,
        ];
    }

    /** @param Collection<int, Model> $records @return array{groups:int,records:int} */
    private function duplicateSummary(Collection $records, callable $key): array
    {
        $groups = $records->filter(fn (Model $record): bool => $key($record) !== '' && $key($record) !== '||')
            ->groupBy($key)
            ->filter(fn (Collection $group): bool => $group->count() > 1);

        return [
            'groups' => $groups->count(),
            'records' => $groups->sum(fn (Collection $group): int => $group->count()),
        ];
    }

    /** @param Collection<int, Rating|Note> $annotations */
    private function brokenAnnotations(User $user, Collection $annotations): int
    {
        return $annotations->filter(fn (Rating|Note $annotation): bool => ! $this->ownedMediaExists($user, $annotation->media_type, $annotation->media_id))->count();
    }

    private function ownedMediaExists(User $user, string $type, int $id): bool
    {
        return match ($type) {
            'movie' => Movie::forUser($user)->whereKey($id)->exists(),
            'show' => Show::forUser($user)->whereKey($id)->exists(),
            'episode' => Episode::forUser($user)->whereKey($id)->exists(),
            default => false,
        };
    }

    /** @param array<string, mixed> $item */
    private function calendarItemIsOwned(User $user, array $item): bool
    {
        return match ($item['kind'] ?? null) {
            'movie' => Movie::forUser($user)->whereKey((int) ($item['movieId'] ?? 0))->exists(),
            'episode' => Episode::forUser($user)->whereKey((int) ($item['episodeId'] ?? 0))->exists(),
            'show' => Show::forUser($user)->whereKey((int) ($item['showId'] ?? 0))->exists(),
            default => false,
        };
    }

    private function alertIsOwned(User $user, Alert $alert): bool
    {
        $payload = $alert->payload ?? [];

        if (isset($payload['movie_id'])) {
            return Movie::forUser($user)->whereKey((int) $payload['movie_id'])->exists();
        }
        if (isset($payload['episode_id'])) {
            return Episode::forUser($user)->whereKey((int) $payload['episode_id'])->exists();
        }
        if (isset($payload['show_id'])) {
            return Show::forUser($user)->whereKey((int) $payload['show_id'])->exists();
        }

        return true;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    /** @param array<string, mixed> $health */
    public function markdown(array $health): string
    {
        $lines = [
            '# ReelAtlas Media Data Health Report',
            '',
            'Generated: '.$health['generated_at'],
            '',
            'This report contains aggregate coverage counts only. It intentionally excludes media titles, watch dates, notes, provider data, and credentials.',
            '',
            '## Coverage',
            '',
            '| Type | Total | TMDB | Coverage | Missing posters | Missing backdrops | Missing dates | Missing runtime | Missing genres |',
            '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
        ];
        foreach (['movies', 'shows', 'episodes'] as $type) {
            $row = $health['coverage'][$type];
            $lines[] = '| '.Str::title($type).' | '.$row['total'].' | '.$row['with_tmdb_id'].' | '.$row['tmdb_coverage_percent'].'% | '.$row['missing_poster'].' | '.$row['missing_backdrop'].' | '.$row['missing_date'].' | '.$row['missing_runtime'].' | '.$row['missing_genres'].' |';
        }

        $lines = [...$lines, '', '## Upcoming And Continue Watching', ''];
        foreach ($health['upcoming'] as $key => $value) {
            $lines[] = '- '.Str::of($key)->replace('_', ' ')->title().': '.$value;
        }
        $lines = [...$lines, '', '## Duplicate Candidates', ''];
        foreach ($health['duplicates'] as $key => $value) {
            $lines[] = '- '.Str::of($key)->replace('_', ' ')->title().': '.$value['groups'].' groups / '.$value['records'].' records';
        }
        $lines = [...$lines, '', '## Broken References', ''];
        foreach ($health['broken_references'] as $key => $value) {
            $lines[] = '- '.Str::of($key)->replace('_', ' ')->title().': '.$value;
        }
        $lines = [...$lines, '', '## Metadata Review', ''];
        foreach ($health['metadata_review'] as $key => $value) {
            $lines[] = '- '.Str::of($key)->replace('_', ' ')->title().': '.$value;
        }
        $lines = [...$lines, '', '## Canonical Consistency', ''];
        foreach ($health['canonical_consistency'] as $key => $value) {
            $lines[] = '- '.Str::of($key)->replace('_', ' ')->title().': '.(is_bool($value) ? ($value ? 'yes' : 'no') : $value);
        }

        return implode("\n", $lines)."\n";
    }
}
